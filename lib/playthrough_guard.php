<?php
require_once __DIR__ . '/playthrough_home.php';

// This journal is outside saved gameplay and survives a failed database or PHP process.
function pgr_state(): array {
    $path = dirname(__DIR__) . '/log/playthrough_runtime/rollback.json';
    clearstatcache(true, $path);
    $marker = dirname($path) . '/rollback.pending';
    clearstatcache(true, $marker);
    if (!is_file($path) && !is_file($marker)) return [];
    $state = json_decode((string)file_get_contents($path), true);
    if (!is_array($state) || !preg_match('/^[a-f0-9]{32}$/D', $state['id'] ?? '') ||
        !in_array($state['phase'] ?? '', ['saving','failed','pruning','complete'], true) ||
        (is_file($marker) && $state['phase'] === 'complete')) {
        // A reader can arrive between sentinel creation, atomic rename and sentinel removal.
        $lock = @fopen(dirname($path) . '/switch.lock', 'r');
        $busy = $lock && !flock($lock, LOCK_EX | LOCK_NB);
        if ($lock) fclose($lock);
        if ($busy) throw new RuntimeException('Rollback recovery state is being updated.', 409);
        throw new RuntimeException('The rollback recovery journal is unreadable.');
    }
    return $state;
}

function pgr_store(array $state): void {
    // A zero-length sentinel remains blocking if a full disk prevents publishing the JSON.
    $marker = dirname(__DIR__) . '/log/playthrough_runtime/rollback.pending';
    if ($state['phase'] !== 'complete') {
        $pending = ptr_runtime_file('rollback.pending');
        fclose($pending);
    }
    $handle = ptr_runtime_file('rollback.' . bin2hex(random_bytes(6)) . '.tmp');
    $path = stream_get_meta_data($handle)['uri'];
    try {
        ptr_runtime_write($handle, json_encode($state, JSON_THROW_ON_ERROR));
        if (function_exists('fsync') && !fsync($handle)) throw new RuntimeException('Could not flush rollback recovery state.');
        if (!rename($path, dirname($path) . '/rollback.json')) throw new RuntimeException('Could not publish rollback recovery state.');
        if ($state['phase'] === 'complete' && is_file($marker) && !unlink($marker)) throw new RuntimeException('Could not clear rollback recovery state.');
    } finally {
        fclose($handle);
        if (is_file($path)) @unlink($path);
    }
}

function pgr_message(string $status): string {
    return match ($status) {
        'created' => 'New Playthrough Save created.',
        'resumed' => 'New Playthrough Save created. Mod processing resumed.',
        'rollback_failed' => "Playthrough Save created, but rollback couldn't finish. Mod processing paused.",
        'busy' => 'A Playthrough Save is being created. Try again shortly.',
        default => "Couldn't create a new Playthrough Save. No data has been rolled back.",
    };
}

// Fixed ASCII status codes keep notifications independent of JSON/stream response formats.
function pgr_notice(array $state, string $status): void {
    if (PHP_SAPI !== 'cli' && !headers_sent()) {
        header('X-Playthrough-Save: v1;' . $state['id'] . ';' . $status);
        header('Cache-Control: no-store');
    }
}

function pgr_deny(array $state, string $status = ''): never {
    $status = $status ?: (!empty($state['saved_id']) || ($state['phase'] ?? '') === 'pruning' ? 'rollback_failed' : 'failed');
    if ($status !== 'busy') pgr_notice($state, $status);
    if (PHP_SAPI !== 'cli' && !headers_sent()) {
        http_response_code(503);
        header('Retry-After: 10');
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode(['ok'=>false, 'error'=>pgr_message($status), 'code'=>$status === 'busy' ? 'playthrough_rollback_busy' : 'playthrough_rollback_blocked']);
    exit(75);
}

// Called by the existing lease barrier, including workers. Raw manager reads need no lease.
function pgr_runtime_check(): void {
    if (!empty($GLOBALS['pgr_candidate']) || !empty($GLOBALS['pgr_operation'])) return;
    try { $state = pgr_state(); } catch (Throwable $error) {
        error_log($error->getMessage());
        pgr_deny(['id'=>str_repeat('0',32)], $error->getCode() === 409 ? 'busy' : 'rollback_failed');
    }
    if ($state && ($state['phase'] ?? '') !== 'complete') pgr_deny($state);
}

// Read the same authoritative clock used by each product's rollback handler.
function pgr_clock($conn): int {
    if (ptp_product()['meta'] === 'stobe_meta') {
        if (!ptr_exists($conn, 'public.conf_opts')) return 0;
        $row = pg_fetch_assoc(pth_query($conn, "SELECT value FROM public.conf_opts WHERE id='PLAYTHROUGH_LAST_SEEN_GAMETS'"));
        return max(0, (int)($row['value'] ?? 0));
    }
    if (!ptr_exists($conn, 'public.eventlog')) return 0;
    return (int)pg_fetch_result(pth_query($conn, 'SELECT COALESCE(MAX(gamets),0) FROM public.eventlog WHERE gamets>0'),0,0);
}

function pgr_required($conn, int $previous, int $incoming): bool {
    if ($incoming <= 0 || $previous <= $incoming) return false;
    $days = ptp_backup_settings($conn)['min_days'];
    $unit = ptp_product()['meta'] === 'stobe_meta' ? 86400 : 10000000;
    return ($previous - $incoming) >= $days * $unit;
}

// Capture and its manager entry commit together. The journal ID also resolves an uncertain commit.
function pgr_capture($conn, array &$state): int {
    $meta = ptp_product()['meta'];
    ptr_ensure_schema($conn);
    $record = ptr_read($conn, 'PLAYTHROUGH_ROLLBACK_CAPTURE', []);
    if (($record['id'] ?? '') === $state['id']) {
        $row = pg_fetch_assoc(pth_query($conn, "SELECT p.id FROM {$meta}.playthrough_profiles p JOIN pg_namespace n ON n.nspname=p.schema_name
            WHERE p.id=$1 AND p.storage_type='schema' AND obj_description(n.oid,'pg_namespace') IS NOT NULL", [$record['saved_id']]));
        if (!$row) throw new RuntimeException('The recorded recovery copy is unavailable.');
        return (int)$row['id'];
    }
    pth_query($conn,'BEGIN');
    try {
        $save = pth_capture($conn, 'Automatic Playthrough Save ' . gmdate('Y-m-d H:i:s') . ' ' . substr($state['id'],0,8), null, 'dragon_break');
        $id = (int)$save['id'];
        if ($id < 1) throw new RuntimeException('The recovery save has no manager entry.');
        pth_query($conn, "UPDATE {$meta}.playthrough_profiles SET retention_pinned=true WHERE id=$1", [$id]);
        ptr_write($conn, 'PLAYTHROUGH_ROLLBACK_CAPTURE', ['id'=>$state['id'],'saved_id'=>$id]);
        pth_query($conn,'COMMIT');
        return $id;
    } catch (Throwable $error) {
        @pg_query($conn,'ROLLBACK');
        throw $error;
    }
}

// Hold the existing runtime barrier through rollback, so failed capture cannot be bypassed.
function pgr_before_rollback(int $previous, int $incoming): int {
    if (!empty($GLOBALS['pgr_operation'])) return (int)$GLOBALS['pgr_operation']['state']['saved_id'];
    $conn = null; $runtime = null; $locked = false; $state = [];
    try {
        $state = pgr_state();
        $pending = $state && ($state['phase'] ?? '') !== 'complete';
        $conn = ptp_connect();
        if (!$conn) throw new RuntimeException('Cannot connect to create the recovery save.');
        if (!$pending && !pgr_required($conn,$previous,$incoming)) { pg_close($conn); return 0; }
        $GLOBALS['pgr_controller'] = true;
        $runtime = ptr_runtime_begin_switch(30.0, $conn);
        if (!ptr_lock($conn)) throw new RuntimeException('Another Playthrough Save operation is busy.');
        $locked = true;
        $state = pgr_state();
        $pending = $state && ($state['phase'] ?? '') !== 'complete';
        $previous = pgr_clock($conn);
        if (!$pending && !pgr_required($conn,$previous,$incoming)) {
            ptr_unlock($conn); ptr_runtime_release_switch($runtime); pg_close($conn);
            unset($GLOBALS['ptr_runtime_controller']); ptr_runtime_enter(); return 0;
        }
        if ($pending && ($incoming <= 0 || $incoming >= (int)$state['previous'])) pgr_deny($state);
        if ($pending && time() - (int)($state['attempted_at'] ?? 0) < 10) pgr_deny($state);
        $recovered = $pending;
        if (!$pending) $state = ['id'=>bin2hex(random_bytes(16)), 'previous'=>$previous, 'saved_id'=>0, 'phase'=>'saving'];
        $state['target'] = $incoming;
        $state['generation'] = $runtime['generation'];
        $state['attempted_at'] = time();
        pgr_store($state);
        $id = pgr_capture($conn,$state);
        $state['saved_id'] = $id;
        $state['phase'] = 'pruning';
        pgr_store($state);
        ptp_record_backup($conn,$id,pgr_message('created'));
        $GLOBALS['pgr_operation'] = ['state'=>$state, 'conn'=>$conn, 'runtime'=>$runtime, 'recovered'=>$recovered];
        // Database warnings are still delivered to this handler even when a legacy caller uses @.
        $previousHandler = null;
        $previousHandler = set_error_handler(static function ($severity,$message,$file,$line) use (&$previousHandler) {
            if (!empty($GLOBALS['pgr_operation']) && str_contains($message,'pg_')) $GLOBALS['pgr_sql_failed'] = true;
            return $previousHandler ? $previousHandler($severity,$message,$file,$line) : false;
        });
        register_shutdown_function(static function () {
            if (!empty($GLOBALS['pgr_operation'])) pgr_fail('Rollback request ended before completion.');
        });
        pgr_notice($state,'created');
        return $id;
    } catch (Throwable $error) {
        error_log('Playthrough rollback protection: ' . $error->getMessage());
        // A losing concurrent request must never replace the owning controller's journal.
        if (!$locked) {
            if ($runtime !== null) ptr_runtime_release_switch($runtime);
            try { $state = pgr_state(); } catch (Throwable $ignored) { $state = []; }
            pgr_deny($state ?: ['id'=>str_repeat('0',32)], 'busy');
        }
        if (!$state || ($state['phase'] ?? '') === 'complete') $state = ['id'=>bin2hex(random_bytes(16)), 'previous'=>$previous, 'target'=>$incoming, 'saved_id'=>0];
        $state['phase'] = !empty($state['saved_id']) ? 'pruning' : 'failed';
        $state['attempted_at'] = time();
        try { pgr_store($state); } catch (Throwable $journalError) { error_log($journalError->getMessage()); }
        if ($conn) {
            @pg_query($conn,'ROLLBACK');
            ptp_record_backup($conn,0,pgr_message(!empty($state['saved_id'])?'rollback_failed':'failed'));
            if ($locked) ptr_unlock($conn);
        }
        if ($runtime !== null) ptr_runtime_release_switch($runtime);
        pgr_deny($state);
    }
}

function pgr_fail(string $reason): void {
    $operation = $GLOBALS['pgr_operation'] ?? null;
    if (!$operation) return;
    unset($GLOBALS['pgr_operation']);
    error_log('Playthrough rollback stopped: ' . $reason);
    ptp_record_backup($operation['conn'],0,pgr_message('rollback_failed'));
    ptr_unlock($operation['conn']);
    ptr_runtime_release_switch($operation['runtime']);
    pgr_notice($operation['state'],'rollback_failed');
    if (!headers_sent()) http_response_code(503);
}

// Only the product's completed rollback path can release the durable block.
function pgr_complete(bool $success = true): void {
    $operation = $GLOBALS['pgr_operation'] ?? null;
    if (!$operation) return;
    if (!$success || !empty($GLOBALS['pgr_sql_failed'])) {
        pgr_fail('A rollback write failed.');
        pgr_deny($operation['state'],'rollback_failed');
    }
    try {
        $conn = $operation['conn']; $meta = ptp_product()['meta'];
        $operation['state']['phase'] = 'complete';
        $operation['state']['completed_at'] = time();
        $operation['state']['notice'] = $operation['recovered'] ? 'resumed' : 'created';
        pgr_store($operation['state']);
        // Completion is durable before this recovery copy becomes eligible for cleanup.
        try { pth_query($conn,"UPDATE {$meta}.playthrough_profiles SET retention_pinned=false WHERE id=$1",[$operation['state']['saved_id']]); }
        catch (Throwable $error) { error_log('Recovery save remains protected: ' . $error->getMessage()); }
        pgr_notice($operation['state'],$operation['state']['notice']);
        ptp_record_backup($conn,$operation['state']['saved_id'],pgr_message($operation['state']['notice']));
        unset($GLOBALS['pgr_operation']);
        ptr_unlock($conn);
        ptr_runtime_release_switch($operation['runtime']);
        pg_close($conn);
        unset($GLOBALS['ptr_runtime_controller'], $GLOBALS['pgr_controller']);
        ptr_runtime_enter();
    } catch (Throwable $error) {
        pgr_fail($error->getMessage());
        pgr_deny($operation['state'],'rollback_failed');
    }
}

// Inspect only routing/timestamps before bootstrap can write player data or start background work.
function pgr_http_preflight(string $endpoint): void {
    if (PHP_SAPI === 'cli') return;
    $meta = ptp_product()['meta'];
    try { $state = pgr_state(); } catch (Throwable $error) {
        error_log($error->getMessage());
        pgr_deny(['id'=>str_repeat('0',32)], $error->getCode() === 409 ? 'busy' : 'rollback_failed');
    }
    $event = ''; $incoming = 0;
    if ($endpoint === 'main' && $meta !== 'dialectic_meta') {
        $query = (string)($_SERVER['QUERY_STRING'] ?? '');
        if (str_starts_with($query,'DATA=')) {
            $packet = base64_decode(explode('&',substr($query,5),2)[0],true);
            $fields = $packet === false ? [] : explode('|',$packet,4);
            $event = strtolower($fields[0] ?? ''); $incoming = (int)($fields[2] ?? 0);
        }
    } else {
        $body = json_decode((string)file_get_contents('php://input'),true);
        $body = is_array($body) ? $body : [];
        $event = $endpoint === 'main' ? strtolower((string)($body['type'] ?? '')) : $endpoint;
        $incoming = (int)($body['gamets'] ?? $body['game_ts'] ?? $body['data']['game_ts'] ?? $_POST['gamets'] ?? $_POST['game_ts'] ?? 0);
        if ($meta === 'stobe_meta' && $incoming <= 0) $incoming = (int)($body['npc']['game_ts'] ?? 0);
        if ($meta === 'stobe_meta' && $endpoint === 'faction_relations') $incoming = (int)($body['faction_relations']['game_ts'] ?? $incoming);
        if ($meta === 'dialectic_meta' && ($body['type'] ?? '') === 'dialogue_delivery') $event = '';
    }
    if ($meta === 'chim_meta') $eligible = in_array($event,['init','playerdied'],true) && $incoming !== 10000000;
    elseif ($meta === 'stobe_meta') {
        require_once __DIR__ . '/playthrough_rollback.php';
        $eligible = stobePlaythroughRollbackEventIsAuthoritative($event);
    } else $eligible = $event !== '';
    $pending = $state && ($state['phase'] ?? '') !== 'complete';
    if (!$eligible || $incoming <= 0) {
        if ($pending) pgr_deny($state);
        $generation = trim((string)@file_get_contents(dirname(__DIR__) . '/log/playthrough_runtime/generation'));
        if ($state && ($state['generation'] ?? '') === $generation && time()-(int)($state['completed_at'] ?? 0)<120) {
            pgr_notice($state,$state['notice'] ?? 'created');
        }
        return;
    }
    $GLOBALS['pgr_candidate'] = true;
    try {
        if (!$pending) ptr_runtime_enter();
        $conn = ptp_connect();
        if (!$conn) pgr_deny($pending ? $state : ['id'=>str_repeat('0',32)]);
        try { $previous = pgr_clock($conn); } finally { pg_close($conn); }
        pgr_before_rollback($previous,$incoming);
        // Replay a recent outcome on ordinary traffic if the first response was lost.
        if (!$pending && empty($GLOBALS['pgr_operation']) && $state &&
            ($state['generation'] ?? '') === ($GLOBALS['ptr_runtime_generation'] ?? '') &&
            time()-(int)($state['completed_at'] ?? 0)<120) pgr_notice($state,$state['notice'] ?? 'created');
    } finally { unset($GLOBALS['pgr_candidate']); }
}
