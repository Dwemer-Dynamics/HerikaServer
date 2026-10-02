-- Exact audience and provenance for memories (docs/actor-identity.md, "Memories").
-- Additive and idempotent; requires data/eventlog_actor_identity.sql. NULL audience means the row predates
-- identity or was not captured: it stays stored and displayable but is never attributed by a name.

-- Sorted exact keys of a format-2 audience. NULL for text that is not a format-2 JSON array (legacy pipe
-- lists, bare names, malformed JSON), so a captured empty audience ('[]' -> '{}') stays distinct from
-- missing. Key validation is chim_eventlog_actor_keys(); this only adds the format test and the sort.
CREATE OR REPLACE FUNCTION public.chim_identity_audience_keys(people text)
RETURNS text[]
LANGUAGE plpgsql IMMUTABLE
SET search_path = pg_catalog
AS $fn$
DECLARE
    parsed jsonb;
BEGIN
    IF people IS NULL OR left(people, 1) <> '[' THEN
        RETURN NULL;
    END IF;
    BEGIN
        parsed := people::jsonb;
    EXCEPTION WHEN others THEN
        RETURN NULL;
    END;
    IF jsonb_typeof(parsed) <> 'array' THEN
        RETURN NULL;
    END IF;
    RETURN ARRAY(SELECT k FROM unnest(public.chim_eventlog_actor_keys(people)) AS k ORDER BY k COLLATE "C");
END;
$fn$;

-- speech.audience: the client's captured format-2 audience for _speech ('[]' when captured empty).
-- companions keeps the legacy display list exactly as received.
ALTER TABLE public.speech ADD COLUMN IF NOT EXISTS audience text;
-- memory.audience: format-2 audience of a raw memory row; owner_key: the physical key of the actor the
-- memory belongs to (diaries). Both NULL for legacy rows; never backfilled from speaker names.
ALTER TABLE public.memory ADD COLUMN IF NOT EXISTS audience text;
ALTER TABLE public.memory ADD COLUMN IF NOT EXISTS owner_key text;
-- memory_summary: audience_keys are the exact sorted keys every source row shares (NULL = legacy,
-- unresolved). source_refs lists [{"t": source_table, "id": source_rowid}] of the packed rows.
ALTER TABLE public.memory_summary ADD COLUMN IF NOT EXISTS audience_keys text[];
ALTER TABLE public.memory_summary ADD COLUMN IF NOT EXISTS source_refs jsonb;
ALTER TABLE public.memory_summary ADD COLUMN IF NOT EXISTS partition_key text;
CREATE INDEX IF NOT EXISTS idx_memory_summary_audience_keys ON public.memory_summary
    USING gin (audience_keys) WHERE audience_keys IS NOT NULL;

-- Appends provenance columns to the existing memory_v column list (CREATE OR REPLACE VIEW keeps the
-- original six). uid values overlap across source tables, so rows are identified by
-- (source_table, source_rowid) and combined with UNION ALL.
CREATE OR REPLACE VIEW public.memory_v AS
 SELECT message, uid, gamets, speaker, listener, ts,
        source_table, source_rowid, audience, audience_keys, speaker_key, listener_keys
   FROM ( SELECT memory.message,
            memory.uid,
            memory.gamets,
            '-'::text AS speaker,
            '-'::text AS listener,
            memory.ts,
            'memory'::text AS source_table,
            memory.rowid::bigint AS source_rowid,
            memory.audience,
            COALESCE(public.chim_identity_audience_keys(memory.audience),
                CASE WHEN memory.owner_key IS NOT NULL THEN ARRAY[memory.owner_key] END) AS audience_keys,
            NULL::text AS speaker_key,
            NULL::text AS listener_keys
           FROM public.memory
          WHERE memory.message !~~ 'Dear Diary%'::text AND memory.message <> ''::text AND memory.event <> 'backgroundlife_diary'::text
        UNION ALL
         SELECT (((('(Context Location:'::text || speech.location) || ') '::text) || speech.speaker) || ': '::text) || speech.speech,
            speech.rowid::integer AS rowid,
            speech.gamets,
            speech.speaker,
            speech.listener,
            speech.ts,
            'speech'::text,
            speech.rowid::bigint,
            speech.audience,
            public.chim_identity_audience_keys(speech.audience),
            speech.speaker_key,
            speech.listener_keys
           FROM public.speech
          WHERE speech.speech <> ''::text
        UNION ALL
         SELECT eventlog.data,
            eventlog.rowid::integer AS rowid,
            eventlog.gamets,
            '-'::text,
            '-'::text,
            eventlog.ts,
            'eventlog'::text,
            eventlog.rowid::bigint,
            CASE WHEN left(eventlog.people, 1) = '[' THEN eventlog.people END,
            public.chim_identity_audience_keys(eventlog.people),
            eventlog.speaker_key,
            eventlog.listener_keys
           FROM public.eventlog
          WHERE eventlog.type::text = ANY (ARRAY['death'::text, 'location'::text])) subquery
  ORDER BY gamets, ts;
