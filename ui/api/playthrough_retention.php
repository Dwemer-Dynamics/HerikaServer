<?php

require_once dirname(__DIR__) . '/profile_loader.php';
require_once dirname(__DIR__, 2) . '/lib/playthrough_retention.php';
if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json');
header('Cache-Control: no-store');
$conn = null;
$locked = false;
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
            throw new RuntimeException('Security check failed. Reload Playthrough Manager.');
        }
    }
    $conn = @pg_connect('host=localhost port=5432 dbname=dwemer user=dwemer password=dwemer', PGSQL_CONNECT_FORCE_NEW);
    if (!$conn) throw new RuntimeException('Database unavailable.');
    ptr_query($conn, "SET statement_timeout='20s'");
    ptr_query($conn, "SET lock_timeout='2s'");
    $action = $method === 'GET' ? 'state' : ($_POST['action'] ?? '');
    if (!is_string($action) || !in_array($action, ['state','save','preview','run','pin'], true)) throw new InvalidArgumentException('That cleanup action is not recognized.');
    if ($method === 'POST') {
        $locked = ptr_lock($conn);
        if (!$locked) throw new RuntimeException('A snapshot or a cleanup is already running. Try again in a moment.');
    }
    $response = ['ok' => true];
    if ($action === 'save') {
        $settings = ptr_validate($_POST);
        ptr_query($conn, 'BEGIN');
        ptr_ensure_schema($conn);
        ptr_write($conn, 'PLAYTHROUGH_RETENTION', $settings);
        ptr_query($conn, 'COMMIT');
        unset($_SESSION['ptm_retention_preview']);
    } elseif ($action === 'pin') {
        $id = filter_var($_POST['profile_id'] ?? null, FILTER_VALIDATE_INT);
        $pinned = $_POST['pinned'] ?? null;
        if (!$id || $id < 1 || !in_array($pinned, ['0','1'], true)) throw new InvalidArgumentException('That snapshot protection request was not valid.');
        ptr_query($conn, 'BEGIN');
        ptr_ensure_schema($conn);
        $result = ptr_query($conn, 'UPDATE chim_meta.playthrough_profiles SET retention_pinned=$2 WHERE id=$1', [$id, $pinned === '1' ? 'true' : 'false']);
        if (pg_affected_rows($result) !== 1) throw new RuntimeException('That snapshot no longer exists.');
        ptr_query($conn, 'COMMIT');
        unset($_SESSION['ptm_retention_preview']);
    } elseif ($action === 'preview') {
        // Bound repeated expensive previews in one browser session.
        if (time() - ($_SESSION['ptm_preview_at'] ?? 0) < 2) {
            http_response_code(429);
            throw new RuntimeException('Please wait a moment before previewing again.');
        }
        $_SESSION['ptm_preview_at'] = time();
        ptr_query($conn, 'BEGIN ISOLATION LEVEL REPEATABLE READ READ ONLY');
        $plan = ptr_preview($conn, ptr_settings($conn));
        ptr_query($conn, 'COMMIT');
        $token = bin2hex(random_bytes(24));
        $_SESSION['ptm_retention_preview'] = ['token' => $token, 'plan' => $plan];
        foreach ($plan['diagnostics'] as &$group) unset($group['selected']);
        unset($group, $plan['identity']);
        $plan['token'] = $token;
        $plan['expires_at'] = gmdate('c', $plan['created'] + 300);
        $response['preview'] = $plan;
    } elseif ($action === 'run') {
        $saved = $_SESSION['ptm_retention_preview'] ?? null;
        $token = $_POST['preview_token'] ?? null;
        if (!$saved || !is_string($token) || !hash_equals($saved['token'], $token)) throw new RuntimeException('Run a preview first, then confirm the cleanup.');
        unset($_SESSION['ptm_retention_preview']);
        ptr_ensure_schema($conn);
        $response['result'] = ptr_execute($conn, $saved['plan']);
    }
    if (in_array($action, ['state','save','pin'], true)) {
        $response += ['settings' => ptr_settings($conn), 'snapshots' => ptr_profiles($conn),
            'last_run' => ptr_read($conn, 'PLAYTHROUGH_RETENTION_LAST_RUN', null),
            'event_status' => 'The event-days box is preview only. This cleanup never deletes events: CHIM may still need them to build NPC memories.'];
    }
    echo json_encode($response, JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    if ($conn) @pg_query($conn, 'ROLLBACK');
    if (http_response_code() === 200) http_response_code($e instanceof InvalidArgumentException ? 400 : 409);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
} finally {
    if ($locked) ptr_unlock($conn);
    if ($conn) pg_close($conn);
}
