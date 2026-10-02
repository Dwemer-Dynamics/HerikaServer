<?php

error_reporting(E_ERROR);
session_start();

define('BASE_PATH', dirname(dirname(__DIR__)));
define('CONFIG_PATH', BASE_PATH . DIRECTORY_SEPARATOR . 'conf');
define('LIB_PATH', BASE_PATH . DIRECTORY_SEPARATOR . 'lib');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if (!file_exists(CONFIG_PATH . DIRECTORY_SEPARATOR . 'conf.php')) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Configuration file not found']);
    exit;
}

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'profile_loader.php';
require_once LIB_PATH . DIRECTORY_SEPARATOR . 'logger.php';
require_once LIB_PATH . DIRECTORY_SEPARATOR . "{$GLOBALS['DBDRIVER']}.class.php";
require_once LIB_PATH . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'npc_master.class.php';
require_once LIB_PATH . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'tts_filter_presets.php';
require_once LIB_PATH . DIRECTORY_SEPARATOR . 'relationship_manager.php';
require_once LIB_PATH . DIRECTORY_SEPARATOR . 'utils_game_timestamp.php';
require_once LIB_PATH . DIRECTORY_SEPARATOR . 'eventlog_helper.php';

$db = new sql();
$npcMaster = new NpcMaster();

function chimNpcManagerRespond(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

// Stale editor state (row reuse, link/unlink, playthrough switch) refuses with 409 instead of writing.
final class ChimNpcManagerConflict extends RuntimeException
{
}

// Well-formed request whose typed content is refused (forged or malformed relationship targets).
final class ChimNpcManagerInvalid extends RuntimeException
{
}

function chimNpcManagerDecodeJson($value): array
{
    if (is_array($value)) {
        return $value;
    }
    $decoded = json_decode((string)$value, true);
    return is_array($decoded) ? $decoded : [];
}

function chimNpcManagerBool($value): bool
{
    if (is_bool($value)) {
        return $value;
    }
    if (is_int($value) || is_float($value)) {
        return (int)$value !== 0;
    }
    return in_array(strtolower(trim((string)$value)), ['1', 'true', 'yes', 'on'], true);
}

function chimNpcManagerProfiles(): array
{
    $rows = $GLOBALS['db']->fetchAll('SELECT id, label, metadata FROM core_profiles ORDER BY label ASC, id ASC');
    $profiles = [];
    foreach ((array)$rows as $row) {
        $profiles[] = [
            'id' => (int)($row['id'] ?? 0),
            'label' => trim((string)($row['label'] ?? 'Unnamed Profile')),
            'metadata' => chimNpcManagerDecodeJson($row['metadata'] ?? '{}'),
        ];
    }
    return $profiles;
}

function chimNpcManagerProfileMap(array $profiles): array
{
    $map = [];
    foreach ($profiles as $profile) {
        $map[(string)$profile['id']] = $profile;
    }
    return $map;
}

function chimNpcManagerToggleState($override, array $profileMetadata, string $profileKey, bool $default = false): array
{
    if ($override !== null && $override !== '') {
        return [
            'value' => chimNpcManagerBool($override),
            'source' => 'npc',
            'profile_default' => array_key_exists($profileKey, $profileMetadata)
                ? chimNpcManagerBool($profileMetadata[$profileKey])
                : $default,
        ];
    }

    $profileDefault = array_key_exists($profileKey, $profileMetadata)
        ? chimNpcManagerBool($profileMetadata[$profileKey])
        : $default;
    return [
        'value' => $profileDefault,
        'source' => array_key_exists($profileKey, $profileMetadata) ? 'profile' : 'default',
        'profile_default' => $profileDefault,
    ];
}

function chimNpcManagerWebRoot(): string
{
    $scriptPath = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $marker = '/HerikaServer/';
    $position = stripos($scriptPath, $marker);
    if ($position !== false) {
        return substr($scriptPath, 0, $position + strlen('/HerikaServer'));
    }
    return '/HerikaServer';
}

function chimNpcManagerPortraitUrl(array $row, array $metadata): string
{
    $webRoot = chimNpcManagerWebRoot();
    $portrait = ltrim(str_replace('\\', '/', trim((string)($metadata['portrait'] ?? ''))), '/');
    if ($portrait !== '') {
        $picturesRoot = realpath(BASE_PATH . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'pictures');
        $portraitPath = realpath(BASE_PATH . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'pictures'
            . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $portrait));
        if ($picturesRoot !== false && $portraitPath !== false
            && strncmp($portraitPath, $picturesRoot, strlen($picturesRoot)) === 0
            && is_file($portraitPath)) {
            return $webRoot . '/data/pictures/' . str_replace('%2F', '/', rawurlencode($portrait));
        }
    }

    $profileDirectory = BASE_PATH . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'pictures'
        . DIRECTORY_SEPARATOR . 'profile' . DIRECTORY_SEPARATOR;
    $candidates = array_filter([
        trim((string)($row['md5'] ?? '')),
        strtoupper(trim((string)($row['refid'] ?? ''))),
        preg_replace('/[^a-z0-9_-]+/i', '', strtolower((string)($row['npc_name'] ?? ''))),
    ]);
    foreach (array_unique($candidates) as $candidate) {
        foreach (['png', 'jpg', 'jpeg', 'webp', 'gif'] as $extension) {
            if (is_file($profileDirectory . $candidate . '.' . $extension)) {
                return $webRoot . '/data/pictures/profile/' . rawurlencode($candidate . '.' . $extension);
            }
        }
    }

    $race = strtolower(trim((string)($row['race'] ?? '')));
    $race = preg_replace('/[^a-z0-9]+/', '', $race);
    $aliases = [
        'highelf' => 'altmer', 'woodelf' => 'bosmer', 'darkelf' => 'dunmer',
        'orsimer' => 'orc', 'khajit' => 'khajiit', 'oldpeople' => 'nord',
        'oldpeoplerace' => 'nord',
    ];
    $race = $aliases[$race] ?? $race;
    foreach (['png', 'jpg', 'jpeg', 'webp', 'gif', 'svg'] as $extension) {
        $racePath = BASE_PATH . DIRECTORY_SEPARATOR . 'ui' . DIRECTORY_SEPARATOR . 'images'
            . DIRECTORY_SEPARATOR . 'races' . DIRECTORY_SEPARATOR . $race . '.' . $extension;
        if ($race !== '' && is_file($racePath)) {
            return $webRoot . '/ui/images/races/' . rawurlencode($race . '.' . $extension);
        }
    }
    return $webRoot . '/ui/images/races/default.png';
}

function chimNpcManagerCard(array $row, array $profileMap): array
{
    $metadata = chimNpcManagerDecodeJson($row['metadata'] ?? '{}');
    $mods = is_array($metadata['mods'] ?? null)
        ? array_values(array_filter(array_map('trim', $metadata['mods'])))
        : [];
    $profile = $profileMap[(string)($row['profile_id'] ?? '')] ?? null;
    $duplicateCount = isset($row['duplicate_count']) ? (int)$row['duplicate_count'] : 0;
    if ($duplicateCount <= 0 && !empty($row['npc_name'])) {
        $escapedName = $GLOBALS['db']->escape((string)$row['npc_name']);
        $countRow = $GLOBALS['db']->fetchOne(
            "SELECT COUNT(*) AS total FROM core_npc_master WHERE lower(npc_name) = lower('{$escapedName}')"
        );
        $duplicateCount = (int)($countRow['total'] ?? 1);
    }
    return [
        'id' => (int)($row['id'] ?? 0),
        'name' => trim((string)($row['npc_name'] ?? 'Unknown NPC')),
        'gender' => trim((string)($row['gender'] ?? '')),
        'race' => trim((string)($row['race'] ?? '')),
        'refid' => trim((string)($row['refid'] ?? '')),
        'refid_source' => (string)($metadata['refid_source'] ?? ''),
        'profile_sharing' => [
            'linked' => !empty($row['profile_owner_npc_id']) || chimNpcManagerBool($row['_has_shared_profile'] ?? false),
            'automatic' => !empty($metadata['_chim_auto_link_group']),
            'auto_link_disabled' => !empty($metadata['_chim_auto_link_disabled']),
            'owner_id' => (int)($row['profile_owner_npc_id'] ?? $row['id']),
        ],
        'source_mod' => (string)($mods[0] ?? ''),
        'mods' => $mods,
        'duplicate_count' => max(1, $duplicateCount),
        'profile_id' => isset($row['profile_id']) ? (int)$row['profile_id'] : null,
        'profile_label' => $profile['label'] ?? 'No Profile',
        'favorite' => chimNpcManagerBool($row['npc_favorite'] ?? false),
        'locked' => chimNpcManagerBool($row['lock_profile'] ?? false),
        'portrait_url' => chimNpcManagerPortraitUrl($row, $metadata),
        // The selected row's own physical key (metadata is never shared); null for legacy rows.
        'actor_key' => chimNpcRowActorKey($row),
    ];
}

// Additive guard: when the client sent the key it was shown, the row id must still be that actor.
function chimNpcManagerGuardExpectedKey(array $input, array $row, string $field = 'expected_actor_key'): void
{
    if (!array_key_exists($field, $input)) {
        return;
    }
    $expected = $input[$field];
    if (!is_string($expected) || !chimIsActorKey($expected) || chimNpcRowActorKey($row) !== $expected) {
        throw new ChimNpcManagerConflict('This NPC changed since it was opened. Reopen it before continuing.');
    }
}

function chimNpcManagerLatestMemory(array $extended): string
{
    $memory = $extended['middle_term_memory'] ?? [];
    if (!is_array($memory) || empty($memory)) {
        return '';
    }
    $latestKey = array_key_last($memory);
    return $latestKey === null ? '' : trim((string)$memory[$latestKey]);
}

function chimNpcManagerDetail(array $row, array $profiles): array
{
    $raw = (new NpcMaster())->getActorById((int)$row['id']);
    $sharing = chimNpcProfileSharing($raw);
    $revision = chimNpcProfileRevision(chimNpcProfileMembers($raw));
    $row = chimNpcEffectiveProfile($raw);
    $row['_has_shared_profile'] = $sharing['linked'];
    $metadata = chimNpcManagerDecodeJson($row['metadata'] ?? '{}');
    $ttsFilterPresetId = normalizeTtsFilterPresetId($metadata['tts_filter_preset'] ?? '');
    unset($metadata['tts_filter_preset']);
    $extended = chimNpcManagerDecodeJson($row['extended_data'] ?? '{}');
    $profileMap = chimNpcManagerProfileMap($profiles);
    $profile = $profileMap[(string)($row['profile_id'] ?? '')] ?? null;
    $profileMetadata = $profile['metadata'] ?? [];

    return [
        'card' => chimNpcManagerCard($row, $profileMap),
        'profile_sharing' => $sharing,
        'profile_revision' => $revision,
        'fields' => [
            'npc_name' => (string)($row['npc_name'] ?? ''),
            'profile_id' => isset($row['profile_id']) ? (int)$row['profile_id'] : null,
            'lock_profile' => chimNpcManagerBool($row['lock_profile'] ?? false),
            'npc_favorite' => chimNpcManagerBool($row['npc_favorite'] ?? false),
            'gender' => (string)($row['gender'] ?? ''),
            'race' => (string)($row['race'] ?? ''),
            'base' => (string)($row['base'] ?? ''),
            'refid' => (string)($row['refid'] ?? ''),
            'voiceid' => (string)($row['voiceid'] ?? ''),
            'tts_filter_preset' => $ttsFilterPresetId,
            'oghma_knowledge_tags' => (string)($row['oghma_knowledge_tags'] ?? ''),
            'tags' => (string)($row['tags'] ?? ''),
            'prompt_head' => (string)($row['prompt_head'] ?? ''),
            'core' => (string)($row['core'] ?? ''),
            'npc_static_bio' => (string)($row['npc_static_bio'] ?? ''),
            'appearance' => (string)($row['appearance'] ?? ''),
            'personality' => (string)($row['personality'] ?? ''),
            'occupation' => (string)($row['occupation'] ?? ''),
            'skills' => (string)($row['skills'] ?? ''),
            'speechstyle' => (string)($row['speechstyle'] ?? ''),
            'goals' => (string)($row['goals'] ?? ''),
            'emote_moods' => (string)($row['emote_moods'] ?? ''),
            'middle_term_latest' => chimNpcManagerLatestMemory($extended),
        ],
        'toggles' => [
            'dynamic_profile' => chimNpcManagerToggleState($row['dynamic_profile'] ?? null, $profileMetadata, 'DYNAMIC_PROFILE_ENABLED'),
            'middle_term_enabled' => chimNpcManagerToggleState($extended['middle_term_enabled'] ?? null, $profileMetadata, 'MIDDLE_TERM_MEMORY_ENABLED'),
            'individual_memory_enabled' => chimNpcManagerToggleState($extended['individual_memory_enabled'] ?? null, $profileMetadata, 'INDIVIDUAL_MEMORY_ENABLED'),
            'auto_diary_enabled' => chimNpcManagerToggleState($extended['auto_diary_enabled'] ?? null, $profileMetadata, 'AUTO_DIARY_ENABLED'),
            'auto_diary_wait_enabled' => chimNpcManagerToggleState($extended['auto_diary_wait_enabled'] ?? null, $profileMetadata, 'AUTO_DIARY_WAIT_ENABLED'),
            'salutation_after_a_while' => chimNpcManagerToggleState($extended['salutation_after_a_while'] ?? null, $profileMetadata, 'SALUTATION_AFTER_A_WHILE'),
        ],
        'readonly_fields' => NpcMaster::isActorBound($row) ? ['refid'] : [],
        'relationships' => RelationshipManager::normalizeRelationshipMap($extended['relationships'] ?? []),
        'relationships_locked' => chimNpcManagerBool($extended['relationships_locked'] ?? false),
        'metadata' => $metadata,
        'tts_filter_presets' => array_values(array_map(static function ($preset) {
            return [
                'id' => (string)$preset['id'],
                'label' => (string)$preset['label'],
                'description' => (string)$preset['description'],
            ];
        }, ttsFilterPresetOptions(true))),
        'profiles' => array_map(static function ($profile) {
            return ['id' => $profile['id'], 'label' => $profile['label']];
        }, $profiles),
    ];
}

function chimNpcManagerFindNpc(array $input): array
{
    $id = (int)($input['id'] ?? 0);
    if ($id > 0) {
        $row = $GLOBALS['db']->fetchOne("SELECT * FROM core_npc_master WHERE id = {$id} LIMIT 1");
        if ($row) {
            return chimNpcEffectiveProfile($row);
        }
        throw new InvalidArgumentException('NPC profile no longer exists');
    }

    $refid = trim((string)($input['refid'] ?? ''));
    if ($refid !== '') {
        $escaped = $GLOBALS['db']->escape(strtolower($refid));
        $rows = $GLOBALS['db']->fetchAll("SELECT * FROM core_npc_master WHERE lower(refid) = '{$escaped}' ORDER BY gamets_last_updated DESC NULLS LAST, id ASC LIMIT 2");
        if (count((array)$rows) === 1) {
            return chimNpcEffectiveProfile($rows[0]);
        }
        if (count((array)$rows) > 1) {
            throw new InvalidArgumentException('RefID matches more than one profile; use the profile id');
        }
    }

    $name = trim((string)($input['name'] ?? $input['npc_name'] ?? ''));
    if ($name !== '') {
        $escaped = $GLOBALS['db']->escape($name);
        $rows = $GLOBALS['db']->fetchAll("SELECT * FROM core_npc_master WHERE npc_name = '{$escaped}' ORDER BY id ASC LIMIT 2");
        if (count((array)$rows) === 1) {
            return chimNpcEffectiveProfile($rows[0]);
        }
        if (count((array)$rows) > 1) {
            throw new InvalidArgumentException('NPC name matches more than one profile; use the profile id or RefID');
        }
    }

    throw new InvalidArgumentException('NPC not found');
}

// Legacy event rows store a '|'-delimited list of visible names. They are never assigned to an actor:
// they are shown, flagged as unassigned, only while exactly one profile carries that name.
function chimNpcManagerSharedNameCount(string $npcName): int
{
    $npcName = trim($npcName);
    if ($npcName === '') {
        return 0;
    }
    $escaped = $GLOBALS['db']->escape($npcName);
    $row = $GLOBALS['db']->fetchOne(
        "SELECT COUNT(*) AS total FROM core_npc_master WHERE lower(npc_name) = lower('{$escaped}')"
    );
    return max(1, (int)($row['total'] ?? 1));
}

// Exact history scope: format-2 rows whose captured keys include this row's group (linked references
// share history while linked). Unkeyed rows match nothing here.
function chimNpcManagerHistoryKeyWhere(array $npc): string
{
    $keys = chimNpcRowActorKey($npc) !== null || !empty($npc['profile_owner_npc_id'])
        ? chimNpcProfileActorKeys($npc) : [];
    return chimBuildEventLogActorKeysWhereClause($GLOBALS['db'], $keys, 'a.people');
}

// Read-only legacy visibility, applied in WHERE before LIMIT; FALSE when the name is ambiguous.
function chimNpcManagerHistoryLegacyWhere(array $npc): string
{
    $npcName = trim((string)($npc['npc_name'] ?? ''));
    if ($npcName === '' || chimNpcManagerSharedNameCount($npcName) !== 1) {
        return 'FALSE';
    }
    return "(left(COALESCE(a.people, ''), 1) <> '[' AND "
        . chimBuildNpcEventLogPeopleWhereClause($GLOBALS['db'], $npcName, 'a.people') . ')';
}

function chimNpcManagerEventRecipients($people): array
{
    return array_map(static function ($participant) {
        return $participant['id'] === null
            ? ['name' => $participant['name']]
            : ['name' => $participant['name'], 'id' => $participant['id']];
    }, chimParseEventParticipants($people)['participants']);
}

function chimNpcManagerHistory(array $input): array
{
    $npc = chimNpcManagerFindNpc($input);
    chimNpcManagerGuardExpectedKey($input, $npc);
    $npcName = trim((string)($npc['npc_name'] ?? ''));
    $limit = max(1, min(100, (int)($input['limit'] ?? 100)));
    $selectedEventType = trim((string)($input['event_type'] ?? ''));
    $hiddenEventTypes = chimGetPersistedEventLogHiddenTypes($GLOBALS['db']);
    // Keep NPC History aligned with the PHP Adventure Log's default narrative event list.
    $allowedEventTypes = [
        'im_alive',
        'chat',
        'infoaction',
        'rpg_word',
        'rpg_lvlup',
        'rechat',
        'quest',
        'itemfound',
        'inputtext',
        'goodnight',
        'goodmorning',
        'ginputtext',
        'death',
        'combatendmighty',
        'combatend',
    ];
    $escapedAllowedEventTypes = array_map(static function ($eventType) {
        return "'" . $GLOBALS['db']->escape($eventType) . "'";
    }, $allowedEventTypes);
    $allowedTypesWhere = 'a.type IN (' . implode(',', $escapedAllowedEventTypes) . ')';
    $keyWhere = chimNpcManagerHistoryKeyWhere($npc);
    $peopleWhere = '(' . $keyWhere . ' OR ' . chimNpcManagerHistoryLegacyWhere($npc) . ')';
    $visibleWhere = chimBuildVisibleEventLogWhereClause(
        $GLOBALS['db'],
        $selectedEventType,
        $hiddenEventTypes
    );
    $rows = $GLOBALS['db']->fetchAll(
        "SELECT a.rowid, a.type, a.data, a.people, a.gamets, a.localts, a.ts, a.sess,
                CASE WHEN {$keyWhere} THEN 1 ELSE 0 END AS exact_scope
         FROM eventlog a
         WHERE {$allowedTypesWhere} AND {$visibleWhere} AND {$peopleWhere}
         ORDER BY a.gamets DESC, a.ts DESC, a.localts DESC, a.rowid DESC
         LIMIT {$limit}"
    );
    $visibleTypesWhere = chimBuildVisibleEventLogWhereClause($GLOBALS['db'], '', $hiddenEventTypes);
    $eventTypes = $GLOBALS['db']->fetchAll(
        "SELECT a.type, COUNT(*) AS total
         FROM eventlog a
         WHERE {$allowedTypesWhere} AND {$visibleTypesWhere} AND {$peopleWhere}
         GROUP BY a.type
         ORDER BY a.type ASC"
    );

    $events = array_map(static function ($row) {
        $gamets = (int)($row['gamets'] ?? 0);
        $exact = (int)($row['exact_scope'] ?? 0) === 1;
        return [
            'rowid' => (int)($row['rowid'] ?? 0),
            'type' => (string)($row['type'] ?? ''),
            'data' => (string)($row['data'] ?? ''),
            'recipients' => chimNpcManagerEventRecipients($row['people'] ?? ''),
            'legacy_unassigned' => !$exact,
            'deletable' => $exact,
            'gamets' => $gamets,
            'tamrielic_time' => $gamets > 0 ? convert_gamets2skyrim_long_date2($gamets) : '',
            'local_time' => !empty($row['localts']) ? gmdate('d-m-Y H:i:s', (int)$row['localts']) : '',
            'manual_injection' => strtolower((string)($row['type'] ?? '')) === 'inputtext'
                && (string)($row['sess'] ?? '') === 'npc_editor',
        ];
    }, (array)$rows);

    $sharedNameCount = chimNpcManagerSharedNameCount($npcName);

    return [
        'npc' => ['id' => (int)$npc['id'], 'name' => $npcName, 'actor_key' => chimNpcRowActorKey($npc)],
        'shared_name' => [
            'shared' => $sharedNameCount > 1,
            'count' => $sharedNameCount,
            'notice' => $sharedNameCount > 1
                ? $sharedNameCount . ' profiles share the name "' . $npcName . '". Only events recorded with this'
                    . ' actor\'s identity are listed; older name-only events stay stored but unassigned.'
                : '',
        ],
        'events' => $events,
        'filters' => [
            'selected_event_type' => $selectedEventType,
            'hidden_event_types' => $hiddenEventTypes,
            'event_types' => array_map(static function ($row) {
                return [
                    'type' => (string)($row['type'] ?? ''),
                    'total' => (int)($row['total'] ?? 0),
                ];
            }, (array)$eventTypes),
        ],
    ];
}

// Recipients are physical rows chosen by id; each must carry its own key, checked against the key the
// client was shown when it sent one.
function chimNpcManagerResolveEventRecipients(array $input, array $npc): array
{
    $ids = [(int)$npc['id']];
    $requestedIds = is_array($input['recipient_ids'] ?? null) ? $input['recipient_ids'] : [];
    foreach ($requestedIds as $requestedId) {
        $requestedId = (int)$requestedId;
        if ($requestedId > 0 && !in_array($requestedId, $ids, true)) {
            $ids[] = $requestedId;
        }
    }
    if (count($ids) > 12) {
        throw new InvalidArgumentException('An event can include at most 12 NPCs');
    }
    $expectedKeys = $input['recipient_expected_keys'] ?? [];
    if (!is_array($expectedKeys)) {
        throw new InvalidArgumentException('Invalid recipient keys');
    }

    $recipients = [];
    foreach ($ids as $id) {
        $row = $id === (int)$npc['id'] ? $npc : chimNpcManagerFindNpc(['id' => $id]);
        if (array_key_exists((string)$id, $expectedKeys)) {
            chimNpcManagerGuardExpectedKey(['expected_actor_key' => $expectedKeys[(string)$id]], $row);
        }
        $name = trim((string)($row['npc_name'] ?? ''));
        $key = chimNpcRowActorKey($row);
        if ($name === '') {
            throw new InvalidArgumentException('One of the selected NPCs has no name');
        }
        if ($key === null) {
            throw new InvalidArgumentException('"' . $name . '" has no stable actor identity yet, so events cannot be routed to it');
        }
        $recipients[] = ['id' => (int)$row['id'], 'name' => $name, 'actor_key' => $key];
    }
    return $recipients;
}

function chimNpcManagerInjectEvent(array $input): array
{
    $npc = chimNpcManagerFindNpc($input);
    chimNpcManagerGuardExpectedKey($input, $npc);
    $eventText = trim((string)($input['event'] ?? ''));
    if (strlen($eventText) >= 2 && $eventText[0] === '(' && substr($eventText, -1) === ')') {
        $eventText = trim(substr($eventText, 1, -1));
    }
    if ($eventText === '') {
        throw new InvalidArgumentException('Event text is required');
    }
    $eventLength = function_exists('mb_strlen') ? mb_strlen($eventText, 'UTF-8') : strlen($eventText);
    if ($eventLength > 4000) {
        throw new InvalidArgumentException('Event text must be 4000 characters or fewer');
    }

    $recipients = chimNpcManagerResolveEventRecipients($input, $npc);
    // Format 2: names are snapshots, keys are the recipients' own physical keys (never a keeper's).
    $people = chimSerializeEventParticipants(array_map(static function ($recipient) {
        return ['name' => $recipient['name'], 'id' => $recipient['actor_key']];
    }, $recipients));
    $rowId = $GLOBALS['db']->insertReturningId('eventlog', [
        'ts' => max(0, (int)DataLastKnownTS()) + 1,
        'gamets' => max(0, (int)DataLastKnownGameTS()),
        'type' => 'inputtext',
        'data' => '(' . $eventText . ')',
        'sess' => 'npc_editor',
        'localts' => time(),
        'people' => $people,
        'location' => '',
        'party' => '',
    ], 'rowid');
    if ($rowId <= 0) {
        throw new RuntimeException('Event could not be injected');
    }

    return [
        'message' => 'Event injected for ' . implode(', ', array_column($recipients, 'name')) . '.',
        'rowid' => $rowId,
        'recipients' => $recipients,
    ];
}

// Only rows recorded with this actor's group keys can be deleted here; legacy name-only rows stay.
function chimNpcManagerDeleteEvent(array $input): array
{
    $npc = chimNpcManagerFindNpc($input);
    chimNpcManagerGuardExpectedKey($input, $npc);
    $rowId = (int)($input['rowid'] ?? 0);
    if ($rowId <= 0) {
        throw new InvalidArgumentException('Invalid event row');
    }

    $keyWhere = chimNpcManagerHistoryKeyWhere($npc);
    $visibleWhere = chimBuildVisibleEventLogWhereClause($GLOBALS['db']);
    $event = $GLOBALS['db']->fetchOne(
        "SELECT a.rowid FROM eventlog a WHERE a.rowid = {$rowId} AND {$visibleWhere} AND {$keyWhere} LIMIT 1"
    );
    if (!$event) {
        throw new InvalidArgumentException('Event is not recorded for this NPC; unassigned legacy events cannot be deleted here');
    }

    $result = chimDeleteEventLogRow($GLOBALS['db'], $rowId);
    if (empty($result['ok'])) {
        throw new RuntimeException((string)($result['message'] ?? 'Event could not be deleted'));
    }
    return ['message' => 'Event deleted.', 'rowid' => $rowId];
}

// Persist only the temporary return point without overwriting live NPC metadata updates.
function chimNpcManagerSaveReturnLocation(int $npcId, ?array $returnLocation): bool
{
    if ($npcId <= 0) {
        return false;
    }

    if ($returnLocation === null) {
        return $GLOBALS['db']->execQuery(
            "UPDATE core_npc_master SET metadata = COALESCE(metadata, '{}'::jsonb) - 'npc_manager_return_location' WHERE id = {$npcId}"
        ) !== false;
    }

    $encoded = json_encode($returnLocation, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($encoded === false) {
        return false;
    }
    $locationLiteral = $GLOBALS['db']->escape($encoded);
    return $GLOBALS['db']->execQuery(
        "UPDATE core_npc_master SET metadata = jsonb_set(COALESCE(metadata, '{}'::jsonb), '{npc_manager_return_location}', '{$locationLiteral}'::jsonb, true) WHERE id = {$npcId}"
    ) !== false;
}

function chimNpcManagerAction(array $input): array
{
    $row = chimNpcManagerFindNpc($input);
    chimNpcManagerGuardExpectedKey($input, $row);
    $action = strtolower(trim((string)($input['action'] ?? '')));
    $npcName = trim((string)($row['npc_name'] ?? 'NPC'));
    $npcManager = new NpcMaster();
    $metadata = $npcManager->getMetadata($row);
    $returnLocationKey = 'npc_manager_return_location';

    if ($action === 'bgl_inception') {
        $idea = trim((string)($input['idea'] ?? ''));
        if ($idea === '') {
            throw new InvalidArgumentException('Background Life inception requires a thought');
        }
        // Row-scoped: same-named actors keep separate profiles, so only the selected row changes.
        if (!$npcManager->updateExtendedKeysById((int)$row['id'], ['bgl_inception' => $idea])) {
            throw new RuntimeException('Background Life inception could not be saved');
        }
        return ['message' => "Background Life thought set for {$npcName}."];
    }

    if (!in_array($action, ['visit', 'teleport', 'return'], true)) {
        throw new InvalidArgumentException('Unsupported NPC action');
    }

    $refid = strtoupper(preg_replace('/^0X/i', '', trim((string)($row['refid'] ?? ''))));
    if (!preg_match('/^[0-9A-F]{1,8}$/', $refid)) {
        throw new InvalidArgumentException("{$npcName} does not have a valid RefID");
    }
    $npcRefid = '0x' . str_pad($refid, 8, '0', STR_PAD_LEFT);

    if ($action === 'return') {
        $returnLocation = $metadata[$returnLocationKey] ?? null;
        if (!is_array($returnLocation)) {
            throw new InvalidArgumentException("{$npcName} does not have a saved return location");
        }

        $locationName = trim((string)($returnLocation['name'] ?? ''));
        $locationFormId = trim((string)($returnLocation['formid'] ?? ''));
        if ($locationName === '' && $locationFormId === '') {
            throw new InvalidArgumentException("{$npcName}'s saved return location is invalid");
        }
        if ($locationName === '' && $locationFormId !== '') {
            $formIdLiteral = $GLOBALS['db']->escape($locationFormId);
            $location = $GLOBALS['db']->fetchOne("SELECT name FROM locations WHERE formid = '{$formIdLiteral}' LIMIT 1");
            $locationName = trim((string)($location['name'] ?? 'previous location'));
        }

        $targetName = str_replace('@', '', NpcMaster::displayIdentifier($npcName, $row['refid']));
        $locationName = str_replace('@', '', $locationName);
        $roleCommand = $locationFormId !== ''
            ? "rolecommand|TeleportNPCRaw@{$targetName}@{$locationFormId}@{$locationName}"
            : "rolecommand|TeleportNPC@{$targetName}@{$locationName}";

        $GLOBALS['db']->insert('responselog', [
            'localts' => time(),
            'sent' => 0,
            'actor' => 'rolemaster',
            'text' => '',
            'action' => $roleCommand,
            'tag' => '',
        ]);

        if (!chimNpcManagerSaveReturnLocation((int)$row['id'], null)) {
            throw new RuntimeException('Saved return location could not be cleared');
        }

        return [
            'message' => "Return command sent for {$npcName} to {$locationName}.",
            'next_action' => 'teleport',
            'return_location' => '',
        ];
    }

    if ($action === 'teleport') {
        if (is_array($metadata[$returnLocationKey] ?? null)) {
            throw new InvalidArgumentException("Return {$npcName} before teleporting them again");
        }

        $lastCoords = $metadata['last_coords'] ?? null;
        if (!is_array($lastCoords) && is_array($metadata['last_coords_history'] ?? null)) {
            $history = $metadata['last_coords_history'];
            $lastCoords = empty($history) ? null : end($history);
        }
        if (!is_array($lastCoords)) {
            throw new InvalidArgumentException("{$npcName} does not have a tracked location to return to");
        }

        $locationName = trim((string)($lastCoords[3] ?? ''));
        $locationFormId = trim((string)($lastCoords['location_formid'] ?? ''));
        if ($locationFormId === '' && $locationName !== '') {
            $locationLiteral = $GLOBALS['db']->escape($locationName);
            $location = $GLOBALS['db']->fetchOne(
                "SELECT name, formid FROM locations ORDER BY similarity(name, '{$locationLiteral}') DESC LIMIT 1"
            );
            if (is_array($location)) {
                $locationName = trim((string)($location['name'] ?? $locationName));
                $locationFormId = trim((string)($location['formid'] ?? ''));
            }
        }
        if ($locationFormId === '' && is_numeric($lastCoords[0] ?? null) && is_numeric($lastCoords[1] ?? null)) {
            $pointLiteral = $GLOBALS['db']->escape('(' . (float)$lastCoords[0] . ',' . (float)$lastCoords[1] . ')');
            $location = $GLOBALS['db']->fetchOne(
                "SELECT name, formid FROM locations WHERE coords IS NOT NULL ORDER BY coords <-> '{$pointLiteral}'::point LIMIT 1"
            );
            if (is_array($location)) {
                $locationName = trim((string)($location['name'] ?? $locationName));
                $locationFormId = trim((string)($location['formid'] ?? ''));
            }
        }
        if ($locationName === '' && $locationFormId === '') {
            throw new InvalidArgumentException("{$npcName}'s tracked return location is invalid");
        }

        $returnLocation = [
            'name' => $locationName,
            'formid' => $locationFormId,
            'coords' => $lastCoords,
            'saved_at' => time(),
        ];
        if (!chimNpcManagerSaveReturnLocation((int)$row['id'], $returnLocation)) {
            throw new RuntimeException('Return location could not be saved');
        }
    }

    require_once LIB_PATH . DIRECTORY_SEPARATOR . 'scriptproxy_papyrus.php';
    $commandBuilder = new SkyrimCommandBuilder();
    $command = $action === 'visit'
        ? $commandBuilder->ObjectReference->MoveTo(PLAYER_REFID, $npcRefid)
        : $commandBuilder->ObjectReference->MoveTo($npcRefid, PLAYER_REFID);
    $commandBuilder->send($command);

    return [
        'message' => $action === 'visit'
            ? "Visit command sent for {$npcName}."
            : "Teleport command sent for {$npcName}.",
        'next_action' => $action === 'teleport' ? 'return' : null,
        'return_location' => $action === 'teleport' ? $locationName : '',
    ];
}

// Member-level list predicates on table alias $alias ('' for the bare table); null when the filter is unused.
function chimNpcManagerListPredicates(string $alias = ''): array
{
    $p = $alias === '' ? '' : $alias . '.';
    // nearby combines nearby_refid (exact RefID) and nearby_name (name only, not proof of proximity).
    $predicates = ['search' => null, 'nearby' => null, 'nearby_refid' => null, 'nearby_name' => null];

    $search = trim((string)($_GET['search'] ?? ''));
    if ($search !== '') {
        $escaped = $GLOBALS['db']->escape('%' . $search . '%');
        $normalizedSearch = preg_replace('/^0x/i', '', $search);
        $escapedNormalized = $GLOBALS['db']->escape('%' . $normalizedSearch . '%');
        $predicates['search'] = "({$p}npc_name ILIKE '{$escaped}' OR {$p}race ILIKE '{$escaped}' OR {$p}refid ILIKE '{$escaped}'
            OR replace(lower({$p}refid), '0x', '') LIKE lower('{$escapedNormalized}')
            OR {$p}metadata::text ILIKE '{$escaped}')";
    }

    $refids = array_values(array_filter(array_map('trim', explode(',', (string)($_GET['refids'] ?? '')))));
    $names = array_values(array_filter(array_map('trim', explode('|', (string)($_GET['names'] ?? '')))));
    if (!empty($refids) || !empty($names)) {
        $nearbyConditions = [];
        if (!empty($refids)) {
            $escapedRefids = array_map(static function ($value) {
                return "'" . $GLOBALS['db']->escape(strtolower($value)) . "'";
            }, $refids);
            $predicates['nearby_refid'] = "lower({$p}refid) IN (" . implode(',', $escapedRefids) . ')';
            $nearbyConditions[] = $predicates['nearby_refid'];
        }
        if (!empty($names)) {
            $escapedNames = array_map(static function ($value) {
                return "'" . $GLOBALS['db']->escape($value) . "'";
            }, $names);
            $predicates['nearby_name'] = "{$p}npc_name IN (" . implode(',', $escapedNames) . ')';
            $nearbyConditions[] = $predicates['nearby_name'];
        }
        $predicates['nearby'] = '(' . implode(' OR ', $nearbyConditions) . ')';
    }

    return $predicates;
}

function chimNpcManagerList(array $profiles): array
{
    if (chimNpcManagerBool($_GET['collapse_shared'] ?? false)) {
        return chimNpcManagerListCollapsed($profiles);
    }

    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = max(1, min(100, (int)($_GET['limit'] ?? 40)));
    $offset = ($page - 1) * $limit;
    $conditions = ["npc_name IS NOT NULL", "btrim(npc_name) <> ''"];

    $predicates = chimNpcManagerListPredicates();
    if ($predicates['search'] !== null) {
        $conditions[] = $predicates['search'];
    }

    $profileId = (int)($_GET['profile_id'] ?? 0);
    if ($profileId > 0) {
        $conditions[] = "(CASE WHEN profile_owner_npc_id IS NULL THEN profile_id ELSE
            (SELECT owner.profile_id FROM core_npc_master owner WHERE owner.id = core_npc_master.profile_owner_npc_id)
            END) = {$profileId}";
    }

    if ($predicates['nearby'] !== null) {
        $conditions[] = $predicates['nearby'];
    }

    $where = implode(' AND ', $conditions);
    $countRow = $GLOBALS['db']->fetchOne("SELECT COUNT(*) AS total FROM core_npc_master WHERE {$where}");
    $total = (int)($countRow['total'] ?? 0);
    $rows = $GLOBALS['db']->fetchAll(
        "SELECT core_npc_master.*, name_counts.duplicate_count,
            CASE WHEN EXISTS (SELECT 1 FROM core_npc_master child WHERE child.profile_owner_npc_id = core_npc_master.id)
                THEN 1 ELSE 0 END AS _has_shared_profile
         FROM core_npc_master
         JOIN (
             SELECT lower(npc_name) AS normalized_name, COUNT(*) AS duplicate_count
             FROM core_npc_master
             GROUP BY lower(npc_name)
         ) name_counts ON name_counts.normalized_name = lower(core_npc_master.npc_name)
         WHERE {$where}
         ORDER BY npc_favorite DESC NULLS LAST, npc_name ASC, id ASC
         LIMIT {$limit} OFFSET {$offset}"
    );
    $profileMap = chimNpcManagerProfileMap($profiles);

    return [
        'npcs' => array_map(static function ($row) use ($profileMap) {
            return chimNpcManagerCard(chimNpcEffectiveProfile($row), $profileMap);
        }, (array)$rows),
        'profiles' => array_map(static function ($profile) {
            return ['id' => $profile['id'], 'label' => $profile['label']];
        }, $profiles),
        'pagination' => [
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'pages' => max(1, (int)ceil($total / $limit)),
        ],
    ];
}

const CHIM_NPC_MANAGER_CARD_MEMBER_LIMIT = 25;

// Opt-in (collapse_shared=1) list with one card per kept profile: explicitly linked references appear once,
// as their keeper (owner row); unlinked rows, including same-name namesakes, stay separate cards. Search and
// nearby filters match any physical member, the profile filter the keeper; all run before LIMIT/OFFSET.
// duplicate_count counts keeper cards sharing the keeper's name. card.profile_sharing.members lists up to
// CHIM_NPC_MANAGER_CARD_MEMBER_LIMIT physical members (keeper, exact nearby RefID, nearby name, search match
// first) in the detail identity shape; member_count is the full total and truncated members open by id.
function chimNpcManagerListCollapsed(array $profiles): array
{
    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = max(1, min(100, (int)($_GET['limit'] ?? 40)));
    $offset = ($page - 1) * $limit;
    $predicates = chimNpcManagerListPredicates('m');

    // A row whose owner is missing or itself linked keeps its own group, as the uncollapsed list shows it.
    $memberCte = "npc_member AS (
            SELECT m.*, COALESCE(owner.id, m.id) AS _group_id
            FROM core_npc_master m
            LEFT JOIN core_npc_master owner
                ON owner.id = m.profile_owner_npc_id AND owner.profile_owner_npc_id IS NULL
        )";
    $having = [];
    foreach (['search', 'nearby'] as $filter) {
        if ($predicates[$filter] !== null) {
            $having[] = "bool_or({$predicates[$filter]})";
        }
    }
    $groupCte = "grouped AS (
            SELECT m._group_id, COUNT(*) AS member_total
            FROM npc_member m
            GROUP BY m._group_id"
        . (empty($having) ? '' : ' HAVING ' . implode(' AND ', $having)) . "
        )";

    $keeperConditions = ["k.npc_name IS NOT NULL", "btrim(k.npc_name) <> ''"];
    $profileId = (int)($_GET['profile_id'] ?? 0);
    if ($profileId > 0) {
        $keeperConditions[] = "k.profile_id = {$profileId}";
    }
    $keeperWhere = implode(' AND ', $keeperConditions);

    $countRow = $GLOBALS['db']->fetchOne(
        "WITH {$memberCte}, {$groupCte}
         SELECT COUNT(*) AS total
         FROM grouped g
         JOIN core_npc_master k ON k.id = g._group_id
         WHERE {$keeperWhere}"
    );
    $total = (int)($countRow['total'] ?? 0);
    $rows = (array)$GLOBALS['db']->fetchAll(
        "WITH {$memberCte}, {$groupCte},
        name_counts AS (
            SELECT lower(npc_name) AS normalized_name, COUNT(*) AS duplicate_count
            FROM npc_member
            WHERE _group_id = id
            GROUP BY lower(npc_name)
        )
         SELECT k.*, g.member_total AS _member_total, name_counts.duplicate_count
         FROM grouped g
         JOIN core_npc_master k ON k.id = g._group_id
         LEFT JOIN name_counts ON name_counts.normalized_name = lower(k.npc_name)
         WHERE {$keeperWhere}
         ORDER BY k.npc_favorite DESC NULLS LAST, k.npc_name ASC, k.id ASC
         LIMIT {$limit} OFFSET {$offset}"
    );

    // One bounded query for the page's members: at most CHIM_NPC_MANAGER_CARD_MEMBER_LIMIT per card.
    $membersByGroup = [];
    $keeperIds = array_values(array_filter(array_map(static fn($row) => (int)($row['id'] ?? 0), $rows)));
    if (!empty($keeperIds)) {
        $idList = implode(',', $keeperIds);
        $nearbyRefidFlag = $predicates['nearby_refid'] ?? 'FALSE';
        $nearbyNameFlag = $predicates['nearby_name'] ?? 'FALSE';
        $matchFlag = $predicates['search'] ?? 'FALSE';
        $memberLimit = CHIM_NPC_MANAGER_CARD_MEMBER_LIMIT;
        // Same grouping as member_total, so a chained or dangling owner never adds rows to another card.
        $memberRows = (array)$GLOBALS['db']->fetchAll(
            "WITH {$memberCte}
            SELECT * FROM (
                SELECT flagged.*, ROW_NUMBER() OVER (
                    PARTITION BY flagged._group_id
                    ORDER BY flagged.is_keeper DESC, flagged.is_nearby_refid DESC, flagged.is_nearby_name DESC,
                        flagged.is_match DESC, flagged.id ASC
                ) AS member_rank
                FROM (
                    SELECT m.id, m.npc_name, m.refid, m.metadata, m.profile_owner_npc_id, m._group_id,
                        CASE WHEN m.id = m._group_id THEN 1 ELSE 0 END AS is_keeper,
                        CASE WHEN {$nearbyRefidFlag} THEN 1 ELSE 0 END AS is_nearby_refid,
                        CASE WHEN {$nearbyNameFlag} THEN 1 ELSE 0 END AS is_nearby_name,
                        CASE WHEN {$matchFlag} THEN 1 ELSE 0 END AS is_match
                    FROM npc_member m
                    WHERE m._group_id IN ({$idList})
                ) flagged
            ) ranked
            WHERE member_rank <= {$memberLimit}
            ORDER BY _group_id, member_rank"
        );
        foreach ($memberRows as $member) {
            $metadata = chimNpcManagerDecodeJson($member['metadata'] ?? '{}');
            $membersByGroup[(int)$member['_group_id']][] = [
                'id' => (int)$member['id'],
                'name' => trim((string)($member['npc_name'] ?? '')),
                'refid' => trim((string)($member['refid'] ?? '')),
                'refid_source' => (string)($metadata['refid_source'] ?? ''),
                'profile_owner_npc_id' => isset($member['profile_owner_npc_id'])
                    ? (int)$member['profile_owner_npc_id'] : null,
                'actor_key' => chimNpcRowActorKey($member),
                'keeper' => (int)$member['is_keeper'] === 1,
                // Only an exact RefID match is proximity; a name match may be any same-name actor.
                'nearby' => (int)$member['is_nearby_refid'] === 1,
                'nearby_name' => (int)$member['is_nearby_name'] === 1,
                'matched' => (int)$member['is_match'] === 1,
            ];
        }
    }

    $profileMap = chimNpcManagerProfileMap($profiles);
    $cards = [];
    foreach ($rows as $row) {
        $memberTotal = max(1, (int)($row['_member_total'] ?? 1));
        $row['_has_shared_profile'] = $memberTotal > 1;
        $card = chimNpcManagerCard(chimNpcEffectiveProfile($row), $profileMap);
        $members = $membersByGroup[$card['id']] ?? [];
        // Detail semantics: linked/automatic follow the group size; members use the detail identity fields.
        $card['profile_sharing']['linked'] = $memberTotal > 1;
        $card['profile_sharing']['automatic'] = $memberTotal > 1 && $card['profile_sharing']['automatic'];
        $card['profile_sharing']['members'] = $members;
        $card['profile_sharing']['member_count'] = $memberTotal;
        $card['profile_sharing']['members_truncated'] = count($members) < $memberTotal;
        $card['member_count'] = $memberTotal;
        $cards[] = $card;
    }

    return [
        'collapsed_shared' => true,
        'npcs' => $cards,
        'profiles' => array_map(static function ($profile) {
            return ['id' => $profile['id'], 'label' => $profile['label']];
        }, $profiles),
        'pagination' => [
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'pages' => max(1, (int)ceil($total / $limit)),
        ],
    ];
}

// Untyped name-keyed edges are legacy/display-only: never renamed, attributed or reclassified here.
function chimNpcManagerIsLegacyEdge(string $key, $rel): bool
{
    return is_array($rel) && $key !== 'Player' && !is_array($rel['target'] ?? null)
        && !RelationshipManager::isActorEdgeKey($key);
}

function chimNpcManagerEdgeFields(array $edge, array $rel): array
{
    if (array_key_exists('aff', $edge)) {
        $rel['aff'] = max(-100, min(100, (int)$edge['aff']));
    }
    if (array_key_exists('type', $edge)) {
        $type = strtolower(trim(is_scalar($edge['type']) ? (string)$edge['type'] : ''));
        $rel['type'] = $type === '' ? 'neutral' : $type;
    }
    foreach (['note', 'custom_info'] as $key) {
        if (array_key_exists($key, $edge)) {
            $rel[$key] = is_scalar($edge[$key]) ? trim((string)$edge[$key]) : '';
        }
    }
    return $rel;
}

// Validate a submitted relationship map against the stored one (read under the owner lock).
// Existing edges keep their map key and stored target; only aff/type/note/custom_info change. Legacy
// edges are kept exactly as stored. New edges need an explicit target: Player, an actor row (selected by
// its id and its own physical card key) or a typed concept. An actor edge is stored under the target's
// current effective key (RelationshipManager link-owner policy), so a linked member's card shares its
// keeper's edge; the label stays the selected row's name. $targetRows collects those rows so the write can
// recheck their binding and keys under row locks.
function chimNpcManagerRelationshipEdits($submitted, array $stored, array $row, array &$targetRows = []): array
{
    if (!is_array($submitted)) {
        throw new ChimNpcManagerInvalid('Relationships must be an object');
    }
    $result = [];
    foreach ($stored as $key => $rel) {
        if (chimNpcManagerIsLegacyEdge((string)$key, $rel)) {
            $result[$key] = $rel;
        }
    }
    $ownKeys = chimNpcRowActorKey($row) !== null || !empty($row['profile_owner_npc_id'])
        ? chimNpcProfileActorKeys($row) : [];

    foreach ($submitted as $key => $edge) {
        $key = (string)$key;
        if (!is_array($edge) || $key === '') {
            throw new ChimNpcManagerInvalid('Invalid relationship entry');
        }
        $target = $edge['target'] ?? null;
        $storedRel = $stored[$key] ?? null;
        if (is_array($storedRel)) {
            if (chimNpcManagerIsLegacyEdge($key, $storedRel)) {
                if ($target !== null) {
                    throw new ChimNpcManagerInvalid('Legacy relationship "' . $key . '" cannot be given a target here');
                }
                continue;
            }
            $storedTarget = $storedRel['target'] ?? null;
            $playerTarget = $key === 'Player' && is_array($target)
                && ($target['kind'] ?? '') === 'player' && ($target['key'] ?? '') === 'Player';
            if ($target !== null && !$playerTarget && $target != $storedTarget) {
                throw new ChimNpcManagerInvalid('Relationship "' . $key . '" no longer has that target. Reopen this NPC.');
            }
            $result[$key] = chimNpcManagerEdgeFields($edge, $storedRel);
            continue;
        }

        $rel = chimNpcManagerEdgeFields($edge, ['aff' => 0, 'type' => 'neutral']);
        if ($key === 'Player') {
            if ($target !== null && (!is_array($target) || ($target['kind'] ?? '') !== 'player' || ($target['key'] ?? '') !== 'Player')) {
                throw new ChimNpcManagerInvalid('Invalid Player relationship target');
            }
            RelationshipManager::storeTargetEdge($result, RelationshipManager::playerTargetIdentity(), null, $rel);
            continue;
        }
        if (!is_array($target)) {
            throw new ChimNpcManagerInvalid('New relationship "' . $key . '" needs an explicit target type');
        }
        $kind = $target['kind'] ?? '';
        if ($kind === 'actor') {
            $targetId = (int)($edge['target_npc_id'] ?? 0);
            if (($target['key'] ?? null) !== $key) {
                throw new ChimNpcManagerInvalid('Relationship target does not match that NPC. Search for it again.');
            }
            // The card key is the row's own physical key; the current effective (keeper) key is also accepted.
            $taken = array_merge(array_map('strval', array_keys($stored)), array_map('strval', array_keys($result)),
                array_values(array_filter(array_map('strval', array_keys($submitted)), static fn($k) => $k !== $key)));
            try {
                [$identity, $targetRow] = RelationshipManager::validateNewActorTarget($key, $targetId, $row, $taken);
            } catch (InvalidArgumentException $invalid) {
                throw new ChimNpcManagerInvalid($invalid->getMessage());
            }
            // The label is the target row's current name, display only; target_npc_id is not stored.
            RelationshipManager::storeTargetEdge($result, $identity, null, $rel);
            $targetRows[$targetId] = $targetRow;
            continue;
        }
        if ($kind === 'concept') {
            $label = $target['label'] ?? null;
            if (!is_string($label) || $label !== trim($label) || $label !== $key || ($target['key'] ?? null) !== $key
                || chimIsActorKey($label) || strcasecmp($label, 'Player') === 0
                || RelationshipManager::normalizeTargetName($label) === 'Player') {
                throw new ChimNpcManagerInvalid('Invalid concept relationship "' . $key . '"');
            }
            RelationshipManager::storeTargetEdge($result, ['kind' => 'concept', 'key' => $key, 'label' => $label], null, $rel);
            continue;
        }
        throw new ChimNpcManagerInvalid('Unsupported relationship target type');
    }
    return $result;
}

function chimNpcManagerSave(array $input, array $profiles): array
{
    $row = chimNpcManagerFindNpc($input);
    $id = (int)$row['id'];
    chimNpcManagerGuardExpectedKey($input, $row);
    $ownerId = (int)($row['profile_owner_npc_id'] ?? $id);
    $lockId = 1001000000 + $ownerId;
    $GLOBALS['db']->execQuery("SELECT pg_advisory_lock({$lockId})");
    try {
        // Everything below is validated against the state read under the owner lock that it writes over.
        $row = chimNpcManagerFindNpc(['id' => $id]);
        if ((int)($row['profile_owner_npc_id'] ?? $id) !== $ownerId) {
            throw new ChimNpcManagerConflict('Profile changed. Reopen this NPC before saving.');
        }
        chimNpcManagerGuardExpectedKey($input, $row);
        $raw = (new NpcMaster())->getActorById($id);
        $members = chimNpcProfileMembers($raw);
        // Older clients remain compatible for never-linked rows. A shared/previously shared editor must be current.
        if (chimNpcProfileBinding($raw) !== ':' || isset($input['profile_revision'])) {
            if (!hash_equals(chimNpcProfileRevision($members), (string)($input['profile_revision'] ?? ''))) {
                throw new ChimNpcManagerConflict('Profile changed. Reopen this NPC before saving.');
            }
        }
        $fields = is_array($input['fields'] ?? null) ? $input['fields'] : [];
        $overrides = is_array($input['overrides'] ?? null) ? $input['overrides'] : [];
        $update = ['_profile_binding' => $row['_profile_binding'] ?? ':'];

        $allowedFields = [
            'npc_name', 'profile_id', 'lock_profile', 'npc_favorite', 'gender', 'race', 'base',
            'refid', 'voiceid', 'oghma_knowledge_tags', 'tags', 'prompt_head', 'core',
            'npc_static_bio', 'appearance', 'personality', 'occupation', 'skills', 'speechstyle',
            'goals', 'emote_moods',
        ];
        foreach ($allowedFields as $field) {
            if (!array_key_exists($field, $fields)) {
                continue;
            }
            if (in_array($field, ['lock_profile', 'npc_favorite'], true)) {
                $update[$field] = chimNpcManagerBool($fields[$field]) ? 1 : 0;
            } elseif ($field === 'profile_id') {
                $profileId = (int)$fields[$field];
                $profileExists = false;
                foreach ($profiles as $profile) {
                    if ((int)$profile['id'] === $profileId) {
                        $profileExists = true;
                        break;
                    }
                }
                if (!$profileExists) {
                    throw new InvalidArgumentException('Selected profile does not exist');
                }
                $update[$field] = $profileId;
            } else {
                $update[$field] = trim((string)$fields[$field]);
            }
        }

        if (array_key_exists('npc_name', $update) && $update['npc_name'] === '') {
            throw new InvalidArgumentException('NPC name is required');
        }

        // RefID is part of the profile selector, so management cannot change it independently.
        if (NpcMaster::isActorBound($row)) {
            unset($update['refid']);
        }

        // The lookup key follows the stored physical reference, never a display name or client-supplied hash.
        $update['md5'] = NpcMaster::identityMd5($row, $update['npc_name'] ?? ($row['npc_name'] ?? ''));

        if (array_key_exists('tts_filter_preset', $fields)) {
            $update['metadata'] = mergeTtsFilterPresetIntoMetadata(
                $row['metadata'] ?? '{}',
                $fields['tts_filter_preset']
            );
        }

        // Starts from stored data: server-owned keys (provenance, dormant entries, relationship metadata)
        // are never taken from the request.
        $extended = chimNpcManagerDecodeJson($row['extended_data'] ?? '{}');
        $relationshipChanged = false;
        $targetRows = [];
        $toggleMap = [
            'middle_term_enabled' => 'middle_term_enabled',
            'individual_memory_enabled' => 'individual_memory_enabled',
            'auto_diary_enabled' => 'auto_diary_enabled',
            'auto_diary_wait_enabled' => 'auto_diary_wait_enabled',
            'salutation_after_a_while' => 'salutation_after_a_while',
        ];
        if (array_key_exists('dynamic_profile', $overrides)) {
            $update['dynamic_profile'] = $overrides['dynamic_profile'] === null
                ? null
                : (chimNpcManagerBool($overrides['dynamic_profile']) ? 1 : 0);
        }
        foreach ($toggleMap as $requestKey => $extendedKey) {
            if (!array_key_exists($requestKey, $overrides)) {
                continue;
            }
            if ($overrides[$requestKey] === null) {
                unset($extended[$extendedKey]);
            } else {
                $extended[$extendedKey] = chimNpcManagerBool($overrides[$requestKey]) ? 1 : 0;
            }
        }

        if (array_key_exists('middle_term_latest', $fields)) {
            chimMiddleTermApplyManualDigest($extended, trim((string)$fields['middle_term_latest']), $row);
        }

        if (array_key_exists('relationships', $input)) {
            $stored = is_array($extended['relationships'] ?? null) ? $extended['relationships'] : [];
            $relationships = chimNpcManagerRelationshipEdits($input['relationships'], $stored, $row, $targetRows);
            if ($relationships !== $stored) {
                $extended['relationships'] = $relationships;
                $relationshipChanged = true;
            }
        }
        if (array_key_exists('relationships_locked', $input)) {
            $locked = chimNpcManagerBool($input['relationships_locked']);
            if ($locked !== chimNpcManagerBool($extended['relationships_locked'] ?? false)) {
                $relationshipChanged = true;
            }
            $extended['relationships_locked'] = $locked;
        }

        if (!array_key_exists('AUTO_LOCK_PROFILE', $GLOBALS) || chimNpcManagerBool($GLOBALS['AUTO_LOCK_PROFILE'])) {
            $update['lock_profile'] = 1;
        }

        $update['extended_data'] = json_encode($extended, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        // Workers (middle-term generator, relationship commands) do not take the owner advisory lock; commit
        // only if extended_data is unchanged since it was read under that lock, otherwise refuse (409).
        $readExtended = (string)($row['extended_data'] ?? '');
        $update['_commit_guard'] = static function () use ($id, $readExtended) {
            return (string)(chimNpcManagerFindNpc(['id' => $id])['extended_data'] ?? '') === $readExtended;
        };
        if ($targetRows) {
            // New actor targets: their binding and physical/keeper keys are rechecked under row locks in the
            // write, so an unlink/relink or key change after validation refuses instead of storing a stale key.
            foreach ($targetRows as $targetId => $targetRow) {
                $update['_expected_bindings'][$targetId] = chimNpcProfileBinding($targetRow);
            }
            $update['_expected_keys'] = RelationshipManager::expectedKeysFor(array_values($targetRows));
        }
        $save = static function () use ($id, $update) {
            $manager = new NpcMaster();
            return $manager->update($id, $update);
        };
        $saved = $relationshipChanged ? chimRunWithRelationshipExtendedDataWrite($save) : $save();
        if ($saved === false) {
            // Binding, related targets and extended_data were rechecked under row locks.
            throw new ChimNpcManagerConflict('This NPC changed while saving. Reopen it before saving again.');
        }
        if ($relationshipChanged && function_exists('chimRelationshipTimelineStamp')) {
            chimRelationshipTimelineStamp($ownerId);
        }
        (new NpcMaster())->backupNpcById($ownerId);
    } finally {
        $GLOBALS['db']->execQuery("SELECT pg_advisory_unlock({$lockId})");
    }

    $fresh = (new NpcMaster())->getById($id);
    if (!$fresh) {
        throw new RuntimeException('NPC could not be reloaded after saving');
    }
    return chimNpcManagerDetail($fresh, $profiles);
}

try {
    $profiles = chimNpcManagerProfiles();
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if ($method === 'POST') {
        $input = json_decode((string)file_get_contents('php://input'), true);
        if (!is_array($input)) {
            throw new InvalidArgumentException('Invalid JSON request');
        }
        $operation = strtolower(trim((string)($input['operation'] ?? 'save')));
        if ($operation === 'inject_event') {
            chimNpcManagerRespond(['success' => true, 'data' => chimNpcManagerInjectEvent($input)]);
        }
        if ($operation === 'delete_event') {
            chimNpcManagerRespond(['success' => true, 'data' => chimNpcManagerDeleteEvent($input)]);
        }
        if ($operation === 'action') {
            chimNpcManagerRespond(['success' => true, 'data' => chimNpcManagerAction($input)]);
        }
        if ($operation === 'reference_group_save') {
            chimNpcManagerRespond(['success' => true, 'data' => chimNpcSaveReferenceGroup($input)]);
        }
        if ($operation === 'reference_group_delete') {
            chimNpcManagerRespond(['success' => true, 'data' => chimNpcDeleteReferenceGroup(
                (string)($input['group_key'] ?? '')
            )]);
        }
        if ($operation !== 'save') {
            throw new InvalidArgumentException('Unsupported NPC manager operation');
        }
        chimNpcManagerRespond(['success' => true, 'data' => chimNpcManagerSave($input, $profiles)]);
    }

    $operation = strtolower(trim((string)($_GET['operation'] ?? 'list')));
    if ($operation === 'list') {
        chimNpcManagerRespond(['success' => true, 'data' => chimNpcManagerList($profiles)]);
    }
    if ($operation === 'detail') {
        $row = chimNpcManagerFindNpc($_GET);
        chimNpcManagerGuardExpectedKey($_GET, $row);
        chimNpcManagerRespond(['success' => true, 'data' => chimNpcManagerDetail($row, $profiles)]);
    }
    if ($operation === 'history') {
        chimNpcManagerRespond(['success' => true, 'data' => chimNpcManagerHistory($_GET)]);
    }
    if ($operation === 'reference_groups') {
        chimNpcManagerRespond(['success' => true, 'data' => chimNpcReferenceGroupCatalog()]);
    }
    throw new InvalidArgumentException('Unsupported NPC manager operation');
} catch (ChimNpcManagerConflict $error) {
    chimNpcManagerRespond(['success' => false, 'error' => $error->getMessage(), 'conflict' => true], 409);
} catch (ChimNpcManagerInvalid $error) {
    chimNpcManagerRespond(['success' => false, 'error' => $error->getMessage()], 422);
} catch (InvalidArgumentException $error) {
    chimNpcManagerRespond(['success' => false, 'error' => $error->getMessage()], 400);
} catch (Throwable $error) {
    Logger::error('CHIM NPC manager API failed: ' . $error->getMessage());
    chimNpcManagerRespond(['success' => false, 'error' => 'Unable to process NPC manager request'], 500);
}
