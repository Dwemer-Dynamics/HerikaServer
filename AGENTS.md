# HerikaServer Agent Notes

- Global Settings and Profiles must remain feature-equivalent between their PHP pages and the in-game Prisma Settings hub.
- When adding, removing, renaming, or changing a setting in `ui/global_settings.php` or `ui/core/core_profiles.php`, update `lib/core/prisma_settings_catalog.php` in the same change.
- Keep structural fields in `ui/api/chim_global_settings.php` and `ui/api/chim_profile_manager.php` allowlisted and typed. Preserve unknown profile metadata for plugin compatibility, but never map arbitrary client keys to database columns.
- Validate both the PHP page and the Prisma API response when changing either settings menu.

## Playthrough Saves

### Table policy and comments

- Read [lib/playthrough_policy.php](lib/playthrough_policy.php) before changing capture, restore, fresh starts or table comments. It is the authoritative table list; do not maintain a second list or fixed table count here.
- `pts_table_policy()` classifies tables as `playthrough`, `global`, `mixed` or `infrastructure`. `pts_playthrough_tables()` selects playthrough and mixed tables.
- Global tables remain live across saves and new playthroughs. These include credentials, connectors, reusable profiles/presets, import rules, prompts/actions and the explicitly listed shared libraries. Global means excluded from switching, not read-only.
- `conf_opts` and `general_settings` are mixed: only gameplay rows travel with a save. Use `is_global_setting()` in [lib/playthrough_selection.sql](lib/playthrough_selection.sql); do not capture or replace these tables wholesale. Player identity, party and gameplay timestamps must not become global.
- `database_versioning` stays live; migration metadata belongs in the save manifest. Unknown/plugin tables are unmanaged: never auto-enrol them or clear them just because they exist in `public`.
- Selected public tables have exactly `Playthrough Manager Backed Up` as their PostgreSQL table comment. Excluded public tables have a NULL/blank comment. Comments describe the policy; they do not control capture.
- [debug/db_updates.php](debug/db_updates.php) calls `pts_update_playthrough_policy()` to synchronise comments idempotently. Change the policy and update path together rather than applying a one-off database label.

### HerikaServer specifics

- CHIM stores Oghma and the explicitly listed quest data with the playthrough. Shared biography/description/animation libraries and translations remain global where listed. Do not reclassify knowledge or quest tables as global merely because they contain authored content.

### New, switch and restore

- Save identity is frozen in the schema manifest under `player_identity` by `playthrough_identity()` in `lib/playthrough_selection.sql`. CHIM/DIALECTIC store character name and level; STOBE stores player squad member names. Dropdowns read saved metadata, never current gameplay to label another save. Legacy saves fall back to existing metadata without inventing a level.

- [lib/playthrough_home.php](lib/playthrough_home.php) provides the shared `pth_change()` operation for home controls and the manager. Keep its runtime barrier, advisory lock, stale-state token and transaction boundaries intact.
- Switching prepares and validates the target privately, saves current progress, then activates the target and active-save metadata in one transaction. Failures before commit roll back.
- [lib/playthrough_fresh.php](lib/playthrough_fresh.php) empties selected data in a private stage for New playthrough. It starts with no encountered NPCs, memories or gameplay progress, while activation preserves global tables and global rows of mixed tables.
- Restore replaces selected rows in existing public tables. Preserve table identities, views, triggers and excluded data. Never solve a dependency failure with a blanket schema replacement or cascading deletion.
- [lib/playthrough_runtime.php](lib/playthrough_runtime.php) drains active work, blocks new game requests during switching and refreshes persistent workers afterward. This is not a restart of PostgreSQL, Apache or the whole distro. Report worker-readiness warnings even after a successful database commit.
- Server saves do not change game save files. Close the game, switch the server playthrough, wait for confirmation, then load the matching game save. Character names do not automatically select a server playthrough.

- Home-picker deletion requires exact `Delete` text and fresh active/target tokens. `pth_delete()` shares the retention lock, rejects active/default/pinned/shared-schema saves, and deletes tables plus schema with RESTRICT in one transaction. Never replace this with a cascading schema drop or an automatic write retry. Deletion does not switch live gameplay or restart workers.

### Older saves and policy changes

- Read [lib/playthrough_schema.php](lib/playthrough_schema.php), [lib/playthrough_upgrade.sql](lib/playthrough_upgrade.sql) and [lib/playthrough_migrations.php](lib/playthrough_migrations.php) together. Upgrade a private stage to the current schema before activation.
- When membership changes, assess the manifest policy version, accepted versions, SQL API readiness check and missing-table rules together.
- Known omissions from older policies may be created empty. Keep `empty_tables` distinct from tables needing content migrations; never fill intentionally empty gameplay tables from the currently active playthrough.
- Reject unsupported versions, missing required data, invalid shared-profile references and unsafe external foreign-key dependencies before activation. Do not silently weaken validation to load an incompatible save.

### Automatic saves and cleanup

- [lib/playthrough_preferences.php](lib/playthrough_preferences.php) keeps saves triggered by loading an older game save enabled; the user controls the rollback threshold in in-game days.
- [lib/playthrough_retention.php](lib/playthrough_retention.php) owns cleanup rules, with storage categories in [lib/playthrough_categories.php](lib/playthrough_categories.php). Keep storage reporting and cleanup scopes aligned.
- Cleanup evaluates enabled category rules without a separate user-facing master toggle. Age-based rules use days; older-event cleanup is off by default. Saved-copy retention has no maximum by default.
- A Playthrough Save contains selected mod data. It is not a full database backup; the Dashboard owns Distro-wide database exports.

### Validation

- Use disposable databases for New, switch, restore, migration and cleanup probes. Never test destructive operations on the user's active playthrough.
- For policy changes, check capture and A-to-B-to-A restoration, an empty New playthrough, unchanged global and unmanaged data, mixed-row filtering, old-policy omissions, comment synchronisation run twice, and rollback on invalid input or dependencies.
- For runtime/API changes, also check stale requests, concurrent switching, request blocking and worker readiness. Use existing checks or focused scratch probes rather than adding a large test harness.
- Read the sibling servers independently before a shared change; their schemas and rollback details differ. Report source, disposable-database, local deployment and in-game evidence separately.
