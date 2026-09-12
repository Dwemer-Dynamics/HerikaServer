-- Existing history is a baseline, not a backlog of new profile events.
DO $$ BEGIN
    IF NOT EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='eventlog' AND column_name='dynamic_profile_pending') THEN
        ALTER TABLE public.eventlog ADD COLUMN dynamic_profile_pending boolean NOT NULL DEFAULT false;
    END IF;
END $$;
ALTER TABLE public.eventlog ALTER COLUMN dynamic_profile_pending SET DEFAULT true;
CREATE INDEX IF NOT EXISTS eventlog_dynamic_profile_pending ON public.eventlog(rowid) WHERE dynamic_profile_pending;

-- Reusable profile settings remain global and explicit existing values win.
UPDATE public.core_profiles SET metadata=jsonb_build_object(
    'DYNAMIC_PROFILE_INTERVAL_DAYS',1,'DYNAMIC_PROFILE_MIN_EVENTS',30,'DYNAMIC_PROFILE_COOLDOWN_MINUTES',5
) || COALESCE(metadata,'{}'::jsonb)
WHERE NOT COALESCE(metadata,'{}'::jsonb) ?& ARRAY['DYNAMIC_PROFILE_INTERVAL_DAYS','DYNAMIC_PROFILE_MIN_EVENTS','DYNAMIC_PROFILE_COOLDOWN_MINUTES'];
INSERT INTO public.core_narrator(id,value) VALUES
    ('DYNAMIC_PROFILE_INTERVAL_DAYS','1'),('DYNAMIC_PROFILE_MIN_EVENTS','30'),('DYNAMIC_PROFILE_COOLDOWN_MINUTES','5')
ON CONFLICT(id) DO NOTHING;
