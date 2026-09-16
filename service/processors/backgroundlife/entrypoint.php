<?php

$GLOBALS["TASKS"]["backgroundlife"] = [];
$GLOBALS["TASKS"]["backgroundlife"]["fn"] = function () {

    $enginePath = $GLOBALS["ENGINE_ROOT"];
    $GLOBALS["ENGINE_PATH"] = $enginePath;


    if (!isset($GLOBALS["db"])) {
        $GLOBALS["db"] = new sql();
    }

    require_once($enginePath . "lib/game_activity.php");
    if (!chimHasRecentGameActivity()) {
        error_log("[BGL] Skipping scheduled LLM work because no recent game activity was detected");
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
    require_once $enginePath . 'lib/scriptproxy_papyrus.php';

    error_log("[BGL] Starting Background Life processing");

    if (chimIsGlobalLlmConnectorEnabled('CORE_CONNECTOR_BGL')) {
    
        $results = $GLOBALS["db"]->fetchAll("select max(gamets) as gamets from eventlog"); // faster
        $maxRow = intval($results[0]["gamets"]);

        // BgL tracking coords, on NPCs marked with gps_track. in-game hourly
        $oneDayAgoGamets = $maxRow - ((24) / 0.0000024);
        $oneHourAgoGamets = $maxRow - ((1) / 0.0000024);

        // Get BgL trigger period from general settings (default: 24 in-game hours).
        $bglTriggerHours = chimGetBackgroundLifeTriggerHours();
        $bglTriggerHoursAgoGamets = $maxRow - ($bglTriggerHours / 0.0000024);

        $bglTriggerHours = chimGetBackgroundLifeTriggerHours();
        $bglTriggerDays = $bglTriggerHours / 24;
        $bglTriggerDaysAgoGamets = $maxRow - ((24 * $bglTriggerDays) / 0.0000024);


        // BgL tracking coords, in-game daily

        $allEnabledBgLNpc = $GLOBALS["db"]->fetchAll("SELECT * FROM core_npc_master WHERE extended_data->>'background_life_enabled' = 'true' AND metadata->>'last_coords' IS NOT NULL AND metadata->'last_coords'->>'pending' IS NULL ");
        foreach ($allEnabledBgLNpc as $npc) {
            $mwdata = json_decode($npc["metadata"], true);
            if (!isset($mwdata["last_coords"]["last_updated"]) || !$mwdata["last_coords"]["last_updated"] || $mwdata["last_coords"]["last_updated"] < ($oneDayAgoGamets)) {
                logger::info("[BGL] Daily Tracking {$npc["npc_name"]}");
                $locaPath = __DIR__;
                $shellResult = shell_exec("php $locaPath/cmd/simple_command.php \"{$npc["npc_name"]}\" Track ");
                if (!empty($GLOBALS["CUSTOM_LOG_FILE"])) {
                    Logger::info($shellResult, $GLOBALS["CUSTOM_LOG_FILE"]);
                }
            }

        }



        // GPS coords track
        if (false) {
            // This will track every 5 secs
            $oneHourAgoGamets = $maxRow;
        }

        error_log("[BGL] Checking tracked NPCs");

        $allEnabledBgLNpc = $GLOBALS["db"]->fetchAll("SELECT * FROM core_npc_master WHERE extended_data->>'background_life_enabled' = 'true' AND metadata->'gps_track' = 'true' AND metadata->'last_coords'->>'pending' IS NULL AND (metadata->'last_coords'->>'last_updated')::numeric < $oneHourAgoGamets ");

        foreach ($allEnabledBgLNpc as $npc) {
            $mwdata = json_decode($npc["metadata"], true);
            if (
                !isset($mwdata["last_coords"]["last_updated"]) || !$mwdata["last_coords"]["last_updated"]
                || $mwdata["last_coords"]["last_updated"] < $oneHourAgoGamets
            ) {
                logger::info("[BGL] Hourly Tracking {$npc["npc_name"]}");
                $locaPath = __DIR__;
                $shellResult = shell_exec("php $locaPath/cmd/simple_command.php \"{$npc["npc_name"]}\" Track ");
                if (!empty($GLOBALS["CUSTOM_LOG_FILE"])) {
                    Logger::info($shellResult, $GLOBALS["CUSTOM_LOG_FILE"]);
                }
            }
        }



        // BgL content
        // In-game based on configured days

        error_log("[BGL] Checking passive events NPCs");
        $allEnabledBgLNpc = $GLOBALS["db"]->fetchAll("SELECT * FROM core_npc_master WHERE extended_data->>'background_life_enabled' = 'true' AND (extended_data->>'background_life_commands' = 'false' or extended_data->>'background_life_commands'  IS NULL)");
        foreach ($allEnabledBgLNpc as $npc) {

            $npcIsNearToPlayer = $GLOBALS["db"]->fetchOne("SELECT count(*) as n from eventlog where 
            type='infonpc' and data like '%" . ($GLOBALS["db"]->escape($npc["npc_name"])) . "%' and gamets > $oneHourAgoGamets");


            if (isset($npcIsNearToPlayer) && $npcIsNearToPlayer["n"] > 0) {
                $localDelta = ($npcIsNearToPlayer["n"] - $oneHourAgoGamets) * 0.0000024;
                error_log("[BGL] Skipping Passive event for {$npc["npc_name"]}, is NEAR TO PLAYER, delta: {$localDelta} {$oneHourAgoGamets}");
                // We're gonna update background_life_last_updated
                // Passive events are intended for dismissed followers/spouses...
                // If the NPC is near a player, we don't want to trigger a passive event, but we still want to update the last updated timestamp to avoid repeated checks.
                $npcManager = new NpcMaster();
                $mwdata = json_decode($npc["extended_data"], true);
                $mwdata["background_life_last_updated"] = $maxRow;
                $mwdata["background_life_last_updated_presence_delta"] = 0;
                $npcManager->updateExtendedKeysByName($npc["npc_name"], $mwdata);
                continue;

            }

            $mwdata = json_decode($npc["extended_data"], true);
            // Trigger if never updated, or if last update is older than configured threshold
            $mustInstructBypassBgl = false;
            if (!isset($mwdata["background_life_last_updated"]) || $mwdata["background_life_last_updated"] < ($bglTriggerDaysAgoGamets)) {
                error_log("[BGL]  Passive event for {$npc["npc_name"]}");


                if (isset($mwdata["background_life_last_updated"])) {
                    if ($mwdata["background_life_last_updated"] > ($oneDayAgoGamets)) {

                        $delta = ($mwdata["background_life_last_updated"] - $oneDayAgoGamets) * 0.0000024;
                        error_log("[BGL]  {$npc["npc_name"]} Avoiding by 1-day HARDCODED RULE. Last updated: {$mwdata["background_life_last_updated"]}, threshold: {$oneDayAgoGamets}, BGL_TRIGGER_DAYS: {$GLOBALS['BGL_TRIGGER_DAYS']}, delta: {$delta}");
                        continue;

                    }
                }
                $locaPath = __DIR__;
                $shellResult = shell_exec("php $locaPath/cmd/main_lw.php \"{$npc["npc_name"]}\" ");
                if (!empty($GLOBALS["CUSTOM_LOG_FILE"])) {
                    Logger::info($shellResult, $GLOBALS["CUSTOM_LOG_FILE"]);
                }


                $extdata["background_life_last_updated"] = $maxRow;
                $npcMaster->updateExtendedKeysByName($npc["npc_name"], $extdata);

                break;  // One per iteration - break after processing
            } else {
                $delta = ($mwdata["background_life_last_updated"] - $bglTriggerHoursAgoGamets) * 0.0000024;
                error_log("[BGL] (Passive) Skipping {$npc["npc_name"]}, last updated: {$mwdata["background_life_last_updated"]}, threshold: {$bglTriggerHoursAgoGamets}, BGL_TRIGGER_HOURS: {$bglTriggerHours},delta: {$delta}");
            }
        }

        error_log("[BGL] Checking active events NPCs");

        // BgL commands
        $allEnabledBgLNpc = $GLOBALS["db"]->fetchAll("SELECT * FROM core_npc_master WHERE extended_data->>'background_life_enabled' = 'true' AND extended_data->>'background_life_commands' = 'true' order by random() ");
        foreach ($allEnabledBgLNpc as $npc) {
            $mwdata = json_decode($npc["extended_data"], true);
            $metadata = json_decode($npc["metadata"], true);
            $mustInstructBypassBgl = false;
            $actorEscaped = $GLOBALS["db"]->escape($npc["npc_name"]);
            $npcIsNearToPlayer = $GLOBALS["db"]->fetchOne("SELECT max(gamets) as n from eventlog where 
            type='infonpc_close' and 
            (
                people like '%|$actorEscaped|%'
                or people like '$actorEscaped'
                or people like '%|$actorEscaped (busy)|%'
                or people like '%|$actorEscaped (hostile)|%'
                or people like '%|$actorEscaped (in combat)|%'
                or people like '%|$actorEscaped (far away)|%'
            )
            and gamets > $oneHourAgoGamets");

            // TravelTo stuck NPCs
            // Check NPC is not near to player, last action issued was TravelTo or MoveTo,  We must check coords history on metadata,
            // and if no movement in the last hour notify
            $npcNameEscDb = $GLOBALS["db"]->escape($npc["npc_name"]);
            $actionsRows = $GLOBALS["db"]->fetchAll(
                "SELECT action,actorname,gamets,fullcall FROM actions_issued
     WHERE actorname='$npcNameEscDb' 
       AND gamets > ({$mwdata["background_life_last_updated"]}-($oneHourAgoGamets*4)) 
     ORDER BY gamets DESC, ts DESC
     LIMIT 1 OFFSET 0"
            );


            if (!empty($actionsRows)) {
                // Process the actions rows to check for TravelTo or MoveTo
                foreach ($actionsRows as &$row) {
                    $parts = explode(':', $row['fullcall'], 3);
                    $row['destination'] = $parts[1] ?? '';
                }
                if ($parts[0] === 'TravelTo' || $parts[0] === 'MoveTo') {

                    if (isset($metadata['last_coords_history']) && is_array($metadata['last_coords_history'])) {
                        $coordsHistory = $metadata['last_coords_history'];
                        $recentCoords = array_slice($coordsHistory, -5);
                        $uniqueLocations = array_unique(array_column($recentCoords, 3));
                        $isStuck = count($uniqueLocations) === 1;
                        $recentCoordsText = implode(', ', array_column($recentCoords, 3));
                    }

                    if ($isStuck) {
                        error_log("[BGL] NPC <{$npc["npc_name"]}> appears to be STUCK at location: {$uniqueLocations[0]} <{$recentCoordsText}>, Wants:<{$row['destination']}>");
                        if (isset($npcIsNearToPlayer) && $npcIsNearToPlayer["n"] > 0) {
                            error_log("[BGL] NPC STUCK {$npc["npc_name"]} is near a player, skipping stuck check");
                        } else {

                            $npcMaster = new NpcMaster();
                            $currentNpcData = $npcMaster->getByName($npc["npc_name"]);
                            $candidateLocation = resolveTravelLocation($row['destination'], $currentNpcData, $GLOBALS['db']);

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

                                error_log("[BGL RUN] {$npc["npc_name"]} — Teleported to {$candidateLocation['name']} (formid: {$candidateLocation['formid']})");

                                $lastGameTsRow = $GLOBALS["db"]->fetchAll('SELECT max(gamets) AS last_gamets FROM eventlog');
                                $lastTsRow = $GLOBALS["db"]->fetchAll("SELECT max(ts) AS ts FROM eventlog WHERE gamets='{$lastGameTsRow[0]['last_gamets']}'");

                                $last_gamets = (int) $lastGameTsRow[0]['last_gamets'] + 1;
                                $last_ts = $lastTsRow[0]['ts'];

                                $GLOBALS["db"]->insert('actions_issued', [
                                    'action' => 'TeleportTo',
                                    'fullcall' => "TeleportTo:{$candidateLocation['name']}:Teleporting to resolve stuck NPC",
                                    'actorname' => $npc["npc_name"],
                                    'ts' => $last_ts,
                                    'gamets' => $last_gamets,
                                    'localts' => time(),
                                    'original' => 'backgroundaction',
                                ]);


                            } else {
                                error_log("[BGL RUN] STUCK {$candidateLocation["sim"]} >"._LOCATION_RESOLVE_SIM_THRESHOLD." && {$candidateLocation["refs"]}");   
                                $npcTargetmaster=new NpcMaster();
                                $npcTarget=$npcTargetmaster->getByName($row['destination']);
                                if ($npcTarget) {
                                    $skyrimCmd = new SkyrimCommandBuilder();
                                    $json = $skyrimCmd->ObjectReference->MoveTo(
                                        "0x{$currentNpcData['refid']}",
                                        "0x{$npcTarget['refid']}"
                                    );
                                    $skyrimCmd->send(cmd: $json);
                                    error_log("[BGL RUN] {$npc["npc_name"]} — Teleported to {$row['destination']} (formid: {$npcTarget['formid']})");
                                }
                            }


                        }
                    } else {
                        error_log("[BGL] NPC {$npc["npc_name"]} is not stuck, Wants:<{$row['destination']}> <" . count($uniqueLocations) . ">");
                    }
                }

            }

            // End of TravelTo stuck NPCs check

            if (isset($npcIsNearToPlayer) && $npcIsNearToPlayer["n"] > 0) {
                $localDelta = ($npcIsNearToPlayer["n"] - $oneHourAgoGamets) * 0.0000024;

                $npcManager = new NpcMaster();
                $npcData = $npcManager->getByName($npc["npc_name"]);
                $extended = json_decode($npcData["extended_data"], true);
                if (isset($extended["background_life_last_updated_presence_delta"])) {
                    $extended["background_life_last_updated_presence_delta"] += 1;
                } else {
                    $extended["background_life_last_updated_presence_delta"] = 1;
                }
                $npcData = $npcManager->setExtendedData($npcData, $extended);

                if ($extended["background_life_last_updated_presence_delta"] > 10) {
                    error_log("[BGL] {$npc["npc_name"]} has been near a player for more than 10 checks. Issuing instructions if needed");

                    // $extended["background_life_last_updated"] = $maxRow;
                    $mustInstructBypassBgl = true;
                    $npcData = $npcManager->setExtendedData($npcData, $extended);
                    $npcManager->updateByArray($npcData);
                    $mwdata = $extended;
                } else {
                    $npcData = $npcManager->setExtendedData($npcData, $extended);
                    $npcManager->updateByArray($npcData);
                    error_log("[BGL] Skipping Passive event for {$npc["npc_name"]}, is NEAR TO PLAYER, delta: {$localDelta}, Presence retries: {$extended["background_life_last_updated_presence_delta"]}");
                    continue;
                }

            }

            // Trigger if never updated, or if last update is older than configured threshold
            if (!isset($mwdata["background_life_last_updated"]) || $mwdata["background_life_last_updated"] < ($bglTriggerDaysAgoGamets)) {
                $delta = ($mwdata["background_life_last_updated"] - $bglTriggerDaysAgoGamets) * 0.0000024;
                error_log("[BGL] Event for {$npc["npc_name"]}, last updated: {$mwdata["background_life_last_updated"]}, threshold: {$bglTriggerDaysAgoGamets}, BGL_TRIGGER_DAYS: {$GLOBALS['BGL_TRIGGER_DAYS']}, delta: {$delta}, presence delta: {$mwdata["background_life_last_updated_presence_delta"]}");

                if ($mustInstructBypassBgl) {
                    error_log("[BGL] {$npc["npc_name"]} has been near a player for more than 10 checks. Issuing INSTRUCTION");
                    $GLOBALS["db"]->insert(
                        'responselog',
                        [
                            'localts' => time(),
                            'sent' => 0,
                            'actor' => "rolemaster",
                            'text' => "",
                            'action' => "rolecommand|Instruction@{$npc["npc_name"]}@Should review own life goals, latest inner thoughts, and take a related action or express his/her concerns@0",
                            'tag' => "",
                        ]
                    );
                    // Update timestamp to avoid repeated instructions.
                    // In BgL case, simple_lllm_request_with_context_life.php will update the timestamp after processing the instruction.
                    $npcManager = new NpcMaster();

                    $extended["background_life_last_updated"] = $maxRow;
                    $extended["background_life_last_updated_presence_delta"] = 0;
                    $npcManager->updateExtendedKeysByName($npc["npc_name"], $extended);

                } else {
                    $locaPath = __DIR__;
                    $shellResult = shell_exec("php $locaPath/cmd/main.php \"{$npc["npc_name"]}\" full forceaction");
                }

                if (!empty($GLOBALS["CUSTOM_LOG_FILE"])) {
                    Logger::info($shellResult, $GLOBALS["CUSTOM_LOG_FILE"]);
                }
                break;  // One per iteration - break after processing
            } else {
                $delta = ($mwdata["background_life_last_updated"] - $bglTriggerHoursAgoGamets) * 0.0000024;
                error_log("[BGL] Skipping {$npc["npc_name"]}, last updated: {$mwdata["background_life_last_updated"]}, threshold: {$bglTriggerHoursAgoGamets}, BGL_TRIGGER_HOURS: {$bglTriggerHours}, delta: {$delta}");
            }




        }

        if (sizeof($allEnabledBgLNpc) === 0) {
            error_log("[BGL] No NPCs with background life enabled");
        }

    } else {
        Logger::debug('[BGL] Background Life is disabled globally');
    }


   

}
    ?>