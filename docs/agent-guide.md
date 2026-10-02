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
| Actor identity in event participants | [actor-identity.md](actor-identity.md), `lib/core/npc_reference.php` |
| Actions | `lib/core/action_catalog.php`, `functions/functions.php`, `functions/json_response.php` |
| Prompt/history selection | `prompts/`, `lib/data_functions.php`, `lib/compact_context_history.php` |
| Memory retrieval | `lib/memory_helper_vectordb.php`, related worker call sites |
| Schema upgrades | `debug/db_updates.php`, `lib/core/database_schema/`, `data/` |
| Saved playthroughs | `lib/playthrough_policy.php` and the detailed rules in `AGENTS.md` |
| Browser and paired Prisma settings | `ui/`, `lib/core/prisma_settings_catalog.php`, CHIM's `config_manager.*` |
| Extension installation | `lib/plugin_package_manager.php`, `ui/api/plugin_packages.php`, `ext/generic_installer.php` |

HerikaServer, StobeServer, DialecticServer and LorkhanServer are independent products. Shared ancestry does not make their schemas, hooks or request formats interchangeable. Inspect each requested product before porting code.

For correlated synthesis, cache, filtering, queue and playback records, see [speech trace diagnostics](speech-tracing.md).

## Configuration, logs and user state

`conf/conf.sample.php` documents configuration defaults; installed `conf/conf.php` and generated profile configuration may contain secrets. Runtime settings also live in the database and must be changed through their owning APIs/tools. Do not replace live configuration with the sample or publish its values.

`lib/logger.php` defaults to `/var/www/html/HerikaServer/log/chim.log` and supports a custom log path. Check the actual configuration, Apache/PHP error log and worker logs, including file ownership when a request cannot write. Local proxies and remote servers use different endpoints; read the client's selected route instead of assuming localhost or a port.

For missing output, trace ingress, selected connector, provider result, response delivery and client playback. For memory/profile issues, confirm the active server playthrough before inspecting its records. Follow `lib/playthrough_policy.php` for table ownership: clearing event history or switching all tables can damage NPC memory or reusable settings.

Back up using the established installation workflow before an authorized update. Preserve credentials, database contents, voice samples, generated media, installed extensions and mutable configuration. Never run the unit-test database setup, schema cleanup or factory reset against the user's runtime.

## Provider diagnostics

With trace logging enabled, the existing `[PERF]` entry in `log/chim.log` includes a `providers` list for dialogue recovery requests. Each row identifies the connector, driver and configured model, primary/fallback role, selection reason, success/failure/skip/interruption status, elapsed milliseconds, HTTP status and health snapshot. `retry_in_s` is the remaining cooldown or recovery-probe lease at the last health update, not a live countdown. Busy or unavailable cache states are identified separately. At most eight rows are retained per request.

For OpenAI/OpenRouter JSON streams, `ttft_ms` measures from opening the provider request to the first observed content, reasoning, tool-call or refusal chunk; heartbeat and role-only chunks do not count. `first_content_ms` measures the first content chunk, which may still contain JSON framing rather than speakable dialogue. Buffered responses report no streaming TTFT. `upstream_provider` is populated only when the response explicitly supplies a provider name; otherwise it is null. These fields are retained on failed attempts too. They do not measure audible playback latency.

The added diagnostics contain no prompt, response text, credentials or endpoint URL and add no provider requests or health-file reads. Existing logs may contain other request data; these fields do not redact the rest of the log. Background `fast_request()` calls are outside this dialogue diagnostic path.
## Speech sentence boundaries

`lib/sentence_boundaries.php` supplies byte offsets to both streaming and full-text splitters. It preserves titles and initials, decimal/version tokens, ellipses, open narration spans, and closing quotes/brackets. CJK sentence punctuation supports adjacent characters without whitespace. Language-specific abbreviations use `CORE_LANG`; English titles are also recognized.

Streaming waits for a following non-whitespace character before committing a boundary. The existing end-of-response flush releases the final fragment. Existing minimum/maximum chunk-size behavior is retained. These are conservative text rules, not a linguistic model: ambiguous abbreviations may keep adjacent sentences together. No extra model call or settings page is involved.

## Extend and validate

Use [custom-plugins.md](custom-plugins.md) for supported extension hooks, package formats and maintained examples, and [plugin-npc-data.md](plugin-npc-data.md) for the namespaced NPC data API. Use [building.md](building.md) for PHP/test prerequisites and safe checks. API changes shared with the client need paired contract checks; UI changes need browser and keyboard testing; database changes need disposable fresh-install and upgrade probes.

Keep `AGENTS.md`, `README.md` and `docs/` in server archives and syncs. These are plain text and introduce no request-time work. They are not deployment scripts.


## NPC schedules

The NPC editor's Schedules tab creates, edits, cancels and deletes appointments. Select a recognised location, an in-game day number and appointment time. Daily repetition is 24 game hours; zero means one time. Destination validation requires the paired server and CHIM scripts connected to the game. Saved schedules stay pending until the game resolves a persistent arrival marker; unknown or ambiguous AI destinations remain reminders with a clarification request.

Combat, loot application and scheduled departure share a dispatch lock. Scheduled actors cannot be recruited into a Background Life encounter; pending encounter outcomes and loot postpone departure.

Travel starts three game hours early. Routine progress checks run hourly; the worker checks departure and arrival deadlines on each service tick using the accepted game clock. Early arrivals wait. At the deadline CHIM checks the actor's actual location and moves late actors to the validated marker. Combat/dialogue can defer execution. Visits finish after arrival, stays after their duration, and duties require an AI-reported outcome. Repetition starts only after releasing the previous activity. Overlapping travel windows are rejected; multiple recurring schedules require matching intervals.

CHIM Off blocks new schedule commands. An already installed travel package can continue until it is released. Cancellation/deletion waits for the game's release acknowledgement; Retry resends a stalled operation. Timeline changes invalidate old occurrences after release. Load the corresponding game and server saves together. Dynamic references and ambiguous same-name AI duties are rejected. A valid marker does not prove navmesh reachability: verify travel, waiting, teleport and package restoration in Skyrim before release.

The paired protocol uses `BackgroundCmd@actor@Schedule/run/token/operation/destination/issuedDays` and `util_npc_schedule` replies containing `run/token/actor/operation/result/marker`. Operations are validate, travel, check, ensure and release. The client restores only its owned travel override and link, and the server accepts only the pending operation's matching token, actor and clock epoch. Compile CHIMSchedule.psc alongside AIAgentAIMind.psc when building the client payload.
## Connector capability tests

The individual LLM Test button and profile/global connector batches share the same isolated test endpoint. They call the selected connector directly with a synthetic greeting; they do not run fallback, update provider recovery health, execute actions, synthesize dialogue or generate memories. Existing connector audit/log writes still apply. Tests incur the selected provider's normal usage charges.

Results separate connection, completion, dialogue JSON and the harmless Talk action fields. JSON drivers must return a complete object; plain-text drivers are not required to emit JSON and native tool calls are reported as untested. Provider completion/refusal/token-limit evidence and first-token timing are available for openaijson/openrouterjson. Older drivers report a warning when provider finish status is unavailable. The loop caps iterations, returned text and elapsed time; blocking legacy calls remain subject to their driver's transport timeout.

The image test sends a fixed two-shape fixture and checks the left/right colours. A nonempty but incorrect answer is a recognition warning, not proof of vision support. Batch jobs still deduplicate connector IDs; this is not a test of every diary/formatter prompt or every game action.
## Automatic actor voice effects

Automatic Actor Voice Effects is a global setting under Memory & Others / Misc in PHP and Prisma, enabled by default. It temporarily selects Werewolf for werewolf form, Vampire Lord for vampire-lord form, Combat for combat/attacking, or Sneaking for sneaking, in that priority order. Transformation effects also respect Transformation Detection. The NPC's saved filter is never overwritten. Normal, missing, future or older-than-one-minute observations fall back to the saved filter. Effects are selected with NPC voice setup and remain fixed for that response; narrator and book-reading filters keep their existing paths.

The setting is reusable general_settings configuration and stays global across playthrough restores. Transformation/activity updates record server receipt time because client timestamps may use a monotonic nanosecond clock. Existing transformation/activity metadata remains NPC playthrough data; there is no new table or migration. Current client updates identify NPCs by name and arrive periodically, so effect switching is not instantaneous and inherits existing same-name routing limitations.

Automatic effects use the existing FFmpeg WAV path. Filter failure preserves the generated audio. Filtered requests keep the existing TTS-cache bypass, so default-on combat/sneaking effects can increase synthesis work and latency. No new provider request, polling or model prompt is introduced by effect selection itself. A small .wav.ttsfilter marker prevents a normal voice from reusing previously filtered audio at the same dialogue-text hash; fresh unfiltered generation removes the marker. If a marker cannot be created, filtering is skipped and speech stays available.

The four actor effects are also selectable voice-filter presets in the PHP NPC editor and Prisma, through the shared preset catalog. Werewolf lowers pitch by about six semitones and adds rough modulation; Vampire Lord lowers pitch by about three semitones with chorus and echo; Combat increases pace, presence and loudness; Sneaking reduces brightness and loudness with slightly slower delivery. These are audio effects, not new expressive TTS performances: Sneaking does not synthesize a true whisper. Existing Deep, Sinister, Commanding and Soft-Spoken presets are unchanged.
