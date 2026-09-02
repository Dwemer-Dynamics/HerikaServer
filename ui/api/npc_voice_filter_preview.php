<?php

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

// Return a consistent preview response without exposing connector configuration.
function chimNpcVoiceFilterPreviewRespond(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    chimNpcVoiceFilterPreviewRespond(['ok' => false, 'error' => 'Use POST to generate a voice preview.'], 405);
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
    is_array($_SESSION['chim_npc_voice_filter_previews'] ?? null)
        ? $_SESSION['chim_npc_voice_filter_previews']
        : [],
    static fn($timestamp): bool => is_numeric($timestamp) && floatval($timestamp) > $now - 60
));
if (count($recentRequests) >= 20) {
    chimNpcVoiceFilterPreviewRespond(['ok' => false, 'error' => 'Too many voice previews. Wait a moment and try again.'], 429);
}
$recentRequests[] = $now;
$_SESSION['chim_npc_voice_filter_previews'] = $recentRequests;
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
require_once($enginePath . 'lib' . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'api_badge.class.php');
require_once($enginePath . 'lib' . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'core_profiles.class.php');
require_once($enginePath . 'lib' . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'tts_connector.class.php');

if (function_exists('requireFilesRecursively')) {
    requireFilesRecursively($enginePath . 'ext' . DIRECTORY_SEPARATOR, 'globals.php');
}
require_once($enginePath . 'prompt.includes.php');

$profileId = intval($_POST['profile_id'] ?? 0);
$voiceId = trim(strval($_POST['voiceid'] ?? ''));
$requestedPreset = strtolower(trim(strval($_POST['tts_filter_preset'] ?? 'none')));
$voiceIdLength = function_exists('mb_strlen') ? mb_strlen($voiceId, 'UTF-8') : strlen($voiceId);
$availablePresets = ttsFilterPresetOptions(true);

if ($profileId <= 0 || $voiceId === '' || $voiceIdLength > 160) {
    chimNpcVoiceFilterPreviewRespond([
        'ok' => false,
        'error' => 'Choose a profile and enter a Voice ID before playing a preview.',
    ], 400);
}
if (!isset($availablePresets[$requestedPreset])) {
    chimNpcVoiceFilterPreviewRespond(['ok' => false, 'error' => 'That voice filter is not available.'], 400);
}

$profile = (new CoreProfile())->getById($profileId);
if (!is_array($profile)) {
    chimNpcVoiceFilterPreviewRespond(['ok' => false, 'error' => 'The selected profile could not be loaded.'], 404);
}

$connectorId = intval($profile['tts_connector_id'] ?? 0);
if ($connectorId <= 0) {
    chimNpcVoiceFilterPreviewRespond(['ok' => false, 'error' => 'The selected profile has no TTS connector.'], 400);
}

$ttsConnector = new TTSConnector();
$connector = $ttsConnector->getById($connectorId);
if (!is_array($connector)) {
    chimNpcVoiceFilterPreviewRespond(['ok' => false, 'error' => 'The selected profile TTS connector could not be loaded.'], 404);
}

$sampleText = "In Skyrim's land of snow and ice, where dragons soar and snowstorms bind the roads, a steady voice can still cut through the cold.";
$responsePayload = null;
$responseStatus = 200;

try {
    $ttsConnector->setOldGlobals($connector);
    $GLOBALS['HERIKA_NAME'] = 'Voice Filter Preview';
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
    $GLOBALS['PATCH_OVERRIDE_VOICE'] = $voiceId;
    $GLOBALS['TRACK'] = ['FILES_GENERATED' => []];
    unset(
        $GLOBALS['PATCH_OVERRIDE_VOICE_ID'],
        $GLOBALS['PATCH_OVERRIDE_TTS_LANGUAGE'],
        $GLOBALS['TTS_FALLBACK_FNCT']
    );
    setActiveTtsFilterPreset($requestedPreset);

    returnLines([$sampleText], false);
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

    $responsePayload = [
        'ok' => true,
        'audio_url' => rtrim($webRoot, '/') . '/soundcache/' . rawurlencode($filename) . '?ts=' . time(),
    ];
} catch (Throwable $exception) {
    Logger::error('[NPC Voice Filter Preview] ' . $exception->getMessage());
    $responsePayload = [
        'ok' => false,
        'error' => 'Voice preview could not be generated. Check this profile\'s TTS connector and Voice ID.',
    ];
    $responseStatus = 502;
} finally {
    clearActiveTtsFilterPreset();
    unset(
        $GLOBALS['PATCH_DONT_STORE_SPEECH_ON_DB'],
        $GLOBALS['PATCH_OVERRIDE_VOICE'],
        $GLOBALS['SCRIPTLINE_ANIMATION_SENT']
    );
}

chimNpcVoiceFilterPreviewRespond($responsePayload, $responseStatus);
