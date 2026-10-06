<?php
// Game-client only; reuse the playthrough barrier before generation or gameplay-history writes.
ini_set('display_errors','0');
require_once __DIR__.'/lib/playthrough_switching.php';
require_once __DIR__.'/lib/chim_interaction.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');

// Deliver each ordinary dialogue chunk as soon as its speech is ready; retries keep stable IDs.
function chimInteractSpeech(array $state, bool $stream = false): array {
    $narration=$state['narration'];
    $chunks=$narration['chunks'] ?? [$narration];
    $narrator=(new Narrator())->getNarratorData();
    chimDirectorActorGlobals($narrator);
    $GLOBALS['DIRECT_NARRATOR_DIALOGUE']=true;
    if ($stream) {
        header('Content-Type: application/x-ndjson');
        header('X-Accel-Buffering: no');
        ini_set('zlib.output_compression','0');
        while (ob_get_level()>0) ob_end_flush();
    }
    foreach ($chunks as $index=>$chunk) {
        $audio=__DIR__.'/soundcache/'.$chunk['tts_cache_key'].'.wav';
        if (!is_file($audio) || filesize($audio)<=44) {
            $GLOBALS['CHIM_SPEECH_TRACE_ID']=$chunk['utterance_id'];
            try {
                callNpcTtsWithFallback($chunk['text'],'default',$chunk['utterance_id']);
            } catch (Throwable $error) {
                Logger::warn('[INTERACT] Narrator audio unavailable; subtitles remain available');
            }
        }
        clearstatcache(true,$audio);
        $chunk['audio_ready']=is_file($audio) && filesize($audio)>44;
        if ($stream) {
            echo json_encode(['ok'=>true,'id'=>$state['id'],'narration'=>$chunk,'chunk_index'=>$index],JSON_INVALID_UTF8_SUBSTITUTE)."\n";
            flush();
        }
    }
    if ($stream) {
        echo json_encode(['ok'=>true,'id'=>$state['id'],'done'=>true,'chunk_count'=>count($chunks)])."\n";
        flush();
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
        if (isset($snapshot['selected_magic']) && !is_array($snapshot['selected_magic'])) throw new InvalidArgumentException('Invalid selected magic');
        $selectedMagic=$snapshot['selected_magic'] ?? null;
        if ($selectedMagic!==null && (trim((string)($selectedMagic['name'] ?? ''))==='' || !in_array($selectedMagic['kind'] ?? '',['spell','power','shout'],true))) throw new InvalidArgumentException('Invalid selected magic');
        $target=mb_substr((string)($snapshot['target']['name'] ?? ''),0,160);
        $hasItem=$snapshot['item']!==null;
        $item=$hasItem ? mb_substr((string)($snapshot['item']['name'] ?? ''),0,160) : null;
        $allowed=array_intersect_key(chimInteractCatalog(),array_flip(array_filter($input['capabilities'] ?? [],'is_string')));
        if (($snapshot['target']['actor'] ?? false)===true) $allowed=array_diff_key($allowed,array_flip(['burning_visual','frost_visual','shock_visual','impact_burst','directional_throw','rotate','move']));
        $dispellable=$snapshot['target']['dispellable_spells'] ?? [];
        if (!is_array($dispellable) || !array_is_list($dispellable) || count($dispellable)>8) throw new InvalidArgumentException('Invalid captured dispel choices');
        if (!$dispellable) unset($allowed['dispel']);
        elseif (isset($allowed['dispel'])) $allowed['dispel']=[0,count($dispellable)-1];
        if ($selectedMagic===null) unset($allowed['cast_selected_magic']);
        if ($hasItem) unset($allowed['consume_world']);
        if (!$hasItem) $allowed=array_diff_key($allowed,array_flip(['give','store','consume','equip','magic','drop','place']));
        if (!$allowed || $target==='' || ($hasItem && $item==='')) throw new InvalidArgumentException('No supported interaction');
        $location=mb_substr((string)($snapshot['location'] ?? ''),0,160);
        $sceneFilter=$location!=='' && $location!=='unknown' ? " OR location='".$db->escape($location)."'" : '';
        $history=$db->fetchAll("SELECT type,data FROM eventlog WHERE type IN ('chat','infoaction','death','itemfound','itemremoved')
            AND localts>".(time()-1800)." AND (strpos(people, '|".$db->escape($target)."|')>0{$sceneFilter}) ORDER BY rowid DESC LIMIT 10");
        $history=array_reverse($history ?: []);
        $budget=6000;
        foreach ($history as &$event) { $event['data']=mb_substr((string)$event['data'],0,min(600,$budget)); $budget-=mb_strlen($event['data']); }
        unset($event);
        $npcMaster=new NpcMaster();
        $npc=$npcMaster->getByName($target);
        // A load can restore server profiles after the native actor was registered. Recover only
        // this freshly captured, exact actor through the normal template/voice profile creator.
        $speaker=(string)($snapshot['target']['speaker'] ?? '');
        $targetRef=(string)($snapshot['target']['ref_id'] ?? '');
        if (!$npc && ($snapshot['target']['actor'] ?? false)===true && $speaker===$target
            && preg_match('/^[A-Fa-f0-9]{8}$/D',$targetRef)) {
            createProfile($speaker);
            $npc=$npcMaster->getByName($speaker);
            if (!$npc || ($npc['npc_name'] ?? '')!==$speaker || ($npc['md5'] ?? '')!==md5($speaker)) {
                error_log('[INTERACT] target profile recovery failed id='.$id);
                throw new RuntimeException('The target NPC profile could not be registered');
            }
            $npc['refid']=strtoupper($targetRef);
            $npc['gamets_last_updated']=$gamets;
            if ($npcMaster->updateByArray($npc)===false) throw new RuntimeException('The target NPC identity could not be saved');
            error_log('[INTERACT] target profile recovered id='.$id.' ref='.strtoupper($targetRef));
        }
        $profile=[];
        foreach (['personality','occupation','goals','npc_static_bio'] as $field) $profile[$field]=mb_substr((string)($npc[$field] ?? ''),0,500);
        $state=['id'=>$id,'session'=>$session,'status'=>'resolving','player'=>$player,'target'=>$target,'item'=>$item,
            'gamets'=>$gamets,'allowed'=>$allowed,'intent'=>$intent,'selected_magic'=>$selectedMagic,
            'target_ref'=>(string)($snapshot['target']['ref_id'] ?? ''),
            'target_speaker'=>(string)($snapshot['target']['speaker'] ?? '')];
        $rowid=$db->insertReturningId('rolemaster',['type'=>'item_interaction','localts'=>time(),'ttl'=>600,'data'=>json_encode($state)],'rowid');
        if (!$rowid) throw new RuntimeException('Could not save request');
        if ($db->query('COMMIT')===false) throw new RuntimeException('Could not save attempt');
        unset($snapshot['target']['ref_id'],$snapshot['target']['speaker']);
        $plan=chimInteractGenerate(['player'=>$player,'intent'=>$intent,'current_game'=>$snapshot,'target_profile'=>$profile,'recent_context'=>$history],$allowed);
        $state['failure_scene']=empty($plan['steps']);
        $state['failure_scene_token']=$state['failure_scene'] ? bin2hex(random_bytes(16)) : '';
        if ($db->query('BEGIN')===false) throw new RuntimeException('Could not begin resolution');
        $state['plan']=$plan; $state['status']='ready';
        $encoded=$db->escape(json_encode($state,JSON_THROW_ON_ERROR));
        if (!$db->fetchOne("UPDATE rolemaster SET data='{$encoded}' WHERE rowid=".(int)$rowid." AND data::jsonb->>'status'='resolving' RETURNING rowid")) throw new RuntimeException('Could not save resolution');
        if (!$db->insertReturningId('eventlog',['type'=>'infoaction','ts'=>time(),'localts'=>time(),'gamets'=>$gamets,
            'data'=>"[Interact {$id} attempt] {$player} ".($hasItem ? "attempts to use {$item} on {$target}" : "attempts to interact with {$target} without an item").": {$intent}",
            'people'=>"|{$player}|{$target}|",'location'=>(string)($snapshot['location'] ?? ''),'party'=>'','sess'=>''], 'rowid')) throw new RuntimeException('Could not record attempt');
        if ($db->query('COMMIT')===false) throw new RuntimeException('Could not commit resolution');
        echo json_encode(['ok'=>true,'id'=>$id,'plan'=>$plan,'failure_scene_token'=>$state['failure_scene_token']],JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }
    if (in_array($op,['audio','receipt'],true) && $state && empty($state['plan']['steps'])) {
        if (($state['failure_scene'] ?? false)!==true || !is_string($state['failure_scene_token'] ?? null)
            || !preg_match('/^[a-f0-9]{32}$/D',$state['failure_scene_token'])
            || ($op==='receipt' && (!is_string($input['failure_scene_token'] ?? null)
                || !hash_equals($state['failure_scene_token'],$input['failure_scene_token']))))
            throw new InvalidArgumentException('No failure scene was authorized');
    }
    if ($op==='audio' && $state && hash_equals($state['session'],$session) && $state['status']==='completed') {
        $db->query('COMMIT');
        if (($input['stream'] ?? false)===true) chimInteractSpeech($state,true);
        else echo json_encode(['ok'=>true,'id'=>$id,'narration'=>chimInteractSpeech($state)],JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }
    if ($op==='receipt' && $state && hash_equals($state['session'],$session) && $state['status']==='completed') {
        if (($state['receipts'] ?? [])!==($input['receipts'] ?? [])) throw new InvalidArgumentException('Receipt changed');
        $db->query('COMMIT');
        echo json_encode(['ok'=>true,'id'=>$id,'narration'=>(($input['defer_audio'] ?? false)===true ? $state['narration'] : chimInteractSpeech($state))],JSON_INVALID_UTF8_SUBSTITUTE);
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
    // Spoken attempts use ordinary verbs; receipt facts retain the exact effect identifiers below.
    $attempts=[
        'frost'=>'chill '.$state['target'],
        'shock'=>'shock '.$state['target'],
        'drain_stamina'=>'drain the stamina of '.$state['target'],
        'drain_magicka'=>'drain the magicka of '.$state['target'],
        'slow'=>'slow '.$state['target'],
        'haste'=>'quicken '.$state['target'],
        'weaken_armor'=>'weaken the armor of '.$state['target'],
        'fortify_armor'=>'strengthen the armor of '.$state['target'],
        'weaken_weapon'=>'weaken the weapon damage of '.$state['target'],
        'fortify_weapon'=>'strengthen the weapon damage of '.$state['target'],
        'stagger'=>'stagger '.$state['target'],
        'absorb_health'=>'draw health from '.$state['target'],
        'absorb_stamina'=>'draw stamina from '.$state['target'],
        'absorb_magicka'=>'draw magicka from '.$state['target'],
        'ethereal'=>'make '.$state['target'].' ethereal',
        'soul_trap'=>'place a soul trap on '.$state['target'],
        'reanimate'=>'reanimate '.$state['target'],
        'banish'=>'banish '.$state['target'],
        'turn_undead'=>'turn away '.$state['target'],
        'extinguish'=>'extinguish the flames on '.$state['target'],
        'neutralize_poison'=>'neutralize the poison in '.$state['target'],
        'release_paralysis'=>'release the paralysis of '.$state['target'],
        'dispel'=>'dispel an effect on '.$state['target'],
        'directional_throw'=>'throw '.$state['target'],
        'rotate'=>'rotate '.$state['target'],
        'move'=>'move '.$state['target'],
        'frost_visual'=>'cover '.$state['target'].' with frost',
        'shock_visual'=>'wreathe '.$state['target'].' in sparks',
        'impact_burst'=>'create a burst of light at '.$state['target'],
        'observe'=>'examine '.$state['target'], 'pickup'=>'pick up '.$state['target'],
        'give'=>'give '.$state['item'].' to '.$state['target'], 'store'=>'put '.$state['item'].' in '.$state['target'],
        'consume'=>'give '.$state['item'].' to '.$state['target'], 'consume_world'=>'consume '.$state['target'],
        'equip'=>'outfit '.$state['target'].' with '.$state['item'], 'injure'=>'hurt '.$state['target'],
        'kill'=>'kill '.$state['target'], 'push'=>'push '.$state['target'], 'lock'=>'lock '.$state['target'],
        'unlock'=>'unlock '.$state['target'], 'activate'=>'use '.$state['target'], 'open'=>'open '.$state['target'],
        'close'=>'close '.$state['target'], 'destroy'=>'damage '.$state['target'], 'disable'=>'remove '.$state['target'],
        'resize'=>'change the size of '.$state['target'], 'magic'=>'use '.$state['item'].' on '.$state['target'],
        'cast_selected_magic'=>'use '.($state['selected_magic']['name'] ?? 'magic').' on '.$state['target'],
        'heal'=>'heal '.$state['target'], 'restore_stamina'=>'restore '.$state['target']."'s strength",
        'restore_magicka'=>'restore '.$state['target']."'s magicka", 'disarm'=>'disarm '.$state['target'],
        'unequip'=>'remove armor from '.$state['target'], 'drop'=>'drop '.$state['item'],
        'place'=>'place '.$state['item'].' near '.$state['target'],
        'burning_visual'=>'wreathe '.$state['target'].' in flames',
        'poison'=>'poison '.$state['target'], 'burning'=>'set '.$state['target'].' alight',
        'paralysis'=>'paralyze '.$state['target'], 'calm'=>'calm '.$state['target'],
        'fear'=>'frighten '.$state['target'], 'frenzy'=>'enrage '.$state['target'],
        'combat'=>'provoke '.$state['target'].' into a fight'
    ];
    foreach ($state['plan']['steps'] as $index=>$step) {
        $receipt=$input['receipts'][$index];
        $status=$receipt['status'] ?? '';
        if (!in_array($status,['succeeded','failed','skipped','unknown'],true)) throw new InvalidArgumentException('Invalid outcome');
        if ($status==='succeeded') foreach ($step['requires'] as $dependency) {
            if (($input['receipts'][$dependency]['status'] ?? '')!=='succeeded') throw new InvalidArgumentException('Unsatisfied effect dependency');
        }
        $detail=mb_substr((string)($receipt['detail'] ?? ''),0,300);
        $facts[]=$step['effect'].': '.$status.($detail!=='' ? ' ('.$detail.')' : '');
        if ($status==='succeeded' && $step['effect']==='cast_selected_magic') $sentences[]=$state['player'].' casts '.($state['selected_magic']['name'] ?? 'the selected magic').'.';
        elseif ($status==='succeeded' && $step['effect']==='activate') $sentences[]=$state['player'].' uses '.$state['target'].'.';
        elseif ($status==='succeeded' && $step['effect']==='consume_world') $sentences[]=$state['player'].' finishes '.$state['target'].'.';
        elseif ($status==='succeeded' && $step['effect']==='consume') $sentences[]=$state['target'].' finishes '.$state['item'].'.';
        elseif ($status==='succeeded' && $step['narration']!=='') $sentences[]=$step['narration'];
        elseif ($status==='unknown' || $status==='failed') {
            if (str_starts_with($detail,'World item transferred')) $sentences[]=$state['player'].' takes '.$state['target'].' and tries to consume it.';
            elseif (str_starts_with($detail,'Item transferred')) $sentences[]=$state['target'].' receives '.$state['item']
                .($status==='failed' ? ', but the attempt goes no further.' : '.');
            elseif (str_starts_with($detail,'Scroll consumed')) $sentences[]=$state['player'].' uses up the scroll.';
            elseif ($status==='failed' && !empty($step['failure_narration'])) $sentences[]=$step['failure_narration'];
            else $sentences[]=$state['player'].' tries to '.($attempts[$step['effect']] ?? 'act on '.$state['target'])
                .($status==='failed' ? ', but the attempt falls short. Confidence, alas, is not quite enough.' : '.');
        }
    }
    if (!empty($state['failure_scene'])) {
        $sentences[]=$state['plan']['failure_narration'];
        $facts[]='failed attempt: no game effects executed';
    }
    if (!$sentences) $sentences[]=$state['player']."'s attempt ends before it can get underway.";
    $text=implode(' ',$sentences);
    $state['status']='completed'; $state['receipts']=$input['receipts'];
    $utterance='interact-'.$id;
    $state['narration']=['text'=>$text,'utterance_id'=>$utterance,'tts_cache_key'=>md5($utterance)];
    $state['narration']['chunks']=[];
    foreach (split_sentences_stream(cleanResponse($text)) as $index=>$chunkText) {
        $chunkId=$utterance.'-'.$index;
        $state['narration']['chunks'][]=['text'=>$chunkText,'utterance_id'=>$chunkId,'tts_cache_key'=>md5($chunkId)];
    }
    $encoded=$db->escape(json_encode($state,JSON_THROW_ON_ERROR));
    if ($db->query("UPDATE rolemaster SET data='{$encoded}' WHERE rowid=".(int)$row['rowid'])===false
        || !$db->insertReturningId('eventlog',['type'=>'infoaction','ts'=>time(),'localts'=>time(),'gamets'=>$gamets,
            'data'=>"[Interact {$id} outcome] ".$state['player'].(!empty($state['failure_scene']) ? ' attempted to interact with ' : ($state['item']!==null ? ' used '.$state['item'].' on ' : ' interacted without an item with ')).$state['target'].': '.implode('; ',$facts).'. '.$text,
            'people'=>'|'.$state['player'].'|'.$state['target'].'|','location'=>'','party'=>'','sess'=>'',
            'utterance_id'=>$utterance,'delivery_state'=>'pending'],'rowid')) throw new RuntimeException('Could not save outcome');
    if ($db->query('COMMIT')===false) throw new RuntimeException('Could not commit outcome');
    echo json_encode(['ok'=>true,'id'=>$id,'narration'=>(($input['defer_audio'] ?? false)===true ? $state['narration'] : chimInteractSpeech($state))],JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $error) {
    if (isset($db)) {
        $db->query('ROLLBACK');
        if (isset($rowid,$state) && in_array($state['status'] ?? '', ['resolving','ready'], true)) {
            $state['status']='failed';
            $encoded=$db->escape(json_encode($state,JSON_INVALID_UTF8_SUBSTITUTE));
            $db->fetchOne("UPDATE rolemaster SET data='{$encoded}' WHERE rowid=".(int)$rowid." AND data::jsonb->>'status'='resolving' RETURNING rowid");
        }
    }
    error_log('[INTERACT] '.$error->getMessage());
    http_response_code(409);
    echo json_encode(['ok'=>false,'code'=>($op ?? 'resolve')==='resolve' ? 'no_action' : 'interaction_failed',
        'message'=>'The interaction could not be completed. No uncertain effects will be repeated.']);
}
