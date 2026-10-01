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
    $byId = array_column($rows,null,'id');
    $chim = $product['prefix'] !== 'stobe';
    if ($chim) require_once __DIR__ . '/core/npc_profile_sharing.php';
    foreach ($rows as $row) {
        $metadata = array_replace(dps_json($row['profile_metadata']), dps_json($row['metadata'] ?? ''));
        $enabled = $metadata['DYNAMIC_PROFILE_ENABLED'] ?? $row['dynamic_profile'] ?? false;
        if (isset($row['dynamic_profile']) && !in_array($row['dynamic_profile'], [true,1,'1','t','true'],true)) $enabled = false;
        $name = trim((string)$row[$product['name']]);
        // CHIM: a physical row named "The Narrator" is an ordinary actor; the typed Narrator is id 0 below.
        if ($name === '' || (!$chim && $name === 'The Narrator')) continue;
        if ($chim) {
            // Shared character fields belong to the explicit profile owner; physical provenance stays this row.
            $ownerId = (int)($row['profile_owner_npc_id'] ?? 0);
            $row['owner_id'] = $ownerId ?: (int)$row['id'];
            $row['binding'] = chimNpcProfileBinding($row);
            $row['actor_key'] = chimNpcRowActorKey($row);
            $row['owner_binding'] = '';
            if ($ownerId) {
                $owner = $byId[$ownerId] ?? null;
                if (!$owner || !empty($owner['profile_owner_npc_id'])) continue;
                foreach (CHIM_SHARED_NPC_FIELDS as $shared) $row[$shared] = $owner[$shared] ?? null;
                $row['owner_binding'] = chimNpcProfileBinding($owner);
            }
        }
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
    $narrator = ['id'=>0, 'name'=>'The Narrator', 'key'=>'DYNAMIC_PROFILE_STATE_NARRATOR', 'narrator'=>true, 'owner_id'=>0,
        'binding'=>'', 'owner_binding'=>'', 'actor_key'=>defined('CHIM_ACTOR_KEY_NARRATOR') ? CHIM_ACTOR_KEY_NARRATOR : 'narrator',
        'fields'=>$narratorFields, 'enabled'=>filter_var($data['dynamic_profile'] ?? false,FILTER_VALIDATE_BOOLEAN) && count($narratorFields)>0,
        'policy'=>dps_policy($data), 'metadata_effective'=>$data];
    foreach ($narratorFields as $field) $narrator[$field] = $data[$field] ?? null;
    $candidates[] = $narrator;
    return $candidates;
}

// Use the same recorded audience as gameplay context, including Stobe's stable actor identities.
function dps_audience(array $npc, array &$params): string {
    if ((int)$npc['id'] === 0) return 'TRUE';
    if (dps_product()['prefix'] === 'stobe') return stobeEventAudienceSql($npc['name'], $params, [], $npc);
    require_once __DIR__ . '/eventlog_helper.php';
    if (dps_product()['prefix'] === 'chim') {
        // Physical key plus explicitly linked references sharing the kept profile; legacy name-only rows never count.
        return chimBuildEventLogActorKeysWhereClause($GLOBALS['db'], chimResolveContextActorKeys($GLOBALS['db'], $npc));
    }
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
    if (!$state) return false;
    // Explicit requests bypass scheduling thresholds, including the real-time cooldown.
    if ($manual) return true;
    return $now-(int)$state['attempt'] >= $policy['DYNAMIC_PROFILE_COOLDOWN_MINUTES']*60
        && $gamets-(int)$state['last_game'] >= $policy['DYNAMIC_PROFILE_INTERVAL_DAYS']*dps_product()['day']
        && (int)$state['total']-(int)$state['consumed'] >= $policy['DYNAMIC_PROFILE_MIN_EVENTS'];
}

function dps_context_limit(array $npc): int {
    $limit = (int)($npc['metadata_effective']['CONTEXT_HISTORY_DYNAMIC_PROFILE'] ?? 50);
    if ($limit <= 0) $limit = (int)($npc['metadata_effective']['CONTEXT_HISTORY'] ?? 50);
    return max(1,min(400,$limit));
}

function dps_context($conn, array $npc, int $gamets): string {
    try {
        $limit = dps_context_limit($npc);
        // The candidate row itself scopes keyed history; the Narrator uses its reserved key.
        require_once __DIR__ . '/core/npc_reference.php';
        $data = DataLastDataExpandedForNPC($npc["name"], $limit*-1, "", (int)$npc['id'] === 0 ? CHIM_ACTOR_KEY_NARRATOR : $npc);
        $context = [];
        foreach ($data as $k => $v) {
            $context[] = $v["content"];
        }
        return implode("\n", $context);
    } catch (Throwable $e) {
        $params = [];
        $audience = dps_audience($npc,$params);
        $limit = dps_context_limit($npc);
        $rows = pg_fetch_all(dps_query($conn,'SELECT type,data,gamets,location FROM public.eventlog WHERE '
            . dps_event_filter(false) . " AND ($audience) AND gamets <= $gamets ORDER BY rowid DESC LIMIT $limit",$params)) ?: [];
        return implode("\n",array_map(static fn($row)=>'['.$row['gamets'].' '.$row['type'].' '.$row['location'].'] '.mb_substr($row['data'],0,2000),array_reverse($rows)));
    }
}

// Playthrough runtime generation, captured before a model call and rechecked before writing.
function dps_runtime_generation(): string {
    $path = dirname(__DIR__) . '/log/playthrough_runtime/generation';
    clearstatcache(true,$path);
    return is_file($path) ? trim((string)@file_get_contents($path)) : '';
}

// Only explicit manual actions carry overrides; old client timer batches have no scheduling authority.
function dps_request(array $names): int {
    $conn = ptp_connect();
    if (!$conn) {
        error_log('[DPS] Manual request rejected: database unavailable.');
        return 0;
    }
    try {
        $clock = dps_state($conn,'DYNAMIC_PROFILE_CLOCK');
        if (!$clock) {
            error_log('[DPS] Manual request rejected: game clock unavailable.');
            return 0;
        }
        $count = 0;
        $all = dps_candidates($conn);
        $names = dps_resolve_selectors($all,$names);
        foreach ($all as $npc) {
            // Numeric entries select an exact physical row; the bare "The Narrator" selects only the typed narrator.
            $hit = false;
            foreach ($names as $selector) $hit = $hit || dps_manual_match($npc,$selector);
            if (!$hit) continue;
            if (!$npc['enabled']) {
                dps_log($npc,'manual_request_disabled_or_locked');
                continue;
            }
            dps_store($conn,'DYNAMIC_PROFILE_MANUAL_'.(int)$npc['id'],
                ['epoch'=>$clock['epoch'],'requested'=>time(),'token'=>bin2hex(random_bytes(16))]);
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

// Report only identity and reason keys, never whole profiles, prompts, or metadata values.
function dps_log(array $npc, string $reason, array $details = []): void {
    error_log('[DPS] '.json_encode(['npc_id'=>(int)$npc['id'],'npc_name'=>$npc['name'],
        'reason'=>$reason] + $details, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR));
}

// Compare update inputs, not volatile gameplay metadata such as inventory and activity timestamps.
function dps_conflicts(array $npc, array $fresh): array {
    $changed = [];
    if (!$fresh) return ['npc_missing'];
    if (!$fresh['enabled']) $changed[] = 'npc_disabled_or_locked';
    foreach (['name','profile_id','fields','policy','actor_key','binding','owner_id','owner_binding'] as $key) {
        if (($fresh[$key] ?? null) !== ($npc[$key] ?? null)) $changed[] = $key;
    }
    if (dps_context_limit($fresh) !== dps_context_limit($npc)) $changed[] = 'context_history_limit';
    foreach ($npc['fields'] as $field) {
        if (($fresh[$field] ?? null) !== ($npc[$field] ?? null)) $changed[] = $field;
    }
    return $changed;
}

// One NPC per worker pass; attempts determine ordering so a failing NPC cannot starve others.
// $manualName selects an exact row: an integer id (or numeric string), or "The Narrator" for the typed narrator.
function dps_run($manualName = null, ?callable $generator = null, $connection = null): array {
    $result = ['updated'=>0,'npcs'=>0,'events'=>0];
    $conn = $connection ?? ptp_connect();
    if (!$conn) return $result;
    $locked = false;
    $manualAttempt = null;
    $activeNpc = null;
    try {
        if (pg_fetch_result(dps_query($conn,"SELECT pg_try_advisory_lock(hashtext('dynamic_profile_scheduler'))"),0,0) !== 't') return $result;
        $locked = true;
        $clock = dps_state($conn,'DYNAMIC_PROFILE_CLOCK');
        if (!$clock || pg_fetch_result(dps_query($conn,"SELECT EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='eventlog' AND column_name='dynamic_profile_pending')"),0,0) !== 't') return $result;
        $candidates = dps_candidates($conn);
        if ($manualName !== null) {
            $manualName = dps_resolve_selectors($candidates,[$manualName])[0] ?? null;
            if ($manualName === null) return $result;
        }
        $result['events'] = dps_account($conn,$candidates,$clock);
        if ((dps_state($conn,'DYNAMIC_PROFILE_CLOCK')['epoch'] ?? '') !== $clock['epoch']) return $result;
        $allowed = dps_product()['prefix'].'InteractionAllowed';
        if (!$allowed() || time()-(int)($clock['seen'] ?? 0)>300) return $result;
        foreach ($candidates as &$npc) {
            $npc['state'] = dps_state($conn,$npc['key']);
            $request = dps_state($conn,'DYNAMIC_PROFILE_MANUAL_'.(int)$npc['id']);
            $npc['manual_request'] = $request;
            $npc['manual'] = ($request['epoch'] ?? '') === $clock['epoch']
                && time()-(int)($request['requested'] ?? 0)<3600 && (int)($request['attempts'] ?? 0)<3;
        }
        unset($npc);
        // Serve explicit requests first; preserve retry fairness within each group.
        usort($candidates,static function ($a,$b) {
            if ($a['manual'] !== $b['manual']) return $a['manual'] ? -1 : 1;
            return ($a['state']['attempt'] ?? 0)<=>($b['state']['attempt'] ?? 0);
        });
        foreach ($candidates as $npc) {
            if (!$npc['enabled'] || ($manualName !== null && !dps_manual_match($npc,$manualName))) continue;
            $state = $npc['state'];
            if (($state['epoch'] ?? '') !== $clock['epoch'] || !dps_due($state,$npc['policy'],(int)$clock['gamets'],time(),$manualName!==null || $npc['manual'])) continue;
            if ($manualName === null && $npc['manual'] && (int)($npc['manual_request']['retry_after'] ?? 0)>time()) continue;
            $activeNpc = $npc;
            $blocked = $generator === null && !dps_connector_ready($npc) ? 'connector_unavailable' : '';
            $history = $blocked === '' ? dps_context($conn,$npc,(int)$clock['gamets']) : '';
            if ($blocked === '' && $history === '') $blocked = 'history_empty';
            if ($blocked !== '') {
                // Log a queued request's blocked reason once, without consuming a generation attempt.
                if ($npc['manual'] && ($npc['manual_request']['blocked_reason'] ?? '') !== $blocked) {
                    $request = $npc['manual_request'];
                    $blockedRequest = $request;
                    $blockedRequest['blocked_reason'] = $blocked;
                    $logged = dps_query($conn,'UPDATE public.conf_opts SET value=$1 WHERE id=$2 AND value::jsonb=$3::jsonb',
                        [json_encode($blockedRequest,JSON_THROW_ON_ERROR),'DYNAMIC_PROFILE_MANUAL_'.(int)$npc['id'],json_encode($request,JSON_THROW_ON_ERROR)]);
                    if (pg_affected_rows($logged)===1) dps_log($npc,$blocked);
                } elseif ($manualName !== null) {
                    dps_log($npc,$blocked);
                }
                continue;
            }
            if ($npc['manual']) {
                $request = $npc['manual_request'];
                $claimed = $request;
                $claimed['attempts'] = (int)($request['attempts'] ?? 0)+1;
                $claimed['retry_after'] = time()+(int)ceil($npc['policy']['DYNAMIC_PROFILE_COOLDOWN_MINUTES']*60);
                // A new click may arrive at any time; only claim the request we actually observed.
                $claim = dps_query($conn,'UPDATE public.conf_opts SET value=$1 WHERE id=$2 AND value::jsonb=$3::jsonb',
                    [json_encode($claimed,JSON_THROW_ON_ERROR),'DYNAMIC_PROFILE_MANUAL_'.(int)$npc['id'],json_encode($request,JSON_THROW_ON_ERROR)]);
                if (pg_affected_rows($claim)!==1) continue;
                $manualAttempt = $claimed;
            }
            $state['attempt'] = time();
            dps_store($conn,$npc['key'],$state);
            $npc['runtime_generation'] = dps_runtime_generation();
            $result['npcs']++;
            $updates = ($generator ?? 'dps_generate')($npc,$history);
            if (!$updates || !$allowed()) {
                dps_log($npc,!$updates ? 'generation_failed_or_empty' : 'interaction_cancelled');
                break;
            }
            dps_query($conn,'BEGIN');
            try {
                dps_query($conn,"SELECT pg_advisory_xact_lock(hashtext('dynamic_profile_clock'))");
                if ($npc['id']) {
                    $table = dps_product()['npc_table'];
                    // Member and explicit owner, in id order, on this same connection.
                    dps_query($conn,"SELECT id FROM public.$table WHERE id IN ($1,$2) ORDER BY id FOR UPDATE",[(int)$npc['id'],(int)($npc['owner_id'] ?? $npc['id'])]);
                } else {
                    dps_query($conn,'SELECT id FROM public.core_narrator FOR UPDATE');
                }
                $current = dps_state($conn,'DYNAMIC_PROFILE_CLOCK');
                $fresh = array_values(array_filter(dps_candidates($conn),static fn($row)=>$row['key']===$npc['key']));
                $conflicts = dps_conflicts($npc,$fresh[0] ?? []);
                if (($current['epoch'] ?? '') !== $clock['epoch']) $conflicts[] = 'clock_epoch';
                if (dps_runtime_generation() !== $npc['runtime_generation']) $conflicts[] = 'runtime_generation';
                if (!$allowed()) $conflicts[] = 'interaction_cancelled';
                if ($conflicts) {
                    dps_query($conn,'ROLLBACK');
                    dps_log($npc,'save_conflict',['changed'=>$conflicts]);
                    break;
                }
                dps_save($conn,$npc,$updates,(int)$clock['gamets']);
                $state['consumed'] = $state['total'];
                $state['last_game'] = $clock['gamets'];
                dps_store($conn,$npc['key'],$state);
                // Linked references share the regenerated profile: account their progress so the group generates once.
                foreach ($candidates as $member) {
                    if ((int)$npc['id']>0 && (int)$member['id']>0 && $member['key']!==$npc['key'] && ($member['owner_id'] ?? null)===($npc['owner_id'] ?? null)) {
                        $memberState = dps_state($conn,$member['key']);
                        if (($memberState['epoch'] ?? '') !== $clock['epoch']) continue;
                        $memberState['consumed'] = $memberState['total'] ?? 0;
                        $memberState['last_game'] = $clock['gamets'];
                        dps_store($conn,$member['key'],$memberState);
                    }
                }
                if ($manualAttempt !== null) {
                    dps_query($conn,'DELETE FROM public.conf_opts WHERE id=$1 AND value::jsonb=$2::jsonb',
                        ['DYNAMIC_PROFILE_MANUAL_'.(int)$npc['id'],json_encode($manualAttempt,JSON_THROW_ON_ERROR)]);
                }
                dps_query($conn,'COMMIT');
                $result['updated']++;
            } catch (Throwable $e) { pg_query($conn,'ROLLBACK'); throw $e; }
            break;
        }
    } catch (Throwable $e) {
        error_log('Dynamic Profiles: '.$e->getMessage());
        if ($activeNpc !== null) dps_log($activeNpc,'update_exception',['error_type'=>get_class($e)]);
    }
    finally {
        if ($manualAttempt !== null && !$result['updated']) {
            dps_log($npc,$manualAttempt['attempts']<3 ? 'manual_retry_pending' : 'manual_retry_exhausted',
                ['attempt'=>$manualAttempt['attempts'],'retry_after'=>$manualAttempt['retry_after']]);
        }
        if ($locked) pg_query($conn,"SELECT pg_advisory_unlock(hashtext('dynamic_profile_scheduler'))");
        if ($connection === null) pg_close($conn);
    }
    return $result;
}

const CHIM_DPS_NARRATOR = ['id'=>0,'narrator'=>true,'name'=>'The Narrator'];

// Legacy name selectors resolve only to a unique physical row among candidates; namesakes are refused.
function dps_resolve_selectors(array $candidates, array $selectors): array {
    $out = [];
    foreach ($selectors as $selector) {
        if (is_int($selector) || ctype_digit((string)$selector) || $selector === 'The Narrator') { $out[] = $selector; continue; }
        $hits = array_values(array_filter($candidates, static fn($c)=>(int)$c['id']>0 && $c['name']===(string)$selector));
        if (count($hits) === 1) $out[] = (int)$hits[0]['id'];
        else error_log('[DPS] '.json_encode(['reason'=>'manual_selector_ambiguous_or_missing','matches'=>count($hits)]));
    }
    return $out;
}

function dps_manual_match(array $npc, $selector): bool {
    if (is_int($selector) || ctype_digit((string)$selector)) return (int)$npc['id']>0 && (int)$npc['id']===(int)$selector;
    return (int)$npc['id']===0 && $selector==='The Narrator';
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
        // Typed narrator or the exact candidate row (owner-overlaid shared fields); never a name lookup.
        $value = updateDynamicProfileField((int)$npc['id'] ? $npc : CHIM_DPS_NARRATOR,$field,$history);
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
            dps_query($conn,"INSERT INTO public.core_npc_master_history(npc_id,$names) SELECT id,$names FROM public.core_npc_master WHERE id=$1",[(int)($npc['owner_id'] ?? 0) ?: (int)$npc['id']]);
        }
        $values[]=$gamets; $sets[]='gamets_last_updated=$'.count($values);
        // Linked: generated character fields update the explicit owner, so the member's dormant original
        // (restored on unlink) is not overwritten. Physical provenance stays in the member's progress state.
        $values[]=$product['prefix']==='stobe' ? (int)$npc['id'] : ((int)($npc['owner_id'] ?? 0) ?: (int)$npc['id']);
        $saved = dps_query($conn,"UPDATE public.{$product['npc_table']} SET ".implode(',',$sets).' WHERE id=$'.count($values),$values);
        if (pg_affected_rows($saved)!==1) throw new RuntimeException('NPC changed during profile update.');
        else
            dps_log($npc,'profile_updated_success',['fields'=>array_keys($updates),'gamets'=>$gamets]);
    } else {
        dps_query($conn,"INSERT INTO public.core_narrator(id,value) VALUES('gamets_last_updated',$1) ON CONFLICT(id) DO UPDATE SET value=EXCLUDED.value",[(string)$gamets]);
    }
}

?>
