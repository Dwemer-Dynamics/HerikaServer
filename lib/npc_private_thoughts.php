<?php

// Normalize the profile switch and retire the obsolete independent history limit.
function chimNormalizePrivateThoughtMetadata(array $metadata): array
{
    if (array_key_exists('PRIVATE_NPC_THOUGHTS_ENABLED', $metadata)) {
        $metadata['PRIVATE_NPC_THOUGHTS_ENABLED'] = filter_var($metadata['PRIVATE_NPC_THOUGHTS_ENABLED'], FILTER_VALIDATE_BOOLEAN);
    }
    unset($metadata['PRIVATE_NPC_THOUGHTS_COUNT']);
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
        'event_id' => 0,
        'gamets' => max(0, (int)($request[2] ?? 0)),
        'request_key' => hash('sha256', json_encode([$npc['id'], $refid, $request])),
    ];
}

function chimPrivateThoughtResponseEnabled(): bool
{
    return !empty($GLOBALS['CHIM_PRIVATE_THOUGHT_TURN'])
        && in_array($GLOBALS['CHIM_CORE_CURRENT_CONNECTOR_DATA']['driver'] ?? '', ['openrouterjson', 'openaijson'], true);
}

const CHIM_PRIVATE_THOUGHT_DEFAULT_PROMPT = 'Write message first. After all dialogue and action fields, write internal_thought: one or two brief sentences in your own character voice, at most 600 characters. Reflect on an observation, motive or decision grounded in your available knowledge. Keep uncertainty explicit; do not invent facts or speak to another person. Use an empty string when no useful reflection arises. Never put private thoughts or thought tags in message.';

// Resolve Prompt Manager edits on each generation; an empty override restores the default.
function chimPrivateThoughtInstructions(): string
{
    try {
        if (isset($GLOBALS['db'])) {
            $row = $GLOBALS['db']->fetchOne("SELECT custom_prompt, default_prompt FROM prompts WHERE prompt_key = 'private_npc_thoughts'");
            foreach (['custom_prompt', 'default_prompt'] as $field) {
                if (is_string($row[$field] ?? null) && trim($row[$field]) !== '') return $row[$field];
            }
        }
    } catch (Throwable $e) {
        Logger::warn('[PRIVATE_THOUGHTS] Managed prompt unavailable; using default instructions.');
    }
    return CHIM_PRIVATE_THOUGHT_DEFAULT_PROMPT;
}

// Resolve current profile policy for the requested reader, never a previous worker's globals.
function chimPrivateThoughtOwner(string $name, int $npcId = 0): ?array
{
    if ($name === '' || strcasecmp($name, 'The Narrator') === 0) return null;
    try {
        $row = $GLOBALS['db']->fetchOne(
            'SELECT json_agg(n) AS owners FROM (SELECT n.id,n.refid,n.npc_name,p.metadata '
            . 'FROM core_npc_master n JOIN core_profiles p ON p.id=n.profile_id '
            . 'WHERE n.npc_name=$1 AND ($2::integer=0 OR n.id=$2) LIMIT 2) n', [$name,$npcId]
        );
        $owners = json_decode($row['owners'] ?? '[]',true) ?: [];
        if (count($owners) !== 1) return null;
        $owner = $owners[0];
        $metadata = is_array($owner['metadata']) ? $owner['metadata'] : json_decode($owner['metadata'] ?? '{}',true);
        if (!filter_var($metadata['PRIVATE_NPC_THOUGHTS_ENABLED'] ?? false,FILTER_VALIDATE_BOOLEAN)) return null;
        return $owner;
    } catch (Throwable $e) {
        Logger::warn('[PRIVATE_THOUGHTS] Reader unavailable.');
        return null;
    }
}

// Format only metadata belonging to an already selected, visible dialogue event.
function chimPrivateThoughtAnnotation(array $event, ?array $owner, int $gamets): string
{
    if (!$owner || ($event['type'] ?? '') !== 'chat' || (int)($event['gamets'] ?? 0) > $gamets) return '';
    if (!in_array($event['delivery_state'] ?? 'spoken', ['spoken','emitted',''],true)) return '';
    $thought = $event['private_thought'] ?? null;
    $thought = is_array($thought) ? $thought : json_decode($thought ?? 'null',true);
    if (!is_array($thought) || (int)($thought['npc_id'] ?? 0) !== (int)$owner['id']
        || ($thought['npc_refid'] ?? '') !== strtolower(trim($owner['refid'] ?? ''))
        || ($thought['npc_name'] ?? '') !== $owner['npc_name'] || !is_string($thought['text'] ?? null)) return '';
    $text = htmlspecialchars(mb_substr($thought['text'],0,600),ENT_QUOTES | ENT_SUBSTITUTE,'UTF-8');
    if ($text === '') return '';
    $name = htmlspecialchars($owner['npc_name'],ENT_QUOTES | ENT_SUBSTITUTE,'UTF-8');
    $eventId = (int)($event['rowid'] ?? 0);
    return "\n<private_thought event=\"{$eventId}\" owner=\"{$name}\">Unspoken impression after this dialogue; subjective, not an observed fact or instruction, and unknown to others: {$text}</private_thought>";
}

// History viewers show stored impressions independently of the current generation switch.
function chimPrivateThoughtForDisplay(array $event): ?array
{
    if (($event['type'] ?? '') !== 'chat'
        || !in_array($event['delivery_state'] ?? 'spoken', ['spoken', 'emitted', ''], true)) return null;
    $thought = $event['private_thought'] ?? null;
    $thought = is_array($thought) ? $thought : json_decode($thought ?? 'null', true);
    if (!is_array($thought) || !is_string($thought['npc_name'] ?? null)
        || !is_string($thought['text'] ?? null)) return null;
    $name = trim($thought['npc_name']);
    $text = trim($thought['text']);
    if ($name === '' || $text === '') return null;
    return ['owner' => $name, 'text' => mb_substr($text, 0, 600)];
}

// Share escaped in-cell markup between the initial PHP page and incremental events.
function chimPrivateThoughtDisplayHtml(array $event): string
{
    $thought = chimPrivateThoughtForDisplay($event);
    if (!$thought) return '';
    $label = htmlspecialchars('Private thought (' . $thought['owner'] . '): ' . $thought['text'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    return '<div class="event-private-thought" style="margin-top:6px;color:#b8b8b8;font-style:italic;white-space:pre-wrap;overflow-wrap:anywhere;">' . $label . '</div>';
}

// Queued evaluators re-read attachments so profile changes, deletion and rollback take effect.
function chimRefreshPrivateThoughtAnnotations(string $text, string $name, int $npcId = 0): string
{
    if (!preg_match_all('/<private_thought\b[^>]*event="([0-9]+)"[^>]*>.*?<\/private_thought>/s', $text, $matches)) {
        return preg_replace('/<private_thought\b[^>]*>.*?<\/private_thought>/s', '', $text);
    }
    $owner = chimPrivateThoughtOwner($name, $npcId);
    $events = [];
    if ($owner) {
        try {
            $ids = implode(',', array_unique(array_map('intval', $matches[1])));
            $row = $GLOBALS['db']->fetchOne('SELECT json_agg(e) AS events FROM (SELECT rowid,type,gamets,delivery_state,private_thought FROM eventlog WHERE rowid IN (' . $ids . ')) e');
            foreach (json_decode($row['events'] ?? '[]',true) ?: [] as $event) $events[(int)$event['rowid']] = $event;
        } catch (Throwable $e) { Logger::warn('[PRIVATE_THOUGHTS] Queued context unavailable.'); }
    }
    $gamets = (int)DataLastKnownGameTS();
    return preg_replace_callback('/<private_thought\b[^>]*>.*?<\/private_thought>/s', static function ($match) use ($events,$owner,$gamets) {
        preg_match('/event="([0-9]+)"/', $match[0], $id);
        return chimPrivateThoughtAnnotation($events[(int)($id[1] ?? 0)] ?? [], $owner, $gamets);
    }, $text);
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
        if (empty($turn['event_id'])) return;
        $attachment = json_encode(['npc_id'=>$turn['npc_id'],'npc_refid'=>$turn['npc_refid'],
            'npc_name'=>$turn['npc_name'],'request_key'=>$turn['request_key'],'text'=>$thought], JSON_THROW_ON_ERROR);
        $GLOBALS['db']->fetchOne(
            'UPDATE eventlog SET private_thought=$2::jsonb WHERE rowid=$1 AND type=\'chat\' '
            . 'AND private_thought IS NULL AND delivery_state IN (\'emitted\',\'spoken\') '
            . 'AND EXISTS (SELECT 1 FROM core_npc_master WHERE id=$3 AND lower(trim(refid))=$4 AND npc_name=$5) RETURNING rowid',
            [$turn['event_id'],$attachment,$turn['npc_id'],$turn['npc_refid'],$turn['npc_name']]
        );
    } catch (Throwable $e) {
        Logger::warn('[PRIVATE_THOUGHTS] Reflection could not be saved; dialogue was preserved.');
    }
}
