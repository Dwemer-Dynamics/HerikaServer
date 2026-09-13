<?php
require_once __DIR__ . '/lib/chim_interaction.php';
chimInteractionRequire();
// Dispatch only actions previously validated and stored by the Director worker.
ini_set('display_errors', '0');
require_once __DIR__ . '/lib/runtime_bootstrap.php';
chimRuntimeBootstrap(__DIR__, ['run_db_updates' => false, 'load_general_settings' => true,
    'load_stt_connector' => false, 'load_itt_connector' => false, 'load_player_name' => true, 'load_narrator' => true]);
require_once __DIR__ . '/lib/core/npc_master.class.php';
require_once __DIR__ . '/lib/core/core_profiles.class.php';
require_once __DIR__ . '/lib/data_functions.php';
require_once __DIR__ . '/lib/director_scene.php';
header('Content-Type: application/json');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); exit; }
$request = json_decode(file_get_contents('php://input'), true);
$id = $request['scene_id'] ?? '';
$after = $request['after_line'] ?? 0;
$approvedIndex = $request['approved_action'] ?? null;
if (!is_string($id) || !preg_match('/^[a-f0-9]{32}$/D', $id) || !is_int($after) || $after < 1 || $after > 5) {
    http_response_code(400); echo '{"ok":false}'; exit;
}
if ($approvedIndex !== null && (!is_int($approvedIndex) || $approvedIndex < 0 || $approvedIndex > 2)) {
    http_response_code(400); exit;
}
$db = $GLOBALS['db'];
$db->query('BEGIN');
try {
    $row = $db->fetchOne("SELECT rowid, data FROM rolemaster WHERE type='director_scene'
        AND localts > " . (time() - 600) . " AND data::jsonb->>'id'='{$id}' FOR UPDATE");
    $scene = json_decode($row['data'] ?? '', true);
    if (!$scene || ($approvedIndex === null && !empty($scene['dispatched'][$after]))
        || ($approvedIndex !== null && (empty($scene['awaiting_confirmation'][$approvedIndex])
            || ($scene['actions'][$approvedIndex]['after_line'] ?? 0) !== $after))) {
        $db->query('ROLLBACK'); echo '{"ok":true,"commands":[]}'; exit;
    }
    $line = $scene['lines'][$after - 1] ?? null;
    if (!$line) throw new RuntimeException('Unknown Director turn');
    $utterance = $db->escape($line['utterance_id']);
    $delivery = $db->fetchOne("SELECT delivery_state FROM eventlog WHERE utterance_id='{$utterance}' AND type='chat' LIMIT 1");
    if (!$delivery || in_array($delivery['delivery_state'], ['aborted', 'failed'], true)) {
        throw new RuntimeException('Cancelled Director turn');
    }
    $scene['dispatched'][$after] = true;
    if ($approvedIndex !== null) unset($scene['awaiting_confirmation'][$approvedIndex]);
    $encoded = $db->escape(json_encode($scene, JSON_THROW_ON_ERROR));
    if ($db->query("UPDATE rolemaster SET data='{$encoded}' WHERE rowid=" . (int)$row['rowid']) === false
        || $db->query('COMMIT') === false) throw new RuntimeException('Could not claim Director actions');
} catch (Throwable $error) {
    $db->query('ROLLBACK'); http_response_code(409); echo '{"ok":false,"commands":[]}'; exit;
}

// Capture immediate command output while preserving ordinary catalog side effects and delayed commands.
class ChimDirectorDispatchDb extends sql
{
    public array $commands = [];
    private sql $connectionOwner;
    public function __construct(sql $connectionOwner) {
        // The base driver shares a static connection; retain its owner so replacement cannot close it.
        $this->connectionOwner = $connectionOwner;
    }
    public function insert($table, $data)
    {
        if ($table === 'responselog' && (int)($data['localts'] ?? 0) <= time()) {
            $parts = explode('|', $data['action'] ?? '', 2);
            $this->commands[] = ['actor' => $data['actor'] ?? 'rolemaster',
                'channel' => $parts[0], 'text' => $parts[1] ?? ''];
            return true;
        }
        if ($table === 'actions_issued') $data['original'] = '[director_scene]' . ($data['original'] ?? '');
        return parent::insert($table, $data);
    }
}
$dispatchDb = new ChimDirectorDispatchDb($db);
$GLOBALS['db'] = $dispatchDb;
$GLOBALS['gameRequest'] = ['director_action', time(), (int)($request['gamets'] ?? 0), ''];
require_once __DIR__ . '/functions/functions.php';
$master = new NpcMaster();
foreach ($scene['actions'] as $actionIndex => $action) {
    if ($action['after_line'] !== $after || ($approvedIndex !== null && $approvedIndex !== $actionIndex)) continue;
    try {
        $npc = $action['speaker'] === 'The Narrator'
            ? (new Narrator())->getNarratorData() : $master->getByName($action['speaker']);
        if (!$npc) continue;
        $actors = [$action['speaker'] => $npc];
        $catalog = chimDirectorActionCatalog($actors);
        if (!isset($catalog[$action['command_name']])) continue;
        chimDirectorActorGlobals($npc);
        $execution = buildFunctionExecutionContextFromResponse(array_merge($action['parameters'], ['action' => $action['command_name']]));
        if (!empty($execution['missing_required'])) continue;
        $commands = [];
        $sent = [];
        if (!queueFunctionExecutionCommand($commands, $sent, $execution, 'Director', $action['speaker'])) continue;
        $needsConfirmation = str_contains($commands[0], '|confirmcommand|');
        if ($needsConfirmation && $approvedIndex === null) {
            // Persist the offer before returning it. Approval claims it once, before server-side effects.
            if ($dispatchDb->query("UPDATE rolemaster SET data=jsonb_set(data::jsonb, '{awaiting_confirmation}',
                COALESCE(data::jsonb->'awaiting_confirmation', '{}'::jsonb) || '{\"{$actionIndex}\":true}'::jsonb)::text
                WHERE rowid=" . (int)$row['rowid']) === false) throw new RuntimeException('Could not store action confirmation');
            $dispatchDb->commands[] = ['actor' => $action['speaker'], 'channel' => 'directorconfirm',
                'text' => $action['command_name'], 'action_index' => $actionIndex];
            continue;
        }
        $wasApproved = $approvedIndex !== null || str_contains($commands[0], '|approvedcommand|');
        $commands = array_map(static fn($command) => str_replace(['|confirmcommand|', '|approvedcommand|'], '|command|', $command), $commands);
        foreach ($GLOBALS['action_post_process_fnct_ex'] ?? [] as $filter) $commands = $filter($commands);
        foreach ($commands as $command) {
            $parts = explode('|', trim($command), 3);
            if (count($parts) !== 3) continue;
            $dispatchDb->commands[] = ['actor' => $parts[0],
                'channel' => $wasApproved && $parts[1] === 'command' ? 'approvedcommand' : $parts[1], 'text' => $parts[2]];
        }
        $dispatchDb->insert('actions_issued', ['action' => $action['command_name'], 'actorname' => $action['speaker'],
            'fullcall' => $action['speaker'] . '|command|' . $action['command_name'] . '@' . $execution['parameter_string'],
            'ts' => time(), 'gamets' => $GLOBALS['gameRequest'][2], 'localts' => time(), 'original' => '']);
    } catch (Throwable $error) {
        Logger::warn('[DIRECTOR] Action dispatch failed: ' . $error->getMessage());
    }
}
echo json_encode(['ok' => true, 'commands' => $dispatchDb->commands], JSON_INVALID_UTF8_SUBSTITUTE);
