<?php

// Preserve bookmarked URLs, but do not leave a second set of mutation handlers reachable.
function dwemerStorageRedirect(string $mod, string $view): void
{
    if (defined('DWEMER_STORAGE_FRAGMENT') && DWEMER_STORAGE_FRAGMENT === true) return;
    $dashboard = dirname(__DIR__, 2) . '/Dwemer-Dashboard';
    if (!is_file($dashboard . '/lib/storage_fragment.php')) return; // Standalone install.
    $server = ['chim' => 'HerikaServer', 'stobe' => 'StobeServer', 'dialectic' => 'DialecticServer'][$mod];
    $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $position = strpos($script, '/' . $server . '/ui/');
    $prefix = $position === false ? '' : substr($script, 0, $position);
    $params = ['mod' => $view === 'shared' ? 'shared' : $mod, 'view' => $view === 'shared' ? 'databases' : $view];
    if ($view === 'shared' && $mod === 'dialectic') $params['server'] = 'dialectic';
    foreach (['action', 'filename', 'file', 'source', 'target', 'version_tab'] as $key) {
        if (isset($_GET[$key]) && is_string($_GET[$key])) $params[$key] = $_GET[$key];
    }
    $url = $prefix . '/Dwemer-Dashboard/data_manager.php?' . http_build_query($params);
    header('Cache-Control: no-store');
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        http_response_code(409);
        echo 'These tools have moved. Nothing was changed. <a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">Open Storage &amp; Cleanup</a> and try again.';
        exit;
    }
    header('Location: ' . $url, true, 302);
    exit;
}
