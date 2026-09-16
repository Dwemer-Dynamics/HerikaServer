<?php

/**
 * BGL Processor (v2 — refactored)
 *
 * Generates an NPC "BGL" cycle when the player is absent:
 *   1. Inner-thought soliloquy (Step 1 LLM call)
 *   2. Action decision (Step 2 LLM call)
 *
 * Usage:
 *   php simple_llm_request_with_context_life_v2.php <npc_name> [dryrun|forceletter|forceaction|full] [forceaction]
 */

// ─── Bootstrap ────────────────────────────────────────────────────────────────

$startTime = microtime(true);

define('MAXIMUM_SENTENCE_SIZE', 125);
define('MINIMUM_SENTENCE_SIZE', 15);

/** Conversion factor: in-game time units (gamets) → real hours */
define('GAMETS_TO_HOURS', 0.0000024);

define('HISTORY_LIMIT', 75);   // Max number of context entries to include in the LLM prompt

// Expected globals consumed by included library functions
$GLOBALS['SCRIPTLINE_EXPRESSION'] = '';
$GLOBALS['SCRIPTLINE_LISTENER'] = '';
$GLOBALS['SCRIPTLINE_ANIMATION'] = '';

error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('max_execution_time', 120); // Set maximum execution time to 2 minutes

$enginePath = dirname((__FILE__)) . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR. ".." . DIRECTORY_SEPARATOR. ".." . DIRECTORY_SEPARATOR. ".." . DIRECTORY_SEPARATOR;;
$GLOBALS['ENGINE_PATH'] = $enginePath;

// ─── Includes ─────────────────────────────────────────────────────────────────

require_once $enginePath . 'lib/runtime_bootstrap.php';
chimRuntimeBootstrap($enginePath, [
    'load_general_settings' => true,
    'load_player_name' => true,
    'load_narrator' => true,
]);

if (!chimIsGlobalLlmConnectorEnabled('CORE_CONNECTOR_BGL')) {
    echo "Background Life is disabled globally." . PHP_EOL;
    exit(0);
}

require_once $enginePath . 'lib/model_dynmodel.php';
require_once $enginePath . 'lib/chat_helper_functions.php';
require_once $enginePath . 'lib/data_functions.php';
require_once $enginePath . 'lib/logger.php';
require_once $enginePath . 'lib/utils_game_timestamp.php';
require_once $enginePath . 'lib/rolemaster_helpers.php';
require_once $enginePath . 'lib/scriptproxy_papyrus.php';
require_once $enginePath . 'lib/core/player.class.php';
require_once $enginePath . 'lib/core/npc_master.class.php';
require_once $enginePath . 'lib/core/api_badge.class.php';
require_once $enginePath . 'lib/core/core_profiles.class.php';
require_once $enginePath . 'lib/core/llm_connector.class.php';
require_once $enginePath . 'lib/core/tts_connector.class.php';
require_once $enginePath . 'lib/lazy_xml.php';


require_once 'background_action_handler.php';
require_once 'helpers.php';
// ─── Database ─────────────────────────────────────────────────────────────────

$db = $GLOBALS["db"];



// ─── Argument Parsing ─────────────────────────────────────────────────────────

$npcName = $argv[1];
$argMode = $argv[2] ?? '';   // dryrun | forceletter | forceaction | full
$argMode3 = $argv[3] ?? '';   // optional third arg (forceaction)

// Simple non-blocking process lock to avoid concurrent runs for the same NPC.
$lockKeyRaw = $npcName ?: 'global';
$lockKey = preg_replace('/[^a-zA-Z0-9_-]/', '_', (string) $lockKeyRaw);
$lockPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . "herika_bgl_life_v2_{$lockKey}.lock";
$lockHandle = @fopen($lockPath, 'c');

if ($lockHandle === false) {
    error_log("[BGL RUN] $npcName — unable to create lock file at $lockPath");
    exit(1);
}

if (!flock($lockHandle, LOCK_EX | LOCK_NB)) {
    error_log("[BGL RUN] LOCK! $npcName — another background-life run is already in progress, skipping.");
    exit(0);
}

ftruncate($lockHandle, 0);
fwrite($lockHandle, (string) getmypid());
fflush($lockHandle);

register_shutdown_function(static function () use ($lockHandle): void {
    if (is_resource($lockHandle)) {
        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
    }
});

$isDryRun = ($argMode === 'dryrun');
$isFullMode = ($argMode === 'full');
$forceLetter = ($argMode === 'forceletter');
$forceAction = ($argMode === 'forceaction' || $argMode3 === 'forceaction');

$GLOBALS['HERIKA_NAME'] = $npcName;
if (empty($GLOBALS['PLAYER_NAME'])) {
    $GLOBALS['PLAYER_NAME'] = resolvePlayerName($db);
}

// Variables expected by some library functions
$COMMAND_PROMPT = '';

// ─── NPC & Connector Setup ────────────────────────────────────────────────────

$npcMaster = new NpcMaster();
$connector = new LLMConnector();

$currentNpcData = $npcMaster->getByName($npcName);
$currentConnectorData = $connector->getById($GLOBALS['CORE_CONNECTOR_BGL']);

$profile = new CoreProfile();
$currentProfileData = $profile->getById($currentNpcData['profile_id']);

$connector->setOldGlobals($currentConnectorData);
$npcMaster->setOldGlobalsFromCurrentNpcData($currentNpcData);

$extdata = $npcMaster->getExtendedData($currentNpcData);
$metadata = $npcMaster->getMetadata($currentNpcData);

$connectionHandler = $connector->getConnector($currentConnectorData);

// Guard, if background_life_last_updated_ec exceeds 2, skip processing to avoid infinite loops or repeated errors
// background_life_last_updated_ec is incremented each time an error occurs during processing, and reset to 0 on successful completion.

$backgroundLifeErrorCount = (int) ($extdata['background_life_last_updated_ec'] ?? 0);
if ($backgroundLifeErrorCount > 2) {
    error_log("[BGL RUN] $npcName — background_life_last_updated_ec exceeded 2, skipping.");
    return;
}



// ─── Game Timestamps ──────────────────────────────────────────────────────────

$lastGameTsRow = $db->fetchAll('SELECT max(gamets) AS last_gamets FROM eventlog');
$lastTsRow = $db->fetchAll("SELECT max(ts) AS ts FROM eventlog WHERE gamets='{$lastGameTsRow[0]['last_gamets']}'");

$last_gamets = (int) $lastGameTsRow[0]['last_gamets'] + 1;
$GLOBALS["LAST_GAMETS_BGL"] = $last_gamets;
$last_ts = $lastTsRow[0]['ts'];
$momentum = time();

$gameRequest = ['inputtext', '0', $last_gamets, $npcName];
$npcNameEsc = $db->escape($npcName);


// Guard: Avoid running if game is paused.
if (isset($extdata["background_life_last_run"]) && $extdata["background_life_last_run"] >= $GLOBALS["LAST_GAMETS_BGL"]) {
    error_log("[BGL RUN] $npcName — background_life_last_run equals LAST_GAMETS_BGL <{$GLOBALS["LAST_GAMETS_BGL"]}>, game is paused?.");
    return;
} else {
    error_log("[BGL RUN] $npcName — background_life_last_run: {$extdata["background_life_last_run"]}, LAST_GAMETS_BGL: {$GLOBALS["LAST_GAMETS_BGL"]}");
}

// Last action issued by the NPC (if any) in the last 24 in-game hours

$lastIssuedAction = $db->fetchOne(
    "SELECT gamets, action,fullcall FROM actions_issued
     WHERE actorname='$npcNameEsc' 
     and gamets is not null
     ORDER BY gamets DESC, ts ASC"
);

// Guard: SpreadRumors cooldown check

$spreadRumorsCooldownGamets = 48 / GAMETS_TO_HOURS;
$recentSpreadRumor = $db->fetchOne(
    "SELECT gamets FROM actions_issued
         WHERE actorname='$npcNameEsc'
             AND action='SpreadRumors'
             AND gamets > ($last_gamets - $spreadRumorsCooldownGamets)
         ORDER BY gamets DESC, ts DESC
         LIMIT 1"
);

$spreadRumorsAvailable = empty($recentSpreadRumor);
if (!$spreadRumorsAvailable) {
    error_log("[BGL RUN] $npcName — SpreadRumors is on cooldown for 48 in-game hours.");
}

if ($lastIssuedAction["gamets"] && ($lastIssuedAction["action"] == "TravelTo" || $lastIssuedAction["action"] == "MoveTo")) {
    $npcIsTravelling = true;
    $npcIsTravellingStarted = $lastIssuedAction["gamets"];
} else {
    $npcIsTravelling = false;
    $npcIsTravellingStarted = 0;
}

// Guard: Check lasts LLM requests to avoid exceeding the maximum allowed LLM calls per hour for this NPC
// Check if the last 5 calls were made within the last 2 minutes

if (checkLastCallsFor($GLOBALS['HERIKA_NAME'])) {
    error_log("[BGL RUN] $npcName — LLM call limit exceeded for this NPC, skipping.");
    if (isset($extdata["background_life_last_llm_call_suspended"]) && $extdata["background_life_last_llm_call_suspended"] === true) {
        error_log("[BGL RUN] $npcName — LLM calls are suspended for this NPC, skipping.");
    }
    markAsErrored($GLOBALS['HERIKA_NAME']);
    return;
}

// ─── Guard: Require at Least One Prior Interaction ───────────────────────────
// if background_life_player_unattached is true, we can skip this guard 

$lastInteractionRow = $db->fetchOne(
    "SELECT max(gamets) AS gamets FROM speech
     WHERE speaker='$npcNameEsc' OR listener='$npcNameEsc' OR companions LIKE '%|$npcNameEsc|%'"
);

if (empty($lastInteractionRow['gamets'])) {
    if ($extdata["background_life_player_unattached"]) {
        error_log('[BGL RUN] No prior interaction found but background_life_player_unattached is true');
    } else {
        error_log('[BGL RUN] No prior interaction found, but background_life_player_unattached is false — skipping.');
        $extdata['background_life_last_updated'] = $last_gamets;
        $npcMaster->updateExtendedKeysByName($npcName, $extdata);


        return;
    }
}

// Default behaviour is to get the events... from last interaction with player gamets 
// This can lead to too long context history.

$lastItGamets = (int) $lastInteractionRow['gamets'];

$npcNameEscDb = $db->escape($GLOBALS['HERIKA_NAME']);

// Check if there are more than 10 journal notes since last interaction, and if so, update lastItGamets to the gamets of the last diary entry
$diaryEntryRowsCheck = $db->fetchAll(
    "SELECT content, gamets, topic FROM diarylog
     WHERE people='$npcNameEscDb'
       AND gamets > $lastItGamets
       AND topic IN ('Journal Note')
     ORDER BY gamets DESC, ts DESC
     LIMIT 11 OFFSET 0"
);

if (sizeof($diaryEntryRowsCheck) > 10) {
    // If there are more than 10 journal notes since last interaction
    // lastItGamets will be updated to the gamets of the last diary entry 
    $diaryEntryRowsCheck = array_reverse($diaryEntryRowsCheck);
    $lastItGamets = (int) $diaryEntryRowsCheck[0]['gamets'];
    error_log("[BGL RUN] $npcNameEsc — gamets limit updated to last diary entry gamets: $lastItGamets");
}

// ─── Guard: Skip if Last Interaction Is Within the Configured Cooldown ────────────────

$bglTriggerHours = chimGetBackgroundLifeTriggerHours();
$minDeltaForRerun = $bglTriggerHours / GAMETS_TO_HOURS;

if (($last_gamets - $lastItGamets) < $minDeltaForRerun) {
    Logger::info("[BGL RUN] $npcNameEsc — last interaction was less than {$bglTriggerHours} hours ago.");
    error_log("[BGL RUN] $npcNameEsc — last interaction was less than {$bglTriggerHours} hours ago.");

    $extLocaldata['background_life_last_updated'] = $last_gamets;
    $npcMaster->updateExtendedKeysByName($npcName, $extLocaldata);

    if ($forceLetter) {
        error_log("[BGL RUN] $npcNameEsc — bypassing interaction cooldown via forceletter.");
    } elseif ($forceAction) {
        error_log("[BGL RUN] $npcNameEsc — bypassing interaction cooldown via forceaction.");
    } else {
        return;
    }
}


$daysPassed = round(($last_gamets - $lastItGamets) * GAMETS_TO_HOURS / 24, 2);
$hoursPassed = round(($last_gamets - $lastItGamets) * GAMETS_TO_HOURS, 2);
$history = "";

$GUARD_TRAVELTO = true;

// TravelTo Guard. 
// Sometimes NPCs get stuck inside a building (probably locked doors?)
// We must detect if last 2 actions were TravelTo or MoveTo, and if so, we can assume NPC is stuck and we should solve it

if ($GUARD_TRAVELTO) {

    $actionsRows = $db->fetchAll(
        "SELECT action,actorname,gamets,fullcall FROM actions_issued
     WHERE actorname='$npcNameEscDb' 
       AND gamets > $lastItGamets
     ORDER BY gamets DESC, ts DESC
     LIMIT 10 OFFSET 0"
    );

    // Prepare the values used by the stuck checks.
// We only need the action type and the first argument after the action name.
// Examples:
//   TravelTo:Elysium Estate:...
//   MoveTo:Orianne Marius
//   FindNPC:Orianne Marius
    foreach ($actionsRows as &$row) {
        $parts = explode(':', $row['fullcall'], 3);
        $row['destination'] = $parts[1] ?? '';

        // TravelTo and MoveTo are considered the same family for the travel stuck check.
        $row['action_stuck_check1'] = in_array($row['action'], ['TravelTo', 'MoveTo'], true)
            ? 'TravelTo'
            : '';

        // MoveTo and FindNPC are considered the same family for the NPC-target stuck check.
        $row['action_stuck_check2'] = in_array($row['action'], ['MoveTo', 'FindNPC'], true)
            ? 'FindNPC'
            : '';
    }
    unset($row);

    // We deliberately require 3 consecutive actions now.
// This catches patterns such as:
//   TravelTo -> MoveTo -> TravelTo
//   TravelTo -> TravelTo -> TravelTo
//   MoveTo   -> TravelTo -> MoveTo
// etc.
//
// For the NPC-target check it also requires all 3 actions to point to the
// exact same NPC, so different FindNPC/MoveTo targets do not trigger it.
    if ($actionsRows && sizeof($actionsRows) >= 3) {

        // ---------------------------------------------------------------------
        // TravelTo / MoveTo stuck check
        // ---------------------------------------------------------------------
        $lastThreeTravelActions = array_slice($actionsRows, 0, 3);

        $allTravelActions = count(array_filter(
            $lastThreeTravelActions,
            fn($row) => $row['action_stuck_check1'] === 'TravelTo'
        )) === 3;

        if ($allTravelActions) {
            $sameDestination = (
                $lastThreeTravelActions[0]['destination'] !== '' &&
                $lastThreeTravelActions[0]['destination'] === $lastThreeTravelActions[1]['destination'] &&
                $lastThreeTravelActions[1]['destination'] === $lastThreeTravelActions[2]['destination']
            );

            if ($sameDestination) {
                $destination = $lastThreeTravelActions[0]['destination'];

                error_log("[BGL RUN] $npcNameEsc — last 3 actions were TravelTo/MoveTo to the same destination ({$destination}), assuming NPC is stuck. Teleport it near destination");

                $candidateLocation = resolveTravelLocation($destination, $currentNpcData, $GLOBALS['db']);

                if ($candidateLocation["sim"] > _LOCATION_RESOLVE_SIM_THRESHOLD && $candidateLocation["refs"] != "") {
                    // Extract first ref if any, e.g.
                    // [refs] => 0x0001bdf1:0x2101e6ec;0x0001bdf1:0x2101e6ec
                    $refs = explode(';', $candidateLocation['refs']);
                    $firstReferencePair = explode(":", $refs[0]);

                    $skyrimCmd = new SkyrimCommandBuilder();
                    $json = $skyrimCmd->ObjectReference->MoveTo(
                        "0x{$currentNpcData['refid']}",
                        "{$firstReferencePair[1]}"
                    );
                    $skyrimCmd->send(cmd: $json);

                    error_log("[BGL RUN] $npcNameEsc — Teleported to {$candidateLocation['name']} (formid: {$candidateLocation['formid']})");

                    $db->insert('actions_issued', [
                        'action' => 'TeleportTo',
                        'fullcall' => "TeleportTo:{$candidateLocation['name']}:Teleporting to resolve stuck NPC",
                        'actorname' => $npcName,
                        'ts' => $last_ts,
                        'gamets' => $last_gamets,
                        'localts' => time(),
                        'original' => 'backgroundaction',
                    ]);

                    die();
                } else {
                    error_log("[BGL RUN] $npcNameEsc — Could not resolve a valid location for destination: $destination");
                }
            }

            // If the last 3 actions are TravelTo/MoveTo but their destinations
            // differ, fall back to the coordinate-history check.
            if (isset($extdata['last_coords']) && is_array($extdata['last_coords'])) {
                $coordsHistory = $extdata['last_coords'];
                $recentCoords = array_slice($coordsHistory, -3);
                $uniqueLocations = array_unique(array_column($recentCoords, 3));

                if (count($uniqueLocations) === 1) {
                    error_log("[BGL RUN] $npcNameEsc — last 3 coordinates are the same location ({$uniqueLocations[0]}), assuming NPC is stuck. Teleporting to resolve.");

                    $candidateLocation = resolveTravelLocation($uniqueLocations[0], $currentNpcData, $GLOBALS['db']);

                    if ($candidateLocation["sim"] > _LOCATION_RESOLVE_SIM_THRESHOLD && $candidateLocation["refs"] != "") {
                        $refs = explode(';', $candidateLocation['refs']);
                        $firstReferencePair = explode(":", $refs[0]);

                        $skyrimCmd = new SkyrimCommandBuilder();
                        $json = $skyrimCmd->ObjectReference->MoveTo(
                            "0x{$currentNpcData['refid']}",
                            "{$firstReferencePair[1]}"
                        );
                        $skyrimCmd->send(cmd: $json);

                        error_log("[BGL RUN] $npcNameEsc — Teleported to {$candidateLocation['name']} (formid: {$candidateLocation['formid']})");

                        $db->insert('actions_issued', [
                            'action' => 'TeleportTo',
                            'fullcall' => "TeleportTo:{$candidateLocation['name']}:Teleporting to resolve stuck NPC",
                            'actorname' => $npcName,
                            'ts' => $last_ts,
                            'gamets' => $last_gamets,
                            'localts' => time(),
                            'original' => 'backgroundaction',
                        ]);

                        die();
                    } else {
                        error_log("[BGL RUN] $npcNameEsc — Could not resolve a valid location for destination: {$uniqueLocations[0]}");
                    }
                }
            }
        }

        // ---------------------------------------------------------------------
        // MoveTo / FindNPC stuck check
        // ---------------------------------------------------------------------
        $lastThreeNpcActions = array_slice($actionsRows, 0, 3);

        $allNpcActions = count(array_filter(
            $lastThreeNpcActions,
            fn($row) => $row['action_stuck_check2'] === 'FindNPC'
        )) === 3;

        if ($allNpcActions) {
            // All 3 actions must target the exact same NPC.
            $targetNpcName = $lastThreeNpcActions[0]['destination'];

            $sameTargetNpc = (
                $targetNpcName !== '' &&
                $targetNpcName === $lastThreeNpcActions[1]['destination'] &&
                $targetNpcName === $lastThreeNpcActions[2]['destination']
            );

            if ($sameTargetNpc) {
                $targetNpcData = $npcMaster->getByName($targetNpcName);

                if ($targetNpcData && isset($targetNpcData['refid'])) {
                    $skyrimCmd = new SkyrimCommandBuilder();
                    $json = $skyrimCmd->ObjectReference->MoveTo(
                        "0x{$currentNpcData['refid']}",
                        "0x{$targetNpcData['refid']}"
                    );
                    $skyrimCmd->send(cmd: $json);

                    error_log("[BGL RUN] $npcNameEsc — Last 3 MoveTo/FindNPC actions target the same NPC {$targetNpcName}. Teleported to resolve stuck NPC.");

                    $db->insert('actions_issued', [
                        'action' => 'TeleportTo',
                        'fullcall' => "TeleportTo:{$targetNpcName}:Teleporting to resolve stuck NPC",
                        'actorname' => $npcName,
                        'ts' => $last_ts,
                        'gamets' => $last_gamets,
                        'localts' => time(),
                        'original' => 'backgroundaction',
                    ]);

                    die();
                } else {
                    error_log("[BGL RUN] $npcNameEsc — Could not resolve target NPC {$targetNpcName} for teleportation.");
                }
            }
        }
    }
}

// ─── Dynamic Biography ────────────────────────────────────────────────────────

$dynamicBiography = buildDynamicBiography($GLOBALS, true, true, true);
$dynamicBiography = $npcMaster->appendBackgroundLifeGoals($dynamicBiography, $currentNpcData);

// Remove equipment from bio, as probably will be outdated.
// Just remove everyting between <equipment> and </equipment>
$dynamicBiography = preg_replace('/<equipment>.*?<\/equipment>/s', '', $dynamicBiography);

// ─── NEW: Token Reduction Strategies ──────────────────────────────────────────

// 1. Strip verbose item descriptions and gold values from inventory lists.
// Matches lines like: "- `0x0003133B:Alto Wine` (1) - A tall glass bottle..."
// Reduces them to: "- `0x0003133B:Alto Wine` (1)"
// The regex safely handles item names with hyphens by targeting the " (qty) - " separator.
$dynamicBiography = preg_replace('/^(\s*-\s*.+?\(\d+\))\s+-\s+.+$/m', '$1', $dynamicBiography);

// 2. Remove RPG skills and spells sections. 
// The LLM primarily needs <goals>, <personality>, and <occupation> for background life behavioral choices.
// Dropping these saves ~150-250 tokens per request with zero impact on decision quality.
$dynamicBiography = preg_replace('/<rpg_skills>.*?<\/rpg_skills>/s', '', $dynamicBiography);
$dynamicBiography = preg_replace('/<spells>.*?<\/spells>/s', '', $dynamicBiography);

// ──────────────────────────────────────────────────────────────────────────────

if (isset($extdata['middle_term_memory'])) {
    $middleTermMemory = end($extdata['middle_term_memory']);
    $middleTerm_memoryTs = array_keys($extdata['middle_term_memory']);
    $middleTermMemorygameTs = end($middleTerm_memoryTs);
    $dynamicBiography .= "\n\n<middle_term_memory>\nPast events\n{$middleTermMemory}\n</middle_term_memory>";
}

// ─── Dialogue History ─────────────────────────────────────────────────────────

// background_life_player_unattached is intended to be set to NPCs that are not attached to the player, e.g. 
// NPCs that are not companions or followers.
// if false, we exclude inner thoughts, as they will be appended later.

// ─── Dialogue History ─────────────────────────────────────────────────────────
if ($extdata["background_life_player_unattached"] === true) {
    $sqlFilter = " AND gamets < $lastItGamets"
        . " AND type NOT IN ('prechat','itemfound','npcspellcast','innerchat','infoaction')";
} else {
    $sqlFilter = " AND gamets < $lastItGamets"
        . " AND type NOT IN ('prechat','itemfound','infoaction','npcspellcast','innerchat')"
        . " AND data NOT LIKE '%inner thoughts%'";
}

// We can skip history if the last middle term memory is more recent than the last interaction with the player.
// Threshold: Only include dialogue history if the last middle term memory is more recent than 24 hours from the last interaction with the player.

if ($middleTermMemorygameTs < ($lastItGamets + (24 / GAMETS_TO_HOURS)))  {
    $contextDataHistoric = DataLastDataExpandedFor($GLOBALS['HERIKA_NAME'], -100, $sqlFilter);
    error_log("[BGL RUN] Last middle term memory is older than 24 hours from the last interaction with the player.");
    if ($extdata['background_life_player_unattached']===true) {
        // NPC unattached, so maybe does not know anything about player
        error_log("[BGL RUN] Unattached NPC, so maybe does not know anything about player.");
        foreach ($contextDataHistoric as $entry) {
            $line = trim($entry['content']);

            // NEW: Strip verbose JSON action blobs and replace with a clean [Action: <Name>] format
            // Matches {"...": "...", "action": "Travel_To", ...} and replaces with [Action: Travel_To]
            $line = preg_replace('/\{[^{}]*"action"\s*:\s*"([^"]+)"[^{}]*\}/is', '[Action: $1]', $line);

            $history .= ($entry['role'] === 'assistant')
                ? "{$GLOBALS['HERIKA_NAME']}: $line\n"
                : "$line\n";
        }
    } else {
        error_log("[BGL RUN] NPC is attached, including dialogue history.");
        $history = "\n<last_dialogue>\nThis represents last dialogue where player ({$GLOBALS['PLAYER_NAME']}) was present. Can be more dialogues with other NPCs from this point.\n";
        foreach ($contextDataHistoric as $entry) {
            $line = trim($entry['content']);

            // NEW: Strip verbose JSON action blobs and replace with a clean [Action: <Name>] format
            $line = preg_replace('/\{[^{}]*"action"\s*:\s*"([^"]+)"[^{}]*\}/is', '[Action: $1]', $line);

            $history .= ($entry['role'] === 'assistant')
                ? "{$GLOBALS['HERIKA_NAME']}: $line\n"
                : "$line\n";
        }
        $history .= "\nNote: {$GLOBALS['PLAYER_NAME']} is absent from this point on.\n</last_dialogue>\n";
    }

} else {
    //Append also last memories to the history, as they are more recent than the last interaction with the player.
    error_log("[BGL] Last middle term memory is more recent than 24 hours from the last interaction with the player. Appending last memory to history.");
    $lastMemory=$db->fetchOne("select * from memory_summary
     where gamets_truncated>$middleTermMemorygameTs 
     and companions like '%$npcNameEsc%' 
     and summary is not null
     order by gamets_truncated asc limit 1");
    if ($lastMemory) {
        $history = "\n<last_memory>\nThis represents last memory of {$GLOBALS['HERIKA_NAME']} after the last interaction with player ({$GLOBALS['PLAYER_NAME']}).\n";
        $history .= "Memory: {$lastMemory['summary']}\n";
        $history .= "</last_memory>\n";
    } else {
        $history = "";
    }
    // $history = ""; // This line is redundant and would overwrite the last memory history
}
// ─── Last Known Location ──────────────────────────────────────────────────────

$lastLocRow = $db->fetchOne(
    "SELECT location, gamets FROM speech
     WHERE speaker='$npcNameEsc' OR listener='$npcNameEsc' OR companions LIKE '%|$npcNameEsc|%'
     ORDER BY gamets DESC, ts DESC"
);

// ─── Diary Entries Since Last Iteration ──────────────────────────────────────

$npcNameEscDb = $db->escape($GLOBALS['HERIKA_NAME']);
$diaryEntryRows = $db->fetchAll(
    "SELECT content, gamets, topic FROM diarylog
     WHERE people='$npcNameEscDb'
       AND gamets > $lastItGamets
       AND topic IN ('Sent Letter','Journal Note')
     ORDER BY gamets DESC, ts DESC
     LIMIT 16 OFFSET 0"
);

$diaryEntries = [];
foreach (array_reverse($diaryEntryRows) as $row) {
    $hoursAgo = number_format(($last_gamets - $row['gamets']) * GAMETS_TO_HOURS, 2);
    if ($row['topic'] === 'Sent Letter') {
        $diaryEntries[] = [
            'gamets' => $row['gamets'],
            'content' => "{$row['content']}",
            'type' => ($row['topic'] === 'Sent Letter') ? 'sent_letter' : 'diary_entry',
        ];

        // Update daysPassed to reflect the latest inner chat entry if it's older than the last interaction
        $daysPassed = round(($last_gamets - $row['gamets']) * GAMETS_TO_HOURS / 24, 2);
        $hoursPassed = round(($last_gamets - $row['gamets']) * GAMETS_TO_HOURS, 2);
    }
}

// ─── Remote dialogues  ──────────────────────────────────────

$npcNameEscDb = $db->escape($GLOBALS['HERIKA_NAME']);
$innerChatEntryRows = $db->fetchAll(
    "SELECT data, gamets,ts,people FROM eventlog
     WHERE (people like '%|$npcNameEscDb|%' or people='$npcNameEscDb')
       AND gamets > $lastItGamets
       AND type IN ('innerchat')
     ORDER BY gamets DESC, ts DESC
     LIMIT 100 OFFSET 0"
);

$innerChats = [];
$localCounter = 0;


foreach (array_reverse($innerChatEntryRows) as $row) {

    if (strpos($row['data'], 'can sell these items') !== false) {
        if ($localCounter < sizeof($innerChatEntryRows) - 3) {
            $localCounter++;
            continue; // Skip this entry if it's not one of the last 3 inner chats
            // $row['data'] = "content skipped due to being a trader inner chat";
        }
    }

    $hoursAgo = number_format(($last_gamets - $row['gamets']) * GAMETS_TO_HOURS, 2);
    $innerChats[] = [
        'gamets' => $row['gamets'],
        'content' => "{$row['data']}",
        'type' => 'inner_chat',
    ];
    // Update daysPassed to reflect the earliest inner chat entry if it's older than the last interaction
    $daysPassed = round(($last_gamets - $row['gamets']) * GAMETS_TO_HOURS / 24, 2);
    $hoursPassed = round(($last_gamets - $row['gamets']) * GAMETS_TO_HOURS, 2);
    $localCounter++;
}

// ─── Actions since last Interaction ───────────────────────────────────


$actionsRows = $db->fetchAll(
    "SELECT action,actorname,gamets,fullcall FROM actions_issued
     WHERE actorname='$npcNameEscDb' and action in ('TravelTo','MoveTo')
       AND gamets > $lastItGamets
     ORDER BY gamets DESC, ts DESC
     LIMIT 16 OFFSET 0"
);
$actions = [];
foreach (array_reverse($actionsRows) as $row) {
    $hoursAgo = number_format(($last_gamets - $row['gamets']) * GAMETS_TO_HOURS, 2);
    $actions[] = [
        'gamets' => $row['gamets'],
        'content' => ($row["action"] == "TravelTo" ? "{$row['actorname']} starts journey: {$row['fullcall']}" :
            "{$row['actorname']} moves to: {$row['fullcall']}"),
        'type' => 'travel_action',

    ];
    // Update daysPassed to reflect the earliest action entry if it's older than the last interaction
    $daysPassed = round(($last_gamets - $row['gamets']) * GAMETS_TO_HOURS / 24, 2);
    $hoursPassed = round(($last_gamets - $row['gamets']) * GAMETS_TO_HOURS, 2);
}


// ─── Background Events Since Last Interaction ───────────────────────────────────

$bgEvents = [];
$lastEventParsed = [];   // Tracks the most recent valid background event for location context

$lastLocRow['location'] = $lastLocRow['location'] ?? '';

error_log("Last interaction gamets: $lastItGamets, location: {$lastLocRow['location']}");

$backgroundEventRows = $db->fetchAll(
    "SELECT gamets, data FROM eventlog
     WHERE type='backgroundaction' AND gamets > $lastItGamets
     ORDER BY gamets ASC, ts ASC"
);

foreach ($backgroundEventRows as $event) {
    $eventParsed = json_decode($event['data'], true);

    if (empty($eventParsed['source']) || $eventParsed['source'] !== 'AIAgent.esp') {
        continue;
    }
    if (empty($eventParsed['description']) || $eventParsed['description'] === 'unknown') {
        continue;
    }
    if ($eventParsed['actor'] !== $GLOBALS['HERIKA_NAME']) {
        continue;
    }

    $bgEvents[] = [
        'gamets' => $event['gamets'],
        'content' => $eventParsed['description'],
        'type' => 'event',
    ];
    $lastEventParsed = $eventParsed;   // Keep last matching event for location reference

    // Update daysPassed to reflect the latest background event if it's older than the last interactions
    $daysPassed = round(($last_gamets - $event['gamets']) * GAMETS_TO_HOURS / 24, 2);
    $hoursPassed = round(($last_gamets - $event['gamets']) * GAMETS_TO_HOURS, 2);
}

$lastIssuedBgEvent = $lastEventParsed;
// Append last known speech location
if ($lastLocRow['location']) {
    $bgEvents[] = [
        'gamets' => $lastLocRow['gamets'],
        'content' => $lastLocRow['location'],
        'type' => 'last_known_location',
    ];
}

// Append current and historical coordinate data
$LAST_REPORTED_LOCATION = '';

if (isset($metadata['last_coords']) && !empty($metadata['last_coords'][3])) {
    $coords = $metadata['last_coords'];
    $hoursAgo = number_format(($last_gamets - $coords['last_updated']) * GAMETS_TO_HOURS, 2);
    $bgEvents[] = [
        'gamets' => $coords['last_updated'],
        'content' => "{$coords[3]}",
        'type' => 'reported_location',
    ];
    $LAST_REPORTED_LOCATION = $coords[3];

    $richLocation = $db->fetchOne("SELECT name,region,hold,is_interior  FROM locations WHERE formid='{$coords["location_formid"]}'");
    // error_log("[BGL RUN]  Last reported location: " . json_encode($coords) . " => rich location: " . json_encode($richLocation));
    if ($richLocation && !empty($richLocation['name'])) {
        $LAST_REPORTED_LOCATION = $richLocation['name'];
        if (checkInterior($richLocation['is_interior'])) {
            $LAST_REPORTED_LOCATION .= " (Interior)";
        }
    }
}


if (isset($metadata['low_process_actors'])) {

    // Keep only the last 3 entries.
    $metadataLow_process_actors = array_slice($metadata['low_process_actors'], -1, 1, true);
    //$metadataLow_process_actors = ($metadata['low_process_actors']);
    foreach ($metadataLow_process_actors as $gamets_lpa_processed => $actorList) {
        if ($gamets_lpa_processed <= $lastItGamets) {
            continue;
        }
        $hoursAgo = number_format(($last_gamets - $gamets_lpa_processed) * GAMETS_TO_HOURS, 2);
        // actorList in the form of name
        if ($actorList === []) {
            $actorList = ["No visible characters nearby"];
        }

        $actorListExpanded = [];
        foreach ($actorList as $key => $actor) {
            if (is_array($actor)) {

                $npcMaster = new NpcMaster();
                $actorRow = $npcMaster->getByName($actor[1]);
                if ($actorRow && isset($actorRow['oghma_knowledge_tags']) && !empty($actorRow['oghma_knowledge_tags'])) {
                    $actorListExpanded[] = "$key;$actor;{$actorRow['oghma_knowledge_tags']}";
                } else if ($actorRow && isset($actorRow['race']) && !empty($actorRow['race'])) {
                    $actorListExpanded[] = "$key;$actor;{$actorRow['race']} {$actorRow['gender']}";
                } else {
                    $actorListExpanded[] = "$key;$actor;;";
                }
            } else {

                $npcMaster = new NpcMaster();
                $actorRow = $npcMaster->getByName($actor);
                if ($actorRow && isset($actorRow['oghma_knowledge_tags']) && !empty($actorRow['oghma_knowledge_tags'])) {
                    $actorListExpanded[] = "$key;$actor;{$actorRow['oghma_knowledge_tags']}";
                } else if ($actorRow && isset($actorRow['race']) && !empty($actorRow['race'])) {
                    $actorListExpanded[] = "$key;$actor;{$actorRow['race']} {$actorRow['gender']}";
                } else {
                    $actorListExpanded[] = "$key;$actor;;";
                }
            }
        }

        $bgEvents[] = [
            'gamets' => $gamets_lpa_processed,
            'content' => "Nearby actors/npc {$GLOBALS['HERIKA_NAME']} can see (refid;name;tags): \n" . implode("\n", $actorListExpanded) . "\n",
            'type' => 'nearby_npcs',
        ];
    }
}


if (isset($metadata['last_inventory_update_gamets'])) {
    $nullArray = [];
    $bgEvents[] = [
        'gamets' => $metadata['last_inventory_update_gamets'],
        'content' => implode("\n", chimFormatInventoryPromptLines($metadata['inventory'] ?? [], null, $nullArray, false, true)),
        'type' => 'inventory_update',
    ];
}

// ─── Rumors Near Current Location ────────────────────────────────────────────

if ($LAST_REPORTED_LOCATION) {
    $locationEsc = $db->escape(str_replace(" (Interior)", "", $LAST_REPORTED_LOCATION));
    $rumorSinceTs = $last_gamets - ((24 * 7) / GAMETS_TO_HOURS);   // Last 7 in-game days
    $rumorRows = $db->fetchAll(
        "SELECT gamets, content FROM rumors
         WHERE (
            hold LIKE '%{$locationEsc}%' 
            or hold IN (SELECT distinct(hold) FROM locations where name='$locationEsc')
            or hold IN (SELECT distinct(region) FROM locations where name in (SELECT distinct(hold) FROM locations where name='$locationEsc'))
            )
         AND gamets > $rumorSinceTs order by gamets desc, ts desc LIMIT 2 OFFSET 0"
    );
    error_log("[BGL RUN] LAST_REPORTED_LOCATION " . count($rumorRows) . " rumors near <$LAST_REPORTED_LOCATION> since gamets $rumorSinceTs");
    foreach ($rumorRows as $rumor) {
        $bgEvents[] = [
            'gamets' => $rumor['gamets'],
            'content' => $rumor['content'],
            'type' => 'rumor',
        ];
    }
}

// ─── Merge & Sort Events; Append to History ───────────────────────────────────

$combinedEvents = array_merge($bgEvents, $diaryEntries, $innerChats, $actions);
usort($combinedEvents, fn($a, $b) => $a['gamets'] <=> $b['gamets']);

// To avoid very long contexts, lets consider only last 100 records from combinedEvents
// if sizeof($diaryEntryRowsCheck)>10, we can consider context has grown big enough.
if (sizeof($diaryEntryRowsCheck) > 10) {
    $combinedEvents = array_slice($combinedEvents, HISTORY_LIMIT * -1, HISTORY_LIMIT, true);
    error_log("[BGL RUN] Slicing context history");
}



if (empty($combinedEvents)) {
    $history .= "Note: After these events, $daysPassed days have passed.";
}

$previousGamets = 0;
foreach ($combinedEvents as $entry) {
    $content = $entry['content'];
    if ($entry['type'] === 'event' && $previousGamets) {
        $hoursSincePrev = round(($entry['gamets'] - $previousGamets) * GAMETS_TO_HOURS, 2);
        $hoursAgo = round(($last_gamets - $entry['gamets']) * GAMETS_TO_HOURS, 2);
        $content = "* {$hoursSincePrev}h after last entry: {$content}, {$hoursAgo}h ago";
    }
    $previousGamets = $entry['gamets'];
    $history .= "\n<{$entry['type']} date=\"" . convert_gamets2skyrim_date($entry['gamets']) . "\">\n{$content}\n</{$entry['type']}>\n";
}

echo str_repeat('=', 63) . PHP_EOL;

$closestLocations = getLocationsNearNpcCoords($GLOBALS['HERIKA_NAME']);
if (is_array($closestLocations) && count($closestLocations) > 0) {
    $history .= "Hint: Closest locations to {$GLOBALS['HERIKA_NAME']} ordered by distance. (Use TravelTo to move to one of this locations if needed):\n";
    foreach ($closestLocations as $loc) {
        $history .= "\n$loc";
    }
    $history .= "\n";
}

$postHistory = "\nCurrent location: $LAST_REPORTED_LOCATION\n";
$postHistory .= "\nCurrent date and hour: " . convert_gamets2skyrim_long_date($last_gamets) . "\n";

// ─── Check last Idles  ───────────────────────────────────

$lastMinuteNotes = "\n";
$fortyEightHoursAgo = $last_gamets - 48 / GAMETS_TO_HOURS;
$actionIdleRows = $db->fetchAll(
    "SELECT action,actorname,gamets,fullcall FROM actions_issued
     WHERE actorname='$npcNameEscDb' and action in ('Idle')
       AND gamets > $fortyEightHoursAgo
     ORDER BY gamets DESC, ts DESC
     LIMIT 10 OFFSET 0"
);
if (sizeof($actionIdleRows) > 2) {

    $summaryIdleActions = [];
    $summaryIdleActions['Sleep'] = 0;
    $summaryIdleActions['Work'] = 0;
    $summaryIdleActions['Relax'] = 0;
    $summaryIdleActions['Socialize'] = 0;
    $summaryIdleActions['Guard'] = 0;

    foreach ($actionIdleRows as $row) {
        $data = explode(":", $row['fullcall']);

        $actionType = $data[2] ?? '';
        if (isset($summaryIdleActions[$actionType])) {
            $summaryIdleActions[$actionType]++;
        }
    }

    if ($summaryIdleActions['Sleep'] == 0) {
        $lastMinuteNotes .= "\nNote: {$GLOBALS['HERIKA_NAME']} hasn't been sleeping for the last 48h. This may affect health and well-being.\n";
    }
    if ($summaryIdleActions['Work'] >= 3) {
        $lastMinuteNotes .= "\nNote: {$GLOBALS['HERIKA_NAME']} has been working too much for the last 48h. This may affect health and well-being.\n";
    }
    if ($summaryIdleActions['Guard'] == 0) {
        $lastMinuteNotes .= "\nNote: {$GLOBALS['HERIKA_NAME']} hasn't been guarding for the last 48h. This may affect security well-being.\n";
    }
    if ($summaryIdleActions['Socialize'] == 0) {
        $lastMinuteNotes .= "\nNote: {$GLOBALS['HERIKA_NAME']} hasn't been properly socializing for the last 48h. This may affect health and well-being. Should make an effort to interact with others at a inn or tavern by staying with intent 'Socialize'.\n";
    }
    if ($summaryIdleActions['Relax'] == 0 && ($summaryIdleActions['Sleep'] == 0)) {
        $lastMinuteNotes .= "\nNote: {$GLOBALS['HERIKA_NAME']} hasn't been relaxing for the last 48h. This may affect health and well-being.\n";
    }
    error_log("[BGL RUN] $npcNameEscDb — summary of last 48h idle actions: " . json_encode($summaryIdleActions));
}

if ($lastIssuedAction['action'] === 'StayAtPlace') {
    $data = explode(":", $lastIssuedAction['fullcall']);
    if (isset($data[2]) && $data[2] == "Sleep") {
        error_log("[BGL RUN] $npcNameEscDb — last issued action was StayAtPlace:Sleep, indicating the NPC is currently sleeping and waking up.");
        $lastMinuteNotes .= "\nNote: {$GLOBALS['HERIKA_NAME']} wakes up, has been sleeping since " . convert_gamets2skyrim_long_date($lastIssuedAction['gamets']) . ".\n";
    }
}


// Check excessive use of MoveTo.

$lastMinuteNotes .= "\n";
$fortyEightHoursAgo = $last_gamets - 48 / GAMETS_TO_HOURS;
$actionMoveTo = $db->fetchAll(
    "SELECT action,actorname,gamets,fullcall FROM actions_issued
     WHERE actorname='$npcNameEscDb' and action in ('MoveTo')
       AND gamets > $fortyEightHoursAgo
     ORDER BY gamets DESC, ts DESC
     LIMIT 10 OFFSET 0"
);
if (sizeof($actionMoveTo) > 5) {
    error_log("[BGL RUN] $npcNameEscDb — EXCESSIVE use of MoveTo actions in the last 48h: " . json_encode($actionMoveTo));
}

// ─── Language Detection ───────────────────────────────────────────────────────

$npcMetadata = json_decode($currentNpcData['metadata'], true) ?? [];
$profileMetadata = json_decode($currentProfileData['metadata'], true) ?? [];


// ─── NPC Production Detection ───────────────────────────────────────────────────────

// Lets check if last action was Idle. This means NPC is staying at a place doing something
// We must as first what was doing. We need to know:
// 1) If NPC was on a relaxing scenario (inn..home..), ask if we consumed any item in inventory (food, drink, potion, etc)
// 2) If NPC was on a working (scenario), ask if we produced any good. (iron ore, leather, etc). Subsection production at <goals> specifies what is produced and how much per hour. We must check if we have produced any good.

$npcNameEscBg = $db->escape($GLOBALS['HERIKA_NAME']);
$lastBackgroundAction = $db->fetchOne(
    "SELECT action, fullcall, gamets
     FROM actions_issued
     WHERE actorname='$npcNameEscBg' AND original='backgroundaction'
     AND gamets is not null
     ORDER BY gamets DESC, localts DESC
     LIMIT 1"
);

$isIdleAction = !empty($lastBackgroundAction)
    && (
        strcasecmp((string) ($lastBackgroundAction['action'] ?? ''), 'Idle') === 0
        || stripos((string) ($lastBackgroundAction['fullcall'] ?? ''), 'StayAtPlace:') === 0
    );

$idleGamets = (int) ($lastBackgroundAction['gamets'] ?? 0);
$idleHours = max(0, round(($last_gamets - $idleGamets) * GAMETS_TO_HOURS, 2));

// Short version of history for LLM prompt. We only need the last 100 lines to determine if we consumed or produced something.
$historyShort = implode("\n", array_slice(explode("\n", $history), -100));


// Prerequest to gues production/consumption during idle period. 
// We will ask LLM to determine if we consumed or produced something during the idle period.

if ($isIdleAction && $idleHours > 1) { // If last Idle was Socialize, there a chance of a follow up.
    $intent = explode(':', (string) ($lastBackgroundAction['fullcall'] ?? ''));
    $lastIntent = $intent[2] ?? '';
    $lastIntentBasedHint = "";

    if ($lastIntent === 'Work') {
        $lastIntentBasedHint = "Hint: Last intent was 'Work', so check if any goods were produced during the idle period based on production rules.";
    }

    if ($lastIntent === 'Relax') {
        $lastIntentBasedHint = "Hint: Last intent was 'Relax', so probably ate/drank items in inventory.";
    }

    if ($lastIntent === 'Socialize') {
        $lastIntentBasedHint = "Hint: Last intent was 'Socialize', so probably drank items in inventory.";
    }

    if ($lastIntent === 'Guard') {
        $lastIntentBasedHint = "Hint: Last intent was 'Guard', so probably stayed alert and did not consume items in inventory.";
    }

    if ($lastIntent === 'Sleep') {
        $bypassProduction = true;
    } else {
        $bypassProduction = false;
    }

    if (!$bypassProduction) {
        /*
        function requestForComsuption($dynamicBiography, $historyShort, $postHistory, $idleHours, $lastIntentBasedHint,
 $currentNpcData, $extdata, $db, $last_ts, $last_gamets, $momentum, $npcNameEsc, $npcMaster, $connector, $currentConnectorData,
 $npcName,$startTime):string
 */
        $consumtionResponse=requestForComsuption($dynamicBiography, $historyShort, $postHistory, $idleHours, 
        $lastIntentBasedHint, $currentNpcData, $extdata, $db, $last_ts, $last_gamets, $momentum, 
        $npcNameEsc, $npcMaster, $connector, $currentConnectorData, $npcName, $startTime);
        if ($consumtionResponse) {
            $history .= "\nThe Narrator: $npcName produced/consumed items while idle: $consumtionResponse";
        
        } else {
            error_log("[BGL RUN] No production/consumption detected during idle period.");
        }
    }
}


// ─── Last iteration was speak ───────────────────────────────────────────────────────


$npcNameEscBg = $db->escape($GLOBALS['HERIKA_NAME']);
$lastBackgroundAction = $db->fetchOne(
    "SELECT action, fullcall, gamets
     FROM actions_issued
     WHERE actorname='$npcNameEscBg' AND original='backgroundaction'
     and gamets is not null
     ORDER BY gamets DESC, localts DESC
     LIMIT 1"
);

$isSpeakAction = !empty($lastBackgroundAction)
    && (
        strcasecmp((string) ($lastBackgroundAction['action'] ?? ''), 'SpeakTo') === 0
        || stripos((string) ($lastBackgroundAction['fullcall'] ?? ''), 'SpeakTo:') === 0
    );


// Language detection (for translated prompts in the future (TO-DO))
$lang = (($npcMetadata['CORE_LANG'] ?? '') === 'es' || ($profileMetadata['CORE_LANG'] ?? '') === 'es')
    ? 'es'
    : 'en';


// Hinter
error_log(date("YMd H:i:s") . " [BGL RUN] HINT $npcNameEsc — last action: {$lastBackgroundAction['action']}, last event: <{$lastIssuedBgEvent['name']}> <{$lastIssuedBgEvent['event']}>, npcIsTravelling: " . ($npcIsTravelling ? 'true' : 'false'));
if (
    strtolower($lastIssuedBgEvent["name"]) == "sandbox" && $lastIssuedBgEvent["event"] == "start" && $npcIsTravelling
    || strtolower($lastIssuedBgEvent["name"]) == "travelto" && $lastIssuedBgEvent["event"] == "end" && $npcIsTravelling
) {
    // Last action was MoveTo or TravelTo.
    // Last event was a Sandbox event. This means the NPC reached destination
    // 
    error_log(date("YMd H:i:s") . " [BGL RUN] HINT bypassInnerThoughts: true");
    $bypassInnerThoughts = true;
} else {
    $bypassInnerThoughts = false;
}

// Guard: Avoid too much transactions.

$byspassTradingActions = false;
if ($lastBackgroundAction['action'] === 'BuyItem' || $lastBackgroundAction['action'] === 'SellItem' || $lastBackgroundAction['action'] === 'SellService') {
    // Last action was BuyItem, SellItem or SellService.
    // Avoid repeated trading actions in the same turn, as it can lead to infinite loops of buying/selling items/services.
    // 
    error_log(date("YMd H:i:s") . " [BGL RUN] HINT bypass Trading actions: true");
    $bypassTradingActions = true;
} else {
    $bypassTradingActions = false;
}

$localHoursPassed = round($last_gamets - (1 / GAMETS_TO_HOURS), 2);

$tradingGuard = $db->fetchAll("select * from actions_issued where actorname='$npcNameEscDb' and action in ('BuyItem','SellItem','SellService') and gamets>$localHoursPassed order by gamets desc limit 5");
if (sizeof($tradingGuard) > 3) {
    error_log(date("YMd H:i:s") . " [BGL RUN] HINT bypass Trading actions: because transactions>=3 in the last hour");
    $bypassTradingActions = true;
}


// Modifier: Socialize chain
$wasSocializeIntentAction = false;
if (
    !empty($lastBackgroundAction)
    && (
        strcasecmp((string) ($lastBackgroundAction['action'] ?? ''), 'Idle') === 0
        || stripos((string) ($lastBackgroundAction['fullcall'] ?? ''), 'StayAtPlace:') === 0
    )
) {
    // If NPC selected StayAtPlace and intent Socialize, there is a chance that next turn happens inmediatly after (50%)
    // If this is the case, we can skip inner thoughts and go directly to action decision suggestion SpeakTo.
    // If this is the case, $hoursAgo should have a low value.

    $action_parts = explode(":", $lastBackgroundAction['fullcall'] ?? '');
    if ($action_parts[0] === 'StayAtPlace' && isset($action_parts[2]) && strtolower($action_parts[2]) === 'socialize') {
        if ($hoursPassed < 1) {
            $wasSocializeIntentAction = true;
            $bypassInnerThoughts = true;
            //$innerThoughtBufferForced = "{$GLOBALS['HERIKA_NAME']}'s inner thought: I'm socializing, let's see who is around and speak to them.";
            $innerThoughtBufferForced = "{$GLOBALS['HERIKA_NAME']}'s inner thought: I'm here to socialize. If there's already a conversation I'm part of, I'll stay engaged with it. Otherwise, I'll look around for someone to meet and start a conversation.";
            error_log(date("YMd H:i:s") . " [BGL RUN] HINT bypassInnerThoughts: true (socialize intent)");
        } else {
            // Was a socialize intent action, but more than 1 hour has passed since last action. We will generate inner thoughts.
            $wasSocializeIntentAction = true;
        }
    }
}

// IF last action was less than half an hour ago, skip inner thoughts and go directly to action decision suggestion.
$action_parts = explode(":", $lastBackgroundAction['fullcall'] ?? '');
$localHoursPassed = round(($last_gamets - $lastBackgroundAction['gamets']) * GAMETS_TO_HOURS, 2);
if ($localHoursPassed < 0.5 && !$wasSocializeIntentAction) {

    $bypassInnerThoughts = true;
    $innerThoughtBufferForced = "{$GLOBALS['HERIKA_NAME']}'s inner thought: Let’s see where this takes us";
    error_log(date("YMd H:i:s") . " [BGL RUN] HINT bypassInnerThoughts: true (last actions was half an hour ago or less)");
}


// Inception

if (isset($extdata['bgl_inception']) && !empty($extdata['bgl_inception'])) {
    $lastMinuteNotes .= "\nImportant:A thought crosses {$GLOBALS['HERIKA_NAME']}'s mind: He should {$extdata['bgl_inception']}\n";
    $npcMaster = new NpcMaster();
    $npcData = $npcMaster->getByName($GLOBALS['HERIKA_NAME']);
    $npcMaster->updateExtendedKeysByName($GLOBALS['HERIKA_NAME'], ['bgl_inception' => ""]);
    error_log("[BGL RUN] HINT inception: {$extdata['bgl_inception']}");
}


// Check gold

$currentNpcData = $npcMaster->getByName($npcName); // Refresh NPC data to ensure we have the latest metadata

$npcMetadata = json_decode($currentNpcData['metadata'], true) ?? [];
$profileMetadata = json_decode($currentProfileData['metadata'], true) ?? [];

$inventory = $npcMetadata["inventory"] ?? [];
foreach ($inventory as $item) {
    if (isset($item['baseid']) && strcasecmp($item['baseid'], '0000000F') === 0) {
        $goldFound = true;
        $goldAmount = (int) ($item['count'] ?? 0);
        if ($goldAmount < 50) {
            $lastMinuteNotes .= "\nNote: {$GLOBALS['HERIKA_NAME']} has low gold budget ($goldAmount). Consider StayAtPlace, work intent, to ensure financial stability.\n";
            error_log("[BGL RUN] $npcNameEscDb — low gold budget: $goldAmount");

            break;
        }
    }
}

$lastMinuteNotesSpeakContext = "";

if (!isset($goldFound)) {
    $lastMinuteNotes .= "\nNote: {$GLOBALS['HERIKA_NAME']} has no gold coins. Should work to get some coins. Check background_life_goals->production to know how to earn gold.\n";
    $lastMinuteNotesSpeakContext .= "\nNote: {$GLOBALS['HERIKA_NAME']} has no gold coins, so cannot buy anything.\n";
    error_log("[BGL RUN] $npcNameEscDb — has no gold!");
} else
    $lastMinuteNotesSpeakContext = "";

// ─── Step 1: Inner-Thought Soliloquy ─────────────────────────────────────────
if ($wasSocializeIntentAction && !$bypassInnerThoughts) {
    // If last action was a socialize intent, we will generate inner thoughts, 
    // but we will add a note to the inner thoughts that the NPC is socializing.
    $innerThoughtEnforceSocialice = "* Also, as last action was a socialize intent, simulate that {$GLOBALS['HERIKA_NAME']} has been talking with other characters (E.G. I've talked to X about <topic>, and Y about <topic>)";
    error_log(date("YMd H:i:s") . " [BGL RUN] HINT inner thoughts: enforced socialize/conversation prompt");
} else
    $innerThoughtEnforceSocialice = "";

$innerThoughtBuffer=requestForInnerThought(
    $npcName,
    $currentNpcData,
    $extdata,
    $hoursPassed,
    $innerThoughtEnforceSocialice,
    $dynamicBiography,
    $history,
    $postHistory,
    $lastMinuteNotes,
    $lang,
    $bypassInnerThoughts,
    $innerThoughtBufferForced,
    $isSpeakAction,
    $startTime,
    $connector,
    $currentConnectorData,
    $recordInnerThoughts,
    $recordDiaryEntry
);

Logger::debug(__LINE__ . ' ' . (microtime(true) - $startTime));
echo $innerThoughtBuffer . PHP_EOL;

// ─── Dry-Run Guard ────────────────────────────────────────────────────────────

if ($isDryRun && !$forceAction) {
    die();
}

// ─── Step 2: Action Decision ─────────────────────────────────────────────────

$lettersEnabled = isset($extdata['background_life_letters']) && $extdata['background_life_letters'] === true;

$innerThoughtStyle = loadBGLStylePrompt('background_life_innerthought');

$decisionBuffer=requestForaction(
    $extdata,
    $dynamicBiography,
    $postHistory,
    $lastMinuteNotes,
    $historyShort,
    $innerThoughtBuffer,
    $innerThoughtStyle,
    $isFullMode, 
    $connector,
    $currentConnectorData,
    $spreadRumorsAvailable,
    $isSpeakAction,
    $bypassTradingActions,
    $npcIsTravelling,
    $lastIssuedBgEvent,
    $npcNameEsc,$GLOBALS["db"],$fortyEightHoursAgo,$last_gamets
);

echo $decisionBuffer . PHP_EOL;

// Refresh NPC data to ensure we have the latest information before executing any actions
// This is important because the NPC's state may have changed during the decision-making process

$currentNpcData = $npcMaster->getByName($npcName);
$extdata = $npcMaster->getExtendedData($currentNpcData);
$metadata = $npcMaster->getMetadata($currentNpcData);

// ─── Update Background-Life Timestamp ────────────────────────────────────────

$extdata['background_life_last_updated'] = $last_gamets;
$npcMaster->updateExtendedKeysByName($npcName, $extdata);


// ─── Parse LLM Decision Response ─────────────────────────────────────────────

$parsed = [
    'action' => manual_get_tag_content($decisionBuffer, 'action'),
    'notification' => '',
    'rumor' => '',
    'reason' => manual_get_tag_content($decisionBuffer, 'reason')
];

print_r($parsed);

if (!is_array($parsed)) {
    die();
}

if ($isDryRun && $forceAction) {   // In dry-run mode (forceAction was enabled), we stop after parsing the decision without executing actions
    die();
}

$refHexString = convertSignedToUnsignedHex(hexdec($currentNpcData['refid']));

// ─── Dispatch: Movement / Stay Action ────────────────────────────────────────
$recordDiaryEntry = true;
if (!empty($parsed['action'])) {
    [$actionCmd, $actionArg] = array_pad(explode(':', $parsed['action'], 2), 2, null);
    error_log("[BGL RUN] Chosen action: $actionCmd, argument: $actionArg, reason: {$parsed['reason']}");
    $GLOBALS["LAST_REASON"] = $parsed['reason'];
    switch ($actionCmd) {
        case 'TravelTo':
            handleTravelToAction($actionArg, $currentNpcData, $GLOBALS['HERIKA_NAME'], $last_ts, $last_gamets, $momentum, $lastEventParsed, $db);
            unset($parsed['rumor']);   // Prevent rumor dispatch if MoveTo action is chosen
            break;
        case 'StayAtPlace':
            [$stayLocation, $stayIntent] = array_pad(explode(':', (string) $actionArg, 2), 2, '');
            $stayLocation = trim($stayLocation);
            $stayIntent = trim($stayIntent);
            handleStayAtPlaceAction($stayLocation, $currentNpcData, $GLOBALS['HERIKA_NAME'], $last_ts, $last_gamets, $momentum, $db, $stayIntent);
            break;
        case 'ReturnHome':
            handleReturnHome($actionArg, $currentNpcData, $GLOBALS['HERIKA_NAME'], $last_ts, $last_gamets, $momentum, $db);
            unset($parsed['notification']);   // Prevent letter dispatch if ReturnHome action is chosen
            break;
        case 'FindNPC':
            handleFindNPCAction($actionArg, $currentNpcData, $GLOBALS['HERIKA_NAME'], $last_ts, $last_gamets, $momentum, $db, $LAST_REPORTED_LOCATION);
            unset($parsed['notification']);   // Prevent letter dispatch if FindNPC action is chosen
            unset($parsed['rumor']);   // Prevent rumor dispatch if FindNPC action is chosen

            break;
        case 'MoveTo':
            handleMoveToAction($actionArg, $currentNpcData, $GLOBALS['HERIKA_NAME'], $last_ts, $last_gamets, $momentum, $db);
            unset($parsed['notification']);   // Prevent letter dispatch if MoveTo action is chosen
            unset($parsed['rumor']);   // Prevent rumor dispatch if MoveTo action is chosen

            break;
        case 'SpeakTo':
            $historyWithInnerThought = $history
                . "\n{$postHistory}\n$lastMinuteNotesSpeakContext\n<inner_thought>\n{$innerThoughtBuffer}\n</inner_thought>\n";
            handleSpeakToAction($actionArg, $currentNpcData, $GLOBALS['HERIKA_NAME'], $last_ts, $last_gamets, $momentum, $db, $connectionHandler, $dynamicBiography, $historyWithInnerThought, $lastEventParsed['location']);
            unset($parsed['notification']);   // Prevent letter dispatch if SpeakTo action is chosen
            //unset($parsed['rumor']);   // Prevent rumor dispatch if SpeakTo action is chosen

            break;
        case 'SpreadRumors':
            $historyWithInnerThought = $history
                . "\n{$postHistory}\n$lastMinuteNotesSpeakContext\n<inner_thought>\n{$innerThoughtBuffer}\n</inner_thought>\n";
            handleSpreadRumorsAction($actionArg, $currentNpcData, $GLOBALS['HERIKA_NAME'], $last_ts, $last_gamets, $momentum, $db, $connectionHandler, $dynamicBiography, $historyWithInnerThought, $LAST_REPORTED_LOCATION);
            unset($parsed['notification']);
            unset($parsed['rumor']);
            break;
        case 'BuyItem':
        case 'SellItem':
        case 'GiveItemTo':
            // Support semicolon-separated multi-item trades, e.g.:
            // BuyItem:NPC:itemid1:count1:gold1;BuyItem:NPC:itemid2:count2:gold2
            $tradeEntries = explode(';', $parsed['action']);
            foreach ($tradeEntries as $tradeEntry) {
                $tradeEntry = trim($tradeEntry);
                if ($tradeEntry === '')
                    continue;
                [$tradeCmd, $tradeArg] = array_pad(explode(':', $tradeEntry, 2), 2, null);
                $tradeCmd = trim($tradeCmd);
                if ($tradeCmd !== 'BuyItem' && $tradeCmd !== 'SellItem' && $tradeCmd !== 'GiveItemTo')
                    continue;
                handleTradeItemsAction($tradeCmd, $tradeArg, $currentNpcData, $GLOBALS['HERIKA_NAME'], $last_ts, $last_gamets, $momentum, $db);
            }
            unset($parsed['notification']);
            unset($parsed['rumor']);

            break;
        case 'GiveGoldTo':
            handleGiveGoldToAction($actionArg, $currentNpcData, $GLOBALS['HERIKA_NAME'], $last_ts, $last_gamets, $momentum, $db);
            unset($parsed['notification']);
            unset($parsed['rumor']);

            break;
        case 'SellService':
            handleSellServiceAction($actionArg, $currentNpcData, $GLOBALS['HERIKA_NAME'], $last_ts, $last_gamets, $momentum, $db);
            unset($parsed['notification']);
            unset($parsed['rumor']);

            break;
        case 'Continue':
            error_log("[BGL RUN] Chosen action: Continue. No new action will be issued. Reason: {$parsed['reason']}");
            unset($parsed['notification']);
            unset($parsed['rumor']);

            break;
        case 'SendLetter':
            $historyWithInnerThought = $history
                . "\n{$postHistory}\n<inner_thought>\n{$innerThoughtBuffer}\n</inner_thought>\n";
            handleSendLetter($parsed['reason'], $currentNpcData, $GLOBALS['HERIKA_NAME'], $last_ts, $last_gamets, $momentum, $db, $connectionHandler, $dynamicBiography, $historyWithInnerThought, $lastEventParsed['location']);
            error_log("[BGL RUN] Chosen action: SendLetter. No new action will be issued. Reason: {$parsed['reason']}");
            unset($parsed['notification']);
            unset($parsed['rumor']);

            break;
        default:
            error_log("[BGL RUN] ERROR! Chosen action: $actionCmd. No handler implemented for this action. Reason: {$parsed['reason']}");
            unset($parsed['notification']);
            unset($parsed['rumor']);
            $recordDiaryEntry = false;
            triggerNpcUpdate($GLOBALS['HERIKA_NAME'], ($extdata['background_life_last_updated_ec'] ?? 0) + 1);
            break;
    }
    updateLastActionGameTs($GLOBALS['HERIKA_NAME']);
}

// ─── Dispatch: Letter / Notification (disabled) ─────────────────────────────

/*
if (!empty($parsed['notification']) && $lettersEnabled) {
    // Disabled in v2 flow: Step 2 now decides action only.
}
*/

// ─── Dispatch: Rumor (disabled) ──────────────────────────────────────────────

/*
if (!empty($parsed['rumor'])) {
    // Disabled in v2 flow: Step 2 now decides action only.
}
*/

// ─── Persist Inner Thought to Event & Diary Logs ──────────────────────────────

if ($innerThoughtBuffer && $recordInnerThoughts) {
    $db->insert('eventlog', [
        'ts' => $last_ts,
        'gamets' => $last_gamets,
        'type' => 'innerchat',
        'data' => "{$GLOBALS['HERIKA_NAME']}'s inner thoughts: " . $innerThoughtBuffer,
        'sess' => $momentum,
        'localts' => time(),
        'people' => $GLOBALS['HERIKA_NAME'],
        'location' => $lastEventParsed['location'] ?? null,
        'party' => '',
    ]);
    $cnName = $db->escape($GLOBALS['HERIKA_NAME']);
    $checkLatestDiaryEntry = $db->fetchOne("SELECT * FROM diarylog WHERE topic='Journal Note' AND people='$cnName' ORDER BY gamets DESC, ts DESC LIMIT 1");
    $latestDiaryGamets = (float) $checkLatestDiaryEntry['gamets'];
    if ($last_gamets - $latestDiaryGamets < (1 / GAMETS_TO_HOURS) * 4) {
        // If the last diary entry was less than 4 hours ago, we skip adding a new diary entry to avoid cluttering the diary with too many entries in a short time.
        $recordDiaryEntry = false;
    }

    if ($recordDiaryEntry) {
        $db->insert('diarylog', [
            'ts' => $last_ts,
            'gamets' => $last_gamets,
            'topic' => 'Journal Note',
            'content' => convert_gamets2skyrim_long_date($last_gamets) . "\n" . trim($innerThoughtBuffer),
            'tags' => 'Auto-diary, backgroundlife',
            'people' => $GLOBALS['HERIKA_NAME'],
            'location' => $lastEventParsed['location'] ?? null,
            'sess' => $momentum,
            'localts' => time(),
        ]);
    }

    logMemory($GLOBALS['HERIKA_NAME'], $GLOBALS['HERIKA_NAME'], trim($innerThoughtBuffer), $momentum, $last_gamets, 'backgroundlife_diary', $last_ts);
}
// ─── Mark NPC as Background-Life Enabled ─────────────────────────────────────

$currentNpcData = $npcMaster->getByName($npcName);
$extdata = $npcMaster->getExtendedData($currentNpcData);
if (!$extdata['background_life_enabled']) {
    $extdata['background_life_enabled'] = true;
    $currentNpcData = $npcMaster->setExtendedData($currentNpcData, $extdata);
    $npcMaster->updateByArray($currentNpcData);
}

if (is_resource($lockHandle)) {
    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);
}
die();
