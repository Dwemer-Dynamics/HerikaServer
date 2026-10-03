-- Audit relationship state in the same transaction, including direct SQL writers.
-- Resolve the event table in the actor's schema so archived playthroughs stay isolated.
CREATE OR REPLACE FUNCTION public.chim_relationship_eventlog() RETURNS trigger
LANGUAGE plpgsql AS $$
DECLARE
    previous_map jsonb := '{}'::jsonb;
    current_map jsonb := '{}'::jsonb;
    target text;
    previous_value jsonb;
    current_value jsonb;
    field text;
    description text;
    details text;
    target_name text;
    relationship_label text;
    player_name text;
    event_gamets bigint;
    event_ts bigint := floor(extract(epoch FROM clock_timestamp()));
    actor_key text;
    reference_parts text[];
    participants text;
    identity_keys text[];
BEGIN
    -- Loading an existing save restores history; it must not invent new relationship decisions.
    IF current_setting('chim.relationship_eventlog_suspended', true) = 'on' THEN RETURN NEW; END IF;
    IF TG_OP = 'UPDATE' THEN
        previous_map := COALESCE(OLD.extended_data->'relationships', '{}'::jsonb);
    END IF;
    current_map := COALESCE(NEW.extended_data->'relationships', '{}'::jsonb);
    IF jsonb_typeof(previous_map) <> 'object' THEN previous_map := '{}'::jsonb; END IF;
    IF jsonb_typeof(current_map) <> 'object' THEN current_map := '{}'::jsonb; END IF;
    IF previous_map = current_map THEN RETURN NEW; END IF;

    -- Evaluation writes can omit the NPC game clock; use the latest recorded game event.
    EXECUTE format('SELECT COALESCE(MAX(gamets),0) FROM %I.eventlog WHERE type <> ''relationship''', TG_TABLE_SCHEMA)
        INTO event_gamets;
    event_gamets := GREATEST(event_gamets, COALESCE(NEW.gamets_last_updated,0));
    EXECUTE format('SELECT value FROM (
        SELECT value, 1 AS priority FROM %I.core_player WHERE id = ''player_name''
        UNION ALL SELECT value, 2 FROM %I.conf_opts WHERE id = ''PLAYER_NAME''
        ) names WHERE NULLIF(trim(value),'''') IS NOT NULL ORDER BY priority LIMIT 1',
        TG_TABLE_SCHEMA, TG_TABLE_SCHEMA)
        INTO player_name;
    player_name := COALESCE(NULLIF(trim(player_name),''),'Player');
    -- Newer identity-aware installations must attribute the owner by its captured key, not its name.
    IF to_regprocedure('public.chim_eventlog_actor_keys(text)') IS NOT NULL THEN
        reference_parts := regexp_match(NEW.metadata->>'refid_source', '^([^/|]+\.[eE][sS][mMpPlL])[/|]([0-9A-Fa-f]{1,8})$');
        IF reference_parts IS NOT NULL THEN
            actor_key := 'ref:' || lower(reference_parts[1]) || '|' || upper(lpad(reference_parts[2],8,'0'));
        ELSE
            actor_key := CASE WHEN NEW.metadata->>'actor_key' LIKE 'dyn:%' THEN NEW.metadata->>'actor_key' END;
        END IF;
    END IF;

    FOR target IN SELECT jsonb_object_keys(previous_map) UNION SELECT jsonb_object_keys(current_map)
    LOOP
        previous_value := previous_map->target;
        current_value := current_map->target;
        IF previous_value IS NOT DISTINCT FROM current_value THEN CONTINUE; END IF;
        target_name := CASE WHEN lower(target)='player' THEN player_name ELSE target END;
        description := NEW.npc_name || ' → ' || target_name || ': ';
        IF previous_value IS NULL THEN
            relationship_label := CASE lower(current_value->>'type')
                WHEN 'enemy' THEN 'Now considers them enemies'
                WHEN 'friend' THEN 'Now considers them friends'
                ELSE initcap(COALESCE(NULLIF(current_value->>'type',''),'Relationship'))
            END;
            description := description || relationship_label || '.';
        ELSIF current_value IS NULL THEN
            description := description || 'Relationship removed.';
        ELSIF jsonb_typeof(previous_value)='object' AND jsonb_typeof(current_value)='object' THEN
            details := '';
            FOR field IN SELECT jsonb_object_keys(previous_value) UNION SELECT jsonb_object_keys(current_value)
            LOOP
                IF previous_value->field IS DISTINCT FROM current_value->field THEN
                    details := details || CASE WHEN details='' THEN '' ELSE '; ' END
                        || CASE field WHEN 'aff' THEN 'Affinity' WHEN 'type' THEN 'Relationship'
                            WHEN 'note' THEN 'Note' WHEN 'best' THEN 'Best memory'
                            WHEN 'worst' THEN 'Worst memory' WHEN 'relation' THEN 'Connection'
                            ELSE initcap(replace(field,'_',' ')) END
                        || ': ' || COALESCE(previous_value->>field,'none')
                        || ' → ' || COALESCE(current_value->>field,'none');
                END IF;
            END LOOP;
            description := description || details;
        ELSE
            description := description || COALESCE(previous_value::text,'(unset)')
                || ' → ' || COALESCE(current_value::text,'(unset)');
        END IF;
        participants := '|' || replace(NEW.npc_name,'|','') || '|' || replace(target_name,'|','') || '|';
        IF actor_key IS NOT NULL THEN
            participants := jsonb_build_array(jsonb_build_object('id',actor_key,'name',NEW.npc_name))::text;
            EXECUTE 'SELECT public.chim_eventlog_actor_keys($1)' INTO identity_keys USING participants;
            IF cardinality(identity_keys) = 0 THEN
                participants := '|' || replace(NEW.npc_name,'|','') || '|' || replace(target_name,'|','') || '|';
            ELSIF lower(target) = 'player' THEN
                participants := (participants::jsonb || jsonb_build_array(jsonb_build_object('id','player','name',target_name)))::text;
            END IF;
        END IF;
        EXECUTE format('INSERT INTO %I.eventlog (type,data,people,gamets,localts,ts,sess)
            VALUES (''relationship'',$1,$2,$3,$4,$4,'''')', TG_TABLE_SCHEMA)
            USING description, participants, event_gamets, event_ts;
    END LOOP;
    RETURN NEW;
END;
$$;

DROP TRIGGER IF EXISTS trg_chim_relationship_eventlog ON public.core_npc_master;
CREATE TRIGGER trg_chim_relationship_eventlog AFTER INSERT OR UPDATE OF extended_data
ON public.core_npc_master FOR EACH ROW EXECUTE FUNCTION public.chim_relationship_eventlog();
