<?php
require_once __DIR__ . '/playthrough_home.php';

// Diagnostic outcomes are outside saved gameplay. They never gate requests or workers.
function pgr_state(): array {
    $path = dirname(__DIR__) . '/log/playthrough_runtime/rollback.json';
    $state = json_decode((string)@file_get_contents($path), true);
    return is_array($state) && preg_match('/^[a-f0-9]{32}$/D', $state['id'] ?? '') ? $state : [];
}

function pgr_store(array $state): void {
    $handle = null; $path = '';
    try {
        $handle = ptr_runtime_file('rollback.' . bin2hex(random_bytes(6)) . '.tmp');
        $path = stream_get_meta_data($handle)['uri'];
        ptr_runtime_write($handle, json_encode($state, JSON_THROW_ON_ERROR));
        if (!rename($path, dirname($path) . '/rollback.json')) throw new RuntimeException('Could not record rollback outcome.');
    } catch (Throwable $error) {
        error_log('Playthrough Save status: ' . $error->getMessage());
    } finally {
        if (is_resource($handle)) fclose($handle);
        if ($path !== '' && is_file($path)) @unlink($path);
    }
}

function pgr_message(string $status): string {
    return match ($status) {
        'created' => 'New Playthrough Save created.',
        'resumed' => 'New Playthrough Save created.',
        'rollback_failed' => "Playthrough Save created, but rollback couldn't finish. Mod processing continues.",
        'busy' => 'A Playthrough Save is being created. Try again shortly.',
        default => "Couldn't create a new Playthrough Save. No data has been rolled back.",
    };
}

// Fixed ASCII status codes keep notifications independent of JSON/stream response formats.
function pgr_notice(array $state, string $status): void {
    if (PHP_SAPI !== 'cli' && !headers_sent()) {
        header('X-Playthrough-Save: v1;' . ($state['notice_id'] ?? $state['id']) . ';' . $status);
        header('Cache-Control: no-store');
    }
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

// Capture, its manager entry and operation ID commit together.
function pgr_capture($conn, array &$state): int {
    $meta = ptp_product()['meta'];
    ptr_ensure_schema($conn);
    pth_query($conn,'BEGIN ISOLATION LEVEL REPEATABLE READ');
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

// A failed capture skips this rollback only. The shared lease still protects manual switching.
function pgr_before_rollback(int $previous, int $incoming): int {
    if (!empty($GLOBALS['pgr_skip_rollback'])) return -1;
    if (!empty($GLOBALS['pgr_operation'])) return (int)$GLOBALS['pgr_operation']['state']['saved_id'];
    if ($incoming <= 0 || $previous <= $incoming) return 0;
    $conn = null; $locked = false;
    $state = ['id'=>bin2hex(random_bytes(16)), 'previous'=>$previous, 'target'=>$incoming, 'saved_id'=>0, 'phase'=>'saving'];
    try {
        $conn = ptp_connect();
        if (!$conn) throw new RuntimeException('Cannot connect to create the recovery save.');
        if (!pgr_required($conn,$previous,$incoming)) { pg_close($conn); return 0; }
        ptr_runtime_enter();
        if (!ptr_lock($conn)) {
            $GLOBALS['pgr_skip_rollback'] = true;
            pg_close($conn);
            return -1;
        }
        $locked = true;
        $previous = pgr_clock($conn);
        if (!pgr_required($conn,$previous,$incoming)) { ptr_unlock($conn); pg_close($conn); return 0; }
        $last = pgr_state();
        $recovered = $last && ($last['phase'] ?? '') !== 'complete';
        // Throttle only repeated rollback attempts; the triggering request continues normally.
        if ($recovered && ($last['previous'] ?? 0) === $previous && time()-(int)($last['attempted_at'] ?? 0)<10) {
            $GLOBALS['pgr_skip_rollback'] = true;
            pgr_notice($last,!empty($last['saved_id'])?'rollback_failed':'failed');
            ptr_unlock($conn); pg_close($conn);
            return -1;
        }
        $state['previous'] = $previous;
        $state['generation'] = $GLOBALS['ptr_runtime_generation'] ?? '';
        $state['notice_id'] = $recovered ? ($last['notice_id'] ?? $last['id']) : $state['id'];
        $state['attempted_at'] = time();
        // Always capture current progress again: normal processing may have continued since a failure.
        $state['saved_id'] = pgr_capture($conn,$state);
        $state['phase'] = 'pruning';
        pgr_store($state);
        ptp_record_backup($conn,$state['saved_id'],pgr_message('created'));
        $GLOBALS['pgr_sql_failed'] = false;
        $GLOBALS['pgr_operation'] = ['state'=>$state, 'conn'=>$conn, 'recovered'=>$recovered];
        $previousHandler = null;
        $previousHandler = set_error_handler(static function ($severity,$message,$file,$line) use (&$previousHandler) {
            if (!empty($GLOBALS['pgr_operation']) && str_contains($message,'pg_')) $GLOBALS['pgr_sql_failed'] = true;
            return $previousHandler ? $previousHandler($severity,$message,$file,$line) : false;
        });
        register_shutdown_function(static function () {
            if (!empty($GLOBALS['pgr_operation'])) pgr_fail('Rollback request ended before completion.');
        });
        pgr_notice($state,'created');
        return (int)$state['saved_id'];
    } catch (Throwable $error) {
        error_log('Playthrough rollback skipped: ' . $error->getMessage());
        $GLOBALS['pgr_skip_rollback'] = true;
        $state['phase'] = 'failed';
        pgr_store($state);
        if ($conn) {
            @pg_query($conn,'ROLLBACK');
            ptp_record_backup($conn,0,pgr_message('failed'));
            if ($locked) ptr_unlock($conn);
            pg_close($conn);
        }
        pgr_notice($state,'failed');
        return -1;
    }
}

// Keep the recovery copy pinned after incomplete pruning, without stopping normal processing.
function pgr_fail(string $reason): void {
    $operation = $GLOBALS['pgr_operation'] ?? null;
    if (!$operation) return;
    unset($GLOBALS['pgr_operation']);
    error_log('Playthrough rollback could not finish: ' . $reason);
    $operation['state']['phase'] = 'rollback_failed';
    pgr_store($operation['state']);
    ptp_record_backup($operation['conn'],0,pgr_message('rollback_failed'));
    ptr_unlock($operation['conn']);
    pg_close($operation['conn']);
    pgr_notice($operation['state'],'rollback_failed');
}

function pgr_complete(bool $success = true): bool {
    $operation = $GLOBALS['pgr_operation'] ?? null;
    if (!$operation) return empty($GLOBALS['pgr_skip_rollback']);
    if (!$success || !empty($GLOBALS['pgr_sql_failed'])) {
        pgr_fail('A rollback write failed.');
        return false;
    }
    $conn = $operation['conn']; $meta = ptp_product()['meta'];
    try { pth_query($conn,"UPDATE {$meta}.playthrough_profiles SET retention_pinned=false WHERE id=$1",[$operation['state']['saved_id']]); }
    catch (Throwable $error) { error_log('Recovery save remains protected: ' . $error->getMessage()); }
    $operation['state']['phase'] = 'complete';
    $operation['state']['completed_at'] = time();
    $operation['state']['notice'] = $operation['recovered'] ? 'resumed' : 'created';
    pgr_store($operation['state']);
    pgr_notice($operation['state'],$operation['state']['notice']);
    ptp_record_backup($conn,$operation['state']['saved_id'],pgr_message('created'));
    unset($GLOBALS['pgr_operation']);
    ptr_unlock($conn);
    pg_close($conn);
    return true;
}

// Inspect only routing/timestamps before bootstrap can write player data or start background work.
function pgr_http_preflight(string $endpoint): void {
    if (PHP_SAPI === 'cli') return;
    $meta = ptp_product()['meta'];
    $state = pgr_state();
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
    if ($eligible && $incoming > 0) {
        ptr_runtime_enter();
        $conn = ptp_connect();
        if ($conn) {
            try { $previous = pgr_clock($conn); }
            catch (Throwable $error) { $previous = 0; $GLOBALS['pgr_skip_rollback'] = true; pgr_notice(['id'=>str_repeat('0',32)],'failed'); }
            finally { pg_close($conn); }
            pgr_before_rollback($previous,$incoming);
        } else {
            $GLOBALS['pgr_skip_rollback'] = true;
            pgr_notice(['id'=>str_repeat('0',32)],'failed');
        }
    }
    // Profile scheduling uses accepted live traffic, never a historical MAX timestamp.
    if ($incoming > 0 && $event !== '' && !str_starts_with($event, 'updateprofile')) {
        require_once __DIR__ . '/dynamic_profile_scheduler.php';
        ptr_runtime_enter();
        $profileClockConn = ptp_connect();
        if ($profileClockConn) {
            try { dps_clock($profileClockConn, $incoming, $eligible); }
            catch (Throwable $error) { error_log('Dynamic Profiles clock: '.$error->getMessage()); }
            finally { pg_close($profileClockConn); }
        }
    }
    // Replay completed outcomes on existing traffic without using the journal as a processing gate.
    if (empty($GLOBALS['pgr_operation']) && empty($GLOBALS['pgr_skip_rollback']) && ($state['phase'] ?? '') === 'complete') {
        $generation = trim((string)@file_get_contents(dirname(__DIR__) . '/log/playthrough_runtime/generation'));
        if (($state['generation'] ?? '') === $generation && time()-(int)($state['completed_at'] ?? 0)<120) pgr_notice($state,$state['notice'] ?? 'created');
    }
}
