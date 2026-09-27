# Per-NPC plugin data

`core_npc_master.plugin_extended_data` is a JSONB object, initially `{}`.
Use the existing `NpcMaster` API after the server database updates have run:

```php
$npcMaster = new NpcMaster();
$npcMaster->setPluginData($npcId, 'chim_custom', [
    'dirt_and_blood' => ['dirt_level' => 2, 'washing' => false],
]);
$data = $npcMaster->getPluginData($npcId, 'chim_custom');
$npcMaster->deletePluginData($npcId, 'chim_custom');
```

- Resolve an existing NPC's positive database ID in the current playthrough; names are not keys.
- Plugin IDs match `[a-z][a-z0-9_-]{0,63}`. Each plugin owns one top-level namespace.
- `setPluginData` replaces that namespace with a string-keyed PHP array encoded as a JSON object. An empty array stores `{}`. Invalid input throws.
- `getPluginData` returns its top-level associative array, or `null` for an absent NPC/namespace. Nested JSON objects remain `stdClass` objects and arrays remain arrays so a read/write round trip preserves their types.
- Set/delete return `false` for a missing NPC or failed database operation. Deleting an absent namespace on an existing NPC succeeds.
- Writes are single parameterized updates. Other namespaces are preserved; concurrent writes to the same namespace use the last committed update, without a deep merge.
- Generic NPC create/update/upsert operations do not accept this field. Use the dedicated API so stale profile objects cannot replace plugin state.
- Data travels with NPC history and playthrough saves under the existing capture, retention and rollback rules. Older snapshots initialize the field to `{}`. These methods do not create a history record on every call or change game timestamps.
- This is a trusted server-side plugin API, not an HTTP endpoint or a sandbox between installed PHP plugins. It does not automatically inject data into prompts.

CHIM-Custom currently has separate actor-state storage. Adopting this API and migrating that data is a separate change; player state and actors without NPC records need their own handling.
