<?php
require_once __DIR__ . '/lib/playthrough_switching.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');
$conn = null;
try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || isset($_SERVER['HTTP_ORIGIN'])
        || !str_starts_with(strtolower($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json')) {
        http_response_code(403);
        throw new InvalidArgumentException('A game-client JSON request is required.');
    }
    $raw = file_get_contents('php://input', false, null, 0, 2049);
    if (strlen($raw) > 2048) throw new InvalidArgumentException('Request too large.');
    $input = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
    if (!is_array($input)) throw new InvalidArgumentException('Invalid load request.');
    $conn = ptp_connect();
    if (!$conn) throw new RuntimeException('Database unavailable.');
    // An unconfigured installation remains off without creating schemas or inspecting saves.
    if (!pas_enabled($conn)) $result = ['ok'=>true,'status'=>'off'];
    else $result = pas_handshake($conn, $input);
    echo json_encode($result, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
} catch (PlaythroughSwitchBusyException $error) {
    http_response_code(503);
    header('Retry-After: 1');
    echo json_encode(['ok'=>false,'status'=>'busy','message'=>'Another playthrough is still loading. Reload this save shortly.']);
} catch (Throwable $error) {
    error_log('Automatic playthrough switch: ' . $error->getMessage());
    if (http_response_code() < 400) http_response_code(409);
    echo json_encode(['ok'=>false,'status'=>'failed','message'=>'Could not prepare the playthrough. Open Playthrough Saves, then reload the Skyrim save.']);
} finally { if ($conn) pg_close($conn); }
