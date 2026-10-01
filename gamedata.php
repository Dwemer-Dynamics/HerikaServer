<?php
require_once __DIR__ . "/lib/playthrough_guard.php";
pgr_http_preflight("gamedata");
/**
 * Game Data Endpoint
 * 
 * Handles JSON POST requests for equipment, inventory, skills, and stats updates
 * for both NPCs and player data.
 * 
 * This endpoint does not trigger LLM requests - it only updates database metadata.
 */

error_reporting(E_ALL);
ini_set('display_errors', '0'); // Don't output errors to response body
require_once(__DIR__ . "/lib/runtime_bootstrap.php");
chimRuntimeBootstrap(__DIR__, [
    'load_general_settings' => true,
    'load_stt_connector' => false,
    'load_itt_connector' => false,
    'load_player_name' => true,
    'load_narrator' => true,
]);
$GLOBALS["db"] = $GLOBALS["db"] ?? new sql();
require_once(__DIR__ . "/lib/core/npc_master.class.php");
require_once(__DIR__ . "/lib/core/activity_status.php");
require_once(__DIR__ . "/lib/core/transformation_state.php");
require_once(__DIR__ . "/lib/core/game_plugins.php");
require_once(__DIR__ . "/lib/core/npc_reference.php");
require_once(__DIR__ . "/lib/logger.php");
require_once(__DIR__ . "/lib/chim_quest_engine.php");
require_once(__DIR__ . "/lib/quest_reference_data.php");

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo "Method Not Allowed";
    exit;
}

// Parse JSON body
$json = file_get_contents('php://input');
$data = json_decode($json, true);

if (!$data || !isset($data['type'])) {
    http_response_code(400);
    echo "Bad Request: Missing type field";
    Logger::error("[gamedata.php] Bad request - missing type field <$json>");
    exit;
}

// Types that operate on global data and do not require an actor
$actorlessTypes = [
    'market_stock',
    'activity_status_bulk',
    'transformation_state_bulk',
    'loaded_plugins',
    'quest_event',
    'quest_action_poll',
    'quest_action_ack',
    'quest_import_bundled',
    'quest_reset_runtime',
    'quest_status',
    'player_item_acquired',
    'player_items_acquired'
];

// Validate required fields (skipped for actorless types)
if (!in_array($data['type'], $actorlessTypes)) {
    if (!isset($data['actor_name']) || !isset($data['actor_type'])) {
        http_response_code(400);
        echo "Bad Request: Missing actor_name or actor_type";
        Logger::error("[gamedata.php] Bad request - missing actor_name or actor_type");
        exit;
    }
}

$npcMaster = new NpcMaster();
$responseBody = "OK";
$responseIsJson = false;

// Paired identity contract (docs/actor-identity.md): every actor-bearing row is resolved on its own, bulk rows
// included. Invalid explicit identity (actor, attack target pair or nearby entry) rejects the whole request (422)
// before any write; a stale runtime bind, duplicate key or unregistered explicit key rejects a single-actor
// request (409, so the client retries); bulk rows that are stale or unregistered are skipped and reported per row.
$gamedataBulkRows = ['activity_status_bulk' => 'statuses', 'transformation_state_bulk' => 'states'];
$gamedataIdentityRows = isset($gamedataBulkRows[$data['type']])
    ? (is_array($data[$gamedataBulkRows[$data['type']]] ?? null) ? $data[$gamedataBulkRows[$data['type']]] : [])
    : (in_array($data['type'], $actorlessTypes, true) ? [] : [$data]);
foreach ($gamedataIdentityRows as $gamedataIndex => $gamedataRow) {
    if (!is_array($gamedataRow)) { continue; }
    $gamedataResolved = gamedataResolveActor($gamedataRow, $npcMaster);
    $gamedataStatus = $gamedataResolved['status'];
    if ($gamedataStatus === 'invalid') {
        gamedataRejectIdentity(422, $gamedataResolved['reason'], $gamedataIndex);
    }
    if (in_array($data['type'], ['activity_status', 'activity_status_bulk'], true)) {
        $gamedataTargetError = gamedataValidateAttackTarget($gamedataRow);
        if ($gamedataTargetError !== null) { gamedataRejectIdentity(422, $gamedataTargetError, $gamedataIndex); }
    }
    if (!isset($gamedataBulkRows[$data['type']]) && (in_array($gamedataStatus, ['stale', 'conflict'], true)
        || ($gamedataStatus === 'unknown' && $gamedataResolved['reason'] === 'unregistered_actor_key'))) {
        gamedataRejectIdentity(409, $gamedataResolved['reason'], $gamedataIndex);
    }
}
if ($data['type'] === 'low_process_actors' && is_array($data['actors_nearby'] ?? null)) {
    foreach ($data['actors_nearby'] as $gamedataIndex => $gamedataEntry) {
        $gamedataEntryError = is_array($gamedataEntry) ? gamedataValidateNearbyEntry($gamedataEntry) : 'nearby_entry_invalid';
        if ($gamedataEntryError !== null) { gamedataRejectIdentity(422, $gamedataEntryError, 'actors_nearby.' . $gamedataIndex); }
    }
}
$GLOBALS['GAMEDATA_BULK_RESULTS'] = [];

// Key/refid pair shared by actor rows, attack targets and nearby entries; null when valid.
function gamedataPairError($key, $ref, string $prefix): ?string
{
    if (!is_string($key) || !chimIsActorKey($key) || $key === CHIM_ACTOR_KEY_NARRATOR) { return $prefix . '_key_invalid'; }
    if (!is_string($ref) || !preg_match('/^[0-9A-F]{8}$/D', $ref)) { return $prefix . '_refid_invalid'; }
    if (($key === CHIM_ACTOR_KEY_PLAYER) !== ($ref === '00000014')) { return $prefix . '_player_mismatch'; }
    return null;
}

// C18 attack target: attack_target_key/attack_target_refid both present, both null (no known target) or a valid
// pair. Rows from older clients carry neither and keep the display name only. Never resolved by name.
function gamedataValidateAttackTarget(array $row): ?string
{
    $hasKey = array_key_exists('attack_target_key', $row);
    if ($hasKey !== array_key_exists('attack_target_refid', $row)) { return 'attack_target_pair_incomplete'; }
    if (!$hasKey) { return null; }
    $key = $row['attack_target_key'];
    $ref = $row['attack_target_refid'];
    if ($key === null && $ref === null) { return null; }
    if ($key === null || $ref === null) { return 'attack_target_pair_incomplete'; }
    return gamedataPairError($key, $ref, 'attack_target');
}

// Nearby entry identity (C18 low_process_actors): fields all present or none, the pair valid and matching formId.
function gamedataValidateNearbyEntry(array $entry): ?string
{
    $keyed = array_key_exists('actor_identity_version', $entry) || array_key_exists('actor_key', $entry) || array_key_exists('actor_refid', $entry);
    if (!$keyed) { return null; }
    if (($entry['actor_identity_version'] ?? null) !== 1) { return 'nearby_identity_version'; }
    $error = gamedataPairError($entry['actor_key'] ?? null, $entry['actor_refid'] ?? null, 'nearby');
    if ($error !== null) { return $error; }
    if (isset($entry['formId']) && gamedataFormIdHex($entry['formId']) !== $entry['actor_refid']) { return 'nearby_refid_mismatch'; }
    return null;
}

function gamedataFormIdHex($formId): string
{
    return strtoupper(str_pad(dechex(((int)$formId) & 0xFFFFFFFF), 8, '0', STR_PAD_LEFT));
}

// Binds a validated attack target to its exact row when that row still holds the captured runtime ref. The
// display name comes only from the captured keyed target; a null pair clears it.
function gamedataBindAttackTarget(array $row, NpcMaster $npcMaster): array
{
    if (!array_key_exists('attack_target_key', $row)) { return $row; }
    $key = $row['attack_target_key'];
    $row['attack_target_npc_id'] = null;
    if ($key === null) {
        unset($row['attack_target']);
        $row['attack_target_binding'] = 'none';
        return $row;
    }
    if ($key === CHIM_ACTOR_KEY_PLAYER) {
        $row['attack_target_binding'] = 'player';
        return $row;
    }
    try {
        $target = $npcMaster->getByActorKey($key);
    } catch (RuntimeException $e) {
        $target = null;
    }
    if (!$target) {
        $row['attack_target_binding'] = 'unregistered';
    } elseif (NpcMaster::normalizeRefId($target['refid'] ?? '') !== $row['attack_target_refid']) {
        $row['attack_target_binding'] = 'stale';
    } else {
        $row['attack_target_binding'] = 'npc';
        $row['attack_target_npc_id'] = (int)$target['id'];
    }
    return $row;
}

function gamedataBulkResult($index, string $status, string $reason = ''): void
{
    $GLOBALS['GAMEDATA_BULK_RESULTS'][] = ['index' => $index, 'status' => $status, 'reason' => $reason];
}

// Bulk rows that did not resolve to an applied write are reported, never counted as applied.
function gamedataBulkApply(array $row, $index, NpcMaster $npcMaster, string $metadataKey): void
{
    $resolved = gamedataResolveActor($row, $npcMaster);
    if ($resolved['status'] !== 'npc') {
        gamedataBulkResult($index, 'skipped', $resolved['reason'] !== '' ? $resolved['reason'] : $resolved['status']);
        return;
    }
    if ($metadataKey === 'activity_status') { $row = gamedataBindAttackTarget($row, $npcMaster); }
    $ok = chimApplyNpcMetadataUpdatesByName($resolved['row'], [$metadataKey => $row]);
    gamedataBulkResult($index, $ok ? 'applied' : 'skipped', $ok ? '' : (isset($resolved['row']['_expected_refid']) ? 'stale_runtime_ref' : 'write_failed'));
}

function gamedataRejectIdentity(int $code, string $reason, $index): void
{
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'actor_identity', 'reason' => $reason, 'index' => $index]);
    Logger::warn("[gamedata.php] Rejected actor identity ({$code} {$reason}) row {$index}");
    exit;
}

// One actor row: 'player' (core_player only), 'npc' (exact row), 'unknown' (safe ignore), 'stale', 'conflict'
// or 'invalid'. Rows without identity fields keep the unique-name legacy lookup; any identity field makes the
// row strict with no name fallback.
function gamedataResolveActor(array $item, NpcMaster $npcMaster): array
{
    $type = (string)($item['actor_type'] ?? '');
    $keyed = array_key_exists('actor_identity_version', $item) || array_key_exists('actor_key', $item) || array_key_exists('actor_refid', $item);
    if (!$keyed) {
        if ($type === 'player') { return ['status' => 'player', 'reason' => '']; }
        $row = $npcMaster->getByName((string)($item['actor_name'] ?? ''));
        return $row ? ['status' => 'npc', 'row' => $row, 'reason' => ''] : ['status' => 'unknown', 'reason' => 'legacy_name_unresolved'];
    }
    $key = $item['actor_key'] ?? null;
    $ref = $item['actor_refid'] ?? null;
    if (($item['actor_identity_version'] ?? null) !== 1) { return ['status' => 'invalid', 'reason' => 'unsupported_identity_version']; }
    if (!in_array($type, ['npc', 'player'], true)) { return ['status' => 'invalid', 'reason' => 'actor_type_invalid']; }
    if (!is_string($key) || !chimIsActorKey($key) || $key === CHIM_ACTOR_KEY_NARRATOR) { return ['status' => 'invalid', 'reason' => 'actor_key_invalid']; }
    if (!is_string($ref) || !preg_match('/^[0-9A-F]{8}$/D', $ref)) { return ['status' => 'invalid', 'reason' => 'actor_refid_invalid']; }
    if (($key === CHIM_ACTOR_KEY_PLAYER) !== ($type === 'player') || ($type === 'player' && $ref !== '00000014')) {
        return ['status' => 'invalid', 'reason' => 'actor_type_mismatch'];
    }
    if ($type === 'player') { return ['status' => 'player', 'reason' => '']; }
    try {
        $row = $npcMaster->getByActorKey($key);
    } catch (RuntimeException $e) {
        return ['status' => 'conflict', 'reason' => 'duplicate_actor_key'];
    }
    if (!$row) { return ['status' => 'unknown', 'reason' => 'unregistered_actor_key']; }
    if (NpcMaster::normalizeRefId($row['refid'] ?? '') !== $ref) { return ['status' => 'stale', 'reason' => 'stale_runtime_ref']; }
    $row['_expected_refid'] = $ref;
    return ['status' => 'npc', 'row' => $row, 'reason' => ''];
}

// The exact NPC row for a handler, or null (player, unregistered, stale). Keyed rows carry _expected_refid.
function gamedataActorRow(array $item, NpcMaster $npcMaster): ?array
{
    $resolved = gamedataResolveActor($item, $npcMaster);
    if ($resolved['status'] !== 'npc') {
        if ($resolved['status'] !== 'player') { Logger::debug("[gamedata.php] Ignored actor row: {$resolved['reason']}"); }
        return null;
    }
    return $resolved['row'];
}

// Physical metadata of the exact row; a keyed row writes only while it still holds the bound runtime ref.
function gamedataUpdateMetadata(NpcMaster $npcMaster, ?array $row, array $setValues): bool
{
    if (!$row || (int)($row['id'] ?? 0) <= 0) { return false; }
    $expected = isset($row['_expected_refid']) ? (string)$row['_expected_refid'] : null;
    $ok = $npcMaster->updateMetadataKeysById((int)$row['id'], $setValues, [], $expected);
    if (!$ok && $expected !== null) { $GLOBALS['GAMEDATA_STALE_WRITE'] = true; }
    return $ok;
}

function gamedataApplyMetadata(?array $row, array $updates): bool
{
    if (!$row) { return false; }
    $ok = chimApplyNpcMetadataUpdatesByName($row, $updates);
    if (!$ok && isset($row['_expected_refid'])) { $GLOBALS['GAMEDATA_STALE_WRITE'] = true; }
    return $ok;
}

try {
    if (($data['actor_type'] ?? '') === 'player') {
        chimMaybeSyncPlayerName($data['actor_name'] ?? null, true);
    }
    switch ($data['type']) {
        case 'equipment':
            handleEquipmentUpdate($data, $npcMaster);
            break;
        case 'inventory':
            handleInventoryUpdate($data, $npcMaster);
            break;
        case 'skills':
            handleSkillsUpdate($data, $npcMaster);
            break;
        case 'stats':
            handleStatsUpdate($data, $npcMaster);
            break;
        case 'spells':
            // Only handle NPC spells, not player spells (deprecated for player)
            if ($data['actor_type'] !== 'player') {
                handleSpellsUpdate($data, $npcMaster);
            }
            break;
        case 'skyrim_stats':
            handleSkyrimStatsUpdate($data);
            break;
        case 'furniture':
            handleFurnitureUpdate($data, $npcMaster);
            break;
        case 'activity_status':
            handleActivityStatusUpdate($data, $npcMaster);
            break;
        case 'activity_status_bulk':
            handleActivityStatusBulkUpdate($data, $npcMaster);
            break;
        case 'transformation_state':
            handleTransformationStateUpdate($data, $npcMaster);
            break;
        case 'transformation_state_bulk':
            handleTransformationStateBulkUpdate($data, $npcMaster);
            break;
        case 'market_stock':
            handleMarketStockUpdate($data);
            break;
        case 'loaded_plugins':
            handleLoadedPluginsUpdate($data);
            break;
        case 'low_process_actors':
            handleLowProcessActorsUpdate($data, $npcMaster);
            break;
        case 'quest_event':
            $responseBody = chimQuestEngineJsonEncode(chimQuestEngineHandleEvent(
                $data['event_type'] ?? '',
                (isset($data['payload']) && is_array($data['payload'])) ? $data['payload'] : $data
            ));
            $responseIsJson = true;
            break;
        case 'quest_action_poll':
            $responseBody = chimQuestEngineJsonEncode(array(
                'ok' => true,
                'actions' => chimQuestEngineFetchPendingActions($data['limit'] ?? 25),
            ));
            $responseIsJson = true;
            break;
        case 'quest_action_ack':
            $responseBody = chimQuestEngineJsonEncode(chimQuestEngineAcknowledgeAction(
                $data['action_id'] ?? 0,
                $data['status'] ?? 'applied',
                (isset($data['result']) && is_array($data['result'])) ? $data['result'] : array()
            ));
            $responseIsJson = true;
            break;
        case 'quest_import_bundled':
            $responseBody = chimQuestEngineJsonEncode(array(
                'ok' => true,
                'imported' => chimQuestEngineImportBundledDefinitions(),
            ));
            $responseIsJson = true;
            break;
        case 'quest_reset_runtime':
            $responseBody = chimQuestEngineJsonEncode(array(
                'ok' => chimQuestEngineResetRuntime(true),
            ));
            $responseIsJson = true;
            break;
        case 'quest_status':
            $responseBody = chimQuestEngineJsonEncode(chimQuestEngineStatus());
            $responseIsJson = true;
            break;
        case 'player_item_acquired':
            handlePlayerItemAcquired($data);
            break;
        case 'player_items_acquired':
            handlePlayerItemsAcquired($data);
            break;
        default:
            http_response_code(400);
            echo "Bad Request: Unknown type";
            Logger::error("[gamedata.php] Bad request - unknown type: {$data['type']}");
            exit;
    }

    if (!empty($GLOBALS['GAMEDATA_STALE_WRITE']) && !isset($gamedataBulkRows[$data['type']])) {
        gamedataRejectIdentity(409, 'stale_runtime_ref', 0);
    }
    if (isset($gamedataBulkRows[$data['type']])) {
        $gamedataResults = $GLOBALS['GAMEDATA_BULK_RESULTS'];
        $gamedataApplied = count(array_filter($gamedataResults, function ($r) { return $r['status'] === 'applied'; }));
        $gamedataSkipped = count(array_filter($gamedataResults, function ($r) { return $r['status'] === 'skipped'; }));
        $responseBody = json_encode(['ok' => true, 'applied' => $gamedataApplied, 'skipped' => $gamedataSkipped,
            'complete' => $gamedataSkipped === 0, 'rows' => $gamedataResults]);
        $responseIsJson = true;
    }
    if ($responseIsJson) {
        header('Content-Type: application/json');
    }
    echo $responseBody;
} catch (Exception $e) {
    http_response_code(500);
    echo "Internal Server Error";
    Logger::error("[gamedata.php] Error processing request: " . $e->getMessage());
}

/**
 * Handle equipment update
 */
function handleEquipmentUpdate(array $data, NpcMaster $npcMaster): void
{
    $actorName = $data['actor_name'];
    $actorType = $data['actor_type'];

    if (!isset($data['equipment'])) {
        Logger::error("[gamedata.php] Equipment update missing equipment data");
        return;
    }

    $equipment = $data['equipment'];

    // If this is a player, save directly to core_player table (player doesn't need NPC record)
    if ($actorType === 'player') {
        try {
            require_once(__DIR__ . "/lib/core/player.class.php");
            $player = new Player();

            $equipmentData = buildEquipmentMetadataValue($equipment);
            $player->setJson('equipment', $equipmentData);
            Logger::debug("[gamedata.php] Saved player equipment to core_player table");
        } catch (Exception $e) {
            Logger::warn("[gamedata.php] Could not save player equipment to core_player: " . $e->getMessage());
        }

        // Typed player data lives only in core_player; an NPC namesake is never written.

        return; // Done with player, exit early
    }

    // Handle NPC equipment
    $currentData = gamedataActorRow($data, $npcMaster);

    if (!$currentData) {
        // NPC not in database yet - this is normal, they haven't been encountered
        return;
    }

    gamedataUpdateMetadata($npcMaster, $currentData, [
        'equipment' => buildEquipmentMetadataValue($equipment),
        'last_equipment_update_gamets' => $data['gamets'] ?? null,
    ]);

    Logger::debug("[gamedata.php] Updated equipment for {$actorType}: {$actorName}");
}

function handleFurnitureUpdate(array $data, NpcMaster $npcMaster): void
{
    $currentData = gamedataActorRow($data, $npcMaster);
    if (!$currentData) {
        return;
    }

    gamedataApplyMetadata($currentData, [
        'activity_status' => [
            'furniture_name' => $data['furniture'] ?? '',
            'timestamp' => $data['timestamp'] ?? chimActivityStatusNowMs(),
            'gamets' => $data['gamets'] ?? 0,
        ],
    ]);
}

function handleActivityStatusUpdate(array $data, NpcMaster $npcMaster): void
{
    $currentData = gamedataActorRow($data, $npcMaster);
    if (!$currentData) {
        return;
    }

    gamedataApplyMetadata($currentData, [
        'activity_status' => gamedataBindAttackTarget($data, $npcMaster),
    ]);
}

function handleActivityStatusBulkUpdate(array $data, NpcMaster $npcMaster): void
{
    if (empty($data['statuses']) || !is_array($data['statuses'])) {
        Logger::warn("[gamedata.php] activity_status_bulk missing statuses payload");
        return;
    }

    foreach ($data['statuses'] as $index => $statusRow) {
        if (!is_array($statusRow) || empty($statusRow['actor_name'])) {
            gamedataBulkResult($index, 'skipped', 'row_invalid');
            continue;
        }
        if (($statusRow['actor_type'] ?? '') === 'player') {
            gamedataBulkResult($index, 'noop', 'player_activity_not_stored');
            continue;
        }

        gamedataBulkApply($statusRow, $index, $npcMaster, 'activity_status');
    }
}

function handleTransformationStateUpdate(array $data, NpcMaster $npcMaster): void
{
    if (($data['actor_type'] ?? '') === 'player') {
        try {
            require_once(__DIR__ . "/lib/core/player.class.php");
            $player = new Player();
            $player->setJson('transformation_state', chimSanitizeTransformationStatePayload($data));
        } catch (Exception $e) {
            Logger::warn("[gamedata.php] Could not save player transformation_state to core_player: " . $e->getMessage());
        }
        return;
    }

    $currentData = gamedataActorRow($data, $npcMaster);
    if (!$currentData) {
        return;
    }

    gamedataApplyMetadata($currentData, [
        'transformation_state' => $data,
    ]);
}

function handleTransformationStateBulkUpdate(array $data, NpcMaster $npcMaster): void
{
    if (empty($data['states']) || !is_array($data['states'])) {
        Logger::warn("[gamedata.php] transformation_state_bulk missing states payload");
        return;
    }

    foreach ($data['states'] as $index => $stateRow) {
        if (!is_array($stateRow) || empty($stateRow['actor_name'])) {
            gamedataBulkResult($index, 'skipped', 'row_invalid');
            continue;
        }

        if (($stateRow['actor_type'] ?? '') === 'player') {
            try {
                require_once(__DIR__ . "/lib/core/player.class.php");
                $player = new Player();
                $player->setJson('transformation_state', chimSanitizeTransformationStatePayload($stateRow));
                gamedataBulkResult($index, 'applied');
            } catch (Exception $e) {
                Logger::warn("[gamedata.php] Could not save player transformation_state during bulk update: " . $e->getMessage());
                gamedataBulkResult($index, 'skipped', 'write_failed');
            }
            continue;
        }

        gamedataBulkApply($stateRow, $index, $npcMaster, 'transformation_state');
    }
}

function handleLoadedPluginsUpdate(array $data): void
{
    if (empty($data['plugins']) || !is_array($data['plugins'])) {
        Logger::warn("[gamedata.php] loaded_plugins missing plugins payload");
        return;
    }

    $pluginCount = chimSyncNpcReferenceLoadOrder($data['plugins']);
    Logger::debug("[gamedata.php] Updated loaded plugin manifest ({$pluginCount} plugins)");

    $repair = quest_reference_repair_runtime_formids_to_stable($data['plugins']);
    if ($repair['error'] !== null) {
        Logger::warn("[gamedata.php] AI Quest V1 FormID repair skipped: {$repair['error']}");
        return;
    }

    Logger::debug(
        "[gamedata.php] AI Quest V1 FormID repair: "
        . "{$repair['rows_updated']}/{$repair['rows_scanned']} rows updated, "
        . "{$repair['converted']} references converted, "
        . "{$repair['unresolved']} unresolved, "
        . "{$repair['dynamic']} dynamic"
    );
}

function buildEquipmentMetadataValue(array $equipment): array
{
    $equipmentData = [];
    foreach ($equipment as $slot => $item) {
        $equipmentData[$slot] = isset($item['name']) ? $item['name'] : '';
        $equipmentData[$slot . '_baseid'] = isset($item['baseid']) ? $item['baseid'] : '';
        $equipmentData[$slot . '_keywords'] = isset($item['keywords'])
            ? sanitizeItemKeywordList($item['keywords'])
            : [];
    }

    return $equipmentData;
}

function sanitizeItemKeywordList($keywords): array
{
    if (!is_array($keywords)) {
        return [];
    }

    $clean = [];
    foreach ($keywords as $keyword) {
        $keyword = trim((string) $keyword);
        if ($keyword === '') {
            continue;
        }
        if (!in_array($keyword, $clean, true)) {
            $clean[] = $keyword;
        }
    }

    return $clean;
}

function buildInventoryMetadataValue(array $items): array
{
    $inventoryData = [];
    foreach ($items as $item) {
        if (isset($item['name']) && isset($item['baseid']) && isset($item['count'])) {
            $inventoryData[] = [
                'name' => $item['name'],
                'baseid' => $item['baseid'],
                'count' => intval($item['count']),
                'keywords' => isset($item['keywords']) ? sanitizeItemKeywordList($item['keywords']) : [],
                'goldvalue' => isset($item['goldvalue']) ? intval($item['goldvalue']) : 0,
                'equipped' => !empty($item['equipped']),
                'is_quest_item' => !empty($item['is_quest_item']),
            ];
            $pluginRow = chimGetLoadedGamePluginByRuntimeFormId($item['baseid']);
            $pluginName = ($pluginRow !== null) ? ($pluginRow['plugin_name'] ?? '') : '';
            if (trim($pluginName) !== '') {
                $GLOBALS["db"]->upsertRowTrx(
                    "market_cache",
                    [
                        'baseid' => $item['baseid'],
                        'plugin' => $pluginName,
                        'name' => trim($item['name']),
                        'price' => intval($item['goldvalue'] ?? 0),
                    ],
                    ["baseid" => $item['baseid'], "plugin" => $pluginName]
                );
            } else {
                error_log("[buildInventoryMetadataValue] Plugin name for baseid {$item['baseid']} is empty. Item name: {$item['name']}");
            }
        }
    }

    return $inventoryData;
}

function buildSkillsMetadataValue(array $skills): array
{
    $skillsData = [];
    foreach ($skills as $skillName => $skillValue) {
        $skillsData[$skillName] = floatval($skillValue);
    }

    return $skillsData;
}

function buildStatsMetadataValue(array $stats): array
{
    return [
        'level' => isset($stats['level']) ? intval($stats['level']) : 1,
        'health' => isset($stats['health']) ? floatval($stats['health']) : 0,
        'health_max' => isset($stats['health_max']) ? floatval($stats['health_max']) : 0,
        'magicka' => isset($stats['magicka']) ? floatval($stats['magicka']) : 0,
        'magicka_max' => isset($stats['magicka_max']) ? floatval($stats['magicka_max']) : 0,
        'stamina' => isset($stats['stamina']) ? floatval($stats['stamina']) : 0,
        'stamina_max' => isset($stats['stamina_max']) ? floatval($stats['stamina_max']) : 0,
        'scale' => isset($stats['scale']) ? floatval($stats['scale']) : 1.0,
        'is_essential' => !empty($stats['is_essential']),
        'is_protected' => !empty($stats['is_protected']),
        'is_dead' => !empty($stats['is_dead']),
    ];
}

function buildSpellsMetadataValue(array $spells): array
{
    $spellData = [];
    foreach ($spells as $spell) {
        if (isset($spell['name']) && isset($spell['baseid'])) {
            $spellData[] = [
                'name' => $spell['name'],
                'baseid' => $spell['baseid'],
                'casting_type' => isset($spell['casting_type']) ? intval($spell['casting_type']) : 0,
                'delivery' => isset($spell['delivery']) ? intval($spell['delivery']) : 0,
            ];
        }
    }

    return $spellData;
}

/**
 * Handle inventory update
 */
function handleInventoryUpdate(array $data, NpcMaster $npcMaster): void
{
    $actorName = $data['actor_name'];
    $actorType = $data['actor_type'];

    if (!isset($data['items'])) {
        Logger::error("[gamedata.php] Inventory update missing items data");
        return;
    }

    $items = $data['items'];

    // If this is a player, save directly to core_player table (player doesn't need NPC record)
    if ($actorType === 'player') {
        $inventoryData = buildInventoryMetadataValue($items);

        try {
            require_once(__DIR__ . "/lib/core/player.class.php");
            $player = new Player();
            $player->setJson('inventory', $inventoryData);
            Logger::debug("[gamedata.php] Saved player inventory to core_player table");
        } catch (Exception $e) {
            Logger::warn("[gamedata.php] Could not save player inventory to core_player: " . $e->getMessage());
        }

        // Typed player data lives only in core_player; an NPC namesake is never written.

        chimQuestEngineSyncPlayerInventory($inventoryData, $data['gamets'] ?? null);

        $itemCount = count($items);
        Logger::debug("[gamedata.php] Updated inventory for player: {$actorName} ({$itemCount} items)");
        return; // Done with player, exit early
    }

    // Handle NPC inventory
    $currentData = gamedataActorRow($data, $npcMaster);

    if (!$currentData) {
        // NPC not in database yet - this is normal, they haven't been encountered
        return;
    }

    gamedataUpdateMetadata($npcMaster, $currentData, [
        'inventory' => buildInventoryMetadataValue($items),
    ]);

    gamedataUpdateMetadata($npcMaster, $currentData, [
        'last_inventory_update_gamets' => $data['gamets'] ?? null,
    ]);



    $itemCount = count($items);
    Logger::debug("[gamedata.php] Updated inventory for {$actorType}: {$actorName} ({$itemCount} items)");
}

/**
 * Handle player item pickup telemetry.
 */
function handlePlayerItemAcquired(array $data): void
{
    $itemName = trim(strval($data['name'] ?? ''));
    if ($itemName === '') {
        Logger::warn("[gamedata.php] player_item_acquired missing item name");
        return;
    }

    $count = max(1, intval($data['count'] ?? 1));
    $goldValue = max(0, intval($data['gold_value'] ?? 0));
    $totalValue = isset($data['total_value'])
        ? max(0, intval($data['total_value']))
        : ($goldValue * $count);
    $playerName = trim(strval($data['actor_name'] ?? ($GLOBALS['PLAYER_NAME'] ?? 'Player')));
    if ($playerName === '') {
        $playerName = 'Player';
    }

    Logger::debug("[gamedata.php] Received player item pickup: {$playerName} x{$count} {$itemName} (value {$totalValue})");

    if (!empty($data['barter_suppressed']) || !empty($data['crafting_active'])) {
        return;
    }

    $minimumValue = 500;
    if (function_exists('chimGetGeneralSettingInt')) {
        $minimumValue = max(0, chimGetGeneralSettingInt('CHIM_ITEM_PICKUP_EVENTLOG_MIN_VALUE', 500));
    }

    if ($totalValue < $minimumValue) {
        return;
    }

    $sourceName = trim(strval($data['source_name'] ?? ''));
    $sourceOwner = trim(strval($data['source_owner'] ?? ''));
    $sourceType = strtolower(trim(strval($data['source_type'] ?? '')));

    if ($sourceOwner !== '') {
        $eventText = "{$playerName} took/traded {$count} {$itemName} from {$sourceOwner}";
    } elseif ($sourceName !== '' && $sourceType === 'actor') {
        $eventText = "{$playerName} looted {$count} {$itemName} from {$sourceName}";
    } elseif ($sourceName !== '') {
        $eventText = "{$playerName} found {$count} {$itemName} in a {$sourceName}";
    } else {
        $eventText = "{$playerName} found {$count} {$itemName}";
    }

    $gamets = intval($data['gamets'] ?? 0);
    if ($gamets < 5) {
        $gamets = 5;
    }

    $ts = (int) floor(microtime(true) * 1000000);
    $people = getPlayerItemEventPeopleSnapshot($playerName);
    $GLOBALS["db"]->insert('eventlog', [
        'ts' => $ts,
        'gamets' => $gamets,
        'type' => 'itemfound',
        'data' => $eventText,
        'sess' => 'pending',
        'localts' => time(),
        'people' => $people,
        'location' => '',
        'party' => '',
    ]);
}

function handlePlayerItemsAcquired(array $data): void
{
    $items = $data['items'] ?? [];
    if (!is_array($items)) {
        Logger::warn("[gamedata.php] player_items_acquired missing items payload");
        return;
    }

    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }

        if (empty($item['actor_name']) && !empty($data['actor_name'])) {
            $item['actor_name'] = $data['actor_name'];
        }
        if (empty($item['actor_type']) && !empty($data['actor_type'])) {
            $item['actor_type'] = $data['actor_type'];
        }

        handlePlayerItemAcquired($item);
    }
}

function getPlayerItemEventPeopleSnapshot(string $playerName): string
{
    $people = '';
    try {
        $row = $GLOBALS["db"]->fetchOne("
            SELECT people
              FROM public.eventlog
             WHERE type IN ('infonpc_close', 'infonpc')
               AND COALESCE(people, '') <> ''
             ORDER BY gamets DESC, ts DESC
             LIMIT 1
        ");
        if (is_array($row)) {
            $people = trim(strval($row['people'] ?? ''));
        } elseif (is_string($row)) {
            $people = trim($row);
        }
    } catch (Exception $e) {
        Logger::warn("[gamedata.php] Could not read nearby people snapshot for item pickup: " . $e->getMessage());
    }

    $tokens = array_values(array_filter(array_map('trim', explode('|', $people))));
    if ($playerName !== '' && !in_array($playerName, $tokens, true)) {
        array_unshift($tokens, $playerName);
    }

    if (empty($tokens)) {
        $tokens[] = ($playerName !== '') ? $playerName : 'Player';
    }

    return '|' . implode('|', array_values(array_unique($tokens))) . '|';
}

/**
 * Handle skills update
 */
function handleSkillsUpdate(array $data, NpcMaster $npcMaster): void
{
    $actorName = $data['actor_name'];
    $actorType = $data['actor_type'];

    if (!isset($data['skills'])) {
        Logger::error("[gamedata.php] Skills update missing skills data");
        return;
    }

    $skills = $data['skills'];

    // If this is a player, save directly to core_player table (player doesn't need NPC record)
    if ($actorType === 'player') {
        try {
            require_once(__DIR__ . "/lib/core/player.class.php");
            $player = new Player();

            $skillsData = buildSkillsMetadataValue($skills);
            $player->setJson('skills', $skillsData);
            Logger::debug("[gamedata.php] Saved player skills to core_player table");
        } catch (Exception $e) {
            Logger::warn("[gamedata.php] Could not save player skills to core_player: " . $e->getMessage());
        }

        // Typed player data lives only in core_player; an NPC namesake is never written.

        Logger::debug("[gamedata.php] Updated skills for player: {$actorName}");
        return; // Done with player, exit early
    }

    // Handle NPC skills
    $currentData = gamedataActorRow($data, $npcMaster);

    if (!$currentData) {
        // NPC not in database yet - this is normal, they haven't been encountered
        return;
    }

    gamedataUpdateMetadata($npcMaster, $currentData, [
        'skills' => buildSkillsMetadataValue($skills),
    ]);

    Logger::debug("[gamedata.php] Updated skills for {$actorType}: {$actorName}");
}

/**
 * Handle stats update
 */
function handleStatsUpdate(array $data, NpcMaster $npcMaster): void
{
    $actorName = $data['actor_name'];
    $actorType = $data['actor_type'];

    if (!isset($data['stats'])) {
        Logger::error("[gamedata.php] Stats update missing stats data");
        return;
    }

    $stats = $data['stats'];

    // If this is a player, save directly to core_player table (player doesn't need NPC record)
    if ($actorType === 'player') {
        try {
            require_once(__DIR__ . "/lib/core/player.class.php");
            $player = new Player();

            $statsData = buildStatsMetadataValue($stats);
            $player->setJson('stats', $statsData);
            Logger::debug("[gamedata.php] Saved player stats to core_player table");
        } catch (Exception $e) {
            Logger::warn("[gamedata.php] Could not save player stats to core_player: " . $e->getMessage());
        }

        // Typed player data lives only in core_player; an NPC namesake is never written.

        Logger::debug("[gamedata.php] Updated stats for player: {$actorName}");
        return; // Done with player, exit early
    }

    // Handle NPC stats
    $currentData = gamedataActorRow($data, $npcMaster);

    if (!$currentData) {
        // NPC not in database yet - this is normal, they haven't been encountered
        return;
    }

    gamedataUpdateMetadata($npcMaster, $currentData, [
        'stats' => buildStatsMetadataValue($stats),
        'last_stats_update_gamets' => $data['gamets'] ?? null,
    ]);

    Logger::debug("[gamedata.php] Updated stats for {$actorType}: {$actorName}");
}

/**
 * Handle spells update (NPCs only - player spells deprecated)
 */
function handleSpellsUpdate(array $data, NpcMaster $npcMaster): void
{
    $actorName = $data['actor_name'];
    $actorType = $data['actor_type'];

    // Player spells are deprecated - skip
    if ($actorType === 'player') {
        Logger::debug("[gamedata.php] Skipping player spells update (deprecated)");
        return;
    }

    if (!isset($data['spells'])) {
        Logger::error("[gamedata.php] Spells update missing spells data for {$actorName}");
        return;
    }

    $spells = $data['spells'];

    // Handle NPC spells
    $currentData = gamedataActorRow($data, $npcMaster);

    if (!$currentData) {
        // NPC not in database yet - this is normal, they haven't been encountered
        return;
    }

    gamedataUpdateMetadata($npcMaster, $currentData, [
        'spells' => buildSpellsMetadataValue($spells),
        'spells_updated' => time(),
    ]);

    Logger::debug("[gamedata.php] Updated spells for NPC: {$actorName}");
}

/**
 * Handle Skyrim statistics update (player only)
 * This handles the ~40 Skyrim stats like Quests Completed, Days Passed, etc.
 */
function handleSkyrimStatsUpdate(array $data): void
{
    if (!isset($data['stats']) || !is_array($data['stats'])) {
        Logger::error("[gamedata.php] Skyrim stats update missing stats data");
        return;
    }

    try {
        require_once(__DIR__ . "/lib/core/player.class.php");
        $player = new Player();

        $stats = $data['stats'];

        // Save each stat to core_player table
        foreach ($stats as $statKey => $statValue) {
            $player->set($statKey, (string) $statValue);
        }

        // Also save to conf_opts for backward compatibility
        $db = $GLOBALS["db"];
        foreach ($stats as $statKey => $statValue) {
            $escapedKey = $db->escape($statKey);
            $escapedValue = $db->escape((string) $statValue);
            $db->execQuery("
                INSERT INTO public.conf_opts (id, value) 
                VALUES ('{$escapedKey}', '{$escapedValue}')
                ON CONFLICT (id) DO UPDATE SET value = EXCLUDED.value
            ");
        }

        Logger::debug("[gamedata.php] Updated " . count($stats) . " Skyrim stats to core_player and conf_opts");

    } catch (Exception $e) {
        Logger::error("[gamedata.php] Failed to save Skyrim stats: " . $e->getMessage());
    }
}

/**
 * Handle market stock update
 * Updates the stock JSONB column on the factions table for the given faction formid.
 * Expected payload:
 *   { "type": "market_stock", "faction": "0x01", "list": [ { "itemid": "0x02", "name": "item_name", "count": 2 } ] }
 */
function handleMarketStockUpdate(array $data): void
{
    if (!isset($data['faction']) || !isset($data['list']) || !is_array($data['list'])) {
        Logger::error("[gamedata.php] market_stock update missing faction or list data");
        return;
    }

    $db = $GLOBALS['db'];
    $factionFormId = $db->escape(strtoupper($data['faction']));

    // Build normalised stock array from the incoming list
    $stock = [];
    $gold = 0;
    $rank = isset($data['player_rank']) ? intval($data['player_rank']) : -1;
    foreach ($data['list'] as $item) {
        if (isset($item['itemid'], $item['name'], $item['count'])) {
            $stock[] = [
                'itemid' => $item['itemid'],
                'name' => trim($item['name']),
                'count' => intval($item['count']),
                'price' => intval($item['gold']),
                'enchantment' => isset($item['enchantment']) ? $item['enchantment'] : []

            ];
            if (isset($item['gold']) && $item['itemid'] === '0000000F') { // Gold item  
                $gold = intval($item['count']);
            }

            // Insert or update the item entry in the descriptions_custom table, if a matching plugin can be found
            // and if it does not exists on descriptions table for that plugin. 
            // This is to ensure that we have a record of the item name for future reference.

            $baseid = "00" . substr($item['itemid'], 2);
            $modIndex = substr($item['itemid'], 0, 4);
            $candidateMod = $db->fetchOne("select * from game_plugins where formid_prefix='{$modIndex}'");
            if (!$candidateMod) {
                $modIndex = substr($item['itemid'], 0, 2);
                $candidateMod = $db->fetchOne("select * from game_plugins where formid_prefix='{$modIndex}'");
            }

            if ($candidateMod) {
                $pluginName = $candidateMod['plugin_name'];
                $existing = $db->fetchOne("select * from descriptions where baseid='{$baseid}' and plugin='{$pluginName}'");
                if (!$existing || sizeof($existing) === 0) {
                    // Insert. if exists, will throw error.
                    $db->upsertRowTrx(
                        "descriptions_custom",
                        [
                            'baseid' => $baseid,
                            'plugin' => $pluginName,
                            'name' => trim($item['name'])
                        ],
                        ["baseid" => $baseid, "plugin" => $pluginName]
                    );
                }
                $db->upsertRowTrx(
                    "market_cache",
                    [
                        'baseid' => $item['itemid'],
                        'plugin' => $pluginName,
                        'name' => trim($item['name']),
                        'enchantment' => isset($item['enchantment']) ? ($item['enchantment']) : 0,
                        'price' => intval($item['gold'] + (isset($item['enchantment']) ? ($item['enchantment']) : 0)),
                    ],
                    ["baseid" => $item['itemid'], "plugin" => $pluginName]
                );

            }


        }
    }

    $stockJson = $db->escape(json_encode($stock));

    $sql = "UPDATE public.factions
               SET stock = '{$stockJson}'::jsonb,gold=$gold,player_rank=$rank,localts=" . time() . "
             WHERE formid = '{$factionFormId}'";

    $result = $db->execQuery($sql);

    if ($result) {

        Logger::debug("[gamedata.php] Updated market stock for faction '{$data['faction']}': "
            . count($stock) . " item(s)");
    }
}

function handleLowProcessActorsUpdate(array $data, NpcMaster $npcMaster): void
{

    $actorList = isset($data['actors_nearby']) && is_array($data['actors_nearby']) ? $data['actors_nearby'] : [];

    $currentData = gamedataActorRow($data, $npcMaster);
    if ($currentData) {
        $extendedData = $npcMaster->getMetadata(($currentData));
        // Ensure the history bucket exists and is always an array.
        if (!isset($extendedData['low_process_actors']) || !is_array($extendedData['low_process_actors'])) {
            $extendedData['low_process_actors'] = [];
        }

        // refid => display name stays the shape existing readers use; C18 keyed entries also keep their exact
        // key/refid (and the row id while it still holds that ref) in the parallel low_process_actor_keys map.
        $actorSanitizedList = [];
        $actorKeyList = [];
        foreach ($actorList as $v) {
            if (!is_array($v) || !isset($v['formId'])) { continue; }
            $hexRefId = gamedataFormIdHex($v['formId']);
            $actorSanitizedList[$hexRefId] = (string)($v['name'] ?? '');
            if (isset($v['actor_key'])) {
                $entry = ['actor_key' => $v['actor_key'], 'actor_refid' => $v['actor_refid'], 'npc_id' => null];
                if ($v['actor_key'] !== CHIM_ACTOR_KEY_PLAYER) {
                    try { $nearbyRow = $npcMaster->getByActorKey($v['actor_key']); } catch (RuntimeException $e) { $nearbyRow = null; }
                    if ($nearbyRow && NpcMaster::normalizeRefId($nearbyRow['refid'] ?? '') === $v['actor_refid']) {
                        $entry['npc_id'] = (int)$nearbyRow['id'];
                    }
                }
                $actorKeyList[$hexRefId] = $entry;
            }
        }

        // Store current nearby actors snapshot keyed by game timestamp.
        if ($actorSanitizedList === []) {
            error_log("[gamedata.php] Received empty low_process_actors list for {$data['actor_name']}");
            //return; // Not an error, just an empty list. We'll still store it.
        } else {
            Logger::debug("[gamedata.php] Received low_process_actors list for {$data['actor_name']}: " . count($actorSanitizedList) . " actor(s)");
        }
        $gametsKey = isset($data['gamets']) ? (string) $data['gamets'] : (string) time();
        $extendedData['low_process_actors'][$gametsKey] = $actorSanitizedList;
        $keySnapshots = is_array($extendedData['low_process_actor_keys'] ?? null) ? $extendedData['low_process_actor_keys'] : [];
        $keySnapshots[$gametsKey] = $actorKeyList;

        // Keep entries ordered by timestamp and retain only the 5 most recent snapshots.
        ksort($extendedData['low_process_actors'], SORT_NUMERIC);
        if (count($extendedData['low_process_actors']) > 5) {
            $extendedData['low_process_actors'] = array_slice($extendedData['low_process_actors'], -5, null, true);
        }
        $keySnapshots = array_intersect_key($keySnapshots, $extendedData['low_process_actors']);

        // Physical metadata of the exact row only; never a whole-row write of linked profile fields.
        gamedataUpdateMetadata($npcMaster, $currentData, [
            'low_process_actors' => $extendedData['low_process_actors'],
            'low_process_actor_keys' => $keySnapshots,
        ]);

        Logger::debug("[gamedata.php] Updated low_process_actors list for {$data['actor_name']}: " . count($actorList) . " actor(s)");
        error_log("[gamedata.php] Updated low_process_actors list for {$data['actor_name']}: " . count($actorList) . " actor(s)");
    }
}
