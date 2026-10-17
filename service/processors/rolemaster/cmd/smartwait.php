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

if (!isset($GLOBALS["db"])) {
    $GLOBALS["db"] = new sql();
}

// The dispatcher passes the request's interaction generation and clock epoch; this worker starts later and
// would otherwise adopt whatever is live then. Direct legacy invocations omit both and use the live values.
$smartWaitArgs = $GLOBALS['argv'] ?? [];
$userPrompt = trim((string)($smartWaitArgs[3] ?? ''));
if ($userPrompt === '') {
    Logger::warn('[SMARTWAIT] No scene was provided');
    return;
}
if (isset($smartWaitArgs[4]) || isset($smartWaitArgs[5])) {
    $smartWaitGeneration = (string)($smartWaitArgs[4] ?? '');
    $smartWaitEpoch = (string)($smartWaitArgs[5] ?? '');
    if (!preg_match('/^-?\d+$/', $smartWaitGeneration) || !preg_match('/^[0-9a-f]*$/', $smartWaitEpoch)) {
        Logger::warn('[SMARTWAIT] Invalid request generation or timeline; scene skipped');
        return;
    }
    // Off/On between the request and this worker invalidates the request; its scene is discarded silently.
    if ((int)$smartWaitGeneration !== $GLOBALS['chim_interaction_generation']) {
        Logger::info('[SMARTWAIT] Interaction generation changed before the worker started; scene skipped');
        return;
    }
    // No clock existed at submission; the first recorded time starts the timeline.
    if ($smartWaitEpoch === '') $smartWaitEpoch = null;
} else {
    $smartWaitEpoch = null;
    Logger::info('[SMARTWAIT] Started without request context; using the current generation and timeline');
}

$notifySmartWait = function (string $message) {
    $GLOBALS["db"]->insert('responselog', [
        'localts' => time(),
        'sent' => 0,
        'actor' => 'rolemaster',
        'text' => '',
        'action' => 'rolecommand|DebugNotification@' . $message,
        'tag' => '',
    ]);
};
$notifySmartWaitFailure = function () use ($notifySmartWait) {
    $notifySmartWait('Smart Wait could not prepare the scene. Check the server log.');
};

if (!chimIsGlobalLlmConnectorEnabled('CORE_CONNECTOR_SUMMARY')) {
    logMsg('Summary connector is disabled globally; skipping smartwait task');
    $notifySmartWait('Enable Summaries to use Smart Wait.');
    return;
}

// One scene at a time; the session lock is released when this worker process exits.
$smartWaitLock = $GLOBALS["db"]->fetchOne("SELECT pg_try_advisory_lock(hashtext('chim_smart_wait')) AS locked");
if (($smartWaitLock['locked'] ?? '') !== 't') {
    $notifySmartWait('Smart Wait is already preparing a scene.');
    return;
}

// A game load that rewinds time starts a new clock epoch; a scene from the old timeline must not be published.
$smartWaitClock = function () {
    $row = $GLOBALS["db"]->fetchOne("SELECT value FROM conf_opts WHERE id='DYNAMIC_PROFILE_CLOCK'");
    return (string)((json_decode($row['value'] ?? '{}', true) ?: [])['epoch'] ?? '');
};
$smartWaitEpoch ??= $smartWaitClock();
if ($smartWaitClock() !== $smartWaitEpoch) {
    Logger::info('[SMARTWAIT] Game timeline changed before the worker started; scene skipped');
    $notifySmartWait('Smart Wait scene was discarded because an earlier save was loaded.');
    return;
}

$notifySmartWait('Preparing content for SmartWait');

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
// The Wait menu is already open; without a scene there is nothing to record or narrate.
if ($generatedContent === '') {
    $notifySmartWaitFailure();
    return;
}

// Off/On invalidates this request's generation, so its output is discarded silently; a timeline change is reported.
$smartWaitCurrent = function () use ($smartWaitClock, $smartWaitEpoch) {
    if (!chimInteractionAllowed()) {
        Logger::info('[SMARTWAIT] Interaction generation changed; scene discarded');
        return false;
    }
    if ($smartWaitClock() !== $smartWaitEpoch) {
        Logger::info('[SMARTWAIT] Game timeline changed; scene discarded');
        return false;
    }
    return true;
};
$notifySmartWaitStale = function () use ($notifySmartWait) {
    if (chimInteractionAllowed()) {
        $notifySmartWait('Smart Wait scene was discarded because an earlier save was loaded.');
    }
};
if (!$smartWaitCurrent()) {
    $notifySmartWaitStale();
    return;
}

// Rolemaster runs outside main.php, which normally sets the dialogue chunk sizes.
if (!defined('MAXIMUM_SENTENCE_SIZE')) define('MAXIMUM_SENTENCE_SIZE', 125);
if (!defined('MINIMUM_SENTENCE_SIZE')) define('MINIMUM_SENTENCE_SIZE', 15);

// Speak through The Narrator's configured voice; routing uses the canonical name, history the roleplay name.
loadNarratorVoiceSettings();
$GLOBALS["HERIKA_NAME"] = Narrator::CANONICAL_NAME;
$narratorRoleplayName = chimGetNarratorRoleplayName();
$sceneText = stripOutputSpeakerPrefix(stripOutputSpeakerPrefix($generatedContent, Narrator::CANONICAL_NAME), $narratorRoleplayName);
$ttsFunction = strval($GLOBALS["TTSFUNCTION"] ?? '');
$ttsExpected = $ttsFunction !== '' && strcasecmp($ttsFunction, 'none') !== 0;
$ttsFailed = false;
$sceneLines = [];
foreach (preg_split('/\R+/u', $sceneText) as $paragraph) {
    // ScriptQueue fields are slash-delimited and responselog rows pipe-delimited.
    $paragraph = cleanupDisplayText(strtr($paragraph, ['/' => ' ', '|' => ' ', '*' => '']), $narratorRoleplayName);
    if ($paragraph === '') continue;
    foreach (split_sentences_stream($paragraph) as $chunk) {
        $subtitle = trim($chunk);
        if ($subtitle === '') continue;
        $speech = chimApplyTtsPronunciationDictionary($subtitle, null, []);
        $utteranceId = chimGenerateUtteranceId();
        $GLOBALS['CHIM_SPEECH_TRACE_ID'] = $utteranceId;
        chimSpeechTrace('sentence_ready', ['sentence' => count($sceneLines) + 1]);
        if ($ttsExpected) {
            // The client fetches audio by the hash of the subtitle it displays.
            try {
                callConfiguredTts($speech, 'default', $subtitle);
            } catch (\Throwable $e) {
                Logger::warn('[SMARTWAIT] Narration audio failed: ' . $e->getMessage());
            }
            $audio = $GLOBALS["ENGINE_ROOT"] . '/soundcache/' . md5(trim($subtitle)) . '.wav';
            if (!is_file($audio) || filesize($audio) <= 44) $ttsFailed = true;
        }
        $phonetic = $speech !== $subtitle ? $speech : '';
        $sceneLines[] = ['utterance_id' => $utteranceId,
            'text' => "{$subtitle}////{$phonetic}/1/explicit_disable_rechat/{$utteranceId}"];
    }
}
unset($GLOBALS['CHIM_SPEECH_TRACE_ID']);
if (!$sceneLines) {
    $notifySmartWaitFailure();
    return;
}
if (!$smartWaitCurrent()) {
    $notifySmartWaitStale();
    return;
}

$lastGameTsRow = $GLOBALS["db"]->fetchAll('SELECT max(gamets) AS last_gamets FROM eventlog');
$lastTsRow = $GLOBALS["db"]->fetchAll("SELECT max(ts) AS ts FROM eventlog WHERE gamets='{$lastGameTsRow[0]['last_gamets']}'");

$last_gamets = (int) $lastGameTsRow[0]['last_gamets'] + 1;
$last_ts = $lastTsRow[0]['ts'];
$momentum=time();

// History and delivery commit together, so a refused or failed write leaves neither behind.
$db = $GLOBALS["db"];
$published = false;
$stale = false;
if ($db->query('BEGIN') !== false) {
    try {
        $published = (bool)$db->insertReturningId('eventlog', [
            'ts' => $last_ts,
            'gamets' => $last_gamets + 20,
            'type' => 'innerchat',
            'data' => $narratorRoleplayName . ": \n$sceneText",
            'sess' => $momentum,
            'localts' => time(),
            'people' => DataBeingsInRange(),
            'location' => DataLastKnownLocation(),
            'party' => '',
        ], 'rowid');
        foreach ($sceneLines as $line) {
            $published = $published && $db->insertReturningId('responselog', [
                'localts' => time(),
                'sent' => 0,
                'actor' => Narrator::CANONICAL_NAME,
                'text' => $line['text'],
                'action' => 'ScriptQueue',
                'tag' => '',
            ], 'rowid');
        }
        $stale = $published && !$smartWaitCurrent();
        $published = $published && !$stale && $db->query('COMMIT') !== false;
    } catch (\Throwable $e) {
        Logger::warn('[SMARTWAIT] Scene publication failed: ' . $e->getMessage());
        $published = false;
    }
    if (!$published) $db->query('ROLLBACK');
}
if (!$published) {
    // Writes are refused after Off/On; only report failures for the current interaction generation.
    if ($stale) {
        $notifySmartWaitStale();
    } elseif (chimInteractionAllowed()) {
        Logger::warn('[SMARTWAIT] Scene could not be published');
        $notifySmartWaitFailure();
    }
    return;
}
foreach ($sceneLines as $line) chimSpeechTrace('queued_for_delivery', [], $line['utterance_id']);
if ($ttsFailed) {
    $notifySmartWait('Smart Wait narration voice could not be generated. Check the server log.');
}
Logger::info('[SMARTWAIT] Scene queued for narration: lines=' . count($sceneLines));
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