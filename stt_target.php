<?php
// Automatic responder decision for CHIM player voice input. The game client posts JSON from its existing
// transport; browser cross-origin writes and requests without a playthrough tag are rejected.
header('Content-Type: application/json');
header('Cache-Control: no-store');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); exit; }
if (!str_starts_with(strtolower($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json')
    || !isset($_SERVER['HTTP_X_CHIM_PLAYTHROUGH'])
    || (isset($_SERVER['HTTP_ORIGIN']) && parse_url($_SERVER['HTTP_ORIGIN'], PHP_URL_HOST)
        !== explode(':', $_SERVER['HTTP_HOST'] ?? '')[0])) { http_response_code(403); exit; }
$raw = file_get_contents('php://input', false, null, 0, 8193);
if (!is_string($raw) || strlen($raw) > 8192) { http_response_code(413); exit; }

require_once __DIR__ . '/lib/chim_interaction.php';
chimInteractionRequire();
require_once __DIR__ . '/lib/playthrough_switching.php';
pas_http_guard();

ini_set('display_errors', '0');
require_once __DIR__ . '/lib/runtime_bootstrap.php';
chimRuntimeBootstrap(__DIR__, ['run_db_updates' => false, 'load_general_settings' => true,
    'load_stt_connector' => false, 'load_itt_connector' => false]);
require_once __DIR__ . '/lib/logger.php';
require_once __DIR__ . '/lib/stt_target_jev.php';

$input = chimSttTargetParseRequest(json_decode($raw, true, 8));
if ($input === null) { http_response_code(400); echo '{"ok":false}'; exit; }

echo json_encode(['ok' => true] + chimSttTargetRespond($input, $GLOBALS['db'] ?? null));
