<?php

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

// Return one consistent JSON response without exposing connector credentials or provider payloads.
function chimTtsPronunciationPreviewRespond(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    chimTtsPronunciationPreviewRespond(['ok' => false, 'error' => 'Use POST to generate a preview.'], 405);
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start([
        'use_strict_mode' => true,
        'cookie_httponly' => true,
        'cookie_samesite' => 'Strict',
    ]);
}
$now = microtime(true);
$recentRequests = array_values(array_filter(
    is_array($_SESSION['chim_tts_pronunciation_previews'] ?? null)
        ? $_SESSION['chim_tts_pronunciation_previews']
        : [],
    static fn($timestamp): bool => is_numeric($timestamp) && floatval($timestamp) > $now - 60
));
if (count($recentRequests) >= 30) {
    chimTtsPronunciationPreviewRespond(['ok' => false, 'error' => 'Too many previews. Wait a moment and try again.'], 429);
}
$recentRequests[] = $now;
$_SESSION['chim_tts_pronunciation_previews'] = $recentRequests;
session_write_close();

$enginePath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR;
$GLOBALS['ENGINE_PATH'] = $enginePath;

require_once($enginePath . 'lib' . DIRECTORY_SEPARATOR . 'runtime_bootstrap.php');
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
require_once($enginePath . 'lib' . DIRECTORY_SEPARATOR . 'online_translation.php');
require_once($enginePath . 'lib' . DIRECTORY_SEPARATOR . 'tts_pronunciation_preview.php');
require_once($enginePath . 'lib' . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'api_badge.class.php');
require_once($enginePath . 'lib' . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'tts_connector.class.php');

if (function_exists('requireFilesRecursively')) {
    requireFilesRecursively($enginePath . 'ext' . DIRECTORY_SEPARATOR, 'globals.php');
}
require_once($enginePath . 'prompt.includes.php');

$connectorId = intval($_POST['connector_id'] ?? 0);
$voice = trim(strval($_POST['voice'] ?? ''));
$text = trim(strval($_POST['text'] ?? ''));
$textLength = function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
if ($connectorId <= 0 || $voice === '' || $text === '' || $textLength > 240) {
    chimTtsPronunciationPreviewRespond(['ok' => false, 'error' => 'Choose a connector and voice, and preview 240 characters or fewer.'], 400);
}

$previewOptions = chimTtsPronunciationPreviewOptions($enginePath);
$availableConnectorIds = array_map(
    static fn(array $row): int => intval($row['id'] ?? 0),
    $previewOptions['connectors'] ?? []
);
if (!in_array($connectorId, $availableConnectorIds, true)
    || !in_array($voice, $previewOptions['voices'] ?? [], true)) {
    chimTtsPronunciationPreviewRespond(['ok' => false, 'error' => 'That connector or installed voice is no longer available.'], 400);
}

$ttsConnector = new TTSConnector();
$connector = $ttsConnector->getById($connectorId);
if (!is_array($connector)) {
    chimTtsPronunciationPreviewRespond(['ok' => false, 'error' => 'The selected TTS connector could not be loaded.'], 404);
}

$savedGlobals = [
    'TTSFUNCTION' => $GLOBALS['TTSFUNCTION'] ?? null,
    'TTS_FUNCTION' => $GLOBALS['TTS_FUNCTION'] ?? null,
    'HERIKA_NAME' => $GLOBALS['HERIKA_NAME'] ?? null,
    'TRACK' => $GLOBALS['TRACK'] ?? null,
];

try {
    $ttsConnector->setOldGlobals($connector);
    $GLOBALS['HERIKA_NAME'] = Narrator::CANONICAL_NAME;
    $GLOBALS['AVOID_TTS_CACHE'] = true;
    $GLOBALS['TTS_FFMPEG_FILTERS'] = [];
    $GLOBALS['HERIKA_ANIMATIONS'] = false;
    $GLOBALS['SCRIPTLINE_LISTENER'] = '';
    $GLOBALS['SCRIPTLINE_EXPRESSION'] = '';
    $GLOBALS['DEBUG_DATA'] = [];
    $GLOBALS['FEATURES'] = $GLOBALS['FEATURES'] ?? [];
    $GLOBALS['FEATURES']['MISC'] = $GLOBALS['FEATURES']['MISC'] ?? [];
    $GLOBALS['FEATURES']['MISC']['TTS_RANDOM_PITCH'] = false;
    $GLOBALS['PATCH_DONT_STORE_SPEECH_ON_DB'] = true;
    $GLOBALS['PATCH_OVERRIDE_VOICE'] = $voice;
    $GLOBALS['CHIM_TTS_PRONUNCIATION_BYPASS'] = true;
    $GLOBALS['TRACK'] = ['FILES_GENERATED' => []];
    unset($GLOBALS['PATCH_OVERRIDE_VOICE_ID'], $GLOBALS['PATCH_OVERRIDE_TTS_LANGUAGE']);

    returnLines([$text], false);
    $generated = strval($GLOBALS['TRACK']['FILES_GENERATED'][0] ?? '');
    $filename = basename($generated);
    if ($generated === '' || $filename === '') {
        throw new RuntimeException('No audio file was generated.');
    }

    $scriptPath = strval($_SERVER['SCRIPT_NAME'] ?? '');
    $uiPosition = strpos($scriptPath, '/ui/');
    $webRoot = $uiPosition !== false ? substr($scriptPath, 0, $uiPosition) : '';
    if ($webRoot === '/') {
        $webRoot = '';
    }

    chimTtsPronunciationPreviewRespond([
        'ok' => true,
        'audio_url' => rtrim($webRoot, '/') . '/soundcache/' . rawurlencode($filename) . '?ts=' . time(),
    ]);
} catch (Throwable $exception) {
    Logger::error('[TTS Pronunciation Preview] ' . $exception->getMessage());
    chimTtsPronunciationPreviewRespond([
        'ok' => false,
        'error' => 'TTS preview could not be generated. Check the selected connector and voice.',
    ], 502);
} finally {
    foreach ($savedGlobals as $name => $value) {
        if ($value === null) {
            unset($GLOBALS[$name]);
        } else {
            $GLOBALS[$name] = $value;
        }
    }
    unset(
        $GLOBALS['PATCH_DONT_STORE_SPEECH_ON_DB'],
        $GLOBALS['PATCH_OVERRIDE_VOICE'],
        $GLOBALS['CHIM_TTS_PRONUNCIATION_BYPASS'],
        $GLOBALS['SCRIPTLINE_ANIMATION_SENT']
    );
}

