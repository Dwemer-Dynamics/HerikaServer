<?php
require_once __DIR__ . '/director_scene_contract.php';
require_once __DIR__ . '/core/tts_connector.class.php';
require_once __DIR__ . '/core/narrator.class.php';
require_once __DIR__ . '/chat_helper_functions.php';
// Rolemaster runs outside main.php, which normally sets the dialogue chunk sizes.
if (!defined('MAXIMUM_SENTENCE_SIZE')) define('MAXIMUM_SENTENCE_SIZE', 125);
if (!defined('MINIMUM_SENTENCE_SIZE')) define('MINIMUM_SENTENCE_SIZE', 15);

// Activate each actor's existing profile before evaluating its action permissions.
function chimDirectorActorGlobals(array $npc): void
{
    $profiles = new CoreProfile();
    $profile = !empty($npc['profile_id']) ? $profiles->getById((int)$npc['profile_id']) : $profiles->getDefaultNpc();
    // Restore profile override keys between actors; an omitted setting must not inherit from the last cast member.
    static $baseline = null;
    static $overridden = [];
    if ($baseline === null) $baseline = $GLOBALS;
    foreach ($overridden as $key) {
        if (array_key_exists($key, $baseline)) $GLOBALS[$key] = $baseline[$key];
        else unset($GLOBALS[$key]);
    }
    $overridden = array_keys(json_decode($profile['metadata'] ?? '{}', true) ?: []);
    $profiles->setOldGlobals($profile ?: []);
    (new NpcMaster())->setOldGlobalsFromCurrentNpcData($npc);
    $GLOBALS['DIRECT_NARRATOR_DIALOGUE'] = false;
    $party = json_decode(DataGetCurrentPartyConf(), true) ?: [];
    $GLOBALS['IS_NPC'] = !array_key_exists($npc['npc_name'], $party);
}

function chimDirectorActionCatalog(array $actors): array
{
    $catalog = [];
    foreach ($actors as $name => $npc) {
        chimDirectorActorGlobals($npc);
        if (isset($GLOBALS['FUNCTIONS_ARE_ENABLED']) && !$GLOBALS['FUNCTIONS_ARE_ENABLED']) continue;
        foreach (herikaGetActionCatalogRowsByCode() as $code => $row) {
            // These initiate generation or terminate conversation rather than execute a scene action.
            if (in_array($code, ['DirectorCommand', 'CreateNewNPC', 'UseSoulGaze', 'ReadBook', 'EndConversation'], true)
                || !herikaActionCatalogRowIsUsableInCurrentContext($row)
                || !in_array($row['metadata']['dispatch'] ?? 'plugin_command', ['plugin_command', 'script_proxy'], true)) continue;
            $function = herikaActionCatalogBuildFunctionEntryFromRow($row);
            if (!$function) continue;
            $schema = $function['parameters'] ?? ['type' => 'object', 'properties' => []];
            // Empty dynamic enums mean the current nearby/inventory context supplies the choices.
            foreach ($schema['properties'] ?? [] as $key => $property) {
                if (isset($property['enum']) && !$property['enum']) unset($schema['properties'][$key]['enum']);
            }
            $catalog[$code]['description'] = str_replace($name, 'The acting NPC',
                herikaFormatActionPromptTemplate($row['description'] ?? '', [], $row));
            $catalog[$code]['parameters'] = $schema;
            $catalog[$code]['speakers'][] = $name;
        }
    }
    return $catalog;
}

// Scope the existing JSON connectors' templates and dialogue-only options to this scene request.
function chimRequestDirectorScene($connection, array $prompt, array $actors, array $catalog, string $player): array
{
    require_once __DIR__ . '/../functions/json_response.php';
    $keys = ['responseTemplate', 'structuredOutputTemplate', 'CONNECTOR', 'PATCH', 'CHIM_NO_EXAMPLES',
        'FUNCTIONS_ARE_ENABLED', 'PATCH_PROMPT_ENFORCE_ACTIONS', 'DIRECT_NARRATOR_DIALOGUE',
        'HERIKA_NAME', 'HERIKA_PERS', 'HERIKA_SPEECHSTYLE', 'TTSFUNCTION'];
    $saved = [];
    foreach ($keys as $key) {
        if (array_key_exists($key, $GLOBALS)) $saved[$key] = $GLOBALS[$key];
    }
    try {
        $GLOBALS['responseTemplate'] = ['lines' => [['speaker' => 'Eligible NPC name',
            'listener' => 'Present NPC or player name', 'text' => 'Exact spoken words']], 'actions' => []];
        if ($catalog) {
            $GLOBALS['responseTemplate']['actions'][] = ['speaker' => 'Eligible action speaker',
                'after_line' => 1, 'command_name' => 'Catalog code', 'parameters' => new stdClass()];
        }
        $GLOBALS['structuredOutputTemplate'] = dwemerDirectorResponseFormat($actors, $catalog, $player);
        $GLOBALS['FUNCTIONS_ARE_ENABLED'] = false;
        $GLOBALS['PATCH_PROMPT_ENFORCE_ACTIONS'] = false;
        $GLOBALS['DIRECT_NARRATOR_DIALOGUE'] = false;
        $GLOBALS['HERIKA_NAME'] = 'Director';
        $GLOBALS['HERIKA_PERS'] = '';
        $GLOBALS['HERIKA_SPEECHSTYLE'] = '';
        $GLOBALS['TTSFUNCTION'] = '';
        $GLOBALS['CHIM_NO_EXAMPLES'] = true;
        unset($GLOBALS['PATCH']['PREAPPEND']);
        $driver = $GLOBALS['CURRENT_CONNECTOR'];
        $GLOBALS['CONNECTOR'][$driver]['PREFILL_JSON'] = false;
        $GLOBALS['CONNECTOR'][$driver]['ENFORCE_JSON'] = true;
        // Preserve the connector's schema opt-in; JSON-only connectors still receive the scene template.
        $format = ['type' => 'json_object'];
        if (!empty($GLOBALS['CONNECTOR'][$driver]['json_schema'])) {
            $format = $GLOBALS['structuredOutputTemplate'];
        }
        $connection->open($prompt, ['response_format' => $format, 'MAX_TOKENS' => 4000]);
        do { $connection->process(); } while (!$connection->isDone());
        $raw = $connection->close('director_scene');
        $raw = trim($raw);
        // Accept one complete Markdown JSON fence, but keep surrounding prose invalid.
        if (preg_match('/\A```(?:json)?[ \t]*\R(.*)\R```[ \t]*\z/is', $raw, $match)) {
            $raw = trim($match[1]);
        }
        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            $message = 'Director did not return JSON: ' . $error->getMessage();
            dwemerDirectorLogError($message, $error);
            throw new RuntimeException($message, 0, $error);
        }
        if (!is_array($decoded)) {
            dwemerDirectorLogError('Director did not return a scene object');
            throw new RuntimeException('Director did not return a scene object');
        }
        return dwemerValidateDirectorScene($decoded, $actors, $catalog, $player);
    } catch (Throwable $error) {
        dwemerDirectorLogError('Director request failed', $error);
        throw $error;
    } finally {
        foreach ($keys as $key) {
            if (array_key_exists($key, $saved)) $GLOBALS[$key] = $saved[$key];
            else unset($GLOBALS[$key]);
        }
    }
}

// Present cast by model-facing label: the captured physical roster's own rows (namesakes each keep their row,
// labelled "Name [RefID: XXXXXXXX]" so both can participate); legacy rosters without keys fall back to a
// unique name row. A keyed roster excludes the typed player and narrator by key, so an NPC sharing the player's
// name (or a physical "The Narrator") stays in the cast; such an NPC is labelled with its RefID so a bare player
// name never addresses it. Up to 12, those named in the instruction first.
function chimDirectorCastRows($master, string $player, string $instruction): array
{
    $rows = [];
    $roster = DataCloseRangeActorRoster(true);
    $keyed = (bool)array_filter($roster, static fn($entry) => is_string($entry['key'] ?? null));
    foreach ($roster as $entry) {
        $name = (string)$entry['name'];
        if ($name === '' || (!$keyed && ($name === $player || $name === 'The Narrator'))
            || preg_match('/\((?:busy|dead|hostile|in combat|restrained|unavailable)\)/i', $name)) continue;
        $npc = $entry['row'] ?? null;
        if (!$npc && !$keyed) $npc = $master->getByName($name) ?: null;
        if (!$npc || isset($rows[(int)$npc['id']])) continue;
        $rows[(int)$npc['id']] = $npc;
    }
    $counts = array_count_values(array_map(static fn($npc) => mb_strtolower(trim((string)$npc['npc_name'])), $rows));
    $cast = [];
    foreach ($rows as $npc) {
        $name = trim((string)$npc['npc_name']);
        $refid = strtoupper(preg_replace('/^0X/i', '', trim((string)($npc['refid'] ?? ''))));
        if ($counts[mb_strtolower($name)] > 1 || mb_strtolower($name) === mb_strtolower(trim($player))
            || mb_strtolower($name) === 'the narrator') {
            if (!preg_match('/^[0-9A-F]{1,8}$/D', $refid)) continue;  // A namesake without a reference has no exact label.
            $name .= ' [RefID: ' . str_pad($refid, 8, '0', STR_PAD_LEFT) . ']';
        }
        $cast[$name] = $npc;
    }
    $named = static fn($label) => (int)(stripos($instruction, (string)$cast[$label]['npc_name']) !== false);
    uksort($cast, static fn($a, $b) => $named($b) <=> $named($a));
    return array_slice($cast, 0, 12, true);
}

// Physical binding of each cast row captured before the LLM/TTS delay: row id, canonical key (or null for an
// unkeyed legacy row) and runtime RefID.
function chimDirectorCastBindings(array $actors): array
{
    $bindings = [];
    foreach ($actors as $label => $npc) {
        $bindings[$label] = ['id' => (int)($npc['id'] ?? 0), 'key' => chimNpcRowActorKey($npc),
            'refid' => NpcMaster::normalizeRefId($npc['refid'] ?? '')];
    }
    return $bindings;
}

// Publication re-reads the captured rows inside the transaction; a row whose key or RefID moved during
// generation (recycled FF reference, relink, deletion) aborts the scene rather than re-keying it live.
function chimDirectorAssertCastCurrent($db, array $bindings): void
{
    $ids = array_values(array_filter(array_map(static fn($b) => $b['id'], $bindings)));
    if (!$ids) { return; }
    $current = [];
    foreach ($db->fetchAll('SELECT * FROM core_npc_master WHERE id IN (' . implode(',', $ids) . ') FOR UPDATE') ?: [] as $row) {
        $current[(int)$row['id']] = $row;
    }
    foreach ($bindings as $label => $binding) {
        $row = $current[$binding['id']] ?? null;
        if (!$row || chimNpcRowActorKey($row) !== $binding['key'] || NpcMaster::normalizeRefId($row['refid'] ?? '') !== $binding['refid']) {
            throw new RuntimeException('Director cast binding changed: ' . $label);
        }
    }
}

// Pending history row identity: the captured cast plus the typed player as audience (format 2), the speaker's
// own key and the listener's key (a cast row or the typed player). Unkeyed legacy rows stay name-only; a listener
// label outside the cast and player is never resolved by name.
function chimDirectorLineIdentity(array $line, array $actors, array $bindings, string $player): array
{
    $participants = [];
    foreach ($actors as $label => $npc) {
        $participants[] = chimEventParticipant(trim((string)$npc['npc_name']), $bindings[$label]['key']);
    }
    if ($player !== '') { $participants[] = chimEventParticipant($player, CHIM_ACTOR_KEY_PLAYER); }
    $listener = (string)($line['listener'] ?? '');
    $listenerKey = isset($bindings[$listener]) ? $bindings[$listener]['key']
        : ($listener !== '' && strcasecmp($listener, $player) === 0 ? CHIM_ACTOR_KEY_PLAYER : null);
    return ['people' => chimSerializeEventParticipants($participants),
        'speaker_key' => $bindings[$line['speaker']]['key'] ?? null,
        'listener_keys' => $listenerKey === null ? [] : [$listenerKey], 'target_key' => null];
}

// Generate and publish one complete scene; no NPC model interprets these lines again.
function chimGenerateDirectorScene($connection, string $instruction, string $worldContext): void
{
    require_once __DIR__ . '/chat_helper_functions.php';
    require_once __DIR__ . '/core/tts_connector.class.php';
    require_once __DIR__ . '/../functions/functions.php';
    $directorConnector = $GLOBALS['CHIM_CORE_CURRENT_CONNECTOR_DATA'];
    $CACHE_ENGINE_ROOT=$GLOBALS['ENGINE_ROOT'];
    $master = new NpcMaster();
    $profiles = new CoreProfile();
    $player = (string)$GLOBALS['PLAYER_NAME'];
    $actors = [];
    $context = [];
    foreach (chimDirectorCastRows($master, $player, $instruction) as $name => $npc) {
        $actors[$name] = $npc;
        $bio = ['name' => $name];
        foreach (['npc_static_bio', 'personality', 'speechstyle', 'occupation', 'appearance', 'skills', 'goals', 'core'] as $field) {
            $bio[$field] = mb_substr((string)($npc[$field] ?? ''), 0, 3000);
        }
        $profile = !empty($npc['profile_id']) ? $profiles->getById((int)$npc['profile_id']) : $profiles->getDefaultNpc();
        $bio['profile_instructions'] = mb_substr((string)($profile['prompt'] ?? ''), 0, 2000);
        $metadata = $master->getMetadata($npc);
        $bio['inventory'] = array_slice(chimFormatInventoryPromptLines($metadata['inventory'] ?? []), 0, 80);
        $extended = $master->getExtendedData($npc);
        $bio['past_events'] = [];
        foreach (chimMiddleTermValidDigests($npc, (int)($GLOBALS['gameRequest'][2] ?? 0)) as $digest) {
            $bio['past_events'][] = mb_substr($digest['text'], 0, 2000);
        }
        $bio['past_events'] = array_slice($bio['past_events'], -2);
        $context[] = $bio;
    }
    if (!$actors) {
        dwemerDirectorLogError('No eligible Director actors');
        throw new RuntimeException('No eligible Director actors');
    }
    $castBindings = chimDirectorCastBindings($actors);
    // The reserved narrator joins the action catalog only; cast labels never include it, and a physical
    // row's label is never replaced by it.
    $actionActors = $actors;
    $narrator = (new Narrator())->getNarratorData();
    if ($narrator && !isset($actionActors['The Narrator'])) $actionActors['The Narrator'] = $narrator;
    $catalog = chimDirectorActionCatalog($actionActors);
    (new LLMConnector())->setOldGlobals($directorConnector);
    $GLOBALS['CURRENT_CONNECTOR'] = $directorConnector['driver'];
    $prompt = [
        ['role' => 'system', 'content' => dwemerDirectorPrompt('Skyrim', $catalog)],
        ['role' => 'user', 'content' => "# World context and history\n" . $worldContext
            . "\n# Present eligible NPC profiles\n" . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            . "\n# Player name\n" . $player],
        ['role' => 'user', 'content' => $instruction],
    ];
    $scene = chimRequestDirectorScene($connection, $prompt, $actors, $catalog, $player);
    $scene = dwemerSplitDirectorScene($scene, static function (array $line) use ($actors): array {
        chimDirectorActorGlobals($actors[$line['speaker']]);
        return split_sentences_stream(cleanResponse($line['text']));
    });
    $scene['schema'] = 'chim.director_scene.v2';
    $scene['id'] =  bin2hex(random_bytes(16));
    $scene['generation'] = (int)($GLOBALS['argv'][5] ?? 0);
    foreach ($scene['lines'] as $index => &$line) {
        dwemerDirectorLogError('Processing line ' . $index . ' for speaker ' . $line['speaker'] . ' with text: ' . $line['text']);
        chimDirectorActorGlobals($actors[$line['speaker']]);
        $line['actor_refid'] = $actors[$line['speaker']]['refid'] ?? '';
        // Physical endpoints of the selected rows ($actors holds only names with exactly one row). The listener
        // is that unique row, the typed player, or null; never a nearest-name guess. Unkeyed rows stay legacy.
        try {
            $actorEndpoint = chimResponseEndpointForNpcRow($actors[$line['speaker']]);
            if ($actorEndpoint !== null) {
                $line['actor_identity'] = $actorEndpoint;
                $listenerName = (string)($line['listener'] ?? '');
                $line['listener_identity'] = isset($actors[$listenerName])
                    ? chimResponseEndpointForNpcRow($actors[$listenerName])
                    : ($listenerName !== '' && strcasecmp($listenerName, $player) === 0 ? chimResponsePlayerEndpoint() : null);
            }
        } catch (InvalidArgumentException $e) {
            dwemerDirectorLogError('Director actor identity invalid: ' . $e->getMessage());
            throw new RuntimeException('Director actor identity invalid');
        }
        $line['utterance_id'] = 'director-' . $scene['id'] . '-' . $index;
        $GLOBALS['CHIM_SPEECH_TRACE_ID'] = $line['utterance_id'];
        chimSpeechTrace('sentence_ready', ['sentence' => $index + 1]);
        $line['tts_cache_key'] = md5($line['utterance_id']);
        $audio = $CACHE_ENGINE_ROOT . '/soundcache/' . $line['tts_cache_key'] . '.wav';
        if (!is_file($audio) || filesize($audio) <= 44) callNpcTtsWithFallback($line['text'], 'default', $line['utterance_id']);
        if (!is_file($audio) || filesize($audio) <= 44) {
            dwemerDirectorLogError('Director audio generation failed');
            throw new RuntimeException('Director audio generation failed');
        }
    }
    unset($line);
    // Actions dispatch later by the selected row's key, never by re-reading the label as a name.
    foreach ($scene['actions'] as &$sceneAction) {
        if (isset($actors[$sceneAction['speaker']])) {
            $sceneAction['speaker_identity'] = chimResponseEndpointForNpcRow($actors[$sceneAction['speaker']]);
        }
    }
    unset($sceneAction);
    $db = $GLOBALS['db'];
    if ($db->query('BEGIN') === false) {
        dwemerDirectorLogError('Director publication failed');
        throw new RuntimeException('Director publication failed');
    }
    try {
        chimDirectorAssertCastCurrent($db, $castBindings);
        foreach ($scene['lines'] as $index => $line) {
            $lineIdentity = chimDirectorLineIdentity($line, $actors, $castBindings, $player);
            if (!$db->insertReturningId('eventlog', ['type' => 'chat', 'ts' => time() + $index,
                'gamets' => (int)($GLOBALS['gameRequest'][2] ?? 0), 'localts' => time(), 'sess' => 'pending',
                'data' => $actors[$line['speaker']]['npc_name'] . ': ' . $line['text'] . ' ' . buildDialogueTargetSuffix($line['listener']),
                'people' => $lineIdentity['people'],
                'location' => $GLOBALS['CACHE_LOCATION'] ?? '', 'party' => $GLOBALS['CACHE_PARTY'] ?? '',
                'utterance_id' => $line['utterance_id'], 'delivery_state' => 'pending'] + chimEventRoleColumns($lineIdentity, false), 'rowid')) {
                dwemerDirectorLogError('Director pending history failed');
                throw new RuntimeException('Director pending history failed');
            }
        }
        $json = json_encode($scene, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (!$db->insertReturningId('rolemaster', ['localts' => time(), 'ttl' => 600, 'type' => 'director_scene', 'data' => $json], 'rowid')
            || !$db->insertReturningId('responselog', ['localts' => time(), 'sent' => 0, 'actor' => 'rolemaster',
                'text' => '', 'action' => 'rolecommand|DirectorScene@' . base64_encode($json), 'tag' => 'director_scene:' . $scene['id']], 'rowid')) {
            dwemerDirectorLogError('Director scene queue failed');
            throw new RuntimeException('Director scene queue failed');
        }
        if ($db->query('COMMIT') === false) {
            dwemerDirectorLogError('Director commit failed');
            throw new RuntimeException('Director commit failed');
        }
    } catch (Throwable $error) {
        $db->query('ROLLBACK');
        dwemerDirectorLogError('Director publication transaction rolled back', $error);
        throw $error;
    }
    foreach ($scene['lines'] as $line) chimSpeechTrace('queued_for_delivery', [], $line['utterance_id']);
    unset($GLOBALS['CHIM_SPEECH_TRACE_ID']);
    Logger::info('[DIRECTOR] Authored scene queued: ' . $scene['id'] . ' lines=' . count($scene['lines']));
}
