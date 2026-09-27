# HerikaServer agent guide

## Identify the component

[HerikaServer](https://github.com/Dwemer-Dynamics/HerikaServer) is CHIM's PHP/PostgreSQL backend. The [CHIM client](https://github.com/Dwemer-Dynamics/CHIM) owns Skyrim/SKSE, Papyrus, microphone capture, game actions and playback. LLM means language model; STT and TTS mean speech-to-text and text-to-speech.

Before editing, read [AGENTS.md](../AGENTS.md), record `git status --short --branch` and `git rev-parse HEAD` if Git is present, and locate the actual server document root. A downloaded source archive may have no `.git`. Check `.version.txt`, `.version_number.txt`, the client version and installed extensions; current online development may differ from the user's release. Do not assume the checkout under inspection is the deployed server.

## How requests move through the server

1. `comm.php` enters `main.php`; `lib/runtime_bootstrap.php` loads configuration and supporting services. `processor/` handles event-specific behavior.
2. Game events, actor/profile identity and the active playthrough determine stored history and prompt context. Core settings/profiles and the action catalog live under `lib/core/`.
3. `prompts/`, `prompt.includes.php` and `lib/data_functions.php` build context. `connector/` calls the selected LLM; `stt/` and `tts/` own speech provider integrations.
4. `functions/` handles model actions and response formatting. `stream.php`/`streamv2.php` deliver responses; the game client executes actions and plays audio.
5. Background processing handles derived work. `lib/background_processor.php`, `processor/comm.php` and `service/` identify the scheduling/worker paths; verify the installed service configuration before restarting anything.

An HTTP success does not prove that an actor spoke or an action completed. Correlate client, server and provider timestamps before assigning a cause.

## Where to work

| Task | Source entry points |
|---|---|
| Request dispatch or passive events | `comm.php`, `main.php`, `processor/comm.php` |
| LLM/STT/TTS connectors | `connector/`, `stt/`, `tts/`, `lib/core/*_connector.class.php` |
| Profiles and NPC state | `lib/core/core_profiles.class.php`, `lib/core/npc_master.class.php` |
| Actions | `lib/core/action_catalog.php`, `functions/functions.php`, `functions/json_response.php` |
| Prompt/history selection | `prompts/`, `lib/data_functions.php`, `lib/compact_context_history.php` |
| Memory retrieval | `lib/memory_helper_vectordb.php`, related worker call sites |
| Schema upgrades | `debug/db_updates.php`, `lib/core/database_schema/`, `data/` |
| Saved playthroughs | `lib/playthrough_policy.php` and the detailed rules in `AGENTS.md` |
| Browser and paired Prisma settings | `ui/`, `lib/core/prisma_settings_catalog.php`, CHIM's `config_manager.*` |
| Extension installation | `lib/plugin_package_manager.php`, `ui/api/plugin_packages.php`, `ext/generic_installer.php` |

HerikaServer, StobeServer, DialecticServer and LorkhanServer are independent products. Shared ancestry does not make their schemas, hooks or request formats interchangeable. Inspect each requested product before porting code.

## Configuration, logs and user state

`conf/conf.sample.php` documents configuration defaults; installed `conf/conf.php` and generated profile configuration may contain secrets. Runtime settings also live in the database and must be changed through their owning APIs/tools. Do not replace live configuration with the sample or publish its values.

`lib/logger.php` defaults to `/var/www/html/HerikaServer/log/chim.log` and supports a custom log path. Check the actual configuration, Apache/PHP error log and worker logs, including file ownership when a request cannot write. Local proxies and remote servers use different endpoints; read the client's selected route instead of assuming localhost or a port.

For missing output, trace ingress, selected connector, provider result, response delivery and client playback. For memory/profile issues, confirm the active server playthrough before inspecting its records. Follow `lib/playthrough_policy.php` for table ownership: clearing event history or switching all tables can damage NPC memory or reusable settings.

Back up using the established installation workflow before an authorized update. Preserve credentials, database contents, voice samples, generated media, installed extensions and mutable configuration. Never run the unit-test database setup, schema cleanup or factory reset against the user's runtime.

## Extend and validate

Use [custom-plugins.md](custom-plugins.md) for supported extension hooks, package formats and maintained examples, and [plugin-npc-data.md](plugin-npc-data.md) for the namespaced NPC data API. Use [building.md](building.md) for PHP/test prerequisites and safe checks. API changes shared with the client need paired contract checks; UI changes need browser and keyboard testing; database changes need disposable fresh-install and upgrade probes.

Keep `AGENTS.md`, `README.md` and `docs/` in server archives and syncs. These are plain text and introduce no request-time work. They are not deployment scripts.
