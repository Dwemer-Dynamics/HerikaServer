-- Upgrade a private working copy. Never run db_updates.php here: its public
-- schema writes and library seeds are not scoped to a saved playthrough.
CREATE OR REPLACE FUNCTION chim_meta.prepare_playthrough(source_schema text, selected_tables text[])
RETURNS text AS $$
DECLARE
    stage_schema text := 'chim_profile_upgrade_' || pg_backend_pid() || '_' || txid_current();
    source_names text[];
    live_names text[];
    manifest_text text;
    manifest jsonb;
    table_name text;
    item record;
    target_type text;
    default_sql text;
    saved_version bigint;
    required_version bigint;
    version_key text;
    missing_tables text[] := '{}';
    all_source_names text[];
    sequence_name text;
    sequence_info record;
BEGIN
    IF source_schema !~ '^chim_profile_[a-z0-9_]+$' OR source_schema = stage_schema THEN
        RAISE EXCEPTION 'Invalid playthrough schema';
    END IF;
    SELECT array_agg(tablename ORDER BY tablename) INTO source_names
    FROM pg_tables WHERE schemaname=source_schema AND tablename=ANY(selected_tables);
    SELECT array_agg(tablename ORDER BY tablename) INTO live_names
    FROM pg_tables WHERE schemaname='public' AND tablename=ANY(selected_tables);
    IF source_names IS NULL OR live_names IS NULL OR NOT ('eventlog'=ANY(source_names)) THEN
        RAISE EXCEPTION 'Playthrough source tables are unavailable';
    END IF;
    EXECUTE format('LOCK TABLE %s IN SHARE MODE',
        (SELECT string_agg(format('%I.%I', source_schema, name), ', ' ORDER BY name) FROM unnest(source_names) name));
    SELECT array_agg(tablename ORDER BY tablename) INTO all_source_names FROM pg_tables WHERE schemaname=source_schema;
    PERFORM chim_meta.validate_save_manifest(source_schema, all_source_names);
    -- CREATE, not replacement: an unexpected name collision must leave it intact.
    EXECUTE format('CREATE SCHEMA %I', stage_schema);
    PERFORM chim_meta.clone_selected_schema(source_schema, stage_schema, source_names);

    FOREACH table_name IN ARRAY live_names LOOP
        IF NOT (table_name=ANY(source_names)) THEN
            SELECT v.version_key, v.version INTO version_key, required_version FROM (VALUES
                ('bgl_history','bgl_history',20260623001::bigint),
                ('market_cache','market_cache',20260805001::bigint),
                ('core_tts_fallback','core_tts_fallback',20260727001::bigint),
                ('core_tts_pronunciation','core_tts_pronunciation',20260829003::bigint),
                ('faction_vanilla','faction_vanilla',20260803001::bigint),
                ('profile_settings_presets','profile_settings_presets',20260824001::bigint),
                ('oghma_audit','oghma_catalog',20260827001::bigint),
                ('oghma_catalogs','oghma_catalog',20260827001::bigint),
                ('oghma_catalog_entries','oghma_catalog',20260827001::bigint),
                ('oghma_catalog_events','oghma_catalog',20260827001::bigint),
                ('oghma_factory_overrides','oghma_catalog',20260827001::bigint)
            ) v(name,version_key,version) WHERE v.name=table_name;
            IF required_version IS NULL THEN
                RAISE EXCEPTION 'Snapshot is missing table %; no safe upgrade is available', table_name;
            END IF;
            SELECT obj_description(oid,'pg_namespace')::jsonb INTO manifest FROM pg_namespace WHERE nspname=source_schema;
            IF to_regclass(format('%I.database_versioning',source_schema)) IS NOT NULL THEN
                EXECUTE format('SELECT coalesce(max(version),0) FROM %I.database_versioning WHERE tablename=$1',source_schema)
                    INTO saved_version USING version_key;
            ELSIF manifest ? 'migrations' THEN
                saved_version := coalesce((manifest->'migrations'->>version_key)::bigint,0);
            ELSE
                RAISE EXCEPTION 'Snapshot has no migration history for missing table %',table_name;
            END IF;
            IF saved_version >= required_version THEN
                RAISE EXCEPTION 'Snapshot is missing table %, which already existed when it was saved',table_name;
            END IF;
            EXECUTE format('CREATE TABLE %I.%I (LIKE public.%I INCLUDING ALL)',stage_schema,table_name,table_name);
            -- LIKE gives identity columns their own sequences, but serial defaults still
            -- point at public. Give newly introduced serial columns private sequences.
            FOR item IN SELECT a.attname, pg_get_serial_sequence(format('public.%I',table_name),a.attname) AS seq
                FROM pg_attribute a WHERE a.attrelid=format('public.%I',table_name)::regclass
                AND a.attnum>0 AND NOT a.attisdropped AND a.attidentity='' LOOP
                IF item.seq IS NULL THEN CONTINUE; END IF;
                SELECT * INTO sequence_info FROM pg_sequence WHERE seqrelid=item.seq::regclass;
                sequence_name := table_name || '_' || item.attname || '_seq';
                EXECUTE format('CREATE SEQUENCE %I.%I AS %s INCREMENT %s MINVALUE %s MAXVALUE %s START %s',
                    stage_schema,sequence_name,format_type(sequence_info.seqtypid,NULL),sequence_info.seqincrement,
                    sequence_info.seqmin,sequence_info.seqmax,sequence_info.seqstart);
                EXECUTE format('ALTER SEQUENCE %I.%I OWNED BY %I.%I.%I',stage_schema,sequence_name,stage_schema,table_name,item.attname);
                EXECUTE format('ALTER TABLE %I.%I ALTER COLUMN %I SET DEFAULT nextval(%L::regclass)',
                    stage_schema,table_name,item.attname,format('%I.%I',stage_schema,sequence_name));
            END LOOP;
            missing_tables := array_append(missing_tables,table_name);
        END IF;
        -- The saved ledger describes the source; do not pretend every historical
        -- content migration ran, or replace the installed server's version ledger.
        IF table_name='database_versioning' THEN CONTINUE; END IF;

        FOR item IN
            SELECT src.attname, src.atttypid, src.atttypmod,
                dst.atttypid AS target_oid, dst.atttypmod AS target_mod
            FROM pg_attribute src LEFT JOIN pg_attribute dst
                ON dst.attrelid=format('public.%I', table_name)::regclass AND dst.attname=src.attname
                AND dst.attnum>0 AND NOT dst.attisdropped
            WHERE src.attrelid=format('%I.%I', stage_schema, table_name)::regclass
                AND src.attnum>0 AND NOT src.attisdropped
        LOOP
            IF item.target_oid IS NULL THEN
                RAISE EXCEPTION 'Saved column %.% no longer exists in the current tables; a dedicated snapshot migration is required', table_name, item.attname;
            END IF;
            IF item.atttypid=item.target_oid AND item.atttypmod=item.target_mod THEN CONTINUE; END IF;
            -- Reviewed, lossless conversions from db_updates.php:
            -- rolemaster 20250528001, memory 20260617001,
            -- eventlog_session_payload 20260807001.
            IF NOT (
                (table_name='memory' AND item.attname='localts'
                    AND item.atttypid IN ('smallint'::regtype, 'integer'::regtype) AND item.target_oid='bigint'::regtype)
                OR (table_name='responselog' AND item.attname IN ('actor','action','text')
                    AND item.atttypid='varchar'::regtype AND item.target_oid='text'::regtype)
                OR (table_name='eventlog' AND item.attname='sess'
                    AND item.atttypid IN ('varchar'::regtype, 'smallint'::regtype, 'integer'::regtype, 'bigint'::regtype)
                    AND item.target_oid='text'::regtype)
            ) THEN
                RAISE EXCEPTION 'Saved column %.% has an unsupported type change', table_name, item.attname;
            END IF;
            target_type := format_type(item.target_oid, item.target_mod);
            EXECUTE format('ALTER TABLE %I.%I ALTER COLUMN %I TYPE %s USING %I::%s',
                stage_schema, table_name, item.attname, target_type, item.attname, target_type);
        END LOOP;

        -- Fill new columns on the working copy using current declared defaults.
        -- Reject defaults with external dependencies (sequences or application
        -- functions) rather than executing them against the live playthrough.
        FOR item IN
            SELECT dst.*, def.oid AS default_oid, pg_get_expr(def.adbin, def.adrelid) AS expression
            FROM pg_attribute dst LEFT JOIN pg_attrdef def ON def.adrelid=dst.attrelid AND def.adnum=dst.attnum
            WHERE dst.attrelid=format('public.%I', table_name)::regclass
                AND dst.attnum>0 AND NOT dst.attisdropped AND NOT EXISTS (
                    SELECT 1 FROM pg_attribute src
                    WHERE src.attrelid=format('%I.%I', stage_schema, table_name)::regclass
                        AND src.attname=dst.attname AND src.attnum>0 AND NOT src.attisdropped)
            ORDER BY dst.attnum
        LOOP
            -- Text-built sequence names have no pg_depend entry; reject those
            -- calls too, since nextval/setval changes cannot be rolled back.
            IF item.attidentity<>'' OR item.expression ~* '\m(nextval|setval)\s*\(' OR EXISTS (
                SELECT 1 FROM pg_depend d
                LEFT JOIN pg_proc p ON d.refclassid='pg_proc'::regclass AND p.oid=d.refobjid
                WHERE d.classid='pg_attrdef'::regclass AND d.objid=item.default_oid
                    AND ((d.refclassid='pg_class'::regclass AND d.refobjid<>item.attrelid)
                        OR (p.oid IS NOT NULL AND p.pronamespace<>'pg_catalog'::regnamespace))
            ) THEN
                RAISE EXCEPTION 'New column %.% needs a dedicated snapshot migration', table_name, item.attname;
            END IF;
            default_sql := CASE WHEN item.attgenerated<>'' THEN ' GENERATED ALWAYS AS (' || item.expression || ') STORED'
                WHEN item.expression IS NOT NULL THEN ' DEFAULT ' || item.expression ELSE '' END;
            EXECUTE format('ALTER TABLE %I.%I ADD COLUMN %I %s%s%s', stage_schema, table_name, item.attname,
                format_type(item.atttypid, item.atttypmod), default_sql, CASE WHEN item.attnotnull THEN ' NOT NULL' ELSE '' END);
        END LOOP;
    END LOOP;
    IF EXISTS (
        SELECT 1 FROM pg_depend d JOIN pg_attrdef a ON d.classid='pg_attrdef'::regclass AND d.objid=a.oid
        JOIN pg_class t ON t.oid=a.adrelid JOIN pg_namespace tn ON tn.oid=t.relnamespace
        JOIN pg_class s ON s.oid=d.refobjid JOIN pg_namespace sn ON sn.oid=s.relnamespace
        WHERE s.relkind='S' AND tn.nspname=stage_schema AND sn.nspname<>stage_schema
    ) THEN RAISE EXCEPTION 'Snapshot sequence defaults still reference another schema'; END IF;

    EXECUTE format('COMMENT ON SCHEMA %I IS %L',stage_schema,
        jsonb_build_object('format','chim_selected_tables_v2','table_policy_version',2,
            'tables',live_names,'missing_tables',missing_tables,'source_schema',source_schema,
            'upgrade_version',2)::text);
    RETURN stage_schema;
END;
$$ LANGUAGE plpgsql SET lock_timeout = '10s';

-- Exercise the real constraint/sequence checks inside a rolled-back subtransaction.
-- No restored rows become visible; application triggers stay disabled as in activation.
CREATE OR REPLACE FUNCTION chim_meta.validate_playthrough(source_schema text, selected_tables text[])
RETURNS void AS $$
BEGIN
    BEGIN
        PERFORM chim_meta.restore_playthrough(source_schema,selected_tables);
        RAISE EXCEPTION USING ERRCODE='PZ001', MESSAGE='playthrough validation completed';
    EXCEPTION WHEN SQLSTATE 'PZ001' THEN
        NULL;
    END;
END;
$$ LANGUAGE plpgsql;

-- Retain the SQL API for structural upgrades. Content upgrades use the PHP
-- coordinator so bundled seed/catalog files are validated before activation.
CREATE OR REPLACE FUNCTION chim_meta.restore_playthrough_upgraded(source_schema text, selected_tables text[])
RETURNS void AS $$
DECLARE stage_schema text; missing jsonb;
BEGIN
    stage_schema := chim_meta.prepare_playthrough(source_schema,selected_tables);
    SELECT obj_description(oid,'pg_namespace')::jsonb->'missing_tables' INTO missing FROM pg_namespace WHERE nspname=stage_schema;
    IF EXISTS(SELECT 1 FROM jsonb_array_elements_text(missing) t(name)
        WHERE name NOT IN ('bgl_history','market_cache','profile_settings_presets','oghma_audit')) THEN
        RAISE EXCEPTION 'This save needs a content upgrade through Playthrough Manager';
    END IF;
    PERFORM chim_meta.restore_playthrough(stage_schema,selected_tables);
    EXECUTE format('DROP SCHEMA %I CASCADE',stage_schema);
END;
$$ LANGUAGE plpgsql SET lock_timeout = '10s';

CREATE OR REPLACE FUNCTION chim_meta.playthrough_api_version()
RETURNS integer LANGUAGE sql IMMUTABLE AS 'SELECT 3';
