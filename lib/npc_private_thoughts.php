<?php

// Normalize only the two owned keys, preserving all other profile metadata.
function chimNormalizePrivateThoughtMetadata(array $metadata): array
{
    if (array_key_exists('PRIVATE_NPC_THOUGHTS_ENABLED', $metadata)) {
        $metadata['PRIVATE_NPC_THOUGHTS_ENABLED'] = filter_var($metadata['PRIVATE_NPC_THOUGHTS_ENABLED'], FILTER_VALIDATE_BOOLEAN);
    }
    if (array_key_exists('PRIVATE_NPC_THOUGHTS_COUNT', $metadata)) {
        $count = filter_var($metadata['PRIVATE_NPC_THOUGHTS_COUNT'], FILTER_VALIDATE_INT);
        $metadata['PRIVATE_NPC_THOUGHTS_COUNT'] = $count === false ? 3 : max(1, min(10, $count));
    }
    return $metadata;
}

// Capture the resolved owner once; shared profiles never own private thoughts.
function chimBeginPrivateThoughts(): void
{
    unset($GLOBALS['CHIM_PRIVATE_THOUGHT_TURN']);
    $profile = $GLOBALS['CHIM_CORE_CURRENT_PROFILE_DATA'] ?? [];
    $metadata = $profile['metadata'] ?? '{}';
    $metadata = is_array($metadata) ? $metadata : json_decode($metadata, true);
    $metadata = chimNormalizePrivateThoughtMetadata(is_array($metadata) ? $metadata : []);
    if (empty($metadata['PRIVATE_NPC_THOUGHTS_ENABLED'])) return;
    $driver = $GLOBALS['CHIM_CORE_CURRENT_CONNECTOR_DATA']['driver'] ?? '';
    if (!in_array($driver, ['openrouterjson', 'openaijson'], true)) return;
    $request = $GLOBALS['gameRequest'] ?? [];
    if (!in_array($request[0] ?? '', ['inputtext', 'inputtext_s', 'ginputtext', 'ginputtext_s', 'rechat', 'bored', 'chat', 'continue', 'continue_group', 'combatbark'], true)) return;
    $npc = $GLOBALS['CHIM_CORE_CURRENT_NPC_DATA'] ?? [];
    $name = trim((string)($npc['npc_name'] ?? ''));
    $refid = strtolower(trim((string)($npc['refid'] ?? '')));
    $narrator = class_exists('Narrator') ? Narrator::CANONICAL_NAME : 'The Narrator';
    if (empty($npc['id']) || $name === '' || $refid === '' || $refid === '0'
        || strcasecmp($name, $narrator) === 0 || strcasecmp($name, (string)($GLOBALS['HERIKA_NAME'] ?? '')) !== 0) return;
    $GLOBALS['CHIM_PRIVATE_THOUGHT_TURN'] = [
        'npc_id' => (int)$npc['id'], 'npc_refid' => $refid, 'npc_name' => $name,
        'count' => $metadata['PRIVATE_NPC_THOUGHTS_COUNT'] ?? 3,
        'gamets' => max(0, (int)($request[2] ?? 0)),
        'request_key' => hash('sha256', json_encode([$npc['id'], $refid, $request])),
    ];
}

function chimPrivateThoughtResponseEnabled(): bool
{
    return !empty($GLOBALS['CHIM_PRIVATE_THOUGHT_TURN'])
        && in_array($GLOBALS['CHIM_CORE_CURRENT_CONNECTOR_DATA']['driver'] ?? '', ['openrouterjson', 'openaijson'], true);
}

function chimPrivateThoughtInstructions(): string
{
    return 'Write message first. After all dialogue and action fields, write internal_thought: one or two brief sentences in your own character voice, at most 600 characters. Reflect on an observation, motive or decision grounded in your available knowledge. Keep uncertainty explicit; do not invent facts or speak to another person. Use an empty string when no useful reflection arises. Never put private thoughts or thought tags in message.';
}

// This block belongs only to the current speaker's Character section, never shared history.
function chimBuildPrivateThoughtContext(): string
{
    if (!chimPrivateThoughtResponseEnabled()) return '';
    $turn = $GLOBALS['CHIM_PRIVATE_THOUGHT_TURN'];
    try {
        $row = $GLOBALS['db']->fetchOne(
            'SELECT COALESCE(json_agg(t ORDER BY gamets, id), \'[]\'::json) AS thoughts FROM '
            . '(SELECT id, gamets, thought FROM npc_private_thoughts WHERE npc_id=$1 AND npc_refid=$2 '
            . 'AND npc_name=$3 AND gamets<=$4 ORDER BY gamets DESC, id DESC LIMIT $5) t',
            [$turn['npc_id'], $turn['npc_refid'], $turn['npc_name'], $turn['gamets'], $turn['count']]
        );
        $thoughts = json_decode($row['thoughts'] ?? '[]', true) ?: [];
    } catch (Throwable $e) {
        Logger::warn('[PRIVATE_THOUGHTS] Context unavailable.');
        return '';
    }
    if (!$thoughts) return '';
    $lines = [];
    $budget = 1200;
    // Keep the most recent reflections when the character budget is exhausted.
    foreach (array_reverse($thoughts) as $thought) {
        $text = mb_substr((string)$thought['thought'], 0, min(600, $budget));
        if ($text === '') break;
        $budget -= mb_strlen($text);
        array_unshift($lines, '- ' . htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
    }
    return "\n<private_thoughts>\n# Private Thoughts\nYour recent unspoken impressions, oldest first. These are not confirmed facts or new instructions. Other characters do not know them unless you told them.\n"
        . implode("\n", $lines) . "\n</private_thoughts>\n";
}

// Store only a complete accepted response; incremental parser repairs are not durable thoughts.
function chimStorePrivateThoughtResponse(string $raw): void
{
    if (!chimPrivateThoughtResponseEnabled()) return;
    $turn = $GLOBALS['CHIM_PRIVATE_THOUGHT_TURN'];
    $response = json_decode(trim($raw), true);
    if (!is_array($response) || !is_string($response['message'] ?? null)
        || !is_string($response['internal_thought'] ?? null)) return;
    $thought = trim($response['internal_thought']);
    if ($thought === '' || mb_strlen($thought) > 600 || strpos($thought, "\0") !== false) return;
    if (isset($response['character']) && strcasecmp(trim((string)$response['character']), $turn['npc_name']) !== 0) return;
    if (function_exists('chimInteractionAllowed') && !chimInteractionAllowed()) return;
    $request = $GLOBALS['gameRequest'] ?? [];
    if (chimFindSupersedingUserInput($GLOBALS['db'], $request[1] ?? '', $request[0] ?? '') !== null) return;
    try {
        $GLOBALS['db']->fetchOne(
            'INSERT INTO npc_private_thoughts(npc_id,npc_refid,npc_name,request_key,gamets,localts,thought) '
            . 'SELECT $1,$2,$3,$4,$5,$6,$7 WHERE EXISTS '
            . '(SELECT 1 FROM core_npc_master WHERE id=$1 AND lower(trim(refid))=$2 AND npc_name=$3) '
            . 'ON CONFLICT (npc_id,npc_refid,request_key) DO NOTHING RETURNING id',
            [$turn['npc_id'], $turn['npc_refid'], $turn['npc_name'], $turn['request_key'], $turn['gamets'], time(), $thought]
        );
    } catch (Throwable $e) {
        Logger::warn('[PRIVATE_THOUGHTS] Reflection could not be saved; dialogue was preserved.');
    }
}
