<?php

// Content migrations only receive a private schema created by prepare_playthrough.
// Use shipped data, never settings or knowledge from the current live playthrough.
function pts_migrate_prepared_playthrough($conn, string $stage): void {
    if (!preg_match('/^chim_profile_upgrade_[0-9]+_[0-9]+$/D', $stage)
        || pg_transaction_status($conn) !== PGSQL_TRANSACTION_INTRANS) {
        throw new RuntimeException('Invalid playthrough migration context');
    }
    $query = static function (string $sql, array $params = []) use ($conn) {
        $result = $params ? @pg_query_params($conn, $sql, $params) : @pg_query($conn, $sql);
        if (!$result) throw new RuntimeException(pg_last_error($conn));
        return $result;
    };
    $metadata = json_decode(pg_fetch_result($query("SELECT obj_description(oid,'pg_namespace') FROM pg_namespace WHERE nspname=$1", [$stage]), 0, 0), true, 32, JSON_THROW_ON_ERROR);
    $missing = $metadata['missing_tables'];
    $schema = pg_escape_identifier($conn, $stage);
    foreach (['core_tts_fallback'=>'lib/core/database_schema/core_tts_fallback.sql', 'faction_vanilla'=>'data/factions_vanilla.sql'] as $table=>$file) {
        if (!in_array($table, $missing, true)) continue;
        $sql = file_get_contents(dirname(__DIR__) . '/' . $file);
        if ($sql === false) throw new RuntimeException('Playthrough migration seed is unavailable: ' . $table);
        // Only trusted, checked-in SQL is scoped this way; saved text is always parameterized.
        $sql = str_replace('public.' . $table, $schema . '.' . $table, $sql);
        $sql = str_replace('CREATE TABLE ' . $schema, 'CREATE TABLE IF NOT EXISTS ' . $schema, $sql);
        $query($sql);
    }
    if (in_array('core_tts_pronunciation', $missing, true)) {
        require_once __DIR__ . '/tts_pronunciation.php';
        foreach (chimDefaultTtsPronunciationEntries() as $entry) {
            $query("INSERT INTO {$schema}.core_tts_pronunciation(source_text,spoken_text,is_builtin) VALUES($1,$2,true)",
                [$entry['source_text'], $entry['spoken_text']]);
        }
    }
    if (array_intersect($missing, ['oghma_catalogs','oghma_catalog_entries','oghma_catalog_events','oghma_factory_overrides'])) {
        require_once __DIR__ . '/oghma_catalog.php';
        // The catalog manager needs the same connection so savepoints and rollback
        // cover its schema upgrade, article classification and factory projection.
        $database = new class($conn) {
            public function __construct(private $conn) {}
            public function execQuery(string $sql) { return pg_query($this->conn, $sql); }
            public function fetchAll(string $sql): array {
                $result = $this->execQuery($sql);
                if (!$result) throw new RuntimeException(pg_last_error($this->conn));
                return pg_fetch_all($result) ?: [];
            }
            public function fetchOne(string $sql): array { return $this->fetchAll($sql)[0] ?? []; }
            public function escapeLiteral(string $value): string { return pg_escape_literal($this->conn, $value); }
        };
        $hasSource = pg_fetch_result($query("SELECT EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=$1 AND table_name='oghma' AND column_name='source_type')", [$metadata['source_schema']]), 0, 0) === 't';
        if (!$hasSource) $query("UPDATE {$schema}.oghma SET source_type='legacy'");
        $catalog = new ChimOghmaCatalogManager($database, dirname(__DIR__), $stage);
        $catalog->provisionActivePackage(false, true);
    }
    $metadata['content_upgraded'] = true;
    $query('COMMENT ON SCHEMA ' . $schema . ' IS ' . pg_escape_literal($conn, json_encode($metadata, JSON_THROW_ON_ERROR)));
}
