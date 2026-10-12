<?php
require_once(__DIR__ . '/../../../../lib/logger.php');

require_once($GLOBALS["ENGINE_ROOT"] . "/lib/{$GLOBALS["DBDRIVER"]}.class.php");
if (!isset($GLOBALS["db"])) {
    $GLOBALS["db"] = new sql();
}

require_once($GLOBALS["ENGINE_ROOT"] . "/lib/core/api_badge.class.php");
require_once($GLOBALS["ENGINE_ROOT"] . "/lib/core/llm_connector.class.php");
require_once($GLOBALS["ENGINE_ROOT"] . "/lib/core/npc_master.class.php");
require_once($GLOBALS["ENGINE_ROOT"] . "/lib/core/core_profiles.class.php");
require_once($GLOBALS["ENGINE_ROOT"] . "/lib/relationship_manager.php");
require_once($GLOBALS["ENGINE_ROOT"] . "/lib/dynamic_update_util.php");
require_once($GLOBALS["ENGINE_ROOT"] . "/lib/core/narrator.class.php");

$GLOBALS["ENGINE_PATH"] = $GLOBALS["ENGINE_ROOT"]; // Todo, make this uniform

$GLOBALS["active_profile"] = md5("The Narrator");
$GLOBALS["CURRENT_CONNECTOR"] = DMgetCurrentModel();
$GLOBALS["CHIM_NO_EXAMPLES"] = true; // When no assistant entry in history, will try ti provide a bogus example.

if (!chimIsGlobalLlmConnectorEnabled('CORE_CONNECTOR_SUMMARY')) {
    logMsg('Summary connector is disabled globally; skipping smartwait task');
    return;
}

if (!isset($GLOBALS["db"])) {
    $GLOBALS["db"] = new sql();
}

$GLOBALS["db"]->insert(
    'responselog',
    array(
        'localts' => time(),
        'sent' => 0,
        'actor' => "rolemaster",
        'text' => '',
        'action' => 'rolecommand|DebugNotification@Preparing content for SmartWait',
        'tag' => ""
    )
);

$notifySmartWaitFailure = function () {
    $GLOBALS["db"]->insert('responselog', [
        'localts' => time(),
        'sent' => 0,
        'actor' => 'rolemaster',
        'text' => '',
        'action' => 'rolecommand|DebugNotification@Smart Wait could not prepare the scene. Check the server log.',
        'tag' => '',
    ]);
};

$connector = new LLMConnector();
$currentConnectorData = $connector->getById(intval($GLOBALS["CORE_CONNECTOR_SUMMARY"] ?? 0));
$connectionHandler = null;
// Validate the record before getConnector() requires the driver file; a missing driver file is fatal, not catchable.
$connectorDriver = is_array($currentConnectorData) ? ($currentConnectorData["driver"] ?? null) : null;
if (!is_string($connectorDriver) || !preg_match('/^[A-Za-z0-9_]+$/', $connectorDriver)
    || !is_file($GLOBALS["ENGINE_ROOT"] . "/connector/{$connectorDriver}.php")) {
    Logger::warn('[SMARTWAIT] Summary connector is missing or has an invalid driver');
} else {
    try {
        $connectionHandler = $connector->getConnector($currentConnectorData);
    } catch (\Throwable $e) {
        Logger::warn('[SMARTWAIT] Connector initialization failed: ' . $e->getMessage());
    }
}
if (!is_object($connectionHandler)) {
    $notifySmartWaitFailure();
    return;
}

$GLOBALS["CHIM_CORE_CURRENT_CONNECTOR_DATA"] = $currentConnectorData;
$GLOBALS["CURRENT_CONNECTOR"] = $currentConnectorData["driver"];

$connector->setOldGlobals($currentConnectorData);
$sqlfilter = " and type<>'backgroundaction' ";
$contextDataHistoric = DataLastDataExpandedFor("{$GLOBALS["PLAYER_NAME"]}", -10, $sqlfilter);    // Full context
$contextDataHistoricText = "";
foreach ($contextDataHistoric as $element) {
    // We should clean here background events entries
    $contextDataHistoricText .= $element["content"] . "\n";
}



$contextDataWorld = DataLastInfoFor(
    "",
    -2,
    true,
    false,
    false
) ?? [];

$contextDataWorld = $GLOBALS["PROMPT_NEARBY_SECTIONS"];
$userPrompt = $GLOBALS['argv'][3];
$prompt[] = array('role' => 'system', 'content' => "Skyrim roleplay. You're the director of the story, responsible for guiding the narrative and managing the flow of events.");
$prompt[] = array('role' => 'user', 'content' => "# Contextual data\n$contextDataWorld");
$prompt[] = array('role' => 'user', 'content' => "# Historic data\n$contextDataHistoricText");
$prompt[] = array('role' => 'user', 'content' => "# Current location\n" . DataLastKnownLocationHuman());
$prompt[] = array('role' => 'user', 'content' => "Your task is to generate content (dialogue and narration) based on the provided context and historic data for the present actors.\nContext must follow this guildeline: '$userPrompt'");
$prompt[] = array(
    'role' => 'user',
    'content' => "
* Output format: text-only, 2 paragraphs.
* Actions must happen in current location. 
"
);



try {
    $generatedContent = $connectionHandler->fast_request($prompt, ['MAX_TOKENS' => 1024], 'smartwait');
} catch (\Throwable $e) {
    Logger::warn('[SMARTWAIT] Scene generation failed: ' . $e->getMessage());
    $generatedContent = '';
}
$generatedContent = is_string($generatedContent) ? trim($generatedContent) : '';
// Without a scene there is nothing to wait through; do not log empty narration or open the Wait menu.
if ($generatedContent === '') {
    $notifySmartWaitFailure();
    return;
}
print_r($generatedContent);

$lastGameTsRow = $GLOBALS["db"]->fetchAll('SELECT max(gamets) AS last_gamets FROM eventlog');
$lastTsRow = $GLOBALS["db"]->fetchAll("SELECT max(ts) AS ts FROM eventlog WHERE gamets='{$lastGameTsRow[0]['last_gamets']}'");

$last_gamets = (int) $lastGameTsRow[0]['last_gamets'] + 1;
$last_ts = $lastTsRow[0]['ts'];
$momentum=time();

$GLOBALS["db"]->insert('eventlog', [
    'ts' => $last_ts,
    'gamets' => $last_gamets + 20,
    'type' => 'innerchat',
    'data' => chimGetNarratorRoleplayName() . ": \n$generatedContent",
    'sess' => $momentum,
    'localts' => time(),
    'people' => DataBeingsInRange(),
    'location' => DataLastKnownLocation(),
    'party' => '',
]);


$GLOBALS["db"]->insert('responselog', [
    'localts' => time(),
    'sent' => 0,
    'actor' => 'rolemaster',
    'text' => '',
    'action' => "rolecommand|SmartWait@",
    'tag' => '',
]);
/*
$GLOBALS["db"]->insert(
    'responselog',
    array(
        'localts' => time(),
        'sent' => 0,
        'actor' => "rolemaster",
        'text' => '',
        'action' => 'rolecommand|DebugNotification@Hypnosis started for ' . $GLOBALS['argv'][4] . '.',
        'tag' => ""
    )
);

$newvalue = [];
$generationFailed = false;
foreach ($npc['fields'] as $field) {
    $prompt = "Note: There is no story for this character, you will have to create it (be imaginative), Skyrim lore based, based on this hint.";
    $prompt .= "\nMandatory hint from rolemaster to {$GLOBALS["argv"][4]} : " . $GLOBALS["argv"][3];
    $prompt .= "\nCurrent location (character should be attached to this location): " . DataLastKnownLocation();

    if (isset($newvalue["personality"])) {
        $prompt .= "\nCurrent personality: " . $newvalue["personality"];
    }
    if ($field == "speechstyle") {
        $prompt .= "\nCreate a new speech style for the character, consistent with their personality and goals. Also create character's filler words and common expressions";
    }
    $newvalue[$field] = updateDynamicProfileField($GLOBALS["argv"][4], $field, $prompt);
    if (!is_string($newvalue[$field]) || trim($newvalue[$field]) === '') {
        $generationFailed = true;
        Logger::warn('[HYPNOSIS] Profile generation failed; existing fields were kept');
        break;
    }
    error_log("[HYPNOSIS] " . print_r($newvalue[$field], true));
}

$saved = false;
if (!$generationFailed) {
    // Save all four generated fields together, preserving names such as J'zargo.
    $escapedTarget = $GLOBALS['db']->escape($GLOBALS['argv'][4]);
    $saved = $GLOBALS["db"]->upsertRow(
        'core_npc_master',
        $newvalue,
        "npc_name='{$escapedTarget}'"
    );
}
$notification = $saved
    ? "Updated basic profile for {$GLOBALS['argv'][4]}"
    : 'Hypnosis could not update the profile. Existing fields were kept.';
$GLOBALS["db"]->insert(
    'responselog',
    array(
        'localts' => time(),
        'sent' => 0,
        'actor' => "rolemaster",
        'text' => '',
        'action' => 'rolecommand|DebugNotification@' . $notification,
        'tag' => ""
    )
);
*/
?>