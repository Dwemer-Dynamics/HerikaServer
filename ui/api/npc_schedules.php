<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(204); exit; }
require_once dirname(__DIR__,2) . '/lib/runtime_bootstrap.php';
chimRuntimeBootstrap(dirname(__DIR__,2) . '/', ['load_general_settings'=>false]);
require_once dirname(__DIR__,2) . '/lib/core/npc_master.class.php';
require_once dirname(__DIR__,2) . '/lib/core/npc_schedules.php';
try {
    $input=$_SERVER['REQUEST_METHOD']==='POST' ? json_decode(file_get_contents('php://input'),true,32,JSON_THROW_ON_ERROR) : $_GET;
    $npcId=(int)($input['npc_id']??0); $npc=(new NpcMaster())->getById($npcId);
    if (!$npc) throw new InvalidArgumentException('NPC not found.');
    $operation=$input['operation']??'list'; $db=$GLOBALS['db']; $id=(int)($input['id']??0);
    if ($_SERVER['REQUEST_METHOD']==='POST') {
        if (($input['epoch'] ?? '') !== (chimScheduleClock()['epoch'] ?? null)) throw new InvalidArgumentException('The game timeline changed. Refresh this menu.');
        if ($operation==='save') chimScheduleSave($npc,$input,$id);
        else {
            chimScheduleManage($npcId,$id,$operation,(string)($input['epoch']??''));
        }
    }
    $rows=$db->fetchAll("SELECT t.*,r.phase,r.result,r.pending_op FROM npc_commitments t LEFT JOIN LATERAL (SELECT phase,result,pending_op FROM npc_schedule_runs WHERE task_id=t.id ORDER BY id DESC LIMIT 1) r ON true WHERE t.npc_id={$npcId} AND t.schedule IS NOT NULL ORDER BY t.due_gamets,t.id LIMIT 100");
    foreach($rows as &$row) { $row['schedule']=json_decode($row['schedule'],true); $row['departure_gamets']=(int)$row['due_gamets']-chimCommitmentHoursToGamets(3); } unset($row);
    echo json_encode(['success'=>true,'clock'=>chimScheduleClock(),'schedules'=>$rows,'locations'=>$operation==='locations'?chimScheduleLocations((string)($input['search']??'')):[]],JSON_THROW_ON_ERROR);
} catch(Throwable $e) { http_response_code($e instanceof InvalidArgumentException?400:409); echo json_encode(['success'=>false,'error'=>$e->getMessage()]); }
