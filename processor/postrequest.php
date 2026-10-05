<?php
/*

Post tasks.

*/

// Helper function to properly check boolean values (handles string "false" from form submissions)
if (!function_exists('isOghmaSettingEnabled')) {
    function isOghmaSettingEnabled($value)
    {
        if ($value === null)
            return false;
        if ($value === false || $value === 'false' || $value === '0' || $value === 0)
            return false;
        if ($value === true || $value === 'true' || $value === '1' || $value === 1)
            return true;
        return (bool) $value;
    }
}

$minimeEnabled = isMinimeT5Enabled();
$oghmaInfiniumEnabled = isOghmaSettingEnabled($GLOBALS["OGHMA_INFINIUM"] ?? false);

if ($minimeEnabled) {
    // Use profile-based OGHMA_INFINIUM setting (not legacy conf.php $FEATURES["MISC"]["OGHMA_INFINIUM"])
    if ($oghmaInfiniumEnabled) {
        if (in_array($gameRequest[0], ["inputtext", "inputtext_s", "ginputtext", "ginputtext_s", "rechat", "continue", "continue_group", "narrator_inputtext"])) {

            //$TEST_TEXT=lastSpeech($GLOBALS["HERIKA_NAME"]);
            //$TEST_TEXT="{$GLOBALS["HERIKA_NAME"]}:".implode(" ",$GLOBALS["talkedSoFar"]);
            $TEST_TEXT = implode(" ", $GLOBALS["talkedSoFar"]);

            $topic = json_decode(minimePostTopic($TEST_TEXT), true);

        }
    }
}

define("__JEV_CONFIDENCE_THRESHOLD", 0.89);

// POST MEMORY
if ($minimeEnabled) {
    if (in_array($gameRequest[0], ["inputtext", "inputtext_s", "ginputtext", "ginputtext_s", "narrator_inputtext"])) {
        if (sizeof($memoryInjectionCtx) == 0) {
            // In case main memory search didnt return resutls because minime activated and user is nt directly asking a question
            error_log("[POST MEMORY SEARCH]");
            $GLOBALS["PATCH_BYPASS_MINIME_EXTRACT"] = true;

            $GLOBALS["MEMORY_THRESHOLD_MODIFIER"] = 0.5;
            $memoryInjection = offerMemory($gameRequest, $useLocationContext = true);
            if ($memoryInjection) {

                $gameRequestCopy = $gameRequest;
                $gameRequestCopy[0] = "infoaction";
                $gameRequestCopy[3] = "#MEMORY: {$GLOBALS["HERIKA_NAME"]} remembers this: [$memoryInjection]";
                error_log("[POST MEMORY SEARCH], memory found ($memoryInjection)");
                logEvent($gameRequestCopy, $GLOBALS["HERIKA_NAME"]); // Memory log only avaibale to current NPC.
            }

        }

        // Scene genre switches are read before the dialogue history so a switched-off classifier makes no
        // history query or provider request. An enabled Decision Connector owns scene genre: with its Scene
        // Classifier off, Scene Classifier (Legacy) is not used. scene_status is still updated below.
        $decisionConnectorEnabled = chimIsGlobalLlmConnectorEnabled('CORE_CONNECTOR_DECISION');
        $decisionSceneClassifierEnabled = chimIsDecisionSceneClassifierEnabled();
        $sceneClassifierEnabled = true;
        if (array_key_exists("SCENE_CLASSIFIER_ENABLED", $GLOBALS)) {
            $sceneClassifierEnabledValue = $GLOBALS["SCENE_CLASSIFIER_ENABLED"];
            if (is_string($sceneClassifierEnabledValue)) {
                $sceneClassifierEnabled = !in_array(strtolower(trim($sceneClassifierEnabledValue)), ["", "0", "false", "off", "no"], true);
            } else {
                $sceneClassifierEnabled = !empty($sceneClassifierEnabledValue);
            }
        }
        $sceneGenreWanted = $decisionConnectorEnabled ? $decisionSceneClassifierEnabled : $sceneClassifierEnabled;

        $historyData = "";
        $lastPlace = "";
        $lastListener = "";
        $lastDateTime = "";

        foreach (($sceneGenreWanted ? json_decode(DataSpeechJournal($GLOBALS["HERIKA_NAME"], 10), true) : []) as $element) {
            if ($element["listener"] == "The Narrator") {
                continue;
            }
            if ($lastListener != $element["listener"]) {
                $listener = " (talking to {$element["listener"]})";
                $lastListener = $element["listener"];
            } else {
                $listener = "";
            }

            if ($lastPlace != $element["location"]) {
                $place = " (at {$element["location"]})";
                $lastPlace = $element["location"];
            } else {
                $place = "";
            }

            if ($lastDateTime != substr($element["sk_date"], 0, 15)) {
                $date = substr($element["sk_date"], 0, 10);
                $time = substr($element["sk_date"], 11);
                $dateTime = "(on date {$date} at {$time})";
                $lastDateTime = substr($element["sk_date"], 0, 15);
            } else {
                $dateTime = "";
            }

            $historyData .= trim("{$element["speaker"]}:" . trim($element["speech"]) . " $listener $place $dateTime") . PHP_EOL;
        }

        // SCENE classifier

        $status = "default";
        //$topic  = json_decode(minimePostScene($historyData), true);// Not working well for now.

        $genreCriteria = [
            "horror" => "The dialogue is primarily frightening, supernatural, or disturbing.",
            "action" => "The dialogue centers on immediate physical conflict, danger, or fast-paced events.",
            "thriller" => "The dialogue centers on suspense, imminent danger, or tense uncertainty.",
            "mystery" => "The dialogue centers on an unresolved question, investigation, or hidden truth.",
            "romance" => "The dialogue centers on romantic attraction, intimacy, or a relationship.",
            "comedy" => "The dialogue is primarily humorous, playful, or intended to amuse.",
            "drama" => "The dialogue centers on serious emotional conflict or consequential personal events.",
            "nsfw" => "The dialogue is primarily sexually explicit or adult in nature.",
            "default" => "No listed genre clearly describes the current dialogue.",
        ];

        // An enabled Decision Connector replaces the legacy classifier. Each path makes at most one
        // request; a failed, missing or uncertain answer keeps the default genre without a retry.
        $topic = ["generated_tags" => "default"];
        if ($decisionConnectorEnabled && !$decisionSceneClassifierEnabled) {
            Logger::info("[SCENE CLASSIFIER] Decision Connector Scene Classifier is off, skipping scene genre detection");
        } else if ($decisionConnectorEnabled) {
            $connector = new LLMConnector();
            $decisionConnectorId = intval($GLOBALS["CORE_CONNECTOR_DECISION"] ?? 0);
            $decisionConnectorData = $decisionConnectorId > 0 ? $connector->getById($decisionConnectorId) : null;
            $decisionResponse = [];
            if (!chimIsDecisionConnector($decisionConnectorData)) {
                Logger::warn("[SCENE CLASSIFIER] Decision Connector is not an OpenRouter decision connector, skipping scene genre detection");
            } else {
                $connector->setOldGlobals($decisionConnectorData);
                if (trim((string) ($GLOBALS["CONNECTOR"]["openrouterjson"]["API_KEY"] ?? "")) === "") {
                    Logger::warn("[SCENE CLASSIFIER] Decision Connector has no API key, skipping scene genre detection");
                } else {
                    $decisionResponse = $connector->getConnector($decisionConnectorData)->jev_request(
                        ["dialogue" => $historyData],
                        "Choose the dominant genre of the recent dialogue between the actors.",
                        $genreCriteria,
                        "sceneclassifier"
                    );
                }
            }

            $genreDecision = is_array($decisionResponse) ? ($decisionResponse["answers"]["genre"] ?? null) : null;
            $genreChoice = is_array($genreDecision) ? ($genreDecision["choice"] ?? null) : null;
            $genreConfidence = is_array($genreDecision) ? ($genreDecision["confidence"] ?? null) : null;
            if (is_string($genreChoice) && (is_int($genreConfidence) || is_float($genreConfidence))
                && is_finite((float) $genreConfidence) && $genreConfidence <= 1
                && $genreConfidence > __JEV_CONFIDENCE_THRESHOLD) {
                $genreChoice = strtolower(trim($genreChoice));
                if (array_key_exists($genreChoice, $genreCriteria)) {
                    $topic = ["generated_tags" => $genreChoice];
                }
            }

            Logger::info("[SCENE CLASSIFIER] Decision Connector genre: {$topic["generated_tags"]}");
        } else if ($sceneClassifierEnabled) {
            $connector = new LLMConnector();
            $sceneClassifierLabels = [
                "Gemma 3 4B",
                "Gemma 3N E4B",
                "Scene Classifier (Gemma 3N E4B)",
                "Scene Classifier (Gemini 2.5 Flash Lite)"
            ];
            $sceneClassifierConnectorId = intval($GLOBALS["CORE_CONNECTOR_SCENECLASSIFIER"] ?? 0);
            $mediumTermConnectorId = intval($GLOBALS["CORE_CONNECTOR_MEDIUMTERM"] ?? 0);
            $currentConnectorData = null;
            $connectionHandler = null;

            if ($sceneClassifierConnectorId > 0) {
                $currentConnectorData = $connector->getById($sceneClassifierConnectorId);
            }

            if (empty($currentConnectorData) && isset($GLOBALS["db"]) && $GLOBALS["db"]) {
                foreach ($sceneClassifierLabels as $sceneClassifierLabel) {
                    $sceneClassifierLabelEscaped = $GLOBALS["db"]->escape($sceneClassifierLabel);
                    $sceneClassifierRow = $GLOBALS["db"]->fetchOne(
                        "SELECT id FROM core_llm_connector WHERE LOWER(COALESCE(label,'')) = LOWER('{$sceneClassifierLabelEscaped}') LIMIT 1"
                    );
                    if (is_array($sceneClassifierRow) && !empty($sceneClassifierRow["id"])) {
                        $sceneClassifierConnectorId = intval($sceneClassifierRow["id"]);
                        $currentConnectorData = $connector->getById($sceneClassifierConnectorId);
                        if (!empty($currentConnectorData)) {
                            Logger::info("[SCENE CLASSIFIER] Auto-selected dedicated scene classifier connector ID {$sceneClassifierConnectorId}");
                            break;
                        }
                    }
                }
            }

            if (empty($currentConnectorData) && chimIsGlobalLlmConnectorEnabled('CORE_CONNECTOR_MEDIUMTERM') && $mediumTermConnectorId > 0) {
                Logger::info("[SCENE CLASSIFIER] CORE_CONNECTOR_SCENECLASSIFIER not configured or invalid, falling back to CORE_CONNECTOR_MEDIUMTERM");
                $currentConnectorData = $connector->getById($mediumTermConnectorId);
            }

            if (!empty($currentConnectorData) && chimIsDecisionConnector($currentConnectorData)) {
                Logger::warn("[SCENE CLASSIFIER] Scene Classifier (Legacy) needs a chat model, not a decision connector; skipping scene genre detection");
            } else if (!empty($currentConnectorData)) {
                $connector->setOldGlobals($currentConnectorData);
                $connectionHandler = $connector->getConnector($currentConnectorData);
            } else {
                Logger::warn("[SCENE CLASSIFIER] No connector configured for scene classification, skipping scene genre detection");
            }

            $allowedGenres = array_values(array_diff(array_keys($genreCriteria), ["default"]));

            $prompt = [];
            $prompt[] = ['role' => 'system', 'content' => "Classify the following dialogue into one of these genres: ".
                implode(", ", $allowedGenres)];

            $prompt[] = ['role' => 'user', 'content' => "Dialogue:\n$historyData"];
            $prompt[] = ['role' => 'user', 'content' => "Respond only with the genre name."];

            $buffer = "";
            if ($connectionHandler) {
                $buffer = $connectionHandler->fast_request(
                    $prompt,
                    ["MAX_TOKENS" => 256],
                    "sceneclassifier"
                );
            }

            // Parse LLM output to find matching genre
            $bufferLower = is_string($buffer) ? strtolower(trim($buffer)) : "";
            foreach ($allowedGenres as $genre) {
                if ($bufferLower !== "" && stripos($bufferLower, $genre) !== false) {
                    $topic = ["generated_tags" => $genre];
                    break;
                }
            }

            Logger::info("[SCENE CLASSIFIER] Scene Classifier (Legacy) genre: {$topic["generated_tags"]}");
        } else {
            Logger::info("[SCENE CLASSIFIER] Disabled, skipping scene genre detection");
        }

        $sceneNotes = [
            "default" => [
                "status" => "neutral",
                "data" => "The overall atmosphere is neutral and balanced. Actors should behave naturally, with grounded expressions, measured movement, and behavior appropriate to the immediate context.",
            ],

            "relax" => [
                "status" => "relaxed",
                "data" => "The overall atmosphere is calm, comfortable, and unhurried. Actors should appear at ease, using natural body language, gentle expressions, relaxed posture, and slow, effortless movements.",
            ],

            "romance" => [
                "status" => "intimate",
                "data" => "The overall atmosphere is romantic, warm, and emotionally intimate. Actors should convey affection and mutual interest through soft expressions, attentive eye contact, natural proximity, gentle gestures, and restrained, emotionally authentic movement.",
            ],

            "nsfw" => [
                "status" => "intimate",
                "data" => "The overall atmosphere is mature and intimate. Actors should behave naturally and consensually, with emotionally suggestive body language, close interpersonal distance, attentive expressions, and a restrained cinematic tone.",
            ],

            "thriller" => [
                "status" => "dynamic",
                "data" => "The overall atmosphere is tense, suspenseful, and unpredictable. Actors should remain alert and reactive, with purposeful movements, heightened expressions, guarded body language, and pacing that reflects mounting tension.",
            ],

            "horror" => [
                "status" => "tense",
                "data" => "The overall atmosphere is unsettling, ominous, and suspenseful. Actors should appear cautious and increasingly uneasy, using hesitant movements, fearful or suspicious expressions, environmental awareness, and restrained reactions that build tension.",
            ],

            "mystery" => [
                "status" => "intriguing",
                "data" => "The overall atmosphere is mysterious and investigative. Actors should appear observant and thoughtful, with subtle reactions, deliberate movements, cautious interactions, and expressions suggesting curiosity, uncertainty, or suspicion.",
            ],

            "comedy" => [
                "status" => "playful",
                "data" => "The overall atmosphere is lighthearted, playful, and energetic. Actors should use expressive faces, natural comedic timing, relaxed posture, animated gestures, and reactions that emphasize the humorous situation without feeling forced.",
            ],

            "drama" => [
                "status" => "emotional",
                "data" => "The overall atmosphere is emotionally charged and serious. Actors should use authentic facial expressions, deliberate gestures, attentive eye contact, and controlled movements that communicate the emotional weight of the scene.",
            ],

            "action" => [
                "status" => "dynamic",
                "data" => "The overall atmosphere is energetic, urgent, and physically dynamic. Actors should use purposeful movement, decisive gestures, heightened awareness, strong reactions, and pacing appropriate to an active cinematic sequence.",
            ],
        ];

        $sceneNote = $sceneNotes[$topic["generated_tags"]] ?? null;
        if ($sceneNote !== null && $topic["generated_tags"]!="default") {
            $GLOBALS["db"]->insert(
                'rolemaster',
                [
                    'localts' => time(),
                    'ttl' => 60,
                    'type' => "scenenote",
                    'data' => "{$topic["generated_tags"]}: {$sceneNote["data"]}",
                ]
            );
            $status = $sceneNote["status"];
        }

        $npcManager = new NpcMaster();
        $npcData = $npcManager->getByName($GLOBALS["HERIKA_NAME"]);
        if ($npcData) {
            if (isset($npcData["extended_data"])) {
                $extended = json_decode($npcData["extended_data"], true);
            } else {
                $extended = [];
            }
            $extended["scene_status"] = $status;
            $npcData["extended_data"] = json_encode($extended);
            //$npcData["gamets_last_updated"]=$gameRequest[2];
            $npcManager->updateByArray($npcData);
        }

    }
} else {
    Logger::info("[SCENE CLASSIFIER] No topic generated, skipping scene genre detection");
}

$configFilepath = __DIR__ . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . "conf" . DIRECTORY_SEPARATOR;
$GLOBALS["PROFILES"]["default"] = "$configFilepath/conf.php";
foreach (glob($configFilepath . 'conf_????????????????????????????????.php') as $mconf) {
    if (file_exists($mconf)) {
        $filename = basename($mconf);
        $pattern = '/conf_([a-f0-9]+)\.php/';
        preg_match($pattern, $filename, $matches);
        $hash = $matches[1];
        $GLOBALS["PROFILES"][$hash] = $mconf;
    }
}

require "$configFilepath/conf.php";

// Dynmci set current task
if ($minimeEnabled) {
    if (in_array($gameRequest[0], ["inputtext", "inputtext_s", "ginputtext", "ginputtext_s"])) {

        $pattern = "/\([^)]*Context location[^)]*\)/"; // Remove (Context location..
        $replacement = "";
        $TEST_TEXT = preg_replace($pattern, $replacement, $gameRequest[3]); // // assistant vs user war
        $pattern = '/\(\s*(?:(?:talking|whispering|shouting)\s+to|speaking\s+(?:loudly|privately)\s+to)\s+[^()]+(?:\s+from\s+far\s+away)?\s*\)/i';
        $TEST_TEXT = preg_replace($pattern, '', $TEST_TEXT);

        $command = json_decode(minimeTask($TEST_TEXT), true);
        if (isset($command["is_command"])) {
            $prCmd = explode("@", $command["is_command"]);
            if ($prCmd[0] == "SetCurrentTask") {
                $db->insert(
                    'currentmission',
                    [
                        'ts' => $gameRequest[1],
                        'gamets' => $gameRequest[2],
                        'description' => $prCmd[1],
                        'sess' => 'pending',
                        'localts' => time(),
                    ]
                );
                $db->insert(
                    'audit_memory',
                    [
                        'input' => $TEST_TEXT,
                        'keywords' => 'auto added task',
                        'rank_any' => -1,
                        'rank_all' => -1,
                        'memory' => $command["is_command"],
                        'time' => $command["elapsed_time"],
                    ]
                );
            }
        }
    }
}
