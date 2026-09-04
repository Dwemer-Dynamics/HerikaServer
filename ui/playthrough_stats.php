<?php

$enginePath = dirname(__DIR__) . DIRECTORY_SEPARATOR;
require_once($enginePath . "lib" . DIRECTORY_SEPARATOR . "utils_game_timestamp.php");

header('Content-Type: application/json');
http_response_code(200);

$host = 'localhost';
$port = '5432';
$dbname = 'dwemer';
$schema = 'public';
$username = 'dwemer';
$password = 'dwemer';

$adminConn = @pg_connect("host={$host} port={$port} dbname={$dbname} user={$username} password={$password}");
if (!$adminConn) {
    // Keep errors generic: no connection details or driver messages.
    echo json_encode([ 'ok' => false ]);
    exit;
}

// Row-count estimates come from planner statistics (n_live_tup). They can lag or
// reset to 0 (e.g. right after a restore), so they are returned as explicit
// nullable estimates flagged approximate — callers must not display null/0 as an
// exact count or use it to overwrite a known value.
$eventlogEstimate = null;
$oghmaEstimate = null;
$q1 = @pg_query_params($adminConn, "SELECT n_live_tup::bigint AS est FROM pg_stat_all_tables WHERE schemaname=$1 AND relname='eventlog'", [$schema]);
if ($q1 && ($r = @pg_fetch_assoc($q1)) && isset($r['est']) && $r['est'] !== null) { $eventlogEstimate = (int)$r['est']; }
$rex = @pg_query_params($adminConn, "SELECT 1 FROM information_schema.tables WHERE table_schema=$1 AND table_name='oghma' LIMIT 1", [$schema]);
$hasO = ($rex && @pg_fetch_assoc($rex)) ? true : false;
if ($hasO) {
    $q2 = @pg_query_params($adminConn, "SELECT n_live_tup::bigint AS est FROM pg_stat_all_tables WHERE schemaname=$1 AND relname='oghma'", [$schema]);
    if ($q2 && ($r2 = @pg_fetch_assoc($q2)) && isset($r2['est']) && $r2['est'] !== null) { $oghmaEstimate = (int)$r2['est']; }
}
$last = 0; $skyrim = '';
$q3 = @pg_query($adminConn, "SELECT MAX(gamets) AS mx FROM {$schema}.eventlog");
if ($q3 && ($r3 = @pg_fetch_assoc($q3)) && !is_null($r3['mx'])) { $last = (int)$r3['mx']; }
if ($last > 0) { $skyrim = convert_gamets2skyrim_long_date($last); }

// ---- Storage overview ----
// Metadata-size queries only (pg_database_size / pg_total_relation_size over the
// system catalogs). No table contents are scanned or returned.

// Sum relation sizes for a schema using catalog metadata, not table contents.
function ptm_stats_sum_schema($conn, string $schema): ?int {
    $res = @pg_query_params(
        $conn,
        "SELECT COALESCE(SUM(pg_total_relation_size(c.oid)),0)::bigint AS b FROM pg_catalog.pg_class c JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace WHERE n.nspname = $1 AND c.relkind IN ('r','m')",
        [$schema]
    );
    if ($res && ($row = @pg_fetch_assoc($res)) && isset($row['b']) && $row['b'] !== null) {
        return max(0, (int)$row['b']);
    }
    return null;
}

// Sum pg_total_relation_size over a set of public tables, skipping absent ones.
function ptm_stats_sum_public_tables($conn, array $tables): ?int {
    $arr = '{' . implode(',', $tables) . '}';
    $res = @pg_query_params(
        $conn,
        "SELECT COALESCE(SUM(pg_total_relation_size(c.oid)),0)::bigint AS b FROM pg_catalog.pg_class c JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace WHERE n.nspname = 'public' AND c.relkind IN ('r','m') AND c.relname = ANY($1::text[])",
        [$arr]
    );
    if ($res && ($row = @pg_fetch_assoc($res)) && isset($row['b']) && $row['b'] !== null) {
        return max(0, (int)$row['b']);
    }
    return null;
}

$storage = null;
$totRes = @pg_query($adminConn, "SELECT pg_database_size(current_database())::bigint AS b");
if ($totRes && ($tr = @pg_fetch_assoc($totRes)) && isset($tr['b']) && $tr['b'] !== null) {
    $totalBytes = max(0, (int)$tr['b']);

    // Public schema, split into diagnostics and everything else CHIM is using live.
    $publicBytes = ptm_stats_sum_schema($adminConn, 'public');
    $diagBytes = ptm_stats_sum_public_tables($adminConn, ['log', 'audit_request', 'responselog']);

    // All chim_profile_* snapshot schemas (schema-cloned snapshots)
    $snapBytes = null;
    $snapSchemas = 0;
    $snapRes = @pg_query(
        $adminConn,
        "SELECT COALESCE(SUM(pg_total_relation_size(c.oid)),0)::bigint AS b, COUNT(DISTINCT n.nspname) AS s FROM pg_catalog.pg_namespace n LEFT JOIN pg_catalog.pg_class c ON c.relnamespace = n.oid AND c.relkind IN ('r','m') WHERE left(n.nspname, 13) = 'chim_profile_'"
    );
    if ($snapRes && ($sr = @pg_fetch_assoc($snapRes)) && isset($sr['b']) && $sr['b'] !== null) {
        $snapBytes = max(0, (int)$sr['b']);
        $snapSchemas = (int)($sr['s'] ?? 0);
    }
    // Inline legacy dumps are also snapshot storage. Large-object dumps share
    // PostgreSQL's system storage and remain in Other; disclose that distinction.
    $blobRes = @pg_query($adminConn, "SELECT COALESCE(SUM(pg_total_relation_size(c.oid)),0)::bigint AS bytes FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname='chim_meta' AND c.relname='playthrough_blobs' AND c.relkind='r'");
    $blobRow = $blobRes ? pg_fetch_assoc($blobRes) : null;
    if ($snapBytes !== null && $blobRow) $snapBytes += (int)$blobRow['bytes'];

    if ($publicBytes !== null && $diagBytes !== null && $snapBytes !== null) {
        $playthroughBytes = max(0, $publicBytes - $diagBytes);
        $otherBytes = max(0, $totalBytes - $diagBytes - $playthroughBytes - $snapBytes);
        $storage = [
            'total_bytes' => $totalBytes,
            'playthrough_bytes' => $playthroughBytes,
            'diagnostics_bytes' => $diagBytes,
            'snapshots_bytes' => $snapBytes,
            'other_bytes' => $otherBytes,
            'snapshot_schemas' => $snapSchemas,
            'snapshot_note' => 'Snapshot sizes include schema copies and inline legacy dumps. Legacy large-object dumps are included in Other.',
        ];
    }
}

echo json_encode([
    'ok' => true,
    'counts_approximate' => true,
    'eventlog_estimate' => $eventlogEstimate,
    'oghma_estimate' => $oghmaEstimate,
    // Legacy keys: same nullable estimates (never exact counts)
    'eventlog' => $eventlogEstimate,
    'oghma' => $oghmaEstimate,
    'last_gamets' => $last,
    'last_skyrim_date' => $skyrim,
    'storage' => $storage,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
