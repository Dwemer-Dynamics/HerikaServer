

--
-- Data for Name: master_packages; Type: TABLE DATA; Schema: public; Owner: dwemer
--
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
        WHERE conrelid = 'public.master_packages'::regclass AND contype = 'p'
    ) THEN
        ALTER TABLE ONLY public.master_packages
            ADD CONSTRAINT master_packages_pk PRIMARY KEY (formid);
    END IF;
END $$;

-- Move the old entry before seeding; preserve both rows if the target already exists.
UPDATE public.master_packages SET formid = '0x0004ADF0'
WHERE formid = '0x0004ADE7'
  AND NOT EXISTS (SELECT 1 FROM public.master_packages WHERE formid = '0x0004ADF0');

INSERT INTO public.master_packages (
    mod,
    formid,
    "name",
    "start",
    "change",
    "end"
)
VALUES (
    'AIAgent.esp',
    '0x0004ADF0',
    'SandBoxSleep',
    '{actor} is sleeping at {location}',
    '',
    ''
)
ON CONFLICT (formid) DO NOTHING;
