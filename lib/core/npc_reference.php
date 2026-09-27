<?php

require_once __DIR__ . '/game_plugins.php';

// Accept only plugin/local-reference pairs, not paths, base IDs, or dynamic FF identities.
function chimParseNpcReferenceSource($value): ?array
{
    if (!is_string($value) || !preg_match('~^([^/\\\\|@#:\x00-\x1F]+\.es[mpl])[/|]([0-9a-f]{1,8})$~i', trim($value), $matches)) {
        return null;
    }
    $localId = hexdec($matches[2]);
    if ($localId > 0xFFFFFF) {
        return null;
    }
    return chimParseStableFormReference($matches[1] . '|' . sprintf('%08X', $localId));
}

// Plan first so same-name actors can swap occupied runtime IDs without violating uniqueness.
function chimPlanNpcReferenceRemap(array $rows, array $oldPlugins, array $newPlugins): array
{
    $oldByPrefix = chimIndexLoadedGamePluginsByPrefix($oldPlugins);
    $newByName = chimIndexLoadedGamePluginsByName($newPlugins);
    $updates = [];
    $occupied = [];
    foreach ($rows as $row) {
        $refid = strtoupper(trim((string)($row['refid'] ?? '')));
        $metadata = is_array($row['metadata'] ?? null)
            ? $row['metadata'] : (json_decode($row['metadata'] ?? '{}', true) ?: []);
        $source = null;
        if (strpos($refid, 'FF') !== 0) {
            $source = chimParseNpcReferenceSource($metadata['refid_source'] ?? '');
            if (!$source && preg_match('/^[0-9A-F]{8}$/', $refid)) {
                $source = chimParseNpcReferenceSource(
                    chimConvertRuntimeFormIdToStableReference($refid, $oldByPrefix) ?? ''
                );
            }
        }
        $newRefid = $refid;
        if ($source) {
            $plugin = $newByName[strtolower($source['plugin_name'])] ?? null;
            // A missing plugin is unavailable, not a different actor now occupying its old slot.
            $newRefid = $plugin
                ? chimComputeRuntimeFormIdFromPrefix($plugin['formid_prefix'], $source['local_formid']) : null;
            if ($plugin && strlen($plugin['formid_prefix']) === 5 && hexdec($source['local_formid']) > 0xFFF) {
                throw new RuntimeException('NPC reference no longer fits its plugin; compaction requires manual reconciliation');
            }
            $hash = md5('ref:' . strtolower($source['plugin_name']) . '|' . $source['local_formid']);
            if ((string)$newRefid !== $refid || ($metadata['refid_source'] ?? '') !== $source['stable_key'] || $hash !== ($row['md5'] ?? '')) {
                $updates[] = ['id' => (int)$row['id'], 'refid' => $newRefid, 'md5' => $hash, 'source' => $source['stable_key']];
            }
        }
        if ($newRefid !== null && $newRefid !== '') {
            $identity = $newRefid;
            if (isset($occupied[$identity])) {
                throw new RuntimeException('Ambiguous NPC reference remap; existing profiles were left unchanged');
            }
            $occupied[$identity] = true;
        }
    }
    return $updates;
}

// The old manifest and all affected profile identities change as one database-only transaction.
function chimSyncNpcReferenceLoadOrder(array $plugins): int
{
    global $db;
    $normalized = chimNormalizeLoadedGamePluginManifest($plugins);
    if (!$normalized || count($normalized) !== count($plugins)) {
        throw new RuntimeException('Empty or incomplete loaded plugin manifest');
    }
    $prefixes = [];
    foreach ($normalized as $plugin) {
        $prefix = $plugin['formid_prefix'];
        if (!chimParseNpcReferenceSource($plugin['plugin_name'] . '|00000000') ||
            !preg_match('/^(?:[0-9A-F]{2}|FE[0-9A-F]{3})$/', $prefix) || $prefix === 'FF' ||
            $plugin['is_light'] !== (strlen($prefix) === 5) || isset($prefixes[$prefix])) {
            throw new RuntimeException('Invalid or ambiguous loaded plugin manifest');
        }
        $prefixes[$prefix] = true;
    }
    $plugins = $normalized;
    if ($db->execQuery('BEGIN') === false) {
        throw new RuntimeException('Could not start NPC reference remap');
    }
    try {
        if ($db->execQuery('LOCK TABLE public.game_plugins, public.core_npc_master IN SHARE ROW EXCLUSIVE MODE') === false) {
            throw new RuntimeException('Could not lock NPC reference state');
        }
        $oldPlugins = $db->fetchAll('SELECT * FROM public.game_plugins');
        $rows = $db->fetchAll('SELECT id, npc_name, refid, md5, metadata FROM public.core_npc_master');
        $updates = chimPlanNpcReferenceRemap($rows, $oldPlugins, $plugins);
        if ($updates) {
            $ids = implode(',', array_column($updates, 'id'));
            // Release occupied keys inside the transaction before assigning the new permutation.
            if ($db->execQuery("UPDATE public.core_npc_master SET refid = NULL WHERE id IN ({$ids})") === false) {
                throw new RuntimeException('Could not release old NPC reference IDs');
            }
            foreach ($updates as $update) {
                $id = $update['id'];
                $refid = $update['refid'] === null ? 'NULL' : "'" . $db->escape($update['refid']) . "'";
                $hash = $db->escape($update['md5']);
                $source = $db->escape(json_encode($update['source'], JSON_UNESCAPED_SLASHES));
                if ($db->execQuery("UPDATE public.core_npc_master
                    SET refid = {$refid}, md5 = '{$hash}',
                        metadata = jsonb_set(
                            CASE WHEN metadata IN ('null'::jsonb, '[]'::jsonb) THEN '{}'::jsonb
                                 ELSE COALESCE(metadata, '{}'::jsonb) END,
                            '{refid_source}', '{$source}'::jsonb)
                    WHERE id = {$id}") === false) {
                    throw new RuntimeException('Could not persist NPC reference remap');
                }
            }
        }
        $count = chimReplaceLoadedGamePlugins($plugins);
        if ($db->execQuery('COMMIT') === false) {
            throw new RuntimeException('Could not commit NPC reference remap');
        }
        return $count;
    } catch (Throwable $e) {
        $db->execQuery('ROLLBACK');
        throw $e;
    }
}

// Upgrade selectors in place; conflicting references abort without merging or deleting profiles.
function chimMigrateStableNpcIdentity(): void
{
    $db = $GLOBALS['db'];
    if ($db->execQuery('BEGIN') === false) { throw new RuntimeException('Cannot begin stable NPC identity migration'); }
    try {
        if ($db->execQuery('LOCK TABLE core_npc_master IN SHARE ROW EXCLUSIVE MODE') === false) {
            throw new RuntimeException('Cannot lock NPC identities');
        }
        $rows = $db->fetchAll('SELECT id, npc_name, refid, metadata, md5 FROM core_npc_master');
        $sources = [];
        foreach ($rows as $row) {
            $source = chimParseNpcReferenceSource(chimNpcProfileJson($row['metadata'] ?? null)['refid_source'] ?? '');
            if ($source) {
                $key = strtolower($source['stable_key']);
                if (isset($sources[$key])) { throw new RuntimeException('Duplicate stable NPC reference; existing profiles were left unchanged'); }
                $sources[$key] = true;
            }
            $hash = NpcMaster::identityMd5($row);
            $metadata = chimNpcProfileJson($row['metadata'] ?? null);
            $canonicalSource = $source ? $source['stable_key'] : null;
            if ($hash === $row['md5'] && (!$source || ($metadata['refid_source'] ?? '') === $canonicalSource)) { continue; }
            $sourceUpdate = $source ? ", metadata = COALESCE(metadata, '{}'::jsonb) || jsonb_build_object('refid_source', '" . $db->escape($canonicalSource) . "'::text)" : '';
            if ($db->execQuery("UPDATE core_npc_master SET md5 = '{$hash}'{$sourceUpdate} WHERE id = " . (int)$row['id']) === false) {
                throw new RuntimeException('Cannot update stable NPC selector');
            }
        }
        if ($db->execQuery("CREATE UNIQUE INDEX IF NOT EXISTS idx_npc_stable_reference
            ON core_npc_master (lower(metadata->>'refid_source'))
            WHERE COALESCE(metadata->>'refid_source', '') <> ''") === false ||
            $db->execQuery('COMMIT') === false) { throw new RuntimeException('Cannot commit stable NPC identity migration'); }
    } catch (Throwable $error) {
        $db->execQuery('ROLLBACK');
        throw $error;
    }
}
