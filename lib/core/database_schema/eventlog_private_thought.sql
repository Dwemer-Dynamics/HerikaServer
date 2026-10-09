-- Nullable event metadata follows its dialogue through saves, pruning and exports.
ALTER TABLE public.eventlog ADD COLUMN IF NOT EXISTS private_thought jsonb;
