<?php

function chimBglNpcCreationDefaults(): array
{
    return [
        'name' => '',
        'gender' => 'male',
        'class' => 'farmer',
        'race' => 'Nord',
        'location' => '',
        'appearance' => '',
        'background' => '',
        'speech_style' => '',
        'disposition' => 'friendly',
        'goal' => '',
        'starting_point' => '',
        'gold_qty' => '100',
        'gold_ore_qty' => '5',
    ];
}

function chimBglNpcCreationOptions(): array
{
    $rows = $GLOBALS['db']->fetchAll(
        'SELECT formid, name, is_interior, region, hold
         FROM locations
         WHERE COALESCE(name, \'\') <> \'\'
         ORDER BY name ASC'
    );

    $locations = array_map(static function (array $row): array {
        $interior = in_array(
            strtolower(trim((string)($row['is_interior'] ?? ''))),
            ['1', 't', 'true', 'yes', 'on'],
            true
        );
        $details = array_filter([
            $interior ? 'Interior' : 'Exterior',
            trim((string)($row['region'] ?? '')),
            trim((string)($row['hold'] ?? '')),
        ]);

        return [
            'formid' => trim((string)($row['formid'] ?? '')),
            'name' => trim((string)($row['name'] ?? '')),
            'is_interior' => $interior,
            'region' => trim((string)($row['region'] ?? '')),
            'hold' => trim((string)($row['hold'] ?? '')),
            'label' => trim((string)($row['name'] ?? '')) . ' (' . implode(', ', $details) . ')',
        ];
    }, (array)$rows);

    return [
        'defaults' => chimBglNpcCreationDefaults(),
        'genders' => ['male', 'female'],
        'classes' => ['beggar', 'warrior', 'assassin', 'mage', 'farmer', 'soldier', 'merchant', 'noble', 'forsworn'],
        'races' => [
            'Nord',
            'Imperial',
            'RedGuard',
            'Breton',
            'Altmer',
            'Bosmer',
            'Dunmer',
            'Orc',
            'Argonian',
            'Khajiit',
        ],
        'locations' => $locations,
    ];
}

function chimBglNpcCreationFormData(array $input): array
{
    $defaults = chimBglNpcCreationDefaults();

    return [
        'name' => trim((string)($input['npc_name'] ?? $input['name'] ?? $defaults['name'])),
        'gender' => trim((string)($input['npc_gender'] ?? $input['gender'] ?? $defaults['gender'])),
        'class' => trim((string)($input['npc_class'] ?? $input['class'] ?? $defaults['class'])),
        'race' => trim((string)($input['npc_race'] ?? $input['race'] ?? $defaults['race'])),
        'location' => trim((string)($input['npc_location'] ?? $input['location'] ?? $defaults['location'])),
        'appearance' => trim((string)($input['npc_appearance'] ?? $input['appearance'] ?? $defaults['appearance'])),
        'background' => trim((string)($input['npc_background'] ?? $input['background'] ?? $defaults['background'])),
        'speech_style' => trim((string)($input['npc_speech_style'] ?? $input['speech_style'] ?? $defaults['speech_style'])),
        'disposition' => trim((string)($input['npc_disposition'] ?? $input['disposition'] ?? $defaults['disposition'])),
        'goal' => trim((string)($input['npc_goal'] ?? $input['goal'] ?? $defaults['goal'])),
        'starting_point' => trim((string)($input['npc_starting_point'] ?? $input['starting_point'] ?? $defaults['starting_point'])),
        'gold_qty' => (string)max(0, (int)($input['npc_inventory_gold'] ?? $input['gold_qty'] ?? $defaults['gold_qty'])),
        'gold_ore_qty' => (string)max(0, (int)($input['npc_inventory_gold_ore'] ?? $input['gold_ore_qty'] ?? 0)),
    ];
}

function chimBglResolveCreationFormId($formId): int
{
    if (is_int($formId)) {
        return $formId;
    }

    $raw = trim((string)$formId);
    if ($raw === '') {
        return 0;
    }

    if (stripos($raw, '0x') === 0) {
        return (int)hexdec(substr($raw, 2));
    }

    return preg_match('/[a-f]/i', $raw)
        ? (int)hexdec($raw)
        : (int)$raw;
}

// Creates and enrolls one structured NPC through the existing game spawn bridge.
function chimBglCreateNpc(array $input): array
{
    $formData = chimBglNpcCreationFormData($input);
    $requiredFields = [
        'name' => 'Name',
        'gender' => 'Gender',
        'class' => 'Class',
        'race' => 'Race',
        'location' => 'Location',
        'background' => 'Background',
        'speech_style' => 'Speech Style',
        'goal' => 'Goals',
    ];
    $missing = [];
    foreach ($requiredFields as $key => $label) {
        if ($formData[$key] === '') {
            $missing[] = $label;
        }
    }
    if ($missing) {
        return [
            'ok' => false,
            'message' => 'Missing required fields: ' . implode(', ', $missing) . '.',
            'form_data' => $formData,
        ];
    }

    if (preg_match('/[@|\x00-\x1F\x7F]/', $formData['name'])) {
        return [
            'ok' => false,
            'message' => 'NPC names cannot contain command separators or control characters.',
            'form_data' => $formData,
        ];
    }

    $options = chimBglNpcCreationOptions();
    if (!in_array($formData['gender'], $options['genders'], true) ||
        !in_array($formData['class'], $options['classes'], true) ||
        !in_array($formData['race'], $options['races'], true)) {
        return [
            'ok' => false,
            'message' => 'Gender, class, or race is not a supported NPC creation option.',
            'form_data' => $formData,
        ];
    }

    $locationFormId = $GLOBALS['db']->escape($formData['location']);
    $location = $GLOBALS['db']->fetchOne(
        "SELECT formid, name FROM locations WHERE formid = '{$locationFormId}' LIMIT 1"
    );
    if (!$location || trim((string)($location['name'] ?? '')) === '') {
        return [
            'ok' => false,
            'message' => 'Select a valid discovered location.',
            'form_data' => $formData,
        ];
    }

    $startingPoint = $formData['starting_point'] !== ''
        ? chimBglResolveCreationFormId($formData['starting_point'])
        : chimBglResolveCreationFormId($location['formid']);
    if ($startingPoint <= 0) {
        return [
            'ok' => false,
            'message' => 'Starting Point must be a valid FormID.',
            'form_data' => $formData,
        ];
    }

    $inventoryItems = [];
    foreach ([
        ['key' => 'gold_qty', 'refid' => '0x0000000F'],
        ['key' => 'gold_ore_qty', 'refid' => '0x0005acde'],
    ] as $inventoryDefinition) {
        $quantity = (int)$formData[$inventoryDefinition['key']];
        if ($quantity > 0) {
            $inventoryItems[] = [
                'refid' => $inventoryDefinition['refid'],
                'qty' => $quantity,
            ];
        }
    }

    $spawnResult = chimBglSpawnNpc([
        'name' => $formData['name'],
        'gender' => $formData['gender'],
        'class' => $formData['class'],
        'race' => $formData['race'],
        'location' => trim((string)$location['name']),
        'appearance' => $formData['appearance'],
        'background' => $formData['background'],
        'speechStyle' => $formData['speech_style'],
        'disposition' => $formData['disposition'],
        'goal' => $formData['goal'],
        'additional_data' => [
            'disposition' => strtolower($formData['disposition']),
        ],
    ], $startingPoint, $inventoryItems);

    return array_merge($spawnResult, ['form_data' => $formData]);
}

function chimBglSpawnNpc(array $npcProfile, int $startingPoint, array $inventoryItems): array
{
    // Serialize creation by name across browser and Prisma requests. A reserved
    // profile survives HTTP timeouts, so retries never queue another actor.
    $escapedName = $GLOBALS['db']->escape($npcProfile['name']);
    $lockKey = "hashtext(current_schema() || ':bgl_create'), hashtext('{$escapedName}')";
    $lock = $GLOBALS['db']->fetchOne("SELECT pg_try_advisory_lock({$lockKey}) AS locked");
    if (!chimBglBoolean($lock['locked'] ?? false)) {
        return ['ok' => false, 'message' => 'This NPC is already being created. Keep Skyrim running and check again shortly.'];
    }

    try {
        return chimBglCompleteNpcCreation($npcProfile, $startingPoint, $inventoryItems);
    } finally {
        $GLOBALS['db']->query("SELECT pg_advisory_unlock({$lockKey})");
    }
}

// Resume an authored name's existing spawn instead of repeating game commands.
function chimBglCompleteNpcCreation(array $npcProfile, int $startingPoint, array $inventoryItems): array
{
    $npcMaster = new NpcMaster();
    $npc = $npcMaster->getByName($npcProfile['name']);
    $metadata = $npc ? $npcMaster->getMetadata($npc) : [];
    $creation = $metadata['background_life_creation'] ?? null;
    if ($npc && (!is_array($creation) || !in_array($creation['state'] ?? '', ['pending', 'complete'], true))) {
        return ['ok' => false, 'message' => "An NPC named '{$npcProfile['name']}' already exists."];
    }
    if (($creation['state'] ?? '') === 'complete') {
        return [
            'ok' => true,
            'message' => "NPC '{$npcProfile['name']}' has already been created.",
            'npc' => ['name' => $npc['npc_name'], 'refid' => $npc['refid']],
        ];
    }
    if ($creation) {
        $npcProfile = $creation['profile'];
        $startingPoint = (int)$creation['starting_point'];
        $inventoryItems = $creation['inventory'];
        $startRowId = (int)$creation['start_row'];
    } else {
        $startRow = $GLOBALS['db']->fetchOne('SELECT COALESCE(MAX(rowid), 0) AS rowid FROM eventlog');
        $startRowId = (int)($startRow['rowid'] ?? 0);

        $creation = [
            'state' => 'pending', 'start_row' => $startRowId,
            'profile' => $npcProfile, 'starting_point' => $startingPoint,
            'inventory' => $inventoryItems,
        ];
        if (!$GLOBALS['db']->query('BEGIN')) {
            throw new RuntimeException('Could not start NPC creation.');
        }
        try {
            $npcMaster->createProfile($npcProfile['name'], [
                'npc_static_bio' => "{$npcProfile['name']}. {$npcProfile['background']}",
                'speechstyle' => $npcProfile['speechStyle'],
                'appearance' => $npcProfile['appearance'],
                'personality' => $npcProfile['disposition'],
                'occupation' => $npcProfile['class'],
                'metadata' => json_encode(['background_life_creation' => $creation]),
            ]);
            if (!$npcMaster->getByName($npcProfile['name'])) {
                throw new RuntimeException('Could not save the NPC creation request.');
            }

            $GLOBALS['db']->insert('responselog', [
                'localts' => time(),
                'sent' => 0,
                'actor' => 'rolemaster',
                'text' => '',
                'action' => "rolecommand|DebugNotification@Creating {$npcProfile['name']}. Please keep the game running.",
                'tag' => __FILE__ . ':' . __LINE__,
            ]);

            $queued = npcProfileBase(
                $npcProfile['name'],
                $npcProfile['class'],
                $npcProfile['race'],
                $npcProfile['gender'],
                $npcProfile['location'],
                '0',
                $npcProfile['additional_data'] ?? []
            );

            if (!$queued || !$GLOBALS['db']->fetchOne('SELECT 1 AS ready')) {
                throw new RuntimeException('Could not queue the NPC. Check that CHIM is enabled.');
            }

            if (!$GLOBALS['db']->query('COMMIT')) {
                throw new RuntimeException('Could not queue the NPC creation request.');
            }
        } catch (Throwable $error) {
            $GLOBALS['db']->query('ROLLBACK');
            throw $error;
        }
    }

    $escapedName = $GLOBALS['db']->escape($npcProfile['name']);
    $spawned = false;
    $lastGamets = null;
    $lastTs = null;
    for ($attempt = 0; $attempt < 30; $attempt++) {
        sleep(1);
        $spawnStatus = $GLOBALS['db']->fetchOne(
            "SELECT gamets, ts
             FROM eventlog
             WHERE rowid > {$startRowId}
               AND type = 'status_msg'
               AND POSITION('spawned@{$escapedName}@' IN data) = 1
             ORDER BY rowid ASC LIMIT 1"
        );
        if ($spawnStatus) {
            $spawned = true;
            $lastGamets = $spawnStatus['gamets'] ?? null;
            $lastTs = $spawnStatus['ts'] ?? null;
            break;
        }
    }
    if (!$spawned) {
        return [
            'ok' => false,
            'message' => 'Creation is still pending. Your written profile is saved. Keep Skyrim unpaused, then submit the same name to check again; this will not spawn another NPC.',
        ];
    }

    $npc = null;
    $refid = '';
    for ($attempt = 0; $attempt < 30; $attempt++) {
        $npc = $npcMaster->getByName($npcProfile['name']);
        $refid = trim((string)($npc['refid'] ?? ''));
        if ($npc && $refid !== '') {
            break;
        }
        sleep(1);
    }
    if (!$npc || $refid === '') {
        return [
            'ok' => false,
            'message' => 'The game confirmed the spawn, but the requested name has not registered. Your written profile is saved. Check for a naming-mod conflict before trying again with the same name.',
        ];
    }

    // Commit completion and follow-up commands together so a retry cannot grant
    // the starting inventory twice. Identity remains the exact requested name.
    if (!$GLOBALS['db']->query('BEGIN')) {
        throw new RuntimeException('Could not finish NPC creation.');
    }
    try {
        $GLOBALS['db']->insert('responselog', [
            'localts' => time(),
            'sent' => 0,
            'actor' => 'rolemaster',
            'text' => '',
            'action' => "rolecommand|RenameNPC@0x{$refid}@{$npcProfile['name']}",
            'tag' => '',
        ]);

        $extendedData = $npcMaster->getExtendedData($npc);
        $extendedData['background_life_commands'] = true;
        $extendedData['background_life_enabled'] = true;
        $extendedData['background_life_last_updated'] = $lastGamets;
        $extendedData['background_life_player_unattached'] = true;
        $extendedData['background_life_goals'] = $npcProfile['goal'];
        $extendedData['middle_term_enabled'] = 1;

        $metadata = $npcMaster->getMetadata($npc);
        $metadata['gps_track'] = true;
        $metadata['background_life_created'] = true;
        $metadata['background_life_creation'] = ['state' => 'complete'];

        $npc['core'] = "{$npcProfile['name']}. {$npcProfile['gender']} {$npcProfile['class']} {$npcProfile['race']}";
        $npc['npc_static_bio'] = "{$npcProfile['name']}. {$npcProfile['background']}";
        $npc['appearance'] = $npcProfile['appearance'];
        $npc['personality'] = $npcProfile['disposition'];
        $npc['occupation'] = $npcProfile['class'];
        $npc['speechstyle'] = $npcProfile['speechStyle'];
        $npc['goals'] = '';
        $npc['lock_profile'] = null;
        $npc = $npcMaster->setMetadata($npc, $metadata);
        $npc = $npcMaster->setExtendedData($npc, $extendedData);
        if (!$npcMaster->updateByArray($npc)) {
            throw new RuntimeException('The NPC spawned, but its CHIM profile could not be saved.');
        }

        $skyrimCommand = new SkyrimCommandBuilder();
        foreach ($inventoryItems as $itemEntry) {
            $itemRefId = trim((string)($itemEntry['refid'] ?? ''));
            $itemQuantity = (int)($itemEntry['qty'] ?? 0);
            if ($itemRefId === '' || $itemQuantity <= 0) {
                continue;
            }

            $command = $skyrimCommand->ObjectReference->AddItem(
                "0x{$refid}",
                $itemRefId,
                $itemQuantity,
                true
            );
            $skyrimCommand->send(cmd: $command);
        }

        $GLOBALS['db']->insert('responselog', [
            'localts' => time(),
            'sent' => 0,
            'actor' => 'rolemaster',
            'text' => '',
            'action' => "rolecommand|BackgroundCmd@{$refid}@TravelTo/{$startingPoint}",
            'tag' => __FILE__ . ':' . __LINE__,
        ]);

        $GLOBALS['db']->insert('actions_issued', [
            'action' => 'TravelTo',
            'fullcall' => 'TravelTo',
            'actorname' => $npcProfile['name'],
            'ts' => $lastTs,
            'gamets' => $lastGamets,
            'localts' => time(),
            'original' => 'backgroundaction',
        ]);

        if (!chimInteractionAllowed() || !$GLOBALS['db']->fetchOne('SELECT 1 AS ready') || !$GLOBALS['db']->query('COMMIT')) {
            throw new RuntimeException('Could not finish NPC creation.');
        }
    } catch (Throwable $error) {
        $GLOBALS['db']->query('ROLLBACK');
        throw $error;
    }

    return [
        'ok' => true,
        'message' => "NPC '{$npcProfile['name']}' created and added to Background Life.",
        'npc' => [
            'name' => $npcProfile['name'],
            'refid' => chimBglNormalizeRefId($refid),
        ],
    ];
}
