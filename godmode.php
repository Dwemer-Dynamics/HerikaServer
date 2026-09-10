<?php
// Forward the exact off-stage request to the same authored-scene worker as Director mode.
ignore_user_abort(true);
$receivedData = base64_decode((string)($_GET['DATA'] ?? ''), true);
if ($receivedData === false) { http_response_code(400); exit; }
$gameRequest = explode('|', mb_scrub($receivedData));
$instruction = preg_replace('/^[^:]+:\s*/', '', (string)($gameRequest[3] ?? ''));
$managerPath = __DIR__ . '/service/manager.php';
$phpCli = is_executable(PHP_BINDIR . '/php') ? PHP_BINDIR . '/php' : 'php';
exec(escapeshellarg($phpCli) . ' ' . escapeshellarg($managerPath) . ' rolemaster instruction '
    . escapeshellarg($instruction) . ' notify ' . (int)($_GET['director_generation'] ?? 0), $output, $returnCode);
if ($returnCode !== 0) http_response_code(500);
