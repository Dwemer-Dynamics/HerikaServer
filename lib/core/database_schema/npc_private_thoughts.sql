CREATE TABLE IF NOT EXISTS public.npc_private_thoughts (
    id bigserial PRIMARY KEY,
    npc_id integer NOT NULL,
    npc_refid text NOT NULL,
    npc_name text NOT NULL,
    request_key text NOT NULL,
    gamets bigint NOT NULL,
    localts bigint NOT NULL,
    thought text NOT NULL CHECK (char_length(thought) BETWEEN 1 AND 600),
    UNIQUE (npc_id, npc_refid, request_key)
);
CREATE INDEX IF NOT EXISTS npc_private_thoughts_owner_time
    ON public.npc_private_thoughts (npc_id, npc_refid, gamets DESC, id DESC);
