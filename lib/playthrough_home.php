<?php
require_once __DIR__ . '/playthrough_retention.php';
require_once __DIR__ . '/playthrough_runtime.php';

function pth_query($conn, string $sql, array $params = []) {
    $result = @pg_query_params($conn, $sql, $params);
    if (!$result) {
        error_log('Playthrough Saves: ' . pg_last_error($conn));
        throw new RuntimeException('The operation could not finish. Your previous playthrough was kept. Check the server log for details.');
    }
    return $result;
}

// Home reads metadata only: opening the page must never capture or restore data.
function pth_state($conn): array {
    $meta = ptp_product()['meta'];
    $ready = pg_fetch_result(pth_query($conn, 'SELECT to_regclass($1) IS NOT NULL AND to_regclass($2) IS NOT NULL',
        [$meta . '.playthrough_profiles', $meta . '.settings']), 0, 0) === 't';
    if (!$ready) return ['available'=>false, 'active_id'=>0, 'token'=>'', 'playthroughs'=>[]];
    $rows = pg_fetch_all(pth_query($conn, "SELECT id,name,schema_name,is_active,storage_type,retention_kind FROM {$meta}.playthrough_profiles ORDER BY lower(name),id")) ?: [];
    $active = array_values(array_filter($rows, fn($row) => $row['is_active'] === 't'));
    if (count($active) > 1) throw new RuntimeException('More than one active playthrough is recorded. Open Manage saves before switching.');
    $id = (int)($active[0]['id'] ?? 0);
    $revision = ptr_read($conn, 'PLAYTHROUGH_HOME_REVISION', '0');
    $choices = [];
    foreach ($rows as $row) {
        // Older default saves predate retention labels but must remain selectable.
        $named = $row['retention_kind'] === 'manual' || strtolower($row['name']) === 'default';
        if ($row['is_active'] !== 't' && (!$named || $row['storage_type'] !== 'schema')) continue;
        $choices[] = ['id'=>(int)$row['id'], 'name'=>$row['name'], 'active'=>$row['is_active']==='t'];
    }
    return ['available'=>true, 'active_id'=>$id,
        'token'=>hash('sha256', json_encode([$id, $active[0]['schema_name'] ?? '', $revision])), 'playthroughs'=>$choices];
}

// Capture rows and their metadata using the same transaction as the eventual activation.
function pth_capture($conn, string $name, ?array $existing = null, string $kind = 'manual'): array {
    $product = ptp_product(); $meta = $product['meta'];
    $schema = $existing['schema_name'] ?? ($product['prefix'] . 'save_' . bin2hex(random_bytes(8)));
    if (!str_starts_with((string)$schema, $product['prefix']) || ($existing['storage_type'] ?? 'schema') !== 'schema') {
        $schema = $product['prefix'] . 'save_' . bin2hex(random_bytes(8));
    }
    $result = pts_transfer_playthrough($conn, $schema);
    if (empty($result['success'])) throw new RuntimeException('Could not save current progress. Nothing was switched.');
    $tables = array_column(pg_fetch_all(pth_query($conn, "SELECT tablename FROM pg_tables WHERE schemaname='public'")) ?: [], 'tablename');
    $event = in_array('eventlog', $tables, true)
        ? pg_fetch_assoc(pth_query($conn, 'SELECT count(*) AS count,coalesce(max(gamets),0) AS gamets FROM public.eventlog')) : ['count'=>0,'gamets'=>0];
    $knowledge = $meta === 'chim_meta' ? 'oghma' : ($meta === 'stobe_meta' ? 'world_knowledge' : 'worldknowledge');
    $knowledgeCount = in_array($knowledge, $tables, true) ? pg_fetch_result(pth_query($conn, 'SELECT count(*) FROM public.' . $knowledge),0,0) : 0;
    $player = '';
    if (in_array('core_player', $tables, true)) {
        $row = pg_fetch_assoc(pth_query($conn, "SELECT value FROM public.core_player WHERE id='player_name'"));
        $player = $row['value'] ?? '';
    } elseif (in_array('general_settings', $tables, true)) {
        $row = pg_fetch_assoc(pth_query($conn, "SELECT value FROM public.general_settings WHERE id='PLAYER_NAME'"));
        $player = $row['value'] ?? '';
    }
    $fields = ['name'=>$name,'schema_name'=>$schema,'storage_type'=>'schema','size_bytes'=>pts_get_schema_size($conn,$schema),
        'player_name'=>$player,'eventlog_count'=>(int)$event['count'],'last_gamets'=>(int)$event['gamets'],
        ($meta==='dialectic_meta'?'worldknowledge_count':'oghma_count')=>(int)$knowledgeCount];
    if ($meta === 'stobe_meta' && in_array('conf_opts', $tables, true)) {
        $squads = pg_fetch_assoc(pth_query($conn, "SELECT value FROM public.conf_opts WHERE id='PLAYER_SQUADS'"));
        $members = [];
        foreach ((array)json_decode($squads['value'] ?? '[]', true) as $squad) {
            if (!is_string($squad)) continue;
            $row = pg_fetch_assoc(pth_query($conn, 'SELECT value FROM public.conf_opts WHERE id=$1', [$squad]));
            foreach ((array)json_decode($row['value'] ?? '[]', true) as $entry) {
                if (!is_string($entry)) continue;
                $member = trim(explode('|', $entry, 2)[0]);
                if ($member !== '') $members[strtolower($member)] = $member;
            }
        }
        natcasesort($members);
        $fields['player_faction_members'] = json_encode(array_values($members));
    }
    if ($existing) {
        $values = array_values($fields); $sets = []; $i = 1;
        foreach (array_keys($fields) as $column) $sets[] = $column . '=$' . $i++;
        $values[] = (int)$existing['id'];
        pth_query($conn, "UPDATE {$meta}.playthrough_profiles SET " . implode(',', $sets) . ' WHERE id=$' . $i, $values);
        return ['id'=>(int)$existing['id'], 'schema_name'=>$schema];
    }
    $fields += ['retention_kind'=>$kind,'is_active'=>'false','game'=>($meta==='chim_meta'?'Skyrim':($meta==='stobe_meta'?'Kenshi':'Fallout')),
        'notes'=>($kind==='before_switch'?'Saved automatically before switching.':'')];
    $slots = array_map(fn($n) => '$'.$n, range(1,count($fields)));
    return pg_fetch_assoc(pth_query($conn, "INSERT INTO {$meta}.playthrough_profiles(" . implode(',',array_keys($fields)) . ') VALUES(' . implode(',',$slots) . ') RETURNING id,schema_name',array_values($fields)));
}

// Both the full manager and home controls use this one guarded switch/new operation.
function pth_change($conn, string $action, array $input): array {
    if (!in_array($action,['switch','new'],true)) throw new InvalidArgumentException('Unknown playthrough action.');
    $runtime = null; $locked = false; $meta = ptp_product()['meta'];
    try {
        $runtime = ptr_runtime_begin_switch(30.0,$conn);
        if (!ptr_lock($conn)) throw new RuntimeException('Another Playthrough Save operation is running. Try again shortly.');
        $locked = true;
        pth_query($conn,'BEGIN');
        pth_query($conn, "SET LOCAL lock_timeout='2s'");
        $state = pth_state($conn);
        if (!$state['available']) throw new RuntimeException('Open Manage saves to set up Playthrough Saves first.');
        if (isset($input['expected_token']) && !hash_equals($state['token'], (string)$input['expected_token'])) {
            throw new RuntimeException('The active playthrough changed in another window. Reload this page before trying again.');
        }
        $current = pg_fetch_assoc(pth_query($conn,"SELECT * FROM {$meta}.playthrough_profiles WHERE is_active FOR UPDATE")) ?: null;
        if ($action === 'switch') {
            $id = filter_var($input['profile_id'] ?? null,FILTER_VALIDATE_INT);
            if (!$id || $id < 1) throw new InvalidArgumentException('Choose a valid playthrough.');
            $target = pg_fetch_assoc(pth_query($conn,"SELECT * FROM {$meta}.playthrough_profiles WHERE id=$1 FOR UPDATE",[$id]));
            if (!$target || $target['storage_type'] !== 'schema') throw new RuntimeException('That Playthrough Save is unavailable or unsupported.');
            if ($target['is_active'] === 't') throw new RuntimeException('That playthrough is already active.');
            $stage = pts_prepare_playthrough($conn,$target['schema_name']);
            $name = $target['name'];
        } else {
            $name = trim((string)($input['name'] ?? ''));
            if ($name === '' || mb_strlen($name) > 160 || preg_match('/[\x00-\x1f\x7f]/u',$name)) throw new InvalidArgumentException('Enter a playthrough name of 1–160 characters.');
            if (pg_num_rows(pth_query($conn,"SELECT id FROM {$meta}.playthrough_profiles WHERE lower(name)=lower($1)",[$name]))) {
                throw new InvalidArgumentException('A playthrough with that name already exists. Choose another name.');
            }
        }
        $autosaveId = 0;
        if ($meta === 'stobe_meta' && ($input['recovery_copy'] ?? true)) {
            $recovery = pth_capture($conn,'Before-Switch Save ' . gmdate('Y-m-d H:i:s') . ' ' . bin2hex(random_bytes(3)),null,'before_switch');
            $autosaveId = (int)$recovery['id'];
        }
        $saved = pth_capture($conn,$current['name'] ?? ('Previous playthrough ' . gmdate('Y-m-d H:i:s') . ' ' . bin2hex(random_bytes(3))),$current);
        if ($action === 'new') {
            $stage = pts_prepare_playthrough($conn,$saved['schema_name']);
            require_once __DIR__ . '/playthrough_fresh.php';
            pth_prepare_fresh($conn,$stage);
        }
        $result = pts_activate_playthrough($conn,$stage);
        if (empty($result['success'])) throw new RuntimeException('Could not load the playthrough. Your previous data was kept.');
        if ($action === 'new') $id = (int)pth_capture($conn,$name)['id'];
        pth_query($conn,"UPDATE {$meta}.playthrough_profiles SET is_active=(id=$1)",[$id]);
        ptr_write($conn,'PLAYTHROUGH_HOME_REVISION',bin2hex(random_bytes(16)));
        pth_query($conn,'COMMIT');
        ptr_unlock($conn); $locked = false;
        $ready = ptr_runtime_finish_switch($runtime);
        return ['success'=>true,'error'=>'','id'=>$id,'name'=>$name,'runtime_ready'=>$ready,'autosave_id'=>$autosaveId,
            'message'=>($action==='new'?'New playthrough ready: ':'Playthrough loaded: ') . $name . '. ' .
                ($ready?($action==='new'?'Start your new game.':'Load the matching game save.'):'Background processing could not be confirmed. Restart this mod server before starting the game.')];
    } catch (Throwable $error) {
        if (pg_transaction_status($conn) !== PGSQL_TRANSACTION_IDLE) @pg_query($conn,'ROLLBACK');
        throw $error;
    } finally {
        if ($locked) ptr_unlock($conn);
        if ($runtime !== null) ptr_runtime_finish_switch($runtime);
    }
}
