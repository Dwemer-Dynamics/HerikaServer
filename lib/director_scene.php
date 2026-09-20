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
    $names = array_values(array_filter(array_unique(explode('|', DataBeingsInCloseRange(true))),
        static fn($name) => $name !== '' && $name !== $player && $name !== 'The Narrator'
            && !preg_match('/\((?:busy|dead|hostile|in combat|restrained|unavailable)\)/i', $name)));
    usort($names, static fn($a, $b) => (int)(stripos($instruction, $b) !== false) <=> (int)(stripos($instruction, $a) !== false));
    $actors = [];
    $context = [];
    foreach (array_slice($names, 0, 12) as $name) {
        $npc = $master->getByName($name);
        if (!$npc) continue;
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
        foreach ($extended['middle_term_memory'] ?? [] as $gamets => $memory) {
            if (is_numeric($gamets) && (int)$gamets <= (int)($GLOBALS['gameRequest'][2] ?? 0) && is_string($memory)) {
                $bio['past_events'][] = mb_substr($memory, 0, 2000);
            }
        }
        $bio['past_events'] = array_slice($bio['past_events'], -2);
        $context[] = $bio;
    }
    if (!$actors) {
        dwemerDirectorLogError('No eligible Director actors');
        throw new RuntimeException('No eligible Director actors');
    }
    $actionActors = $actors;
    $narrator = (new Narrator())->getNarratorData();
    if ($narrator) $actionActors['The Narrator'] = $narrator;
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
        chimDirectorActorGlobals($actors[$line['speaker']]);
        $line['actor_refid'] = $actors[$line['speaker']]['refid'] ?? '';
        $line['utterance_id'] = 'director-' . $scene['id'] . '-' . $index;
        $line['tts_cache_key'] = md5($line['utterance_id']);
        $audio = $CACHE_ENGINE_ROOT . '/soundcache/' . $line['tts_cache_key'] . '.wav';
        if (!is_file($audio) || filesize($audio) <= 44) callNpcTtsWithFallback($line['text'], 'default', $line['utterance_id']);
        if (!is_file($audio) || filesize($audio) <= 44) {
            dwemerDirectorLogError('Director audio generation failed');
            throw new RuntimeException('Director audio generation failed');
        }
    }
    unset($line);
    $db = $GLOBALS['db'];
    if ($db->query('BEGIN') === false) {
        dwemerDirectorLogError('Director publication failed');
        throw new RuntimeException('Director publication failed');
    }
    try {
        foreach ($scene['lines'] as $index => $line) {
            if (!$db->insertReturningId('eventlog', ['type' => 'chat', 'ts' => time() + $index,
                'gamets' => (int)($GLOBALS['gameRequest'][2] ?? 0), 'localts' => time(), 'sess' => 'pending',
                'data' => $line['speaker'] . ': ' . $line['text'] . ' ' . buildDialogueTargetSuffix($line['listener']),
                'people' => '|' . $line['speaker'] . '|' . $line['listener'] . '|',
                'location' => $GLOBALS['CACHE_LOCATION'] ?? '', 'party' => $GLOBALS['CACHE_PARTY'] ?? '',
                'utterance_id' => $line['utterance_id'], 'delivery_state' => 'pending'], 'rowid')) {
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
    Logger::info('[DIRECTOR] Authored scene queued: ' . $scene['id'] . ' lines=' . count($scene['lines']));
}
