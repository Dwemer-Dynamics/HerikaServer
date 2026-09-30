<?php

require_once __DIR__ . '/game_plugins.php';

// Accept only plugin/local-reference pairs, not paths, base IDs, or dynamic FF identities.
function chimParseNpcReferenceSource($value): ?array
{
    if (!is_string($value) || !preg_match('~^([^/\\\\|@#:\x00-\x1F]+\.es[mpl])[/|]([0-9a-f]{1,8})$~i', trim($value), $matches)) {
        return null;
    }
    $localId = hexdec($matches[2]);
    if ($localId > 0xFFFFFF) {
        return null;
    }
    return chimParseStableFormReference($matches[1] . '|' . sprintf('%08X', $localId));
}

// Plan first so same-name actors can swap occupied runtime IDs without violating uniqueness.
function chimPlanNpcReferenceRemap(array $rows, array $oldPlugins, array $newPlugins): array
{
    $oldByPrefix = chimIndexLoadedGamePluginsByPrefix($oldPlugins);
    $newByName = chimIndexLoadedGamePluginsByName($newPlugins);
    $updates = [];
    $occupied = [];
    foreach ($rows as $row) {
        $refid = strtoupper(trim((string)($row['refid'] ?? '')));
        $metadata = is_array($row['metadata'] ?? null)
            ? $row['metadata'] : (json_decode($row['metadata'] ?? '{}', true) ?: []);
        $source = null;
        if (strpos($refid, 'FF') !== 0) {
            $source = chimParseNpcReferenceSource($metadata['refid_source'] ?? '');
            if (!$source && preg_match('/^[0-9A-F]{8}$/', $refid)) {
                $source = chimParseNpcReferenceSource(
                    chimConvertRuntimeFormIdToStableReference($refid, $oldByPrefix) ?? ''
                );
            }
        }
        $newRefid = $refid;
        if ($source) {
            $plugin = $newByName[strtolower($source['plugin_name'])] ?? null;
            // A missing plugin is unavailable, not a different actor now occupying its old slot.
            $newRefid = $plugin
                ? chimComputeRuntimeFormIdFromPrefix($plugin['formid_prefix'], $source['local_formid']) : null;
            if ($plugin && strlen($plugin['formid_prefix']) === 5 && hexdec($source['local_formid']) > 0xFFF) {
                throw new RuntimeException('NPC reference no longer fits its plugin; compaction requires manual reconciliation');
            }
            $hash = md5('ref:' . strtolower($source['plugin_name']) . '|' . $source['local_formid']);
            if ((string)$newRefid !== $refid || ($metadata['refid_source'] ?? '') !== $source['stable_key'] || $hash !== ($row['md5'] ?? '')) {
                $updates[] = ['id' => (int)$row['id'], 'refid' => $newRefid, 'md5' => $hash, 'source' => $source['stable_key']];
            }
        }
        if ($newRefid !== null && $newRefid !== '') {
            $identity = $newRefid;
            if (isset($occupied[$identity])) {
                throw new RuntimeException('Ambiguous NPC reference remap; existing profiles were left unchanged');
            }
            $occupied[$identity] = true;
        }
    }
    return $updates;
}

// The old manifest and all affected profile identities change as one database-only transaction.
function chimSyncNpcReferenceLoadOrder(array $plugins): int
{
    global $db;
    $normalized = chimNormalizeLoadedGamePluginManifest($plugins);
    if (!$normalized || count($normalized) !== count($plugins)) {
        throw new RuntimeException('Empty or incomplete loaded plugin manifest');
    }
    $prefixes = [];
    foreach ($normalized as $plugin) {
        $prefix = $plugin['formid_prefix'];
        if (!chimParseNpcReferenceSource($plugin['plugin_name'] . '|00000000') ||
            !preg_match('/^(?:[0-9A-F]{2}|FE[0-9A-F]{3})$/', $prefix) || $prefix === 'FF' ||
            $plugin['is_light'] !== (strlen($prefix) === 5) || isset($prefixes[$prefix])) {
            throw new RuntimeException('Invalid or ambiguous loaded plugin manifest');
        }
        $prefixes[$prefix] = true;
    }
    $plugins = $normalized;
    if ($db->execQuery('BEGIN') === false) {
        throw new RuntimeException('Could not start NPC reference remap');
    }
    try {
        if ($db->execQuery('LOCK TABLE public.game_plugins, public.core_npc_master IN SHARE ROW EXCLUSIVE MODE') === false) {
            throw new RuntimeException('Could not lock NPC reference state');
        }
        $oldPlugins = $db->fetchAll('SELECT * FROM public.game_plugins');
        $rows = $db->fetchAll('SELECT id, npc_name, refid, md5, metadata FROM public.core_npc_master');
        $updates = chimPlanNpcReferenceRemap($rows, $oldPlugins, $plugins);
        if ($updates) {
            $ids = implode(',', array_column($updates, 'id'));
            // Release occupied keys inside the transaction before assigning the new permutation.
            if ($db->execQuery("UPDATE public.core_npc_master SET refid = NULL WHERE id IN ({$ids})") === false) {
                throw new RuntimeException('Could not release old NPC reference IDs');
            }
            foreach ($updates as $update) {
                $id = $update['id'];
                $refid = $update['refid'] === null ? 'NULL' : "'" . $db->escape($update['refid']) . "'";
                $hash = $db->escape($update['md5']);
                $source = $db->escape(json_encode($update['source'], JSON_UNESCAPED_SLASHES));
                if ($db->execQuery("UPDATE public.core_npc_master
                    SET refid = {$refid}, md5 = '{$hash}',
                        metadata = jsonb_set(
                            CASE WHEN metadata IN ('null'::jsonb, '[]'::jsonb) THEN '{}'::jsonb
                                 ELSE COALESCE(metadata, '{}'::jsonb) END,
                            '{refid_source}', '{$source}'::jsonb)
                    WHERE id = {$id}") === false) {
                    throw new RuntimeException('Could not persist NPC reference remap');
                }
            }
        }
        $count = chimReplaceLoadedGamePlugins($plugins);
        if ($db->execQuery('COMMIT') === false) {
            throw new RuntimeException('Could not commit NPC reference remap');
        }
        return $count;
    } catch (Throwable $e) {
        $db->execQuery('ROLLBACK');
        throw $e;
    }
}

// Upgrade selectors in place; conflicting references abort without merging or deleting profiles.
function chimMigrateStableNpcIdentity(): void
{
    $db = $GLOBALS['db'];
    if ($db->execQuery('BEGIN') === false) { throw new RuntimeException('Cannot begin stable NPC identity migration'); }
    try {
        if ($db->execQuery('LOCK TABLE core_npc_master IN SHARE ROW EXCLUSIVE MODE') === false) {
            throw new RuntimeException('Cannot lock NPC identities');
        }
        $rows = $db->fetchAll('SELECT id, npc_name, refid, metadata, md5 FROM core_npc_master');
        $sources = [];
        foreach ($rows as $row) {
            $source = chimParseNpcReferenceSource(chimNpcProfileJson($row['metadata'] ?? null)['refid_source'] ?? '');
            if ($source) {
                $key = strtolower($source['stable_key']);
                if (isset($sources[$key])) { throw new RuntimeException('Duplicate stable NPC reference; existing profiles were left unchanged'); }
                $sources[$key] = true;
            }
            $hash = NpcMaster::identityMd5($row);
            $metadata = chimNpcProfileJson($row['metadata'] ?? null);
            $canonicalSource = $source ? $source['stable_key'] : null;
            if ($hash === $row['md5'] && (!$source || ($metadata['refid_source'] ?? '') === $canonicalSource)) { continue; }
            $sourceUpdate = $source ? ", metadata = COALESCE(metadata, '{}'::jsonb) || jsonb_build_object('refid_source', '" . $db->escape($canonicalSource) . "'::text)" : '';
            if ($db->execQuery("UPDATE core_npc_master SET md5 = '{$hash}'{$sourceUpdate} WHERE id = " . (int)$row['id']) === false) {
                throw new RuntimeException('Cannot update stable NPC selector');
            }
        }
        if ($db->execQuery("CREATE UNIQUE INDEX IF NOT EXISTS idx_npc_stable_reference
            ON core_npc_master (lower(metadata->>'refid_source'))
            WHERE COALESCE(metadata->>'refid_source', '') <> ''") === false ||
            $db->execQuery('COMMIT') === false) { throw new RuntimeException('Cannot commit stable NPC identity migration'); }
    } catch (Throwable $error) {
        $db->execQuery('ROLLBACK');
        throw $error;
    }
}

// Durable actor keys for eventlog.people identity format 2 (docs/actor-identity.md).
// Runtime FormIDs, base IDs and display names are never keys.
const CHIM_ACTOR_IDENTITY_VERSION = 2;
const CHIM_ACTOR_KEY_PLAYER = 'player';
const CHIM_ACTOR_KEY_NARRATOR = 'narrator';
const CHIM_ACTOR_STATUS_SUFFIXES = ['busy', 'hostile', 'in combat', 'restrained'];

// Strict canonical form only; must stay identical to public.chim_eventlog_actor_keys().
function chimIsActorKey($key): bool
{
    if (!is_string($key)) { return false; }
    if ($key === CHIM_ACTOR_KEY_PLAYER || $key === CHIM_ACTOR_KEY_NARRATOR) { return true; }
    if (preg_match('/^dyn:[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D', $key)) {
        return $key !== 'dyn:00000000-0000-0000-0000-000000000000';
    }
    return (bool)preg_match('~^ref:[^|/\\\\@#:\x00-\x1F\x7F A-Z][^|/\\\\@#:\x00-\x1F\x7FA-Z]*\.es[mpl]\|00[0-9A-F]{6}$~D', $key);
}

// Writers build ref keys from a plugin/local reference; the md5 of this key is the profile selector.
function chimActorKeyFromReference($source): ?string
{
    $parsed = chimParseNpcReferenceSource($source);
    if (!$parsed) { return null; }
    $key = 'ref:' . strtolower($parsed['plugin_name']) . '|' . $parsed['local_formid'];
    return chimIsActorKey($key) ? $key : null;
}

// Existing profile rows resolve only through their recorded stable reference, never name or runtime slot.
function chimNpcRowActorKey(array $row): ?string
{
    $metadata = is_array($row['metadata'] ?? null)
        ? $row['metadata'] : (json_decode((string)($row['metadata'] ?? ''), true) ?: []);
    return chimActorKeyFromReference($metadata['refid_source'] ?? '');
}

// Thrown by the strict v2 boundaries: client ingress and format-2 serialization. Callers must fail the
// event explicitly; nothing is downgraded to legacy routing or accepted partially.
final class ChimEventIdentityException extends InvalidArgumentException
{
    public const UNSUPPORTED_VERSION = 'unsupported_version';
    public const PARTICIPANTS_INVALID = 'participants_invalid';
    public const PARTICIPANT_INVALID = 'participant_invalid';
    public const NAME_INVALID = 'participant_name_invalid';
    public const ID_INVALID = 'participant_id_invalid';
    public const ROLE_INVALID = 'role_key_invalid';

    public function __construct(public readonly string $reason, public readonly ?int $index = null)
    {
        parent::__construct('Invalid event identity: ' . $reason . ($index === null ? '' : ' at participant ' . $index));
    }
}

// Pipes are allowed: format 2 separates names from keys, and legacy lists never yield a pipe in a token.
function chimIsEventParticipantName($name): bool
{
    return is_string($name) && trim($name, ' ') !== '' && mb_check_encoding($name, 'UTF-8')
        && mb_strlen($name, 'UTF-8') <= 256 && !preg_match('/[\x00-\x1F\x7F]/', $name);
}

// Keep the recorded name as a snapshot; status suffixes are split out, not removed.
function chimEventParticipant(string $name, ?string $id): array
{
    $name = trim($name, ' ');
    $pattern = '/^(.*\S) \((' . implode('|', array_map('preg_quote', CHIM_ACTOR_STATUS_SUFFIXES)) . ')\)$/iD';
    $status = preg_match($pattern, $name, $matches) ? strtolower($matches[2]) : null;
    return ['name' => $name, 'base_name' => $status === null ? $name : $matches[1], 'status' => $status, 'id' => $id];
}

// One participant per key and per unresolved name; namesakes with different keys stay separate.
function chimDedupeEventParticipants(array $participants): array
{
    $unique = [];
    foreach ($participants as $participant) {
        $seenKey = $participant['id'] !== null ? 'id:' . $participant['id'] : 'name:' . $participant['name'];
        $unique[$seenKey] ??= $participant;
    }
    return array_values($unique);
}

// Tolerant display parsing of stored history. An entry keeps a key only when the whole entry is valid,
// exactly as public.chim_eventlog_actor_keys() decides; other entries are shown name-only or skipped.
function chimReadStoredEventParticipants(array $items): array
{
    $participants = [];
    foreach ($items as $item) {
        if (is_string($item)) { $item = ['name' => $item]; }
        elseif (is_object($item)) { $item = get_object_vars($item); }
        else { continue; }
        if (!chimIsEventParticipantName($item['name'] ?? null)) { continue; }
        $participants[] = chimEventParticipant($item['name'], chimIsActorKey($item['id'] ?? null) ? $item['id'] : null);
    }
    return chimDedupeEventParticipants($participants);
}

// Strict v2 validation. Participants are associative arrays or objects holding only name and optional id.
// An absent id is unresolved; a provided id must be canonical. Null counts as absent only for PHP writers,
// whose parsed participants carry 'id' => null; client JSON must omit the field.
function chimValidateEventParticipants($items, bool $nullIdIsAbsent): array
{
    if (!is_array($items) || !array_is_list($items)) {
        throw new ChimEventIdentityException(ChimEventIdentityException::PARTICIPANTS_INVALID);
    }
    $participants = [];
    foreach ($items as $index => $item) {
        if (is_object($item)) { $item = get_object_vars($item); }
        if (!is_array($item) || !array_key_exists('name', $item) || ($item !== [] && array_is_list($item))) {
            throw new ChimEventIdentityException(ChimEventIdentityException::PARTICIPANT_INVALID, $index);
        }
        if (array_diff(array_keys($item), ['name', 'id', 'base_name', 'status'])
            || (!$nullIdIsAbsent && array_diff(array_keys($item), ['name', 'id']))) {
            throw new ChimEventIdentityException(ChimEventIdentityException::PARTICIPANT_INVALID, $index);
        }
        if (!chimIsEventParticipantName($item['name'])) {
            throw new ChimEventIdentityException(ChimEventIdentityException::NAME_INVALID, $index);
        }
        $id = $item['id'] ?? null;
        if (array_key_exists('id', $item) && !($id === null && $nullIdIsAbsent) && !chimIsActorKey($id)) {
            throw new ChimEventIdentityException(ChimEventIdentityException::ID_INVALID, $index);
        }
        $participants[] = chimEventParticipant($item['name'], $id);
    }
    return chimDedupeEventParticipants($participants);
}

// Format 2 is a JSON array; anything else stays name-only legacy data. For display only: never use this
// to accept client input. JSON that PostgreSQL jsonb rejects (for example a \u0000 escape) yields no keys.
function chimParseEventParticipants($people): array
{
    $people = (string)$people;
    if (str_starts_with($people, '[') && !preg_match('/(?<!\\\\)(?:\\\\\\\\)*\\\\u0000/i', $people)) {
        $decoded = json_decode($people, false);
        if (is_array($decoded)) {
            return ['version' => CHIM_ACTOR_IDENTITY_VERSION, 'participants' => chimReadStoredEventParticipants($decoded)];
        }
    }
    // Legacy writers also store a bare name, which may itself start with '['. Such rows, and malformed
    // JSON, are shown by name only; no key is ever derived from them.
    return ['version' => 1, 'participants' => chimReadStoredEventParticipants(explode('|', $people))];
}

// Keys in first-appearance order, matching public.chim_eventlog_actor_keys(people).
function chimEventParticipantKeys($people): array
{
    return array_values(array_filter(array_column(chimParseEventParticipants($people)['participants'], 'id')));
}

// Writes format 2 through the strict boundary; throws ChimEventIdentityException rather than downgrading.
function chimSerializeEventParticipants(array $participants): string
{
    return json_encode(array_map(static fn($p) => $p['id'] === null
        ? ['name' => $p['name']] : ['name' => $p['name'], 'id' => $p['id']], chimValidateEventParticipants($participants, true)),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
}

// Client event metadata. Returns null only when identity_version is absent (legacy client; participants
// is then ignored). Otherwise returns the complete validated audience, possibly empty, or throws
// ChimEventIdentityException; the caller must reject the event instead of falling back to names.
function chimEventIdentityParticipants(array $payload): ?array
{
    if (!array_key_exists('identity_version', $payload)) {
        return null;
    }
    if ($payload['identity_version'] !== CHIM_ACTOR_IDENTITY_VERSION) {
        throw new ChimEventIdentityException(ChimEventIdentityException::UNSUPPORTED_VERSION);
    }
    return chimValidateEventParticipants($payload['participants'] ?? null, false);
}

// Physical role keys travel beside the audience in the same event metadata. They record provenance only:
// a speaker, listener or target key never adds that actor to the audience.
function chimEventIdentityRoles(array $payload): array
{
    $roles = ['speaker_key' => null, 'listener_keys' => [], 'target_key' => null];
    foreach (['speaker_key', 'target_key'] as $field) {
        if (!array_key_exists($field, $payload)) { continue; }
        if (!chimIsActorKey($payload[$field])) {
            throw new ChimEventIdentityException(ChimEventIdentityException::ROLE_INVALID . ':' . $field);
        }
        $roles[$field] = $payload[$field];
    }
    if (array_key_exists('listener_keys', $payload)) {
        $listeners = $payload['listener_keys'];
        if (!is_array($listeners) || !array_is_list($listeners)) {
            throw new ChimEventIdentityException(ChimEventIdentityException::ROLE_INVALID . ':listener_keys');
        }
        foreach ($listeners as $index => $key) {
            if (!chimIsActorKey($key)) {
                throw new ChimEventIdentityException(ChimEventIdentityException::ROLE_INVALID . ':listener_keys', $index);
            }
        }
        $roles['listener_keys'] = array_values(array_unique($listeners));
    }
    return $roles;
}

// Request field 4 carries base64 JSON event metadata (the same field as the player routing snapshot).
// Returns null for legacy clients, including fields that are not base64 JSON objects, and otherwise the
// validated audience plus role keys. Throws before anything is written when an opted-in event is invalid.
function chimDecodeEventIdentityField($rawField): ?array
{
    $rawField = trim((string)$rawField);
    $decoded = $rawField === '' ? false : base64_decode($rawField, true);
    $payload = is_string($decoded) && $decoded !== '' ? json_decode($decoded, true) : null;
    if (!is_array($payload) || array_is_list($payload)) { return null; }
    $participants = chimEventIdentityParticipants($payload);
    if ($participants === null) { return null; }
    return ['participants' => $participants] + chimEventIdentityRoles($payload);
}

// Actor key for a registered actor: a placed reference stays authoritative; otherwise the client-assigned
// dyn: key recorded at registration. Names and runtime FormIDs never produce a key.
function chimNpcRowPhysicalKey(array $row): ?string
{
    $key = chimNpcRowActorKey($row);
    if ($key !== null) { return $key; }
    $metadata = is_array($row['metadata'] ?? null)
        ? $row['metadata'] : (json_decode((string)($row['metadata'] ?? ''), true) ?: []);
    $dynamic = $metadata['actor_key'] ?? null;
    return is_string($dynamic) && str_starts_with($dynamic, 'dyn:') && chimIsActorKey($dynamic) ? $dynamic : null;
}

// Registration metadata may carry the client-assigned dyn: key of a dynamic actor in the same field.
// Absent means legacy; a present value must be a canonical dyn: key or the registration is rejected.
function chimDecodeRegistrationActorKey($rawField): ?string
{
    $rawField = trim((string)$rawField);
    $decoded = $rawField === '' ? false : base64_decode($rawField, true);
    $payload = is_string($decoded) && $decoded !== '' ? json_decode($decoded, true) : null;
    if (!is_array($payload) || array_is_list($payload) || !array_key_exists('actor_key', $payload)) { return null; }
    $key = $payload['actor_key'];
    if (!is_string($key) || !str_starts_with($key, 'dyn:') || !chimIsActorKey($key)) {
        throw new ChimEventIdentityException(ChimEventIdentityException::ROLE_INVALID . ':actor_key');
    }
    return $key;
}
