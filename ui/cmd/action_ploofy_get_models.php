<?php
/**
 * Fetch available Ploofy models using the selected API badge's stored key.
 * Returns list of models for the connector UI autocomplete.
 */

$enginePath = __DIR__.DIRECTORY_SEPARATOR."../../";
require_once($enginePath . "lib" .DIRECTORY_SEPARATOR."runtime_bootstrap.php");
chimRuntimeBootstrap($enginePath, [
    'load_general_settings' => true,
    'load_player_name' => true,
    'load_narrator' => true,
]);

require_once($enginePath . "lib" .DIRECTORY_SEPARATOR."core/api_badge.class.php");

header('Content-Type: application/json');
header('Cache-Control: no-store');

// Errors are fixed messages so upstream bodies and stored keys never reach the browser.
function ploofyModelsFail($status, $message) {
    http_response_code($status);
    echo json_encode(['error' => $message]);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    ploofyModelsFail(405, 'POST required');
}

$jsonDataInput = json_decode(file_get_contents("php://input"), true);
$apiBadgeId = is_array($jsonDataInput) ? ($jsonDataInput['api_badge_id'] ?? null) : null;
if (is_int($apiBadgeId)) {
    $apiBadgeId = (string)$apiBadgeId;
}
if (!is_string($apiBadgeId) || !preg_match('/^[1-9][0-9]{0,9}$/', $apiBadgeId)) {
    ploofyModelsFail(400, 'Please select an API Key first.');
}

$apiBadge = new ApiBadge();
$badgeRow = $apiBadge->getById((int)$apiBadgeId);
$apiKey = is_array($badgeRow) ? trim((string)($badgeRow['api_key'] ?? '')) : '';
if ($apiKey === '') {
    ploofyModelsFail(404, 'The selected API Key was not found or is empty. Add your Ploofy key in API Keys.');
}
if (preg_match('/[\r\n]/', $apiKey)) {
    ploofyModelsFail(400, 'The selected API Key is not valid.');
}

// Fixed endpoint: never accept a client-supplied URL.
$maxBytes = 1048576;
$body = '';
$ch = curl_init('https://ploofy.ai/llm/v1/models');
curl_setopt_array($ch, [
    CURLOPT_HTTPGET => true,
    CURLOPT_HTTPHEADER => ['Accept: application/json', 'Authorization: Bearer ' . $apiKey],
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_TIMEOUT => 10,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
    // Abort oversized responses instead of buffering them.
    CURLOPT_WRITEFUNCTION => function ($handle, $chunk) use (&$body, $maxBytes) {
        if (strlen($body) + strlen($chunk) > $maxBytes) {
            return 0;
        }
        $body .= $chunk;
        return strlen($chunk);
    },
]);
$ok = curl_exec($ch);
$curlError = curl_errno($ch);
$httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($curlError === CURLE_WRITE_ERROR) {
    ploofyModelsFail(502, 'Ploofy returned an unexpectedly large response.');
}
if ($ok === false) {
    ploofyModelsFail(502, 'Could not reach Ploofy. Try again later.');
}
if ($httpCode === 401 || $httpCode === 403) {
    ploofyModelsFail(502, 'Ploofy rejected the selected API Key.');
}
if ($httpCode < 200 || $httpCode >= 300) {
    ploofyModelsFail(502, 'Ploofy models request failed (HTTP ' . $httpCode . ').');
}

$data = json_decode($body, true);
if (!is_array($data) || !isset($data['data']) || !is_array($data['data'])) {
    ploofyModelsFail(502, 'Invalid response from Ploofy.');
}

$result = [];
foreach ($data['data'] as $model) {
    if (!is_array($model) || !is_string($model['id'] ?? null)) continue;
    $id = $model['id'];
    // Model IDs are written into the connector, so accept only plain identifiers.
    if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._:\/-]{0,127}$/', $id) || isset($result[$id])) continue;
    $ownedBy = $model['owned_by'] ?? '';
    $ownedBy = (is_string($ownedBy) && preg_match('/^[\w .\/-]{1,64}$/u', $ownedBy)) ? $ownedBy : 'Ploofy';
    $result[$id] = ['value' => $id, 'label' => $ownedBy, 'id' => $id, 'owned_by' => $ownedBy];
    if (count($result) >= 500) break;
}

ksort($result, SORT_STRING);
echo json_encode(array_values($result));
