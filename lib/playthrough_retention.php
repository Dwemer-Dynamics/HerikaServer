<?php

require_once __DIR__ . '/playthrough_schema.php';

// Retention is opt-in. This metadata lives outside snapshots so restoring a game
// cannot silently re-enable an old cleanup policy.
function ptr_defaults(): array {
    return ['automatic' => false, 'diagnostics_enabled' => false, 'diagnostic_days' => 7,
        'diagnostic_max_mb' => 500, 'snapshots_enabled' => false, 'snapshot_keep' => 5, 'event_days' => 0];
}

function ptr_query($conn, string $sql, array $params = []) {
    $result = @pg_query_params($conn, $sql, $params);
    if (!$result) throw new RuntimeException('The cleanup database request failed.');
    return $result;
}

function ptr_exists($conn, string $relation): bool {
    return pg_fetch_result(ptr_query($conn, 'SELECT to_regclass($1) IS NOT NULL', [$relation]), 0, 0) === 't';
}

// Idempotent fresh-install/upgrade path, called only by explicit writes.
function ptr_ensure_schema($conn): void {
    ptr_query($conn, 'CREATE SCHEMA IF NOT EXISTS chim_meta');
    ptr_query($conn, 'CREATE TABLE IF NOT EXISTS chim_meta.settings (key TEXT PRIMARY KEY, value TEXT)');
    if (ptr_exists($conn, 'chim_meta.playthrough_profiles')) {
        ptr_query($conn, "ALTER TABLE chim_meta.playthrough_profiles ADD COLUMN IF NOT EXISTS retention_kind TEXT NOT NULL DEFAULT 'manual'");
        ptr_query($conn, 'ALTER TABLE chim_meta.playthrough_profiles ADD COLUMN IF NOT EXISTS retention_pinned BOOLEAN NOT NULL DEFAULT false');
    }
}

function ptr_read($conn, string $key, $fallback) {
    if (!ptr_exists($conn, 'chim_meta.settings')) return $fallback;
    $row = pg_fetch_assoc(ptr_query($conn, 'SELECT value FROM chim_meta.settings WHERE key=$1', [$key]));
    return $row ? (json_decode($row['value'], true) ?? $fallback) : $fallback;
}

function ptr_write($conn, string $key, $value): void {
    ptr_query($conn, 'INSERT INTO chim_meta.settings (key,value) VALUES ($1,$2) ON CONFLICT (key) DO UPDATE SET value=EXCLUDED.value', [$key, json_encode($value, JSON_THROW_ON_ERROR)]);
}

function ptr_settings($conn): array {
    $stored = ptr_read($conn, 'PLAYTHROUGH_RETENTION', []);
    return ptr_validate(is_array($stored) ? $stored : []);
}

function ptr_validate(array $input): array {
    $settings = ptr_defaults();
    foreach (['automatic', 'diagnostics_enabled', 'snapshots_enabled'] as $key) {
        if (!array_key_exists($key, $input)) continue;
        if (!in_array($input[$key], [true, false, 0, 1, '0', '1'], true)) throw new InvalidArgumentException('That cleanup on/off value was not valid.');
        $settings[$key] = in_array($input[$key], [true, 1, '1'], true);
    }
    foreach (['diagnostic_days' => [1,3650], 'diagnostic_max_mb' => [0,102400], 'snapshot_keep' => [1,100], 'event_days' => [0,3650]] as $key => [$min,$max]) {
        if (!array_key_exists($key, $input)) continue;
        $number = filter_var($input[$key], FILTER_VALIDATE_INT);
        if ($number === false || $number < $min || $number > $max) throw new InvalidArgumentException('That cleanup value is outside its allowed range.');
        $settings[$key] = $number;
    }
    return $settings;
}

// Manager actions and maintenance yield to a busy snapshot. Dragon Break capture
// waits on this same lock so cleanup cannot make a recovery snapshot disappear.
function ptr_lock($conn): bool {
    return pg_fetch_result(ptr_query($conn, "SELECT pg_try_advisory_lock(hashtext('chim_playthrough_retention'))"), 0, 0) === 't';
}

function ptr_unlock($conn): void {
    @pg_query($conn, "SELECT pg_advisory_unlock(hashtext('chim_playthrough_retention'))");
}

function ptr_profiles($conn): array {
    if (!ptr_exists($conn, 'chim_meta.playthrough_profiles')) return [];
    // JSON field reads tolerate profiles created before newer metadata columns.
    $rows = pg_fetch_all(ptr_query($conn, "SELECT id, name, is_active, created_at, size_bytes,
        COALESCE(to_jsonb(p)->>'retention_kind','manual') AS retention_kind,
        COALESCE(to_jsonb(p)->>'retention_pinned','false') AS retention_pinned,
        COALESCE(to_jsonb(p)->>'storage_type','dump') AS storage_type,
        to_jsonb(p)->>'schema_name' AS schema_name
        FROM chim_meta.playthrough_profiles p ORDER BY created_at DESC, id DESC")) ?: [];
    foreach ($rows as &$row) {
        $row['id'] = (int)$row['id'];
        $row['bytes'] = (int)$row['size_bytes'];
        $row['is_active'] = $row['is_active'] === 't';
        $row['is_default'] = strtolower($row['name']) === 'default';
        $row['automatic'] = $row['retention_kind'] === 'dragon_break';
        $row['pinned'] = $row['retention_pinned'] === 'true';
    }
    return $rows;
}

// A preview cannot be applied to a restored schema or changed snapshot set.
function ptr_identity($conn): string {
    $relations = pg_fetch_all(ptr_query($conn, "SELECT n.nspname,c.relname,c.oid FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace
        WHERE n.nspname='public' AND c.relname IN ('eventlog','log','audit_request','responselog') ORDER BY c.relname")) ?: [];
    return hash('sha256', json_encode([$relations, ptr_profiles($conn), ptr_settings($conn)]));
}

// Each preview is limited to 1,000 diagnostics rows per table and three automatic
// snapshots. Row versions pin the exact data the user agreed to remove.
function ptr_preview($conn, array $settings): array {
    $plan = ['identity' => ptr_identity($conn), 'created' => time(), 'diagnostics' => [], 'snapshots' => [],
        'events' => ['older_rows' => 0, 'cutoff_gamets' => null,
            'blocked_reason' => 'CHIM cannot yet confirm that these events have finished being turned into NPC memories.'],
        'message' => 'This is one cleanup round. Space from deleted rows becomes reusable inside the database, but the files on disk may not shrink.'];
    if ($settings['diagnostics_enabled']) {
        $cutoff = time() - $settings['diagnostic_days'] * 86400;
        foreach (['log' => 'localts', 'audit_request' => 'created_at', 'responselog' => 'localts'] as $table => $column) {
            if (!ptr_exists($conn, 'public.' . $table)) continue;
            $stamp = $column === 'created_at' ? 'EXTRACT(EPOCH FROM created_at)' : $column;
            // responselog is also a delivery queue: unsent entries are never eligible.
            $delivered = $table === 'responselog' ? 'AND sent > 0' : '';
            $excess = 0;
            if ($settings['diagnostic_max_mb'] > 0) {
                $bytes = pg_fetch_result(ptr_query($conn, "SELECT COALESCE(SUM(pg_column_size(t)),0) FROM public.{$table} t"), 0, 0);
                $excess = max(0, (int)$bytes - $settings['diagnostic_max_mb'] * 1048576);
            }
            $rows = pg_fetch_all(ptr_query($conn, "SELECT rowid, xmin::text AS version, pg_column_size(t) AS bytes, {$stamp} AS stamp
                FROM public.{$table} t WHERE {$stamp} > 0 AND {$stamp} < $1 {$delivered}
                ORDER BY {$column}, rowid LIMIT 1000", [time() - 86400])) ?: [];
            $selected = []; $size = 0;
            foreach ($rows as $row) {
                if ((float)$row['stamp'] >= $cutoff && $excess <= 0) break;
                $selected[] = ['id' => $row['rowid'], 'version' => $row['version']];
                $size += (int)$row['bytes'];
                $excess -= (int)$row['bytes'];
            }
            $plan['diagnostics'][] = ['table' => $table, 'rows' => count($selected), 'bytes_estimate' => $size, 'selected' => $selected];
        }
    }
    if ($settings['snapshots_enabled']) {
        $seen = 0;
        foreach (ptr_profiles($conn) as $profile) {
            if (!$profile['automatic']) continue;
            $seen++;
            if ($seen <= $settings['snapshot_keep'] || $profile['is_active'] || $profile['is_default'] || $profile['pinned']) continue;
            // Automatic cleanup only operates on explicitly tagged schema snapshots.
            if ($profile['storage_type'] !== 'schema' || !str_starts_with((string)$profile['schema_name'], 'chim_profile_')) continue;
            $plan['snapshots'][] = ['id' => $profile['id'], 'name' => $profile['name'], 'bytes' => $profile['bytes']];
            if (count($plan['snapshots']) >= 3) break;
        }
    }
    if ($settings['event_days'] > 0 && ptr_exists($conn, 'public.eventlog')) {
        // Preview only: never use this timestamp as proof that an event is disposable.
        $latest = (int)pg_fetch_result(ptr_query($conn, 'SELECT COALESCE(MAX(gamets),0) FROM public.eventlog'), 0, 0);
        $cutoff = max(0, $latest - $settings['event_days'] * 10000000);
        $plan['events']['cutoff_gamets'] = $cutoff;
        $plan['events']['older_rows'] = (int)pg_fetch_result(ptr_query($conn, 'SELECT COUNT(*) FROM public.eventlog WHERE gamets>0 AND gamets<$1', [$cutoff]), 0, 0);
    }
    return $plan;
}

// Caller owns the transaction. A failed schema drop must preserve its profile row.
function ptr_delete_snapshot($conn, int $id): void {
    $row = pg_fetch_assoc(ptr_query($conn, 'SELECT *, to_jsonb(p)->>\'retention_pinned\' AS pinned FROM chim_meta.playthrough_profiles p WHERE id=$1 FOR UPDATE', [$id]));
    if (!$row || $row['is_active'] === 't' || strtolower($row['name']) === 'default' || $row['pinned'] === 'true') {
        throw new RuntimeException('That snapshot is missing, currently loaded, the default one, or protected.');
    }
    if (($row['storage_type'] ?? 'dump') === 'schema') {
        $schema = (string)($row['schema_name'] ?? '');
        if (!preg_match('/^chim_profile_[a-z0-9_]+$/D', $schema)) throw new RuntimeException('That snapshot has an unexpected name and was left alone.');
        $result = pts_drop_schema($conn, $schema);
        if (!$result['success']) throw new RuntimeException('That snapshot could not be removed, so nothing was deleted.');
    }
    ptr_query($conn, 'DELETE FROM chim_meta.playthrough_profiles WHERE id=$1', [$id]);
}

// All mutations commit together; timeouts, changed row versions, or a restore
// invalidate the entire batch, including snapshot drops.
function ptr_execute($conn, array $plan): array {
    if (time() - $plan['created'] >= 300) throw new RuntimeException('The preview expired. Run a new preview.');
    ptr_query($conn, 'BEGIN');
    try {
        ptr_query($conn, "SET LOCAL lock_timeout='2s'");
        ptr_query($conn, "SET LOCAL statement_timeout='20s'");
        if (ptr_exists($conn, 'chim_meta.playthrough_profiles')) ptr_query($conn, 'LOCK TABLE chim_meta.playthrough_profiles IN SHARE ROW EXCLUSIVE MODE');
        if (!hash_equals($plan['identity'], ptr_identity($conn))) throw new RuntimeException('Your playthrough or settings changed. Run a new preview.');
        $deleted = 0;
        foreach ($plan['diagnostics'] as $group) {
            if (!in_array($group['table'], ['log','audit_request','responselog'], true)) throw new RuntimeException('An unexpected log table was in the plan, so nothing was deleted.');
            if (!$group['selected']) continue;
            $table = $group['table'];
            $queueGuard = $table === 'responselog' ? 'AND t.sent > 0' : '';
            $res = ptr_query($conn, "DELETE FROM public.{$table} t USING jsonb_to_recordset($1::jsonb) AS chosen(id bigint, version text)
                WHERE t.rowid=chosen.id AND t.xmin::text=chosen.version {$queueGuard}", [json_encode($group['selected'])]);
            if (pg_affected_rows($res) !== count($group['selected'])) throw new RuntimeException('The debug logs changed since the preview. Run a new preview.');
            $deleted += pg_affected_rows($res);
        }
        foreach ($plan['snapshots'] as $snapshot) ptr_delete_snapshot($conn, $snapshot['id']);
        $result = ['at' => gmdate('c'), 'rows' => $deleted, 'snapshots' => count($plan['snapshots']),
            'message' => 'Cleanup finished. Your current playthrough and files were left alone.'];
        ptr_write($conn, 'PLAYTHROUGH_RETENTION_LAST_RUN', $result);
        ptr_query($conn, 'COMMIT');
        return $result;
    } catch (Throwable $e) {
        @pg_query($conn, 'ROLLBACK');
        throw $e;
    }
}

// Existing service manager calls this; no scheduler or cleanup runs on page GET.
function ptr_tick($conn): void {
    if (!ptr_exists($conn, 'chim_meta.settings')) return;
    $settings = ptr_settings($conn);
    if (!$settings['automatic'] || (!$settings['diagnostics_enabled'] && !$settings['snapshots_enabled'])) return;
    $attempt = (int)ptr_read($conn, 'PLAYTHROUGH_RETENTION_LAST_ATTEMPT', 0);
    if (time() - $attempt < 3600 || !ptr_lock($conn)) return;
    try {
        if (time() - (int)ptr_read($conn, 'PLAYTHROUGH_RETENTION_LAST_ATTEMPT', 0) < 3600) return;
        $settings = ptr_settings($conn);
        if (!$settings['automatic'] || (!$settings['diagnostics_enabled'] && !$settings['snapshots_enabled'])) return;
        ptr_write($conn, 'PLAYTHROUGH_RETENTION_LAST_ATTEMPT', time());
        ptr_query($conn, "SET statement_timeout='20s'");
        $plan = ptr_preview($conn, $settings);
        ptr_execute($conn, $plan);
    } catch (Throwable $e) {
        ptr_write($conn, 'PLAYTHROUGH_RETENTION_LAST_RUN', ['at' => gmdate('c'), 'rows' => 0, 'snapshots' => 0,
            'message' => 'Automatic cleanup did not finish, so nothing was deleted. You can try it yourself with Preview cleanup.']);
        Logger::error('Playthrough retention: ' . $e->getMessage());
    } finally {
        @pg_query($conn, 'RESET statement_timeout');
        ptr_unlock($conn);
    }
}
