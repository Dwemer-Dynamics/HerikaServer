<?php

// No profile bootstrap on this API: reading settings must not run migrations or backups.
if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json');
header('Cache-Control: no-store');
require_once dirname(__DIR__, 2) . '/lib/playthrough_retention.php';

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
            throw new RuntimeException('Security check failed. Reload Playthrough Saves.');
        }
    }
    $conn = ptp_connect();
    if (!$conn) throw new RuntimeException('Database unavailable.');
    ptr_query($conn, "SET statement_timeout='20s'");
    ptr_query($conn, "SET lock_timeout='2s'");
    $action = $method === 'GET' ? 'state' : ($_POST['action'] ?? '');
    if (!is_string($action) || !in_array($action, ['state','save','save_backup','preview','preview_delete','run','pin'], true)) throw new InvalidArgumentException('That cleanup action is not recognized.');
    $previewKey = ptp_product()['meta'] . '_retention_preview';
    if ($method === 'POST') {
        $locked = ptr_lock($conn);
        if (!$locked) throw new RuntimeException('A playthrough operation or a cleanup is already running. Try again in a moment.');
    }
    $response = ['ok' => true];
    if ($action === 'save_backup') {
        $settings = ptp_validate_backup($_POST);
        ptr_query($conn, 'BEGIN');
        ptr_ensure_schema($conn);
        ptr_write($conn, 'PLAYTHROUGH_SAVE_POLICY', $settings);
        ptr_query($conn, 'COMMIT');
        unset($_SESSION[$previewKey]);
    } elseif ($action === 'save') {
        $settings = ptr_validate($_POST);
        ptr_query($conn, 'BEGIN');
        ptr_ensure_schema($conn);
        ptr_write($conn, 'PLAYTHROUGH_RETENTION', $settings);
        ptr_query($conn, 'COMMIT');
        unset($_SESSION[$previewKey]);
    } elseif ($action === 'pin') {
        $id = filter_var($_POST['profile_id'] ?? null, FILTER_VALIDATE_INT);
        $pinned = $_POST['pinned'] ?? null;
        if (!$id || $id < 1 || !in_array($pinned, ['0','1'], true)) throw new InvalidArgumentException('That playthrough protection request was not valid.');
        ptr_query($conn, 'BEGIN');
        ptr_ensure_schema($conn);
        $result = ptr_query($conn, 'UPDATE chim_meta.playthrough_profiles SET retention_pinned=$2 WHERE id=$1', [$id, $pinned === '1' ? 'true' : 'false']);
        if (pg_affected_rows($result) !== 1) throw new RuntimeException('That playthrough no longer exists.');
        ptr_query($conn, 'COMMIT');
        unset($_SESSION[$previewKey]);
    } elseif (in_array($action, ['preview','preview_delete'], true)) {
        // Bound repeated expensive previews in one browser session.
        if (time() - ($_SESSION[$previewKey . '_at'] ?? 0) < 2) {
            http_response_code(429);
            throw new RuntimeException('Please wait a moment before previewing again.');
        }
        $_SESSION[$previewKey . '_at'] = time();
        ptr_query($conn, 'BEGIN ISOLATION LEVEL REPEATABLE READ READ ONLY');
        if ($action === 'preview_delete') {
            $ids = json_decode(is_string($_POST['profile_ids'] ?? null) ? $_POST['profile_ids'] : '', true);
            if (!is_array($ids)) throw new InvalidArgumentException('Choose Playthrough Saves to delete.');
            $plan = ptr_preview_delete($conn, $ids);
        } else {
            // One-off form values do not save preferences or enable automatic cleanup.
            $input = array_intersect_key($_POST, ptr_defaults());
            $category = $_POST['preview_category'] ?? null;
            if ($category !== null) {
                if (!is_string($category) || ($category !== 'playthroughs' && !isset(ptr_categories()[$category]))) {
                    throw new InvalidArgumentException('Choose a valid cleanup category.');
                }
                $keys = $category === 'playthroughs' ? ['playthrough_keep'] : [$category . '_days', $category . '_max_mb'];
                if ($category === 'requests') $keys[] = 'requests_filter';
                $input = array_intersect_key($input, array_flip($keys));
            }
            $plan = ptr_preview($conn, $input ? ptr_validate($input) : ptr_settings($conn), $category);
        }
        ptr_query($conn, 'COMMIT');
        $token = bin2hex(random_bytes(24));
        $_SESSION[$previewKey] = ['token' => $token, 'plan' => $plan];
        foreach ($plan['diagnostics'] as &$group) unset($group['selected']);
        unset($group, $plan['identity']);
        $plan['token'] = $token;
        $plan['expires_at'] = gmdate('c', $plan['created'] + 300);
        $response['preview'] = $plan;
    } elseif ($action === 'run') {
        $saved = $_SESSION[$previewKey] ?? null;
        $token = $_POST['preview_token'] ?? null;
        if (!$saved || !is_string($token) || !hash_equals($saved['token'], $token)) throw new RuntimeException('Run a preview first, then confirm the cleanup.');
        unset($_SESSION[$previewKey]);
        ptr_ensure_schema($conn);
        $response['result'] = ptr_execute($conn, $saved['plan']);
    }
    if (in_array($action, ['state','save','save_backup','pin'], true)) {
        // The shared settings view already paginates playthroughs through its read-only list API.
        $categories = [];
        foreach (ptr_categories() as $key => $category) {
            if (ptr_exists($conn, 'public.' . $category['table'])) $categories[] = ['key'=>$key,'label'=>$category['label'],'description'=>$category['description']];
        }
        $response += ['settings' => ptr_settings($conn), 'backup_settings'=>ptp_backup_settings($conn),
            'last_backup'=>ptr_read($conn, 'PLAYTHROUGH_SAVE_LAST_ATTEMPT', null),
            'capabilities'=>['categories'=>$categories,'bulk_delete'=>true,'category_preview'=>true],
            'storage'=>($_GET['summary'] ?? '') === '1' ? null : ptr_storage_overview($conn, ptp_product()['meta']),
            'playthroughs' => ($_GET['summary'] ?? '') === '1' ? [] : ptr_profiles($conn),
            'last_run' => ptr_read($conn, 'PLAYTHROUGH_RETENTION_LAST_RUN', null),
            'event_status' => 'Events, NPC memories, diaries and relationship history are kept.'];
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
