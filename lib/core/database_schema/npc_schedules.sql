ALTER TABLE public.npc_commitments ADD COLUMN IF NOT EXISTS npc_id bigint;
ALTER TABLE public.npc_commitments ADD COLUMN IF NOT EXISTS schedule jsonb;
CREATE TABLE IF NOT EXISTS public.npc_schedule_runs (
    id bigserial PRIMARY KEY,
    task_id bigint NOT NULL REFERENCES public.npc_commitments(id) ON DELETE RESTRICT,
    due_gamets bigint NOT NULL,
    token text NOT NULL UNIQUE,
    epoch text NOT NULL,
    actor_ref text NOT NULL,
    phase text NOT NULL DEFAULT 'validate',
    result text NOT NULL DEFAULT '',
    marker_ref text NOT NULL DEFAULT '',
    pending_op text NOT NULL DEFAULT '',
    sent_at bigint NOT NULL DEFAULT 0,
    attempts integer NOT NULL DEFAULT 0,
    command_id bigint,
    snapshot jsonb NOT NULL,
    updated_at timestamp NOT NULL DEFAULT now(),
    UNIQUE(task_id, due_gamets, token)
);
CREATE INDEX IF NOT EXISTS npc_schedule_active ON public.npc_schedule_runs(task_id, phase);
CREATE INDEX IF NOT EXISTS npc_commitment_schedule_due ON public.npc_commitments(due_gamets) WHERE schedule IS NOT NULL AND status IN ('scheduled','due');

CREATE INDEX IF NOT EXISTS npc_commitments_schedule_actor_idx ON npc_commitments(npc_id) WHERE schedule IS NOT NULL;
