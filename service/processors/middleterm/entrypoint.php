<?php

$GLOBALS["TASKS"]["middleterm"] = [];
$GLOBALS["TASKS"]["middleterm"]["fn"] = function () {

    $enginePath = $GLOBALS["ENGINE_ROOT"];
    $GLOBALS["ENGINE_PATH"] = $enginePath;


    if (!isset($GLOBALS["db"])) {
        $GLOBALS["db"] = new sql();
    }

    require_once($enginePath . "lib/game_activity.php");
    if (!chimHasRecentGameActivity()) {
        Logger::debug("[MIDDLETERM] Skipping scheduled LLM work because no recent game activity was detected");
        return;
    }

    require_once($enginePath . "prompts/command_prompt.php");
    require_once($enginePath . "lib/chat_helper_functions.php");
    require_once($enginePath . "lib/data_functions.php");
    require_once($enginePath . "lib/rolemaster_helpers.php");
    require_once($enginePath . "lib/utils_game_timestamp.php");

    require_once $enginePath . "lib/core/npc_master.class.php";
    require_once $enginePath . "lib/core/api_badge.class.php";
    require_once $enginePath . "lib/core/core_profiles.class.php";
    require_once $enginePath . "lib/core/llm_connector.class.php";

    /**
     * Process delayed events waiting for TTS to finish
     * Events are stored in npcMaster extended_data and posted when speech has been idle for 15+ seconds
     */
    function processDelayedEvents($db, $enginePath)
    {
        $npcMaster = new NpcMaster();
        $lastSpeechGamets = GetLastSpeechTs();
        $currentGamets = DataLastKnownGameTS();

        // Check all NPCs with pending delayed events
        $allNpcs = $db->fetchAll("SELECT * FROM core_npc_master WHERE extended_data->>'pending_delayed_event' IS NOT NULL");

        foreach ($allNpcs as $npc) {
            $extendedData = $npcMaster->getExtendedData($npc);

            if (!isset($extendedData['pending_delayed_event'])) {
                continue;
            }

            $pendingEvent = $extendedData['pending_delayed_event'];

            // Check if 15 seconds have passed since last speech (using game ticks)
            $secondsSinceLastSpeech = gamets2seconds_between($lastSpeechGamets, $currentGamets);
            if ($secondsSinceLastSpeech >= 15) {
                logger::info("[DELAYED-EVENT] Posting delayed event for {$npc['npc_name']}");

                // Insert the pending event into responselog
                $db->insert('responselog', $pendingEvent);

                // Remove the pending event from extended_data
                unset($extendedData['pending_delayed_event']);
                $npc = $npcMaster->setExtendedData($npc, $extendedData);
                $npcMaster->updateByArray($npc);

                logger::info("[DELAYED-EVENT] Event posted and cleared for {$npc['npc_name']}");
            } else {
                $waitTime = 15 - $secondsSinceLastSpeech;
                logger::debug("[DELAYED-EVENT] Waiting {$waitTime}s more for speech to finish for {$npc['npc_name']}");
            }
        }
    }

    //$results = $GLOBALS["db"]->fetchAll("select max(gamets_truncated) as gamets_truncated from memory_summary where summary is not null order by gamets_truncated desc limit 1"); //0.8ms
    $results = $GLOBALS["db"]->fetchAll("select max(gamets_truncated) as gamets_truncated from memory_summary where summary is not null"); // 0.5ms, faster 
    $lastMemory = intval($results[0]["gamets_truncated"]);

    //$results = $GLOBALS["db"]->fetchAll("select max(gamets) as gamets from eventlog ORDER BY gamets desc limit 1");
    $results = $GLOBALS["db"]->fetchAll("select max(gamets) as gamets from eventlog"); // faster
    $maxRow = intval($results[0]["gamets"]);

    if (chimIsGlobalLlmConnectorEnabled('CORE_CONNECTOR_MEDIUMTERM')) {
        // An explicit NPC override wins; otherwise inherit the assigned profile setting.
        $allEnabledMtNpc = $GLOBALS["db"]->fetchAll(
            "SELECT m.* FROM core_npc_master m
             LEFT JOIN core_profiles p ON p.id = m.profile_id
             WHERE COALESCE(NULLIF(m.extended_data->>'middle_term_enabled',''),
                            p.metadata->>'MIDDLE_TERM_MEMORY_ENABLED') = '1' "
        );

        foreach ($allEnabledMtNpc as $npc) {
            // echo "[MIDDLETERM] {$npc["npc_name"]} has middleterm memory enabled".PHP_EOL;
            $GLOBALS["SELECTED_NPC"] = $npc["npc_name"];
            require("cmd" . DIRECTORY_SEPARATOR . "generate.php");
        }
    } else {
        Logger::debug('[MIDDLETERM] Background & Memory Tasks are disabled globally');
    }

    $pfi = intval($GLOBALS["FEATURES"]["MEMORY_EMBEDDING"]["AUTO_CREATE_SUMMARY_INTERVAL"] ?? 10) * 100000;

    if (chimIsGlobalLlmConnectorEnabled('CORE_CONNECTOR_SUMMARY')) {
        if (isset($GLOBALS["FEATURES"]["MEMORY_EMBEDDING"]["AUTO_CREATE_SUMMARYS"]) && $GLOBALS["FEATURES"]["MEMORY_EMBEDDING"]["AUTO_CREATE_SUMMARYS"] === true) {
            error_log("[MIDDLETERM] Auto-create summary is enabled, interval: {$pfi}");
            if (($maxRow - $lastMemory) > ($pfi)) {
                // Run memory compaction silently
                $shellResult = shell_exec("php {$GLOBALS["ENGINE_PATH"]}/debug/util_memory_subsystem.php compact embed 1 2>/dev/null");
            }

        } else {
            error_log("[MIDDLETERM] Auto-create summary is disabled");
            if (($maxRow - $lastMemory) > ($pfi)) {
                // Run memory compaction silently
                $shellResult = shell_exec("php {$GLOBALS["ENGINE_PATH"]}/debug/util_memory_subsystem.php compact embed 0 2>/dev/null");
            }
        }
    }


    //unset($GLOBALS["db"]);

}
    ?>