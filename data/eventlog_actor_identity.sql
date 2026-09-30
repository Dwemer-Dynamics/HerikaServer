-- Exact actor keys stored in eventlog.people identity format 2 (docs/actor-identity.md).
-- Mirrors chimIsActorKey()/chimReadStoredEventParticipants() in lib/core/npc_reference.php: an entry
-- yields its key only when it is an object with a valid name and a canonical id. Names may contain '|'.
-- Legacy pipe lists and text jsonb rejects (malformed JSON, \u0000 escapes) return no keys and never
-- raise. Nothing is rewritten; rows keep their stored text. E'' literals keep the
-- regexes independent of standard_conforming_strings; each \x escape ends before a backslash or ].
-- The index depends on this body: never change its results in place; add a new function and index.
CREATE OR REPLACE FUNCTION public.chim_eventlog_actor_keys(people text)
RETURNS text[]
LANGUAGE plpgsql IMMUTABLE
SET search_path = pg_catalog
AS $fn$
DECLARE
    parsed jsonb;
BEGIN
    IF people IS NULL OR left(people, 1) <> '[' THEN
        RETURN '{}'::text[];
    END IF;
    BEGIN
        parsed := people::jsonb;
    EXCEPTION WHEN others THEN
        RETURN '{}'::text[];
    END;
    IF jsonb_typeof(parsed) <> 'array' THEN
        RETURN '{}'::text[];
    END IF;
    RETURN ARRAY(
        SELECT participant.actor_key
        FROM (
            SELECT entry->>'id' AS actor_key, position
            FROM jsonb_array_elements(parsed) WITH ORDINALITY AS item(entry, position)
            WHERE jsonb_typeof(entry) = 'object'
              AND jsonb_typeof(entry->'name') = 'string'
              AND jsonb_typeof(entry->'id') = 'string'
              AND (entry->>'name') !~ E'[\\x01-\\x1f\\x7f]'
              AND btrim(entry->>'name') <> ''
              AND char_length(entry->>'name') <= 256
              AND ((entry->>'id') IN ('player', 'narrator')
                OR ((entry->>'id') ~ '^dyn:[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$'
                    AND (entry->>'id') <> 'dyn:00000000-0000-0000-0000-000000000000')
                OR (entry->>'id') ~ E'^ref:[^ |/\\\\@#:A-Z\\x01-\\x1f\\x7f][^|/\\\\@#:A-Z\\x01-\\x1f\\x7f]*\\.es[mpl]\\|00[0-9A-F]{6}$')
        ) participant
        GROUP BY participant.actor_key
        ORDER BY min(participant.position)
    );
END;
$fn$;

-- Legacy rows stay outside the partial index, so existing installs build it cheaply.
CREATE INDEX IF NOT EXISTS idx_eventlog_actor_keys ON public.eventlog
    USING gin (public.chim_eventlog_actor_keys(people))
    WHERE left(people, 1) = '[';
