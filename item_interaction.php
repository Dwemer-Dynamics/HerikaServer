<?php
// Game-client only; reuse the playthrough barrier before generation or gameplay-history writes.
ini_set('display_errors','0');
require_once __DIR__.'/lib/playthrough_switching.php';
require_once __DIR__.'/lib/chim_interaction.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');

// Generate missing audio after committing the receipt; identical retries reuse its cache key.
function chimInteractSpeech(array $state): array {
    $narration=$state['narration'];
    $audio=__DIR__.'/soundcache/'.$narration['tts_cache_key'].'.wav';
    if (!is_file($audio) || filesize($audio)<=44) {
        $narrator=(new Narrator())->getNarratorData();
        chimDirectorActorGlobals($narrator);
        $GLOBALS['DIRECT_NARRATOR_DIALOGUE']=true;
        $GLOBALS['CHIM_SPEECH_TRACE_ID']=$narration['utterance_id'];
        try {
            callNpcTtsWithFallback($narration['text'],'default',$narration['utterance_id']);
        } catch (Throwable $error) {
            Logger::warn('[INTERACT] Narrator audio unavailable; subtitles remain available');
        }
    }
    return $narration;
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || isset($_SERVER['HTTP_ORIGIN'])
        || !str_starts_with(strtolower($_SERVER['CONTENT_TYPE'] ?? ''),'application/json')) throw new InvalidArgumentException('Game client required');
    pas_http_guard();
    chimInteractionRequire();
    $raw=file_get_contents('php://input',false,null,0,65537);
    if (strlen($raw)>65536) throw new InvalidArgumentException('Request too large');
    $input=json_decode($raw,true,32,JSON_THROW_ON_ERROR);
    $id=$input['id'] ?? '';
    if (!is_string($id) || !preg_match('/^[a-f0-9]{32}$/D',$id)) throw new InvalidArgumentException('Invalid interaction ID');
    require_once __DIR__.'/lib/runtime_bootstrap.php';
    chimRuntimeBootstrap(__DIR__,['run_db_updates'=>false,'load_general_settings'=>true,
        'load_stt_connector'=>false,'load_itt_connector'=>false,'load_player_name'=>true,'load_narrator'=>true]);
    require_once __DIR__.'/lib/item_interaction.php';
    require_once __DIR__.'/lib/core/npc_master.class.php';
    require_once __DIR__.'/lib/core/core_profiles.class.php';
    require_once __DIR__.'/lib/director_scene.php';
    require_once __DIR__.'/lib/data_functions.php';
    $db=$GLOBALS['db'];
    $session=hash('sha256',(string)($_SERVER['HTTP_X_CHIM_PLAYTHROUGH'] ?? ''));
    $op=$input['op'] ?? 'resolve';
    $gamets=(int)($input['gamets'] ?? 0);
    $GLOBALS['gameRequest']=['item_interaction',time(),$gamets,''];
    $player=(string)$GLOBALS['PLAYER_NAME'];
    // Serialize a request's claim only. Generation and TTS never run inside the transaction.
    $db->query('BEGIN');
    $db->query("SELECT pg_advisory_xact_lock(hashtext('item_interaction:{$id}'))");
    $row=$db->fetchOne("SELECT rowid,data FROM rolemaster WHERE type='item_interaction' AND data::jsonb->>'id'='{$id}' ORDER BY rowid DESC LIMIT 1 FOR UPDATE");
    $state=$row ? json_decode($row['data'],true) : null;
    if ($op==='resolve') {
        if ($state) throw new RuntimeException('This attempt has already been submitted');
        $recent=$db->fetchOne("SELECT count(*) AS count FROM rolemaster WHERE type='item_interaction' AND localts>".(time()-60));
        if ((int)($recent['count'] ?? 0)>=6) throw new RuntimeException('Please wait before another attempt');
        $intent=trim((string)($input['intent'] ?? ''));
        if ($intent==='' || mb_strlen($intent)>1000) throw new InvalidArgumentException('Describe the attempt in 1000 characters or fewer');
        $snapshot=$input['snapshot'] ?? null;
        if (!is_array($snapshot) || !array_key_exists('item',$snapshot) || ($snapshot['item']!==null && !is_array($snapshot['item'])) || !is_array($snapshot['target'] ?? null)) throw new InvalidArgumentException('Missing current game snapshot');
        $target=mb_substr((string)($snapshot['target']['name'] ?? ''),0,160);
        $hasItem=$snapshot['item']!==null;
        $item=$hasItem ? mb_substr((string)($snapshot['item']['name'] ?? ''),0,160) : null;
        $allowed=array_intersect_key(chimInteractCatalog(),array_flip(array_filter($input['capabilities'] ?? [],'is_string')));
        if ($hasItem) unset($allowed['consume_world']);
        if (!$hasItem) $allowed=array_diff_key($allowed,array_flip(['give','store','consume','equip','magic']));
        if (!$allowed || $target==='' || ($hasItem && $item==='')) throw new InvalidArgumentException('No supported interaction');
        $location=mb_substr((string)($snapshot['location'] ?? ''),0,160);
        $sceneFilter=$location!=='' && $location!=='unknown' ? " OR location='".$db->escape($location)."'" : '';
        $history=$db->fetchAll("SELECT type,data FROM eventlog WHERE type IN ('chat','infoaction','death','itemfound','itemremoved')
            AND localts>".(time()-1800)." AND (strpos(people, '|".$db->escape($target)."|')>0{$sceneFilter}) ORDER BY rowid DESC LIMIT 10");
        $history=array_reverse($history ?: []);
        $budget=6000;
        foreach ($history as &$event) { $event['data']=mb_substr((string)$event['data'],0,min(600,$budget)); $budget-=mb_strlen($event['data']); }
        unset($event);
        $npc=(new NpcMaster())->getByName($target);
        $profile=[];
        foreach (['personality','occupation','goals','npc_static_bio'] as $field) $profile[$field]=mb_substr((string)($npc[$field] ?? ''),0,500);
        $state=['id'=>$id,'session'=>$session,'status'=>'resolving','player'=>$player,'target'=>$target,'item'=>$item,
            'gamets'=>$gamets,'allowed'=>$allowed,'intent'=>$intent,
            'target_ref'=>(string)($snapshot['target']['ref_id'] ?? ''),
            'target_speaker'=>(string)($snapshot['target']['speaker'] ?? '')];
        $rowid=$db->insertReturningId('rolemaster',['type'=>'item_interaction','localts'=>time(),'ttl'=>600,'data'=>json_encode($state)],'rowid');
        if (!$rowid || !$db->insertReturningId('eventlog',['type'=>'infoaction','ts'=>time(),'localts'=>time(),'gamets'=>$gamets,
            'data'=>"[Interact {$id} attempt] {$player} ".($hasItem ? "attempts to use {$item} on {$target}" : "attempts to interact with {$target} without an item").": {$intent}",
            'people'=>"|{$player}|{$target}|",'location'=>(string)($snapshot['location'] ?? ''),'party'=>'','sess'=>''], 'rowid')) throw new RuntimeException('Could not record attempt');
        if ($db->query('COMMIT')===false) throw new RuntimeException('Could not save attempt');
        unset($snapshot['target']['ref_id'],$snapshot['target']['speaker']);
        $plan=chimInteractGenerate(['player'=>$player,'intent'=>$intent,'current_game'=>$snapshot,'target_profile'=>$profile,'recent_context'=>$history],$allowed);
        $state['plan']=$plan; $state['status']='ready';
        $encoded=$db->escape(json_encode($state,JSON_THROW_ON_ERROR));
        if (!$db->fetchOne("UPDATE rolemaster SET data='{$encoded}' WHERE rowid=".(int)$rowid." AND data::jsonb->>'status'='resolving' RETURNING rowid")) throw new RuntimeException('Could not save resolution');
        echo json_encode(['ok'=>true,'id'=>$id,'plan'=>$plan],JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }
    if ($op==='receipt' && $state && hash_equals($state['session'],$session) && $state['status']==='completed') {
        if (($state['receipts'] ?? [])!==($input['receipts'] ?? [])) throw new InvalidArgumentException('Receipt changed');
        $db->query('COMMIT');
        echo json_encode(['ok'=>true,'id'=>$id,'narration'=>chimInteractSpeech($state)],JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }
    if ($op==='cancel' && $state && hash_equals($state['session'],$session) && in_array($state['status'],['resolving','ready'],true)) {
        $state['status']='cancelled';
        $encoded=$db->escape(json_encode($state,JSON_THROW_ON_ERROR));
        $db->query("UPDATE rolemaster SET data='{$encoded}' WHERE rowid=".(int)$row['rowid']);
        $db->query('COMMIT'); echo '{"ok":true}'; exit;
    }
    if ($op!=='receipt' || !$state || !hash_equals($state['session'],$session) || $state['status']!=='ready') throw new RuntimeException('Expired or completed interaction');
    if (!is_array($input['receipts'] ?? null) || count($input['receipts'])!==count($state['plan']['steps'])) throw new InvalidArgumentException('Invalid execution receipt');
    $sentences=[]; $facts=[];
    foreach ($state['plan']['steps'] as $index=>$step) {
        $receipt=$input['receipts'][$index];
        $status=$receipt['status'] ?? '';
        if (!in_array($status,['succeeded','failed','skipped','unknown'],true)) throw new InvalidArgumentException('Invalid outcome');
        if ($status==='succeeded') foreach ($step['requires'] as $dependency) {
            if (($input['receipts'][$dependency]['status'] ?? '')!=='succeeded') throw new InvalidArgumentException('Unsatisfied effect dependency');
        }
        $detail=mb_substr((string)($receipt['detail'] ?? ''),0,300);
        $facts[]=$step['effect'].': '.$status.($detail!=='' ? ' ('.$detail.')' : '');
        if ($status==='succeeded' && $step['effect']==='activate') $sentences[]=$state['player'].' activates '.$state['target'].'.';
        elseif ($status==='succeeded' && $step['effect']==='consume_world') $sentences[]=$state['player'].' consumes '.$state['target'].'.';
        elseif ($status==='succeeded' && $step['effect']==='consume') $sentences[]=$state['target'].' consumes '.$state['item'].'.';
        elseif ($status==='succeeded' && $step['narration']!=='') $sentences[]=$step['narration'];
        elseif ($status==='unknown' || $status==='failed') {
            if (str_starts_with($detail,'World item transferred')) $sentences[]=$state['player'].' takes '.$state['target'].', but consumption could not be confirmed.';
            elseif (str_starts_with($detail,'Item transferred')) $sentences[]=$state['target'].' receives '.$state['item'].', but the rest of that action does not complete.';
            elseif (str_starts_with($detail,'Scroll consumed')) $sentences[]=$state['player'].' uses up the scroll, but its effect could not be confirmed.';
            else $sentences[]=$state['player']."'s attempt to ".$step['effect'].' '.$state['target'].($status==='failed' ? ' does not succeed.' : ' has an uncertain result.');
        }
    }
    if (!$state['plan']['steps']) $sentences[]=$state['plan']['failure_narration'];
    if (!$sentences) $sentences[]=$state['player']."'s interaction with ".$state['target'].' stops before its effects can complete.';
    $text=implode(' ',$sentences);
    $state['status']='completed'; $state['receipts']=$input['receipts'];
    $utterance='interact-'.$id;
    $state['narration']=['text'=>$text,'utterance_id'=>$utterance,'tts_cache_key'=>md5($utterance)];
    $encoded=$db->escape(json_encode($state,JSON_THROW_ON_ERROR));
    if ($db->query("UPDATE rolemaster SET data='{$encoded}' WHERE rowid=".(int)$row['rowid'])===false
        || !$db->insertReturningId('eventlog',['type'=>'infoaction','ts'=>time(),'localts'=>time(),'gamets'=>$gamets,
            'data'=>"[Interact {$id} outcome] ".$state['player'].($state['item']!==null ? ' used '.$state['item'].' on ' : ' interacted without an item with ').$state['target'].': '.implode('; ',$facts).'. '.$text,
            'people'=>'|'.$state['player'].'|'.$state['target'].'|','location'=>'','party'=>'','sess'=>'',
            'utterance_id'=>$utterance,'delivery_state'=>'pending'],'rowid')) throw new RuntimeException('Could not save outcome');
    if ($db->query('COMMIT')===false) throw new RuntimeException('Could not commit outcome');
    echo json_encode(['ok'=>true,'id'=>$id,'narration'=>chimInteractSpeech($state)],JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $error) {
    if (isset($db)) {
        $db->query('ROLLBACK');
        if (isset($rowid,$state) && ($state['status'] ?? '')==='resolving') {
            $state['status']='failed';
            $encoded=$db->escape(json_encode($state,JSON_INVALID_UTF8_SUBSTITUTE));
            $changed=$db->fetchOne("UPDATE rolemaster SET data='{$encoded}' WHERE rowid=".(int)$rowid." AND data::jsonb->>'status'='resolving' RETURNING rowid");
            if ($changed) $db->insert('eventlog',['type'=>'infoaction','ts'=>time(),'localts'=>time(),'gamets'=>$gamets,
                'data'=>"[Interact {$id} outcome] Resolution failed. No game effects were authorized.",
                'people'=>"|{$player}|{$target}|",'location'=>'','party'=>'','sess'=>'']);
        }
    }
    error_log('[INTERACT] '.$error->getMessage());
    http_response_code(409);
    echo json_encode(['ok'=>false,'message'=>'The interaction could not be completed. No uncertain effects will be repeated.']);
}
