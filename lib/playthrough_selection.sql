-- Snapshot and restore one explicit table policy without replacing shared tables.
CREATE OR REPLACE FUNCTION chim_meta.capture_playthrough(dest_schema text, selected_tables text[])
RETURNS void AS $$
DECLARE
    names text[];
    lock_list text;
BEGIN
    IF dest_schema !~ '^chim_profile_[a-z0-9_]+$' THEN
        RAISE EXCEPTION 'Invalid playthrough schema';
    END IF;
    SELECT array_agg(tablename ORDER BY tablename), string_agg(format('public.%I', tablename), ', ' ORDER BY tablename)
    INTO names, lock_list FROM pg_tables WHERE schemaname = 'public' AND tablename = ANY(selected_tables);
    IF names IS NULL OR NOT ('eventlog' = ANY(names)) THEN
        RAISE EXCEPTION 'Playthrough source tables are unavailable';
    END IF;
    -- Take all read locks before copying so related tables describe one timeline.
    EXECUTE 'LOCK TABLE ' || lock_list || ' IN SHARE MODE';
    IF NOT chim_meta.drop_schema_safe(dest_schema) THEN
        RAISE EXCEPTION 'Cannot replace playthrough schema';
    END IF;
    PERFORM chim_meta.clone_selected_schema('public', dest_schema, names);
    IF EXISTS (
        SELECT 1 FROM pg_depend d JOIN pg_attrdef a ON d.classid='pg_attrdef'::regclass AND d.objid=a.oid
        JOIN pg_class t ON t.oid=a.adrelid JOIN pg_namespace tn ON tn.oid=t.relnamespace
        JOIN pg_class s ON s.oid=d.refobjid JOIN pg_namespace sn ON sn.oid=s.relnamespace
        WHERE s.relkind='S' AND tn.nspname=dest_schema AND sn.nspname<>dest_schema
    ) THEN RAISE EXCEPTION 'Snapshot sequence defaults still reference another schema'; END IF;
    EXECUTE format('COMMENT ON SCHEMA %I IS %L', dest_schema,
        jsonb_build_object('format', 'chim_selected_tables_v1', 'tables', names)::text);
END;
$$ LANGUAGE plpgsql SET lock_timeout = '10s';

-- Restore rows in place, keeping excluded tables, views, triggers and table identities.
CREATE OR REPLACE FUNCTION chim_meta.restore_playthrough(source_schema text, selected_tables text[])
RETURNS void AS $$
DECLARE
    names text[];
    source_names text[];
    lock_list text;
    columns_sql text;
    saved_constraints jsonb;
    saved_triggers jsonb;
    item record;
    entry jsonb;
    manifest_text text;
    manifest jsonb;
    source_sequence text;
    next_value bigint;
    last_value_saved bigint;
    called_saved boolean;
    boundary bigint;
    sequence_restarts jsonb := '{}'::jsonb;
BEGIN
    IF source_schema !~ '^chim_profile_[a-z0-9_]+$' THEN
        RAISE EXCEPTION 'Invalid playthrough schema';
    END IF;
    SELECT array_agg(tablename ORDER BY tablename) INTO names
    FROM pg_tables WHERE schemaname = 'public' AND tablename = ANY(selected_tables);
    SELECT array_agg(tablename ORDER BY tablename) INTO source_names
    FROM pg_tables WHERE schemaname = source_schema AND tablename = ANY(selected_tables);
    IF names IS NULL OR source_names IS DISTINCT FROM names OR NOT ('eventlog' = ANY(names)) THEN
        RAISE EXCEPTION 'Snapshot tables do not match this server schema; restore cancelled';
    END IF;
    SELECT obj_description(oid, 'pg_namespace') INTO manifest_text FROM pg_namespace WHERE nspname = source_schema;
    IF manifest_text IS NOT NULL THEN
        manifest := manifest_text::jsonb;
        IF manifest->>'format' IS DISTINCT FROM 'chim_selected_tables_v1'
            OR manifest->'tables' IS DISTINCT FROM to_jsonb(source_names) THEN
            RAISE EXCEPTION 'Snapshot manifest does not match its tables';
        END IF;
    END IF;
    -- Keep the installed migration ledger: restoring old markers can re-run seed
    -- migrations against shared libraries. Its saved copy is compatibility metadata.
    names := array_remove(names, 'database_versioning');
    -- Include referencing tables in the lock set before changing any constraints.
    SELECT string_agg(rel, ', ' ORDER BY rel) INTO lock_list FROM (
        SELECT format('%I.%I', schemaname, tablename) AS rel FROM pg_tables WHERE schemaname = 'public'
        UNION
        SELECT conrelid::regclass::text FROM pg_constraint
        WHERE contype = 'f' AND confrelid IN (
            SELECT c.oid FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace
            WHERE n.nspname='public' AND c.relname=ANY(names))
    ) locked;
    EXECUTE 'LOCK TABLE ' || lock_list || ' IN ACCESS EXCLUSIVE MODE';
    EXECUTE format('LOCK TABLE %s IN SHARE MODE',
        (SELECT string_agg(format('%I.%I', source_schema, name), ', ' ORDER BY name) FROM unnest(names) name));
    SELECT jsonb_agg(jsonb_build_object('table', conrelid::regclass::text, 'name', conname,
        'definition', pg_get_constraintdef(oid), 'validated', convalidated)) INTO saved_constraints
    FROM pg_constraint WHERE contype='f' AND (conrelid IN (
        SELECT c.oid FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace
        WHERE n.nspname='public' AND c.relname=ANY(names)) OR confrelid IN (
        SELECT c.oid FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace
        WHERE n.nspname='public' AND c.relname=ANY(names)));
    SELECT jsonb_agg(jsonb_build_object('table', tgrelid::regclass::text, 'name', tgname, 'enabled', tgenabled))
    INTO saved_triggers FROM pg_trigger WHERE NOT tgisinternal AND tgrelid IN (
        SELECT c.oid FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace
        WHERE n.nspname='public' AND c.relname=ANY(names));
    FOR entry IN SELECT value FROM jsonb_array_elements(saved_constraints) LOOP
        EXECUTE format('ALTER TABLE %s DROP CONSTRAINT %I', entry->>'table', entry->>'name');
    END LOOP;
    FOR entry IN SELECT value FROM jsonb_array_elements(saved_triggers) LOOP
        EXECUTE format('ALTER TABLE %s DISABLE TRIGGER %I', entry->>'table', entry->>'name');
    END LOOP;
    FOREACH lock_list IN ARRAY names LOOP
        -- Reject removed/changed columns instead of silently losing saved data.
        IF EXISTS (
            SELECT 1 FROM pg_attribute src LEFT JOIN pg_attribute dst
              ON dst.attrelid=format('public.%I', lock_list)::regclass AND dst.attname=src.attname
              AND dst.attnum>0 AND NOT dst.attisdropped
            WHERE src.attrelid=format('%I.%I', source_schema, lock_list)::regclass
              AND src.attnum>0 AND NOT src.attisdropped
              AND (dst.attname IS NULL OR src.atttypid<>dst.atttypid OR src.atttypmod<>dst.atttypmod)
        ) THEN RAISE EXCEPTION 'Incompatible saved columns for %', lock_list; END IF;
        SELECT string_agg(format('%I', src.attname), ', ' ORDER BY src.attnum) INTO columns_sql
        FROM pg_attribute src JOIN pg_attribute dst ON dst.attrelid=format('public.%I', lock_list)::regclass
          AND dst.attname=src.attname AND dst.attnum>0 AND NOT dst.attisdropped AND dst.attgenerated=''
        WHERE src.attrelid=format('%I.%I', source_schema, lock_list)::regclass
          AND src.attnum>0 AND NOT src.attisdropped;
        EXECUTE format('DELETE FROM public.%I', lock_list);
        EXECUTE format('INSERT INTO public.%I (%s) OVERRIDING SYSTEM VALUE SELECT %s FROM %I.%I',
            lock_list, columns_sql, columns_sql, source_schema, lock_list);
    END LOOP;
    -- Revalidate all formerly valid FKs, including excluded tables referencing restored IDs.
    FOR entry IN SELECT value FROM jsonb_array_elements(saved_constraints) LOOP
        EXECUTE format('ALTER TABLE %s ADD CONSTRAINT %I %s',
            entry->>'table', entry->>'name', entry->>'definition');
    END LOOP;
    FOR entry IN SELECT value FROM jsonb_array_elements(saved_triggers) LOOP
        EXECUTE format('ALTER TABLE %s %s TRIGGER %I', entry->>'table',
            CASE entry->>'enabled' WHEN 'D' THEN 'DISABLE' WHEN 'A' THEN 'ENABLE ALWAYS'
                WHEN 'R' THEN 'ENABLE REPLICA' ELSE 'ENABLE' END, entry->>'name');
    END LOOP;
    FOR item IN
        SELECT t.relname AS table_name, a.attname AS column_name, s.oid::regclass::text AS sequence_name,
            q.seqincrement AS increment_by
        FROM pg_depend d JOIN pg_class s ON s.oid=d.objid JOIN pg_sequence q ON q.seqrelid=s.oid
        JOIN pg_class t ON t.oid=d.refobjid JOIN pg_namespace n ON n.oid=t.relnamespace
        JOIN pg_attribute a ON a.attrelid=t.oid AND a.attnum=d.refobjsubid
        WHERE d.classid='pg_class'::regclass AND d.refclassid='pg_class'::regclass
          AND d.deptype IN ('a','i') AND n.nspname='public' AND t.relname=ANY(names)
        UNION
        SELECT t.relname, a.attname, s.oid::regclass::text, q.seqincrement
        FROM pg_depend d JOIN pg_attrdef def ON d.classid='pg_attrdef'::regclass AND d.objid=def.oid
        JOIN pg_class s ON s.oid=d.refobjid JOIN pg_sequence q ON q.seqrelid=s.oid
        JOIN pg_class t ON t.oid=def.adrelid JOIN pg_namespace n ON n.oid=t.relnamespace
        JOIN pg_attribute a ON a.attrelid=t.oid AND a.attnum=def.adnum
        WHERE d.refclassid='pg_class'::regclass AND n.nspname='public' AND t.relname=ANY(names)
    LOOP
        source_sequence := pg_get_serial_sequence(format('%I.%I', source_schema, item.table_name), item.column_name);
        IF source_sequence IS NULL THEN
            SELECT s.oid::regclass::text INTO source_sequence
            FROM pg_depend d JOIN pg_attrdef def ON d.classid='pg_attrdef'::regclass AND d.objid=def.oid
            JOIN pg_class s ON s.oid=d.refobjid JOIN pg_attribute a ON a.attrelid=def.adrelid AND a.attnum=def.adnum
            WHERE d.refclassid='pg_class'::regclass AND s.relkind='S'
              AND def.adrelid=format('%I.%I', source_schema, item.table_name)::regclass AND a.attname=item.column_name;
        END IF;
        IF source_sequence IS NULL THEN RAISE EXCEPTION 'Missing saved sequence for %.%', item.table_name, item.column_name; END IF;
        EXECUTE format('SELECT last_value, is_called FROM %s', source_sequence) INTO last_value_saved, called_saved;
        next_value := last_value_saved + CASE WHEN called_saved THEN item.increment_by ELSE 0 END;
        EXECUTE format('SELECT %s(%I) FROM public.%I', CASE WHEN item.increment_by>0 THEN 'max' ELSE 'min' END,
            item.column_name, item.table_name) INTO boundary;
        IF boundary IS NOT NULL THEN
            next_value := CASE WHEN item.increment_by>0 THEN greatest(next_value, boundary+item.increment_by)
                ELSE least(next_value, boundary+item.increment_by) END;
        END IF;
        -- One sequence can feed several tables (speech and moods_issued do this).
        IF sequence_restarts ? item.sequence_name THEN
            next_value := CASE WHEN item.increment_by>0
                THEN greatest(next_value, (sequence_restarts->>item.sequence_name)::bigint)
                ELSE least(next_value, (sequence_restarts->>item.sequence_name)::bigint) END;
        END IF;
        -- A shared/excluded consumer must not have its sequence wound backwards.
        IF EXISTS (
            SELECT 1 FROM pg_depend d JOIN pg_attrdef def ON d.classid='pg_attrdef'::regclass AND d.objid=def.oid
            JOIN pg_class t ON t.oid=def.adrelid JOIN pg_namespace n ON n.oid=t.relnamespace
            WHERE d.refobjid=item.sequence_name::regclass AND d.refclassid='pg_class'::regclass
              AND (n.nspname<>'public' OR NOT (t.relname=ANY(names)))
        ) THEN
            EXECUTE format('SELECT last_value, is_called FROM %s', item.sequence_name) INTO last_value_saved, called_saved;
            next_value := CASE WHEN item.increment_by>0
                THEN greatest(next_value, last_value_saved + CASE WHEN called_saved THEN item.increment_by ELSE 0 END)
                ELSE least(next_value, last_value_saved + CASE WHEN called_saved THEN item.increment_by ELSE 0 END) END;
        END IF;
        sequence_restarts := sequence_restarts || jsonb_build_object(item.sequence_name, next_value);
    END LOOP;
    FOR item IN SELECT key, value FROM jsonb_each_text(sequence_restarts) LOOP
        -- Unlike setval(), RESTART is transactional and rolls back with a failed activation.
        EXECUTE format('ALTER SEQUENCE %s RESTART WITH %s', item.key, item.value::bigint);
    END LOOP;
END;
$$ LANGUAGE plpgsql SET lock_timeout = '10s';
-- Keep pgAdmin labels aligned with the same explicit list used for capture.
CREATE OR REPLACE FUNCTION chim_meta.sync_playthrough_comments(selected_tables text[])
RETURNS void AS $$
DECLARE item record;
BEGIN
    FOR item IN
        SELECT c.relname, CASE WHEN c.relname=ANY(selected_tables)
            THEN 'Playthrough Manager Backed Up' ELSE NULL END AS expected
        FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace
        WHERE n.nspname='public' AND c.relkind IN ('r','p')
            AND obj_description(c.oid,'pg_class') IS DISTINCT FROM
                CASE WHEN c.relname=ANY(selected_tables) THEN 'Playthrough Manager Backed Up' ELSE NULL END
    LOOP
        EXECUTE format('COMMENT ON TABLE public.%I IS %L', item.relname, item.expected);
    END LOOP;
END;
$$ LANGUAGE plpgsql SET lock_timeout = '10s';
