<?php
// Fixed loopback, read-only discovery; no credentials or engine mutations.
require_once dirname(__DIR__, 2) . '/lib/dwemerdistro_llm.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') { http_response_code(405); exit; }
echo json_encode(DwemerDistroLlm::status(), JSON_INVALID_UTF8_SUBSTITUTE);
