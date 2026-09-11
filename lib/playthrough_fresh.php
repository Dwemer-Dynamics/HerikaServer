<?php

// Explicitly classify snapshot tables; an added table needs a fresh-start decision too.
function pth_fresh_policy(): array {
    return [
        'keep' => explode(',', 'core_action,core_action_custom,core_api_badge,core_itt_connector,core_llm_connector,core_narrator,core_profiles,core_stt_connector,core_tts_connector,core_tts_fallback,core_tts_pronunciation,database_versioning,faction_vanilla,game_plugins,import_rules,json_personalities,master_packages,npc_templates,npc_templates_custom,npc_templates_trl,npc_templates_v2,oghma,oghma_catalog_entries,oghma_catalogs,oghma_factory_overrides,profile_settings_presets,prompts,quest_asset_group_members,quest_asset_groups,quest_asset_imports,quest_asset_packs,quest_assets,quest_item_types,quest_npc_own_templates,quest_npc_templates,quest_outfits,skyrim_quest_definitions'),
        'empty' => explode(',', 'actions_issued,audit_memory,audit_request,bgl_history,books,core_npc_master_history,core_player,currentmission,diarylog,dynamic_bio,eventlog,factions,locations,log,market_cache,memory,memory_summary,moods_issued,named_cell,npc_profile_backup,oghma_audit,oghma_catalog_events,oghma_dynamic,physical_npc_diaries,questlog,quests,relationship_eval_queue,relationship_init_queue,responselog,rolemaster,rumors,skyrim_quest_action_outbox,skyrim_quest_beat_state,skyrim_quest_events,skyrim_quest_instances,sneq_quests,sneq_quests_saved,speech,visual_context'),
        'mixed' => ['conf_opts','core_npc_master','general_settings'],
    ];
}

// Reset only a private prepared copy. Live rows and the previous save change at commit.
function pth_prepare_fresh($conn, string $stage): void {
    $product = ptp_product(); $meta = $product['meta'];
    if (pg_transaction_status($conn) !== PGSQL_TRANSACTION_INTRANS
        || !preg_match('/^' . preg_quote($product['prefix'], '/') . 'upgrade_[0-9]+_[0-9]+$/D', $stage)) {
        throw new RuntimeException('Invalid fresh playthrough preparation.');
    }
    $policy = pth_fresh_policy();
    $reviewed = array_merge(...array_values($policy));
    if (array_diff(pts_playthrough_tables(), $reviewed) || count($reviewed) !== count(array_unique($reviewed))) {
        throw new RuntimeException('The fresh playthrough table policy needs an update. Your data was kept.');
    }
    $schema = pg_escape_identifier($conn, $stage);
    $tables = array_column(pg_fetch_all(pth_query($conn, 'SELECT tablename FROM pg_tables WHERE schemaname=$1', [$stage])) ?: [], 'tablename');
    foreach (array_intersect($policy['empty'], $tables) as $table) {
        pth_query($conn, 'DELETE FROM ' . $schema . '.' . pg_escape_identifier($conn, $table));
    }
    if (in_array('conf_opts', $tables, true)) {
        // conf_opts also contains profile settings and reusable voice mappings.
        $settings = array_unique(array_merge(array_keys(ptp_config()), [
            'CONTEXT_HISTORY','CONTEXT_HISTORY_DIARY','CONTEXT_HISTORY_DYNAMIC_PROFILE','MAX_WORDS_LIMIT',
            'RECHAT_H','RECHAT_P','RECHAT_ALLOW_ACTIONS','BORED_EVENT','RPG_COMMENTS_CHANCE',
            'COMBAT_BARK_COOLDOWN','QUEST_COMMENT','PLAYER2_FORCE_ALL_LLM','PLAYER2_HEALTH_URL',
            'core_action_legacy_user_pref_imported','dialectic_mode','dialectic_profile_model','plugin_dll_version',
        ]));
        // Identity and timestamps are game state even if a legacy config file supplies defaults.
        $settings = array_values(array_filter($settings, fn($key) => !preg_match('/^(PLAYER_NAME|PLAYER_BIO|PLAYER_CATS|CurrentParty)$|LAST_.*(TS|GAMETS|TIMESTAMP)$/', $key)));
        pth_query($conn, "DELETE FROM {$schema}.conf_opts WHERE NOT (id = ANY(ARRAY(SELECT jsonb_array_elements_text($1::jsonb))))
            AND id !~ '^(cartesia_voice_|inworld_voice_|tts_sync_|Voicetype/|Nametype/|Network/)'", [json_encode($settings)]);
    }
    if (in_array('general_settings', $tables, true)) {
        pth_query($conn, "DELETE FROM {$schema}.general_settings WHERE id IN ('PLAYER_NAME','PLAYER_BIO','PLAYER_CATS')");
    }
    if (in_array('core_npc_master', $tables, true)) {
        $columns = array_column(pg_fetch_all(pth_query($conn, 'SELECT column_name FROM information_schema.columns WHERE table_schema=$1 AND table_name=$2', [$stage,'core_npc_master'])) ?: [], 'column_name');
        $sets = [];
        foreach (array_intersect(['relationships','md5','gamets_last_updated'], $columns) as $column) $sets[] = $column . '=DEFAULT';
        if ($meta === 'stobe_meta') {
            foreach (array_intersect(['equipment','inventory','bounty','limbs','blood','hunger','is_slave'], $columns) as $column) $sets[] = $column . '=DEFAULT';
            if (in_array('profile_id_before_player_faction', $columns, true)) {
                $sets[] = 'profile_id=coalesce(profile_id_before_player_faction,profile_id)';
                $sets[] = 'profile_id_before_player_faction=NULL';
            }
        }
        // Preserve arbitrary per-NPC setting overrides; remove known learned and observed state.
        $runtimeKeys = explode(',', 'middle_term_memory,relationships,relationships_analyzed,relationships_inferred,relationships_last_eval,relationships_model,relationships_updated,nearby_snapshot,entry,inventory,equipment,health,blood,hunger,limbs,medical,stats,nearby,environment,dist,source,observer,is_player_character,is_slave,bounty,faction,faction_id,indoors,outdoors,in_town,building_serial,building_id,indoors_serial,building_name,indoors_name,town_name,town,floor,floor_num,current_floor,region,zone,zone_name,weather,x,y,z,storage_id,block,hold,passive,jobs,job_list,ranged,taunt,sneak,resource,medic');
        foreach (array_intersect(['extended_data','metadata'], $columns) as $column) {
            $sets[] = "$column=CASE WHEN jsonb_typeof($column::jsonb)='object' THEN $column::jsonb - ARRAY(SELECT jsonb_array_elements_text($1::jsonb)) ELSE '{}'::jsonb END";
        }
        if ($sets) pth_query($conn, "UPDATE {$schema}.core_npc_master SET " . implode(',', $sets),
            array_intersect(['extended_data','metadata'], $columns) ? [json_encode($runtimeKeys)] : []);
    }
    // Recheck constraints after reset, including references from tables outside the policy.
    pth_query($conn, "SELECT {$meta}.validate_playthrough($1, ARRAY(SELECT jsonb_array_elements_text($2::jsonb)))", [$stage,json_encode(pts_playthrough_tables())]);
}
