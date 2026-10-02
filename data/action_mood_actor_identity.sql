-- Exact owner key for issued actions and moods (docs/actor-identity.md). Nullable and additive:
-- rows written before this migration, or by name-only callers, keep NULL and are never adopted by a
-- keyed read. Idempotent; playthrough restores refill these tables in place and keep the indexes.
ALTER TABLE public.actions_issued ADD COLUMN IF NOT EXISTS actor_key text;
ALTER TABLE public.moods_issued ADD COLUMN IF NOT EXISTS actor_key text;
CREATE INDEX IF NOT EXISTS idx_actions_issued_actor_key ON public.actions_issued (actor_key, gamets DESC, ts DESC) WHERE actor_key IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_moods_issued_actor_key ON public.moods_issued (actor_key, gamets DESC) WHERE actor_key IS NOT NULL;
