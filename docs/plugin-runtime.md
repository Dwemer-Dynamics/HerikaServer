# Plugin runtime reference

Start with [custom plugins](custom-plugins.md) for repository and package layout. This guide describes the current `unstable` implementation; check the linked callers against the server version your plugin supports. These PHP extensions run with server privileges. The examples below do not install a plugin or change CHIM's runtime.

## Hook order and timing

[main.php](../main.php) has early exits. Its hooks are not an event bus: reaching one hook does not guarantee that later hooks run. In particular, `_speech` reaches `prerequest.php`, is handled in [processor/comm.php](../processor/comm.php), then exits through the non-LLM path before the context and post-request hooks.

| Hook | When reached / input to inspect | Small diagnostic example |
|---|---|---|
| `globals.php` | After runtime bootstrap, before `$gameRequest` is parsed. Register definitions here. | Log the stage only. |
| `preprocessing.php` | After request parsing and dialogue-tag normalization; before Player re-speech and later dispatch. | Log the request type. |
| `prerequest.php` | After earlier routing/normalization; before `processor/comm.php`. Some events already exited. | Inspect `_speech` JSON using the example below. |
| `context_pre.php` | On the dialogue path, before building the system prompt. | Register a prompt contribution as shown below. |
| `context.php` | Later in that dialogue path, after system-prompt construction. | Log the current request type; do not assume raw Player input. |
| `prepostrequest.php` | Near the end of the dialogue path, before core `processor/postrequest.php`. | Log that this stage was reached. |
| `postrequest.php` | After core post-request processing, if execution reaches it. | Log the stage; this is not confirmation that audio played. |

Other hooks are called from separate builders, not a single linear sequence:

| Hook | Caller / input boundary | Small diagnostic example |
|---|---|---|
| `context_building.php` | [lib/data_functions.php](../lib/data_functions.php), during context construction. | Log the stage; inspect the caller before assuming builder-local variables are available. |
| `prompts.php` | [prompts/prompts.php](../prompts/prompts.php), while loading prompts. | Log the stage. |
| `dialogue_prompt.php` | [prompts/dialogue_prompt.php](../prompts/dialogue_prompt.php), during dialogue prompt setup. | Log the stage. |
| `json_response_custom.php` | [functions/json_response.php](../functions/json_response.php), during response-schema setup. | Log the stage; this is not the finished model response. |

For a minimal working timing probe, put this code in **one** of the named files under your test plugin. Change the stage label to its filename. Run a matching request in a disposable installation, then inspect the server log. Remove the probe after testing.

```php
<?php
$exampleRequest = $GLOBALS['gameRequest'] ?? null;
$exampleType = is_array($exampleRequest) && is_string($exampleRequest[0] ?? null)
    ? $exampleRequest[0] : '(not parsed)';
Logger::debug('[ExamplePlugin] stage=prerequest type=' . $exampleType);
```

The [recursive loader](../lib/data_functions.php) includes files inside its own function scope and explicitly imports `$gameRequest`. It does **not** pass all of the caller's local variables to hooks. Use documented globals or load your own dependencies. A hook's `return` returns from that file; `exit`/`die` ends the whole request. Do not emit debug text with `echo`.

For a small prompt contribution, use this as `context_pre.php`. It adds text only when the dialogue path reaches the hook. The registration and rendering contract lives in [lib/prompt_injections.php](../lib/prompt_injections.php).

```php
<?php
require_once $GLOBALS['ENGINE_PATH'] . 'lib/prompt_injections.php';
chimRegisterPromptInjection(
    'character_bottom',
    'example_plugin.test_context',
    'A blue ribbon is tied to the nearby gate.',
    100
);
```

Injected context is prompt material, not proof that a character said or heard it. Keep that distinction when testing gossip or other dialogue-driven effects.

## Request state and speech acknowledgements

For requests parsed by `main.php`, `$gameRequest[0]` is the event type, `[1]` the client timestamp, `[2]` the game timestamp, and `[3]` the event-specific payload. These come from the client. Validate their type and meaning before using them; do not assume every payload is JSON or every event has the same extra fields.

For `_speech`, the payload is a JSON object. The current handler reads `speaker`, `listener`, `speech` and `location` directly. Optional values include `utterance_id`, `audios`, `companions`, `distance`, `spatial_volume`, `spatial_reason` and `debug`. Their absence is different from a malformed required field. Do not infer an audience from names mentioned in the text.

This small `prerequest.php` example observes acknowledgements without changing them:

```php
<?php
$exampleRequest = $GLOBALS['gameRequest'] ?? [];
if (!is_array($exampleRequest) || ($exampleRequest[0] ?? '') !== '_speech') {
    return;
}
$examplePayload = $exampleRequest[3] ?? null;
if (!is_string($examplePayload)) {
    return;
}
$exampleSpeech = json_decode($examplePayload, true);
if (!is_array($exampleSpeech)) {
    return;
}
foreach (['speaker', 'listener', 'speech', 'location'] as $exampleField) {
    if (!isset($exampleSpeech[$exampleField]) || !is_string($exampleSpeech[$exampleField])) {
        return;
    }
}
$exampleId = is_string($exampleSpeech['utterance_id'] ?? null)
    ? trim($exampleSpeech['utterance_id']) : '';
Logger::debug('[ExamplePlugin] speech acknowledgement: '
    . ($exampleId === '' ? 'without utterance ID' : 'with utterance ID'));
```

Example payloads for that hook (synthetic fixtures, not a transport command):

```json
{"speaker":"Lydia","listener":"Player","speech":"Good morning.","location":"Whiterun"}
```

```json
{"speaker":"Lydia","listener":"Player","speech":"Good morning.","location":"Whiterun","utterance_id":"example-001","companions":["Player"]}
```

Both pass the observer. The core stores an empty ID when one is absent and has fallback matching logic. An ID identifies an utterance, not necessarily a unique plugin operation: scope duplicate prevention to the listener/effect as appropriate. Without an ID, either skip effects requiring exact correlation or define and test a fallback identity; text alone can collapse distinct repeated lines. See [speech tracing](speech-tracing.md) for delivery diagnostics.

Player input needs a separate timing decision. `preprocessing.php` sees it before optional Player re-speech; `prerequest.php` sees later state, which can have rewritten text or a changed event type. Inspect the incoming payload at the stage your feature needs. Do not query for the newest database dialogue row and assume it belongs to this request. Request receipt, model generation and client speech acknowledgement are separate events.

### Missing playthrough state

Do not require an active Playthrough Saves profile just to observe an event. If a feature needs a durable playthrough identity and none is available, skip that feature with a bounded diagnostic or use an explicitly documented plugin-owned scope. Do not invent profile ID `0`, silently choose another profile, or create a save as a side effect of a hook.

[NPC plugin data](plugin-npc-data.md) follows the NPC history/playthrough rules. Arbitrary plugin tables do not become saved data simply because they use the `plugins` schema. Review [lib/playthrough_policy.php](../lib/playthrough_policy.php) before deciding what is global, restored, or discarded. A separate Skyrim save alone does not isolate CHIM's current database.

[lib/playthrough_runtime.php](../lib/playthrough_runtime.php) coordinates requests and built-in workers during switches. Long-running custom work needs an explicit stale-job policy; a numeric NPC ID captured before a switch is not sufficient authority to write afterward. Do not call internal switch functions to manufacture a profile or bypass a switch in progress.

## Atomic writes

For one logical effect, the required invariant is: **the state change, duplicate-prevention record and history entry all commit, or none do**. Complete any model/network call before the write transaction, then re-read and validate the affected state inside it.

- Use one connection and checked query results for the entire transaction. PostgreSQL does not nest `BEGIN` transactions; use a savepoint only when the caller explicitly owns the outer transaction.
- Use parameterized values and a unique constraint for the operation identity. A prior `SELECT` alone cannot prevent concurrent duplicates.
- Coordinate with existing writers, re-read after acquiring the relevant lock, and preserve unrelated JSON fields. Respect `extended_data.relationships_locked` for automatic relationship changes. This is different from locking an NPC profile.
- Treat no change as a valid outcome. Decide whether your history records evaluated-but-rejected effects, and make that decision consistent with duplicate handling.
- Check failure returns as well as exceptions. In [lib/postgresql.class.php](../lib/postgresql.class.php), `execQuery()` returns an empty string on success and an error string on failure; it is not a boolean success API. Do not assume the wrapper exposes `beginTransaction()` or a public connection getter.

[RelationshipManager::setRelationship()](../lib/relationship_manager.php) is a direct/admin setter. It is not a lock-aware transaction API for automatic gossip plus plugin history. Likewise, `NpcMaster::setPluginData()` performs a namespace write, not a transaction spanning your other writes. Do not wrap work on a separate PostgreSQL connection and assume these helpers join it. A direct core-data integration must also preserve CHIM's relationship validation, history/timeline behavior and playthrough safeguards; the toy example below is not that integration.

### Runnable transaction exercise

Run these SQL blocks on **one connection to a disposable PostgreSQL database**. They use temporary tables only; do not substitute CHIM table names. This demonstrates atomic state/history/deduplication without changing a real NPC. Production plugin-owned tables belong in the `plugins` schema with ordered migrations and a defined retention policy.

```sql
CREATE TEMP TABLE example_effects (
    scope text NOT NULL, event_id text NOT NULL,
    PRIMARY KEY (scope, event_id)
);
CREATE TEMP TABLE example_affinity (
    scope text NOT NULL, listener_id bigint NOT NULL, subject_id bigint NOT NULL,
    affinity integer NOT NULL CHECK (affinity BETWEEN -100 AND 100),
    PRIMARY KEY (scope, listener_id, subject_id)
);
CREATE TEMP TABLE example_history (
    scope text NOT NULL, event_id text NOT NULL,
    listener_id bigint NOT NULL, subject_id bigint NOT NULL, affinity integer NOT NULL,
    PRIMARY KEY (scope, event_id)
);
```

The example operation key represents one effect on one listener/subject pair. A real plugin must include those identities in its deduplication key when one utterance can produce multiple effects. Parameterize the fixture values when adapting this statement.

```sql
BEGIN;
WITH claimed AS (
    INSERT INTO example_effects (scope, event_id)
    VALUES ('test-session', 'effect-001')
    ON CONFLICT DO NOTHING
    RETURNING scope, event_id
), changed AS (
    INSERT INTO example_affinity AS current (scope, listener_id, subject_id, affinity)
    SELECT scope, 1, 2, 5 FROM claimed
    ON CONFLICT (scope, listener_id, subject_id) DO UPDATE
    SET affinity = LEAST(100, GREATEST(-100, current.affinity + EXCLUDED.affinity))
    RETURNING scope, listener_id, subject_id, affinity
)
INSERT INTO example_history (scope, event_id, listener_id, subject_id, affinity)
SELECT changed.scope, claimed.event_id, listener_id, subject_id, affinity
FROM changed JOIN claimed USING (scope);
COMMIT;
```

After one run, affinity is `5` with one effect and one history row. Repeating the operation leaves those values unchanged. To test rollback, repeat with a new event ID and replace `COMMIT` with `ROLLBACK`; none of that operation's three writes should remain. On any statement error, roll back and report failure rather than continuing to commit or marking the job complete.

## Install and update routes

Choose one route for each server plugin. Both write into `ext/<name>`, but they use different archive formats and update bookkeeping.

| Route | Package and trigger | Update rule |
|---|---|---|
| Plugin Manager listing | Select a [discovered `chim-plugin` repository](custom-plugins.md#list-a-plugin-in-plugin-manager) and channel, then confirm; [ui/server_plugin_installer.php](../ui/server_plugin_installer.php) downloads a `.tar.gz`/`.tar` archive with `manifest.json` at the extracted plugin root. | Uses the catalog/channel manifest and replaces the plugin directory. Do not assume schema-4 mutable-path preservation applies here. |
| Game-carried package through MO2 | Install the author's game archive into MO2. Its virtual Data tree contains `CHIM/server-plugins/<name>/<version>.dwpkg` (or `.zip`). CHIM sends the schema-4 package to [ui/api/plugin_packages.php](../ui/api/plugin_packages.php). | The package manager checks exact version equality against its own installed-package record. A different version requests an upload, including an older version. Declared `server.mutable_paths` are preserved by this package manager. |

Catalog walkthrough:

1. Back up plugin configuration as described by its author. Disable any older **server-package copy** supplied through MO2 before switching routes; keep separately required game-side components.
2. Open Plugin Manager, select the plugin and intended channel, then install/update.
3. Check the installed manifest/version and installer result. Test one known interaction and inspect the plugin's log.
4. Use that same channel/route for later updates unless deliberately switching.

MO2 walkthrough:

1. Use the author's MO2/game archive, not the catalog tarball or a bare server payload. Check its Data tree: the package belongs under `CHIM/server-plugins/<name>/<version>.dwpkg`.
2. A CHIM-only archive may trigger MO2's data-layout warning because it has no conventional game assets. Verify the path and follow the package author's instructions; do not move PHP files into Skyrim's Data root or ignore warnings for arbitrary archives.
3. Enable one intended package per plugin, launch Skyrim through MO2, and connect to the intended CHIM server. Startup sync installs the package when the package ledger requires it.
4. Check `[SERVER_PLUGIN_SYNC]` in the client log and the installed server version. For an update, replace the old package with the new one and verify again after launch.

The client [ServerPluginSync.cpp](https://github.com/Dwemer-Dynamics/CHIM/blob/unstable/Plugin/ServerPluginSync.cpp) selects the newest **file modification time** when multiple packages are present for a plugin, not the highest semantic version. Avoid leaving multiple versions enabled.

Catalog updates do not update the game-carried package ledger. Consequently, an old MO2 package may be skipped when its ledger version still matches, or uploaded when it differs/is missing. There is no reliable “newest version wins” rule across both routes. Removing the MO2 package stops future discovery; it is not a server-plugin uninstall. Do not edit the ledger to force a repair: choose the intended route, reinstall/update through it, and verify the installed files/version.

## Background model calls

The built-in relationship system is a concrete example of queuing work outside the dialogue request. Its `_rel*` functions and worker are feature internals, not a general plugin job API. Study the flow; give a custom plugin its own queue, process identity and lifecycle rather than enqueueing unrelated jobs into relationship tables.

| Stage | Working reference | What to learn |
|---|---|---|
| Enqueue | [postrequest.php](../ext/relationship_system/postrequest.php) and [_relQueueEvaluation](../ext/relationship_system/async_queue.php) | Capture the necessary request context and return without waiting for a model call. The built-in queue can replace pending work per NPC; it is not an every-event history queue. |
| Start | [context_pre.php](../ext/relationship_system/context_pre.php) | Feature/connector checks and `_relEnsureWorkerRunning()` startup. |
| Bootstrap and consume | [worker.php](../ext/relationship_system/worker.php) | CLI configuration/database setup, bounded batches, one-shot versus daemon execution. |
| Call model and apply | [relationship_llm.php](../ext/relationship_system/relationship_llm.php) | Connector setup, parsing, re-reading state and relationship-lock handling. |
| Failure and switch handling | [async_queue.php](../ext/relationship_system/async_queue.php), [playthrough_runtime.php](../lib/playthrough_runtime.php) | Retry tracking and the built-in worker's refresh boundary. These do not automatically protect a custom worker. |

To exercise that existing example, use an isolated server with disposable relationship data, enable its relationship system, configure a working relationship connector, and produce a qualifying dialogue interaction. Inspect the queue and `log/relationship_worker.log`. If testing one-shot consumption, stop its daemon through that test installation's normal process controls before running this command from the server root:

```sh
php ext/relationship_system/worker.php
```

This is a real worker, not a dry run: it can call a paid model and change relationships. Do not run it against a live database as a documentation check. Its `--daemon` mode runs continuously; the default processes one batch, not necessarily the entire backlog.

For a custom worker, define these decisions before packaging it:

1. Capture stable operation/actor identities and the playthrough scope at enqueue time. Keep payloads bounded and avoid copying secrets into the queue.
2. Claim jobs so two workers cannot apply the same effect. Bound attempts, provider timeouts and retry delays; distinguish retryable failures from rejected/invalid output.
3. Make the model call outside the database transaction. Revalidate scope, current state and locks before committing the effect and completion record together.
4. Handle interruption, server shutdown and playthrough changes. Do not reuse the relationship worker's PID/lock files or assume its restart helpers support arbitrary plugins.
5. Log operation IDs and outcomes without echoing into dialogue output. Test crash-after-commit redelivery, duplicate jobs, no active profile, locked relationships and provider failure before claiming reliability.

## Validation before release

Lint each extracted PHP example with `php -l`. Exercise the observer with both JSON fixtures, malformed input and an unrelated request; it should produce no response output or data writes. Test transaction success, replay and rollback in a disposable database. Test each supported installer route independently and then deliberately switch routes in a disposable installation.

Use the [existing package-manager tests and safe test instructions](building.md). A passing documentation/example check does not establish live gameplay, MO2 warning behavior, provider reliability or compatibility with every released CHIM version. Record plugin/client/server versions, speaker/listener/subject, input route, expected versus actual result, and relevant filtered logs when reporting those tests.
