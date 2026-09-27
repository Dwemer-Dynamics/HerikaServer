<?php

/** Explicit CHIM playthrough data policy; database comments are descriptive only. */
function pts_table_policy(): array {
    return [
        'global' => explode(',', 'animations,animations_custom,bio_templates,bio_templates_custom,core_action,core_action_custom,core_api_badge,core_itt_connector,core_llm_connector,core_narrator,core_profiles,core_stt_connector,core_tts_connector,core_tts_fallback,core_tts_pronunciation,descriptions,descriptions_custom,faction_vanilla,global_settings_presets,import_rules,json_personalities,master_packages,npc_templates,npc_templates_custom,npc_templates_trl,npc_templates_v2,profile_settings_presets,prompts,translations'),
        'playthrough' => explode(',', 'actions_issued,audit_memory,audit_request,bgl_history,books,core_npc_master,core_npc_master_history,core_player,currentmission,diarylog,dynamic_bio,eventlog,factions,game_plugins,locations,log,market_cache,memory,memory_summary,moods_issued,named_cell,npc_profile_backup,oghma,oghma_audit,oghma_catalog_entries,oghma_catalog_events,oghma_catalogs,oghma_context_rule,oghma_dynamic,oghma_factory_overrides,physical_npc_diaries,quest_asset_group_members,quest_asset_groups,quest_asset_imports,quest_asset_packs,quest_assets,quest_item_types,quest_npc_own_templates,quest_npc_templates,quest_outfits,questlog,quests,relationship_eval_queue,relationship_init_queue,responselog,rolemaster,rumors,skyrim_quest_action_outbox,skyrim_quest_beat_state,skyrim_quest_definitions,skyrim_quest_events,skyrim_quest_instances,sneq_quests,sneq_quests_saved,speech,visual_context'),
        'mixed' => ['conf_opts', 'general_settings'],
        'infrastructure' => ['database_versioning'],
        // Tables absent from these lists are unmanaged and must never be cleared.

    ];
}

// Only gameplay tables and gameplay rows of mixed tables belong in a save.
function pts_playthrough_tables(): array {
    $policy = pts_table_policy();
    return array_merge($policy['playthrough'], $policy['mixed']);
}

// Run after database updates so new tables and retired labels follow the capture policy.
function pts_update_playthrough_policy($conn): bool {
    if (!pts_ensure_functions($conn)) return false;
    $result = @pg_query_params($conn,
        'SELECT chim_meta.sync_playthrough_comments(ARRAY(SELECT jsonb_array_elements_text($1::jsonb)))',
        [json_encode(pts_playthrough_tables())]);
    if (!$result) Logger::error('Could not refresh Playthrough Save table comments: ' . pg_last_error($conn));
    return $result !== false;
}
