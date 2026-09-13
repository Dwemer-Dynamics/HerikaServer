CREATE TABLE IF NOT EXISTS public.market_cache (
    baseid character varying(128) NOT NULL,
    name text,
    description text,
    plugin text NOT NULL,
    enchantment integer,
    price numeric
);


ALTER TABLE public.market_cache OWNER TO dwemer;

--
-- Name: market_cache market_cache_pk; Type: CONSTRAINT; Schema: public; Owner: dwemer
--

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
        WHERE conrelid = 'public.market_cache'::regclass AND contype = 'p'
    ) THEN
        ALTER TABLE ONLY public.market_cache
            ADD CONSTRAINT market_cache_pk PRIMARY KEY (baseid, plugin);
    END IF;
END $$;
