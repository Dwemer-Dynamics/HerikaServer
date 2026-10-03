<?php

// Normalize Skyrim reference IDs to the eight-digit form used by NPC storage.
function chimBglNormalizeRefId(string $refid): string
{
    $refid = strtoupper(trim($refid));
    if (str_starts_with($refid, '0X')) {
        $refid = substr($refid, 2);
    }

    $refid = preg_replace('/[^0-9A-F]/', '', $refid) ?? '';
    if ($refid === '' || strlen($refid) > 8) {
        return '';
    }

    return str_pad($refid, 8, '0', STR_PAD_LEFT);
}

// Interpret the boolean forms returned by PostgreSQL, JSON, and HTTP requests.
function chimBglBoolean($value): bool
{
    return $value === true ||
        $value === 1 ||
        $value === '1' ||
        $value === 't' ||
        $value === 'true' ||
        $value === 'on';
}

// Prefer the stable reference ID, with the NPC name kept as a legacy fallback.
function chimBglResolveNpc(NpcMaster $npcMaster, string $refid = '', string $npcName = ''): ?array
{
    $normalizedRefId = chimBglNormalizeRefId($refid);
    if ($normalizedRefId !== '') {
        $npc = $npcMaster->getByRefId($normalizedRefId);
        if (is_array($npc)) {
            return $npc;
        }
    }

    $npcName = trim($npcName);
    if ($npcName !== '') {
        $npc = $npcMaster->getByName($npcName);
        if (is_array($npc)) {
            return $npc;
        }
    }

    return null;
}

// Build the control-panel status shape from an NPC and its stored metadata.
function chimBglNpcStatus(NpcMaster $npcMaster, ?array $npc, string $requestedRefId = '', string $requestedName = ''): array
{
    if (!$npc) {
        return [
            'exists' => false,
            'npc_id' => null,
            'name' => trim($requestedName),
            'refid' => chimBglNormalizeRefId($requestedRefId),
            'background_life_enabled' => false,
            'auto_actions' => false,
            'send_letters' => false,
            'hourly_tracking' => false,
            'combat_participation' => false,
            'combat_initiate' => false,
            'combat_lethal' => false,
            'combat_loot' => false,
            'location' => '',
        ];
    }

    $extendedData = $npcMaster->getExtendedData($npc);
    $metadata = $npcMaster->getMetadata($npc);
    $coordsValue = $metadata['last_coords'] ?? null;
    $coords = is_array($coordsValue) ? $coordsValue : json_decode((string)$coordsValue, true);
    $location = is_array($coords) ? trim((string)($coords[3] ?? '')) : '';

    return [
        'exists' => true,
        'npc_id' => (int)($npc['id'] ?? 0),
        'name' => trim((string)($npc['npc_name'] ?? $requestedName)),
        'refid' => chimBglNormalizeRefId((string)($npc['refid'] ?? $requestedRefId)),
        'background_life_enabled' => chimBglBoolean($extendedData['background_life_enabled'] ?? false),
        'auto_actions' => chimBglBoolean($extendedData['background_life_commands'] ?? false),
        'send_letters' => chimBglBoolean($extendedData['background_life_letters'] ?? false),
        'hourly_tracking' => chimBglBoolean($metadata['gps_track'] ?? false),
        'combat_participation' => chimBglBoolean($extendedData['background_life_combat_participation'] ?? false),
        'combat_initiate' => chimBglBoolean($extendedData['background_life_combat_initiate'] ?? false),
        'combat_lethal' => chimBglBoolean($extendedData['background_life_combat_lethal'] ?? false),
        'combat_loot' => chimBglBoolean($extendedData['background_life_combat_loot'] ?? false),
        'location' => $location,
    ];
}

// Update one supported control while preserving unrelated NPC data.
function chimBglUpdateNpcSetting(NpcMaster $npcMaster, array $npc, string $setting, bool $value): array
{
    $settingMap = [
        'auto_actions' => ['container' => 'extended', 'key' => 'background_life_commands'],
        'send_letters' => ['container' => 'extended', 'key' => 'background_life_letters'],
        'hourly_tracking' => ['container' => 'metadata', 'key' => 'gps_track'],
        'combat_participation' => ['container' => 'extended', 'key' => 'background_life_combat_participation'],
        'combat_initiate' => ['container' => 'extended', 'key' => 'background_life_combat_initiate'],
        'combat_lethal' => ['container' => 'extended', 'key' => 'background_life_combat_lethal'],
        'combat_loot' => ['container' => 'extended', 'key' => 'background_life_combat_loot'],
    ];
    if (!isset($settingMap[$setting])) {
        throw new InvalidArgumentException('Unsupported Background Life setting');
    }

    $mapping = $settingMap[$setting];
    if ($mapping['container'] === 'metadata') {
        $data = $npcMaster->getMetadata($npc);
        $data[$mapping['key']] = $value;
        $npc = $npcMaster->setMetadata($npc, $data);
    } else {
        $data = $npcMaster->getExtendedData($npc);
        $data[$mapping['key']] = $value;
        $npc = $npcMaster->setExtendedData($npc, $data);
    }

    $npcMaster->updateByArray($npc);
    $updatedNpc = $npcMaster->getById((int)$npc['id']);
    if (!is_array($updatedNpc)) {
        throw new RuntimeException('Could not read saved Background Life setting');
    }

    $status = chimBglNpcStatus($npcMaster, $updatedNpc);
    if (($status[$setting] ?? null) !== $value) {
        throw new RuntimeException('Could not save Background Life setting');
    }

    return $status;
}

// Marks an explicit user removal. A missing enrollment key is the normal default.
const CHIM_BGL_AUTO_ENROLL_OPT_OUT = 'background_life_auto_enroll_opt_out';

// Treat malformed legacy extended data as empty, matching NpcMaster::getExtendedData().
function chimBglExtendedDataSql(): string
{
    return "CASE WHEN jsonb_typeof(extended_data) = 'object' THEN extended_data ELSE '{}'::jsonb END";
}

// Toggle Background Life enrollment. Removal by the user blocks automatic enrollment until
// the user adds the NPC again. Only these keys are merged, so concurrent relationship,
// schedule and per-NPC setting writes are preserved.
// Passing $automaticGamets marks an automatic add: the gates are rechecked under the row lock,
// the first scheduled update waits a full trigger period, and null is returned when another
// decision won the race. Explicit calls always return the saved status or throw.
function chimBglSetEnabled(NpcMaster $npcMaster, array $npc, bool $enabled, ?int $automaticGamets = null): ?array
{
    $npcId = (int)($npc['id'] ?? 0);
    if ($npcId <= 0) {
        throw new InvalidArgumentException('NPC is required');
    }
    $automatic = $automaticGamets !== null;
    if ($automatic && !$enabled) {
        throw new InvalidArgumentException('Automatic enrollment can only add NPCs');
    }

    $extended = chimBglExtendedDataSql();
    $patch = $enabled
        ? ['background_life_enabled' => true]
        : ['background_life_enabled' => false, CHIM_BGL_AUTO_ENROLL_OPT_OUT => true];
    $params = [$npcId, CHIM_BGL_AUTO_ENROLL_OPT_OUT, json_encode($patch)];
    if ($automatic) {
        $set = "{$extended} || \$3::jsonb
             || CASE WHEN \$4::numeric > 0 AND NOT (({$extended}) ? 'background_life_last_updated')
                THEN jsonb_build_object('background_life_last_updated', \$4::numeric)
                ELSE '{}'::jsonb END";
        $gates = "
           AND COALESCE(jsonb_typeof(({$extended})->'background_life_enabled'), 'null') = 'null'
           AND lower(COALESCE(({$extended})->>\$2::text, 'false')) NOT IN ('true', '1', 't', 'on')
           AND lower(COALESCE(metadata->'stats'->>'is_dead', 'false')) NOT IN ('true', '1', 't')";
        $params[] = max(0, $automaticGamets);
    } else {
        $set = "({$extended} - \$2::text) || \$3::jsonb";
        $gates = '';
    }
    $saved = $GLOBALS['db']->fetchOne(
        "UPDATE core_npc_master
         SET extended_data = {$set}
         WHERE id = \$1{$gates}
         RETURNING id",
        $params
    );
    if ((int)($saved['id'] ?? 0) !== $npcId) {
        if ($automatic) {
            return null;
        }
        throw new RuntimeException('Could not save Background Life enrollment');
    }

    $updatedNpc = $npcMaster->getById($npcId);
    if (!is_array($updatedNpc)) {
        throw new RuntimeException('Could not read saved Background Life enrollment');
    }

    $status = chimBglNpcStatus($npcMaster, $updatedNpc);
    if ($status['background_life_enabled'] !== $enabled) {
        throw new RuntimeException('Could not save Background Life enrollment');
    }

    return $status;
}

// Known animal and summon races. Display names and editor IDs (WolfRace) are matched by word.
function chimBglAutoEnrollRaceExcluded(string $race): bool
{
    $words = strtolower(preg_replace('/(?<=[a-z])(?=[A-Z])/', ' ', trim($race)) ?? '');
    if ($words === '') {
        return false;
    }

    return preg_match(
        '/\b(?:dog|wolf|wolves|horse|bear|sabre ?cat|saber ?cat|skeever|spider|chaurus|deer|elk|fox|goat|cow|'
        . 'chicken|hare|rabbit|mudcrab|slaughterfish|horker|mammoth|troll|boar|death ?hound|husky|'
        . 'atronach|familiar|spectral|conjured|summoned|wisp)s?\b/',
        $words
    ) === 1;
}

// Bookkeeping, imports, initial profile data and audits are not shared experience.
function chimBglAutoEnrollExcludedEventTypes(): array
{
    require_once __DIR__ . DIRECTORY_SEPARATOR . 'eventlog_helper.php';

    return array_values(array_unique(array_merge(chimGetVisibleEventLogExcludedTypes(), [
        'bored', 'combatbark', 'npc_snapshot', 'setconf', 'relationship', 'updateprofile',
        'updateprofile_narrator', 'updateprofiles_batch_async', 'updateprofiles_batch_async_manual',
    ])));
}

// Count distinct visible events with the NPC as a recorded participant, stopping at the limit.
// Rows sharing an utterance ID are one spoken line. Only confirmed lines count: emitted lines
// may still abort, and pending, aborted or failed lines never reached the game. Rows without
// a delivery state are events or lines recorded before delivery tracking, so they count.
// Matching rows are read oldest first in rowid batches no larger than the keys still needed,
// so reaching the limit stops early and at most $limit keys are held. The exact participant
// test runs outside the indexed prefilter, only until a batch is full. Below the limit every
// matching row is still read.
function chimBglCountAutoEnrollEvents($db, string $npcName, int $limit, int $maxBatch = 5000): int
{
    require_once __DIR__ . DIRECTORY_SEPARATOR . 'eventlog_helper.php';

    $npcName = trim($npcName);
    if ($npcName === '' || $limit <= 0) {
        return 0;
    }

    $types = implode(', ', array_map(static function (string $type) use ($db): string {
        return "'" . $db->escape($type) . "'";
    }, chimBglAutoEnrollExcludedEventTypes()));
    // The substring test lets the people trigram index narrow rows before the exact participant match.
    $peopleLike = $db->escape('%' . addcslashes($npcName, '%_\\') . '%');
    $participant = chimBuildNpcEventLogPeopleWhereClause($db, $npcName);

    $maxBatch = max(1, min(5000, $maxBatch));
    $seen = [];
    $afterRowid = 0;
    do {
        $batchSize = min($maxBatch, max(100, $limit - count($seen)));
        // OFFSET 0 keeps the participant test out of the subquery, so it is evaluated lazily.
        $rows = $db->fetchAll(
            "SELECT rowid, utterance_id
             FROM (
                SELECT rowid, utterance_id, people
                FROM eventlog
                WHERE rowid > {$afterRowid}
                  AND gamets > 0
                  AND type NOT IN ({$types})
                  AND COALESCE(NULLIF(delivery_state, ''), 'spoken') = 'spoken'
                  AND people ILIKE '{$peopleLike}'
                ORDER BY rowid
                OFFSET 0
             ) candidates
             WHERE {$participant}
             ORDER BY rowid
             LIMIT {$batchSize}"
        ) ?: [];
        foreach ($rows as $row) {
            $afterRowid = (int)$row['rowid'];
            $utteranceId = (string)($row['utterance_id'] ?? '');
            // Distinct prefixes keep an utterance ID from matching a row-only key.
            $seen[$utteranceId !== '' ? 'u:' . $utteranceId : 'r:' . $afterRowid] = true;
            if (count($seen) >= $limit) {
                return $limit;
            }
        }
    } while (count($rows) === $batchSize);

    return count($seen);
}

// Enroll the speaker after a delivered reply to the player once enough shared events exist.
// Returns true only when this call enrolled the NPC; repeated or racing calls are no-ops.
function chimBglMaybeAutoEnroll($db, string $npcName, int $gamets = 0): bool
{
    if (!chimGetBackgroundLifeAutoEnrollEnabled()) {
        return false;
    }

    $npcName = trim($npcName);
    $playerName = trim((string)($GLOBALS['PLAYER_NAME'] ?? ''));
    if ($npcName === '' || strcasecmp($npcName, 'The Narrator') === 0
        || ($playerName !== '' && strcasecmp($npcName, $playerName) === 0)) {
        return false;
    }

    // Same-name records are ambiguous until actor identity uses RefIDs; leave them to manual enrollment.
    $rows = $db->fetchAll(
        "SELECT id, race, extended_data, metadata->'stats'->>'is_dead' AS is_dead
         FROM core_npc_master
         WHERE npc_name = " . $db->escapeLiteral($npcName) . "
         LIMIT 2"
    );
    if (count($rows) !== 1) {
        return false;
    }

    // Any stored enrollment value, including a removal made before the opt-out marker existed,
    // is a user decision. Only NPCs that have never been enrolled are considered.
    $npc = $rows[0];
    $extendedData = json_decode((string)($npc['extended_data'] ?? ''), true);
    $extendedData = is_array($extendedData) ? $extendedData : [];
    if (isset($extendedData['background_life_enabled'])
        || chimBglBoolean($extendedData[CHIM_BGL_AUTO_ENROLL_OPT_OUT] ?? false)
        || chimBglBoolean($npc['is_dead'] ?? false)
        || chimBglAutoEnrollRaceExcluded((string)($npc['race'] ?? ''))) {
        return false;
    }

    $threshold = chimGetBackgroundLifeAutoEnrollThreshold();
    if (chimBglCountAutoEnrollEvents($db, $npcName, $threshold) < $threshold) {
        return false;
    }

    // The shared setter rechecks the gates under the row lock. Actions, Letters and combat
    // keep their off defaults.
    if (chimBglSetEnabled(new NpcMaster(), $npc, true, max(0, $gamets)) === null) {
        return false;
    }

    Logger::info("[BGL] Automatically enrolled {$npcName} after {$threshold} recorded events");
    return true;
}

// Resolve the CLI interpreter when PHP is running under Apache rather than CLI.
function chimBglPhpCliBinary(): string
{
    $binaryName = DIRECTORY_SEPARATOR === '\\' ? 'php.exe' : 'php';
    $candidates = [];

    $phpBindir = rtrim((string)(PHP_BINDIR ?? ''), '/\\');
    if ($phpBindir !== '') {
        $candidates[] = $phpBindir . DIRECTORY_SEPARATOR . $binaryName;
    }

    $phpBinary = trim((string)(PHP_BINARY ?? ''));
    if ($phpBinary !== '') {
        $phpBinaryName = basename(str_replace('\\', '/', $phpBinary));
        if (preg_match('/^php(?:[0-9.]+)?(?:\.exe)?$/i', $phpBinaryName) === 1) {
            $candidates[] = $phpBinary;
        }
    }

    if (DIRECTORY_SEPARATOR !== '\\') {
        $candidates[] = '/usr/bin/php';
        $candidates[] = '/usr/local/bin/php';
    }

    foreach (array_unique($candidates) as $candidate) {
        if (is_file($candidate) && is_executable($candidate)) {
            return $candidate;
        }
    }

    return $binaryName;
}

// Invoke the same one-shot action and letter runners used by the existing map UI.
function chimBglRunRequest(string $enginePath, array $npc, string $requestType): array
{
    if (!in_array($requestType, ['action', 'letter'], true)) {
        throw new InvalidArgumentException('Unsupported Background Life request');
    }

    $npcName = trim((string)($npc['npc_name'] ?? ''));
    if ($npcName === '') {
        throw new RuntimeException('Background Life request has no NPC name');
    }

    $extendedData = json_decode((string)($npc['extended_data'] ?? '{}'), true);
    if (!is_array($extendedData)) {
        $extendedData = [];
    }

    if ($requestType === 'letter') {
        $script = 'service/processors/backgroundlife/cmd/main_lw.php';
        $arguments = [$npcName, 'forceletter'];
    } elseif (chimBglBoolean($extendedData['background_life_commands'] ?? false)) {
        $script = 'service/processors/backgroundlife/cmd/main.php';
        $arguments = [$npcName, 'full', 'forceaction'];
    } else {
        $script = 'service/processors/backgroundlife/cmd/main_lw.php';
        $arguments = [$npcName, 'full', 'forceaction'];
    }

    $scriptPath = rtrim($enginePath, '/\\') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $script);
    if (!is_file($scriptPath)) {
        throw new RuntimeException('Background Life request processor is unavailable');
    }

    $command = array_merge([chimBglPhpCliBinary(), $scriptPath], $arguments);
    $pipes = [];
    $process = proc_open(
        $command,
        [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['redirect', 1],
        ],
        $pipes,
        rtrim($enginePath, '/\\'),
        null,
        ['bypass_shell' => true]
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Could not start Background Life request processor');
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]) ?: '';
    fclose($pipes[1]);
    $exitCode = proc_close($process);
    $output = trim($stdout);

    return [
        'exit_code' => $exitCode,
        'stdout' => $exitCode === 0 ? $output : '',
        'stderr' => $exitCode === 0 ? '' : $output,
    ];
}

// Run the coordinate trackers used by the Background Life map controls.
function chimBglRunTrackingRequest(string $enginePath, string $npcName = ''): array
{
    $scriptPath = rtrim($enginePath, '/\\') . DIRECTORY_SEPARATOR . 'service' . DIRECTORY_SEPARATOR . 'processors' . DIRECTORY_SEPARATOR . 'backgroundlife' . DIRECTORY_SEPARATOR . 'cmd' . DIRECTORY_SEPARATOR . 'simple_command.php';
    if (!is_file($scriptPath)) {
        throw new RuntimeException('Background Life coordinate processor is unavailable');
    }

    $arguments = trim($npcName) === '' ? ['The Narrator', 'TrackAll'] : [trim($npcName), 'Track'];
    $command = array_merge([chimBglPhpCliBinary(), $scriptPath], $arguments);
    $pipes = [];
    $process = proc_open(
        $command,
        [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['redirect', 1],
        ],
        $pipes,
        rtrim($enginePath, '/\\'),
        null,
        ['bypass_shell' => true]
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Could not start Background Life coordinate processor');
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]) ?: '';
    fclose($pipes[1]);
    $exitCode = proc_close($process);
    $output = trim($stdout);

    return [
        'exit_code' => $exitCode,
        'stdout' => $exitCode === 0 ? $output : '',
        'stderr' => $exitCode === 0 ? '' : $output,
    ];
}
