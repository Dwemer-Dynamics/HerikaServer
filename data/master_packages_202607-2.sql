

--
-- Data for Name: master_packages; Type: TABLE DATA; Schema: public; Owner: dwemer
--

DO $$
DECLARE
    constraint_record record;
BEGIN
    FOR constraint_record IN
        SELECT conname
        FROM pg_constraint
        WHERE conrelid = 'public.master_packages'::regclass
    LOOP
        EXECUTE format(
            'ALTER TABLE public.master_packages DROP CONSTRAINT IF EXISTS %I',
            constraint_record.conname
        );
    END LOOP;
END
$$;

ALTER TABLE ONLY public.master_packages
    ADD CONSTRAINT master_packages_pk PRIMARY KEY (formid);

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
    '0x0004ADE7',
    'SandBoxSleep',
    '{actor} is sleeping at {location}',
    '',
    ''
)
ON CONFLICT (formid) DO NOTHING;


UPDATE public.master_packages SET formid = '0x0004ADF0' WHERE formid = '0x0004ADE7';
