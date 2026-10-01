<?php

// Response identity v1: the optional fourth field of a streamed label|queue|payload line
// (docs/actor-identity.md). The label stays presentation only; the base64 JSON field names the selected
// physical row or typed principal, the exactly resolved listener and any actor-argument targets.

require_once __DIR__ . '/npc_reference.php';

const CHIM_RESPONSE_IDENTITY_VERSION = 1;
const CHIM_RESPONSE_PLAYER_REFID = '00000014';

final class ChimResponseIdentityException extends InvalidArgumentException
{
}

function chimResponseNarratorEndpoint(): array
{
    return ['id' => CHIM_ACTOR_KEY_NARRATOR, 'refid' => null];
}

function chimResponsePlayerEndpoint(): array
{
    return ['id' => CHIM_ACTOR_KEY_PLAYER, 'refid' => CHIM_RESPONSE_PLAYER_REFID];
}

// A physical endpoint: canonical key plus a current runtime RefID consistent with it. Mirrors the client's
// ParseResponseEndpoint so the server never emits metadata the client must drop.
function chimResponsePhysicalEndpoint(string $key, $refid): array
{
    if (!chimIsActorKey($key) || !preg_match('/^(ref|dyn):/', $key)) {
        throw new ChimResponseIdentityException('response_identity_key_invalid');
    }
    $refid = strtoupper(preg_replace('/^0X/i', '', trim((string)$refid)));
    if (!preg_match('/^[0-9A-F]{1,8}$/D', $refid)) {
        throw new ChimResponseIdentityException('response_identity_refid_invalid');
    }
    $refid = str_pad($refid, 8, '0', STR_PAD_LEFT);
    $value = hexdec($refid);
    if ($value === 0 || $refid === CHIM_RESPONSE_PLAYER_REFID) {
        throw new ChimResponseIdentityException('response_identity_refid_invalid');
    }
    $dynamic = substr($refid, 0, 2) === 'FF';
    if (str_starts_with($key, 'dyn:')) {
        if (!$dynamic) { throw new ChimResponseIdentityException('response_identity_refid_mismatch'); }
    } else {
        $local = hexdec(substr($key, -6));
        $light = substr($refid, 0, 2) === 'FE';
        $matches = !$dynamic && ($light
            ? ($local <= 0xFFF && ($value & 0xFFF) === $local)
            : (($value & 0xFFFFFF) === $local));
        if (!$matches) { throw new ChimResponseIdentityException('response_identity_refid_mismatch'); }
    }
    return ['id' => $key, 'refid' => $refid];
}

// Endpoint of a core_npc_master row by its own physical key. Legacy rows without a key return null;
// a keyed row whose runtime RefID contradicts the key throws.
function chimResponseEndpointForNpcRow($row): ?array
{
    if (!is_array($row)) { return null; }
    $key = chimNpcRowActorKey($row);
    if ($key === null) { return null; }
    return chimResponseCheckSnapshotEndpoint(chimResponsePhysicalEndpoint($key, $row['refid'] ?? ''));
}

// Request endpoint snapshot: after the event (and any dynamic row) is registered and before the LLM runs, main.php
// freezes each captured physical key and the selected speaker's row at its RefID then. Output built after the LLM
// delay uses those bindings only: an endpoint whose key now sits at another RefID, or a RefID now owned by another
// key (a recycled static/dynamic reference), throws so the line is dropped rather than retargeted. Keys first seen
// after the snapshot (a decorated label for a row this request did not capture) resolve live. Background jobs
// capture their own endpoints at enqueue (responselog identity), not here.
function chimResponseSnapshotRequestEndpoints(): array
{
    if (is_array($GLOBALS['CHIM_RESPONSE_ENDPOINT_SNAPSHOT'] ?? null)) { return $GLOBALS['CHIM_RESPONSE_ENDPOINT_SNAPSHOT']; }
    $GLOBALS['CHIM_RESPONSE_ENDPOINT_SNAPSHOT'] = [];  // Lookups below must not check against a partial snapshot.
    $snapshot = [];
    $speaker = chimResponseCurrentPhysicalRow();
    $speakerKey = $speaker ? chimNpcRowActorKey($speaker) : null;
    $npcMaster = chimResponseNpcMaster();
    foreach (array_unique(array_merge(chimResponseCapturedKeys(), $speakerKey ? [$speakerKey] : [])) as $key) {
        if (!is_string($key) || !preg_match('/^(ref|dyn):/', $key)) { continue; }
        try {
            $row = $key === $speakerKey ? $speaker : ($npcMaster ? $npcMaster->getByActorKey($key) : null);
            $endpoint = $row ? chimResponsePhysicalEndpoint($key, $row['refid'] ?? '') : null;
        } catch (InvalidArgumentException | RuntimeException $e) {
            $endpoint = null;
        }
        $snapshot[$key] = $endpoint === null ? null : $endpoint['refid'];
    }
    $GLOBALS['CHIM_RESPONSE_ENDPOINT_SNAPSHOT'] = $snapshot;
    return $snapshot;
}

// A physical endpoint must agree with the request snapshot (both key -> RefID and RefID -> key); typed principals
// and endpoints outside the snapshot pass unchanged.
function chimResponseCheckSnapshotEndpoint(array $endpoint): array
{
    $snapshot = $GLOBALS['CHIM_RESPONSE_ENDPOINT_SNAPSHOT'] ?? null;
    if (!is_array($snapshot) || !$snapshot || !preg_match('/^(ref|dyn):/', (string)($endpoint['id'] ?? ''))) { return $endpoint; }
    if (array_key_exists($endpoint['id'], $snapshot) && $snapshot[$endpoint['id']] !== $endpoint['refid']) {
        throw new ChimResponseIdentityException('response_identity_binding_changed');
    }
    $owner = array_search($endpoint['refid'], $snapshot, true);
    if ($owner !== false && $owner !== $endpoint['id']) {
        throw new ChimResponseIdentityException('response_identity_binding_changed');
    }
    return $endpoint;
}

function chimResponseIsNarratorName($name): bool
{
    $name = trim((string)$name);
    return $name === '' || strcasecmp($name, 'The Narrator') === 0;
}

// The selected physical row speaking now: the current row when HERIKA_NAME is that row's own name and the
// row is keyed. A physical row named "The Narrator" stays physical; a temporary narrator switch over another
// NPC's row (inline narration) does not.
function chimResponseCurrentPhysicalRow(): ?array
{
    $row = is_array($GLOBALS['CHIM_CORE_CURRENT_NPC_DATA'] ?? null) ? $GLOBALS['CHIM_CORE_CURRENT_NPC_DATA'] : null;
    if (!$row || chimNpcRowActorKey($row) === null) { return null; }
    $speaker = trim((string)($GLOBALS['HERIKA_NAME'] ?? ''));
    return $speaker !== '' && strcasecmp($speaker, trim((string)($row['npc_name'] ?? ''))) === 0 ? $row : null;
}

// Speaker principal for the current output: 'physical' (with row), 'narrator', 'player' (forced by
// CHIM_RESPONSE_PRINCIPAL) or 'legacy' (unkeyed row).
function chimResolveResponsePrincipal(): array
{
    $forced = $GLOBALS['CHIM_RESPONSE_PRINCIPAL'] ?? null;
    if ($forced === CHIM_ACTOR_KEY_PLAYER || $forced === CHIM_ACTOR_KEY_NARRATOR) { return ['kind' => $forced, 'row' => null]; }
    $row = chimResponseCurrentPhysicalRow();
    if ($row) { return ['kind' => 'physical', 'row' => $row]; }
    if (chimResponseIsNarratorName($GLOBALS['HERIKA_NAME'] ?? '')) { return ['kind' => 'narrator', 'row' => null]; }
    return ['kind' => 'legacy', 'row' => is_array($GLOBALS['CHIM_CORE_CURRENT_NPC_DATA'] ?? null) ? $GLOBALS['CHIM_CORE_CURRENT_NPC_DATA'] : null];
}

// Shared body of chimGetPromptCharacterName() (npc_master/narrator both define it conditionally).
function chimResponsePromptCharacterName(): string
{
    $principal = chimResolveResponsePrincipal();
    $canonicalName = trim((string)($GLOBALS['HERIKA_NAME'] ?? ''));
    if ($principal['kind'] === CHIM_ACTOR_KEY_PLAYER) { return $canonicalName; }
    if ($principal['kind'] === 'narrator') {
        return function_exists('chimGetNarratorRoleplayName')
            ? chimGetNarratorRoleplayName()
            : ($canonicalName !== '' ? $canonicalName : 'The Narrator');
    }
    $refid = strtoupper(preg_replace('/^0X/i', '', trim((string)($principal['row']['refid'] ?? ''))));
    if ($refid !== '' && preg_match('/^[0-9A-F]{1,8}$/', $refid)) {
        return $canonicalName . ' [RefID: ' . str_pad($refid, 8, '0', STR_PAD_LEFT) . ']';
    }
    return $canonicalName;
}

// Actor endpoint for the current speaker; $principal forces a typed narrator/player line. Null = legacy.
function chimResponseCurrentActorEndpoint(?string $principal = null): ?array
{
    if ($principal === CHIM_ACTOR_KEY_NARRATOR) { return chimResponseNarratorEndpoint(); }
    if ($principal === CHIM_ACTOR_KEY_PLAYER) { return chimResponsePlayerEndpoint(); }
    $resolved = chimResolveResponsePrincipal();
    if ($resolved['kind'] === CHIM_ACTOR_KEY_PLAYER) { return chimResponsePlayerEndpoint(); }
    if ($resolved['kind'] === 'narrator') { return chimResponseNarratorEndpoint(); }
    if ($resolved['kind'] === 'physical') {
        $endpoint = chimResponseEndpointForNpcRow($resolved['row']);
        // The in-memory row was read before the LLM; once snapshotted it must still be that key at that RefID.
        if ($endpoint !== null && is_array($GLOBALS['CHIM_RESPONSE_ENDPOINT_SNAPSHOT'] ?? null)
            && !chimResponseEndpointStillCurrent($endpoint)) {
            throw new ChimResponseIdentityException('response_identity_speaker_binding_changed');
        }
        return $endpoint;
    }
    return null;
}

function chimResponseNpcMaster()
{
    static $npcMaster = null;
    if ($npcMaster === null && !class_exists('NpcMaster') && isset($GLOBALS['db'])) { require_once __DIR__ . '/npc_master.class.php'; }
    if ($npcMaster === null && class_exists('NpcMaster')) { $npcMaster = new NpcMaster(); }
    return $npcMaster;
}

// Endpoint for an exact actor key: typed principals directly, physical keys through their row.
function chimResponseEndpointForKey(?string $key): ?array
{
    if (!is_string($key) || !chimIsActorKey($key)) { return null; }
    if ($key === CHIM_ACTOR_KEY_NARRATOR) { return chimResponseNarratorEndpoint(); }
    if ($key === CHIM_ACTOR_KEY_PLAYER) { return chimResponsePlayerEndpoint(); }
    $npcMaster = chimResponseNpcMaster();
    $row = $npcMaster ? $npcMaster->getByActorKey($key) : null;
    if (!$row) { return null; }
    $endpoint = chimResponseEndpointForNpcRow($row);
    return $endpoint !== null && $endpoint['id'] === $key ? $endpoint : null;
}

// Exact endpoint for a listener/target identifier, never a nearest name guess:
//  - "Name [RefID: XXXXXXXX]" resolves through its unique runtime row and its own key;
//  - a ref:/dyn:/player/narrator key resolves directly;
//  - a bare name only matches a key captured on this request (speaker/listener/target roles), uniquely.
function chimResponseResolveActorEndpoint($identifier, array $capturedKeys = []): ?array
{
    $identifier = trim((string)$identifier);
    if ($identifier === '') { return null; }
    if (chimIsActorKey($identifier)) { return chimResponseEndpointForKey($identifier); }
    if (preg_match('/^(.*?)\s*\[RefID:\s*(?:0x)?([0-9a-f]{1,8})\]\s*$/i', $identifier, $matches)) {
        $npcMaster = chimResponseNpcMaster();
        try {
            $row = $npcMaster ? $npcMaster->getByRefId($matches[2]) : null;
        } catch (RuntimeException $e) {
            return null;
        }
        $endpoint = $row ? chimResponseEndpointForNpcRow($row) : null;
        return $endpoint !== null && hexdec($endpoint['refid']) === hexdec($matches[2]) ? $endpoint : null;
    }
    $name = chimEventParticipant($identifier, null)['base_name'];
    $found = [];
    foreach (array_unique(array_filter($capturedKeys, 'is_string')) as $key) {
        if ($key === CHIM_ACTOR_KEY_NARRATOR) { continue; }
        if ($key === CHIM_ACTOR_KEY_PLAYER) {
            $isPlayer = function_exists('isPlayerDialogueListenerName')
                ? isPlayerDialogueListenerName($name)
                : strcasecmp($name, trim((string)($GLOBALS['PLAYER_NAME'] ?? ''))) === 0;
            if ($isPlayer) { $found[$key] = chimResponsePlayerEndpoint(); }
            continue;
        }
        $npcMaster = chimResponseNpcMaster();
        try {
            $row = $npcMaster ? $npcMaster->getByActorKey($key) : null;
        } catch (RuntimeException $e) {
            continue;
        }
        if ($row && strcasecmp(trim((string)$row['npc_name']), $name) === 0) {
            $endpoint = chimResponseEndpointForNpcRow($row);
            if ($endpoint !== null) { $found[$key] = $endpoint; }
        }
    }
    return count($found) === 1 ? reset($found) : null;
}

// Keys captured on the current request's event identity (field 4/5). Invalid metadata yields none.
function chimResponseCapturedKeys(): array
{
    if (array_key_exists('CHIM_RESPONSE_CAPTURED_KEYS', $GLOBALS)) {
        return (array)$GLOBALS['CHIM_RESPONSE_CAPTURED_KEYS'];
    }
    $keys = [];
    if (isset($GLOBALS['gameRequest']) && is_array($GLOBALS['gameRequest'])) {
        try {
            $identity = chimDecodeRequestEventIdentity($GLOBALS['gameRequest']);
        } catch (ChimEventIdentityException $e) {
            $identity = null;
        }
        if ($identity) {
            $keys = array_merge([$identity['speaker_key'], $identity['target_key']], $identity['listener_keys']);
        }
    }
    return $GLOBALS['CHIM_RESPONSE_CAPTURED_KEYS'] = array_values(array_unique(array_filter($keys, 'is_string')));
}

// Labels, queues and payloads must never carry the line's own '|' or newline delimiters.
function chimResponseTransportText($text): string
{
    return str_replace(['|', "\r", "\n"], [' ', ' ', ' '], (string)$text);
}

// Encodes the fourth field. $targets: list of ['arg' => int, 'id' => ..., 'refid' => ...].
function chimResponseIdentityField(array $actor, ?array $listener = null, array $targets = []): string
{
    $seen = [];
    $cleanTargets = [];
    foreach ($targets as $target) {
        $arg = $target['arg'] ?? null;
        if (!is_int($arg) || $arg < 0 || $arg > 64 || ($target['id'] ?? null) === CHIM_ACTOR_KEY_NARRATOR) {
            throw new ChimResponseIdentityException('response_identity_target_invalid');
        }
        $endpoint = ['arg' => $arg, 'id' => $target['id'], 'refid' => $target['refid']];
        if (isset($seen[$arg])) {
            if ($seen[$arg] !== $endpoint) { throw new ChimResponseIdentityException('response_identity_target_conflict'); }
            continue;
        }
        $seen[$arg] = $endpoint;
        $cleanTargets[] = $endpoint;
    }
    $json = json_encode([
        'response_identity_version' => CHIM_RESPONSE_IDENTITY_VERSION,
        'actor' => ['id' => $actor['id'], 'refid' => $actor['refid']],
        'listener' => $listener === null ? null : ['id' => $listener['id'], 'refid' => $listener['refid']],
        'targets' => $cleanTargets,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    return base64_encode($json);
}

// Builds one response line. $actor null = legacy three fields (unkeyed speaker); otherwise the fourth field
// is appended. A decorated label must agree with the actor RefID, as the client checks.
function chimBuildResponseLine(string $label, string $queue, string $payload, ?array $actor,
    ?array $listener = null, array $targets = []): string
{
    $label = chimResponseTransportText($label);
    $line = $label . '|' . chimResponseTransportText($queue) . '|' . chimResponseTransportText($payload);
    if ($actor === null) {
        if ($targets) { throw new ChimResponseIdentityException('response_identity_actor_missing'); }
        return $line . "\r\n";
    }
    if (preg_match('/\[RefID:\s*(?:0x)?([0-9a-f]{1,8})\]\s*$/i', $label, $matches)
        && ($actor['refid'] === null || hexdec($matches[1]) !== hexdec($actor['refid']))) {
        throw new ChimResponseIdentityException('response_identity_label_mismatch');
    }
    return $line . '|' . chimResponseIdentityField($actor, $listener, $targets) . "\r\n";
}

// Builds a line for the current speaker; returns null (and logs) when metadata is invalid so the caller
// drops the output instead of downgrading it to label routing.
function chimBuildCurrentResponseLine(string $label, string $queue, string $payload, ?string $principal = null,
    ?array $listener = null, array $targets = []): ?string
{
    try {
        return chimBuildResponseLine($label, $queue, $payload, chimResponseCurrentActorEndpoint($principal), $listener, $targets);
    } catch (InvalidArgumentException | RuntimeException $e) {
        error_log('[RESPONSE_IDENTITY] Dropping ' . $queue . ' output for ' . $label . ': ' . $e->getMessage());
        return null;
    }
}

// Decodes a stored/emitted fourth field (for round-trip storage); null when absent or invalid.
function chimDecodeResponseIdentityField($field): ?array
{
    $field = trim((string)$field);
    $decoded = $field === '' ? false : base64_decode($field, true);
    $json = is_string($decoded) ? json_decode($decoded, true) : null;
    if (!is_array($json) || ($json['response_identity_version'] ?? null) !== CHIM_RESPONSE_IDENTITY_VERSION
        || array_diff(array_keys($json), ['response_identity_version', 'actor', 'listener', 'targets'])
        || !is_array($json['actor'] ?? null) || !array_key_exists('listener', $json) || !is_array($json['targets'] ?? null)) {
        return null;
    }
    return $json;
}

// Listener endpoint for an emitted line: exact or null, never an exception into the speech path.
function chimResponseSafeListenerEndpoint($identifier): ?array
{
    try {
        return chimResponseResolveActorEndpoint($identifier, chimResponseCapturedKeys());
    } catch (InvalidArgumentException | RuntimeException $e) {
        error_log('[RESPONSE_IDENTITY] Listener left unresolved: ' . $e->getMessage());
        return null;
    }
}

// Listener endpoint of a responder line. A rechat whose source speaker_key is the typed narrator answers the
// typed narrator: its label (canonical or roleplay name) maps to the narrator endpoint, never to a physical
// namesake, the player engine object (00000014) of the narrator event, or null.
function chimResponseLineListenerEndpoint($identifier): ?array
{
    $label = trim((string)$identifier);
    if ($label !== '' && ($GLOBALS['RECHAT_PREVIOUS_SPEAKER_KEY'] ?? null) === CHIM_ACTOR_KEY_NARRATOR
        && (($GLOBALS['gameRequest'][0] ?? '') === 'rechat')) {
        $name = function_exists('chimNormalizeNarratorRoleplayActorName') ? chimNormalizeNarratorRoleplayActorName($label) : $label;
        if (strcasecmp($name, 'The Narrator') === 0) { return chimResponseNarratorEndpoint(); }
    }
    return chimResponseSafeListenerEndpoint($identifier);
}

// A bare target that resolves exactly to a physical actor is sent decorated; anything else is unchanged.
function chimResponseDecorateExactTarget($identifier): string
{
    $identifier = (string)$identifier;
    if (trim($identifier) === '' || preg_match('/\[RefID:/i', $identifier)) { return $identifier; }
    $endpoint = chimResponseSafeListenerEndpoint($identifier);
    if ($endpoint === null || $endpoint['refid'] === null || $endpoint['id'] === CHIM_ACTOR_KEY_PLAYER) {
        return $identifier;
    }
    $name = chimEventParticipant($identifier, null)['base_name'];
    return $name . ' [RefID: ' . $endpoint['refid'] . ']';
}

// Actor arguments per command, mirroring the client's ActorTargetIdentifierUtils::RoleCommandActorArgs():
// a target may only name an argument the client resolves as an actor. Forms, bases, items and places are not.
function chimResponseCommandActorArgs(string $command): array
{
    $has = static fn(string $part): bool => str_contains($command, $part);
    if ($command === 'DirectorScene' || $command === 'DirectorSceneFailed') { return []; }
    if ($has('spawnCharacter')) { return [4 => 'decimal']; }
    if ($has('spawnItem') || $has('spawnBook')) { return [2 => 'decimal']; }
    if ($has('generateLetter')) { return []; }
    if ($has('moveToPlayer') || $has('stayAtPlace') || $has('TravelTo')) { return [0 => 'name']; }
    if ($has('TeleportNPCRaw') || $has('TeleportNPC') || $has('KillTargetRaw')) { return [0 => 'name']; }
    if ($has('SpawnNPCRaw')) { return []; }
    if ($has('SpawnItemRaw') || $has('SpawnGoldRaw')) { return [0 => 'name']; }
    if ($has('CombatPlayer') || $has('Instruction') || $has('Suggestion') || $has('Disposition') || $has('Despawn')) {
        return [0 => 'name'];
    }
    if ($has('EndQuest') || $has('StartQuest') || $has('UpdateQuest')) { return []; }
    if ($has('Sandbox')) { return [0 => 'name']; }
    if ($has('ImpersonatePlayer') || $has('QuestNotifySound')) { return []; }
    if ($command === 'UploadBookContentByTitle' || $command === 'UploadBookContent') { return []; }
    if ($has('RawDebugNotification') || $has('DebugNotification') || $has('InternalSetting')) { return []; }
    if ($has('QuestTrackReference')) { return [0 => 'hex']; }
    if ($has('RefreshNPCVoice')) { return []; }
    if ($has('RenameNPC') || $has('BackgroundCmd')) { return [0 => 'hex']; }
    return [];
}

// Actor arguments of an ordinary agent command ("command", "confirmcommand", "approvedcommand" channels),
// mirroring the client's ActorTargetIdentifierUtils::OrdinaryCommandActorArgs() branch order exactly (C10).
// 'name': the whole argument is the actor label; 'json_target': JSON {"target":..} (a non-JSON argument is not
// an actor); 'json_target_or_name': JSON "target" when the argument contains '{', else the whole argument.
function chimResponseOrdinaryCommandActorArgs(string $command): array
{
    $has = static fn(string $part): bool => str_contains($command, $part);
    if ($has('Halt')) { return []; }
    if ($has('AddBounty') || $has('PayBounty') || $has('ArrestPlayer') || $has('ForgiveCrime')) { return []; }
    if ($has('Attack') || $has('Brawl')) { return [0 => 'name']; }
    if ($has('OpenInventory') || $has('SetCurrentTask')) { return []; }
    if ($has('MoveTo')) { return [0 => 'name']; }
    if ($has('TravelTo') || $has('CheckInventory') || $has('WalkSpeed') || $has('ReadQuestJournal')
        || $has('SearchMemory') || $has('InspectSurroundings') || $has('LookAround')) {
        return [];
    }
    if ($has('Inspect')) { return [0 => 'name']; }
    if ($has('TakeASeat') || $has('GoToSleep') || $has('WaitHere') || $has('Surrender') || $has('UseSoulGaze')) { return []; }
    if ($has('CastSpell')) { return [0 => 'json_target', 1 => 'name']; }
    if ($has('TakeGoldFromPlayer') || $has('RentRoom') || $has('HireCarriage') || $has('HireFerry')
        || $has('FollowPlayer') || $has('MakeFollower')) {
        return [];
    }
    if ($has('Follow')) { return [0 => 'name']; }
    if ($has('ComeCloser') || $has('EndConversation') || $has('ReturnBackHome')) { return []; }
    if ($has('GiveGoldTo')) { return [0 => 'json_target_or_name']; }
    if ($has('TradeItems')) { return [0 => 'name']; }
    if ($has('Consume')) { return []; }
    if ($has('GiveItemTo')) { return [0 => 'json_target_or_name']; }
    return [];
}

function chimResponseIsOrdinaryCommandChannel(string $channel): bool
{
    return in_array($channel, ['command', 'confirmcommand', 'approvedcommand'], true);
}

// CastSpell's non-actor target literals (the caster itself, a location), as the client's IsCastSpellNonActorTarget.
function chimResponseIsCastSpellNonActorTarget(string $command, string $label): bool
{
    if (!str_contains($command, 'CastSpell')) { return false; }
    $label = strtolower(trim($label));
    return $label === 'self' || $label === 'target location';
}

// The actor label of one ordinary command argument, extracted as the client does: null when the argument is
// not an actor in this form (CastSpell's spell name, JSON that is not an object); '' when empty or a CastSpell
// non-actor literal. Other JSON values (item, amount) are never read.
function chimResponseOrdinaryArgLabel(string $command, string $kind, string $arg): ?string
{
    $start = strpos($arg, '{');
    $json = $kind === 'json_target' || ($kind === 'json_target_or_name' && $start !== false);
    if ($json) {
        $end = strrpos($arg, '}');
        $payload = ($start !== false && $end !== false && $end > $start)
            ? json_decode(substr($arg, $start, $end - $start + 1)) : null;
        if (!($payload instanceof stdClass)) { return null; }
        $label = isset($payload->target) && is_string($payload->target) ? $payload->target : '';
    } else {
        $label = $arg;
    }
    $label = trim($label);
    return chimResponseIsCastSpellNonActorTarget($command, $label) ? '' : $label;
}

// Targets for "Command@arg0@arg1...": only actor arguments that resolve exactly to a keyed row or the
// player. Rolecommands: unresolved or non-actor arguments carry no binding (the client keeps its own safe
// handling). Ordinary commands ($channel command/confirmcommand/approvedcommand) use their own map; the client
// refuses an enveloped line whose non-empty actor argument has no target, so with $strict an unresolved actor
// argument throws and the line is dropped rather than sent without its binding. A decorated argument whose row
// contradicts its key throws so the command is dropped.
function chimResponseCommandTargets(string $payload, string $channel = 'rolecommand', bool $strict = false): array
{
    $at = strpos($payload, '@');
    if ($at === false) { return []; }
    $command = substr($payload, 0, $at);
    $args = explode('@', substr($payload, $at + 1));
    $targets = [];
    if (chimResponseIsOrdinaryCommandChannel($channel)) {
        foreach (chimResponseOrdinaryCommandActorArgs($command) as $index => $kind) {
            if (!array_key_exists($index, $args)) { continue; }
            $label = chimResponseOrdinaryArgLabel($command, $kind, (string)$args[$index]);
            if ($label === null || $label === '') { continue; }
            $endpoint = chimResponseResolveActorEndpoint($label, chimResponseCapturedKeys());
            if ($endpoint === null || $endpoint['id'] === CHIM_ACTOR_KEY_NARRATOR || $endpoint['refid'] === null) {
                if ($strict) { throw new ChimResponseIdentityException('response_identity_command_target_unresolved'); }
                continue;
            }
            $targets[] = ['arg' => $index, 'id' => $endpoint['id'], 'refid' => $endpoint['refid']];
        }
        return $targets;
    }
    foreach (chimResponseCommandActorArgs($command) as $index => $kind) {
        $text = trim((string)($args[$index] ?? ''));
        if ($text === '') { continue; }
        $endpoint = null;
        if ($kind === 'name') {
            $endpoint = chimResponseResolveActorEndpoint($text, chimResponseCapturedKeys());
        } else {
            $ref = $kind === 'decimal'
                ? chimResponseDecimalRefHex($text)
                : strtoupper(preg_replace('/^0X/i', '', $text));
            if ($ref === CHIM_RESPONSE_PLAYER_REFID) {
                $endpoint = chimResponsePlayerEndpoint();
            } elseif (preg_match('/^[0-9A-F]{1,8}$/D', $ref)) {
                $npcMaster = chimResponseNpcMaster();
                try {
                    $row = $npcMaster ? $npcMaster->getByRefId($ref) : null;
                } catch (RuntimeException $e) {
                    $row = null;
                }
                $endpoint = $row ? chimResponseEndpointForNpcRow($row) : null;
            }
        }
        if ($endpoint !== null && $endpoint['id'] !== CHIM_ACTOR_KEY_NARRATOR) {
            $targets[] = ['arg' => $index, 'id' => $endpoint['id'], 'refid' => $endpoint['refid']];
        }
    }
    return $targets;
}

// A command line for the current speaker with its exact actor-argument targets; null drops it. A legacy
// (unkeyed) speaker has no envelope, so its line stays three fields without targets as before; an enveloped
// ordinary command whose actor argument does not resolve exactly is dropped, never downgraded.
function chimBuildCurrentCommandLine(string $label, string $channel, string $payload, ?string $principal = null): ?string
{
    try {
        $actor = chimResponseCurrentActorEndpoint($principal);
        $targets = $actor === null ? [] : chimResponseCommandTargets($payload, $channel, true);
        return chimBuildResponseLine($label, $channel, $payload, $actor, null, $targets);
    } catch (InvalidArgumentException | RuntimeException | JsonException $e) {
        error_log('[RESPONSE_IDENTITY] Dropping ' . $channel . ' output for ' . $label . ': ' . $e->getMessage());
        return null;
    }
}

// Older connectors (openai, openrouter, koboldcpp, llamacpp, web_connector) name the acting character themselves.
// The command is built for the selected typed principal: a label that is the current speaker (or empty) gets its
// envelope; any other character cannot be bound, so it keeps the legacy three-field line only while the speaker
// itself has no envelope (unchanged behaviour) and is otherwise dropped. Null = drop.
function chimBuildLegacyConnectorCommandLine(string $character, string $payload): ?string
{
    $speaker = (string)($GLOBALS['HERIKA_NAME'] ?? '');
    $character = trim($character);
    $same = $character === '' || strcasecmp($character, trim($speaker)) === 0
        || (chimResponseIsNarratorName($character) && chimResponseIsNarratorName($speaker));
    if ($same) { return chimBuildCurrentCommandLine($speaker, 'command', $payload); }
    if (chimResolveResponsePrincipal()['kind'] === 'legacy') {
        return chimResponseTransportText($character) . '|command|' . chimResponseTransportText($payload) . "\r\n";
    }
    error_log('[RESPONSE_IDENTITY] Dropping command for unbound character ' . $character);
    return null;
}

// koboldcpp/llamacpp grammar commands are registered in their returned $alreadysent map keyed by line md5.
function chimRegisterLegacyConnectorCommand(?array &$alreadysent, string $payload): void
{
    $line = chimBuildCurrentCommandLine((string)($GLOBALS['HERIKA_NAME'] ?? ''), 'command', $payload);
    if ($line !== null) { $alreadysent[md5($line)] = $line; }
}

// Field 4 of a stored or emitted line ('' when absent).
function chimResponseLineIdentityField(string $line): string
{
    $parts = explode('|', rtrim($line, "\r\n"));
    return isset($parts[3]) ? trim($parts[3]) : '';
}

// Post-filters rewrite label|queue|payload; keep the original line's identity field on the rewrite.
// Targets are kept as-is: the client refuses a line whose rewritten arguments no longer match them.
function chimResponseReattachIdentity(string $rewritten, string $original): string
{
    $field = chimResponseLineIdentityField($original);
    if ($field === '') { return $rewritten; }
    $eol = str_ends_with($rewritten, "\r\n") ? "\r\n" : '';
    $body = rtrim($rewritten, "\r\n");
    $parts = explode('|', $body);
    if (count($parts) === 4 && trim($parts[3]) === $field) { return $rewritten; }
    if (count($parts) !== 3) {
        // A payload pipe would shift the envelope; the client drops such a line, so drop it here.
        return '';
    }
    return $body . '|' . $field . $eol;
}

// The full stored call: all four fields when the identity envelope is present.
function chimResponseActionFullcall(array $actionParts): string
{
    $fullcall = ($actionParts[0] ?? '') . '|' . ($actionParts[1] ?? '') . '|' . rtrim((string)($actionParts[2] ?? ''), "\r\n");
    $field = isset($actionParts[3]) ? trim($actionParts[3]) : '';
    return $field === '' ? $fullcall : $fullcall . '|' . $field;
}

// The acting row of an action line: by its envelope key when present (null for typed principals or
// invalid metadata, never a fallback to the label), else the legacy label lookup.
function chimResponseActionActorRow($npcMaster, array $actionParts)
{
    $field = isset($actionParts[3]) ? trim($actionParts[3]) : '';
    if ($field === '') { return $npcMaster->getByName($actionParts[0] ?? ''); }
    $identity = chimDecodeResponseIdentityField($field);
    $actor = $identity['actor'] ?? null;
    if (!is_array($actor) || !is_string($actor['id'] ?? null) || !preg_match('/^(ref|dyn):/', $actor['id'])) { return null; }
    try {
        $row = $npcMaster->getByActorKey($actor['id']);
        $endpoint = $row ? chimResponseEndpointForNpcRow($row) : null;
    } catch (InvalidArgumentException | RuntimeException $e) {
        return null;
    }
    return $endpoint !== null && $endpoint['refid'] === ($actor['refid'] ?? null) ? $row : null;
}

// Owner key stamped on actions_issued/moods_issued: only an envelope-bound physical actor (exact key and current
// ref) yields one. Legacy name-only lines, typed principals and stale bindings stay NULL; a unique name never
// grants ownership of a new row.
function chimResponseActionActorKey(array $actionParts): ?string
{
    if (trim((string)($actionParts[3] ?? '')) === '') { return null; }
    $npcMaster = chimResponseNpcMaster();
    $row = $npcMaster ? chimResponseActionActorRow($npcMaster, $actionParts) : null;
    return is_array($row) ? chimNpcRowActorKey($row) : null;
}

// Owner key of the selected physical speaker now; null for narrator, player and legacy rows.
function chimResponseCurrentActorKey(): ?string
{
    $row = chimResponseCurrentPhysicalRow();
    return $row ? chimNpcRowActorKey($row) : null;
}

// Owner filter for actions_issued/moods_issued reads, applied before ORDER/LIMIT: the exact key when the
// reader knows its row (NULL legacy rows are kept but never adopted), else the legacy name match.
function chimIssuedOwnerClause($db, ?string $actorKey, string $name, string $nameColumn = 'actorname'): string
{
    if ($actorKey !== null && $actorKey !== '') {
        return "actor_key = '" . $db->escape($actorKey) . "'";
    }
    return "$nameColumn = '" . $db->escape($name) . "'";
}

// Listener names for ScriptQueue rotation rendered as exact identifiers: each bare name with captured physical
// keys on this request becomes one "Name [RefID: XXXXXXXX]" entry per captured row (namesakes all stay in the
// rotation), excluding the current speaker's own row. Decorated, player and uncaptured names are unchanged.
function chimResponseExpandListenerIdentifiers(array $names): array
{
    $speaker = chimResponseCurrentPhysicalRow();
    $speakerKey = $speaker ? chimNpcRowActorKey($speaker) : null;
    $byName = [];
    $npcMaster = chimResponseNpcMaster();
    foreach (chimResponseCapturedKeys() as $key) {
        if (!preg_match('/^(ref|dyn):/', (string)$key) || $key === $speakerKey || !$npcMaster) { continue; }
        try {
            $row = $npcMaster->getByActorKey($key);
            $endpoint = $row ? chimResponseEndpointForNpcRow($row) : null;
        } catch (InvalidArgumentException | RuntimeException $e) {
            continue;
        }
        if ($endpoint !== null) {
            $byName[mb_strtolower(trim((string)$row['npc_name']), 'UTF-8')][$endpoint['refid']] = trim((string)$row['npc_name']);
        }
    }
    $out = [];
    foreach ($names as $name) {
        $name = trim((string)$name);
        $rows = preg_match('/\[RefID:/i', $name) ? [] : ($byName[mb_strtolower($name, 'UTF-8')] ?? []);
        if (!$rows) { $out[] = $name; continue; }
        ksort($rows);
        foreach ($rows as $refid => $rowName) { $out[] = $rowName . ' [RefID: ' . $refid . ']'; }
    }
    return array_values(array_unique(array_filter($out, static fn($n) => $n !== '')));
}

// Signed 32-bit decimal role arguments (FF dynamic refs arrive negative from Papyrus ints) as 8 hex digits.
function chimResponseDecimalRefHex(string $text): string
{
    if (!preg_match('/^-?\d{1,10}$/D', $text)) { return ''; }
    $value = (int)$text;
    if ($value < -2147483648 || $value > 4294967295) { return ''; }
    return strtoupper(str_pad(dechex($value & 0xFFFFFFFF), 8, '0', STR_PAD_LEFT));
}

// Endpoint for a runtime RefID: the typed player, or the keyed row owning that RefID now. Null otherwise.
function chimResponseEndpointForRefId(string $ref): ?array
{
    $ref = strtoupper(preg_replace('/^0X/i', '', trim($ref)));
    if (!preg_match('/^[0-9A-F]{1,8}$/D', $ref)) { return null; }
    $ref = str_pad($ref, 8, '0', STR_PAD_LEFT);
    if ($ref === CHIM_RESPONSE_PLAYER_REFID) { return chimResponsePlayerEndpoint(); }
    $npcMaster = chimResponseNpcMaster();
    try {
        $row = $npcMaster ? $npcMaster->getByRefId($ref) : null;
        $endpoint = $row ? chimResponseEndpointForNpcRow($row) : null;
    } catch (InvalidArgumentException | RuntimeException $e) {
        return null;
    }
    return $endpoint !== null && $endpoint['refid'] === $ref ? $endpoint : null;
}

// ScriptProxy parameter schema, from the builder (lib/scriptproxy_papyrus.php) and how AIAgentScriptProxy.psc reads
// each parameter: 'actor' = jsonGetActor and the command does nothing without it; 'actor_opt' = jsonGetActor but
// None is accepted; 'ref' = jsonGetReference (an actor or a non-actor object such as a bed, door or marker), or a
// builder parameter Papyrus never reads. Spells, perks, items, factions, form lists, shaders, idles, packages and
// actor bases are forms and never listed. Unlisted commands (getters, form lists, factions) have no actor slots.
function chimScriptProxyParamSchema(int $cmdID): array
{
    if ($cmdID >= 1 && $cmdID < 100) {
        $extra = [
            6 => ['akTarget' => 'actor'], 35 => ['akTarget' => 'ref'], 43 => ['akOther' => 'actor'],
            45 => ['akCriminal' => 'actor'], 46 => ['akTarget' => 'actor'], 47 => ['akTarget' => 'actor'],
            52 => ['akTarget' => 'ref'], 56 => ['arTarget' => 'actor'], 70 => ['aMarker' => 'ref'], 73 => ['aTarget' => 'ref'],
        ];
        return ['targetObjectFormId' => 'actor'] + ($extra[$cmdID] ?? []);
    }
    if ($cmdID >= 100 && $cmdID < 200) {
        $extra = [
            100 => ['akActivator' => 'ref'], 114 => ['akTarget' => 'ref'], 122 => ['akActorToPush' => 'actor'],
            129 => ['akThief' => 'actor'], 130 => ['ObjectWithNeededKey' => 'ref'],
        ];
        return ['targetObjectFormId' => 'ref'] + ($extra[$cmdID] ?? []);
    }
    if ($cmdID === 300 || $cmdID === 301) { return ['akObject' => 'ref']; }
    $actorUtil = [
        400 => ['targetObjectFormId' => 'ref', 'akActor' => 'actor'],
        401 => ['targetObjectFormId' => 'ref', 'akActor' => 'actor'],
        490 => ['targetObjectFormId' => 'ref', 'akTargetRef' => 'ref'],
        491 => ['targetObjectFormId' => 'ref', 'akActor' => 'actor_opt'],
        492 => ['targetObjectFormId' => 'ref', 'akActor' => 'ref'],
    ];
    return $actorUtil[$cmdID] ?? [];
}

// Parameter names of a command that may hold an actor (any schema kind).
function chimScriptProxyActorParams(int $cmdID): array
{
    return array_keys(chimScriptProxyParamSchema($cmdID));
}

// One std::stoul pass over $text: $auto = base 0 (0x hex, leading-0 octal, decimal), else base 16. Null when no
// digits parse (invalid_argument), -1 when out of 32-bit range; a leading '-' wraps as strtoul does.
function chimScriptProxyStoul(string $text, bool $auto): ?int
{
    if (!preg_match('/^[ \t\n\v\f\r]*([+-]?)(.*)$/sD', $text, $m)) { return null; }
    $body = $m[2];
    if ($auto && preg_match('/^0[xX]([0-9a-fA-F]+)/', $body, $d)) { $digits = $d[1]; $base = 16; }
    elseif ($auto && preg_match('/^(0[0-7]*)/', $body, $d)) { $digits = $d[1]; $base = 8; }
    elseif ($auto && preg_match('/^([0-9]+)/', $body, $d)) { $digits = $d[1]; $base = 10; }
    elseif (!$auto && preg_match('/^(?:0[xX](?=[0-9a-fA-F]))?([0-9a-fA-F]+)/', $body, $d)) { $digits = $d[1]; $base = 16; }
    else { return null; }
    $digits = ltrim($digits, '0');
    if ($digits === '') { return 0; }
    if (strlen($digits) > [16 => 8, 10 => 10, 8 => 11][$base]) { return -1; }
    $number = intval($digits, $base);
    if ($number > 0xFFFFFFFF) { return -1; }
    return $m[1] === '-' ? ((0x100000000 - $number) & 0xFFFFFFFF) : $number;
}

// The reference a parameter names, exactly as the client's EventIdentityUtils::ScriptProxyRefValue (and the
// Papyrus json getters): strings only, base 0 then base 16; anything else is 0 (None).
function chimScriptProxyRefValue($value): int
{
    if (!is_string($value)) { return 0; }
    $parsed = chimScriptProxyStoul($value, true);
    if ($parsed === null || $parsed === -1) { $parsed = chimScriptProxyStoul($value, false); }
    return $parsed === null || $parsed === -1 ? 0 : $parsed;
}

// Endpoint for a ScriptProxy actor parameter's reference, or null when it cannot be proven an actor now. A keyed
// row (or the player) owning the reference wins; a known row that is unkeyed or contradicts its reference throws.
// For an actor-only parameter ($actorOnly) an unregistered static placed reference may take its canonical ref: key
// from the loaded plugin manifest (the client still checks it is that loaded actor); a dynamic FF reference has no
// stable identity without a captured row, and a manifest key whose row sits at another reference is not retargeted.
function chimScriptProxyEndpointForValue(int $value, bool $actorOnly): ?array
{
    if ($value === 0) { return null; }
    $ref = sprintf('%08X', $value);
    if ($ref === CHIM_RESPONSE_PLAYER_REFID) { return chimResponsePlayerEndpoint(); }
    $npcMaster = chimResponseNpcMaster();
    $row = $npcMaster ? $npcMaster->getByRefId($ref) : null;
    if ($row) {
        try {
            $endpoint = chimResponseEndpointForNpcRow($row);
        } catch (InvalidArgumentException $e) {
            $endpoint = null;
        }
        if ($endpoint === null || $endpoint['refid'] !== $ref) {
            // A known actor that cannot be bound: the client would refuse it unbound, so reject it here.
            throw new ChimResponseIdentityException('scriptproxy_actor_row_unkeyed');
        }
        return $endpoint;
    }
    if (!$actorOnly || str_starts_with($ref, 'FF') || !function_exists('chimConvertRuntimeFormIdToStableReference')) { return null; }
    $key = chimActorKeyFromReference(chimConvertRuntimeFormIdToStableReference($ref));
    if ($key === null) { return null; }
    $endpoint = chimResponsePhysicalEndpoint($key, $ref);
    if ($npcMaster && $npcMaster->getByActorKey($key)) {
        throw new ChimResponseIdentityException('scriptproxy_actor_key_moved');
    }
    return $endpoint;
}

// Validates one declared target: canonical endpoint shape, the parameter's own reference, and still that key at
// that reference now (a recycled FF reference under another dyn: key no longer matches).
function chimScriptProxyCheckDeclaredTarget(array $cmd, string $param, $target): array
{
    if (!array_key_exists($param, $cmd) || in_array($param, ['cmdID', 'actor_identity_version', 'actor_targets', 'chim_binding'], true)
        || !is_array($target) || array_diff(array_keys($target), ['id', 'refid']) || !is_string($target['id'] ?? null)
        || !is_string($target['refid'] ?? null)) {
        throw new ChimResponseIdentityException('scriptproxy_target_invalid');
    }
    $endpoint = $target['id'] === CHIM_ACTOR_KEY_PLAYER
        ? chimResponsePlayerEndpoint() : chimResponsePhysicalEndpoint($target['id'], $target['refid']);
    if ($endpoint['refid'] !== $target['refid'] || chimScriptProxyRefValue($cmd[$param]) !== hexdec($endpoint['refid'])) {
        throw new ChimResponseIdentityException('scriptproxy_target_mismatch');
    }
    if (!chimResponseEndpointStillCurrent($endpoint)) { throw new ChimResponseIdentityException('scriptproxy_target_stale'); }
    return $endpoint;
}

// ScriptProxy actor identity v1 (client EventIdentityUtils::ParseScriptProxyIdentity). Every command is declared
// (actor_identity_version 1, actor_targets an object, {} when empty), so the client refuses any parameter that is a
// live actor without a binding. Per parameter (chimScriptProxyParamSchema), with the current key/RefID bound now:
//  - 'actor': must resolve to the player or an exactly keyed actor, else the command is rejected;
//  - 'actor_opt': None ('' / 0) is allowed, otherwise as 'actor';
//  - 'ref': bound when it is a known actor; an unknown form cannot be proven an actor and stays unbound.
// Producer-supplied metadata is validated and kept, never stripped or re-derived: a malformed, stale or
// conflicting declaration rejects the command. Bound values are normalised to "0x" + 8 hex digits.
// Returns null when the command must be dropped.
function chimScriptProxyAttachIdentity(array $cmd): ?array
{
    try {
        if (array_key_exists('chim_binding', $cmd)) { throw new ChimResponseIdentityException('scriptproxy_binding_reserved'); }
        $declared = [];
        if (array_key_exists('actor_identity_version', $cmd) || array_key_exists('actor_targets', $cmd)) {
            $raw = $cmd['actor_targets'] ?? null;
            if (($cmd['actor_identity_version'] ?? null) !== 1) { throw new ChimResponseIdentityException('scriptproxy_identity_version_unsupported'); }
            if ($raw instanceof stdClass) { $raw = (array)$raw; }
            if (!is_array($raw) || ($raw && array_is_list($raw))) { throw new ChimResponseIdentityException('scriptproxy_identity_shape_invalid'); }
            foreach ($raw as $param => $target) {
                $declared[(string)$param] = chimScriptProxyCheckDeclaredTarget($cmd, (string)$param, $target);
            }
        }
        $targets = $declared;
        foreach (chimScriptProxyParamSchema((int)($cmd['cmdID'] ?? 0)) as $param => $kind) {
            if (isset($declared[$param])) { continue; }
            $value = array_key_exists($param, $cmd) ? chimScriptProxyRefValue($cmd[$param]) : 0;
            if ($value === 0) {
                if ($kind === 'actor') { throw new ChimResponseIdentityException('scriptproxy_actor_missing:' . $param); }
                continue;
            }
            $endpoint = chimScriptProxyEndpointForValue($value, $kind !== 'ref');
            if ($endpoint === null) {
                if ($kind !== 'ref') { throw new ChimResponseIdentityException('scriptproxy_actor_unresolved:' . $param); }
                continue;
            }
            $targets[$param] = $endpoint;
        }
    } catch (InvalidArgumentException | RuntimeException $e) {
        error_log('[SCRIPTPROXY_IDENTITY] Dropping ScriptProxy cmdID ' . (int)($cmd['cmdID'] ?? 0) . ': ' . $e->getMessage());
        return null;
    }
    foreach ($targets as $param => $endpoint) { $cmd[$param] = '0x' . $endpoint['refid']; }
    $cmd['actor_identity_version'] = 1;
    $cmd['actor_targets'] = $targets
        ? array_map(static fn(array $e): array => ['id' => $e['id'], 'refid' => $e['refid']], $targets)
        : new stdClass();
    return $cmd;
}

// Nested ScriptProxy targets of a queued "rolecommand|ScriptProxy@{json}" row must still be those keys at those
// references when it is sent; the captured metadata is checked, never refreshed to a changed reference.
function chimScriptProxyQueuedTargetsCurrent(string $queue, string $payload): bool
{
    if ($queue !== 'rolecommand' || !str_starts_with($payload, 'ScriptProxy@')) { return true; }
    $cmd = json_decode(substr($payload, strlen('ScriptProxy@')), true);
    if (!is_array($cmd)) { return false; }
    if (!array_key_exists('actor_identity_version', $cmd) && !array_key_exists('actor_targets', $cmd)) { return true; }
    if (($cmd['actor_identity_version'] ?? null) !== 1 || !is_array($cmd['actor_targets'] ?? null)) { return false; }
    foreach ($cmd['actor_targets'] as $param => $target) {
        try {
            chimScriptProxyCheckDeclaredTarget($cmd, (string)$param, $target);
        } catch (InvalidArgumentException | RuntimeException $e) {
            return false;
        }
    }
    return true;
}

// ScriptProxy JSON on the wire: every '|' (including the one inside canonical ref: keys) becomes |, so the
// line's own delimiters never appear in the nested payload.
function chimScriptProxyWireJson(array $cmd): string
{
    return str_replace('|', '\\u007c', json_encode($cmd, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
}

// Actor of a delayed row from its producer's explicit captured key: the typed narrator/player, or the keyed row
// that still owns that key. A bare label must be that row's own name; a decorated label must name its RefID.
function chimResponseQueueExplicitActor($key, string $label): array
{
    if (!is_string($key) || !chimIsActorKey($key)) { throw new ChimResponseIdentityException('response_identity_queue_key_invalid'); }
    $actor = chimResponseEndpointForKey($key);
    if ($actor === null) { throw new ChimResponseIdentityException('response_identity_queue_key_unresolved'); }
    if ($actor['id'] === CHIM_ACTOR_KEY_NARRATOR || $actor['id'] === CHIM_ACTOR_KEY_PLAYER) { return $actor; }
    $row = chimResponseNpcMaster()->getByActorKey($key);
    $name = chimEventParticipant($label, null)['base_name'];
    if (strcasecmp(trim($name), trim((string)($row['npc_name'] ?? ''))) !== 0) {
        throw new ChimResponseIdentityException('response_identity_queue_label_mismatch');
    }
    if (preg_match('/\[RefID:\s*(?:0x)?([0-9a-f]{1,8})\]\s*$/i', $label, $matches) && hexdec($matches[1]) !== hexdec($actor['refid'])) {
        throw new ChimResponseIdentityException('response_identity_queue_label_mismatch');
    }
    return $actor;
}

// Enqueue hook for responselog rows (db insert): identity captured now travels with the delayed row, so dequeue
// never re-derives it from globals. "actor|action|text" is the wire line: for "queue|payload" actions the text
// column carries the fourth field, otherwise it is appended to the text. System rolemaster commands carry the
// typed narrator. Returns null when captured metadata is invalid (the row is dropped, not downgraded).
// Delayed producers (background workers) whose label is not the current speaker pass the captured row's key in
// the internal CHIM_RESPONSE_QUEUE_ACTOR_KEY field; it is always stripped before SQL. An explicit key that is
// invalid, unknown, or names a different actor than the label drops the row; it never falls back.
const CHIM_RESPONSE_QUEUE_ACTOR_KEY = '_chim_actor_key';

function chimResponseQueueAttachIdentity(array $data): ?array
{
    $hasExplicit = array_key_exists(CHIM_RESPONSE_QUEUE_ACTOR_KEY, $data);
    $explicitKey = $data[CHIM_RESPONSE_QUEUE_ACTOR_KEY] ?? null;
    unset($data[CHIM_RESPONSE_QUEUE_ACTOR_KEY]);
    $label = (string)($data['actor'] ?? '');
    $action = (string)($data['action'] ?? '');
    $text = (string)($data['text'] ?? '');
    if ($action === '') { return $data; }
    $split = str_contains($action, '|');
    $existing = $split ? $text : (str_contains($text, '|') ? substr($text, strrpos($text, '|') + 1) : '');
    if ($existing !== '' && chimDecodeResponseIdentityField($existing) !== null) { return $data; }
    if ($split && $text !== '') { return $data; }  // Legacy text in the fourth position stays exactly as before.
    try {
        if ($hasExplicit) {
            $actor = chimResponseQueueExplicitActor($explicitKey, $label);
        } elseif (strcasecmp(trim($label), 'rolemaster') === 0) {
            $actor = chimResponseNarratorEndpoint();
        } elseif (preg_match('/\[RefID:/i', $label)) {
            $actor = chimResponseResolveActorEndpoint($label);
            if ($actor === null) { throw new ChimResponseIdentityException('response_identity_label_unresolved'); }
        } else {
            $row = chimResponseCurrentPhysicalRow();
            if ($row && trim($label) !== '' && strcasecmp(trim($label), trim((string)$row['npc_name'])) === 0) {
                $actor = chimResponseEndpointForNpcRow($row);
            } elseif (trim($label) !== '' && chimResponseIsNarratorName($label)) {
                $actor = chimResponseNarratorEndpoint();
            } else {
                $actor = null;
            }
        }
        if ($actor === null) { return $data; }
        $targets = [];
        if ($split) {
            [$queue, $payload] = explode('|', $action, 2);
            if (str_contains($payload, '|')) { return $data; }
            if ($queue === 'rolecommand' || chimResponseIsOrdinaryCommandChannel($queue)) {
                $targets = chimResponseCommandTargets($payload, $queue, true);
            }
        }
        $field = chimResponseIdentityField($actor, null, $targets);
    } catch (InvalidArgumentException | RuntimeException | JsonException $e) {
        error_log('[RESPONSE_IDENTITY] Dropping queued ' . $action . ' for ' . $label . ': ' . $e->getMessage());
        return null;
    }
    $data['text'] = $split ? $field : chimResponseTransportText($text) . '|' . $field;
    return $data;
}

// A physical endpoint captured at enqueue must still be that key at that RefID; a recycled FF reference or a
// row whose runtime reference changed no longer matches.
function chimResponseEndpointStillCurrent($endpoint): bool
{
    if (!is_array($endpoint)) { return false; }
    $id = $endpoint['id'] ?? null;
    if ($id === CHIM_ACTOR_KEY_NARRATOR || $id === CHIM_ACTOR_KEY_PLAYER) { return true; }
    try {
        $current = chimResponseEndpointForKey(is_string($id) ? $id : null);
    } catch (InvalidArgumentException | RuntimeException $e) {
        return false;
    }
    return $current !== null && $current['refid'] === ($endpoint['refid'] ?? null);
}

// Wire line for a dequeued responselog row, or null to drop it. Rows without a fourth field are unchanged.
function chimResponseDequeueLine(array $row): ?string
{
    $line = ($row['actor'] ?? '') . '|' . ($row['action'] ?? '') . '|' . ($row['text'] ?? '');
    $parts = explode('|', $line);
    if (!chimScriptProxyQueuedTargetsCurrent((string)($parts[1] ?? ''), (string)($parts[2] ?? ''))) {
        error_log('[RESPONSE_IDENTITY] Dropping queued row ' . ($row['rowid'] ?? '?') . ': stale ScriptProxy actor reference');
        return null;
    }
    $field = isset($parts[3]) ? trim($parts[3]) : '';
    if (count($parts) <= 4 && $field === '') { return $line . "\r\n"; }
    $identity = $field === '' ? null : chimDecodeResponseIdentityField($field);
    if ($identity === null) {
        $decoded = base64_decode($field, true);
        if (is_string($decoded) && str_contains($decoded, 'response_identity_version')) {
            error_log('[RESPONSE_IDENTITY] Dropping queued row ' . ($row['rowid'] ?? '?') . ': invalid identity field');
            return null;
        }
        return $line . "\r\n";  // Legacy text after a queue|payload action, unchanged.
    }
    if (count($parts) > 4) {
        error_log('[RESPONSE_IDENTITY] Dropping queued row ' . ($row['rowid'] ?? '?') . ': extra field');
        return null;
    }
    $endpoints = [$identity['actor']];
    if ($identity['listener'] !== null) { $endpoints[] = $identity['listener']; }
    foreach ($identity['targets'] as $target) {
        $endpoints[] = ['id' => $target['id'] ?? null, 'refid' => $target['refid'] ?? null];
    }
    foreach ($endpoints as $endpoint) {
        if (!chimResponseEndpointStillCurrent($endpoint)) {
            error_log('[RESPONSE_IDENTITY] Dropping queued row ' . ($row['rowid'] ?? '?') . ': stale actor reference');
            return null;
        }
    }
    return $line . "\r\n";
}
