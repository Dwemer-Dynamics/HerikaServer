<?php
// Profile policy is global; scheduling progress is saved gameplay in conf_opts.
require_once __DIR__ . '/playthrough_preferences.php';

function dps_product(): array {
    return ['npc_table'=>'core_npc_master', 'name'=>'npc_name', 'day'=>10000000, 'prefix'=>'chim'];
}

function dps_query($conn, string $sql, array $params = []) {
    $result = pg_query_params($conn, $sql, $params);
    if ($result === false) throw new RuntimeException('Dynamic profile database operation failed.');
    return $result;
}

function dps_json($value): array {
    if (is_array($value)) return $value;
    return json_decode((string)$value, true) ?: [];
}

function dps_state($conn, string $key): array {
    $row = pg_fetch_assoc(dps_query($conn, 'SELECT value FROM public.conf_opts WHERE id=$1', [$key]));
    return dps_json($row['value'] ?? '');
}

function dps_store($conn, string $key, array $value): void {
    dps_query($conn, 'INSERT INTO public.conf_opts(id,value) VALUES($1,$2) ON CONFLICT(id) DO UPDATE SET value=EXCLUDED.value',
        [$key, json_encode($value, JSON_THROW_ON_ERROR)]);
}

// Record accepted live time; delayed packets cannot rewind the clock outside load events.
function dps_clock($conn, int $gamets, bool $loading = false): void {
    if ($gamets <= 0) return;
    dps_query($conn, 'BEGIN');
    try {
        dps_query($conn, "SELECT pg_advisory_xact_lock(hashtext('dynamic_profile_clock'))");
        $clock = dps_state($conn, 'DYNAMIC_PROFILE_CLOCK');
        if (!$clock || ($loading && $gamets < ($clock['gamets'] ?? 0))) {
            $clock = ['epoch'=>bin2hex(random_bytes(16)), 'gamets'=>$gamets, 'started'=>$gamets];
        } else {
            $clock['gamets'] = max($gamets, (int)$clock['gamets']);
        }
        $clock['seen'] = time();
        dps_store($conn, 'DYNAMIC_PROFILE_CLOCK', $clock);
        dps_query($conn, 'COMMIT');
    } catch (Throwable $e) { pg_query($conn, 'ROLLBACK'); throw $e; }
}

function dps_policy(array $metadata): array {
    $limits = ['DYNAMIC_PROFILE_INTERVAL_DAYS'=>[1, 1/24, 365],
        'DYNAMIC_PROFILE_MIN_EVENTS'=>[30, 1, 10000], 'DYNAMIC_PROFILE_COOLDOWN_MINUTES'=>[5, 1, 1440]];
    $policy = [];
    foreach ($limits as $key=>[$default,$min,$max]) {
        $value = $metadata[$key] ?? $default;
        $policy[$key] = is_numeric($value) ? max($min,min($max,(float)$value)) : $default;
    }
    $policy['DYNAMIC_PROFILE_MIN_EVENTS'] = (int)$policy['DYNAMIC_PROFILE_MIN_EVENTS'];
    return $policy;
}

// Resolve one effective policy without letting a template override an explicit NPC disable.
function dps_candidates($conn): array {
    $product = dps_product();
    $rows = pg_fetch_all(dps_query($conn, "SELECT n.*, p.metadata AS profile_metadata
        FROM public.{$product['npc_table']} n JOIN public.core_profiles p ON p.id=n.profile_id ORDER BY n.id")) ?: [];
    $candidates = [];
    foreach ($rows as $row) {
        $metadata = array_replace(dps_json($row['profile_metadata']), dps_json($row['metadata'] ?? ''));
        $enabled = $metadata['DYNAMIC_PROFILE_ENABLED'] ?? $row['dynamic_profile'] ?? false;
        if (isset($row['dynamic_profile']) && !in_array($row['dynamic_profile'], [true,1,'1','t','true'],true)) $enabled = false;
        $name = trim((string)$row[$product['name']]);
        if ($name === '' || $name === 'The Narrator') continue;
        $allowed = $product['prefix'] === 'stobe' ? ['backstory','personality','occupation','speechstyle','goals'] : ['personality','occupation','skills','speechstyle','goals'];
        $fields = $metadata['DYNAMIC_PROFILE_FIELDS'] ?? ['personality','speechstyle','goals'];
        $fields = array_values(array_intersect(is_array($fields) ? $fields : [], $allowed));
        $row['key'] = 'DYNAMIC_PROFILE_STATE_NPC_' . (int)$row['id'];
        $row['name'] = $name;
        $row['fields'] = $fields;
        $row['policy'] = dps_policy($metadata);
        $row['enabled'] = filter_var($enabled, FILTER_VALIDATE_BOOLEAN) && !in_array($row['lock_profile'] ?? false,[true,1,'1','t','true'],true) && count($fields)>0;
        $row['metadata_effective'] = $metadata;
        $candidates[] = $row;
    }
    $data = array_column(pg_fetch_all(dps_query($conn,'SELECT id,value FROM public.core_narrator')) ?: [],'value','id');
    $narratorFields = array_values(array_intersect(dps_json($data['dynamic_profile_fields'] ?? ''),['personality','speechstyle','goals']));
    $candidates[] = ['id'=>0, 'name'=>'The Narrator', 'key'=>'DYNAMIC_PROFILE_STATE_NARRATOR',
        'fields'=>$narratorFields, 'enabled'=>filter_var($data['dynamic_profile'] ?? false,FILTER_VALIDATE_BOOLEAN) && count($narratorFields)>0,
        'policy'=>dps_policy($data), 'metadata_effective'=>$data];
    return $candidates;
}

// Use the same recorded audience as gameplay context, including Stobe's stable actor identities.
function dps_audience(array $npc, array &$params): string {
    if ((int)$npc['id'] === 0) return 'TRUE';
    if (dps_product()['prefix'] === 'stobe') return stobeEventAudienceSql($npc['name'], $params, [], $npc);
    require_once __DIR__ . '/eventlog_helper.php';
    $fn = dps_product()['prefix'] . 'BuildNpcEventLogPeopleWhereClause';
    return $fn($GLOBALS['db'], $npc['name']);
}

function dps_event_filter(bool $counting = true): string {
    return ($counting ? "type<>'combatbark' AND " : '') . "gamets>0 AND type NOT IN ('prechat','rechat','bored','request','user_input','infonpc',
        'infonpc_close','addnpc','addbgnpc','infosave','init','playerinfo','npc_snapshot','setconf','status_msg',
        'oghma_import','biography_import','dynamic_oghma_import','infoitems','description_import',
        'traditional_quest_import','backgroundaction','innerchat','npcvoice_refresh','region','relationship',
        'updateprofile','updateprofile_narrator','updateprofiles_batch_async','updateprofiles_batch_async_manual')
        AND (COALESCE(utterance_id,'')='' OR COALESCE(NULLIF(delivery_state,''),'spoken')='spoken')";
}

// Account a bounded batch atomically. Pending delivery is revisited, and cleanup waits for accounting.
function dps_account($conn, array $candidates, array $clock): int {
    dps_query($conn, 'BEGIN');
    try {
        dps_query($conn, "SELECT pg_advisory_xact_lock(hashtext('dynamic_profile_clock'))");
        if ((dps_state($conn, 'DYNAMIC_PROFILE_CLOCK')['epoch'] ?? '') !== $clock['epoch']) {
            dps_query($conn, 'ROLLBACK'); return 0;
        }
        $events = pg_fetch_all(dps_query($conn, "SELECT rowid FROM public.eventlog WHERE dynamic_profile_pending
            AND (COALESCE(utterance_id,'')='' OR COALESCE(delivery_state,'') NOT IN ('pending','emitted'))
            ORDER BY rowid LIMIT 200 FOR UPDATE SKIP LOCKED")) ?: [];
        if (!$events) { dps_query($conn,'COMMIT'); return 0; }
        $ids = implode(',',array_map('intval',array_column($events,'rowid')));
        foreach ($candidates as $npc) {
            $state = dps_state($conn,$npc['key']);
            if (($state['epoch'] ?? '') !== $clock['epoch']) {
                $state = ['epoch'=>$clock['epoch'],'last_game'=>$clock['gamets'],'total'=>0,'consumed'=>0,'attempt'=>0];
            }
            $params = [];
            $audience = dps_audience($npc,$params);
            $count = (int)pg_fetch_result(dps_query($conn, "SELECT count(*) FROM public.eventlog WHERE rowid IN ($ids)
                AND gamets >= " . (int)$state['last_game'] . ' AND ' . dps_event_filter() . " AND ($audience)", $params),0,0);
            $state['total'] += $count;
            dps_store($conn,$npc['key'],$state);
        }
        dps_query($conn,"UPDATE public.eventlog SET dynamic_profile_pending=false WHERE rowid IN ($ids)");
        dps_query($conn,'COMMIT');
        return count($events);
    } catch (Throwable $e) { pg_query($conn,'ROLLBACK'); throw $e; }
}

function dps_due(array $state, array $policy, int $gamets, int $now, bool $manual = false): bool {
    return $state && $now-(int)$state['attempt'] >= $policy['DYNAMIC_PROFILE_COOLDOWN_MINUTES']*60
        && ($manual || ($gamets-(int)$state['last_game'] >= $policy['DYNAMIC_PROFILE_INTERVAL_DAYS']*dps_product()['day']
            && (int)$state['total']-(int)$state['consumed'] >= $policy['DYNAMIC_PROFILE_MIN_EVENTS']));
}

function dps_context($conn, array $npc, int $gamets): string {
    $params = [];
    $audience = dps_audience($npc,$params);
    $limit = (int)($npc['metadata_effective']['CONTEXT_HISTORY_DYNAMIC_PROFILE'] ?? 50);
    if ($limit <= 0) $limit = (int)($npc['metadata_effective']['CONTEXT_HISTORY'] ?? 50);
    $limit = max(1,min(400,$limit));
    $rows = pg_fetch_all(dps_query($conn,'SELECT type,data,gamets,location FROM public.eventlog WHERE '
        . dps_event_filter(false) . " AND ($audience) AND gamets <= $gamets ORDER BY rowid DESC LIMIT $limit",$params)) ?: [];
    return implode("\n",array_map(static fn($row)=>'['.$row['gamets'].' '.$row['type'].' '.$row['location'].'] '.mb_substr($row['data'],0,2000),array_reverse($rows)));
}

// Only explicit manual actions carry overrides; old client timer batches have no scheduling authority.
function dps_request(array $names): int {
    $conn = ptp_connect();
    if (!$conn) return 0;
    try {
        $clock = dps_state($conn,'DYNAMIC_PROFILE_CLOCK');
        if (!$clock) return 0;
        $count = 0;
        foreach (dps_candidates($conn) as $npc) {
            if (!$npc['enabled'] || !in_array($npc['name'],$names,true)) continue;
            dps_store($conn,'DYNAMIC_PROFILE_MANUAL_'.(int)$npc['id'],['epoch'=>$clock['epoch'],'requested'=>time()]);
            $count++;
        }
        return $count;
    } finally { pg_close($conn); }
}

// Check the existing connector route before consuming a retry slot.
function dps_connector_ready(array $npc): bool {
    if (dps_product()['prefix'] === 'stobe') {
        $data = $npc['id'] ? $npc : stobeBuildNarratorNpcData();
        $config = getLlmConfigForNpcPurpose($data, 'dynamic');
        return trim((string)($config['api_key'] ?? '')) !== '' && trim((string)($config['model'] ?? '')) !== '';
    }
    if (function_exists('chimIsGlobalLlmConnectorEnabled') && !chimIsGlobalLlmConnectorEnabled('CORE_CONNECTOR_PROFILES')) return false;
    require_once __DIR__ . '/core/llm_connector.class.php';
    $connector = (new LLMConnector())->getById((int)($GLOBALS['CORE_CONNECTOR_PROFILES'] ?? 0));
    return !empty($connector['driver']) && !empty($connector['model']);
}

// One NPC per worker pass; attempts determine ordering so a failing NPC cannot starve others.
function dps_run(?string $manualName = null, ?callable $generator = null, $connection = null): array {
    $result = ['updated'=>0,'npcs'=>0,'events'=>0];
    $conn = $connection ?? ptp_connect();
    if (!$conn) return $result;
    $locked = false;
    try {
        if (pg_fetch_result(dps_query($conn,"SELECT pg_try_advisory_lock(hashtext('dynamic_profile_scheduler'))"),0,0) !== 't') return $result;
        $locked = true;
        $clock = dps_state($conn,'DYNAMIC_PROFILE_CLOCK');
        if (!$clock || pg_fetch_result(dps_query($conn,"SELECT EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='eventlog' AND column_name='dynamic_profile_pending')"),0,0) !== 't') return $result;
        $candidates = dps_candidates($conn);
        $result['events'] = dps_account($conn,$candidates,$clock);
        if ((dps_state($conn,'DYNAMIC_PROFILE_CLOCK')['epoch'] ?? '') !== $clock['epoch']) return $result;
        $allowed = dps_product()['prefix'].'InteractionAllowed';
        if (!$allowed() || time()-(int)($clock['seen'] ?? 0)>300) return $result;
        foreach ($candidates as &$npc) {
            $npc['state'] = dps_state($conn,$npc['key']);
            $request = dps_state($conn,'DYNAMIC_PROFILE_MANUAL_'.(int)$npc['id']);
            $npc['manual'] = ($request['epoch'] ?? '') === $clock['epoch'] && time()-(int)($request['requested'] ?? 0)<3600;
        }
        unset($npc);
        usort($candidates,static fn($a,$b)=>($a['state']['attempt'] ?? 0)<=>($b['state']['attempt'] ?? 0));
        foreach ($candidates as $npc) {
            if (!$npc['enabled'] || ($manualName !== null && $npc['name'] !== $manualName)) continue;
            $state = $npc['state'];
            if (($state['epoch'] ?? '') !== $clock['epoch'] || !dps_due($state,$npc['policy'],(int)$clock['gamets'],time(),$manualName!==null || $npc['manual'])) continue;
            if ($generator === null && !dps_connector_ready($npc)) continue;
            $history = dps_context($conn,$npc,(int)$clock['gamets']);
            if ($history === '') continue;
            $state['attempt'] = time();
            dps_store($conn,$npc['key'],$state);
            dps_query($conn,'DELETE FROM public.conf_opts WHERE id=$1',['DYNAMIC_PROFILE_MANUAL_'.(int)$npc['id']]);
            $result['npcs']++;
            $updates = ($generator ?? 'dps_generate')($npc,$history);
            if (!$updates || !$allowed()) break;
            dps_query($conn,'BEGIN');
            try {
                dps_query($conn,"SELECT pg_advisory_xact_lock(hashtext('dynamic_profile_clock'))");
                if ($npc['id']) {
                    $table = dps_product()['npc_table'];
                    dps_query($conn,"SELECT id FROM public.$table WHERE id=$1 FOR UPDATE",[(int)$npc['id']]);
                } else {
                    dps_query($conn,'SELECT id FROM public.core_narrator FOR UPDATE');
                }
                $current = dps_state($conn,'DYNAMIC_PROFILE_CLOCK');
                $fresh = array_values(array_filter(dps_candidates($conn),static fn($row)=>$row['key']===$npc['key']));
                if (($current['epoch'] ?? '') !== $clock['epoch'] || !$fresh || !$fresh[0]['enabled']
                    || $fresh[0]['name'] !== $npc['name'] || ($fresh[0]['profile_id'] ?? null) !== ($npc['profile_id'] ?? null)
                    || $fresh[0]['metadata_effective'] !== $npc['metadata_effective'] || !$allowed()) {
                    dps_query($conn,'ROLLBACK'); break;
                }
                foreach ($npc['fields'] as $field) {
                    if (($fresh[0][$field] ?? null) !== ($npc[$field] ?? null)) {
                        dps_query($conn,'ROLLBACK'); return $result;
                    }
                }
                dps_save($conn,$npc,$updates,(int)$clock['gamets']);
                $state['consumed'] = $state['total'];
                $state['last_game'] = $clock['gamets'];
                dps_store($conn,$npc['key'],$state);
                dps_query($conn,'COMMIT');
                $result['updated']++;
            } catch (Throwable $e) { pg_query($conn,'ROLLBACK'); throw $e; }
            break;
        }
    } catch (Throwable $e) { error_log('Dynamic Profiles: '.$e->getMessage()); }
    finally {
        if ($locked) pg_query($conn,"SELECT pg_advisory_unlock(hashtext('dynamic_profile_scheduler'))");
        if ($connection === null) pg_close($conn);
    }
    return $result;
}

function dps_generate(array $npc, string $history): array {
    if (dps_product()['prefix'] === 'stobe') {
        $data = $npc['id'] ? getNpcData($npc['name']) : stobeBuildNarratorNpcData();
        $data['metadata'] = array_replace(dps_json($data['metadata'] ?? ''),['DYNAMIC_PROFILE_FIELDS'=>$npc['fields']]);
        $generated = stobeDynamicProfileGenerateUpdates($npc['name'],$data,$history);
        return !empty($generated['ok']) && count($generated['updates'] ?? [])===count($npc['fields']) ? $generated['updates'] : [];
    }
    $updates = [];
    foreach ($npc['fields'] as $field) {
        $allowed = dps_product()['prefix'].'InteractionAllowed';
        if (!$allowed()) return [];
        $value = updateDynamicProfileField($npc['name'],$field,$history);
        if ($value === false || trim((string)$value)==='') return [];
        if ($field==='skills' && function_exists('getInGameSkillDataFor')) $value .= "\n".getInGameSkillDataFor($npc['name']);
        $updates[$field] = $value;
    }
    return $updates;
}

// Patch only generated fields and their game timestamp in the same transaction as progress.
function dps_save($conn, array $npc, array $updates, int $gamets): void {
    if (count($updates) !== count($npc['fields'])) throw new RuntimeException('Incomplete generated profile.');
    $product = dps_product();
    $sets = []; $values = [];
    foreach ($updates as $field=>$value) {
        if (!in_array($field,$npc['fields'],true) || !is_string($value) || trim($value)==='') throw new RuntimeException('Invalid generated profile field.');
        if (!$npc['id']) {
            if ($field==='backstory') $field='background';
            dps_query($conn,'INSERT INTO public.core_narrator(id,value) VALUES($1,$2) ON CONFLICT(id) DO UPDATE SET value=EXCLUDED.value',[$field,$value]);
        } else {
            $values[] = $value;
            $sets[] = pg_escape_identifier($conn,$field).'=$'.count($values);
        }
    }
    if ($npc['id']) {
        // Keep the existing rollback history format, using this transaction's connection.
        if ($product['prefix']==='stobe') {
            $before = pg_fetch_assoc(dps_query($conn,'SELECT * FROM public.core_npc_master WHERE id=$1',[(int)$npc['id']]));
            $snapshot = stobeBuildNpcHistorySnapshotPayloadFromRow($before,'dynamic_profile');
            if (!$snapshot) throw new RuntimeException('Cannot prepare NPC recovery history.');
            $columns = array_map(static fn($key)=>pg_escape_identifier($conn,$key),array_keys($snapshot));
            $parameters = array_map(static fn($i)=>'$'.$i,range(1,count($snapshot)));
            dps_query($conn,'INSERT INTO public.core_npc_master_history('.implode(',',$columns).') VALUES('.implode(',',$parameters).')',
                array_map(static fn($value)=>is_bool($value)?($value?'true':'false'):$value,array_values($snapshot)));
        } else {
            $columns = pg_fetch_all(dps_query($conn,"SELECT n.column_name FROM information_schema.columns n
                JOIN information_schema.columns h ON h.table_schema='public' AND h.table_name='core_npc_master_history' AND h.column_name=n.column_name
                WHERE n.table_schema='public' AND n.table_name='core_npc_master' AND n.column_name<>'id' ORDER BY n.ordinal_position")) ?: [];
            $names = implode(',',array_map(static fn($column)=>pg_escape_identifier($conn,$column['column_name']),$columns));
            dps_query($conn,"INSERT INTO public.core_npc_master_history(npc_id,$names) SELECT id,$names FROM public.core_npc_master WHERE id=$1",[(int)$npc['id']]);
        }
        $values[]=$gamets; $sets[]='gamets_last_updated=$'.count($values);
        $values[]=(int)$npc['id'];
        $saved = dps_query($conn,"UPDATE public.{$product['npc_table']} SET ".implode(',',$sets).' WHERE id=$'.count($values),$values);
        if (pg_affected_rows($saved)!==1) throw new RuntimeException('NPC changed during profile update.');
    } else {
        dps_query($conn,"INSERT INTO public.core_narrator(id,value) VALUES('gamets_last_updated',$1) ON CONFLICT(id) DO UPDATE SET value=EXCLUDED.value",[(string)$gamets]);
    }
}
