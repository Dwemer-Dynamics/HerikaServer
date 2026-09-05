<?php

// Do not fork a maintenance worker on every five-second service tick. Register
// it only when the persisted policy is enabled and the hourly interval is due.
(function () {
    require_once $GLOBALS['ENGINE_ROOT'] . 'lib/playthrough_retention.php';
    $conn = @pg_connect('host=localhost port=5432 dbname=dwemer user=dwemer password=dwemer', PGSQL_CONNECT_FORCE_NEW);
    if (!$conn) return;
    try {
        $settings = ptr_settings($conn);
        $due = time() - (int)ptr_read($conn, 'PLAYTHROUGH_RETENTION_LAST_ATTEMPT', 0) >= 3600;
        if (!$settings['automatic'] || !$due || (!$settings['diagnostics_enabled'] && !$settings['playthroughs_enabled'])) return;
        $GLOBALS['TASKS']['retention'] = ['fn' => function () {
            $workerConn = @pg_connect('host=localhost port=5432 dbname=dwemer user=dwemer password=dwemer', PGSQL_CONNECT_FORCE_NEW);
            if (!$workerConn) return;
            try { ptr_tick($workerConn); } finally { pg_close($workerConn); }
        }];
    } catch (Throwable $e) {
        Logger::warn('Retention policy could not be loaded; cleanup was skipped.');
    } finally {
        pg_close($conn);
    }
})();
