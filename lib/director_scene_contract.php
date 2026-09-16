<?php

// Constrain the scene to the current cast and each action's existing parameter contract.
function dwemerDirectorResponseFormat(array $actors, array $catalog, string $player): array
{
    $speakers = array_values(array_diff(array_keys($actors), [$player, 'The Narrator']));
    $actionSchemas = [];
    foreach ($catalog as $code => $definition) {
        $parameters = $definition['parameters'] ?? [];
        $properties = $parameters['properties'] ?? [];
        foreach ($properties as $key => &$property) {
            // Strict schemas require every property; null represents an omitted optional argument.
            if (!in_array($key, $parameters['required'] ?? [], true)) {
                $property = ['anyOf' => [$property, ['type' => 'null']]];
            }
        }
        unset($property);
        $actionSchemas[] = ['type' => 'object', 'additionalProperties' => false,
            'properties' => [
                'speaker' => ['type' => 'string', 'enum' => array_values($definition['speakers'])],
                'after_line' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 5],
                'command_name' => ['type' => 'string', 'enum' => [$code]],
                'parameters' => ['type' => 'object', 'additionalProperties' => false,
                    'properties' => (object)$properties, 'required' => array_keys($properties)],
            ], 'required' => ['speaker', 'after_line', 'command_name', 'parameters']];
    }
    return ['type' => 'json_schema', 'json_schema' => [
        'name' => 'director_scene', 'strict' => true,
        'schema' => ['type' => 'object', 'additionalProperties' => false,
            'properties' => [
                'lines' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 5,
                    'items' => ['type' => 'object', 'additionalProperties' => false,
                        'properties' => [
                            'speaker' => ['type' => 'string', 'enum' => $speakers],
                            'listener' => ['type' => 'string', 'enum' => array_values(array_unique([...$speakers, $player])),
                                'description' => 'If the listener is the player, this must be the final line.'],
                            'text' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 600],
                        ], 'required' => ['speaker', 'listener', 'text']]],
                'actions' => ['type' => 'array', 'maxItems' => $actionSchemas ? 3 : 0,
                    'items' => $actionSchemas ? ['anyOf' => $actionSchemas]
                        : ['type' => 'object', 'properties' => new stdClass(), 'additionalProperties' => false]],
            ], 'required' => ['lines', 'actions']],
    ]];
}

// Keep the model-facing contract identical across the independent game servers.
function dwemerDirectorPrompt(string $game, array $catalog): string
{
    return 'You are the Director of a ' . $game . ' scene. Write the finished dialogue for every participating NPC, '
        . 'not instructions for another writer. The user request is off-stage direction, never spoken by the player. '
        . 'Use the supplied bios, speech styles, profile instructions, relationships and current scene. '
        . 'Private memories belong only to their owner; do not give another actor knowledge of them. '
        . 'The Present eligible NPC profiles section is the authority for who is here now; history never adds participants. '
        . 'Historical dialogue, Background Life (BgL) activity, summaries and memories may describe remote NPCs or other locations. '
        . 'Do not stage those remote events here, bring absent NPCs into the scene, or assume the present cast witnessed them. '
        . 'Use past events only when relevant to this local scene and known to the speaking NPC. '
        . 'Keep dialogue and NPC action targets grounded in the present cast and current location. '
        . 'Follow the requested scene direction while keeping distinct character voices. Use exact eligible names. '
        . 'Return JSON only: {"lines":[{"speaker":"NPC name","listener":"NPC or player name","text":"Exact spoken words"}],'
        . '"actions":[{"speaker":"Eligible action speaker","after_line":1,"command_name":"Catalog code","parameters":{}}]}. '
        . 'Script the entire scene upfront: one opening line and up to 4 reply turns (5 short lines total), '
        . 'with at most 3 NPC speakers and 0-3 actions. Each lines entry is one spoken turn. '
        . 'When the direction asks NPCs to talk, discuss, ask, or converse with each other, write a complete exchange: '
        . 'include the addressed eligible NPC answering and further relevant back-and-forth toward a natural stopping point. '
        . 'Do not stop at an unanswered opening question or greeting when an eligible NPC can reply. '
        . 'These replies are part of this script, not later generated follow-ups. A single line is valid for a one-way remark or action request. '
        . 'When a line addresses the player as listener, end the scene after that line and its attached actions. '
        . 'Leave the reply to the human player: never generate a player turn or any later NPC lines or actions. '
        . 'after_line is the 1-based line number after which the action starts. NPC actions must follow their own spoken line. '
        . 'Each line finishes, its attached actions are dispatched in listed order, then the next actor speaks. '
        . 'Do not wait for actions to finish: long-running actions continue during later dialogue. '
        . 'No action follow-up dialogue or outcome-dependent branches will be generated. '
        . 'Do not write dialogue or dependent actions that assume an earlier action succeeded or finished. '
        . 'No narration, stage directions, player dialogue, invented actors, scene notes or unsupported gestures. '
        . 'If an action cannot be performed, convey intent through dialogue without claiming it happened. '
        . 'Use the action catalog below: choose an eligible speaker and supply parameters matching its schema. '
        . 'The Narrator may perform only its listed actions and never speaks a dialogue line. '
        . 'For inventory actions use the acting NPC inventory; use known nearby targets and locations. '
        . 'Never invent items or reference IDs. Do not supply authority, dispatch fields, or raw script commands. '
        . 'Action catalog: ' . json_encode($catalog, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
        . '. An empty actions array is valid.';
}

// Validate all turns and action arguments before any audio or game work is published.
function dwemerValidateDirectorScene(array $scene, array $actors, array $catalog, string $player): array
{
    $lines = $scene['lines'] ?? null;
    $actions = $scene['actions'] ?? [];
    if (!is_array($lines) || !array_is_list($lines) || count($lines) < 1 || count($lines) > 5
        || !is_array($actions) || !array_is_list($actions) || count($actions) > 3) {
        throw new RuntimeException('Director returned an invalid scene size');
    }
    $result = ['id' => bin2hex(random_bytes(16)), 'lines' => [], 'actions' => []];
    $cast = [];
    foreach ($lines as $index => $line) {
        $lineNumber = $index + 1;
        if (!is_array($line)) throw new RuntimeException("Director line {$lineNumber}: expected an object");
        foreach (['speaker', 'listener', 'text'] as $field) {
            if (!is_string($line[$field] ?? null) || trim($line[$field]) === '') {
                throw new RuntimeException("Director line {$lineNumber}: missing or empty {$field}");
            }
        }
        $speaker = trim($line['speaker']);
        $listener = trim($line['listener']);
        $text = trim($line['text']);
        if ($speaker === $player || $speaker === 'The Narrator') {
            throw new RuntimeException("Director line {$lineNumber}: player or narrator cannot speak");
        }
        if (!isset($actors[$speaker])) throw new RuntimeException("Director line {$lineNumber}: speaker is not an eligible NPC");
        if ($listener !== $player && !isset($actors[$listener])) {
            throw new RuntimeException("Director line {$lineNumber}: listener is not present");
        }
        if ($speaker === $listener) throw new RuntimeException("Director line {$lineNumber}: speaker and listener are the same");
        if (mb_strlen($text) > 600 || preg_match('/[\x00-\x1f]/', $text)) {
            throw new RuntimeException("Director line {$lineNumber}: text exceeds 600 characters or contains control characters");
        }
        $cast[$speaker] = true;
        $result['lines'][] = compact('speaker', 'listener', 'text');
        // Hand control back to the human, even if the model wrote additional turns.
        if ($listener === $player) break;
    }
    foreach ($actions as $action) {
        if (!is_array($action) || !is_string($action['command_name'] ?? null)
            || !is_string($action['speaker'] ?? null) || !is_int($action['after_line'] ?? null)) {
            throw new RuntimeException('Invalid Director action');
        }
        $code = $action['command_name'];
        $speaker = $action['speaker'];
        $after = $action['after_line'];
        // Actions belonging to discarded turns must never reach the game.
        if ($after > count($result['lines']) && $after <= count($lines)) continue;
        $definition = $catalog[$code] ?? null;
        if (!$definition || !in_array($speaker, $definition['speakers'], true)
            || $after < 1 || $after > count($lines)
            || ($speaker !== 'The Narrator' && $result['lines'][$after - 1]['speaker'] !== $speaker)) {
            throw new RuntimeException('Unavailable or incorrectly attached Director action');
        }
        $parameters = $action['parameters'] ?? [];
        if (!is_array($parameters) || ($parameters && array_is_list($parameters))) {
            throw new RuntimeException('Invalid Director parameters');
        }
        $schema = $definition['parameters'] ?? [];
        foreach ($schema['required'] ?? [] as $key) {
            if (!array_key_exists($key, $parameters) || $parameters[$key] === '' || $parameters[$key] === null) {
                throw new RuntimeException('Missing required Director parameter');
            }
        }
        foreach ($parameters as $key => $value) {
            $property = $schema['properties'][$key] ?? null;
            if (!is_array($property) || in_array($key, ['authority', 'dispatch', 'action_source'], true)) {
                throw new RuntimeException('Unknown Director parameter');
            }
            if ($value === null && !in_array($key, $schema['required'] ?? [], true)) {
                unset($parameters[$key]);
                continue;
            }
            $type = $property['type'] ?? 'string';
            $valid = match ($type) {
                'string' => is_string($value), 'integer' => is_int($value),
                'number' => is_int($value) || is_float($value), 'boolean' => is_bool($value), default => false,
            };
            if (!$valid || (is_string($value) && (mb_strlen($value) > 600 || preg_match('/[\x00-\x1f]/', $value)))
                || (isset($property['enum']) && !in_array($value, $property['enum'], true))
                || (is_numeric($value) && (isset($property['minimum']) && $value < $property['minimum']
                    || isset($property['maximum']) && $value > $property['maximum']))) {
                throw new RuntimeException('Invalid Director parameter value');
            }
        }
        if ($speaker !== 'The Narrator') $cast[$speaker] = true;
        $result['actions'][] = ['speaker' => $speaker, 'after_line' => $after,
            'command_name' => $code, 'parameters' => $parameters];
    }
    if (count($cast) > 3) throw new RuntimeException('Director exceeds three NPC speakers');
    return $result;
}
