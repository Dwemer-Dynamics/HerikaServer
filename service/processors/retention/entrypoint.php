<?php

// Do not fork a maintenance worker on every five-second service tick. Register
// it only when a cleanup category is enabled and the hourly interval is due.
(function () {
    require_once $GLOBALS['ENGINE_ROOT'] . 'lib/playthrough_retention.php';
    $conn = ptp_connect();
    if (!$conn) return;
    try {
        $settings = ptr_settings($conn);
        $due = time() - (int)ptr_read($conn, 'PLAYTHROUGH_RETENTION_LAST_ATTEMPT', 0) >= 3600;
        if (!$due || (!$settings['diagnostics_enabled'] && !$settings['events_enabled'] && (!$settings['playthroughs_enabled'] || $settings['playthrough_keep'] === 0))) return;
        $GLOBALS['TASKS']['retention'] = ['fn' => function () {
            $workerConn = ptp_connect();
            if (!$workerConn) return;
            try { ptr_tick($workerConn); } finally { pg_close($workerConn); }
        }];
    } catch (Throwable $e) {
        Logger::warn('Retention policy could not be loaded; cleanup was skipped.');
    } finally {
        pg_close($conn);
    }
})();
