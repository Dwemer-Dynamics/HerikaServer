<?php
require_once __DIR__ . '/playthrough_home.php';

// Match frozen character metadata, never mutable display labels or approximate names.
function pas_select(array $rows, string $character, string $name, int $gamets, bool $allowLegacy = true): ?array {
    $matches = array_values(array_filter($rows, static function ($row) use ($character, $name) {
        if (empty($row['available'])) return false;
        if ($character !== '') return ($row['character_id'] ?? '') === $character;
        return mb_strtolower(trim($row['player_name'] ?? ''), 'UTF-8') === mb_strtolower(trim($name), 'UTF-8');
    }));
    if (!$matches && $character !== '' && $allowLegacy) {
        // A legacy archive may be associated once, only if the exact name is unique.
        $legacy = array_values(array_filter($rows, static fn($row) => !empty($row['available'])
            && empty($row['character_id']) && mb_strtolower(trim($row['player_name'] ?? ''), 'UTF-8') === mb_strtolower(trim($name), 'UTF-8')));
        return count($legacy) === 1 ? $legacy[0] : null;
    }
    if (!$matches) return null;
    if ($character === '') {
        $identities = array_unique(array_column($matches, 'character_id'));
        if (count($matches) > 1 && (count($identities) !== 1 || $identities[0] === '')) return null;
    }
    foreach ($matches as $row) if ($row['active']) return $row;
    usort($matches, static function ($a, $b) use ($gamets) {
        $aBefore = $a['last_gamets'] <= $gamets; $bBefore = $b['last_gamets'] <= $gamets;
        if ($aBefore !== $bBefore) return $aBefore ? -1 : 1;
        return ($b['last_gamets'] <=> $a['last_gamets']) ?: ($b['id'] <=> $a['id']);
    });
    return $matches[0];
}

function pas_enabled($conn): bool { return ptr_read($conn, 'PLAYTHROUGH_AUTO_SWITCH', false) === true; }

// Keep the load high-water mark when manual actions revoke a receipt.
function pas_invalidate($conn): void {
    $session = ptr_read($conn, 'PLAYTHROUGH_SESSION', []);
    $session['status'] = 'waiting';
    $session['message'] = 'Reload your Skyrim save to connect its character.';
    unset($session['token']);
    ptr_write($conn, 'PLAYTHROUGH_SESSION', $session);
}

// The shared runtime lease makes this check and the subsequent writes one generation.
function pas_guard($conn, bool $required = false): void {
    if (PHP_SAPI === 'cli' || !empty($GLOBALS['pas_checked'])) return;
    $token = $_SERVER['HTTP_X_CHIM_PLAYTHROUGH'] ?? '';
    if (!$required && $token === '') return;
    $GLOBALS['pas_checked'] = true;
    if (!pas_enabled($conn)) return;
    $session = ptr_read($conn, 'PLAYTHROUGH_SESSION', []);
    if (is_string($token) && $token !== '' && ($session['status'] ?? '') === 'ready'
        && hash_equals($session['token'] ?? '', $token)) return;
    http_response_code(409);
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo json_encode(['ok'=>false,'message'=>'CHIM is waiting for the loaded character. Open Playthrough Saves.']);
    exit;
}

// Tagged uploads must be checked before file writes or connector calls, not just SQL writes.
function pas_http_guard(): void {
    if (PHP_SAPI === 'cli' || !isset($_SERVER['HTTP_X_CHIM_PLAYTHROUGH'])) return;
    ptr_runtime_enter();
    $conn = ptp_connect();
    if (!$conn) { http_response_code(503); exit; }
    try { pas_guard($conn); } finally { pg_close($conn); }
}

// Persist the binding and handshake receipt inside the same transaction as restoration.
function pas_bind($conn, array $session, int $profile): array {
    $character = $session['character_id'];
    if ($character === '') {
        $existing = pg_fetch_assoc(pth_query($conn, "SELECT value FROM public.core_player WHERE id='playthrough_id'"));
        $character = $existing['value'] ?? '';
        if (!preg_match('/^[a-f0-9]{32}$/D', $character)) $character = bin2hex(random_bytes(16));
    }
    foreach (['playthrough_id'=>$character, 'player_name'=>$session['player_name']] as $key=>$value) {
        pth_query($conn, 'INSERT INTO public.core_player(id,value) VALUES($1,$2) ON CONFLICT(id) DO UPDATE SET value=EXCLUDED.value', [$key,$value]);
    }
    $session['character_id'] = $character;
    $session['profile_id'] = $profile;
    $session['status'] = 'ready';
    $session['token'] = bin2hex(random_bytes(16));
    $session['message'] = 'Playthrough ready. Previous progress was kept.';
    ptr_write($conn, 'PLAYTHROUGH_SESSION', $session);
    return $session;
}

// A browser selection is explicit authority to associate an otherwise ambiguous legacy save.
function pas_manual($conn, array $input): array {
    $runtime = ptr_runtime_begin_switch(30.0, $conn);
    try {
        $state = pth_state($conn);
        if (!hash_equals($state['token'], (string)$input['expected_token'])) throw new RuntimeException('The playthrough changed. Reload this page.');
        $session = ptr_read($conn, 'PLAYTHROUGH_SESSION', []);
        if (($session['status'] ?? '') !== 'pending') throw new RuntimeException('No character is waiting. Reload this page.');
        $id = filter_var($input['profile_id'] ?? null, FILTER_VALIDATE_INT);
        if (!$id || $id < 1) throw new InvalidArgumentException('Choose a Playthrough Save.');
        if ($id !== $state['active_id']) {
            $result = pth_change($conn, 'switch', $input + ['_session'=>$session], true);
        } else {
            pth_query($conn, 'BEGIN');
            pas_bind($conn, $session, $id);
            pth_query($conn, 'COMMIT');
            $result = ['success'=>true];
        }
        $ready = ptr_runtime_finish_switch($runtime);
        return array_replace($result, ['message'=>$ready ? 'Character associated. Reload the Skyrim save to resume CHIM.' : 'Character associated. Restart the mod server before reloading Skyrim.']);
    } catch (Throwable $error) {
        if (pg_transaction_status($conn) !== PGSQL_TRANSACTION_IDLE) @pg_query($conn, 'ROLLBACK');
        throw $error;
    } finally { if ($runtime !== null) ptr_runtime_finish_switch($runtime); }
}

// One load operation owns the runtime barrier; a duplicate returns its committed receipt.
function pas_handshake($conn, array $input): array {
    foreach (['client_id','character_id','player_name'] as $key) if (!is_string($input[$key] ?? null)) throw new InvalidArgumentException('Invalid character request.');
    if (!preg_match('/^[a-f0-9]{32}$/D', $input['client_id'])
        || ($input['character_id'] !== '' && !preg_match('/^[a-f0-9]{32}$/D', $input['character_id']))
        || !is_int($input['load_id'] ?? null) || $input['load_id'] < 1
        || !is_int($input['gamets'] ?? null) || $input['gamets'] < 0) throw new InvalidArgumentException('Invalid load identity.');
    if (isset($input['new_game']) && (!is_bool($input['new_game']) || ($input['new_game'] && $input['character_id'] === ''))) throw new InvalidArgumentException('Invalid new-character identity.');
    $name = trim($input['player_name']);
    if ($name === '' || !preg_match('//u', $name) || mb_strlen($name) > 80 || preg_match('/[\x00-\x1f\x7f]/u', $name)
        || in_array(mb_strtolower($name), ['player','prisoner','unknown','unknown player','null','none','the narrator'], true)) {
        return ['ok'=>false,'status'=>'pending','message'=>'Waiting for your Skyrim character name. Reload the save after naming your character.'];
    }
    $runtime = ptr_runtime_begin_switch(30.0, $conn);
    try {
        if (!pas_enabled($conn)) return ['ok'=>true,'status'=>'off','character_id'=>$input['character_id']];
        $last = ptr_read($conn, 'PLAYTHROUGH_SESSION', []);
        if (($last['client_id'] ?? '') === $input['client_id']) {
            if (($last['load_id'] ?? 0) > $input['load_id']) throw new RuntimeException('This load request is stale.');
            if (($last['load_id'] ?? 0) === $input['load_id']) return ['ok'=>($last['status'] ?? '') === 'ready'] + $last;
        }
        $retired = ptr_read($conn, 'PLAYTHROUGH_RETIRED_CLIENTS', []);
        if (in_array($input['client_id'], $retired, true)) throw new RuntimeException('This Skyrim session was replaced.');
        if (!empty($last['client_id']) && $last['client_id'] !== $input['client_id']) {
            $retired[] = $last['client_id'];
            ptr_write($conn, 'PLAYTHROUGH_RETIRED_CLIENTS', array_slice($retired, -32));
        }
        $session = array_intersect_key($input, array_flip(['client_id','load_id','character_id','gamets']));
        $session += ['player_name'=>$name, 'status'=>'pending', 'message'=>'Choose the loaded character’s Playthrough Save, then reload the Skyrim save.'];
        // Invalidate the old receipt before any possibility of failure; wrong-character writes stay blocked.
        ptr_write($conn, 'PLAYTHROUGH_SESSION', $session);
        $state = pth_state($conn);
        if (!$state['available']) return ['ok'=>false] + $session;
        $target = pas_select($state['playthroughs'], $input['character_id'], $name, $input['gamets'], empty($input['new_game']));
        if (!$target) return ['ok'=>false] + $session;
        if ($target['active']) {
            pth_query($conn, 'BEGIN');
            $session = pas_bind($conn, $session, $target['id']);
            pth_query($conn, 'COMMIT');
        } else {
            pth_change($conn, 'switch', ['profile_id'=>$target['id'], 'expected_token'=>$state['token'], '_session'=>$session], true);
            $session = ptr_read($conn, 'PLAYTHROUGH_SESSION', []);
            $session['message'] = 'Switched to ' . $target['name'] . '. Previous playthrough saved.';
        }
        $ready = ptr_runtime_finish_switch($runtime);
        if (!$ready) {
            $session['status'] = 'pending';
            $session['message'] = 'Playthrough restored. Restart the mod server before reloading Skyrim.';
            ptr_write($conn, 'PLAYTHROUGH_SESSION', $session);
        }
        return ['ok'=>$ready] + $session;
    } catch (Throwable $error) {
        if (pg_transaction_status($conn) !== PGSQL_TRANSACTION_IDLE) @pg_query($conn, 'ROLLBACK');
        throw $error;
    } finally { if ($runtime !== null) ptr_runtime_finish_switch($runtime); }
}
