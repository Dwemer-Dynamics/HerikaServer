<?php

// ─── Helper Functions ─────────────────────────────────────────────────────────

/**
 * Resolve the player's name from the Player table, falling back to conf_opts.
 */
function resolvePlayerName(sql $db): string
{
    try {
        $player = new Player();
        $name = $player->get('player_name');
        if (!empty($name)) {
            return $name;
        }
    } catch (Exception $e) {
        // Fall through to database fallback
    }

    $row = $db->fetchOne("SELECT value FROM conf_opts WHERE id='PLAYER_NAME'");
    return !empty($row['value']) ? $row['value'] : '';
}

/**
 * Load a background-life style prompt from the database, with hardcoded fallbacks.
 *
 * @param string $promptKey    'background_life_letter' or 'background_life_innerthought'
 * @param array  $replacements Placeholder => value pairs to substitute
 * @return string              Resolved prompt content
 */
function loadBGLStylePrompt(string $promptKey, array $replacements = []): string
{
    global $db;

    // TODO: Enable DB lookup once default prompts are ready
    $promptData = false; // $db->fetchOne("SELECT custom_prompt, default_prompt FROM prompts WHERE prompt_key='$promptKey'");

    if (!$promptData) {
        error_log("[BGL RUN] Style prompt not found: $promptKey — using fallback.");
        return getBGLStyleFallback($promptKey);
    }

    $prompt = !empty($promptData['custom_prompt'])
        ? $promptData['custom_prompt']
        : $promptData['default_prompt'];

    foreach ($replacements as $placeholder => $value) {
        $prompt = str_replace($placeholder, $value, $prompt);
    }

    return $prompt;
}

/**
 * Return the hardcoded fallback text for a BGL style prompt key.
 */
function getBGLStyleFallback(string $promptKey): string
{
    if ($promptKey === 'background_life_letter') {
        return "Write a letter to {$GLOBALS['PLAYER_NAME']} from {$GLOBALS['HERIKA_NAME']} based on the content of <text>."
            . " Use same language as <text>."
            . " Take into account the <speech_style> section for the writing style,"
            . " and particularly <letter_guidance> if present."
            . " Do not include any meta-commentary or aside, only the content of the letter.";
    }

    return "Read the <text> content, which represents a mental note or inner monologue of the character"
        . " within the Skyrim universe.\nBased on the content of the <text>,"
        . " propose one of the defined actions that would make sense for the development of the story.";
}


function requestForComsuption(
    $dynamicBiography,
    $historyShort,
    $postHistory,
    $idleHours,
    $lastIntentBasedHint,
    $currentNpcData,
    $extdata,
    $db,
    $last_ts,
    $last_gamets,
    $momentum,
    $npcNameEsc,
    $npcMaster,
    $connector,
    $currentConnectorData,
    $npcName,
    $startTime
): string {
    $preStep1Prompt = [
        ['role' => 'system', 'content' => 'Examine this text containing events that occurred in the fictional universe of Skyrim (The Elder Scrolls).'],
        [
            'role' => 'user',
            'content' => "<character_sheet>\n{$GLOBALS['HERIKA_NAME']}:\n$dynamicBiography\n</character_sheet>",
            "cache_control" => ["type" => "ephemeral"]
        ],
        [
            'role' => 'user',
            'content' => "<context_history>\nContext History (chronological order)\n... $historyShort\n</context_history>\n$postHistory\n",
            "cache_control" => ["type" => "ephemeral"]
        ],
        [
            'role' => 'user',
            'content' => "
{$GLOBALS['HERIKA_NAME']}, The character has been idle for the last `$idleHours` hours.

Your task is to determine what happened during this idle period and return the single most appropriate action.

Rules:

0. Check latest {$GLOBALS["HERIKA_NAME"]}'s intent to know if the NPC was in a relaxing or working scenario. Sometimes place seems a working place but the NPC is relaxing or resting.

1. Relaxing scenarios
   - If the NPC was in a relaxing scenario (e.g. inn, home, tavern, camp, etc.), and last intent was a relaxing/sleeping intent, determine whether any consumable items should have been used during the last `$idleHours` hours.
   - Consumables include food, drinks, potions, medicine, or any other item intended to be consumed.
   - Only report items that would actually have been consumed during the idle period and *present on the character's inventory*.

2. Working scenarios
   - If the NPC was in a working scenario,(and last intent was a production intent) determine whether any goods were produced during the last `$idleHours` hours.
   - Inspect the `[production]` subsection inside `<background_life_goals>` first, then `<goals>` if no Background Life production rule is defined, to find:
     - what item(s) are produced
     - the production rate (units per hour)
   - Calculate production only for the last `$idleHours` hours.
   - If production is fractional, round up
   - Produced goods will be added to the character's inventory in the future, so they will not be present in the current inventory.

3. No activity
   - If neither is a working or relaxing scenario (e.g. {$GLOBALS["HERIKA_NAME"]} was sleeping), return the `DoNothing` action.

Requirements

- Consider **only** the last `$idleHours` hours.
- Do not infer events outside this time window.
- Produce exactly one action.
- Base your decision solely on the current scenario, inventory, goals, and production rules provided in the context.
- Do not invent production or consumption that is not supported by the data.

Choose the action that best describes what occurred during the idle period.
$lastIntentBasedHint
"
        ],
        [
            'role' => 'user',
            'content' => "
Return ONLY a valid JSON object with no extra text, no markdown, and no explanation.

Format:
{
  \"action\": [
    \"Consume:itemid:qty\",
    \"Produced:itemid:qty\",
    \"DoNothing\"
  ],
  \"reasoning\": \"optional one-sentence explanation\"
}

Rules:
- Use an empty array [] if no consumption or production happened.
- Only include valid actions in this exact string format:
  Consume:itemid:qty
  Produced:itemid:qty
  DoNothing
- itemid must match in-game inventory identifiers.
- qty must be an integer.
- You may include multiple actions if needed.
- reasoning must be short (one sentence)
- Do not add any keys other than 'action' and 'reasoning'.
- 1 gold coin (or septim) is represented as itemid 0000000F. 9 gold coins would be represented as 0000000F:9, 900 gold coins would be represented as 0000000F:900, and so on.
"
        ]
    ];

    Logger::debug(__LINE__ . ' ' . (microtime(true) - $startTime));

    $connectionHandler = $connector->getConnector($currentConnectorData);
    $preResponse = $connectionHandler->fast_request($preStep1Prompt, ['MAX_TOKENS' => 1024], 'backgroundlife');

    // Keep timestamp of last LLM call for this NPC to avoid too frequent calls
    updateLastLLMCall($GLOBALS['HERIKA_NAME']);

    $parsedResponse = __jpd_decode_lazy($preResponse);

    if (isset($parsedResponse[0]) && is_array($parsedResponse[0])) {
        $parsedResponse = $parsedResponse[0];
    }

    if (isset($parsedResponse['action']) && is_array($parsedResponse['action'])) {
        $action = ($parsedResponse['action']);
    } else {
        $action = '';
    }
    if (isset($parsedResponse['reasoning'])) {
        $reasoning = $parsedResponse['reasoning'];
    } else {
        $reasoning = '';
    }


    if ($action) {
        $actionTextDescription = [];
        foreach ($action as $singleAction) {
            error_log("[BGL RUN] $npcNameEsc — Idle production/consumption detected: $singleAction. Reasoning: $reasoning");

            $skyrimCmd = new SkyrimCommandBuilder();
            $sourceRefHexString = strtolower(convertSignedToUnsignedHex(hexdec($currentNpcData['refid'])));
            // Parse action string
            list($actionType, $itemId, $count) = explode(':', $singleAction);
            $itemId = strtr(strtolower($itemId), ["0x" => ""]); // Remove 0x prefix if present

            $count = (int) $count;
            if ($actionType === 'Consume') {
                $json = $skyrimCmd->ObjectReference->RemoveItem($sourceRefHexString, "0x$itemId", $count, true);
                $skyrimCmd->send(cmd: $json);
            } elseif ($actionType === 'Produced') {
                $json = $skyrimCmd->ObjectReference->AddItem($sourceRefHexString, "0x$itemId", $count, true);
                $skyrimCmd->send(cmd: $json);
            }

            $itemName = getNameForItemReference(strtoupper($itemId));

            if ($itemName) {
                $itemNameResolved = "($count {$itemName})";
            } else {
                $itemNameResolved = "";
            }

            $actionText[] = $singleAction;
            $actionTextDescription[] = $itemNameResolved;
        }

        $actionTextFinal = implode(', ', $actionText);
        $actionTextDescriptionFinal = sizeof($actionTextDescription) > 0 ? implode(', ', $actionTextDescription) : "";

        sleep(sizeof($action));   // Allow time for the command to be processed
        // Send signal to update inventory
        $db->insert('responselog', [
            'localts' => time(),
            'sent' => 0,
            'actor' => 'rolemaster',
            'text' => '',
            'action' => "rolecommand|BackgroundCmd@$sourceRefHexString@UpdateInventory",
            'tag' => '',
        ]);

        sleep(1);   // Allow time for the command to be processed

        $db->insert('eventlog', [
            'ts' => $last_ts,
            'gamets' => $last_gamets - 10,
            'type' => 'innerchat',
            'data' => "The Narrator: $npcName produced/consumed items while idle: $actionTextFinal $actionTextDescriptionFinal. Reasoning: $reasoning",
            'sess' => $momentum,
            'localts' => time(),
            'people' => "|$npcName|",
            'location' => null,
            'party' => '',
        ]);

        // Insert bgl_history log entry
        $db->insert(
            'bgl_history',
            [
                'npc' => $npcName,
                'ts' => $last_ts,
                'gamets' => $last_gamets - 10,
                'localts' => time(),
                'data' => "$npcName produced/consumed items while idle: $actionTextFinal $actionTextDescriptionFinal. Reasoning: $reasoning",
                'category' => 'produce_consume'
            ]
        );

        sleep(1);   // Allow time for the command to be processed

        // Refetch the NPC data to update the dynamic biography with the new inventory state
        $dynamicBiography = buildDynamicBiography($GLOBALS, true, true, true);
        $dynamicBiography = $npcMaster->appendBackgroundLifeGoals($dynamicBiography, $currentNpcData);

        if (isset($extdata['middle_term_memory'])) {
            $middleTermMemory = end($extdata['middle_term_memory']);
            $dynamicBiography .= "\n\n<middle_term_memory>\nPast events\n{$middleTermMemory}\n</middle_term_memory>";
        }
        return "$actionTextFinal $actionTextDescriptionFinal. Reasoning: $reasoning. Inventory will get updated next turn.";
    }
    return "";
}

function requestForInnerThought(
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
    &$recordInnerThoughts,
    &$recordDiaryEntry,
): string {

    $systemPrompts = [
        'en' => [['role' => 'system', 'content' => 'You are a writing assistant. Examine this text containing events that occurred in the fictional universe of Skyrim (The Elder Scrolls).']],
    ];

    $noteAboutPlayer = $extdata['background_life_player_unattached']
        ? ""
        : "Important note: {$GLOBALS['PLAYER_NAME']} and {$GLOBALS['HERIKA_NAME']} are NOT in the same place after the <context_history> events.";


    $userPrompts = [
        'en' => <<<PROMPT_EN
The main character in this logbook is {$GLOBALS['HERIKA_NAME']}.
Read the context history (context_history) and the recent memories (middle_term_memory),
paying attention to notable events and the names of relevant characters.

Also be aware of the rumors as they can affect the character's state of mind and decisions. 

Based on all this information, generate an inner-thought soliloquy for {$GLOBALS['HERIKA_NAME']}.
Take into account the <speech_style> section for the writing style, and particularly
<inner_thought_guidance> if present.

This soliloquy should reflect what the character might have done over the last {$hoursPassed} hours(s), 
and after last inner thoughts presented in the <context_history>:

* Intimate thoughts.
* Evolution of the character's state of mind based on latest inner thoughts (if any) and events.
* Consider the character's goals, desires, and motivations. Give special attention to <background_life_goals> when present; <goals> contains their general motivations.
* Short (2 paragraphs max), concise, and focused on the character's perspective.
$innerThoughtEnforceSocialice

Always respect the character's last known location. If the character is in a specific place,
generated content should occur in that area or its surroundings. The character may express the
intention to travel elsewhere, but such travel should only be described as an immediate plan. (e.g. I'm going to)


$noteAboutPlayer

Write in English as if you were {$GLOBALS['HERIKA_NAME']}, in a soliloquy, speaking to yourself
in first person.

PROMPT_EN,
    ];

    $step1Prompt = array_merge($systemPrompts[$lang], [
        ['role' => 'user', 'content' => "<character_sheet>\n{$GLOBALS['HERIKA_NAME']}:\n$dynamicBiography\n</character_sheet>", "cache_control" => ["type" => "ephemeral"]],
        ['role' => 'user', 'content' => "<context_history>\nContext History (chronological order)\n$history\n</context_history>{$postHistory}\n{$lastMinuteNotes}", "cache_control" => ["type" => "ephemeral"]],
        ['role' => 'user', 'content' => $userPrompts[$lang], "cache_control" => ["type" => "ephemeral"]],
    ]);

    Logger::debug(__LINE__ . ' ' . (microtime(true) - $startTime));

    $recordInnerThoughts = true;

    if (!$isSpeakAction) {
        // If last action was not SpeakTo, we generate inner thoughts. If it was SpeakTo, we skip this step to avoid redundant inner thoughts.

        if ($bypassInnerThoughts == false) {
            $connectionHandler = $connector->getConnector($currentConnectorData);
            $innerThoughtBuffer = $connectionHandler->fast_request($step1Prompt, ['MAX_TOKENS' => 1024], 'backgroundlife');
            updateLastLLMCall($GLOBALS['HERIKA_NAME']);
            $recordDiaryEntry = true;
        } else {
            $innerThoughtBuffer = $innerThoughtBufferForced ?? "{$GLOBALS['HERIKA_NAME']}'s inner thought: I've reached destination, I must figure out my next action";
            $recordInnerThoughts = false;
            $recordDiaryEntry = false;
        }
    } else {
        //If last action was SpeakTo, we skip this step to avoid redundant inner thoughts.
        $innerThoughtBuffer = "{$GLOBALS['HERIKA_NAME']}'s inner thought: I must figure out my next action";
        $recordInnerThoughts = false;
        $recordDiaryEntry = false;
    }

    return $innerThoughtBuffer;
}

function requestForaction(
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
    $npcNameEsc,
    $db,
    $startGamets,
    $last_gamets,
): string {
    $step2Content = "You are responsible for deciding a single action"
        . " based on the character's inner thoughts and the provided context.\n"
        . "Character's name is {$GLOBALS['HERIKA_NAME']}.\n"
        . "$dynamicBiography\n\n";

    if ($isFullMode) {
        $step2Content .= "<context_history>\nContext History (chronological order)\n$historyShort\n</context_history>{$postHistory} {$lastMinuteNotes}\n\n";
    }

    $lastActions = $db->fetchAll("SELECT fullcall,gamets FROM actions_issued where actorname='$npcNameEsc' and gamets>$startGamets and original='backgroundaction' order by gamets desc limit 20");
    $lastActionsSummary = [];
    foreach ($lastActions as $action) {
        $actionParts = explode(':', $action['fullcall']);
        $hoursAgo = number_format(($last_gamets - $action['gamets']) * GAMETS_TO_HOURS, 2);
        $lastActionsSummary[$action['gamets']] = "$actionParts[0] $actionParts[1] ($hoursAgo hours ago)";
    }
    
    $step2Content .= "<text>\nLast actions issued:\n" . implode("\n", array_reverse($lastActionsSummary)) . "\n</text>\n\n";
    $step2Content .= "<text>\n$innerThoughtBuffer\n</text>\n\n";
    $step2Content .= $innerThoughtStyle . "\n\n";


    $step2Content .= <<<PROMPT
Choose exactly **one** action for this turn.

Decision rules (highest priority first):

1. Continue an unfinished action (travel, transaction, meeting, etc.) whenever appropriate.
2. If the NPC has an active goal, choose the action that makes the most progress toward that goal.
3. Avoid unnecessary movement or repetitive conversations.
4. Do not invent information that is not present in the context.

Available actions:

StayAtPlace:<Place>:<intent>
- intent can be: Work, Rest, Relax, Socialize, Sleep, Study, Guard.
- Remain at the current location to work, rest, relax, socialize, or perform ongoing activities.
- This is the default action when the NPC should remain where they are.
- At an inn: rest, relax, socialize with patrons. E.G StayAtPlace:Inn:Relax, StayAtPlace:Inn:Socialize (Socialize is preferred if there are other NPCs present)
- At home: rest, relax, socialize with companions,sleep. e.g StayAtPlace:Breezehome:Sleep
- If gathering information or spreading rumors, remain for at least 24 hours.
- After arriving somewhere, prefer interacting (SpeakTo, BuyItem, SellItem, SellService) before choosing StayAtPlace again, unless there is no meaningful interaction available.

FindNPC:<NPC name>
- Search for an NPC whose current location is unknown.
- Use before MoveTo or SpeakTo when the target's location is unknown.
- Requires a clear reason.

MoveTo:<NPC name>
- Move to an NPC whose current location is already known.
- Only use for characters, never for places.
- Requires a clear reason.
PROMPT;

    if ($spreadRumorsAvailable) {
        $step2Content .= <<<PROMPT


SpreadRumors:rumor
- Spread a rumor within the NPC's social circle or the local community.
PROMPT;
    }

    if (!$isSpeakAction) {
        $step2Content .= <<<PROMPT


SpeakTo:<NPC name>:<npc_refid>
- Start a conversation with another NPC (should be nearby - check last <nearby_npcs> list-).
- Avoid selecting SpeakTo repeatedly with no new purpose.
- Prefer conversations that advance goals, exchange information, negotiate, or socialize.
PROMPT;
    } else {
        error_log(date("YMd H:i:s") . " [BGL RUN] HINT $npcNameEsc — last action was SpeakTo, skipping SpeakTo in available actions.");
    }


    if (!isset($extdata['background_life_player_unattached']) || $extdata['background_life_player_unattached'] == false) {
        $returnHomeAction = "ReturnHome
- Return to the base location to meet {$GLOBALS['PLAYER_NAME']}.
- Use only after all current goals have been completed.";
    } else
        $returnHomeAction = "";

    // Needs to be worked. We need to define a "home". Moving to player (current ReturnHome implementation, is not a general case)
    $returnHomeAction = "";

    if (!$bypassTradingActions) {
        $step2Content .= <<<PROMPT

BuyItem:<NPC name>:<itemid>:<count>:<total_gold_spent>,<NPC name>:<itemid>:<count>:<total_gold_spent>
- Buy items from another NPC.
- Required after a previously agreed trade so inventories can be updated.
- total_gold_spent is <item price>*<count>, the total amount of gold spent for that item, including any haggling or discounts.

SellItem:<NPC name>:<itemid>:<count>:<total_gold_amount>,<NPC name>:<itemid>:<count>:<total_gold_amount>,...
- Sell items to another NPC.
- Required after a previously agreed trade so inventories can be updated.
- total_gold_amount is <item price>*<count>, the total amount of gold received for that item, including any haggling or discounts (price*count).

GiveItemTo:<NPC name>:<itemid>:<count>,<NPC name>:<itemid>:<count>
- Give items directly to one or more NPCs with no gold exchange.
- Use this for gifts, aid, or non-commercial handoffs.
- Item should be on {$GLOBALS["HERIKA_NAME"]}'s inventory.
- E.G. If you want to invite someone to a drink, you must have the drink item in your inventory. If not, buy it from an innkeeper or trader first, then give it to the NPC.

GiveGoldTo:<NPC name>:<gold_amount>,<NPC name>:<gold_amount>
- Give gold directly to one or more NPCs.
- Use this for gifts, donations, payments, or helping allies where only gold should be transferred.

SellService:<NPC name>:<service_description>:<total_gold_amount>,<NPC name>:<service_description>:<total_gold_amount>
- Sell a service to another NPC. No inventory item is moved; only gold changes hands.
- The service_description is a short label (e.g. 'healing', 'repair', 'lockpicking', 'mercenary work') describing what was provided.
- total_gold_amount is the full price paid by the buyer for the service.
PROMPT;
    }
    $step2Content .= <<<PROMPT2

TravelTo:<Place>
- Travel to another location.
- Use only when the destination is different from the current location and travel is necessary.

$returnHomeAction
PROMPT2;

    // SendLetter action is only available if the background_life_letters feature is enabled in the extended data.
    if (isset($extdata['background_life_letters']) && $extdata['background_life_letters'] == true) {

        $step2Content .= "
SendLetter
- Send a letter to {$GLOBALS["PLAYER_NAME"]}.
";
    }

    if ($npcIsTravelling) {
        $step2Content .= <<<PROMPT3
Continue
- Continue executing the previously selected action.
- Prefer this while travelling unless there is a compelling reason to interrupt or change destination.

Note:
{$GLOBALS['HERIKA_NAME']} is already travelling. Do NOT issue another TravelTo action unless the destination must change.

PROMPT3;
    }


    // Hinter

    if (
        (strtolower($lastIssuedBgEvent["name"]) == "sandbox" && $lastIssuedBgEvent["event"] == "start" && $npcIsTravelling)
        || (strtolower($lastIssuedBgEvent["name"]) == "travelto" && $lastIssuedBgEvent["event"] == "end" && $npcIsTravelling)
    ) {

        // Last action was MoveTo or TravelTo.
        // Last event was a Sandbox event. This means the NPC reached destination
        // 
        $actionChoiceDesc = "Hint: Character just reached destination. Preferred actions should be:
    * SpeakTo (talk with another nearby character)
    * FindNPC (if wanting to talk to a specific character and is not present)
    * TravelTo (keeps moving if current location is not the final destination)";
    } else {
        if ($isSpeakAction) {
            $actionChoiceDesc = "Hint: The character has just completed a conversation. Analyze the dialogue outcome first. If there is an unresolved transaction, continue it by choosing the appropriate action: BuyItem, SellItem, SellService, or GiveItemTo.
If no transaction is pending, review the character's active goals and select the action that provides the highest progress toward achieving them.";
        } else {
            $actionChoiceDesc = "";
        }
    }

    $step2Content .= "$actionChoiceDesc\n"
        . "\nElement Definitions:\n```\n"
        . "```\n\n"
        . "- Your answer must use XML format, containing exactly 2 elements.\n"
        . "- NEVER include commentary inside or outside the element tags or ANY content beyond the defined format.\n\n"
        . "Use only this exact Response Format:\n```\n"
        . "<action> ... </action>\n"
        . "<reason> ... </reason>\n"
        . "```";

    $step2Content .= "Example: ```\n\n"
        . "<action>FindNPC:Adrianne Avenicci</action>\n"
        . "<reason>I need to find Adrianne to speak to her</reason>\n"
        . "```";
    if (!$isSpeakAction) {
        $step2Content .= "Examples ```\n\n"
            . "<action>SpeakTo:Adrianne Avenicci:0001A67C</action>\n"
            . "<reason>I need to speak to Adrianne Avenicci to progress in my current objectives.</reason>\n"
            . "```";

        if ($spreadRumorsAvailable) {
            $step2Content .= "Examples ```\n\n"
                . "<action>SpreadRumors:The Jarl's steward is secretly buying forbidden relics.</action>\n"
                . "<reason>I want this rumor to circulate and influence local opinion.</reason>\n"
                . "```";
        }
    }

    if (!$bypassTradingActions) {
        $step2Content .= "Examples ```\n\n"
            . "<action>BuyItem:Adrianne Avenicci:000721E8:1:5,Adrianne Avenicci:00065C97:2:16</action>\n"
            . "<reason>I agreed to buy two items from Adrianne Avenicci, 1 Cooked Beef (5 gold), 2 Bread (16 gold)</reason>\n"
            . "```";

        $step2Content .= "Examples ```\n\n"
            . "<action>GiveGoldTo:Lucan Valerius:25</action>\n"
            . "<reason>I want to support Lucan Valerius with some gold.</reason>\n"
            . "```";

        $step2Content .= "Examples ```\n\n"
            . "<action>SellService:Lucan Valerius:repair:50</action>\n"
            . "<reason>I repaired Lucan's lock for 50 gold.</reason>\n"
            . "```";
    }
    $step2Content .= "
Rules:
- Only ONE action may be chosen per round.
- The action must be consistent with the context_history, memories, and current location.
- Previous actions are present at the context_history, prevent repetition, use previous actions on history to figure out if main goal is achieved or not, and decide accordingly.
For example:
* To Sell/Buy Item to a trader: SpeakTo:<NPC/Actor name> ->(next iteration) SellItem:.. 
* To Sell/Buy Item to a trader that maybe is not present: MoveTo:<NPC/Actor name> ->(next iteration) SpeakTo:<NPC/Actor name> ->(next iteration) SellItem:.. 
* To gift items without taking money: SpeakTo:<NPC/Actor name> ->(next iteration) GiveItemTo:<NPC/Actor name>:<itemid>:<count>
* To give money without trading items: SpeakTo:<NPC/Actor name> ->(next iteration) GiveGoldTo:<NPC/Actor name>:<amount>
* To sell a service: SpeakTo:<NPC/Actor name> ->(next iteration) SellService:<NPC/Actor name>:<service_description>:<amount>
* Buy food at an inn: SpeakTo:<NPC innkeeper> ... ->(next iteration),BuyItem:<NPC/Actor name>... ->(next iteration) StayAtPlace:Inn ...
* Relax/Socialize at an inn: SpeakTo:<NPC/Actor name> ->(next iteration) ->(next iteration) StayAtPlace:Inn 
* Relax at home: SpeakTo:<NPC/Actor name> ->(next iteration) StayAtPlace:Home:Sleep
* Generally speaking, try to Speak to an NPC before trading with him/her, unless the NPC is not present. If the NPC is not present, use MoveTo:<NPC name> to reach him/her first.



";

    $step2Prompt = [['role' => 'system', 'content' => $step2Content]];
    $connectionHandler = $connector->getConnector($currentConnectorData);
    $decisionBuffer = $connectionHandler->fast_request($step2Prompt, ['MAX_TOKENS' => 2048], 'backgroundlife');
    updateLastLLMCall($GLOBALS['HERIKA_NAME']);

    return $decisionBuffer;
}
?>