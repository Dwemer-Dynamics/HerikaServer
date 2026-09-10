<?php

require_once __DIR__ . '/playthrough_schema.php';
require_once __DIR__ . '/playthrough_preferences.php';

require_once __DIR__ . '/playthrough_categories.php';

function ptr_relationship_filter(): string { return " AND connector ILIKE '%Relationship%'"; }

// Manual selection uses the same expiring, atomic plan as policy-based cleanup.
function ptr_preview_delete($conn, array $ids): array {
    if (!$ids || count($ids)>50) throw new InvalidArgumentException('Select between 1 and 50 Playthrough Saves.');
    foreach ($ids as $id) if (!is_int($id) || $id<1) throw new InvalidArgumentException('Invalid Playthrough Save selection.');
    $ids = array_unique($ids);
    $plan = ptr_preview($conn, ptr_defaults());
    foreach (ptr_profiles($conn) as $profile) {
        if (!in_array($profile['id'], $ids, true)) continue;
        if ($profile['is_active'] || $profile['is_default'] || $profile['pinned'] || $profile['storage_type'] !== 'schema') {
            throw new RuntimeException('A selected save is active, protected or unsupported. Nothing was deleted.');
        }
        $plan['playthroughs'][] = ['id'=>$profile['id'], 'name'=>$profile['name'], 'bytes'=>$profile['bytes']];
    }
    if (count($plan['playthroughs']) !== count($ids)) throw new RuntimeException('A selected save no longer exists. Preview again.');
    return $plan;
}

// Retention is opt-in. This metadata lives outside saved playthroughs so restoring a game
// cannot silently re-enable an old cleanup policy.
function ptr_defaults(): array {
    $settings = ['diagnostics_enabled'=>false, 'diagnostic_days'=>7,
        'diagnostic_max_mb'=>0, 'playthroughs_enabled'=>false, 'playthrough_keep'=>0, 'event_days'=>0, 'events_enabled'=>false, 'events_days'=>30, 'requests_filter'=>'all'];
    foreach (ptr_categories() as $key => $category) {
        $settings[$key . '_enabled'] = false;
        $settings[$key . '_days'] = 7;
        $settings[$key . '_max_mb'] = 0;
    }
    return $settings;
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
        ptr_query($conn, "ALTER TABLE chim_meta.playthrough_profiles ADD COLUMN IF NOT EXISTS retention_kind TEXT NOT NULL DEFAULT 'unclassified'");
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
    $booleans = ['diagnostics_enabled','playthroughs_enabled','events_enabled'];
    $numbers = ['diagnostic_days'=>[1,3650], 'diagnostic_max_mb'=>[0,102400], 'playthrough_keep'=>[0,10000], 'event_days'=>[0,3650], 'events_days'=>[1,3650]];
    foreach (ptr_categories() as $key => $category) {
        $booleans[] = $key . '_enabled';
        $numbers[$key . '_days'] = [1,3650];
        $numbers[$key . '_max_mb'] = [0,102400];
        // Preserve saved legacy settings without implicitly enabling a new cleanup category.
        if (!array_key_exists($key . '_enabled', $input) && array_key_exists('diagnostics_enabled', $input)) {
            $input[$key . '_enabled'] = $key === 'recall' ? false : $input['diagnostics_enabled'];
            $input[$key . '_days'] = $input['diagnostic_days'] ?? 7;
            $input[$key . '_max_mb'] = $input['diagnostic_max_mb'] ?? 500;
        }
    }
    foreach ($booleans as $key) {
        if (!array_key_exists($key, $input)) continue;
        if (!in_array($input[$key], [true, false, 0, 1, '0', '1'], true)) throw new InvalidArgumentException('That cleanup on/off value was not valid.');
        $settings[$key] = in_array($input[$key], [true, 1, '1'], true);
    }
    foreach ($numbers as $key => [$min,$max]) {
        if (!array_key_exists($key, $input)) continue;
        $number = filter_var($input[$key], FILTER_VALIDATE_INT);
        if ($number === false || $number < $min || $number > $max) throw new InvalidArgumentException('That cleanup value is outside its allowed range.');
        $settings[$key] = $number;
    }
    if (!in_array($input['requests_filter'] ?? 'all', ['all','relationship'], true)) throw new InvalidArgumentException('Choose a valid request-log filter.');
    $settings['requests_filter'] = $input['requests_filter'] ?? 'all';
    $settings['diagnostics_enabled'] = false;
    foreach (ptr_categories() as $key => $category) $settings['diagnostics_enabled'] = $settings['diagnostics_enabled'] || $settings[$key . '_enabled'];
    return $settings;
}

// Manager actions and maintenance yield to a busy playthrough operation. Dragon Break capture
// waits on this same lock so cleanup cannot make a recovery playthrough disappear.
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
        COALESCE(to_jsonb(p)->>'retention_kind','unclassified') AS retention_kind,
        COALESCE(to_jsonb(p)->>'retention_pinned','false') AS retention_pinned,
        COALESCE(to_jsonb(p)->>'storage_type','dump') AS storage_type,
        to_jsonb(p)->>'schema_name' AS schema_name
        FROM chim_meta.playthrough_profiles p ORDER BY created_at DESC, id DESC")) ?: [];
    foreach ($rows as &$row) {
        $row['id'] = (int)$row['id'];
        $row['bytes'] = (int)$row['size_bytes'];
        $row['is_active'] = $row['is_active'] === 't';
        $row['is_default'] = strtolower($row['name']) === 'default';
        $row['automatic'] = in_array($row['retention_kind'], ['dragon_break','before_switch'], true);
        $row['pinned'] = $row['retention_pinned'] === 'true';
    }
    return $rows;
}

// A preview cannot be applied to a restored schema or changed playthrough set.
function ptr_identity($conn): string {
    $relations = pg_fetch_all(ptr_query($conn, "SELECT n.nspname,c.relname,c.oid,c.relfilenode FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace
        WHERE n.nspname='public' AND c.relname IN ('eventlog','log','audit_request','audit_memory','responselog') ORDER BY c.relname")) ?: [];
    return hash('sha256', json_encode([$relations, ptr_profiles($conn), ptr_settings($conn)]));
}

// Recheck at execution too: keep recent writes, unfinished replies and the newest
// event of each type. Age alone does not prove that history is no longer useful.
function ptr_event_guard(): string {
    return "t.gamets > 0 AND t.gamets < $1 AND t.localts > 0 AND t.localts < $2
        AND t.type <> '' AND t.gamets < (SELECT MAX(newer.gamets) FROM public.eventlog newer WHERE newer.type=t.type)
        AND LOWER(COALESCE(to_jsonb(t)->>'delivery_state','')) IN ('','spoken','cancelled','interrupted')";
}

// Each preview is limited to 1,000 log/event rows per table and three automatic
// playthroughs. Row versions pin the exact data the user agreed to remove.
function ptr_preview($conn, array $settings, ?string $category = null): array {
    $scope = null;
    if ($category !== null) {
        $categories = ptr_categories();
        if (!in_array($category, ['playthroughs','events'], true) && !isset($categories[$category])) {
            throw new InvalidArgumentException('Choose a valid cleanup category.');
        }
        // A one-off category preview ignores every other enabled rule and never saves settings.
        foreach ($categories as $key => $entry) $settings[$key . '_enabled'] = $key === $category;
        $settings['diagnostics_enabled'] = isset($categories[$category]);
        $settings['playthroughs_enabled'] = $category === 'playthroughs';
        $settings['events_enabled'] = $category === 'events';
        $scope = ['key'=>$category, 'label'=>$category === 'playthroughs' ? 'Playthrough Saves' : ($category === 'events' ? 'Events' : $categories[$category]['label'])];
    }
    $plan = ['scope' => $scope, 'identity' => ptr_identity($conn), 'created' => time(), 'diagnostics' => [], 'playthroughs' => [], 'more_possible' => false,
        'events' => ['rows'=>0, 'bytes_estimate'=>0, 'selected'=>[], 'cutoff_gamets'=>null, 'days'=>$settings['events_days'], 'message'=>''],
        'message' => 'Cleanup frees database space for reuse but may not reduce the files on disk.'];
    if ($settings['diagnostics_enabled']) {
        foreach (ptr_categories() as $key => $category) {
            if (!$settings[$key . '_enabled']) continue;
            $cutoff = time() - $settings[$key . '_days'] * 86400;
            $table = $category['table'];
            if (!ptr_exists($conn, 'public.' . $table)) continue;
            $stamp = $category['stamp'];
            // responselog is also a delivery queue: unsent entries are never eligible.
            $delivered = $table === 'responselog' ? 'AND sent > 0' : '';
            if ($key === 'requests' && $settings['requests_filter'] === 'relationship') $delivered .= ptr_relationship_filter();
            // ctid + xmin also identify rows in keyless audit_memory. Rewrites invalidate the preview.
            $rows = pg_fetch_all(ptr_query($conn, "SELECT ctid::text AS rowid, xmin::text AS version, pg_column_size(t) AS bytes, {$stamp} AS stamp
                FROM public.{$table} t WHERE {$stamp} > 0 AND {$stamp} < $1 {$delivered}
                ORDER BY {$stamp}, ctid LIMIT 1000", [time() - 86400])) ?: [];
            $excess = 0;
            // If age already selects this entire batch, measuring the whole table
            // cannot change the result. Empty batches need no size scan either.
            if ($settings[$key . '_max_mb'] > 0 && $rows && (float)$rows[count($rows) - 1]['stamp'] >= $cutoff) {
                $bytes = pg_fetch_result(ptr_query($conn, "SELECT COALESCE(SUM(pg_column_size(t)),0) FROM public.{$table} t WHERE true {$delivered}"), 0, 0);
                $excess = max(0, (int)$bytes - $settings[$key . '_max_mb'] * 1048576);
            }
            $selected = []; $size = 0;
            foreach ($rows as $row) {
                if ((float)$row['stamp'] >= $cutoff && $excess <= 0) break;
                $selected[] = ['id' => $row['rowid'], 'version' => $row['version']];
                $size += (int)$row['bytes'];
                $excess -= (int)$row['bytes'];
            }
            $plan['diagnostics'][] = ['table' => $table, 'label'=>$category['label'], 'rows' => count($selected), 'bytes_estimate' => $size, 'selected' => $selected];
            if (count($selected) === 1000) $plan['more_possible'] = true;
        }
    }
    if ($settings['playthroughs_enabled'] && $settings['playthrough_keep'] > 0) {
        $seen = 0;
        foreach (ptr_profiles($conn) as $profile) {
            if (!$profile['automatic']) continue;
            $seen++;
            if ($seen <= $settings['playthrough_keep'] || $profile['is_active'] || $profile['is_default'] || $profile['pinned']) continue;
            // Automatic cleanup only operates on explicitly tagged schema playthroughs.
            if ($profile['storage_type'] !== 'schema' || !str_starts_with((string)$profile['schema_name'], 'chim_profile_')) continue;
            $plan['playthroughs'][] = ['id' => $profile['id'], 'name' => $profile['name'], 'bytes' => $profile['bytes']];
        }
        $plan['playthroughs'] = array_reverse($plan['playthroughs']);
        if (count($plan['playthroughs']) > 3) $plan['more_possible'] = true;
        $plan['playthroughs'] = array_slice($plan['playthroughs'], 0, 3);
    }
    if ($settings['events_enabled'] && ptr_exists($conn, 'public.eventlog')) {
        $latest = (int)pg_fetch_result(ptr_query($conn, 'SELECT COALESCE(MAX(gamets),0) FROM public.eventlog'), 0, 0);
        $cutoff = max(0, $latest - $settings['events_days'] * 10000000);
        $guard = ptr_event_guard();
        $rows = pg_fetch_all(ptr_query($conn, "SELECT t.ctid::text AS id, t.xmin::text AS version, pg_column_size(t) AS bytes
            FROM public.eventlog t WHERE {$guard} ORDER BY t.gamets, t.ctid LIMIT 1000", [$cutoff, time()-86400])) ?: [];
        $plan['events']['cutoff_gamets'] = $cutoff;
        $plan['events']['rows'] = count($rows);
        $plan['events']['bytes_estimate'] = array_sum(array_column($rows, 'bytes'));
        $plan['events']['selected'] = array_map(fn($row) => ['id'=>$row['id'], 'version'=>$row['version']], $rows);
        $plan['events']['message'] = 'Deleting events removes raw history used for conversations, recall and future diaries. Saved memories and diaries are kept, but they may not contain every detail. Create a Playthrough Save first if you may need this history.';
        if (count($rows) === 1000) $plan['more_possible'] = true;
    }
    return $plan;
}

// Caller owns the transaction. A failed schema drop must preserve its profile row.
function ptr_delete_playthrough($conn, int $id): void {
    $row = pg_fetch_assoc(ptr_query($conn, 'SELECT *, to_jsonb(p)->>\'retention_pinned\' AS pinned FROM chim_meta.playthrough_profiles p WHERE id=$1 FOR UPDATE', [$id]));
    if (!$row || $row['is_active'] === 't' || strtolower($row['name']) === 'default' || $row['pinned'] === 'true') {
        throw new RuntimeException('That playthrough is missing, currently active, the default one, or protected.');
    }
    if (($row['storage_type'] ?? 'dump') === 'schema') {
        $schema = (string)($row['schema_name'] ?? '');
        if (!preg_match('/^chim_profile_[a-z0-9_]+$/D', $schema)) throw new RuntimeException('That playthrough has an unexpected name and was left alone.');
        $result = pts_drop_schema($conn, $schema);
        if (!$result['success']) throw new RuntimeException('That playthrough could not be removed, so nothing was deleted.');
    }
    ptr_query($conn, 'DELETE FROM chim_meta.playthrough_profiles WHERE id=$1', [$id]);
}

// All mutations commit together; timeouts, changed row versions, or a restore
// invalidate the entire batch, including playthrough drops.
function ptr_execute($conn, array $plan): array {
    if (time() - $plan['created'] >= 300) throw new RuntimeException('The preview expired. Run a new preview.');
    ptr_query($conn, 'BEGIN');
    try {
        ptr_query($conn, "SET LOCAL lock_timeout='2s'");
        ptr_query($conn, "SET LOCAL statement_timeout='20s'");
        if (ptr_exists($conn, 'chim_meta.playthrough_profiles')) ptr_query($conn, 'LOCK TABLE chim_meta.playthrough_profiles IN SHARE ROW EXCLUSIVE MODE');
        if (!hash_equals($plan['identity'], ptr_identity($conn))) throw new RuntimeException('Your Playthrough Save or settings changed. Preview again.');
        $deleted = 0;
        foreach ($plan['diagnostics'] as $group) {
            if (!in_array($group['table'], array_column(ptr_categories(), 'table'), true)) throw new RuntimeException('An unexpected log table was in the plan, so nothing was deleted.');
            if (!$group['selected']) continue;
            $table = $group['table'];
            $queueGuard = $table === 'responselog' ? 'AND t.sent > 0' : '';
            $res = ptr_query($conn, "DELETE FROM public.{$table} t USING jsonb_to_recordset($1::jsonb) AS chosen(id text, version text)
                WHERE t.ctid=chosen.id::tid AND t.xmin::text=chosen.version {$queueGuard}", [json_encode($group['selected'])]);
            if (pg_affected_rows($res) !== count($group['selected'])) throw new RuntimeException('The logs changed. Preview again before deleting.');
            $deleted += pg_affected_rows($res);
        }
        $eventDeleted = 0;
        if (!empty($plan['events']['selected'])) {
            // Loading an older game can move the clock back without replacing the table.
            $latest = (int)pg_fetch_result(ptr_query($conn, 'SELECT COALESCE(MAX(gamets),0) FROM public.eventlog'), 0, 0);
            $cutoff = min($plan['events']['cutoff_gamets'], max(0, $latest - $plan['events']['days'] * 10000000));
            $guard = ptr_event_guard();
            $res = ptr_query($conn, "DELETE FROM public.eventlog t USING jsonb_to_recordset($3::jsonb) AS chosen(id text, version text)
                WHERE t.ctid=chosen.id::tid AND t.xmin::text=chosen.version AND {$guard}",
                [$cutoff, time()-86400, json_encode($plan['events']['selected'])]);
            $eventDeleted = pg_affected_rows($res);
            if ($eventDeleted !== count($plan['events']['selected'])) throw new RuntimeException('The events changed. Preview again before deleting.');
            $deleted += $eventDeleted;
        }
        foreach ($plan['playthroughs'] as $playthrough) ptr_delete_playthrough($conn, $playthrough['id']);
        $changed = $deleted > 0 || count($plan['playthroughs']) > 0;
        $result = ['at' => gmdate('c'), 'status' => $changed ? 'succeeded' : 'no_work',
            'rows' => $deleted, 'event_rows'=>$eventDeleted, 'playthroughs' => count($plan['playthroughs']), 'more_possible' => $plan['more_possible'] ?? false,
            'message' => $changed ? 'Cleanup finished. Only the listed entries and saves were deleted. Your active Playthrough Save was kept.' : 'Nothing matches these cleanup rules.'];
        ptr_write($conn, 'PLAYTHROUGH_RETENTION_LAST_RUN', $result);
        ptr_query($conn, 'COMMIT');
        return $result;
    } catch (Throwable $e) {
        @pg_query($conn, 'ROLLBACK');
        throw $e;
    }
}

// The existing service sweeps enabled categories; the retired master switch is ignored.
// No cleanup runs on page GET, and disabled categories never enter the sweep.
function ptr_tick($conn): void {
    if (!ptr_exists($conn, 'chim_meta.settings')) return;
    $settings = ptr_settings($conn);
    if (!$settings['diagnostics_enabled'] && !$settings['events_enabled'] && (!$settings['playthroughs_enabled'] || $settings['playthrough_keep'] === 0)) return;
    $attempt = (int)ptr_read($conn, 'PLAYTHROUGH_RETENTION_LAST_ATTEMPT', 0);
    if (time() - $attempt < 3600 || !ptr_lock($conn)) return;
    try {
        if (time() - (int)ptr_read($conn, 'PLAYTHROUGH_RETENTION_LAST_ATTEMPT', 0) < 3600) return;
        $settings = ptr_settings($conn);
        if (!$settings['diagnostics_enabled'] && !$settings['events_enabled'] && (!$settings['playthroughs_enabled'] || $settings['playthrough_keep'] === 0)) return;
        ptr_write($conn, 'PLAYTHROUGH_RETENTION_LAST_ATTEMPT', time());
        ptr_query($conn, "SET statement_timeout='20s'");
        $plan = ptr_preview($conn, $settings);
        ptr_execute($conn, $plan);
    } catch (Throwable $e) {
        ptr_write($conn, 'PLAYTHROUGH_RETENTION_LAST_RUN', ['at' => gmdate('c'), 'status' => 'failed', 'rows' => 0, 'playthroughs' => 0,
            'message' => 'Cleanup failed. Nothing was deleted. Use Preview cleanup to try again.']);
        error_log('Playthrough retention: ' . $e->getMessage());
    } finally {
        @pg_query($conn, 'RESET statement_timeout');
        ptr_unlock($conn);
    }
}
