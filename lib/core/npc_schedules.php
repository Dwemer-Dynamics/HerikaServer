<?php
require_once __DIR__ . '/npc_commitments.php';
require_once __DIR__ . '/game_plugins.php';
require_once __DIR__ . '/../background_life_encounters.php';

// Database failures must abort the transaction instead of reporting a saved schedule.
function chimScheduleExec(string $sql) {
    $result = $GLOBALS['db']->execQuery($sql);
    if ($result === false) throw new RuntimeException('Schedule database operation failed.');
    return $result;
}


// Use the accepted live clock and its load epoch, never synthetic event timestamps.
function chimScheduleClock(): array {
    $row = $GLOBALS['db']->fetchOne("SELECT value FROM conf_opts WHERE id='DYNAMIC_PROFILE_CLOCK'");
    return json_decode($row['value'] ?? '{}', true) ?: [];
}

function chimScheduleLocations(string $search): array {
    $search = $GLOBALS['db']->escape(trim($search));
    return $GLOBALS['db']->fetchAll("SELECT DISTINCT name, formid FROM locations WHERE formid IS NOT NULL AND name ILIKE '%{$search}%' ORDER BY name, formid LIMIT 40");
}

// Both the editor and AI creation pass through this validator before activation.
function chimScheduleSave(array $npc, array $input, int $taskId = 0, bool $outerTransaction = false): int {
    $db = $GLOBALS['db']; $clock = chimScheduleClock(); $now = (int)($clock['gamets'] ?? 0);
    if (isset($input['epoch']) && $input['epoch'] !== ($clock['epoch'] ?? '')) throw new InvalidArgumentException('The game timeline changed. Refresh this menu.');
    if (!$now || empty($clock['epoch'])) throw new RuntimeException('Connect the game before creating a schedule.');
    $subject = trim((string)($input['subject'] ?? ''));
    $mode = (string)($input['mode'] ?? 'visit');
    $due = (int)($input['due_gamets'] ?? 0);
    $duration = (float)($input['duration_hours'] ?? 0);
    $repeat = (float)($input['repeat_hours'] ?? 0);
    if ($subject === '' || strlen($subject) > 1000 || !in_array($mode, ['visit','stay','task'], true)) throw new InvalidArgumentException('Enter an activity and a valid schedule type.');
    if ($due <= $now || $duration < 0 || $duration > 8760 || $repeat < 0 || $repeat > 8760 || ($mode === 'stay' && $duration <= 0)) throw new InvalidArgumentException('Choose a future appointment and valid duration.');
    if ($repeat > 0 && $repeat < $duration + 3) throw new InvalidArgumentException('The repeat interval must allow three hours of travel plus the activity duration.');
    $locationId = (int)($input['location_id'] ?? 0);
    $locations = $db->fetchAll("SELECT DISTINCT name,formid FROM locations WHERE formid={$locationId}");
    if (count($locations) !== 1) throw new InvalidArgumentException('Select one recognised destination.');
    $actorStable = chimConvertRuntimeFormIdToStableReference($npc['refid'] ?? '');
    $locationStable = chimConvertRuntimeFormIdToStableReference(sprintf('%08X', $locationId & 0xffffffff));
    if (!$actorStable || !$locationStable) throw new InvalidArgumentException('The actor and destination need registered, persistent game references. Refresh game data first.');
    $npcId = (int)$npc['id'];
    if ($mode === 'task') {
        $actorName = $db->escape($npc['npc_name']);
        if (count($db->fetchAll("SELECT id FROM core_npc_master WHERE lower(npc_name)=lower('{$actorName}')")) !== 1) throw new InvalidArgumentException('AI duties require a unique NPC name. Use a destination-only schedule for this NPC.');
    }
    $schedule = ['mode'=>$mode,'duration_hours'=>$duration,'actor'=>$actorStable,'destination'=>$locationStable,'name'=>$locations[0]['name'],'validation'=>'pending'];
    if (!$outerTransaction) chimScheduleExec('BEGIN');
    try {
        $db->fetchAll("SELECT pg_advisory_xact_lock(hashtext('dynamic_profile_clock'))");
        if ((chimScheduleClock()['epoch'] ?? '') !== $clock['epoch']) throw new RuntimeException('The game timeline changed. Refresh schedules.');
        $db->fetchAll("SELECT pg_advisory_xact_lock(7419, {$npcId})");
        $others = $db->fetchAll("SELECT id,due_gamets,repeat_interval_gamets,schedule FROM npc_commitments WHERE npc_id={$npcId} AND schedule IS NOT NULL AND status IN ('scheduled','due') AND id<>{$taskId}");
        $start = $due - chimCommitmentHoursToGamets(3); $end = $due + (int)round($duration / 0.0000024);
        foreach ($others as $other) {
            $s = json_decode($other['schedule'], true);
            $otherDue = (int)$other['due_gamets']; $interval = (int)$other['repeat_interval_gamets'];
            // Check repeated windows over one shared cycle; unbounded free-text duties reserve the NPC.
            if (($s['mode'] ?? '') === 'task' || $mode === 'task') throw new InvalidArgumentException('Resolve or cancel the existing schedule before adding an open-ended duty.');
            $otherEnd = $otherDue + (int)round((float)$s['duration_hours'] / 0.0000024);
            $newInterval = $repeat > 0 ? chimCommitmentHoursToGamets($repeat) : 0;
            $windows = [[0,0]];
            if ($interval) { $n = max(0, (int)floor(($due-$otherDue)/$interval)); $windows = [[$n,0],[$n+1,0]]; }
            if ($newInterval) { $n = max(0, (int)floor(($otherDue-$due)/$newInterval)); $windows[]=[0,$n]; $windows[]=[0,$n+1]; }
            if ($interval && $newInterval && $interval !== $newInterval) throw new InvalidArgumentException('Use matching repeat intervals for multiple schedules, or cancel the conflicting schedule.');
            foreach ($windows as [$a,$b]) if ($start+$b*$newInterval <= $otherEnd+$a*$interval && $end+$b*$newInterval >= $otherDue+$a*$interval-chimCommitmentHoursToGamets(3)) throw new InvalidArgumentException('This overlaps another schedule, including travel time.');
        }
        $active = null;
        if ($taskId) {
            $old = $db->fetchOne("SELECT id,schedule FROM npc_commitments WHERE id={$taskId} AND npc_id={$npcId} FOR UPDATE");
            if (!$old) throw new InvalidArgumentException('Schedule not found.');
            $active = $db->fetchOne("SELECT id FROM npc_schedule_runs WHERE task_id={$taskId} AND phase NOT IN ('finished','cancelled','invalidated')");
            $schedule['revision'] = (int)(json_decode($old['schedule'], true)['revision'] ?? 0) + 1;
            if ($active) {
                chimScheduleExec("UPDATE npc_schedule_runs SET phase='releasing',pending_op='',attempts=0 WHERE task_id={$taskId} AND phase NOT IN ('finished','cancelled','invalidated')");
                chimScheduleExec("DELETE FROM responselog WHERE sent=0 AND rowid IN (SELECT command_id FROM npc_schedule_runs WHERE task_id={$taskId})");
            }
        } else {
            $created = chimCommitmentCreate($npc['npc_name'], ['subject'=>$subject,'location'=>$locations[0]['name'],'due_in_hours'=>($due-$now)*0.0000024,'repeat_every_hours'=>$repeat], $now);
            if (empty($created['ok'])) throw new RuntimeException('Could not create schedule.');
            $taskId = $created['id'];
        }
        $json = $db->escape(json_encode($schedule, JSON_THROW_ON_ERROR)); $text = $db->escape($subject); $name = $db->escape($locations[0]['name']);
        $interval = $repeat > 0 ? chimCommitmentHoursToGamets($repeat) : 0;
        chimScheduleExec("UPDATE npc_commitments SET npc_id={$npcId},schedule='{$json}'::jsonb,subject='{$text}',location_name='{$name}',due_gamets={$due},repeat_interval_gamets={$interval},status='scheduled',resolved_gamets=NULL,outcome='',updated_at=now() WHERE id={$taskId}");
        if (!$active) chimScheduleNewRun($taskId, $due, $schedule, $clock);
        if (!$outerTransaction) chimScheduleExec('COMMIT'); return $taskId;
    } catch (Throwable $e) { if (!$outerTransaction) chimScheduleExec('ROLLBACK'); throw $e; }
}

function chimScheduleNewRun(int $taskId, int $due, array $schedule, array $clock): void {
    $db = $GLOBALS['db']; $token = bin2hex(random_bytes(16)); $epoch = $db->escape($clock['epoch']);
    $actor = chimResolveStableFormReferenceToRuntimeFormId($schedule['actor']);
    if (!$actor) throw new RuntimeException('Scheduled actor is unavailable.');
    $json = $db->escape(json_encode($schedule, JSON_THROW_ON_ERROR)); $actor = $db->escape($actor);
    chimScheduleExec("INSERT INTO npc_schedule_runs(task_id,due_gamets,token,epoch,actor_ref,snapshot) VALUES ({$taskId},{$due},'{$token}','{$epoch}','{$actor}','{$json}')");
}

// Enqueue one correlated command; retries reuse the token and operation.
function chimScheduleCommand(array $run, string $op, array $clock): void {
    $db = $GLOBALS['db']; $s = json_decode($run['snapshot'], true);
    $destination = chimResolveStableFormReferenceToRuntimeFormId($op === 'validate' ? $s['destination'] : ($s['arrival'] ?? $s['destination']));
    $actor = chimResolveStableFormReferenceToRuntimeFormId($s['actor']);
    if (!$destination || !$actor) throw new RuntimeException('Actor or destination is no longer available.');
    $id=(int)$run['id']; $now=(int)$clock['gamets'];
    if (!empty($run['command_id'])) chimScheduleExec('DELETE FROM responselog WHERE sent=0 AND rowid='.(int)$run['command_id']);
    $command = "rolecommand|BackgroundCmd@{$actor}@Schedule/{$id}/{$run['token']}/{$op}/{$destination}/" . ($now/10000000);
    $commandId = $db->insertReturningId('responselog', ['localts'=>time(),'sent'=>0,'actor'=>'rolemaster','text'=>'','action'=>$command,'tag'=>''], 'rowid');
    if (!$commandId) throw new RuntimeException('Schedule command could not be queued.');
    $sent=time(); $actor=$db->escape($actor);
    chimScheduleExec("UPDATE npc_schedule_runs SET pending_op='{$op}',actor_ref='{$actor}',sent_at={$sent},attempts=attempts+1,result=CASE WHEN attempts>=2 THEN 'Waiting for game acknowledgement; retry from Schedules.' ELSE result END,command_id=".(int)$commandId.",updated_at=now() WHERE id={$id}");
}

// Processor ticks drain deadlines; the hourly clock is only for routine progress checks.
function chimScheduleTick(): void {
    $db=$GLOBALS['db']; $clock=chimScheduleClock(); $now=(int)($clock['gamets']??0);
    if (!$now || time()-(int)($clock['seen']??0)>120 || !chimInteractionAllowed()) return;
    chimScheduleExec('BEGIN');
    try {
        $db->fetchAll("SELECT pg_advisory_xact_lock(hashtext('dynamic_profile_clock'))");
        $clock = chimScheduleClock();
        $lock=$db->fetchOne("SELECT pg_try_advisory_xact_lock(7419,0) AS acquired");
        if (!in_array($lock['acquired']??false,[true,'t','1',1],true)) { chimScheduleExec('ROLLBACK'); return; }
        $now=(int)($clock['gamets']??0);
        $epoch = $db->escape($clock['epoch']); $retryBefore=time()-20; $departureBefore=$now+chimCommitmentHoursToGamets(3);
        $runs=$db->fetchAll("SELECT r.*,t.status,t.subject,t.npc_id,t.due_gamets AS current_due,t.repeat_interval_gamets FROM npc_schedule_runs r JOIN npc_commitments t ON t.id=r.task_id WHERE r.phase NOT IN ('finished','cancelled','invalidated') AND (r.epoch<>'{$epoch}' OR ((r.pending_op='' OR (r.attempts<3 AND r.sent_at<={$retryBefore})) AND (r.phase<>'blocked' OR t.status IN ('cancelled','completed','failed')) AND (r.phase<>'ready' OR r.due_gamets<={$departureBefore} OR t.status IN ('cancelled','completed','failed')))) ORDER BY r.updated_at,r.id LIMIT 40 FOR UPDATE OF r,t");
        foreach ($runs as $r) {
            $id=(int)$r['id']; $task=(int)$r['task_id']; $s=json_decode($r['snapshot'],true);
            chimScheduleExec("UPDATE npc_schedule_runs SET updated_at=now() WHERE id={$id}");
            if ($r['epoch'] !== $clock['epoch']) {
                if ($r['command_id']) chimScheduleExec('DELETE FROM responselog WHERE sent=0 AND rowid='.(int)$r['command_id']);
                $s['invalidated'] = true;
                $snapshot = $db->escape(json_encode($s,JSON_THROW_ON_ERROR)); $epoch=$db->escape($clock['epoch']);
                chimScheduleExec("UPDATE npc_schedule_runs SET phase='releasing',epoch='{$epoch}',snapshot='{$snapshot}',attempts=0,result='Game timeline changed; releasing old activity.',pending_op='' WHERE id={$id}");
                chimScheduleExec("UPDATE npc_commitments SET schedule=jsonb_set(schedule,'{validation}','\"blocked\"') WHERE id={$task}");
                continue;
            }
            if ($r['pending_op'] !== '' && time()-(int)$r['sent_at']<20) continue;
            if ($r['pending_op'] !== '' && (int)$r['attempts']>=3) {
                chimScheduleExec("UPDATE npc_schedule_runs SET result='Waiting for game acknowledgement; retry from Schedules.' WHERE id={$id}"); continue;
            }
            $op=$r['pending_op'];
            if (!$op) {
                if (in_array($r['status'],['cancelled','completed','failed'],true) || $r['phase']==='releasing' || (int)$r['current_due']!==(int)$r['due_gamets']) $op='release';
                elseif ($r['phase']==='validate') $op='validate';
                elseif ($r['phase']==='blocked') continue;
                elseif ($r['phase']==='ready' && $now >= (int)$r['due_gamets']-chimCommitmentHoursToGamets(3)) {
                    // The dispatch lock also protects encounter creation and loot application.
                    if (chimBglEncounterIsActiveForNpc($db, (int)$r['npc_id'])) continue;
                    $busy=$db->fetchOne("SELECT r.id FROM npc_schedule_runs r JOIN npc_commitments t ON t.id=r.task_id WHERE t.npc_id=".(int)$r['npc_id']." AND r.id<>{$id} AND (r.phase IN ('travelling','waiting','active','releasing') OR r.pending_op IN ('travel','ensure')) LIMIT 1");
                    if ($busy) continue;
                    $op=$now >= (int)$r['due_gamets'] ? 'ensure' : 'travel';
                } elseif (in_array($r['phase'],['travelling','waiting'],true)) {
                    if ($now >= (int)$r['due_gamets']) $op='ensure';
                    elseif (intdiv($now*24+12,10000000)>intdiv((int)($s['checked_gamets']??0)*24+12,10000000)) $op='check';
                } elseif ($r['phase']==='active' && $s['mode']!=='task' && $now >= (int)$r['due_gamets']+(int)round($s['duration_hours']/0.0000024)) $op='release';
            }
            if ($op) {
                try { chimScheduleCommand($r,$op,$clock); }
                catch (Throwable $e) { $error=$db->escape($e->getMessage());chimScheduleExec("UPDATE npc_schedule_runs SET phase='blocked',result='{$error}' WHERE id={$id}"); }
            }
        }
        chimScheduleExec('COMMIT');
    } catch(Throwable $e) { chimScheduleExec('ROLLBACK'); throw $e; }
}

// A game reply is accepted only for the current operation, actor, occurrence and load epoch.
function chimScheduleReply(string $message): void {
    $p=explode('/', $message); if(count($p)!==6 || !ctype_digit($p[0])) return;
    [$id,$token,$actor,$op,$result,$marker]=$p; $id=(int)$id;
    $db=$GLOBALS['db']; $clock=chimScheduleClock();
    chimScheduleExec('BEGIN');
    try {
        $db->fetchAll("SELECT pg_advisory_xact_lock(hashtext('dynamic_profile_clock'))");
        $clock = chimScheduleClock();
        $r=$db->fetchOne("SELECT r.*,t.status,t.actor_name,t.subject,t.schedule AS current_schedule,t.due_gamets AS current_due,t.repeat_interval_gamets FROM npc_schedule_runs r JOIN npc_commitments t ON t.id=r.task_id WHERE r.id={$id} FOR UPDATE OF r,t");
        if (!$r || !hash_equals($r['token'],$token) || strcasecmp($r['actor_ref'],$actor)!==0 || $r['pending_op']!==$op || $r['epoch']!==($clock['epoch']??'')) { chimScheduleExec('ROLLBACK'); return; }
        $allowed=['busy','invalid','stale'];
        $allowed=array_merge($allowed, ['validate'=>['validated'],'travel'=>['travelling','arrived'],'check'=>['travelling','arrived'],'ensure'=>['arrived','teleported'],'release'=>['released']][$op] ?? []);
        if ($result==='validated' && $marker==='00000000') { chimScheduleExec('ROLLBACK'); return; }
        if (!in_array($result,$allowed,true) || !preg_match('/^[0-9a-fA-F]{8}$/',$marker)) { chimScheduleExec('ROLLBACK'); return; }
        $s=json_decode($r['snapshot'],true); $now=(int)$clock['gamets']; $s['checked_gamets']=$now; $task=(int)$r['task_id']; $phase=$r['phase'];
        if (in_array($result,['invalid','stale'],true)) $phase='blocked';
        elseif ($result==='busy') $phase=$r['phase'];
        elseif ($op==='validate' && $result==='validated') {
            $arrival = chimConvertRuntimeFormIdToStableReference($marker);
            if (!$arrival) throw new RuntimeException('Arrival marker does not have a persistent identity.');
            $s['arrival'] = $arrival;
            $phase='ready'; chimScheduleExec("UPDATE npc_commitments SET schedule=jsonb_set(schedule,'{validation}','\"validated\"') WHERE id={$task}");
        } elseif ($op==='release' && $result==='released') {
            if (!empty($s['invalidated'])) {
                chimScheduleExec("UPDATE npc_schedule_runs SET phase='invalidated',pending_op='',result='Timeline changed; edit this schedule to reactivate.' WHERE id={$id}");
                chimScheduleExec('COMMIT'); return;
            }
            $currentSchedule=json_decode($r['current_schedule'],true);
            if (!empty($currentSchedule['pending_delete'])) {
                chimScheduleExec("DELETE FROM npc_schedule_runs WHERE task_id={$task}");
                chimScheduleExec("DELETE FROM npc_commitments WHERE id={$task}");
                chimScheduleExec('COMMIT'); return;
            }
            $phase=$r['status']==='cancelled'?'cancelled':'finished';
            if (in_array($r['status'],['scheduled','due'],true) && (int)$r['current_due']===(int)$r['due_gamets'] && (int)(json_decode($r['current_schedule'],true)['revision']??0)===(int)($s['revision']??0)) chimCommitmentSetStatus($r['actor_name'],$task,'completed','Appointment completed in game.',$now);
            $t=$db->fetchOne("SELECT * FROM npc_commitments WHERE id={$task}");
            if ($t['status']==='scheduled') chimScheduleNewRun($task,(int)$t['due_gamets'],json_decode($t['schedule'],true),$clock);
        } elseif (in_array($result,['arrived','teleported'],true)) {
            $phase=$op==='ensure'?'active':'waiting';
            if ($phase==='active' && $s['mode']==='task' && !chimInteractionAllowed()) $phase='waiting';
            if ($phase==='active' && $s['mode']==='task') {
                $name=str_replace(['|','@',"\n"], ' ', $r['actor_name']); $subject=str_replace(['|','@',"\n"], ' ', $r['subject']);
                $db->insert('responselog',['localts'=>time(),'sent'=>0,'actor'=>'rolemaster','text'=>'','action'=>"rolecommand|Instruction@{$name}@Scheduled task #{$task} is due. You have arrived: {$subject}. Use available actions and resolve only after the outcome happens.@schedule{$id}",'tag'=>'']);
            }
        } elseif ($result==='travelling') $phase='travelling';
        $json=$db->escape(json_encode($s,JSON_THROW_ON_ERROR));
        chimScheduleExec("UPDATE npc_schedule_runs SET phase='{$phase}',result='{$result}',marker_ref='{$marker}',pending_op='',attempts=0,snapshot='{$json}',updated_at=now() WHERE id={$id}");
        chimScheduleExec('COMMIT');
    } catch(Throwable $e) { chimScheduleExec('ROLLBACK'); throw $e; }
}

// Serialize menu changes with game replies; active deletion waits for package release.
function chimScheduleManage(int $npcId, int $id, string $operation, string $epoch): void {
    $db=$GLOBALS['db'];
            chimScheduleExec('BEGIN');
            try {
                $db->fetchAll("SELECT pg_advisory_xact_lock(hashtext('dynamic_profile_clock'))");
                if ($epoch !== (chimScheduleClock()['epoch'] ?? '')) throw new InvalidArgumentException('The game timeline changed. Refresh this menu.');
                $db->fetchAll("SELECT pg_advisory_xact_lock(7419, {$npcId})");
                $task=$db->fetchOne("SELECT * FROM npc_commitments WHERE id={$id} AND npc_id={$npcId} AND schedule IS NOT NULL FOR UPDATE");
                if (!$task) throw new InvalidArgumentException('Schedule not found.');
                $active=$db->fetchOne("SELECT id FROM npc_schedule_runs WHERE task_id={$id} AND phase NOT IN ('finished','cancelled','invalidated')");
                if ($operation==='cancel' || ($operation==='delete' && $active)) {
                    if ($operation==='delete') chimScheduleExec("UPDATE npc_commitments SET schedule=jsonb_set(schedule,'{pending_delete}','true') WHERE id={$id}");
                    chimScheduleExec("UPDATE npc_commitments SET status='cancelled',updated_at=now() WHERE id={$id}");
                    chimScheduleExec("UPDATE npc_schedule_runs SET pending_op='',attempts=0,phase='releasing' WHERE task_id={$id} AND phase NOT IN ('finished','cancelled','invalidated')");
                    chimScheduleExec("DELETE FROM responselog WHERE sent=0 AND rowid IN (SELECT command_id FROM npc_schedule_runs WHERE task_id={$id})");
                } elseif ($operation==='retry') {
                    chimScheduleExec("UPDATE npc_schedule_runs SET attempts=0,sent_at=0,phase=CASE WHEN phase='blocked' THEN 'validate' ELSE phase END WHERE task_id={$id} AND phase NOT IN ('finished','cancelled','invalidated')");
                } elseif ($operation==='delete') {
                    chimScheduleExec("DELETE FROM npc_schedule_runs WHERE task_id={$id}");
                    chimScheduleExec("DELETE FROM npc_commitments WHERE id={$id}");
                } else throw new InvalidArgumentException('Unknown schedule operation.');
                chimScheduleExec('COMMIT');
            } catch(Throwable $e) { chimScheduleExec('ROLLBACK'); throw $e; }
}
