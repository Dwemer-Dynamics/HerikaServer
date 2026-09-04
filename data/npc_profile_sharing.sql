-- Both actor rows survive a merge. The optional owner is local to this playthrough.
DO $$
BEGIN
    ALTER TABLE public.core_npc_master ADD COLUMN IF NOT EXISTS profile_owner_npc_id integer;
    CREATE INDEX IF NOT EXISTS idx_npc_profile_owner ON public.core_npc_master (profile_owner_npc_id)
        WHERE profile_owner_npc_id IS NOT NULL;
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conrelid = 'public.core_npc_master'::regclass
                   AND conname = 'npc_profile_owner_not_self') THEN
        ALTER TABLE public.core_npc_master ADD CONSTRAINT npc_profile_owner_not_self
            CHECK (profile_owner_npc_id IS NULL OR profile_owner_npc_id <> id);
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conrelid = 'public.core_npc_master'::regclass
                   AND conname = 'npc_profile_owner_fk') THEN
        ALTER TABLE public.core_npc_master ADD CONSTRAINT npc_profile_owner_fk
            FOREIGN KEY (profile_owner_npc_id) REFERENCES public.core_npc_master(id)
            DEFERRABLE INITIALLY DEFERRED;
    END IF;
END;
$$;

-- Shipped alternate-reference groups remain server-owned. Custom rows override a
-- whole group by key, matching the built-in/custom behavior of NPC biographies.
CREATE TABLE IF NOT EXISTS public.npc_profile_reference_groups (
    group_key character varying(64) NOT NULL PRIMARY KEY,
    display_name character varying(128) NOT NULL,
    plugin_name character varying(255) NOT NULL,
    local_formids text[] NOT NULL,
    enabled boolean NOT NULL DEFAULT TRUE,
    CONSTRAINT npc_profile_reference_groups_member_count
        CHECK (cardinality(local_formids) BETWEEN 2 AND 32)
);

CREATE TABLE IF NOT EXISTS public.npc_profile_reference_groups_custom (
    group_key character varying(64) NOT NULL PRIMARY KEY,
    display_name character varying(128) NOT NULL,
    plugin_name character varying(255) NOT NULL,
    local_formids text[] NOT NULL,
    enabled boolean NOT NULL DEFAULT TRUE,
    updated_at timestamp with time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT npc_profile_reference_groups_custom_member_count
        CHECK (cardinality(local_formids) BETWEEN 2 AND 32)
);

INSERT INTO public.npc_profile_reference_groups
    (group_key, display_name, plugin_name, local_formids, enabled)
VALUES
    ('astrid', 'Astrid', 'Skyrim.esm', ARRAY['0001BDE8', '0004D6D1'], TRUE),
    ('cicero', 'Cicero', 'Skyrim.esm', ARRAY['000550F1', '0001E64A', '0009BCB0'], TRUE),
    ('erik', 'Erik / Erik the Slayer', 'Skyrim.esm', ARRAY['000350B8', '000656E2'], TRUE),
    ('ulfric', 'Ulfric', 'Skyrim.esm', ARRAY['0001B131', '000BE32B', '00053C66', '000EA584'], TRUE),
    ('sinding', 'Sinding', 'Skyrim.esm', ARRAY['0002ABBD', '0006C1B8'], TRUE),
    ('kodlak', 'Kodlak', 'Skyrim.esm', ARRAY['0001A68F', '000AD3A6', '000DCCC1', '00058300', '00098BD4'], TRUE)
ON CONFLICT (group_key) DO UPDATE SET
    display_name = EXCLUDED.display_name,
    plugin_name = EXCLUDED.plugin_name,
    local_formids = EXCLUDED.local_formids,
    enabled = EXCLUDED.enabled;

CREATE OR REPLACE VIEW public.combined_npc_profile_reference_groups AS
SELECT c.group_key,
       c.display_name,
       c.plugin_name,
       c.local_formids,
       c.enabled,
       'custom'::text AS source
  FROM public.npc_profile_reference_groups_custom c
UNION ALL
SELECT b.group_key,
       b.display_name,
       b.plugin_name,
       b.local_formids,
       b.enabled,
       'default'::text AS source
  FROM public.npc_profile_reference_groups b
  LEFT JOIN public.npc_profile_reference_groups_custom c
    ON c.group_key = b.group_key
 WHERE c.group_key IS NULL;
