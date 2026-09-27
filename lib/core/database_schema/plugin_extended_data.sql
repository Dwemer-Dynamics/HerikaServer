-- Keep live NPCs and history compatible in the same atomic schema update.
ALTER TABLE public.core_npc_master
    ADD COLUMN IF NOT EXISTS plugin_extended_data jsonb NOT NULL DEFAULT '{}'::jsonb
    CHECK (jsonb_typeof(plugin_extended_data) = 'object');
ALTER TABLE public.core_npc_master_history
    ADD COLUMN IF NOT EXISTS plugin_extended_data jsonb NOT NULL DEFAULT '{}'::jsonb
    CHECK (jsonb_typeof(plugin_extended_data) = 'object');
