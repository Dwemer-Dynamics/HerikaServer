<?php

// Shared "Storage & Cleanup" fragment mode. The Dwemer Dashboard includes this
// page in-process and renders its controls inside the shared shell, so only the
// document chrome and asset URLs adapt while server-owned operations stay here.
$ptmFragment = defined('DWEMER_STORAGE_FRAGMENT') && DWEMER_STORAGE_FRAGMENT === true;
$ptmSharedRoute = null;
if (!$ptmFragment) {
    // Shared compatibility policy lives in one place: redirect a bookmarked view,
    // refuse stale writes, and stay standalone when the Dashboard is absent.
    $ptmRouteHelper = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'lib'
        . DIRECTORY_SEPARATOR . 'storage_manager_route.php';
    if (is_file($ptmRouteHelper)) {
        require_once $ptmRouteHelper;
        dwemerStorageRedirect('chim', 'manage');
    }
    $ptmDashboardFile = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'Dwemer-Dashboard'
        . DIRECTORY_SEPARATOR . 'data_manager.php';
    if (is_file($ptmDashboardFile)) {
        $ptmScript = str_replace(DIRECTORY_SEPARATOR, '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
        $ptmServerPos = strpos($ptmScript, '/HerikaServer/ui/');
        $ptmPrefix = $ptmServerPos !== false ? substr($ptmScript, 0, $ptmServerPos) : '';
        $ptmSharedRoute = $ptmPrefix . '/Dwemer-Dashboard/data_manager.php';
    }
}

$enginePath = dirname(__DIR__) . DIRECTORY_SEPARATOR;

require_once(__DIR__.DIRECTORY_SEPARATOR."profile_loader.php");
require_once($enginePath . "lib" . DIRECTORY_SEPARATOR . "{$GLOBALS["DBDRIVER"]}.class.php");
require_once($enginePath . "lib" . DIRECTORY_SEPARATOR . "logger.php");
require_once($enginePath . "lib" . DIRECTORY_SEPARATOR . "utils_game_timestamp.php");
require_once($enginePath . "lib" . DIRECTORY_SEPARATOR . "playthrough_storage.php");
require_once($enginePath . "lib" . DIRECTORY_SEPARATOR . "playthrough_schema.php");
require_once($enginePath . "lib" . DIRECTORY_SEPARATOR . "playthrough_retention.php");

// Session must be active before any output; the CSRF token lives in it.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (empty($_SESSION['ptm_csrf'])) {
    $_SESSION['ptm_csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = (string)$_SESSION['ptm_csrf'];

// Determine web root (match other core pages)
$scriptPath = $_SERVER['SCRIPT_NAME'];
$uiPos = strpos($scriptPath, '/ui/');
if ($uiPos !== false) {
    $webRoot = substr($scriptPath, 0, $uiPos);
} else {
    $webRoot = '';
}
if ($webRoot == '/') $webRoot = '';
$webRoot = rtrim($webRoot, '/');

$TITLE = "🎮 CHIM - Playthrough Manager";
$debugPaneLink = false;
if ($ptmFragment) {
    // The shared page lives under a different path, so every asset and endpoint
    // URL is rebuilt against this server's own web root.
    $webRoot = DWEMER_STORAGE_FRAGMENT_WEBROOT;
    $isEmbed = true;
    foreach ([
        $webRoot . '/ui/lib/ui/bootstrap/bootstrap.min.css',
        $webRoot . '/ui/css/style_new.css',
        $webRoot . '/ui/css/chim-theme.css',
        $webRoot . '/ui/css/main.css',
    ] as $ptmStyleHref) {
        if (function_exists('dwemer_storage_fragment_style')) {
            dwemer_storage_fragment_style($ptmStyleHref);
        } else {
            echo '<link rel="stylesheet" href="' . htmlspecialchars($ptmStyleHref, ENT_QUOTES, 'UTF-8') . '">';
        }
    }
} else {
    ob_start();
    include(__DIR__.DIRECTORY_SEPARATOR."tmpl/head.html");
?>

<link rel="stylesheet" href="<?php echo $webRoot; ?>/ui/css/main.css">

<?php
    // Embed mode and navbar (match Oghma style)
    $isEmbed = (isset($_GET['embed']) && $_GET['embed'] == '1');
    if (!$isEmbed) {
        include(__DIR__.DIRECTORY_SEPARATOR."tmpl/navbar.php");
    }
}

// Where the combined backup/maintenance tools live for this install.
$ptmDatabaseToolsUrl = $webRoot . '/ui/import_db.php';
$ptmDatabaseToolsLabel = 'Database Manager';
if ($ptmFragment) {
    $ptmDatabaseToolsUrl = 'data_manager.php?mod=shared&view=databases';
    $ptmDatabaseToolsLabel = 'Shared databases';
} elseif ($ptmSharedRoute !== null) {
    $ptmDatabaseToolsUrl = $ptmSharedRoute . '?mod=shared&view=databases';
    $ptmDatabaseToolsLabel = 'Shared databases';
}

// DB connection details (aligned with import_db.php)
$host = 'localhost';
$port = '5432';
$dbname = 'dwemer';
$schema = 'public';
$username = 'dwemer';
$password = 'dwemer';

$adminConn = @pg_connect("host={$host} port={$port} dbname={$dbname} user={$username} password={$password}");

$db = new sql();
$message = '';
if (!$adminConn) {
    $message .= '<p><strong>Error:</strong> Failed to connect to the database.</p>';
}

// Helpers
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function formatFileSize($bytes) {
    if ($bytes == 0) return '0 Bytes';
    $k = 1024; $sizes = ['Bytes','KB','MB','GB','TB'];
    $i = floor(log($bytes) / log($k));
    return round($bytes / pow($k, $i), 2) . ' ' . $sizes[$i];
}

// PostgreSQL boolean columns come back as 't'/'f' strings from pg_fetch_assoc;
// PDO-style drivers may hand over real booleans or '1'/'0'. Normalize once here.
function ptm_is_active($v): bool {
    if (is_bool($v)) return $v;
    $s = strtolower(trim((string)$v));
    return in_array($s, ['t', 'true', '1', 'on', 'yes'], true);
}

// Read-only existence check for the profile metadata table (catalog only).
function ptm_meta_table_exists($conn): bool {
    if (!$conn) return false;
    $res = @pg_query_params(
        $conn,
        "SELECT 1 FROM pg_catalog.pg_class c JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace WHERE n.nspname = $1 AND c.relname = $2 AND c.relkind = 'r' LIMIT 1",
        ['chim_meta', 'playthrough_profiles']
    );
    return ($res && pg_fetch_assoc($res)) ? true : false;
}

// Schema/table initialization and migrations. Only ever called from a valid POST;
// GET requests never mutate the database.
function ptm_run_setup_migrations($adminConn): void {
    $initSQL = [];
    $initSQL[] = "CREATE SCHEMA IF NOT EXISTS chim_meta";
    $initSQL[] = "CREATE TABLE IF NOT EXISTS chim_meta.playthrough_profiles (\n        id SERIAL PRIMARY KEY,\n        name TEXT NOT NULL UNIQUE,\n        created_at TIMESTAMP NOT NULL DEFAULT NOW(),\n        size_bytes BIGINT NOT NULL,\n        storage_format TEXT NOT NULL DEFAULT 'plain_sql',\n        notes TEXT,\n        is_active BOOLEAN NOT NULL DEFAULT FALSE\n    )";
    $initSQL[] = "CREATE TABLE IF NOT EXISTS chim_meta.playthrough_blobs (\n        profile_id INT PRIMARY KEY REFERENCES chim_meta.playthrough_profiles(id) ON DELETE CASCADE,\n        dump_data TEXT\n    )";
    // Metadata columns (idempotent)
    $initSQL[] = "ALTER TABLE chim_meta.playthrough_profiles ADD COLUMN IF NOT EXISTS player_name TEXT";
    $initSQL[] = "ALTER TABLE chim_meta.playthrough_profiles ADD COLUMN IF NOT EXISTS game TEXT";
    $initSQL[] = "ALTER TABLE chim_meta.playthrough_profiles ADD COLUMN IF NOT EXISTS eventlog_count BIGINT";
    $initSQL[] = "ALTER TABLE chim_meta.playthrough_profiles ADD COLUMN IF NOT EXISTS oghma_count BIGINT";
    $initSQL[] = "ALTER TABLE chim_meta.playthrough_profiles ADD COLUMN IF NOT EXISTS last_gamets BIGINT";
    // Schema-based storage columns
    $initSQL[] = "ALTER TABLE chim_meta.playthrough_profiles ADD COLUMN IF NOT EXISTS schema_name TEXT";
    $initSQL[] = "ALTER TABLE chim_meta.playthrough_profiles ADD COLUMN IF NOT EXISTS storage_type TEXT DEFAULT 'dump'";
    foreach ($initSQL as $qs) {
        ptr_query($adminConn, $qs);
    }
    ptr_ensure_schema($adminConn);
    ptm_ensure_lob_schema($adminConn);

    // Ensure schema cloning functions exist
    pts_ensure_functions($adminConn);

    // If an older deployment created dump_data as BYTEA, migrate it to TEXT (plain SQL dumps)
    $colTypeRes = @pg_query($adminConn, "SELECT data_type FROM information_schema.columns WHERE table_schema='chim_meta' AND table_name='playthrough_blobs' AND column_name='dump_data'");
    if ($colTypeRes) {
        $ct = pg_fetch_assoc($colTypeRes);
        if ($ct && isset($ct['data_type']) && strtolower($ct['data_type']) === 'bytea') {
            @pg_query($adminConn, "ALTER TABLE chim_meta.playthrough_blobs ALTER COLUMN dump_data TYPE TEXT USING convert_from(dump_data,'UTF8')");
        }
    }
}

// Snapshot the live public schema's descriptive metadata (counts run on the live DB).
function ptm_collect_live_metadata($adminConn, string $schema): array {
    $meta = [
        'player_name' => (string)($GLOBALS['PLAYER_NAME'] ?? 'Unknown'),
        'game' => 'Skyrim',
        'eventlog_count' => 0,
        'oghma_count' => 0,
        'last_gamets' => 0,
    ];
    $r1 = @pg_query($adminConn, "SELECT COUNT(*) AS c FROM {$schema}.eventlog");
    if ($r1 && ($rr = pg_fetch_assoc($r1))) { $meta['eventlog_count'] = intval($rr['c']); }
    $rex = @pg_query_params($adminConn, "SELECT 1 FROM information_schema.tables WHERE table_schema=$1 AND table_name='oghma' LIMIT 1", [$schema]);
    if ($rex && pg_fetch_assoc($rex)) {
        $r2 = @pg_query($adminConn, "SELECT COUNT(*) AS c FROM {$schema}.oghma");
        if ($r2 && ($rr = pg_fetch_assoc($r2))) { $meta['oghma_count'] = intval($rr['c']); }
    }
    $r3 = @pg_query($adminConn, "SELECT MAX(gamets) AS mx FROM {$schema}.eventlog");
    if ($r3 && ($rr = pg_fetch_assoc($r3)) && !is_null($rr['mx'])) { $meta['last_gamets'] = intval($rr['mx']); }
    return $meta;
}

// First-run capture of the current database as the 'default' snapshot (POST only).
function ptm_create_default_snapshot($adminConn, string $schema): array {
    // Guard against partial states: skip if any profile (or a 'default' row) already exists.
    $cntRes = @pg_query($adminConn, "SELECT COUNT(*) AS c FROM chim_meta.playthrough_profiles");
    if ($cntRes && ($c = pg_fetch_assoc($cntRes)) && intval($c['c']) > 0) {
        return ['success' => true, 'existing' => true];
    }
    $existsRes = @pg_query_params($adminConn, "SELECT 1 FROM chim_meta.playthrough_profiles WHERE name=$1 LIMIT 1", ['default']);
    if ($existsRes && pg_fetch_assoc($existsRes)) {
        return ['success' => true, 'existing' => true];
    }

    $meta = ptm_collect_live_metadata($adminConn, $schema);
    $schemaName = pts_sanitize_profile_name('default');
    $cloneResult = pts_clone_schema($adminConn, 'public', $schemaName);
    if (!$cloneResult['success']) {
        return ['success' => false];
    }
    $size = pts_get_schema_size($adminConn, $schemaName);
    @pg_query($adminConn, 'BEGIN');
    $q1 = @pg_query_params(
        $adminConn,
        "INSERT INTO chim_meta.playthrough_profiles (name, size_bytes, storage_type, notes, is_active, player_name, game, eventlog_count, oghma_count, last_gamets, schema_name) VALUES ($1,$2,$3,$4,true,$5,$6,$7,$8,$9,$10)",
        ['default', (string)$size, 'schema', 'Auto-captured default profile', $meta['player_name'], $meta['game'], (string)$meta['eventlog_count'], (string)$meta['oghma_count'], (string)$meta['last_gamets'], $schemaName]
    );
    if ($q1) {
        @pg_query($adminConn, 'COMMIT');
        return ['success' => true];
    }
    @pg_query($adminConn, 'ROLLBACK');
    return ['success' => false];
}

// Handle actions (POST only — GET never writes to the database)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedToken = (string)($_POST['csrf_token'] ?? '');
    if ($postedToken === '' || !hash_equals($csrfToken, $postedToken)) {
        $message .= '<p><strong>Error:</strong> Security check failed (missing or expired form token). No changes were made. Please reload the page and try again.</p>';
    } elseif (!$adminConn) {
        // Connection error message already queued above.
    } elseif (!ptr_lock($adminConn)) {
        $message .= '<p><strong>Error:</strong> Another snapshot operation or cleanup is running. Try again shortly.</p>';
    } else {
        try {
        // Initialization/migrations run only after a CSRF-validated POST.
        ptm_run_setup_migrations($adminConn);
        $action = $_POST['action'] ?? '';

    if ($action === 'setup') {
        $res = ptm_create_default_snapshot($adminConn, $schema);
        if ($res['success']) {
            $message .= !empty($res['existing'])
                ? '<p>Playthrough management is already set up. Existing snapshots were preserved.</p>'
                : '<p><strong>✅ Playthrough management is set up.</strong> Your current data was captured as the <strong>default</strong> snapshot and is now the active playthrough.</p>';
        } else {
            $message .= '<p><strong>Error:</strong> Setup failed. Please check the server logs and try again.</p>';
        }
    }

    if ($action === 'create') {
        $name = trim((string)($_POST['name'] ?? ''));
        $notes = trim((string)($_POST['notes'] ?? ''));
        if ($name === '') {
            $message .= '<p><strong>Error:</strong> Name is required.</p>';
        } else {
            // Use schema-based storage for instant snapshots
            $schemaName = pts_sanitize_profile_name($name);

            // Check if schema already exists
            if (pts_schema_exists($adminConn, $schemaName)) {
                $message .= '<p><strong>Error:</strong> A snapshot with this name already exists.</p>';
            } else {
                // Clone public schema to new profile schema
                $cloneResult = pts_clone_schema($adminConn, 'public', $schemaName);

                if (!$cloneResult['success']) {
                    $message .= '<p><strong>Error:</strong> Failed to create snapshot.</p>';
                    $message .= '<pre>'.h($cloneResult['error']).'</pre>';
                } else {
                    // Collect metadata
                    $meta = ptm_collect_live_metadata($adminConn, $schema);

                    // Calculate schema size
                    $size = pts_get_schema_size($adminConn, $schemaName);

                    // Insert profile record
                    @pg_query($adminConn, 'BEGIN');
                    $q1 = @pg_query_params(
                        $adminConn,
                        "INSERT INTO chim_meta.playthrough_profiles (name, size_bytes, storage_type, notes, is_active, player_name, game, eventlog_count, oghma_count, last_gamets, schema_name) VALUES ($1,$2,$3,$4,false,$5,$6,$7,$8,$9,$10)",
                        [$name, (string)$size, 'schema', $notes, $meta['player_name'], $meta['game'], (string)$meta['eventlog_count'], (string)$meta['oghma_count'], (string)$meta['last_gamets'], $schemaName]
                    );
                    if ($q1) {
                        @pg_query($adminConn, 'COMMIT');
                        $message .= '<p><strong>✅ Snapshot created:</strong> '.h($name).' ('.h(formatFileSize($size)).')</p>';
                    } else {
                        @pg_query($adminConn, 'ROLLBACK');
                        $message .= '<p><strong>Error:</strong> Failed to save snapshot metadata.</p>';
                    }
                }
            }
        }
    }

    if ($action === 'switch') {
        $profileId = intval($_POST['profile_id'] ?? 0);
        if ($profileId <= 0) {
            $message .= '<p><strong>Error:</strong> Invalid snapshot selected.</p>';
        } else {
            // Fetch target profile info
            $targetRes = pg_query_params($adminConn, 'SELECT name, storage_type, schema_name, is_active FROM chim_meta.playthrough_profiles WHERE id=$1', [$profileId]);
            $targetRow = $targetRes ? pg_fetch_assoc($targetRes) : null;
            if (!$targetRow) {
                $message .= '<p><strong>Error:</strong> Snapshot not found.</p>';
                goto SWITCH_ABORT;
            }
            if (ptm_is_active($targetRow['is_active'] ?? null)) {
                $message .= '<p><strong>Error:</strong> This snapshot is already the active playthrough. Nothing to restore.</p>';
                goto SWITCH_ABORT;
            }

            $targetName = $targetRow['name'];
            $targetStorageType = $targetRow['storage_type'] ?? 'dump';
            $targetSchemaName = $targetRow['schema_name'] ?? '';

            // 1) Auto-save current active profile BEFORE switching
            $curRes = pg_query($adminConn, "SELECT id, name, storage_type, schema_name FROM chim_meta.playthrough_profiles WHERE is_active = true LIMIT 1");
            $curRow = $curRes ? pg_fetch_assoc($curRes) : null;
            if (!$curRow) {
                $message .= '<p><strong>Error:</strong> No loaded snapshot is recorded. Restore was cancelled to preserve your current playthrough.</p>';
                goto SWITCH_ABORT;
            }
            if ($curRow) {
                $curProfileId = intval($curRow['id']);
                $curStorageType = $curRow['storage_type'] ?? 'dump';
                $curSchemaName = $curRow['schema_name'] ?? '';

                // Gather live metadata
                $meta = ptm_collect_live_metadata($adminConn, $schema);

                if ($curStorageType === 'schema' && !empty($curSchemaName)) {
                    // Schema-based: clone public back to profile schema
                    $cloneResult = pts_clone_schema($adminConn, 'public', $curSchemaName);
                    if (!$cloneResult['success']) {
                        $message .= '<p><strong>Error:</strong> Failed to save the current playthrough. Aborting restore.</p>';
                        $message .= '<pre>'.h($cloneResult['error']).'</pre>';
                        goto SWITCH_ABORT;
                    }
                    // Update metadata
                    $size = pts_get_schema_size($adminConn, $curSchemaName);
                    @pg_query_params(
                        $adminConn,
                        'UPDATE chim_meta.playthrough_profiles SET size_bytes=$2, player_name=$3, game=$4, eventlog_count=$5, oghma_count=$6, last_gamets=$7 WHERE id=$1',
                        [(string)$curProfileId, (string)$size, $meta['player_name'], $meta['game'], (string)$meta['eventlog_count'], (string)$meta['oghma_count'], (string)$meta['last_gamets']]
                    );
                } else {
                    // Legacy dump-based: use old system
                    $tmpSnap = sys_get_temp_dir() . DIRECTORY_SEPARATOR . ('playthrough_autosave_'.time().'_'.mt_rand(1000,9999).'.sql');
                    $dumpCmd = "PGPASSWORD=".escapeshellarg($password)." pg_dump -h ".escapeshellarg($host)." -p ".escapeshellarg($port)." -U ".escapeshellarg($username)." -d ".escapeshellarg($dbname)." -N chim_meta > ".escapeshellarg($tmpSnap)." 2>&1";
                    $dumpOut = shell_exec($dumpCmd);
                    if (!file_exists($tmpSnap) || filesize($tmpSnap) === 0) {
                        $preview = $dumpOut ? '<pre>'.h(substr($dumpOut,0,2000)).'</pre>' : '';
                        $message .= '<p><strong>Error:</strong> Failed to snapshot current database. Aborting.</p>'.$preview;
                        goto SWITCH_ABORT;
                    }
                    $upd = ptm_update_profile_blob_from_file($adminConn, $curProfileId, $tmpSnap, $meta['player_name'], $meta['game'], (int)$meta['eventlog_count'], (int)$meta['oghma_count'], (int)$meta['last_gamets']);
                    @unlink($tmpSnap);
                    if (!$upd['success']) {
                        $message .= '<p><strong>Error:</strong> Failed to save current snapshot. Aborting.</p>';
                        goto SWITCH_ABORT;
                    }
                }
            }

            // 2) Load target profile
            if ($targetStorageType === 'schema' && !empty($targetSchemaName)) {
                // Schema-based: fast switch
                if (!pts_schema_exists($adminConn, $targetSchemaName)) {
                    $message .= '<p><strong>Error:</strong> Snapshot schema does not exist.</p>';
                    goto SWITCH_ABORT;
                }

                // Keep the live public schema intact if recreating or cloning the snapshot fails.
                if (!@pg_query($adminConn, 'BEGIN')) {
                    $message .= '<p><strong>Error:</strong> Failed to start restore.</p>';
                    goto SWITCH_ABORT;
                }

                // Recreate public schema
                if (!pts_recreate_public_schema($adminConn)) {
                    @pg_query($adminConn, 'ROLLBACK');
                    $message .= '<p><strong>Error:</strong> Failed to recreate public schema.</p>';
                    goto SWITCH_ABORT;
                }

                // Clone profile schema to public
                $cloneResult = pts_clone_schema($adminConn, $targetSchemaName, 'public');
                if (!$cloneResult['success']) {
                    @pg_query($adminConn, 'ROLLBACK');
                    $message .= '<p><strong>Error:</strong> Failed to restore snapshot.</p>';
                    $message .= '<pre>'.h($cloneResult['error']).'</pre>';
                    goto SWITCH_ABORT;
                }

                // Mark active
                $clearActive = @pg_query($adminConn, 'UPDATE chim_meta.playthrough_profiles SET is_active = false');
                $resU = $clearActive
                    ? @pg_query_params($adminConn, 'UPDATE chim_meta.playthrough_profiles SET is_active = true WHERE id=$1', [$profileId])
                    : false;
                $resetVersioning = $resU
                    ? @pg_query($adminConn, 'TRUNCATE TABLE public.database_versioning')
                    : false;
                if ($resetVersioning && @pg_query($adminConn, 'COMMIT')) {
                    $message .= '<p><strong>✅ Restored snapshot:</strong> '.h($targetName).'</p>';
                    $message .= '<div style="background:#4a1e0d; border:2px solid #dc2626; border-radius:8px; padding:15px; margin-top:15px;">';
                    $message .= '<p style="color:#fbbf24; font-weight:bold; margin:0 0 10px 0;">⚠️ RESTART REQUIRED</p>';
                    $message .= '<p style="margin:0 0 8px 0;">You must restart the CHIM server for the restore to take effect:</p>';
                    $message .= '<ol style="margin:5px 0; padding-left:20px;">';
                    $message .= '<li>Shutdown Skyrim</li>';
                    $message .= '<li>Restart CHIM Server</li>';
                    $message .= '<li>Restart Skyrim and load into the save you want to continue from</li>';
                    $message .= '</ol>';
                    $message .= '</div>';
                } else {
                    @pg_query($adminConn, 'ROLLBACK');
                    $message .= '<p><strong>Error:</strong> Restore failed. The current active playthrough was preserved.</p>';
                }
            } else {
                // Legacy dump-based: slow restore
                $tmpFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . ('playthrough_restore_'.time().'_'.mt_rand(1000,9999).'.sql');
                $ff = ptm_fetch_snapshot_to_file($adminConn, $profileId, $tmpFile);
                if (!$ff['success']) {
                    $message .= '<p><strong>Error:</strong> Failed to materialize snapshot.</p><pre>'.h($ff['error']).'</pre>';
                    goto SWITCH_ABORT;
                }

                // Drop and recreate public schema
                $Q = ["DROP SCHEMA IF EXISTS {$schema} CASCADE", "DROP EXTENSION IF EXISTS vector CASCADE", "DROP EXTENSION IF EXISTS pg_trgm CASCADE", "CREATE SCHEMA {$schema}", "CREATE EXTENSION IF NOT EXISTS vector", "CREATE EXTENSION IF NOT EXISTS pg_trgm"];
                $errorOccurred = false;
                foreach ($Q as $QS) {
                    if (!pg_query($adminConn, $QS)) {
                        $message .= '<p>Error: '.h(pg_last_error($adminConn)).'</p>';
                        $errorOccurred = true;
                        break;
                    }
                }

                if (!$errorOccurred) {
                    $psqlCommand = "PGPASSWORD=".escapeshellarg($password)." psql -h ".escapeshellarg($host)." -p ".escapeshellarg($port)." -U ".escapeshellarg($username)." -d ".escapeshellarg($dbname)." -f ".escapeshellarg($tmpFile);
                    $output = []; $returnVar = 0;
                    exec($psqlCommand, $output, $returnVar);
                    if ($returnVar !== 0) {
                        $message .= '<p><strong>Error:</strong> Failed to restore snapshot.</p><pre>'.h(implode("\n", $output)).'</pre>';
                    } else {
                        pg_query($adminConn, 'BEGIN');
                        pg_query($adminConn, 'UPDATE chim_meta.playthrough_profiles SET is_active = false');
                        $resU = pg_query_params($adminConn, 'UPDATE chim_meta.playthrough_profiles SET is_active = true WHERE id=$1', [$profileId]);
                        if ($resU) {
                            pg_query($adminConn, 'COMMIT');
                            $message .= '<p><strong>✅ Restored snapshot:</strong> '.h($targetName).'</p>';
                            $message .= '<div style="background:#4a1e0d; border:2px solid #dc2626; border-radius:8px; padding:15px; margin-top:15px;">';
                            $message .= '<p style="color:#fbbf24; font-weight:bold; margin:0 0 10px 0;">⚠️ RESTART REQUIRED</p>';
                            $message .= '<p style="margin:0 0 8px 0;">You must restart the CHIM server for the restore to take effect:</p>';
                            $message .= '<ol style="margin:5px 0; padding-left:20px;">';
                            $message .= '<li>Restart Apache/Nginx (or restart Docker container)</li>';
                            $message .= '<li>Any background services connected to the database</li>';
                            $message .= '<li>The Skyrim mod will automatically reconnect</li>';
                            $message .= '</ol>';
                            $message .= '</div>';
                        } else {
                            pg_query($adminConn, 'ROLLBACK');
                            $message .= '<p><strong>Warning:</strong> Database restored but failed to mark profile active.</p>';
                        }
                    }
                }
                @unlink($tmpFile);
            }
        }
        SWITCH_ABORT:;
    }

    if ($action === 'delete') {
        $profileId = intval($_POST['profile_id'] ?? 0);
        if ($profileId <= 0) {
            $message .= '<p><strong>Error:</strong> Invalid snapshot selected.</p>';
        } else {
            $rowRes = pg_query_params($adminConn, 'SELECT is_active, name, storage_type, schema_name FROM chim_meta.playthrough_profiles WHERE id=$1', [$profileId]);
            $row = $rowRes ? pg_fetch_assoc($rowRes) : null;
            if (!$row) {
                $message .= '<p><strong>Error:</strong> Snapshot not found.</p>';
            } else if (ptm_is_active($row['is_active'] ?? null)) {
                $message .= '<p><strong>Error:</strong> Cannot delete the active playthrough.</p>';
            } else if (strtolower((string)$row['name']) === 'default') {
                $message .= '<p><strong>Error:</strong> Cannot delete the default snapshot.</p>';
            } else {
                ptr_query($adminConn, 'BEGIN');
                ptr_query($adminConn, "SET LOCAL lock_timeout='2s'");
                ptr_delete_snapshot($adminConn, $profileId);
                ptr_query($adminConn, 'COMMIT');
                $message .= '<p><strong>✅ Deleted:</strong> '.h($row['name']).'</p>';
            }
        }
    }
        } catch (Throwable $e) {
            @pg_query($adminConn, 'ROLLBACK');
            Logger::error('Playthrough Manager: ' . $e->getMessage());
            $message .= '<p><strong>Error:</strong> The operation could not finish. Protected snapshots cannot be deleted. Check the server log for details.</p>';
        } finally {
            ptr_unlock($adminConn);
        }
    }
}

// Read-only state detection (safe on GET: catalog queries only)
$ptmInitialized = $adminConn ? ptm_meta_table_exists($adminConn) : false;

// Fetch profiles
$profiles = [];
if ($ptmInitialized) {
    $profiles = $db->fetchAll("SELECT p.* FROM chim_meta.playthrough_profiles p ORDER BY COALESCE((to_jsonb(p)->>'last_gamets')::bigint,0) DESC, created_at DESC");
    if (!is_array($profiles)) { $profiles = []; }
}
$ptmNeedsSetup = (!$ptmInitialized || count($profiles) === 0);

// Live stats for currently loaded (active) playthrough; initial values come from
// snapshot metadata (fast) and are refreshed asynchronously.
$activeProfileName = '';
$livePlayerName = (string)($GLOBALS['PLAYER_NAME'] ?? 'Unknown');
$liveGameName = 'Skyrim';
$liveEventlogCount = 0; $liveOghmaCount = 0; $liveLastGamets = 0;
foreach ($profiles as $p) {
    if (ptm_is_active($p['is_active'] ?? null)) {
        $activeProfileName = (string)($p['name'] ?? '');
        $liveEventlogCount = intval($p['eventlog_count'] ?? 0);
        $liveOghmaCount = intval($p['oghma_count'] ?? 0);
        $liveLastGamets = intval($p['last_gamets'] ?? 0);
        break;
    }
}
$liveSkyrimDate = ($liveLastGamets > 0) ? convert_gamets2skyrim_long_date($liveLastGamets) : '';

// Prepare timeline items based on last_gamets
$timelineItems = [];
foreach ($profiles as $p) {
    $nameStr = (string)($p['name'] ?? '');
    $isActive = ptm_is_active($p['is_active'] ?? null);
    $lgMeta = isset($p['last_gamets']) ? intval($p['last_gamets']) : 0;
    $lg = $lgMeta;
    if ($lg <= 0 && $isActive && $liveLastGamets > 0) { $lg = $liveLastGamets; }
    if ($lg <= 0) { continue; }
    $timelineItems[] = [
        'id' => (int)$p['id'],
        'name' => $nameStr,
        'last_gamets' => $lg,
        'skyrim_date' => convert_gamets2skyrim_long_date($lg),
        'created_at' => (string)$p['created_at'],
        'size' => formatFileSize((int)$p['size_bytes']),
        'is_active' => $isActive
    ];
}

// Timeline ticks (static notches with labels)
$timelineTicks = [];
if (!empty($timelineItems)) {
    $values = array_map(function($i){ return (int)$i['last_gamets']; }, $timelineItems);
    $minGamets = min($values);
    $maxGamets = max($values);
    $segments = min(max(count($timelineItems) - 1, 4), 12); // 4..12 ticks based on data
    if ($maxGamets === $minGamets) {
        // Degenerate: place a center tick
        $timelineTicks[] = [
            'gamets' => $minGamets,
            'date' => convert_gamets2skyrim_long_date($minGamets)
        ];
    } else {
        for ($s = 0; $s <= $segments; $s++) {
            $g = (int)round($minGamets + ($s * ($maxGamets - $minGamets) / $segments));
            $timelineTicks[] = [
                'gamets' => $g,
                'date' => convert_gamets2skyrim_long_date($g)
            ];
        }
    }
}

$csrfField = '<input type="hidden" name="csrf_token" value="'.h($csrfToken).'">';

?>

<style>
    main { padding-top: 80px; padding-bottom: 40px; padding-left: 10%; padding-right: 10%; width: 100%; margin: 0; }
    footer { position: fixed; bottom: 0; width: 100%; height: 20px; background: #031633; z-index: 100; }

    .page-header {
        text-align: center;
        margin-bottom: 30px;
        padding: 20px;
        background: linear-gradient(180deg, rgba(42, 42, 42, 0.95), rgba(34, 34, 34, 0.98));
        border-radius: 10px;
        border: 1px solid #3a3a3a;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15), inset 0 1px rgba(255, 255, 255, 0.03);
    }

    .page-header h1 {
        margin-bottom: 8px;
        font-family: 'MagicCards', serif;
        word-spacing: 8px;
        font-size: 2.0em;
        color: rgb(242, 124, 17);
        text-shadow: 2px 2px 4px rgba(0,0,0,0.5);
    }

    .content-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 30px; margin-bottom: 30px; }

    .content-section {
        background: linear-gradient(135deg, rgba(42, 42, 42, 0.95), rgba(34, 34, 34, 0.98));
        padding: 25px;
        border-radius: 10px;
        border: 1px solid #3a3a3a;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15), inset 0 1px rgba(255, 255, 255, 0.03);
        transition: border-color 0.3s ease, box-shadow 0.3s ease;
    }

    .content-section:hover {
        border-color: rgba(242, 124, 17, 0.3);
        box-shadow: 0 4px 16px rgba(0, 0, 0, 0.25), inset 0 1px rgba(255, 255, 255, 0.05);
    }

    .content-section h2 {
        font-family: 'MagicCards', serif;
        color: rgb(242, 124, 17);
        text-shadow: 1px 1px 2px rgba(0,0,0,0.5);
        word-spacing: 6px;
        margin-bottom: 15px;
        font-size: 1.4em;
    }

    .full-width-section { grid-column: 1 / -1; }
    .button-group { display: flex; gap: 15px; margin-top: 15px; flex-wrap: wrap; }
    @font-face { font-family: 'MagicCards'; src: url('<?php echo $webRoot; ?>/ui/css/font/MagicCardsNormal.ttf') format('truetype'); font-weight: normal; font-style: normal; }
    @media (max-width: 768px) { main { padding-left: 5%; padding-right: 5%; } .content-grid { grid-template-columns: 1fr; } .content-section { padding: 15px; } }
    /* Timeline */
    .timeline { position: relative; padding: 28px 8px 30px 8px; }
    .timeline-title { text-align:center; color:#e0e0e0; font-size: 13px; margin-bottom: 12px; }
    .timeline-track { position: relative; height: 4px; background: linear-gradient(90deg, rgba(138,155,182,0.5), rgba(242,124,17,0.6)); border-radius: 2px; }
    .timeline-nodes { position: relative; height: 0; }
    /* Explicit resets so timeline nodes never inherit global button min-width/padding */
    .timeline-node { position: absolute; top: -8px; width: 16px; height: 16px; min-width: 0; min-height: 0; padding: 0; margin: 0; line-height: 0; box-sizing: border-box; appearance: none; -webkit-appearance: none; border-radius: 50%; background: #ffb862; border: 2px solid #1a1a1a; box-shadow: 0 0 0 2px rgba(255,255,255,0.08); transform: translateX(-50%); cursor: pointer; }
    .timeline-node.active { background: #2ea8ff; box-shadow: 0 0 0 2px rgba(46,168,255,0.25), 0 0 12px rgba(46,168,255,0.35); }
    .timeline-tooltip { position: absolute; display: none; max-width: 280px; background: #111; border: 1px solid rgba(138,155,182,0.4); color: #e0e0e0; padding: 8px 10px; border-radius: 6px; font-size: 12px; z-index: 20; pointer-events: none; box-shadow: 0 8px 24px rgba(0,0,0,0.4); }
    .timeline-tooltip .name { color: #ffb862; font-weight: bold; }
    .timeline-legend { display:flex; justify-content:space-between; font-size: 12px; color:#9fb1c9; margin-top: 8px; }
    .timeline-notches { position: relative; height: 0; }
    .timeline-notch { position: absolute; top: -12px; width: 2px; height: 10px; background: #9fb1c9; opacity: 0.7; transform: translateX(-50%); }
    .timeline-notch.major { height: 14px; background:#e0e0e0; opacity: 0.9; }
    .timeline-tick-label { position: absolute; top: -30px; transform: translateX(-50%); color:#9fb1c9; font-size: 11px; white-space: nowrap; pointer-events: none; }
    .timeline-label { position: absolute; top: -28px; transform: translateX(-50%); color:#9fb1c9; font-size: 11px; white-space: nowrap; pointer-events: none; }
    .timeline-label.active { color:#eaee05; }
    /* Dragon Break styling */
    .backup-item.dragonbreak { background-color: #1e2a3a; }
    .backup-item.dragonbreak:hover { background-color: #223044; }
    /* Snapshot list */
    .backup-list { max-height: 420px; overflow-y: auto; padding: 0; margin: 0; border: 1px solid #333333; border-radius: 8px; background-color: #1a1a1a; }
    .backup-item { padding: 12px; border-bottom: 1px solid #333333; }
    .backup-item.active-snapshot { background: rgba(74, 222, 128, 0.1); border-left: 4px solid #4ade80; }
    .backup-row { display: flex; justify-content: space-between; gap: 10px; flex-wrap: wrap; }
    .backup-info { flex: 1; min-width: 220px; }
    .backup-actions { display: flex; gap: 6px; align-items: flex-start; flex-wrap: wrap; }
    .badge-loaded { background:#4ade80; color:#000; padding:2px 8px; border-radius:4px; font-size:11px; font-weight:bold; margin-right:6px; }
    @media (max-width: 700px) {
        .backup-row { flex-direction: column; }
        .backup-actions { width: 100%; }
        .backup-actions form, .backup-actions .button { max-width: 100%; }
    }
    /* Switch overlay */
    #switch-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.65); z-index: 2000; display: none; align-items: center; justify-content: center; }
    #switch-overlay .loading-modal { background:#111; border:1px solid #444; border-radius:8px; padding:20px 24px; width: 340px; color:#e0e0e0; text-align:center; box-shadow: 0 12px 36px rgba(0,0,0,0.5); }
    #switch-overlay .loading-title { font-family: 'MagicCards', serif; color: rgb(242, 124, 17); margin-bottom: 8px; font-size: 1.2em; word-spacing: 6px; }
    #switch-overlay .loading-sub { font-size: 12px; color:#9fb1c9; margin-top: 6px; }
    .lds-ring { display:inline-block; position:relative; width:64px; height:64px; margin: 6px 0 2px 0; }
    .lds-ring div { box-sizing:border-box; display:block; position:absolute; width:51px; height:51px; margin:6px; border:6px solid #ffb862; border-radius:50%; animation: lds-ring 1.2s cubic-bezier(0.5, 0, 0.5, 1) infinite; border-color:#ffb862 transparent transparent transparent; }
    .lds-ring div:nth-child(1) { animation-delay:-0.45s; }
    .lds-ring div:nth-child(2) { animation-delay:-0.3s; }
    .lds-ring div:nth-child(3) { animation-delay:-0.15s; }
    @keyframes lds-ring { 0% { transform: rotate(0deg);} 100% { transform: rotate(360deg);} }
    /* Confirmation dialog */
    .ptm-dialog { background:#1a1a1a; color:#e0e0e0; border:1px solid #555; border-radius:10px; padding:20px; max-width:520px; width:calc(100% - 40px); box-shadow:0 12px 36px rgba(0,0,0,0.5); }
    .ptm-dialog::backdrop { background: rgba(0,0,0,0.65); }
    .ptm-dialog h3 { font-family:'MagicCards', serif; color:rgb(242,124,17); word-spacing:6px; margin:0 0 10px 0; font-size:1.3em; }
    .ptm-dialog p { margin: 0 0 8px 0; font-size: 14px; line-height: 1.5; }
    .ptm-dialog ol { margin: 4px 0 8px 0; padding-left: 20px; font-size: 14px; }
    .ptm-dialog .ptm-dialog-target { color:#ffb862; }
    .ptm-dialog-buttons { display:flex; gap:10px; justify-content:flex-end; margin-top:16px; flex-wrap:wrap; }
    .ptm-dialog-buttons .button { padding: 6px 14px; }
    .btn-confirm-danger { background-color: rgba(166, 53, 63, 0.9); color:#fff; }
    .btn-confirm-primary { background-color: rgb(1 53 166 / 90%); color:#fff; }
    /* Storage overview */
    .storage-total { font-size: 14px; color:#e0e0e0; margin-bottom: 8px; }
    .storage-bar { display:flex; height: 22px; border-radius: 6px; overflow:hidden; border:1px solid #444; background:#111; }
    .storage-bar .seg { display:block; min-width: 2px; }
    .seg-playthrough { background: #2ea8ff; }
    .seg-snapshots { background: repeating-linear-gradient(45deg, #f27c11, #f27c11 6px, #a85408 6px, #a85408 12px); }
    .seg-diagnostics { background: repeating-linear-gradient(-45deg, #9fb1c9, #9fb1c9 6px, #64748c 6px, #64748c 12px); }
    .seg-other { background: repeating-linear-gradient(90deg, #555, #555 4px, #2e2e2e 4px, #2e2e2e 8px); }
    .storage-legend { list-style: none; margin: 10px 0 0 0; padding: 0; display:flex; gap: 6px 18px; flex-wrap: wrap; font-size: 13px; color:#ccc; }
    .storage-legend .swatch { display:inline-block; width: 14px; height: 14px; border-radius: 3px; border:1px solid #444; vertical-align: -2px; margin-right: 4px; }
    .help-text { font-size: 0.9em; color: #9fb1c9; }
    /* Data retention panel */
    .retention-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 14px; margin: 12px 0; }
    .retention-fieldset { border: 1px solid #3a3a3a; border-radius: 8px; padding: 10px 12px; margin: 0; background: rgba(0,0,0,0.25); min-width: 0; }
    .retention-fieldset legend { color: #ffb862; font-size: 13px; font-weight: bold; padding: 0 6px; }
    .retention-row { display: flex; align-items: center; gap: 8px; margin: 6px 0; flex-wrap: wrap; font-size: 13px; color: #e0e0e0; }
    .retention-row label { margin: 0; width: auto; display: inline; cursor: pointer; }
    .retention-row input[type="number"] { width: 90px; min-width: 0; background: #111; color: #e0e0e0; border: 1px solid #555; border-radius: 4px; padding: 4px 6px; }
    .retention-row input[type="checkbox"] { width: 16px; height: 16px; accent-color: #f27c11; flex: 0 0 auto; }
    .retention-note { font-size: 12px; color: #9fb1c9; margin: 6px 0 0 0; line-height: 1.5; }
    .retention-blocked { font-size: 12px; color: #fbbf24; background: rgba(74,30,13,0.5); border: 1px solid rgba(220,38,38,0.5); border-radius: 6px; padding: 6px 8px; margin-top: 8px; line-height: 1.5; }
    .retention-status { font-size: 13px; margin: 10px 0 0 0; padding: 6px 10px; border-radius: 6px; border: 1px solid transparent; }
    .retention-status:empty { display: none; }
    .retention-status.is-busy { color: #9fb1c9; border-color: #444; background: rgba(0,0,0,0.3); }
    .retention-status.is-error { color: #fca5a5; border-color: rgba(220,38,38,0.6); background: rgba(74,30,13,0.4); }
    .retention-status.is-success { color: #4ade80; border-color: rgba(74,222,128,0.4); background: rgba(20,60,35,0.35); }
    .retention-actions { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 14px; }
    .retention-actions .button { padding: 6px 14px; min-width: 0; }
    .retention-actions .button[disabled] { opacity: 0.55; cursor: not-allowed; transform: none; }
    .retention-preview-table { width: 100%; border-collapse: collapse; font-size: 12px; margin: 8px 0; }
    .retention-preview-table th, .retention-preview-table td { border: 1px solid #333; padding: 4px 8px; text-align: left; color: #e0e0e0; }
    .retention-preview-table th { background: #222; color: #ffb862; }
    .retention-preview-table td.num, .retention-preview-table th.num { text-align: right; }
    .retention-preview-box { border: 1px solid #3a3a3a; border-radius: 8px; background: rgba(0,0,0,0.25); padding: 10px 12px; margin-top: 12px; }
    .retention-preview-box h3 { font-size: 14px; color: #ffb862; margin: 0 0 6px 0; }
    .retention-snap-list { list-style: none; margin: 8px 0 0 0; padding: 0; border: 1px solid #333; border-radius: 8px; background: #1a1a1a; }
    .retention-snap-list li { display: flex; justify-content: space-between; align-items: center; gap: 10px; padding: 8px 10px; border-bottom: 1px solid #2c2c2c; flex-wrap: wrap; font-size: 13px; color: #e0e0e0; }
    .retention-snap-list li:last-child { border-bottom: none; }
    .retention-badge { display: inline-block; font-size: 11px; padding: 1px 7px; border-radius: 4px; margin-left: 6px; border: 1px solid #444; color: #ccc; white-space: nowrap; }
    .retention-badge.b-active { background: #14532d; color: #bbf7d0; border-color: #166534; }
    .retention-badge.b-default { background: #1e3a5f; color: #bfdbfe; border-color: #1d4ed8; }
    .retention-badge.b-auto { background: #3b2f14; color: #fde68a; border-color: #a16207; }
    .retention-badge.b-pinned { background: #312e81; color: #c7d2fe; border-color: #4338ca; }
    .btn-pin { padding: 4px 10px; font-size: 12px; min-width: 0; }
    #retention-section details { margin-top: 12px; font-size: 13px; color: #ccc; }
    #retention-section details ul { margin: 8px 0 4px 0; padding-left: 20px; line-height: 1.6; }
    #retention-section summary { cursor: pointer; color: #ffb862; }
    #retention-section summary:focus-visible { outline: 2px solid #ffb862; outline-offset: 2px; }
    @media (max-width: 700px) { .retention-actions .button { width: 100%; text-align: center; } }
    /* Accessibility helpers */
    .visually-hidden { position:absolute; width:1px; height:1px; margin:-1px; padding:0; border:0; clip:rect(0 0 0 0); clip-path: inset(50%); overflow:hidden; white-space:nowrap; }
    main button:focus-visible, main a:focus-visible, main input:focus-visible, .ptm-dialog button:focus-visible { outline: 2px solid #ffb862; outline-offset: 2px; }
    @media (prefers-reduced-motion: reduce) {
        * { animation-duration: 0.01ms !important; animation-iteration-count: 1 !important; transition-duration: 0.01ms !important; }
    }
</style>

<?php if ($isEmbed): ?>
<style>
    main { padding-top: 20px; }
    /* Avoid nested fixed-height scroll areas inside the embed iframe */
    .backup-list { max-height: none; overflow-y: visible; }
</style>
<?php endif; ?>

<main>
    <div id="toast" class="toast-notification"><span class="message"></span></div>
    <div id="switch-overlay" role="status" aria-live="polite">
        <div class="loading-modal">
            <div class="loading-title" id="loading-title">Restoring snapshot…</div>
            <div class="lds-ring"><div></div><div></div><div></div><div></div></div>
            <div class="loading-sub">This can take a few minutes. Please keep this tab open.</div>
        </div>
    </div>

    <div class="page-header">
        <?php if ($ptmFragment): ?><h2>Snapshots and cleanup</h2><?php else: ?><h1>Playthrough Manager</h1><?php endif; ?>
        <div style="font-size: 0.95em; color: #ccc; margin-bottom: 10px;">Save or restore server snapshots. Restoring first saves your current progress over the loaded snapshot.</div>

        <details class="storage-help"><summary>How snapshots work</summary>
        <div style="background: rgba(0,0,0,0.3); padding: 15px; border-radius: 8px; border: 1px solid #444; margin-top: 15px; text-align: left;">
            <div style="font-size: 0.9em; color: #e0e0e0; line-height: 1.6;">
                • <strong>Active playthrough</strong> = the live data CHIM is reading and writing right now.<br>
                • <strong>Saved snapshots</strong> = stored copies of a playthrough. They are not in use.<br>
                • <strong>Restore</strong> = saves your current progress over the loaded snapshot first, then loads the selected snapshot as the active playthrough. If the currently loaded snapshot cannot be determined, the restore is blocked so nothing is overwritten.<br>
                • <strong>Dragon Breaks</strong> = automatic snapshots taken when you load a save 3+ days behind.<br>
                <span class="help-text">Technical note: the active playthrough lives in the PostgreSQL <code>public</code> schema; snapshots are cloned schemas in the same database.</span>
            </div>
        </div>
        </details>
    </div>

    <?php if (!empty($message)) { echo '<div class="content-section" style="margin-bottom: 30px;">'.$message.'</div>'; } ?>

    <?php if ($adminConn && $ptmNeedsSetup) { ?>
    <div class="content-section full-width-section" style="margin-bottom: 30px;">
        <h2>🎮 Set up playthrough management</h2>
        <p style="margin: 0 0 10px 0;">
            Playthrough management is not set up yet<?php echo $ptmInitialized ? ' (no snapshots exist)' : ''; ?>.
            Nothing has been changed by opening this page.
        </p>
        <p class="help-text" style="margin: 0 0 10px 0;">
            Setting up creates the snapshot bookkeeping tables and saves your current data as the
            <strong>default</strong> snapshot, which becomes the active playthrough. Your live data is not modified.
        </p>
        <form method="post" class="setup-form">
            <?php echo $csrfField; ?>
            <input type="hidden" name="action" value="setup">
            <div class="button-group">
                <button type="submit" class="button" style="background-color: rgb(1 53 166 / 90%); color: #fff;">🚀 Set up playthrough management</button>
            </div>
        </form>
    </div>
    <?php } elseif ($adminConn) { ?>

    <div class="content-section full-width-section" style="background: linear-gradient(135deg, #1a3a2a 0%, #2a2a2a 100%); border: 2px solid #4ade80; margin-bottom: 30px;">
        <div style="display: flex; align-items: center; gap: 15px; margin-bottom: 15px; flex-wrap: wrap;">
            <div style="font-size: 2.5em;">🎮</div>
            <div style="flex: 1; min-width: 200px;">
                <h2 style="margin: 0;">Active playthrough</h2>
                <div class="help-text" style="margin-top: 4px;">
                    The live data CHIM is using right now.
                </div>
            </div>
        </div>

        <div style="background: rgba(0,0,0,0.3); padding: 15px; border-radius: 6px; border-left: 4px solid #4ade80;">
            <div style="display:flex; gap:12px; flex-wrap:wrap; font-size: 14px; color:#ccc; align-items: center;">
                <div style="font-size: 1.1em; color: #4ade80; font-weight: bold;">
                    📋 Loaded snapshot: <?php echo h($activeProfileName !== '' ? $activeProfileName : '(unknown)'); ?>
                </div>
                <div style="border-left: 2px solid #444; padding-left: 12px; margin-left: 6px;">
                    <strong style="color:#f8f9fa;">Player:</strong> <span id="live-player"><?php echo h($livePlayerName); ?></span>
                </div>
                <div><strong style="color:#f8f9fa;">Game:</strong> <span id="live-game"><?php echo h($liveGameName); ?></span></div>
                <div><strong style="color:#f8f9fa;" id="live-eventlog-label">Events (at last snapshot save):</strong> <span id="live-eventlog" title="Refreshed with an approximate live estimate when database statistics are available"><?php echo intval($liveEventlogCount); ?></span></div>
                <div><strong style="color:#f8f9fa;" id="live-oghma-label">Knowledge entries (at last snapshot save):</strong> <span id="live-oghma" title="Refreshed with an approximate live estimate when database statistics are available"><?php echo intval($liveOghmaCount); ?></span></div>
                <div><strong style="color:#f8f9fa;">Last in-game date:</strong> <span id="live-last"><?php echo h($liveSkyrimDate !== '' ? $liveSkyrimDate : 'n/a'); ?></span></div>
            </div>
        </div>
        <?php if (!empty($timelineItems)) { ?>
        <div class="timeline" id="pt-timeline">
            <div class="timeline-title" id="pt-title"></div>
            <div class="timeline-track"></div>
            <div class="timeline-notches" id="pt-timeline-notches"></div>
            <div class="timeline-nodes" id="pt-timeline-nodes"></div>
            <div class="timeline-legend"><span id="pt-min"></span><span id="pt-max"></span></div>
            <div class="timeline-tooltip" id="pt-tooltip"></div>
        </div>
        <?php } ?>
    </div>

    <div class="content-grid">
        <div class="content-section">
            <h2>📦 Save snapshot</h2>
            <div class="help-text" style="margin-bottom: 12px;">
                Saves a copy of the active playthrough as a new snapshot. The active playthrough keeps running unchanged.
            </div>
                <form method="post" class="create-form">
                    <?php echo $csrfField; ?>
                    <input type="hidden" name="action" value="create">
                    <label for="name">Snapshot name</label><br>
                    <input type="text" id="name" name="name" required style="width: 100%; margin: 6px 0;" placeholder="e.g., Before Quest X">
                    <label for="notes">Notes (optional)</label><br>
                    <input type="text" id="notes" name="notes" style="width: 100%; margin: 6px 0;" placeholder="e.g., Level 25, just finished main quest">
                    <div class="button-group">
                        <button type="submit" class="button" style="background-color: rgb(1 53 166 / 90%); color: #fff;">💾 Save Snapshot</button>
                    </div>
                </form>
        </div>

        <div class="content-section">
            <h2>💾 Saved snapshots</h2>
            <div class="help-text" style="margin-bottom: 12px;">
                Stored copies of playthroughs — they are not in use.
                <strong>Restore</strong> loads one as the active playthrough; your current progress is saved over the loaded snapshot first.
            </div>
                <?php if (empty($profiles)) { ?>
                    <div style="text-align:center; color:#ccc; padding: 12px;">No snapshots yet. Create one from the left panel.</div>
                <?php } else { ?>
                    <div class="backup-list">
                        <?php foreach ($profiles as $p) {
                            $nm = strtolower((string)($p['name'] ?? ''));
                            $nt = strtolower((string)($p['notes'] ?? ''));
                            $isDragon = (strpos($nm,'dragon') !== false) || (strpos($nt,'dragon') !== false);
                            $isActive = ptm_is_active($p['is_active'] ?? null);
                            $isDefault = ($nm === 'default');
                            $sizeText = formatFileSize((int)$p['size_bytes']);
                        ?>
                        <div class="backup-item<?php echo $isDragon ? ' dragonbreak' : ''; ?><?php echo $isActive ? ' active-snapshot' : ''; ?>">
                            <div class="backup-row">
                                <div class="backup-info">
                                    <div style="font-weight:bold; font-size: 14px; word-break: break-all;">
                                        <?php if ($isActive) { ?>
                                            <span class="badge-loaded">✓ LOADED</span>
                                        <?php } ?>
                                        <?php echo h($p['name']); ?>
                                    </div>
                                    <div style="font-size: 12px; color:#ccc; display:flex; gap:10px; flex-wrap:wrap;">
                                        <span><?php echo h($p['created_at']); ?></span>
                                        <span>• <?php echo h($sizeText); ?></span>
                                        <span>• Player: <?php echo h($p['player_name'] ?? ''); ?></span>
                                        <span>• Game: <?php echo h($p['game'] ?? 'Skyrim'); ?></span>
                                        <span>• Events: <?php echo intval($p['eventlog_count'] ?? 0); ?></span>
                                        <span>• Knowledge entries: <?php echo intval($p['oghma_count'] ?? 0); ?></span>
                                        <?php
                                            $lg = isset($p['last_gamets']) ? intval($p['last_gamets']) : 0;
                                            $skDate = $lg > 0 ? convert_gamets2skyrim_long_date($lg) : '';
                                        ?>
                                        <span>• last in-game: <?php echo h($skDate); ?></span>
                                    </div>
                                    <?php if (!empty($p['notes'])) { ?>
                                        <div style="font-size: 12px; color:#9fb1c9; margin-top: 4px; word-break: break-all;"><?php echo h($p['notes']); ?></div>
                                    <?php } ?>
                                </div>
                                <div class="backup-actions">
                                    <?php if (!$isActive) { ?>
                                    <form method="post" class="switch-form"
                                          data-confirm="restore"
                                          data-name="<?php echo h($p['name']); ?>"
                                          data-size="<?php echo h($sizeText); ?>"
                                          data-active="<?php echo h($activeProfileName); ?>">
                                        <?php echo $csrfField; ?>
                                        <input type="hidden" name="action" value="switch">
                                        <input type="hidden" name="profile_id" value="<?php echo (int)$p['id']; ?>">
                                        <button type="submit" class="button" style="background-color: rgb(1 53 166 / 90%); color:#fff; padding:6px 10px;">Restore</button>
                                    </form>
                                    <?php } else { ?>
                                    <button class="button" style="background-color: #333; color:#999; padding:6px 10px; cursor: not-allowed;" disabled aria-disabled="true" title="This snapshot is the active playthrough">Loaded</button>
                                    <?php } ?>
                                    <?php if (!$isActive && !$isDefault) { ?>
                                    <form method="post"
                                          data-confirm="delete"
                                          data-name="<?php echo h($p['name']); ?>"
                                          data-size="<?php echo h($sizeText); ?>">
                                        <?php echo $csrfField; ?>
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="profile_id" value="<?php echo (int)$p['id']; ?>">
                                        <button type="submit" class="button" style="background-color: rgba(166, 53, 63, 0.9); color:#fff; padding:6px 10px;">Delete</button>
                                    </form>
                                    <?php } ?>
                                </div>
                            </div>
                        </div>
                        <?php } ?>
                    </div>
                <?php } ?>
        </div>
    </div>
    <?php } ?>

    <div class="content-section full-width-section">
        <h2>💽 Storage overview</h2>
        <div class="help-text" style="margin-bottom: 12px;">
            Read-only summary of database disk usage. Sizes come from database metadata; no table contents are read.
        </div>
        <div id="storage-overview">Loading storage overview…</div>
        <div class="help-text" style="margin-top: 12px;">
            For backups, exports, and maintenance, use the
            <a href="<?php echo htmlspecialchars($ptmDatabaseToolsUrl, ENT_QUOTES, 'UTF-8'); ?>"<?php echo ($isEmbed && !$ptmFragment) ? ' target="_top"' : ''; ?> style="color:#ffb862;"><?php echo htmlspecialchars($ptmDatabaseToolsLabel, ENT_QUOTES, 'UTF-8'); ?></a>.
            Optional cleanup of old debug logs and old automatic snapshots is set up in the <a href="#retention-section" style="color:#ffb862;">Storage cleanup</a> panel below.
        </div>
    </div>

    <div class="content-section full-width-section" id="retention-section" style="margin-top: 30px;">
        <h2>🧹 Storage cleanup</h2>
        <div class="help-text" style="margin-bottom: 10px;">
            Choose which old data CHIM can delete. Cleanup is <strong>off by default</strong>.
            These settings apply to every playthrough on this server.
        </div>
        <div class="retention-note" style="margin-bottom: 4px;">
            Your current playthrough's memories, relationships, diaries, quests and files stay intact.
            Snapshots are saved copies of CHIM data — not Skyrim saves. Deleting one removes that whole copy.
        </div>

        <div id="retention-status" role="status" aria-live="polite" class="retention-status"></div>

        <form id="retention-form" novalidate data-api="<?php echo htmlspecialchars($webRoot . '/ui/api/playthrough_retention.php', ENT_QUOTES); ?>" data-csrf="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES); ?>">
            <div class="retention-grid">
                <fieldset class="retention-fieldset">
                    <legend>Automatic cleanup</legend>
                    <div class="retention-row">
                        <input type="checkbox" id="ret-automatic" name="automatic" disabled>
                        <label for="ret-automatic">Clean up automatically</label>
                    </div>
                    <p class="retention-note" id="ret-automatic-help">
                        After you save, CHIM can run the enabled cleanups once an hour while its Background Processor is running. Off by default.
                    </p>
                    <p class="retention-note">Last run: <span id="ret-lastrun">—</span></p>
                </fieldset>

                <fieldset class="retention-fieldset">
                    <legend>Debug logs</legend>
                    <div class="retention-row">
                        <input type="checkbox" id="ret-diag-enabled" name="diagnostics_enabled" disabled>
                        <label for="ret-diag-enabled">Delete old debug logs</label>
                    </div>
                    <div class="retention-row">
                        <label for="ret-diag-days">Delete entries older than</label>
                        <input type="number" id="ret-diag-days" name="diagnostic_days" inputmode="numeric" min="1" max="3650" step="1" value="7" disabled aria-describedby="ret-diag-help">
                        <span>real-world days (1–3650)</span>
                    </div>
                    <div class="retention-row">
                        <label for="ret-diag-maxmb">Try to stay under</label>
                        <input type="number" id="ret-diag-maxmb" name="diagnostic_max_mb" inputmode="numeric" min="0" max="102400" step="1" value="500" disabled aria-describedby="ret-diag-help">
                        <span>MB per log table (0 = ignore size)</span>
                    </div>
                    <p class="retention-note" id="ret-diag-help">
                        Deletes old entries, or entries from logs above the size target. Always keeps the last 24 hours and unsent replies.
                        The size target applies separately to each of the three log tables. It is a goal, not a hard limit.
                    </p>
                </fieldset>

                <fieldset class="retention-fieldset">
                    <legend>Automatic snapshots (Dragon Breaks)</legend>
                    <div class="retention-row">
                        <input type="checkbox" id="ret-snap-enabled" name="snapshots_enabled" disabled>
                        <label for="ret-snap-enabled">Delete old automatic snapshots</label>
                    </div>
                    <div class="retention-row">
                        <label for="ret-snap-keep">Keep the newest</label>
                        <input type="number" id="ret-snap-keep" name="snapshot_keep" inputmode="numeric" min="1" max="100" step="1" value="5" disabled aria-describedby="ret-snap-help">
                        <span>automatic snapshots (1–100)</span>
                    </div>
                    <p class="retention-note" id="ret-snap-help">
                        Keeps the loaded and default snapshots, protected copies, snapshots you saved yourself, and copies made before this cleanup feature.
                    </p>
                </fieldset>

                <fieldset class="retention-fieldset">
                    <legend>Event log (preview only)</legend>
                    <div class="retention-row">
                        <label for="ret-event-days">Preview events older than</label>
                        <input type="number" id="ret-event-days" name="event_days" inputmode="numeric" min="0" max="3650" step="1" value="0" disabled aria-describedby="ret-event-blocked ret-event-help">
                        <span>in-game days (0 = no preview)</span>
                    </div>
                    <div class="retention-blocked" id="ret-event-blocked">
                        <strong>Preview only — no events are deleted.</strong> CHIM may still need them to build NPC memories.
                    </div>
                    <p class="retention-note" id="ret-event-help">Counted in in-game days back from the latest game time CHIM has recorded.</p>
                </fieldset>
            </div>

            <div class="retention-actions">
                <button type="submit" class="button" id="ret-save" style="background-color: rgb(1 53 166 / 90%); color:#fff;" disabled>💾 Save settings</button>
                <button type="button" class="button" id="ret-preview" style="background-color:#333; color:#e0e0e0;" disabled>🔍 Preview cleanup (saved settings)</button>
                <button type="button" class="button" id="ret-run" style="background-color: rgba(166, 53, 63, 0.9); color:#fff;" disabled aria-disabled="true">🗑️ Run cleanup now</button>
            </div>
            <p class="retention-note" id="ret-run-hint">
                Saving settings never deletes anything. Preview always uses the settings you last <em>saved</em>, so save first if you just changed something.
                "Run cleanup now" unlocks only after a preview and still asks you to confirm. A preview lasts 5 minutes, and changing any setting or snapshot protection cancels it.
            </p>
        </form>

        <div id="ret-preview-out" style="margin-top: 4px;"></div>

        <div style="margin-top: 16px;">
            <strong style="color:#ffb862; font-size: 14px;">Snapshot protection</strong>
            <p class="retention-note">
                Protected snapshots cannot be deleted, even manually, until you unprotect them.
            </p>
            <ul class="retention-snap-list" id="ret-snap-list">
                <li><span>Loading snapshot list…</span></li>
            </ul>
        </div>

        <details>
            <summary>How storage cleanup works (details)</summary>
            <ul>
                <li>These settings are stored on the server and apply to whichever playthrough is loaded. <strong>Saving them never deletes anything by itself</strong> — turning on automatic cleanup is what lets CHIM delete in the background.</li>
                <li>Debug log cleanup covers three tables: log, audit_request, and already-sent rows in responselog. Each table is checked on its own, by real-world age or by estimated size. Rows from the last 24 hours and replies CHIM has not sent yet are always kept.</li>
                <li>Cleanup runs only when you press "Run cleanup now" after a preview and confirm, or at most once an hour when automatic cleanup is on and CHIM's Background Processor is running.</li>
                <li>A preview always uses the settings you last <em>saved</em>. It shows the exact number of log entries and the snapshot names for the next cleanup, and it expires after 5 minutes.</li>
                <li>Each round deletes at most 1,000 debug log rows per table and 3 automatic snapshots. Sizes shown are estimates of the data itself: that space becomes reusable inside the database, but the files on disk may not shrink.</li>
                <li id="ret-event-status">The event-days box is preview only. This cleanup never deletes events.</li>
                <li>Your current playthrough's NPC memories, relationships, diaries and quests are never trimmed. Deleting a snapshot deletes the whole saved copy, including the records inside it. Audio and temporary files are left alone.</li>
                <li>Only snapshots that were tagged as automatic Dragon Breaks when they were created can be deleted here. Snapshots made before this feature existed count as manual and are never removed automatically.</li>
            </ul>
        </details>
    </div>

    <dialog id="ret-confirm-dialog" class="ptm-dialog" aria-labelledby="ret-confirm-title" aria-describedby="ret-confirm-body">
        <h3 id="ret-confirm-title">Run cleanup now?</h3>
        <p id="ret-confirm-body" style="white-space:pre-line;"></p>
        <form method="dialog" class="ptm-dialog-buttons">
            <button class="button" value="cancel" autofocus>Cancel</button>
            <button class="button" value="run" style="background:#a6353f;color:#fff;">Delete this batch</button>
        </form>
    </dialog>
    <dialog id="ptm-dialog" class="ptm-dialog" role="alertdialog" aria-labelledby="ptm-dialog-title" aria-describedby="ptm-dialog-body">
        <h3 id="ptm-dialog-title"></h3>
        <div id="ptm-dialog-body"></div>
        <div class="ptm-dialog-buttons">
            <button type="button" class="button" id="ptm-dialog-cancel" style="background-color:#333; color:#e0e0e0;">Cancel</button>
            <button type="button" class="button" id="ptm-dialog-confirm"></button>
        </div>
    </dialog>
    <div id="ptm-dialog-status" role="status" aria-live="polite" class="visually-hidden"></div>
</main>

<?php
if (!$ptmFragment) {
    $buffer = ob_get_contents();
    ob_end_clean();
    $title = $TITLE;
    $buffer = preg_replace('/(<title>)(.*?)(<\/title>)/i', '$1' . $title . '$3', $buffer);
    echo $buffer;
}
?>

<script>
(function(){
    const overlay = document.getElementById('switch-overlay');
    const overlayTitle = document.getElementById('loading-title');
    function showOverlay(){ if (overlay) overlay.style.display='flex'; }

    // ----- Accessible confirmation dialog for Restore / Delete -----
    const dlg = document.getElementById('ptm-dialog');
    const dlgTitle = document.getElementById('ptm-dialog-title');
    const dlgBody = document.getElementById('ptm-dialog-body');
    const dlgCancel = document.getElementById('ptm-dialog-cancel');
    const dlgConfirm = document.getElementById('ptm-dialog-confirm');
    const dlgStatus = document.getElementById('ptm-dialog-status');
    let pendingForm = null;
    let lastFocus = null;

    function el(tag, text){
        const e = document.createElement(tag);
        if (text) e.textContent = text;
        return e;
    }

    function buildRestoreBody(name, size, activeName){
        const frag = document.createDocumentFragment();
        const p1 = el('p');
        p1.appendChild(el('strong', 'Snapshot: '));
        const nm = el('span', name + (size ? ' (' + size + ')' : ''));
        nm.className = 'ptm-dialog-target';
        p1.appendChild(nm);
        frag.appendChild(p1);
        frag.appendChild(el('p', activeName
            ? 'Before restoring, your current live progress is saved over the currently loaded snapshot "' + activeName + '" — its stored copy is replaced with the current live state.'
            : 'Before restoring, the server saves your current live progress over the currently loaded snapshot. If the loaded snapshot cannot be determined, the restore is blocked and nothing is changed.'));
        frag.appendChild(el('p', 'Then "' + name + '" replaces the active playthrough.'));
        frag.appendChild(el('p', 'After the restore completes you must:'));
        const ol = el('ol');
        ol.appendChild(el('li', 'Shut down Skyrim'));
        ol.appendChild(el('li', 'Restart the CHIM server'));
        ol.appendChild(el('li', 'Restart Skyrim and load the save you want to continue from'));
        frag.appendChild(ol);
        return frag;
    }

    function buildDeleteBody(name, size){
        const frag = document.createDocumentFragment();
        const p1 = el('p');
        p1.appendChild(el('strong', 'Snapshot: '));
        const nm = el('span', name + (size ? ' (' + size + ')' : ''));
        nm.className = 'ptm-dialog-target';
        p1.appendChild(nm);
        frag.appendChild(p1);
        frag.appendChild(el('p', 'This permanently deletes the saved snapshot "' + name + '"' + (size ? ' and frees about ' + size + ' of storage' : '') + '. The active playthrough is not affected.'));
        frag.appendChild(el('p', 'This cannot be undone.'));
        return frag;
    }

    function plainTextConfirm(kind, name, size, activeName){
        if (kind === 'restore') {
            return 'Restore snapshot "' + name + '"' + (size ? ' (' + size + ')' : '') + '?\n\n' +
                '1. Your current progress is saved over the loaded snapshot' + (activeName ? ' "' + activeName + '"' : ' (if it cannot be determined, the restore is blocked)') + '.\n' +
                '2. "' + name + '" then replaces the active playthrough.\n' +
                '3. Afterwards: shut down Skyrim, restart the CHIM server, then restart Skyrim.\n\nContinue?';
        }
        return 'Permanently delete snapshot "' + name + '"' + (size ? ' (' + size + ')' : '') + '?\n\nThis cannot be undone.';
    }

    function proceed(){
        const f = pendingForm;
        pendingForm = null;
        if (dlg && dlg.open) dlg.close('confirm');
        if (!f) return;
        f.dataset.confirmed = '1';
        if (typeof f.requestSubmit === 'function') {
            f.requestSubmit();
        } else {
            // Fallback path bypasses the submit event, so show the overlay here.
            if (f.classList.contains('switch-form')) { if (overlayTitle) overlayTitle.textContent = 'Restoring snapshot…'; showOverlay(); }
            if (f.getAttribute('data-confirm') === 'delete') { if (overlayTitle) overlayTitle.textContent = 'Deleting snapshot…'; showOverlay(); }
            f.submit();
        }
    }

    function openConfirm(form, kind){
        const name = form.getAttribute('data-name') || '';
        const size = form.getAttribute('data-size') || '';
        const activeName = form.getAttribute('data-active') || '';
        if (!dlg || typeof dlg.showModal !== 'function') {
            // <dialog> unsupported: accessible native confirm fallback
            if (window.confirm(plainTextConfirm(kind, name, size, activeName))) {
                pendingForm = form;
                proceed();
            }
            return;
        }
        pendingForm = form;
        lastFocus = document.activeElement;
        dlgBody.textContent = '';
        if (kind === 'restore') {
            dlgTitle.textContent = 'Restore snapshot?';
            dlgBody.appendChild(buildRestoreBody(name, size, activeName));
            dlgConfirm.textContent = 'Restore "' + name + '"';
            dlgConfirm.className = 'button btn-confirm-primary';
        } else {
            dlgTitle.textContent = 'Delete snapshot?';
            dlgBody.appendChild(buildDeleteBody(name, size));
            dlgConfirm.textContent = 'Delete "' + name + '"';
            dlgConfirm.className = 'button btn-confirm-danger';
        }
        dlg.returnValue = '';
        dlg.showModal();
        dlgCancel.focus();
        if (dlgStatus) dlgStatus.textContent = (kind === 'restore' ? 'Restore' : 'Delete') + ' confirmation opened for snapshot ' + name;
    }

    if (dlg) {
        dlgConfirm.addEventListener('click', proceed);
        dlgCancel.addEventListener('click', function(){ dlg.close('cancel'); });
        // Escape triggers the native cancel → close; restore focus and state either way.
        dlg.addEventListener('close', function(){
            if (dlg.returnValue !== 'confirm') {
                if (pendingForm) { delete pendingForm.dataset.confirmed; pendingForm = null; }
                if (dlgStatus) dlgStatus.textContent = 'Cancelled. No changes were made.';
            }
            if (lastFocus && document.contains(lastFocus) && typeof lastFocus.focus === 'function') lastFocus.focus();
            lastFocus = null;
        });
    }

    document.addEventListener('submit', function(e){
        const form = e.target;
        if (!form || !form.classList) return;
        const kind = form.getAttribute && form.getAttribute('data-confirm');
        if (kind && form.dataset.confirmed !== '1') {
            e.preventDefault();
            openConfirm(form, kind);
            return false;
        }
        if (form.classList.contains('switch-form')) {
            if (overlayTitle) overlayTitle.textContent = 'Restoring snapshot…';
            showOverlay();
        }
        if (form.classList.contains('create-form')) {
            if (overlayTitle) overlayTitle.textContent = 'Saving snapshot…';
            showOverlay();
        }
        if (form.classList.contains('setup-form')) {
            if (overlayTitle) overlayTitle.textContent = 'Setting up…';
            showOverlay();
        }
        if (form.getAttribute && form.getAttribute('data-confirm') === 'delete') {
            if (overlayTitle) overlayTitle.textContent = 'Deleting snapshot…';
            showOverlay();
        }
    }, true);

    // Reset transient UI when the page is restored from the back/forward cache,
    // otherwise a stale overlay or confirmed flag would stick after navigating back.
    window.addEventListener('pageshow', function(){
        if (overlay) overlay.style.display = 'none';
        document.querySelectorAll('form[data-confirm]').forEach(function(f){ delete f.dataset.confirmed; });
        if (dlg && dlg.open) dlg.close('cancel');
    });

    // ----- Timeline -----
    (function(){
        const items = <?php echo json_encode($timelineItems, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
        const ticks = <?php echo json_encode($timelineTicks, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
        if (!items || !items.length) return;
        const nodesEl = document.getElementById('pt-timeline-nodes');
        const notchesEl = document.getElementById('pt-timeline-notches');
        const trackEl = document.querySelector('#pt-timeline .timeline-track');
        const tooltip = document.getElementById('pt-tooltip');
        const minEl = document.getElementById('pt-min');
        const maxEl = document.getElementById('pt-max');
        if (!nodesEl || !trackEl) return;

        const values = items.map(i => i.last_gamets);
        const min = Math.min.apply(null, values);
        const max = Math.max.apply(null, values);
        const minItem = items.find(i => i.last_gamets === min);
        const maxItem = items.find(i => i.last_gamets === max);
        const minLabel = minItem ? minItem.skyrim_date : String(min);
        const maxLabel = maxItem ? maxItem.skyrim_date : String(max);
        minEl && (minEl.textContent = 'Earliest: ' + minLabel);
        maxEl && (maxEl.textContent = 'Latest: ' + maxLabel);

        function pct(x){
            if (max === min) return 50; // collapse to center if identical
            return ((x - min) / (max - min)) * 100;
        }

        function showTip(e, html){
            if (!tooltip) return;
            tooltip.innerHTML = html;
            tooltip.style.display = 'block';
            const rect = nodesEl.getBoundingClientRect();
            const x = e.clientX - rect.left + 10;
            const y = e.clientY - rect.top + 14;
            tooltip.style.left = x + 'px';
            tooltip.style.top = y + 'px';
        }
        function showTipForNode(node, html){
            if (!tooltip) return;
            tooltip.innerHTML = html;
            tooltip.style.display = 'block';
            const nodesRect = nodesEl.getBoundingClientRect();
            const nodeRect = node.getBoundingClientRect();
            tooltip.style.left = (nodeRect.left - nodesRect.left + nodeRect.width + 6) + 'px';
            tooltip.style.top = (nodeRect.top - nodesRect.top + nodeRect.height + 6) + 'px';
        }
        function hideTip(){ if (tooltip) tooltip.style.display = 'none'; }

        items.sort((a,b) => a.last_gamets - b.last_gamets);
        items.forEach(it => {
            const node = document.createElement('button');
            node.type = 'button';
            node.className = 'timeline-node' + (it.is_active ? ' active' : '');
            node.style.left = pct(it.last_gamets) + '%';
            node.setAttribute('aria-label', it.name + (it.is_active ? ' (active playthrough)' : '') + ', ' + it.skyrim_date);
            const tip = `<div class="name">${escapeHtml(it.name)}</div>
                <div>Skyrim date: ${escapeHtml(it.skyrim_date)}</div>
                <div>Created: ${escapeHtml(it.created_at)}</div>
                <div>Size: ${escapeHtml(it.size)}</div>`;
            node.addEventListener('mouseenter', (e)=>showTip(e, tip));
            node.addEventListener('mousemove', (e)=>showTip(e, tip));
            node.addEventListener('mouseleave', hideTip);
            node.addEventListener('focus', ()=>showTipForNode(node, tip));
            node.addEventListener('blur', hideTip);
            nodesEl.appendChild(node);
        });

        // Static ticks (major/minor) with labels aligned to gamets scale
        if (notchesEl && ticks && ticks.length) {
            const isDegenerate = (max === min);
            const tickPct = (x) => isDegenerate ? 50 : ((x - min) / (max - min)) * 100;
            ticks.forEach((t, idx) => {
                const notch = document.createElement('div');
                notch.className = 'timeline-notch' + ((idx % 2 === 0) ? ' major' : '');
                notch.style.left = tickPct(t.gamets) + '%';
                notchesEl.appendChild(notch);
                // Add labels for major interior ticks only (skip first and last)
                if (idx % 2 === 0 && idx > 0 && idx < (ticks.length - 1)) {
                    const lbl = document.createElement('div');
                    lbl.className = 'timeline-tick-label';
                    lbl.style.left = tickPct(t.gamets) + '%';
                    lbl.textContent = t.date;
                    notchesEl.appendChild(lbl);
                }
            });
        }

        document.addEventListener('scroll', hideTip, { passive:true });
        window.addEventListener('resize', hideTip, { passive:true });

        function escapeHtml(s){
            return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;');
        }
    })();

    // ----- Live stats + storage overview (read-only fetch) -----
    function fmtBytes(b){
        b = Number(b);
        if (!isFinite(b) || b < 0) b = 0;
        if (b === 0) return '0 Bytes';
        const k = 1024, sizes = ['Bytes','KB','MB','GB','TB'];
        const i = Math.min(sizes.length - 1, Math.floor(Math.log(b) / Math.log(k)));
        return (Math.round((b / Math.pow(k, i)) * 100) / 100) + ' ' + sizes[i];
    }

    function renderStorage(s){
        const host = document.getElementById('storage-overview');
        if (!host) return;
        if (!s || typeof s.total_bytes !== 'number') {
            host.textContent = 'Storage overview is currently unavailable.';
            return;
        }
        const total = Math.max(0, s.total_bytes);
        const segs = [
            { label: 'Active playthrough and settings', bytes: Math.max(0, s.playthrough_bytes || 0), cls: 'seg-playthrough' },
            { label: 'Saved snapshots' + (s.snapshot_schemas ? ' (' + s.snapshot_schemas + ')' : ''), bytes: Math.max(0, s.snapshots_bytes || 0), cls: 'seg-snapshots' },
            { label: 'Diagnostics logs', bytes: Math.max(0, s.diagnostics_bytes || 0), cls: 'seg-diagnostics' },
            { label: 'Other', bytes: Math.max(0, s.other_bytes || 0), cls: 'seg-other' }
        ];
        host.textContent = '';

        const totalLine = document.createElement('div');
        totalLine.className = 'storage-total';
        totalLine.textContent = 'Total database size: ' + fmtBytes(total) + ' (' + Math.round(total).toLocaleString() + ' bytes)';
        host.appendChild(totalLine);

        const bar = document.createElement('div');
        bar.className = 'storage-bar';
        bar.setAttribute('role', 'img');
        bar.setAttribute('aria-label', 'Storage breakdown: ' + segs.map(g => g.label + ' ' + fmtBytes(g.bytes)).join(', '));
        segs.forEach(g => {
            const seg = document.createElement('span');
            seg.className = 'seg ' + g.cls;
            const p = total > 0 ? (g.bytes / total) * 100 : 0;
            seg.style.width = p + '%';
            seg.title = g.label + ': ' + fmtBytes(g.bytes);
            bar.appendChild(seg);
        });
        host.appendChild(bar);

        const legend = document.createElement('ul');
        legend.className = 'storage-legend';
        segs.forEach(g => {
            const li = document.createElement('li');
            const sw = document.createElement('span');
            sw.className = 'swatch ' + g.cls;
            sw.setAttribute('aria-hidden', 'true');
            li.appendChild(sw);
            const pctTxt = total > 0 ? (Math.round((g.bytes / total) * 1000) / 10) + '%' : '0%';
            li.appendChild(document.createTextNode(g.label + ': ' + fmtBytes(g.bytes) + ' (' + Math.round(g.bytes).toLocaleString() + ' bytes, ' + pctTxt + ')'));
            legend.appendChild(li);
        });
        host.appendChild(legend);
        if (s.snapshot_note) {
            const note = document.createElement('p');
            note.className = 'retention-note';
            note.textContent = s.snapshot_note;
            host.appendChild(note);
        }
    }

    try {
        fetch('<?php echo $webRoot; ?>/ui/playthrough_stats.php', { credentials:'same-origin' })
            .then(r => r.ok ? r.json() : null)
            .then(j => {
                const host = document.getElementById('storage-overview');
                if (!j || !j.ok) {
                    if (host) host.textContent = 'Storage overview is currently unavailable.';
                    return;
                }
                renderStorage(j.storage || null);
                // Counts are planner estimates and can lag or reset; only replace the
                // metadata-based value with a positive estimate, never with zero.
                // The label stays "(at last snapshot save)" unless the live estimate applies.
                const ev = document.getElementById('live-eventlog');
                const evLabel = document.getElementById('live-eventlog-label');
                if (ev && typeof j.eventlog_estimate === 'number' && j.eventlog_estimate > 0) {
                    ev.textContent = '~' + j.eventlog_estimate.toLocaleString();
                    ev.title = 'Approximate (from database statistics)';
                    if (evLabel) evLabel.textContent = 'Events (approx. live):';
                }
                const og = document.getElementById('live-oghma');
                const ogLabel = document.getElementById('live-oghma-label');
                if (og && typeof j.oghma_estimate === 'number' && j.oghma_estimate > 0) {
                    og.textContent = '~' + j.oghma_estimate.toLocaleString();
                    og.title = 'Approximate (from database statistics)';
                    if (ogLabel) ogLabel.textContent = 'Knowledge entries (approx. live):';
                }
                const la = document.getElementById('live-last');
                if (la && typeof j.last_skyrim_date === 'string' && j.last_skyrim_date) {
                    la.textContent = j.last_skyrim_date;
                }
            })
            .catch(() => {
                const host = document.getElementById('storage-overview');
                if (host) host.textContent = 'Storage overview is currently unavailable.';
            });
    } catch (_e) {}
})();
</script>

<script src="<?php echo $webRoot; ?>/ui/js/playthrough_retention.js"></script>
