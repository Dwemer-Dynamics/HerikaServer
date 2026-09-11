<?php

if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json');
header('Cache-Control: no-store');
require_once dirname(__DIR__, 2) . '/lib/playthrough_home.php';

$conn = null;
try {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if (!in_array($method, ['GET','POST'], true)) {
        http_response_code(405);
        throw new RuntimeException('GET or POST required.');
    }
    if ($method === 'POST') {
        $token = $_POST['csrf_token'] ?? null;
        if (!is_string($token) || empty($_SESSION['ptm_csrf']) || !hash_equals($_SESSION['ptm_csrf'], $token)) {
            http_response_code(403);
            throw new RuntimeException('Security check failed. Reload this page.');
        }
        if (!is_string($_POST['expected_token'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $_POST['expected_token'])) {
            throw new InvalidArgumentException('Reload this page before changing playthroughs.');
        }
        if (!is_string($_POST['action'] ?? null) || !in_array($_POST['action'], ['switch','new','delete'], true)
            || isset($_POST['name']) && !is_string($_POST['name'])) {
            throw new InvalidArgumentException('Invalid playthrough request.');
        }
    }
    $conn = ptp_connect();
    if (!$conn) throw new RuntimeException('Database unavailable.');
    pth_query($conn, "SET statement_timeout='120s'");
    pth_query($conn, "SET lock_timeout='2s'");
    if ($method === 'GET') {
        if (empty($_SESSION['ptm_csrf'])) $_SESSION['ptm_csrf'] = bin2hex(random_bytes(32));
        pth_query($conn, 'BEGIN ISOLATION LEVEL REPEATABLE READ READ ONLY');
        $state = pth_state($conn);
        pth_query($conn, 'COMMIT');
        $response = ['ok'=>true,'state'=>$state,'csrf_token'=>$_SESSION['ptm_csrf'],'notice'=>$_SESSION['pth_notice'] ?? ''];
        unset($_SESSION['pth_notice']);
    } else {
        // Only validated writes load Stobe's read-only DB/settings helpers for worker health.
        if ($_POST['action'] !== 'delete' && ptp_product()['meta'] === 'stobe_meta') {
            require_once dirname(__DIR__, 2) . '/lib/postgresql.class.php';
            require_once dirname(__DIR__, 2) . '/lib/settings.php';
            require_once dirname(__DIR__, 2) . '/lib/data_functions.php';
            $GLOBALS['db'] = new sql();
        }
        $input = array_intersect_key($_POST, array_flip(['profile_id','name','expected_token','delete_token','delete_confirmation']));
        $result = $_POST['action'] === 'delete' ? pth_delete($conn,$input) : pth_change($conn,$_POST['action'],$input);
        $response = ['ok'=>true] + $result;
        $_SESSION['pth_notice'] = $response['message'];
    }
    echo json_encode($response, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $error) {
    if ($conn && pg_transaction_status($conn) !== PGSQL_TRANSACTION_IDLE) @pg_query($conn, 'ROLLBACK');
    error_log('Playthrough home: ' . $error->getMessage());
    if (http_response_code() < 400) http_response_code(409);
    echo json_encode(['ok'=>false,'message'=>$error->getMessage(),'retryable'=>$error instanceof InvalidArgumentException], JSON_INVALID_UTF8_SUBSTITUTE);
} finally {
    if ($conn) pg_close($conn);
}
