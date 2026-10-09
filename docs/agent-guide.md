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

## CHIM Interact protocol

Scenery uses the exact loaded camera-ray reference only when no valid normal crosshair target exists. `burning_visual` applies timed fire appearance without actor damage or spread; non-destructible `destroy` disables that reference without debris. Actor emotions and poison remain unsupported on scenery.

Synthetic effects accept any selected item as a narrative prop, or no item. Up to five effects execute sequentially with explicit dependencies. Actor-only `poison`/`burning` use value 1–10 damage per second; `paralysis` uses value 1; `calm`/`fear`/`frenzy` use value 1–100 as the affected level limit. Optional `duration` is 5, 10, 20 or 30 seconds (default 10 for statuses, 0 for other actions). Refresh replaces only the same CHIM status family on that actor. Engine-managed active effects own expiry and save persistence; cancellation stops pending work but does not undo an applied effect. Receipts confirm active application, not guaranteed total damage or full duration. Resistance and immunity may prevent application.

Additional actor effects remain bounded and require native eligibility. `frost`, `shock`, `drain_stamina`, `drain_magicka` and `absorb_health`/`absorb_stamina`/`absorb_magicka` use value 1–10 per second. `slow`/`haste` and `weaken_weapon`/`fortify_weapon` use 1–50 percent; `weaken_armor`/`fortify_armor` use 1–100 armor points. `ethereal` and `soul_trap` use value 1. Frost damages health and stamina equally and includes a slowing component; shock damages health and magicka equally. Slow/haste and weapon percentage modifiers scale against the current statistic when applied. Absorb retains the authored exclusion of Dwarven actors. These statuses use the same 5/10/20/30-second durations and same-family refresh behavior. They confirm initial application only, not future totals, guaranteed behavior or a trapped soul. Standalone `stagger` uses value 0–1 and duration 0 without damage.

`reanimate` targets an eligible corpse (`alive: false`); `turn_undead` targets eligible living undead. Both use value 1–100 as the actor level limit and a supported duration. `banish` targets an eligible currently summoned living Daedra within that level limit with duration 0. These operations do not establish permanent resurrection or invented future behavior. Rally is not supported.

`extinguish`, `neutralize_poison` and `release_paralysis` use value 0 and duration 0, removing only their corresponding CHIM effect; extinguish also handles CHIM scenery fire. They do not undo prior damage or cure unrelated effects. `dispel` uses an integer index into the captured `target.dispellable_spells` list (at most eight entries), with duration 0. The server narrows its range to that list and the client rechecks the exact chosen spell.

Eligible movable non-actor references support `directional_throw` (impulse 1–100, direction `toward`/`away`/`up`), `rotate` (−180–180 degrees, axis `x`/`y`/`z`) and `move` (positive 1–256 units, direction `forward`/`backward`/`left`/`right`/`up`/`down`). All use duration 0 and `alive: false`; no arbitrary coordinates or additional references are accepted. Irrelevant `direction` and `axis` fields are empty strings; older plans may omit them. Move directions use the player’s heading and world vertical axis; throw toward/away uses the player’s position. Placement and collision outcomes require native checks and do not guarantee a resting point. `frost_visual`/`shock_visual` use value 1 and a supported duration; `impact_burst` uses value 1 and duration 0 for a one-second shock-shader burst. These scenery effects are visual only.

A validated empty plan with nonempty failure narration creates a marked failure scene: attempt/outcome history, narration and an eligible NPC response, but no game effects. The server issues a scene token that the native receipt must echo; unmarked legacy empty plans and invalid/transport/stale requests cannot create scenes. Per-step failure prose is used only for confirmed failure; unknown and partial outcomes remain factual.

Interact asks the Director to grant supported effects without plausibility refusals. Actual engine eligibility and execution failures still apply; it cannot fabricate success. There is no mode toggle.

In **Prompt Manager**, search `interact_` to edit `interact_rules` or `interact_narration`. The upgrade keeps the former unrestricted custom rules active and archives retired mode customizations in the rules description. Action descriptions remain code-owned. Clear a custom prompt to restore its default. Eligible actions, numeric limits and receipt validation remain enforced. The universal planning prompt maps intent to the smallest faithful eligible sequence, uses observed properties without inferring traits from names, and keeps execution receipts authoritative. Numeric action limits come from the validated catalog. Guidance refreshes preserve custom prompts and archived mode customizations.

Item data uses explicit units and limits: base weapon damage is not final hit damage, per-item gold value is not barter price, and base armor rating is not final protection. Weight, weapon/armor class, tempering, charge, enchantments and applied poison describe the captured instance. Authored ingredient effects may be undiscovered and are not active effects. Item and selected magic remain independent.


`consume_world` (value zero) requires `item: null` and consumes a single eligible world food/potion as the player; it cannot be combined with another pickup/world-consumption step. Successful narration confirms consumption, without promising a measured healing effect.

`chatnf_interact_reaction` generates through the normal NPC dialogue/voice pipeline as soon as the receipt is committed, alongside Narrator audio preparation. Request-tagged reply chunks remain held in the native client until every expected Narrator chunk has completed playback. Individual chunk acknowledgements do not create history or mark the parent outcome spoken; the native client sends the parent acknowledgement only after all chunks finish and the stream completion marker arrives. The server atomically claims a completed interaction once, matching playthrough session, captured target reference and NPC speaker. It builds the prompt from stored receipt effects/statuses and outcome, never client-supplied prose. No new table or connector is required. The native client suppresses stale, cancelled or interrupted replies and requires the exact actor active in CHIM.

`heal`, `restore_stamina` and `restore_magicka` restore value 1–100 points on a living actor without consuming an item. Actual inventory consumption remains `consume`. `disarm` uses captured hand 0/1; `unequip` uses a captured armor slot 30–61. Both require a living target and exact captured equipment. `drop`/`place` use integer quantity 1–100, with place limited to a verified drop near the same-cell target. No item is created; timed statuses use persistent authored CHIM spells. Supported scroll effects are selected by authored single-effect, non-area delivery and safe archetype/actor-value checks; success requires an observed effect, not just issuing a cast.

`pickup` (value zero, at most one step) takes one eligible loose playable inventory reference into player inventory. It is independent of the optional selected inventory item. `activate` alone does not establish pickup. Native receipts canonicalize the bounded status enum from Papyrus; unrecognized statuses stay uncertain rather than becoming success.

The snapshot carries `item: null` for itemless attempts. The client defaults to **No item**; the server excludes give, store, consume, equip, magic, drop and place for that snapshot and records the attempt/outcome without claiming an item was used. Existing item snapshots remain objects. Independent `selected_magic` is null or the captured known spell, power or unlocked shout, with its name, kind and authored effects. `cast_selected_magic` (value zero, at most once) uses only that selection and may accompany one inventory operation; it never consumes the selected item. Without selected magic, the server excludes that capability.

`item_interaction.php` is a game-client JSON endpoint paired with CHIM's ItemInteraction module. It uses the existing Director connector, playthrough barrier, `rolemaster` request records, and visible `infoaction` Event Log entries; no connector setting or database table is added. Browser-origin submissions are rejected, payloads are bounded, and resolution is capped at six attempts per minute per runtime.

`resolve` requires a random 32-hex request ID, intent, current game snapshot, valid capabilities and game timestamp. It records the attempt before generation. The response contains at most five allowlisted effects with typed values, earlier-step dependencies, alive conditions and conditional narration. The model supplies no reference IDs or executable code. Only one inventory operation is allowed in a sequence. Director JSON schema settings are honored; all outputs are validated independently of provider support.

`receipt` reports each step as succeeded, failed, skipped or unknown. The server records one correlated outcome and returns configured Narrator audio/subtitle metadata. Only succeeded steps use prepared success narration. A repeated identical completed receipt retrieves the same narration without replaying effects or synthesizing again; changed receipts are rejected. Clients may set `defer_audio: true` on `receipt` to receive committed narration metadata immediately, then request `audio` with `stream: true`. This returns newline-delimited JSON: one `{ok,id,narration,chunk_index}` per ready chunk, then `{ok,id,done:true,chunk_count}`. Receipt metadata includes all deterministic chunks; each uses `interact-ID-N` and its own cache key. The shared ordinary-dialogue splitter divides receipt-confirmed prose without another model call. Audio failures set `narration.audio_ready` false; clients must not replay the full narration as a fallback. Retries reuse chunk IDs and caches, and native playback deduplicates them. Both paths retain session checks. `cancel` terminates a pending request without generated dialogue. Native save/load generation checks reject stale replies, while the existing server playthrough barrier scopes history and writes to the active playthrough.

The client interprets operation-specific receipts, not LLM claims of execution. Full mechanical behavior, exact-instance transfers, protected targets, input focus, Narrator playback and save/load cancellation require paired Skyrim validation. Ordinary Event Log observation records remain separate from the correlated attempt/outcome records; Interact does not insert an additional Narrator chat event.

### Interact plugin actions

Plugins can add actions to Interact's existing intent, plan, receipt and narration flow without editing the built-in effect handlers. [lib/interact_extensions.php](../lib/interact_extensions.php) implements both kinds:

- **Game plugin actions** come from a Skyrim mod's Papyrus handler. The handler registers through CHIM's `CHIMInteractExtensions` script, which sends a descriptor with `resolve`. The CHIM [agent guide](https://github.com/Dwemer-Dynamics/CHIM/blob/unstable/AIAgent/docs/CHIM/agent-guide.md#plugin-actions) documents registration, the event and completion.
- **Server plugin actions** are trusted compositions of built-in effects, declared in an installed package. They expand into ordinary steps before the plan is stored or returned. Older clients can run them without a game script.

Both kinds use descriptor version 1:

| Field | Contract |
|---|---|
| `id` | `namespace:action`. Each part is 1–31 characters from `a-z`, `0-9` and `_`, starting with a letter. `chim` is reserved. Every ID contains `:`, so it cannot replace a built-in effect. |
| `description` | 1–240 bytes of UTF-8, trimmed, with no control characters. The prompt escapes it and labels it as plugin-supplied data. |
| `targets` | A non-empty list of distinct values: `living_actor`, `dead_actor`, `object`. |
| `item` | `optional`, `required` or `forbidden`. |
| `min`, `max`, `whole` | Finite JSON numbers within ±1,000,000, with `min` ≤ `max`. If `whole` is true, the range must contain an integer. |

**Game descriptors.** `resolve` may include `extensions`: a list of at most 16 objects with exactly the keys `version`, `id`, `description`, `targets`, `item`, `min`, `max` and `whole`. They are untrusted. A descriptor is accepted only if every field is valid and its ID is unique and also in `capabilities`. Its target and item rules must also match the snapshot. Invalid entries are dropped one by one, and a non-list or oversized payload is ignored. Accepted plugin steps must use the descriptor's bounds, `duration` 0 and empty `direction`/`axis`. They count toward the five-step limit. The receipt reports the plugin's own result, so the Event Log labels it `reported by game plugin, not verified by CHIM`. A `skipped`, `failed` or `unknown` receipt with `source: native` means CHIM skipped the step, could not dispatch it, timed out without a plugin report, or lost the target or registration before recording the report; the Event Log labels it a CHIM lifecycle outcome and leaves the game effect unverified. Any other combination for a game plugin step, including a missing or invalid `source` or a `native` success, is labelled `unconfirmed plugin provenance; game effect not verified by CHIM` and is never treated as CHIM evidence. The endpoint rejects a `succeeded` receipt for a game plugin step unless its `source` is exactly `plugin_reported`, so such a request records no outcome or narration; that label applies only to legacy or malformed stored data.

**Server declarations.** A package opts in by placing `interact_actions.php` directly in `ext/<package>/`. The loader reads the folder names under `ext/` (at most 256 entries) and loads at most 16 declaration files and 32 actions. It loads each file once per request in an isolated scope, discards its output and logs any exceptions. It never loads `functions.php` or other hooks, and adds no dependencies or tables. Packages are visited in name order, and the first one to declare a namespace owns it. A later package using the same namespace is rejected. A server action whose ID matches an accepted game descriptor is skipped. The file must `return` an array with `version` 1, `namespace` and at most 16 `actions`. Each action has a `name`, the descriptor fields above and 1–5 `steps`. A step has:

- `effect`: a built-in catalog effect. Plugin IDs are rejected, so actions cannot nest or recurse.
- `value`: a number, or `"input"` to pass on the planned step's value.
- `alive`: a boolean.
- `requires`: optional indices of earlier steps in the same action.
- `duration`, `direction` and `axis`: optional, with the same meaning as for a built-in step.

No other keys are accepted. When it loads an action, the loader validates its steps with the built-in validator at both value extremes. Fractional values are also checked unless `whole` is true, so invalid bounds, conflicting inventory steps and unsupported selectors are rejected.

A server action is advertised for a request only when every step's effect is in that request's built-in capabilities, after the snapshot narrows them. Each step's value range must also fit the allowed bounds. A server action can therefore never enable a game operation that the client did not offer. During expansion, each server action step becomes its built-in steps. A dependency on that step becomes a dependency on all of them. The whole expanded plan is validated again with the normal rules: at most five steps, one inventory operation, one pickup and one selected-magic cast, and only earlier dependencies. A plan that grows past five steps is rejected without running anything. The expansion record (`compositions`) is stored with the request. The narration is spoken once, if all steps in the group succeed. A confirmed failure of every step uses the group's failure narration. Otherwise each step is reported on its own. These are CHIM-checked receipts, labelled with the action that produced them.

Requests without `extensions`, and servers with no `interact_actions.php` files, behave as before. Their Event Log and narration text is unchanged. Old servers ignore the `extensions` payload and drop plugin IDs from `capabilities`.

This example, `ext/mymod/interact_actions.php`, only returns data; its package supplies no other Interact code.

```php
<?php
return [
    'version' => 1,
    'namespace' => 'mymod',
    'actions' => [
        [
            'name' => 'soothe',
            'description' => 'Calm a living actor, then restore some of their stamina.',
            'targets' => ['living_actor'],
            'item' => 'optional',
            'min' => 10,
            'max' => 50,
            'whole' => true,
            'steps' => [
                ['effect' => 'calm', 'value' => 'input', 'alive' => true, 'duration' => 20],
                ['effect' => 'restore_stamina', 'value' => 25, 'alive' => true, 'requires' => [0]],
            ],
        ],
    ],
];
```

The Director can then choose `mymod:soothe` with a value from 10 to 50, as the calm level limit. The client receives `calm` (duration 20) and then `restore_stamina` 25, which depends on it. Each step's receipt comes from CHIM's own checks.

## Player voice responder decision

CHIM posts to `stt_target.php` only for voice input whose own router would otherwise use its nearest-eligible fallback among two or more NPCs. The endpoint requires the game's JSON transport and playthrough tag, honours the CHIM interaction switch, and accepts at most 8 candidates and a 600-byte transcript. It uses only the dedicated Decision Connector (`CORE_CONNECTOR_DECISION`), while its enable switch is on and `chimIsDecisionConnector()` accepts it; it never reads the legacy Scene Classifier connector and never takes URLs, models or keys from the caller. The state sent to Jev holds the transcript, the candidates' names, distances and view/follower cues, and up to 8 recent `speech` lines with speaker and listener.

The request goes through that connector's shared `jev_request`, which uses the connector's configured model, decisions URL (or the default OpenRouter decisions endpoint) and key. Its optional bounded mode makes one cURL call with a 1500 ms total bound, a 16 KB response cap and no retry, and writes no `audit_request` row, response log or provider-body warning. Called with its original four arguments, `jev_request` keeps its previous timeout, audit rows and response log.

The global `STT_TARGETING_ENABLED` setting (STT Targeting, default on) appears as a toggle directly under the Decision Connector dropdown in Global Settings, beside `DECISION_SCENE_CLASSIFIER_ENABLED` (Scene Classifier, default on), and after the Decision Connector in Prisma. Scene Classifier gates only the Decision Connector's scene genre: with the Decision Connector available and Scene Classifier off, `processor/postrequest.php` skips the history query and provider request, keeps the default genre and does not fall back to Scene Classifier (Legacy); with the Decision Connector unavailable, the legacy rules apply unchanged. It is reusable `general_settings` configuration, not playthrough data. Off answers `abstain` with reason `disabled` before any connector lookup, history query or provider call. It does not affect scene genre classification, the Decision Connector's availability, or STT recording and transcription. A crosshair target and the client's own router still take priority because the client only asks when it would otherwise use its nearest-eligible fallback.

The decision fails open: after the request guards, `chimSttTargetRespond()` turns every failure into an abstention and never exposes error details, and the client also falls back once on HTTP errors, malformed replies or its 2-second deadline. An abstention routes the speech normally; it never cancels it. Stop, newer input, a load, or leaving the cell or area still discard the pending turn on the client, and the interaction generation still rejects output invalidated by switching CHIM interaction Off/On.

The reply is `select` with one offered form ID, or `abstain` with a reason. `not_configured` means the Decision Connector is unset, disabled or not a decision connector; it is reevaluated on every request, so the client keeps asking and a settings change applies to the next voice turn without reloading. `connector_error`, `missing_key`, `no_answer` (transport, HTTP or provider failure), `malformed_answer`, `low_confidence` and `model_abstained` are per-request outcomes. Logs contain only the outcome, form ID, reason, latency and, for transport failures, cURL and HTTP codes; never the transcript, dialogue, provider body or key.

## Quest dialogue intent decision

When a Traditional Quest dialogue turn fires no beat deterministically, `chimQuestEngineSelectDialogueBeatByIntent()` may ask for a semantic match among the still-eligible beats (unfired, prerequisites, conditions, required item and NPC focus already checked), within the existing `CHIM_QUEST_DIALOGUE_INTENT_MAX_CALLS` per-turn budget. With `DECISION_QUEST_INTENT_ENABLED` (Quest Dialogue Intent, default off, a Decision Connector toggle beside STT Targeting and Scene Classifier) off, this remains the existing chat-connector `fast_request`. Radiant templates and concrete radiant instances (`radiant_instance` or `template_quest_key`) always keep that path.

On, the chat connector is never called. The dedicated Decision Connector receives the beat IDs plus `no_match` as choices, described by the existing summaries, intent labels, rules, item gates and examples, with the quest, stage, location and NPC as bounded state. Each criterion asks whether its step happens on this turn, and the instructions have Jev judge each step by the actor its existing summary names: NPC exposition or requests from the NPC reply, player acceptance, refusal, questions, reports and hand-ins only from the player line (never inferred from the NPC's request or reply), with steps naming no actor judged from the player line. The refusal, condition, negation and quoted-speech protections apply to player steps. Only one beat fires per turn, so prerequisite ordering holds: an exposition beat can fire on one turn and the acceptance beat that requires it is offered from the next. Stage rehydration does not backfill a beat whose stage equals the current stage when a beat that really fired (not a backfill) already set that stage, so an acceptance beat sharing its exposition's stage stays offered; a game-reported higher stage still backfills it. The player line and NPC reply are sent whole after whitespace normalisation; invalid UTF-8 or more than 2000 bytes abstains rather than truncating. More than 8 eligible beats or a request over 15 KB abstains instead of trimming candidates. The call uses `jev_request`'s bounded mode (1500 ms, 16 KB, no retry). A beat advances only when the choice is an offered ID with finite confidence in 0..1 at or above the beat's threshold (default 0.50 unless the beat sets `intent_min_confidence` or its `dialogue_intent` trigger sets `min_confidence`), never below 0.5; an unavailable connector, missing key, transport failure, malformed answer, `no_match` or low confidence leaves the quest unchanged. Connector globals hydrated for the call are restored afterwards. Logs record only the quest key, beat ID, outcome, an allowlisted reason code, confidence and elapsed time; any other exception is logged as `decision_error` with its class name, never its message.

A streamed reply reaches the engine once per `returnLines()` chunk. `chimQuestEngineLiveDialogueReply()` keeps the reply assembled so far for one root request only (NPC, request ts, request type, player line and original request game time, not the later time a delayed turn is recorded at), so Jev reads the reply so far while dialogue events, beat evidence and `last_dialogue` keep each chunk. A new turn, NPC or a rejected pre-save-load request replaces it; the oldest whole chunks are dropped to stay within 2000 bytes. The player line is unchanged and the deterministic triggers and chat path are untouched. With Jev, chunks use all but the last call of the per-turn budget; a later chunk defers. In `main.php` after `call_llm()`, `chimQuestEngineEndLiveDialogueTurn()` passes only valid output with no `ERROR_TRIGGERED` or `FORCED_STOP`, interaction still allowed and no newer player input to `chimQuestEngineFinishLiveDialogueTurn()`, which spends that call on the complete reply after repeating the save-load check, without recording the event again or re-running deterministic triggers. Failed, stopped or superseded output clears the assembled reply unjudged, as does a request that ends before then.

With Jev, each live turn of a Traditional quest is also kept in that quest instance's `state_json.recent_dialogue` by `chimQuestEngineRecordRecentDialogue()`: the newest whole turns, at most 3 and 2000 bytes in total, chunks of one root turn deduplicated into one entry holding the reply assembled so far, and a turn whose player line and reply exceed 2000 bytes is not kept. `chimQuestEngineRecentDialogueContext()` sends earlier turns with the same NPC, sent before the current request, as `recent_conversation` (whole turns, newest first within 2000 bytes, oldest first in the request); any malformed or out-of-order entry sends none. The instructions say earlier turns only explain what the current lines refer to, so an earlier acceptance never fires a step: the current player line or NPC reply must. The history adds no queries (it rides on the instance row already read and written per chunk); rollback rebuilds and save-load resets start from the default state, so it is cleared with them, and pre-load turns are never recorded. Legacy chat and radiant quests record and send none.

A beat may list `implied_prerequisites`: earlier conversation beats its own dialogue necessarily includes. With Jev only, such a beat is offered while those prerequisites are unfired when each is a dialogue beat with the same focus NPC (not `trigger_mode` all, no required item, its own prerequisites, conditions and natural-start rules met); game gates, item gates and other NPCs' beats are never implied. Its criterion says the earlier step is unrecorded and must be clearly present in the NPC reply or recent conversation. When chosen, each implied beat is marked fired first with `implied_prerequisite` evidence and no action, downstream action or stage change; only the selected beat's own action is queued. MS07 Lights Out! uses this: `ACCEPT_JAREE_RA_OFFER` (player acceptance, stage 50) implies `JAREE_RA_OFFER` (Jaree-Ra explaining the job, stage 10). Its stages were checked in Skyrim.esm and USSEP: 10 shows the offer objective, 50 is acceptance (Fragment_42), 100 is the fire put out (lighthouse fire alias OnActivate, prerequisite 50), 125 is reporting back to Jaree-Ra, 150 Deeja attacks, 175 Deeja dead, 200 note read, 225 Broken Oar entered, 250 done. `MS07LampScript`'s stages 30 and 60 do not exist in MS07 and its activator is never placed; objective numbers are not stages.

## Quest action dispatch

Beat actions are queued in `skyrim_quest_action_outbox` and polled by the CHIM plugin, which runs each polled action as its own Papyrus call. An `applied` acknowledgement means the call was dispatched, not that the game state changed. Objective actions accept `index`, `objective_index` or `objective` in definitions. For `set_objective_*`, `cross_quest_set_objective_completed` and the `*start_quest_stage_objective` actions, `chimQuestEngineNormalizeObjectiveActionPayload()` fills the integer `index` and `objective_index` the plugin reads, at queue time and again at poll time for older pending rows. Only whole values from 0 to 2147483647 count; INF, NAN, signs, decimals and longer digit strings are ignored. The first valid value in the order `index`, `objective_index`, `objective` fills a missing or invalid field, and a field that already holds a different valid value keeps it. Other action types, including `fail_all_objectives`, are left as queued.

Vanilla completion stages normally apply their own hand-in, rewards, objectives and `Stop()`. A completion beat for such a quest should only set that stage; downstream objective, grant or `stop_quest` actions would repeat the fragment's work as separate calls. Andurs' Arkay Amulet (`FreeformWhiterunQuest04`) follows this: its `QUEST_COMPLETE` beat sets stage 200 and has no downstream actions.

A definition may author an optional top-level `completion_stage`. The instance becomes `completed` only when the game reports a `quest_stage` for that same quest at or above it (kept as `observed_stage` in the instance state). Selecting or dispatching a completion beat, its `applied` acknowledgement and the optimistic `current_stage` from `set_stage` never prove completion, so the quest stays `running` until the game's report arrives. Reports for other quests and stages below `completion_stage` have no completion effect. Rollback rebuilds from the retained events with the same rule, so rolling back before the report returns the quest to `running`. Definitions without the field are unchanged, and no terminal stage is inferred for them. Andurs' Arkay Amulet authors `completion_stage` 200.

A `gate` beat with no downstream actions may author `observed_stage_checkpoint`: an integer equal to the `min_stage` of one of its own `quest_stage` triggers for that quest, verified to mean the physical step happened. Any other value or beat shape is ignored. Stage rehydration then records the gate (as `state_backfill` evidence with the observed and checkpoint stages, queuing nothing) once the game reports that quest at or above the checkpoint (`observed_stage`) and the gate's prerequisites have fired and its conditions and required item are met, including while player-only advancement is on. The optimistic `current_stage`, dialogue and Jev never record it, and backfilling a later `set_stage` beat from `current_stage` stops at an unobserved checkpoint gate rather than inferring it. Rollback removes it with the report it relied on. MS07 authors 100 (fire put out), 150, 175, 225 and 250, so after the game reports 100 the player's report back to Jaree-Ra can queue stage 125.

The plugin reports a `quest_stage`, the location and the inventory for one moment as concurrent `gamedata.php` requests, and each request reads and rewrites every quest instance. `chimQuestEngineWithInstanceLock()` holds a per-quest session advisory lock around each instance's read-modify-write in `chimQuestEngineHandleEventForDefinition()` and around each rollback rebuild, so a request that read before another committed cannot overwrite its observed stage or state. The lock also covers that quest's dialogue intent decision, so a concurrent event for the same quest waits for that call; other quests are not blocked. If the lock cannot be taken the event is still processed unlocked, as before.

Loading a save sends `init`, which resets the quest runtime in `processor/comm.php`; the plugin then resends the current stages, location and inventory. A game report older than the retained history still rolls the runtime back and rebuilds it in `chimQuestEngineHandleEvent()`. A live `dialogue_turn` never does: it carries the game time of the request that produced the reply, which is normally older than reports that arrived while the reply was generated, so it is evaluated against the current state. When its request time is older than the retained history, the turn and any beat or action it fires are recorded at the latest history time (the request time is kept as `request_gamets`), so a later rollback removes them together with the reports they relied on. `chimQuestEngineRequestPrecedesSaveLoad()` ignores the turn instead when the request was sent before the latest `init`, whether it is still waiting for the MAIN lock (its `user_input` marker) or has already reset the runtime (its `init` row), so a reply produced for the replaced save cannot queue actions for the loaded one. Out-of-order game reports from the same moment cannot be told apart from reports sent just after a load, so they still roll back.
