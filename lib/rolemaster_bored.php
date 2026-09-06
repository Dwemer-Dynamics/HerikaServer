<?php

// Keep bored-event probability consistent across direct, narrator, and Rolemaster routes.
function chimBoredEventChancePasses(int $chance, int $roll): bool
{
    $chance = max(0, min(100, $chance));
    if ($roll < 0 || $roll > 99) {
        return false;
    }

    return $roll < $chance;
}

function chimBoredEventCaptureActivitySnapshot($db): array
{
    $row = $db->fetchOne(
        "SELECT
             COALESCE((SELECT MAX(rowid) FROM eventlog), 0) AS event_rowid,
             COALESCE((SELECT MAX(rowid) FROM speech), 0) AS speech_rowid"
    );

    if (
        !is_array($row)
        || !array_key_exists('event_rowid', $row)
        || !array_key_exists('speech_rowid', $row)
        || !is_numeric($row['event_rowid'])
        || !is_numeric($row['speech_rowid'])
    ) {
        throw new RuntimeException('Unable to capture a valid bored activity snapshot');
    }

    return [
        'event_rowid' => max(0, intval($row['event_rowid'])),
        'speech_rowid' => max(0, intval($row['speech_rowid'])),
    ];
}

function chimBoredEventFindSupersedingActivity($db, array $snapshot): ?array
{
    $eventRowId = max(0, intval($snapshot['event_rowid'] ?? 0));
    $speechRowId = max(0, intval($snapshot['speech_rowid'] ?? 0));
    $row = $db->fetchOne(
        "SELECT activity.activity_rowid, activity.activity_type
         FROM (VALUES (1)) AS guard(dummy)
         LEFT JOIN LATERAL (
             SELECT activity_rowid, activity_type
             FROM (
                 SELECT rowid AS activity_rowid, type AS activity_type
                 FROM eventlog
                 WHERE rowid > {$eventRowId}
                   AND (
                       type = 'user_input'
                       OR (
                           type = 'chat'
                           AND COALESCE(delivery_state, 'spoken') IN ('emitted', 'spoken')
                       )
                   )
                 UNION ALL
                 SELECT rowid AS activity_rowid, 'speech' AS activity_type
                 FROM speech
                 WHERE rowid > {$speechRowId}
             ) AS superseding_activity
             LIMIT 1
         ) AS activity ON TRUE"
    );

    if (
        !is_array($row)
        || !array_key_exists('activity_rowid', $row)
        || !array_key_exists('activity_type', $row)
    ) {
        throw new RuntimeException('Unable to validate the bored activity snapshot');
    }

    if ($row['activity_rowid'] === null && $row['activity_type'] === null) {
        return null;
    }

    if (!is_numeric($row['activity_rowid']) || trim((string)$row['activity_type']) === '') {
        throw new RuntimeException('Received an invalid bored activity validation result');
    }

    return [
        'rowid' => intval($row['activity_rowid']),
        'type' => trim((string)$row['activity_type']),
    ];
}

function chimRolemasterBoredActorKey(string $actorName): string
{
    return function_exists('mb_strtolower')
        ? mb_strtolower($actorName, 'UTF-8')
        : strtolower($actorName);
}

function chimRolemasterBoredActorMap(string $actorsInRange, string $playerName = '', string $seedActor = ''): array
{
    $actors = [];
    foreach (preg_split('/[|\/]/', $actorsInRange) ?: [] as $actor) {
        $actor = trim($actor);
        if ($actor === '' || ($playerName !== '' && strcasecmp($actor, $playerName) === 0)) {
            continue;
        }
        $actors[chimRolemasterBoredActorKey($actor)] = $actor;
    }

    $seedActor = trim($seedActor);
    if ($seedActor !== '' && ($playerName === '' || strcasecmp($seedActor, $playerName) !== 0)) {
        $actors[chimRolemasterBoredActorKey($seedActor)] = $seedActor;
    }

    return $actors;
}

function chimRolemasterBoredCanonicalActor(string $actorName, array $actorMap): ?string
{
    $actorName = trim($actorName);
    if ($actorName === '') {
        return null;
    }

    return $actorMap[chimRolemasterBoredActorKey($actorName)] ?? null;
}

function chimRolemasterFilterBoredInstructions(array $instructions, array $actorMap, string $seedActor): array
{
    $seedInstruction = null;

    foreach ($instructions as $instruction) {
        if (!is_array($instruction)) {
            continue;
        }

        $canonicalActor = chimRolemasterBoredCanonicalActor((string)($instruction['character'] ?? ''), $actorMap);
        if ($canonicalActor === null) {
            continue;
        }

        $instruction['character'] = $canonicalActor;
        if ($seedActor !== '' && strcasecmp($canonicalActor, $seedActor) === 0 && $seedInstruction === null) {
            $seedInstruction = $instruction;
        }
    }

    return $seedInstruction === null ? [] : [$seedInstruction];
}

function chimRolemasterBoredListenerRequirement(string $target, array $actorMap): string
{
    $canonicalTarget = chimRolemasterBoredCanonicalActor($target, $actorMap);
    if ($canonicalTarget === null) {
        return '';
    }

    return " The dialogue listener must be {$canonicalTarget}.";
}

function chimRolemasterDefaultBoredSystemPrompt(): string
{
    return <<<'PROMPT'
You coordinate one spontaneous, grounded NPC moment in the current Skyrim scene. Use the supplied context to give the selected initiating actor one brief third-person instruction. Do not introduce a plot development, quest, dramatic turn, foreshadowing, or manufactured tension. Do not write the final dialogue; the selected actor's own model will produce the in-character response. Return only the requested JSON object.
PROMPT;
}

function chimRolemasterDefaultBoredEventRules(): string
{
    return <<<'PROMPT'
# Bored event rules
{SEED_ACTOR_RULE}
* Only use speakers from this nearby eligible actor list: {NEARBY_ACTORS}.
* Do not invent distant or off-scene actors.
* Let the instruction arise naturally from recent events or conversations, the present surroundings, mood, fatigue, curiosity, or an ordinary personal thought.
* The instruction does not need to introduce a new topic or advance the plot. Brief, personal, playful, tired, curious, or mundane dialogue is valid.
* Do not use poetic, philosophical, or atmospheric wording merely to make the moment feel meaningful. In danger or emotional tension, keep the dialogue brief, cautious, and appropriate to the situation.
* Do not target or comment on {PLAYER_NAME} merely because time passed or the player is idle.
* Prefer a natural NPC-to-NPC interaction or scene action. Involve the player only when recent player activity clearly requires a response.
* When an instruction targets another nearby actor, direct the dialogue to that actor.
* Do not generate the listener's reply. Normal dialogue routing will let the listener respond after the initiating actor speaks.
* Prefer JustTalk unless a different available action follows naturally from the current situation.
* Available actions:
{FUNCTION_LIST}
  ** JustTalk
* Keep the scene note brief and factual. It should clarify the immediate interaction, not add atmosphere or future plot.
PROMPT;
}

function chimRolemasterRenderBoredEventRules(
    string $template,
    string $seedActor,
    string $playerName,
    array $actorMap,
    string $functionList = ''
): string {
    if (trim($template) === '') {
        $template = chimRolemasterDefaultBoredEventRules();
    }

    $seedActor = trim($seedActor);
    $seedActorRule = $seedActor === ''
        ? ''
        : "* Return exactly one instruction, using the selected initiating actor: {$seedActor}.";
    $nearbyActors = implode(', ', array_values($actorMap));

    $rendered = str_replace(
        ['{SEED_ACTOR_RULE}', '{SEED_ACTOR}', '{NEARBY_ACTORS}', '{PLAYER_NAME}', '{FUNCTION_LIST}'],
        [$seedActorRule, $seedActor, $nearbyActors, $playerName, $functionList],
        $template
    );

    return trim((string)preg_replace("/\n{3,}/", "\n\n", $rendered));
}
