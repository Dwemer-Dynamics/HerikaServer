<?php

// Server-owned cleanup categories, shared by the Dashboard and standalone controls.
function ptr_categories(): array {
    return [
        'log' => ['label'=>'Prompt and response logs', 'description'=>'AI prompts and replies kept for troubleshooting.', 'table'=>'log', 'stamp'=>'localts'],
        'requests' => ['label'=>'Request logs', 'description'=>'Server requests kept for troubleshooting.', 'table'=>'audit_request', 'stamp'=>'EXTRACT(EPOCH FROM created_at)'],
        'recall' => ['label'=>'Memory search logs', 'description'=>'Search records only. NPC memories are kept.', 'table'=>'audit_memory', 'stamp'=>'EXTRACT(EPOCH FROM created_at)'],
        'responses' => ['label'=>'Response logs', 'description'=>'Response records. Unsent replies are always kept.', 'table'=>'responselog', 'stamp'=>'localts'],
    ];
}

// Read table sizes once; the same category keys drive storage totals and cleanup.
function ptr_storage_overview($conn, string $meta): array {
    if (!in_array($meta, ['chim_meta', 'stobe_meta', 'dialectic_meta'], true)) {
        throw new InvalidArgumentException('Unknown mod storage.');
    }
    $result = pg_query($conn, "SELECT pg_database_size(current_database()) AS bytes");
    if (!$result) throw new RuntimeException('Storage sizes are unavailable.');
    $total = (int)pg_fetch_result($result, 0, 0);
    $result = pg_query_params($conn, 'SELECT to_regclass($1) IS NOT NULL', [$meta . '.playthrough_profiles']);
    if (!$result) throw new RuntimeException('Saved storage is unavailable.');
    $schemas = [];
    if (pg_fetch_result($result, 0, 0) === 't') {
        $result = pg_query($conn, 'SELECT DISTINCT to_jsonb(p)->>\'schema_name\' AS name FROM '
            . pg_escape_identifier($conn, $meta) . '.playthrough_profiles p');
        if (!$result) throw new RuntimeException('Saved storage is unavailable.');
        foreach (pg_fetch_all($result) ?: [] as $row) {
            // Only registered save schemas count as saves; plugin and system schemas stay separate.
            if (preg_match('/^(chim|stobe|dialectic)_profile_[a-z0-9_]+$/D', (string)$row['name'])) $schemas[$row['name']] = true;
        }
    }
    $result = pg_query($conn, "SELECT n.nspname, c.relname, pg_total_relation_size(c.oid) AS bytes, c.reltuples
        FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace
        WHERE c.relkind IN ('r','m') AND (n.nspname='public' OR n.nspname LIKE '%\_profile\_%')");
    if (!$result) throw new RuntimeException('Storage sizes are unavailable.');
    $categories = []; $tables = [];
    foreach (ptr_categories() as $key => $category) {
        $categories[$key] = ['key'=>$key,'label'=>$category['label'],'description'=>$category['description'],
            'bytes'=>0,'rows_estimate'=>0,'cleanup'=>true,'available'=>false];
        $tables[$category['table']] = $key;
    }
    foreach ([
        'playthroughs'=>['Playthrough Saves','Saved copies of your mod data. Older archive formats may be counted under other database storage.'],
        'events'=>['Events','Raw gameplay and conversation history. Cleanup is off by default.'],
        'memory'=>['Memories and knowledge','Used by your characters.'],
        'other'=>['Settings and other live data','Needed by the mod. Not included in log cleanup.'],
        'stored'=>['Other database storage','Database overhead, older save archives and other stored data.'],
    ] as $key => [$label,$description]) {
        $categories[$key] = ['key'=>$key,'label'=>$label,'description'=>$description,'bytes'=>0,
            'rows_estimate'=>null,'cleanup'=>in_array($key, ['playthroughs','events'], true),'available'=>$key!=='events'];
    }
    foreach (pg_fetch_all($result) ?: [] as $table) {
        if ($table['nspname'] !== 'public') {
            if (isset($schemas[$table['nspname']])) $categories['playthroughs']['bytes'] += (int)$table['bytes'];
            continue;
        }
        $name = $table['relname'];
        $key = $tables[$name] ?? 'other';
        if ($name === 'eventlog') $key = 'events';
        elseif (!isset($tables[$name]) && preg_match('/^(memory|memories|oghma|worldknowledge|diary|diaries)(_|$)/', $name)) $key = 'memory';
        $categories[$key]['bytes'] += (int)$table['bytes'];
        $categories[$key]['available'] = true;
        if (isset($tables[$name])) $categories[$key]['rows_estimate'] = (float)$table['reltuples'] < 0 ? null : (int)round((float)$table['reltuples']);
    }
    $categories['stored']['bytes'] = max(0, $total - array_sum(array_column($categories, 'bytes')));
    return ['database_bytes'=>$total,'categories'=>array_values(array_filter($categories, fn($category) => $category['available']))];
}
