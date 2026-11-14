<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once(
    __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..'
    . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'playthrough_schema.php'
);

final class SchemaCloneIdentityTest extends TestCase
{
    public function testLegacyCloneFunctionNeedsRefresh(): void
    {
        $legacyDefinition = 'INSERT INTO destination SELECT * FROM source';

        $this->assertFalse(pts_clone_function_is_current($legacyDefinition));
    }

    public function testIdentityOnlyCloneFunctionNeedsSequenceRefresh(): void
    {
        $identityOnlyDefinition =
            'INSERT INTO destination OVERRIDING SYSTEM VALUE SELECT * FROM source';

        $this->assertFalse(pts_clone_function_is_current($identityOnlyDefinition));
    }

    public function testSelectiveCloneFunctionIsRequired(): void
    {
        $currentDefinition = <<<'SQL'
INSERT INTO destination OVERRIDING SYSTEM VALUE SELECT * FROM source;
PERFORM chim_meta.sync_schema_sequences(dest_schema);
SQL;

        $this->assertFalse(pts_clone_function_is_current($currentDefinition));
        $this->assertTrue(pts_clone_function_is_current('PERFORM chim_meta.clone_selected_schema(source_schema, dest_schema, NULL);'));
    }

    public function testRequestLogsAreSavedAsStructureOnly(): void
    {
        // Saves keep the table for compatibility, so it stays selected, but its rows stay live.
        $this->assertSame(['audit_request'], pts_live_row_tables());
        $this->assertContains('audit_request', pts_playthrough_tables());
        $this->assertSame(
            "CREATE OR REPLACE FUNCTION chim_meta.playthrough_live_row_tables() RETURNS text[] LANGUAGE sql IMMUTABLE AS 'SELECT ARRAY[''audit_request'']::text[]';",
            pts_live_row_tables_sql()
        );
        foreach (pts_live_row_tables() as $table) {
            $this->assertContains($table, pts_table_policy()['playthrough']);
        }
    }

    public function testViewCloningFunctionNeedsRefresh(): void
    {
        $legacyDefinition = <<<'SQL'
INSERT INTO destination OVERRIDING SYSTEM VALUE SELECT * FROM source;
PERFORM chim_meta.sync_schema_sequences(dest_schema);
EXECUTE format('CREATE OR REPLACE VIEW %I.%I AS %s', dest_schema, obj.table_name, obj.view_definition);
SQL;

        $this->assertFalse(pts_clone_function_is_current($legacyDefinition));
    }

    public function testSchemaClonePreservesGeneratedIdentityValues(): void
    {
        $sql = file_get_contents(
            __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..'
            . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'schema_clone_function.sql'
        );

        $this->assertIsString($sql);
        $this->assertStringNotContainsString('CREATE OR REPLACE VIEW', $sql);
        $this->assertStringContainsString(
            'INSERT INTO %I.%I (%s) OVERRIDING SYSTEM VALUE SELECT %s FROM %I.%I',
            $sql
        );
        $this->assertStringContainsString('sync_schema_sequences', $sql);
        $this->assertStringContainsString("dependency.deptype IN ('a', 'i')", $sql);
        $this->assertStringContainsString(
            'PERFORM chim_meta.sync_schema_sequences(dest_schema)',
            $sql
        );
        $this->assertStringContainsString(
            'SELECT last_value, is_called FROM %I.%I',
            $sql
        );
        $this->assertStringContainsString(
            'THEN source_last_value + obj.increment_by',
            $sql
        );
        $this->assertStringContainsString(
            "SELECT setval(''%I.%I'', %s, false)",
            $sql
        );
    }

    public function testDatabaseUpdateRepairsExistingPublicSequences(): void
    {
        $updates = file_get_contents(
            __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..'
            . DIRECTORY_SEPARATOR . 'debug' . DIRECTORY_SEPARATOR . 'db_updates.php'
        );

        $this->assertIsString($updates);
        $this->assertStringContainsString(
            "SELECT chim_meta.sync_schema_sequences('public')",
            $updates
        );
        $this->assertStringContainsString(
            '$updateVersion("playthrough_schema", 20260723001)',
            $updates
        );
    }
}
