<?php

ob_start();
error_reporting(E_ERROR);

header('Content-Type: application/json');
header('Cache-Control: no-store');
header('Access-Control-Allow-Origin: *');

$enginePath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR;
$GLOBALS['ENGINE_PATH'] = $enginePath;

require_once($enginePath . 'lib' . DIRECTORY_SEPARATOR . 'runtime_bootstrap.php');

try {
    chimRuntimeBootstrap($enginePath, [
        'load_general_settings' => true,
        'load_stt_connector' => false,
        'load_itt_connector' => false,
        'load_tts_connector' => false,
        'load_player_name' => true,
        'load_narrator' => true,
    ]);

    require_once($enginePath . 'lib' . DIRECTORY_SEPARATOR . 'logger.php');
    require_once($enginePath . 'lib' . DIRECTORY_SEPARATOR . 'model_dynmodel.php');
    require_once($enginePath . 'lib' . DIRECTORY_SEPARATOR . 'data_functions.php');
    require_once($enginePath . 'lib' . DIRECTORY_SEPARATOR . 'chat_helper_functions.php');
    require_once($enginePath . 'lib' . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'core_profiles.class.php');
    require_once($enginePath . 'lib' . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'narrator.class.php');
    require_once($enginePath . 'lib' . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'npc_master.class.php');
    require_once($enginePath . 'lib' . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'tts_connector.class.php');

    if (function_exists('requireFilesRecursively')) {
        requireFilesRecursively($enginePath . 'ext' . DIRECTORY_SEPARATOR, 'globals.php');
    }
    require_once($enginePath . 'prompt.includes.php');

    $entryId = filter_input(INPUT_GET, 'entry', FILTER_VALIDATE_INT);
    if (!$entryId || $entryId < 1) {
        throw new InvalidArgumentException('A valid diary entry is required.');
    }

    $db = $GLOBALS['db'];
    $entry = $db->fetchOne(
        'SELECT rowid, content, people, author_key FROM public.diarylog WHERE rowid = ' . intval($entryId) . ' LIMIT 1'
    );
    if (!is_array($entry) || empty($entry)) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Diary entry not found.']);
        exit;
    }

    $content = trim(html_entity_decode(strip_tags(strval($entry['content'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    if ($content === '') {
        throw new RuntimeException('This diary entry has no readable text.');
    }

    $people = array_values(array_filter(
        array_map('trim', explode('|', trim(strval($entry['people'] ?? ''), '|'))),
        static fn($value) => $value !== ''
    ));
    $author = strval($people[0] ?? '');
    if ($author === '') {
        throw new RuntimeException('The diary author could not be determined.');
    }

    // The stored author key decides the voice: a physical "The Narrator" stays physical and same-named
    // NPCs never share audio. A linked physical author speaks with its kept profile's effective voice.
    // Legacy unassigned rows fall back to a name only when it is unambiguous.
    require_once($enginePath . 'lib' . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'npc_reference.php');
    $authorKey = $entry['author_key'] ?? null;
    $narratorManager = new Narrator();
    $npcManager = new NpcMaster();
    if ($authorKey !== null && $authorKey !== '') {
        if (!chimIsActorKey($authorKey)) {
            throw new RuntimeException('The diary author key is invalid.');
        }
        $isNarrator = $authorKey === CHIM_ACTOR_KEY_NARRATOR;
        if ($authorKey === CHIM_ACTOR_KEY_PLAYER) {
            throw new RuntimeException('Player diary entries have no NPC voice profile.');
        }
        $npcData = $isNarrator ? $narratorManager->getNarratorData() : $npcManager->getByActorKey($authorKey);
    } else {
        $authorKey = null;
        $isNarrator = in_array($author, [Narrator::CANONICAL_NAME, $narratorManager->getRoleplayName()], true);
        if ($isNarrator) {
            $npcData = $narratorManager->getNarratorData();
        } else {
            $namesakes = $db->fetchAll("SELECT id FROM public.core_npc_master WHERE lower(npc_name) = lower('" . $db->escape($author) . "') LIMIT 2");
            if (is_array($namesakes) && count($namesakes) > 1) {
                throw new RuntimeException("This unattributed diary entry matches several NPCs named {$author}.");
            }
            $npcData = $npcManager->getByName($author);
        }
    }
    if (!is_array($npcData) || empty($npcData)) {
        throw new RuntimeException("No CHIM NPC profile was found for {$author}.");
    }

    $profileManager = new CoreProfile();
    $profileId = intval($npcData['profile_id'] ?? 0);
    $profileData = $profileId > 0 ? $profileManager->getById($profileId) : null;
    if (!is_array($profileData) || empty($profileData)) {
        $profileData = $isNarrator ? $profileManager->getDefaultNarrator() : $profileManager->getDefaultNpc();
    }
    if (!is_array($profileData) || empty($profileData)) {
        throw new RuntimeException("No TTS profile is available for {$author}.");
    }

    $connectorId = intval($profileData['tts_connector_id'] ?? 0);
    $ttsConnector = new TTSConnector();
    $connectorData = $connectorId > 0 ? $ttsConnector->getById($connectorId) : null;
    $driver = $ttsConnector->normalizeDriverValue($connectorData['driver'] ?? 'none');
    if (!is_array($connectorData) || empty($connectorData) || $driver === 'none' || $driver === '') {
        throw new RuntimeException("TTS is not configured for {$author}'s profile.");
    }

    $GLOBALS['CHIM_CORE_CURRENT_PROFILE_DATA'] = $profileData;
    $profileManager->setOldGlobals($profileData);
    $GLOBALS['CHIM_CORE_CURRENT_NPC_DATA'] = $npcData;
    if ($isNarrator) {
        $narratorManager->loadCharacterIntoGlobals();
    } else {
        $npcManager->setOldGlobalsFromCurrentNpcData($npcData);
    }

    $voiceId = trim(strval($isNarrator
        ? ($GLOBALS['PATCH_OVERRIDE_VOICE'] ?? '')
        : ($GLOBALS['TTS_NPC_RESOLVED_VOICE'] ?? $GLOBALS['PATCH_OVERRIDE_VOICE'] ?? '')));
    if ($voiceId === '') {
        throw new RuntimeException("No TTS voice could be resolved for {$author}.");
    }

    $connectorSignature = json_encode([
        'id' => $connectorId,
        'driver' => $driver,
        'url' => $connectorData['url'] ?? '',
        'metadata' => $connectorData['metadata'] ?? '{}',
        'api_badge_id' => $connectorData['api_badge_id'] ?? null,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $playthrough = $db->fetchOne("SELECT value FROM public.core_player WHERE id = 'playthrough_id' LIMIT 1");
    $cacheSeed = 'diary-audio|' . hash('sha256', implode('|', [
        strval($entryId),
        $content,
        hash('fnv1a64', $content),
        $author,
        strval($authorKey ?? 'legacy'),
        strval($playthrough['value'] ?? ''),
        strval($npcData['id'] ?? ''),
        $voiceId,
        strval($connectorSignature),
    ]));
    $expectedFile = md5(trim($cacheSeed)) . '.wav';
    $soundCachePath = $enginePath . 'soundcache' . DIRECTORY_SEPARATOR . $expectedFile;
    $wasCached = is_file($soundCachePath) && filesize($soundCachePath) > 0;

    $lockPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'chim-diary-audio-' . md5($cacheSeed) . '.lock';
    $lockHandle = fopen($lockPath, 'c');
    if ($lockHandle === false || !flock($lockHandle, LOCK_EX)) {
        throw new RuntimeException('The diary audio generator is currently unavailable.');
    }

    try {
        $wasCached = is_file($soundCachePath) && filesize($soundCachePath) > 0;
        if (!$wasCached) {
            $GLOBALS['AVOID_TTS_CACHE'] = false;
            $GLOBALS['PATCH_DONT_STORE_SPEECH_ON_DB'] = true;
            $GLOBALS['HERIKA_ANIMATIONS'] = false;
            $GLOBALS['SCRIPTLINE_LISTENER'] = '';
            $GLOBALS['SCRIPTLINE_EXPRESSION'] = '';
            $GLOBALS['TTS_FFMPEG_FILTERS'] = $GLOBALS['TTS_FFMPEG_FILTERS'] ?? [];
            $GLOBALS['FEATURES'] = $GLOBALS['FEATURES'] ?? [];
            $GLOBALS['FEATURES']['MISC'] = $GLOBALS['FEATURES']['MISC'] ?? [];
            $GLOBALS['FEATURES']['MISC']['TTS_RANDOM_PITCH'] = false;

            $ttsOutput = callNpcTtsWithFallback($content, 'default', $cacheSeed);
            $generatedFile = basename(strval($ttsOutput));
            if ($generatedFile === '' || !is_file($enginePath . 'soundcache' . DIRECTORY_SEPARATOR . $generatedFile)) {
                throw new RuntimeException('The configured TTS service did not produce diary audio.');
            }
            $expectedFile = $generatedFile;
        }
    } finally {
        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
    }

    $scriptPath = strval($_SERVER['SCRIPT_NAME'] ?? '');
    $uiPosition = strpos($scriptPath, '/ui/');
    $webRoot = $uiPosition !== false ? substr($scriptPath, 0, $uiPosition) : '';
    $host = trim(strval($_SERVER['HTTP_HOST'] ?? ''));
    $isHttps = !empty($_SERVER['HTTPS']) && strtolower(strval($_SERVER['HTTPS'])) !== 'off';
    $origin = $host !== '' ? (($isHttps ? 'https' : 'http') . '://' . $host) : '';
    $audioUrl = $origin . rtrim($webRoot, '/') . '/soundcache/' . rawurlencode($expectedFile);

    if (filter_input(INPUT_GET, 'raw', FILTER_VALIDATE_BOOLEAN)) {
        $audioPath = $enginePath . 'soundcache' . DIRECTORY_SEPARATOR . $expectedFile;
        if (!is_file($audioPath) || filesize($audioPath) < 1) {
            throw new RuntimeException('The generated diary audio file is unavailable.');
        }

        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: audio/wav');
        header('Content-Length: ' . filesize($audioPath));
        header('Content-Disposition: inline; filename="' . basename($expectedFile) . '"');
        readfile($audioPath);
        exit;
    }

    echo json_encode([
        'success' => true,
        'audio_url' => $audioUrl,
        'author' => $author,
        'author_key' => $authorKey,
        'voice' => $voiceId,
        'connector' => strval($connectorData['label'] ?? $driver),
        'cached' => $wasCached,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (Throwable $e) {
    if (class_exists('Logger')) {
        Logger::error('[DIARY AUDIO] ' . $e->getMessage());
    } else {
        error_log('[DIARY AUDIO] ' . $e->getMessage());
    }
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
