# Actor identity in event participants

Status: ingestion and central context retrieval are wired server-side (pass S2); the CHIM client producer, role storage and most readers are pending. See [Pipeline status](#pipeline-status).

## Keys

| Key | Meaning |
|---|---|
| `ref:<plugin>\|<local>` | A placed reference. `<plugin>` is the plugin file name with ASCII letters lowercased (`skyrim.esm`); `<local>` is the eight-digit uppercase local FormID (`0001BDE8`, at most `00FFFFFF`). |
| `player` | The player character. |
| `narrator` | The Narrator. |
| `dyn:<uuid>` | Reserved for a future client-assigned persistent key for dynamic (`FF`) actors: lowercase 8-4-4-4-12 hex, not the nil UUID. |

Keys must already be canonical; anything else is treated as having no identity. Runtime FormIDs, `FF` IDs, base IDs, `runtime:` selectors and display names are never keys. Unrelated actors with the same name keep different keys. The server never assigns a key by matching names.

## Stored format

`eventlog.people` stays a text column. Identity format 2 is a JSON array written without leading whitespace:

```json
[{"name":"Astrid (busy)","id":"ref:skyrim.esm|0001BDE8"},{"name":"Lydia"}]
```

- `name` is the display name recorded for this event, including a status suffix such as ` (busy)`, ` (hostile)`, ` (in combat)` or ` (restrained)`. It is a snapshot and is not rewritten after a rename. It must be non-blank, at most 256 characters, and contain no control characters. `|` is allowed: the object keeps the name apart from the key.
- `id` is optional. Without it the participant is name-only and unresolved.
- Repeated keys keep the first entry. Name-only entries are deduplicated by exact name.
- Text that is not a JSON array, including legacy `|Name|Other|` lists and bare names, is legacy name-only data. Legacy lists still split on `|`.

## Reading stored rows (tolerant, display only)

`chimParseEventParticipants()` reads history as it is and never rewrites it:

- An entry keeps its key only if it is an object with a valid `name` and a canonical `id`. PHP and `public.chim_eventlog_actor_keys()` apply the same rule, so they report the same keys.
- Objects with an invalid `name` are skipped. Objects with an invalid or missing `id` are shown name-only. String entries are shown name-only.
- Text starting with `[` that PostgreSQL `jsonb` rejects, such as malformed JSON or a `\u0000` escape, has no keys. It is shown as a legacy name list, because legacy writers may store a bare name that starts with `[`.
- No key is ever guessed from a name, a runtime ID or a malformed entry. Known residual differences: a document nested deeper than PHP's 512-level `json_decode` limit parses only in PostgreSQL. A number beyond PostgreSQL's `numeric` range, or nesting past its stack limit, parses only in PHP. Writers never produce either.

## Strict boundaries

Client ingress and serialization never accept a partial audience. They throw `ChimEventIdentityException` (an `InvalidArgumentException`) with a fixed `reason` and, where it applies, the zero-based participant `index`:

| `reason` | Cause |
|---|---|
| `unsupported_version` | `identity_version` is present but is not the integer `2` |
| `participants_invalid` | `participants` is missing or is not a JSON array |
| `participant_invalid` | An entry is not an object, has no `name`, or has fields other than `name` and `id` |
| `participant_name_invalid` | `name` is not a valid name |
| `participant_id_invalid` | `id` is present but not a canonical key; `null` counts as provided for client payloads |

The caller must reject the event explicitly. It must not fall back to legacy name routing.

## Client payload capability

A client event opts in by adding both fields to its JSON event metadata:

```json
{"identity_version":2,"participants":[{"name":"Astrid","id":"ref:skyrim.esm|0001BDE8"},{"name":"Bandit"}]}
```

`chimEventIdentityParticipants($payload)` has three results:

- `null`: `identity_version` is absent. The client is legacy and `participants` is ignored.
- A list of participants, possibly empty: every entry was valid. Omitting `id` leaves that participant unresolved.
- `ChimEventIdentityException`: the event must be rejected.

## Server helpers

| Helper | Location | Purpose |
|---|---|---|
| `chimIsActorKey($key)` | `lib/core/npc_reference.php` | Strict canonical key check |
| `chimActorKeyFromReference($source)` | same | `Plugin.esp\|1234` → `ref:plugin.esp\|00001234` |
| `chimNpcRowActorKey($row)` | same | Key from a profile row's `metadata.refid_source`; null otherwise |
| `chimParseEventParticipants($people)` | same | Tolerant display read: `['version' => 1\|2, 'participants' => [['name','base_name','status','id'], ...]]` |
| `chimEventParticipantKeys($people)` | same | Keys in first-appearance order |
| `chimSerializeEventParticipants($list)` | same | Writes format 2 through the strict boundary. Accepts parsed participants, where `'id' => null` means unresolved |
| `chimEventIdentityParticipants($payload)` | same | Strict client ingress: `null`, a validated list, or `ChimEventIdentityException` |
| `chimNpcProfileActorKeys($actor)` | `lib/core/npc_profile_sharing.php` | Keys for the actor and the explicitly linked references sharing its kept profile |
| `chimBuildEventLogActorKeysWhereClause($db, $keys, $column)` | `lib/eventlog_helper.php` | Indexed exact-key SQL condition; `FALSE` without valid keys |

## Database

PostgreSQL uses `public.chim_eventlog_actor_keys(people)` from `data/eventlog_actor_identity.sql`, with the partial GIN index `idx_eventlog_actor_keys` covering rows that start with `[`. The function never raises on legacy or malformed text. The index depends on it, so do not change its results in place.

`debug/db_updates.php` applies the file under version key `eventlog_actor_identity` `20260930001`. It reruns this idempotent file if the function or index is missing. `lib/runtime_bootstrap.php` requires that version, the `eventlog` table, the function and the index before a request continues. Otherwise it runs the database updates first. Consumers can therefore rely on the SQL once bootstrap has run.

Playthrough saves, based on `lib/schema_clone_function.sql`, `lib/playthrough_upgrade.sql`, `lib/playthrough_selection.sql` and `lib/playthrough_transfer.php`:

- Capture clones selected tables with `CREATE TABLE ... (LIKE ... INCLUDING ALL)`, so saves made after this migration carry the index. It still references `public.chim_eventlog_actor_keys`.
- Saves made earlier, and imported archives, have no index. Imports build raw tables from column definitions only. Their private stages copy the saved table, so they have no index either. Nothing queries a stage by actor key.
- Restore, and import validation's rolled-back restore, delete and insert rows in the existing `public.eventlog`. PostgreSQL maintains the live index for inserted rows. Because the function never raises, malformed history cannot fail a restore.
- Neither the table policy nor the playthrough SQL API changes.

Linked references share memories, relationships and settings through the existing profile owner. Each event still records the physical key of each participant.

## Pipeline status

- Wire: the client adds `identity_version`, `participants` and optional `speaker_key`, `listener_keys` (list) and `target_key` to the base64 JSON object already sent in request field 4 (`gameRequest[4]`, the player routing snapshot). Registration (`addnpc`) may send `actor_key` (`dyn:` only) in the same field.
- `main.php` validates with `chimDecodeEventIdentityField()`/`chimDecodeRegistrationActorKey()` before any eventlog write. Invalid opted-in events get HTTP 422 and `ERROR: invalid event identity (<reason>)`; role failures use `role_key_invalid:<field>`. Field 4 values that are not base64 JSON objects stay legacy.
- `logEvent()` and `resolvePeopleForIncomingEvent()` store the captured audience through `chimCapturedEventPeople()`. Forced legacy lists narrow it by exact name; a name matching one captured entry keeps its key, namesakes and uncaptured names stay unresolved, except the player and Narrator. Legacy requests are unchanged.
- Role keys are validated and kept in `$GLOBALS['CHIM_EVENT_IDENTITY']` for the request only. They never grant audience. There is no eventlog column for them yet.
- `addnpc` stores a validated `dyn:` `actor_key` in metadata only when the row has no `refid_source`, and never overwrites a different stored key. `chimNpcRowPhysicalKey()` prefers the placed reference.
- `buildHistoricContext()` and the eventlog queries in `DataLastDataExpandedForNPC()` use `chimBuildNpcContextPeopleWhereClause()`: exact keys for format-2 rows, legacy name rows only when one registered profile owner has that name. Namesakes without a captured key get no actor-specific legacy rows. `info_timeforward` and the held-item rule are unchanged.
- `parsePeoplePipeList()` returns display names for format-2 rows, so pipe audience builders do not receive JSON.
- Still name-based: `speech.companions` in `DataLastDataExpandedForNPC()`, `DataLastDataFor()`, `lib/chat_helper_functions.php` (latest SOT people lookup and `people LIKE` queries), `processor/oghma.php`, `ui/api/chim_npc_manager.php`, diary, memory, relationship and web editors, and background workers that log without a request capture.
