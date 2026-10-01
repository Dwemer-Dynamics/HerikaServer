<?php
// Objects each idempotent actor-identity migration must leave behind, checked by both
// lib/runtime_bootstrap.php and debug/db_updates.php so a current version marker cannot hide a
// missing column, index, function or appended memory_v column (docs/actor-identity.md).
// Column values are the expected format_type(); keep this list in step with the SQL files.

if (!function_exists('chimIdentitySchemaObjects')) {
    function chimIdentitySchemaObjects(): array
    {
        return [
            'eventlog_actor_identity' => [
                'fn:chim_eventlog_actor_keys(text)' => '',
                'idx:idx_eventlog_actor_keys' => '',
                'idx:idx_npc_dynamic_actor_key' => '',
                'col:eventlog.speaker_key' => 'text',
                'col:eventlog.listener_keys' => 'text',
                'col:eventlog.target_key' => 'text',
                'col:speech.speaker_key' => 'text',
                'col:speech.listener_keys' => 'text',
            ],
            'memory_actor_identity' => [
                'fn:chim_identity_audience_keys(text)' => '',
                'idx:idx_memory_summary_audience_keys' => '',
                'col:speech.audience' => 'text',
                'col:memory.audience' => 'text',
                'col:memory.owner_key' => 'text',
                'col:memory_summary.audience_keys' => 'text[]',
                'col:memory_summary.source_refs' => 'jsonb',
                'col:memory_summary.partition_key' => 'text',
                'col:memory_v.source_table' => 'text',
                'col:memory_v.source_rowid' => 'bigint',
                'col:memory_v.audience' => 'text',
                'col:memory_v.audience_keys' => 'text[]',
                'col:memory_v.speaker_key' => 'text',
                'col:memory_v.listener_keys' => 'text',
            ],
            'action_mood_actor_identity' => [
                'idx:idx_actions_issued_actor_key' => '',
                'idx:idx_moods_issued_actor_key' => '',
                'col:actions_issued.actor_key' => 'text',
                'col:moods_issued.actor_key' => 'text',
            ],
            'diary_actor_identity' => [
                'idx:idx_diarylog_author_key_gamets' => '',
                'idx:idx_books_book_key' => '',
                'idx:physical_npc_diaries_author_key_uidx' => '',
                'idx:physical_npc_diaries_legacy_name_uidx' => '',
                'col:diarylog.author_key' => 'text',
                'col:books.book_key' => 'text',
                'col:books.author_key' => 'text',
                'col:books.recipient_key' => 'text',
                'col:books.reader_key' => 'text',
                'col:books.book_instance' => 'text',
                'col:books.content_version' => 'text',
                'col:physical_npc_diaries.author_key' => 'text',
            ],
            'npc_commitments' => [
                'idx:idx_npc_commitments_owner_status_due' => '',
                'idx:idx_npc_commitments_actor_status_due' => '',
                'idx:idx_npc_commitments_timeline' => '',
                'col:npc_commitments.npc_id' => 'bigint',
                'col:npc_commitments.actor_key' => 'text',
                'col:npc_commitments.counterparty_key' => 'text',
            ],
            'bgl_letters' => [
                'col:bgl_letters.courier_refid' => 'character varying',
            ],
        ];
    }
}

if (!function_exists('chimIdentitySchemaGaps')) {
    // Returns [migration => ['missing' => [...], 'incompatible' => ['col:t.c' => 'actual type']]] for
    // migrations with gaps, or null when the catalog cannot be read. Missing objects are repaired by
    // rerunning the migration; ADD COLUMN IF NOT EXISTS cannot convert a wrong type, so incompatible
    // columns are reported instead of retried.
    function chimIdentitySchemaGaps($db): ?array
    {
        $tables = [];
        $indexes = [];
        $functions = [];
        foreach (chimIdentitySchemaObjects() as $objects) {
            foreach (array_keys($objects) as $key) {
                [$kind, $name] = explode(':', $key, 2);
                if ($kind === 'col') {
                    $tables[explode('.', $name, 2)[0]] = true;
                } elseif ($kind === 'idx') {
                    $indexes[$name] = true;
                } else {
                    $functions[$name] = true;
                }
            }
        }
        $quote = static function (array $names): string {
            return implode(',', array_map(static function ($name) {
                return "'" . str_replace("'", "''", $name) . "'";
            }, array_keys($names)));
        };
        $fnChecks = [];
        foreach (array_keys($functions) as $fn) {
            $literal = "'" . str_replace("'", "''", $fn) . "'";
            $fnChecks[] = "SELECT 'fn:' || $literal, '' WHERE to_regprocedure('public.' || $literal) IS NOT NULL";
        }

        try {
            $rows = $db->fetchAll(
                "SELECT 'col:' || c.relname || '.' || a.attname AS k, format_type(a.atttypid, a.atttypmod) AS t
                   FROM pg_attribute a JOIN pg_class c ON c.oid = a.attrelid
                  WHERE c.relnamespace = 'public'::regnamespace AND c.relname IN (" . $quote($tables) . ")
                    AND a.attnum > 0 AND NOT a.attisdropped
                 UNION ALL SELECT 'idx:' || c.relname, '' FROM pg_class c
                  WHERE c.relnamespace = 'public'::regnamespace AND c.relkind = 'i' AND c.relname IN (" . $quote($indexes) . ")
                 UNION ALL " . implode(' UNION ALL ', $fnChecks)
            );
        } catch (\Throwable $e) {
            return null;
        }
        if (!is_array($rows)) {
            return null;
        }

        $present = [];
        foreach ($rows as $row) {
            $present[strval($row['k'] ?? '')] = strval($row['t'] ?? '');
        }

        $gaps = [];
        foreach (chimIdentitySchemaObjects() as $migration => $objects) {
            foreach ($objects as $key => $type) {
                if (!array_key_exists($key, $present)) {
                    $gaps[$migration]['missing'][] = $key;
                } elseif ($type !== '' && $present[$key] !== $type) {
                    $gaps[$migration]['incompatible'][$key] = $present[$key];
                }
            }
        }
        return $gaps;
    }
}
