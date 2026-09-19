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

$GLOBALS["ENGINE_PATH"] = $GLOBALS["ENGINE_ROOT"]; // Todo, make this uniform

$GLOBALS["active_profile"] = md5("The Narrator");
$GLOBALS["CURRENT_CONNECTOR"] = DMgetCurrentModel();
$GLOBALS["CHIM_NO_EXAMPLES"] = true; // When no assistant entry in history, will try ti provide a bogus example.

if (!chimIsGlobalLlmConnectorEnabled('CORE_CONNECTOR_PROFILES')) {
    logMsg('Profile connector is disabled globally; skipping hypnosis task');
    return;
}

$connector = new LLMConnector();
$currentConnectorData = $connector->getById(intval($GLOBALS["CORE_CONNECTOR_PROFILES"] ?? 0));
$connectionHandler = $connector->getConnector($currentConnectorData);

$GLOBALS["CHIM_CORE_CURRENT_CONNECTOR_DATA"] = $currentConnectorData;
$GLOBALS["CURRENT_CONNECTOR"] = $currentConnectorData["driver"];

$connector->setOldGlobals($currentConnectorData);

//function dps_run(?string $manualName = null, ?callable $generator = null, $connection = null): array {

$contextCreator = function () {
    error_log("Hypnosis task started");
    return "<MARK>";
};

$npc['fields'] = [
    'personality',
    'goals',
    'speechstyle',
    'occupation'
];

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
?>
