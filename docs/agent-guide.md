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
| Player voice responder decision | `stt_target.php`, `lib/stt_target_jev.php` |

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


## Compact NPC action tools

Eligible, uncustomized vanilla actions are grouped for the model at request time by `lib/core/action_groups.php`. The model selects an action and mode; execution resolves back to the original action code and payload. Groups contain only modes eligible for the current NPC and turn, and require at least two eligible members. Customized definitions remain individual.

Observe covers actor inspection, surroundings and inventory (with optional item search text). Rest covers sitting and sleeping. Travel covers a named destination and returning home. The other groups cover crime, combat, following, pace, giving and exchange. Toast remains an individual gesture. JSON prompts, structured output, local grammar and tool calls share the mode field. Relax and Drink are retired; dedicated migrations remove their base/custom catalog rows and the runtime excludes them even before migration. Use Consume for food, drinks and potions actually present in inventory. Client handlers remain for compatibility.

## Background Life enrollment

`chimBglSetEnabled()` in `lib/background_life_requests.php` is the enrollment writer for the current web API (`ui/api/background_life_npc.php`), the in-game `enable_bg`/`disable_bg` requests and automatic enrollment. Older writers, such as the Background Life command processors, NPC creation, `ui/largemapview.php`, the SNQE API and debug scripts, still write `background_life_enabled` directly and do not manage the opt-out marker. The setter merges only enrollment keys into `extended_data`; removal also sets `background_life_auto_enroll_opt_out`. Its optional automatic argument rechecks the enrollment, opt-out and death gates in the same `UPDATE` and returns null when another decision won.

`BGL_AUTO_ENROLL_ENABLED` (default off) and `BGL_AUTO_ENROLL_EVENT_THRESHOLD` (default 200, range 1-5000) are global settings shown in Global Settings, Prisma Settings and both Background Life pages. When on, a delivered NPC reply to the player in `processor/comm.php` `_speech` checks only that speaker. The NPC must have no stored enrollment value or opt-out, be unique by name, alive and not a known animal or summon race. Distinct events with the NPC in `people` are counted up to the threshold, excluding bookkeeping, imports, relationship audits and combat barks. Only confirmed `spoken` lines count; emitted, pending and aborted lines do not, while rows without a delivery state count as legacy events. Enrollment leaves Actions, Letters and combat off and starts the normal trigger period.

`GET ui/api/background_life_npc.php?operation=chat_targets&targets=[{"refid":"0001A2B3","name":"Lydia"}]` (URL-encoded) serves the CHIM chat. In one query, it returns each target's enrollment and saved NPC-to-Player affinity for up to 32 targets (`chimBglChatTargetStatuses()`). The raw `targets` value is limited to 16 KiB and list-of-objects nesting; at most 64 entries are examined, and entries whose `refid` or `name` is not a string are skipped. Targets resolve like `enable_bg`: the most recently updated row with the RefID, then a unique name. A name used by several rows is `ambiguous`, not guessed. Affinity uses the `Player` entry from `RelationshipManager::normalizeRelationshipMap()` and is null when none is saved. The `scope` token changes with `core_player` `playthrough_id`/`player_name`, so the client can drop cached data. The chat sends its selected target first and rereads when the selection changes. Its add button sends `enable_bg`, and at most four of these reads, each with a client deadline, confirm the saved result.

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

## Player voice responder decision

CHIM posts to `stt_target.php` only for voice input whose own router would otherwise use its nearest-eligible fallback among two or more NPCs. The endpoint requires the game's JSON transport and playthrough tag, honours the CHIM interaction switch, and accepts at most 8 candidates and a 600-byte transcript. It uses only the dedicated Decision Connector (`CORE_CONNECTOR_DECISION`), while its enable switch is on and `chimIsDecisionConnector()` accepts it; it never reads the legacy Scene Classifier connector and never takes URLs, models or keys from the caller. The state sent to Jev holds the transcript, the candidates' names, distances and view/follower cues, and up to 8 recent `speech` lines with speaker and listener.

The request goes through that connector's shared `jev_request`, which uses the connector's configured model, decisions URL (or the default OpenRouter decisions endpoint) and key. Its optional bounded mode makes one cURL call with a 1500 ms total bound, a 16 KB response cap and no retry, and writes no `audit_request` row, response log or provider-body warning. Called with its original four arguments, `jev_request` keeps its previous timeout, audit rows and response log.

The global `STT_TARGETING_ENABLED` setting (STT Targeting, default on) appears as a toggle directly under the Decision Connector dropdown in Global Settings, beside `DECISION_SCENE_CLASSIFIER_ENABLED` (Scene Classifier, default on), and after the Decision Connector in Prisma. Scene Classifier gates only the Decision Connector's scene genre: with the Decision Connector available and Scene Classifier off, `processor/postrequest.php` skips the history query and provider request, keeps the default genre and does not fall back to Scene Classifier (Legacy); with the Decision Connector unavailable, the legacy rules apply unchanged. It is reusable `general_settings` configuration, not playthrough data. Off answers `abstain` with reason `disabled` before any connector lookup, history query or provider call. It does not affect scene genre classification, the Decision Connector's availability, or STT recording and transcription. A crosshair target and the client's own router still take priority because the client only asks when it would otherwise use its nearest-eligible fallback.

The decision fails open: after the request guards, `chimSttTargetRespond()` turns every failure into an abstention and never exposes error details, and the client also falls back once on HTTP errors, malformed replies or its 2-second deadline. An abstention routes the speech normally; it never cancels it. Stop, newer input, a load, or leaving the cell or area still discard the pending turn on the client, and the interaction generation still rejects output invalidated by switching CHIM interaction Off/On.

The reply is `select` with one offered form ID, or `abstain` with a reason. `not_configured` means the Decision Connector is unset, disabled or not a decision connector; it is reevaluated on every request, so the client keeps asking and a settings change applies to the next voice turn without reloading. `connector_error`, `missing_key`, `no_answer` (transport, HTTP or provider failure), `malformed_answer`, `low_confidence` and `model_abstained` are per-request outcomes. Logs contain only the outcome, form ID, reason, latency and, for transport failures, cURL and HTTP codes; never the transcript, dialogue, provider body or key.

## Quest dialogue intent decision

When a Traditional Quest dialogue turn fires no beat deterministically, `chimQuestEngineSelectDialogueBeatByIntent()` may ask for a semantic match among the still-eligible beats (unfired, prerequisites, conditions, required item and NPC focus already checked), within the existing `CHIM_QUEST_DIALOGUE_INTENT_MAX_CALLS` per-turn budget. With `DECISION_QUEST_INTENT_ENABLED` (Quest Dialogue Intent, default off, a Decision Connector toggle beside STT Targeting and Scene Classifier) off, this remains the existing chat-connector `fast_request`. Radiant templates and concrete radiant instances (`radiant_instance` or `template_quest_key`) always keep that path.

On, the chat connector is never called. The dedicated Decision Connector receives the beat IDs plus `no_match` as choices, described by the existing summaries, intent labels, rules, item gates and examples, with the quest, stage, location and NPC as bounded state. Each criterion asks whether its step happens on this turn, and the instructions have Jev judge each step by the actor its existing summary names: NPC exposition or requests from the NPC reply, player acceptance, refusal, questions, reports and hand-ins only from the player line (never inferred from the NPC's request or reply), with steps naming no actor judged from the player line. The refusal, condition, negation and quoted-speech protections apply to player steps. Only one beat fires per turn, so prerequisite ordering holds: an exposition beat can fire on one turn and the acceptance beat that requires it is offered from the next. Stage rehydration does not backfill a beat whose stage equals the current stage when a beat that really fired (not a backfill) already set that stage, so an acceptance beat sharing its exposition's stage stays offered; a game-reported higher stage still backfills it. The player line and NPC reply are sent whole after whitespace normalisation; invalid UTF-8 or more than 2000 bytes abstains rather than truncating. More than 8 eligible beats or a request over 15 KB abstains instead of trimming candidates. The call uses `jev_request`'s bounded mode (1500 ms, 16 KB, no retry). A beat advances only when the choice is an offered ID with finite confidence in 0..1 at or above the beat's threshold, never below 0.5; an unavailable connector, missing key, transport failure, malformed answer, `no_match` or low confidence leaves the quest unchanged. Connector globals hydrated for the call are restored afterwards. Logs record only the quest key, beat ID, outcome, an allowlisted reason code, confidence and elapsed time; any other exception is logged as `decision_error` with its class name, never its message.

## Quest action dispatch

Beat actions are queued in `skyrim_quest_action_outbox` and polled by the CHIM plugin, which runs each polled action as its own Papyrus call. An `applied` acknowledgement means the call was dispatched, not that the game state changed. Objective actions accept `index`, `objective_index` or `objective` in definitions. For `set_objective_*`, `cross_quest_set_objective_completed` and the `*start_quest_stage_objective` actions, `chimQuestEngineNormalizeObjectiveActionPayload()` fills the integer `index` and `objective_index` the plugin reads, at queue time and again at poll time for older pending rows. Only whole values from 0 to 2147483647 count; INF, NAN, signs, decimals and longer digit strings are ignored. The first valid value in the order `index`, `objective_index`, `objective` fills a missing or invalid field, and a field that already holds a different valid value keeps it. Other action types, including `fail_all_objectives`, are left as queued.

Vanilla completion stages normally apply their own hand-in, rewards, objectives and `Stop()`. A completion beat for such a quest should only set that stage; downstream objective, grant or `stop_quest` actions would repeat the fragment's work as separate calls. Andurs' Arkay Amulet (`FreeformWhiterunQuest04`) follows this: its `QUEST_COMPLETE` beat sets stage 200 and has no downstream actions.

A definition may author an optional top-level `completion_stage`. The instance becomes `completed` only when the game reports a `quest_stage` for that same quest at or above it (kept as `observed_stage` in the instance state). Selecting or dispatching a completion beat, its `applied` acknowledgement and the optimistic `current_stage` from `set_stage` never prove completion, so the quest stays `running` until the game's report arrives. Reports for other quests and stages below `completion_stage` have no completion effect. Rollback rebuilds from the retained events with the same rule, so rolling back before the report returns the quest to `running`. Definitions without the field are unchanged, and no terminal stage is inferred for them. Andurs' Arkay Amulet authors `completion_stage` 200.

The plugin reports a `quest_stage`, the location and the inventory for one moment as concurrent `gamedata.php` requests, and each request reads and rewrites every quest instance. `chimQuestEngineWithInstanceLock()` holds a per-quest session advisory lock around each instance's read-modify-write in `chimQuestEngineHandleEventForDefinition()` and around each rollback rebuild, so a request that read before another committed cannot overwrite its observed stage or state. The lock also covers that quest's dialogue intent decision, so a concurrent event for the same quest waits for that call; other quests are not blocked. If the lock cannot be taken the event is still processed unlocked, as before.

Loading a save sends `init`, which resets the quest runtime in `processor/comm.php`; the plugin then resends the current stages, location and inventory. A game report older than the retained history still rolls the runtime back and rebuilds it in `chimQuestEngineHandleEvent()`. A live `dialogue_turn` never does: it carries the game time of the request that produced the reply, which is normally older than reports that arrived while the reply was generated, so it is evaluated against the current state. When its request time is older than the retained history, the turn and any beat or action it fires are recorded at the latest history time (the request time is kept as `request_gamets`), so a later rollback removes them together with the reports they relied on. `chimQuestEngineRequestPrecedesSaveLoad()` ignores the turn instead when the request was sent before the latest `init`, whether it is still waiting for the MAIN lock (its `user_input` marker) or has already reset the runtime (its `init` row), so a reply produced for the replaced save cannot queue actions for the loaded one. Out-of-order game reports from the same moment cannot be told apart from reports sent just after a load, so they still roll back.
