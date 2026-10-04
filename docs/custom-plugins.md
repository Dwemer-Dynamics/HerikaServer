# Making custom HerikaServer plugins

Use a separate repository for an extension. Install its server payload under `ext/<name>/`; do not develop in a deployed copy or force ignored extension files into HerikaServer. These are trusted PHP extensions running with server privileges, not sandboxed model tools.

## Choose a hook from the current code

`requireFilesRecursively` in [lib/data_functions.php](../lib/data_functions.php) loads named files beneath `ext/`. The caller determines the available variables and ordering. Start with the actual caller in the target server version:

| Hook | Caller / purpose |
|---|---|
| `globals.php` | [main.php](../main.php): early registration/shared definitions |
| `preprocessing.php`, `prerequest.php` | `main.php`: before later request dispatch |
| `context_pre.php`, `context.php` | `main.php`: context contributions at different stages |
| `context_building.php` | `lib/data_functions.php`: context-building integration |
| `prompts.php`, `dialogue_prompt.php` | [prompts/prompts.php](../prompts/prompts.php), [prompts/dialogue_prompt.php](../prompts/dialogue_prompt.php) |
| `json_response_custom.php` | [functions/json_response.php](../functions/json_response.php): custom JSON response integration |
| `prepostrequest.php`, `postrequest.php` | `main.php`: end-of-request hooks |

Do not assume every endpoint executes every hook. Keep hook code bounded, avoid logging secrets, and fail cleanly when optional mods/providers or data are absent. Do not echo diagnostics into a streamed response. Namespaces/function prefixes prevent collisions with other installed extensions.

See [Plugin runtime reference](plugin-runtime.md) for hook examples and timing, optional speech/playthrough state, atomic writes, install/update routes, and background model calls.

## Maintained examples

- [CHIM-Custom](https://github.com/Dwemer-Dynamics/CHIM-Custom): optional Skyrim-mod state, native client source under `SkyrimPlugin/`, PHP context hooks, migrations and release scripts.
- [CHIM-Twitch-Bot](https://github.com/Dwemer-Dynamics/CHIM-Twitch-Bot): a separately maintained service/server integration.
- [CHIM-MCP](https://github.com/Dwemer-Dynamics/CHIM-MCP): external-tool integration; inspect its own instructions and authentication boundary.
- [Per-NPC plugin data](plugin-npc-data.md): `NpcMaster::getPluginData`, `setPluginData` and `deletePluginData` for existing NPC IDs and owned namespaces.

Check each example's current README and compatible version. Historical comments under bundled `ext/` examples can reference old Papyrus names; they are not the current game API. The [CHIM custom-plugin guide](https://github.com/Dwemer-Dynamics/CHIM/blob/unstable/AIAgent/docs/CHIM/custom-plugins.md) explains where to verify game-side commands.

For new plugin-owned tables, use prefixed names in the `plugins` schema and inspect the current installer/migration code. Do not put arbitrary tables into core playthrough capture. Use the NPC plugin-data API for appropriate per-NPC state, and explicitly decide which other plugin data is global or playthrough-specific.

## Package a server extension

The current [package manager](../lib/plugin_package_manager.php) accepts ZIP-format `.dwpkg`/`.zip` uploads with schema version 4. Plugin Manager listings ([below](#list-a-plugin-in-plugin-manager)) use [ui/server_plugin_installer.php](../ui/server_plugin_installer.php) and tar archives; [ext/generic_installer.php](../ext/generic_installer.php) is another legacy installer. Do not mix their layouts. Choose one [install/update route](plugin-runtime.md#install-and-update-routes) per plugin.

```text
manifest.json
checksums.sha256
server/
  manifest.json
  README.md
  AGENTS.md
  context_pre.php
  migrations/
    001_initial.sql
```

The outer manifest identifies the package; payload paths are relative to `server/`, which installs into `ext/<name>/`. A minimal outer manifest is:

```json
{
  "schema_version": 4,
  "name": "ExamplePlugin",
  "version": "1.0.0",
  "server": { "mutable_paths": ["config.json"] }
}
```

List only actual plugin-owned mutable files/directories. The updater preserves those paths when replacing the extension. Every non-directory archive entry except `checksums.sha256` must have a SHA-256 line in that file, including the outer manifest. Paths must be relative, safe and under `server/` except for the two outer metadata files. Do not include game DLLs in this server payload.

Use [CHIM-Custom's packaging scripts](https://github.com/Dwemer-Dynamics/CHIM-Custom/tree/main/scripts) as a working example. Its `build-dwpkg.ps1` creates the server package and `build-release.ps1` creates the combined game archive. CHIM discovers embedded packages under `Data/CHIM/server-plugins/<package>/<version>.dwpkg`; [ui/api/plugin_packages.php](../ui/api/plugin_packages.php) and `Plugin/ServerPluginSync.cpp` in CHIM define the transfer contract.

## List a plugin in Plugin Manager

Plugin Manager discovers plugins from public GitHub repositories with the `chim-plugin` topic. There is no central registration. [lib/plugin_discovery.php](../lib/plugin_discovery.php) searches `topic:chim-plugin archived:false fork:false`, reads each repository's root `manifest.json` from its default branch and caches the result for six hours in `conf/plugin_discovery/`. **Refresh Plugins** forces a refresh, with backoff after GitHub errors. If GitHub is unavailable, the last successful list stays visible. Only the 100 most recently updated tagged repositories are checked.

A topic is a community listing, not a review or endorsement. Installation runs the plugin's PHP, migrations and Composer with server privileges. Users should install only from authors they trust.

To be listed:

1. Make the repository public, not archived and not a fork. Add the `chim-plugin` topic in its GitHub About settings.
2. Put `manifest.json` at the repository root on the default branch. `name` is required and becomes `ext/<name>/`: 1–64 letters, digits, `_`, `.` or `-`, starting with a letter or digit. Keep it stable; it is also the installed package identity.
3. Add `version` and `description`. Optional catalog fields are `display_name`, `mod_download_url` (an `https://` link that users open in the browser; `<version>` is replaced with the manifest version), `default_channel` and `channels`:

```json
{
  "name": "ExamplePlugin",
  "version": "1.0.0",
  "description": "What the plugin adds to CHIM.",
  "schema_version": 2,
  "config_url": "/HerikaServer/ext/ExamplePlugin/index.php",
  "default_channel": "main",
  "channels": {
    "main": { "label": "Live", "branch": "main", "package_source": "release" },
    "dev": { "label": "Dev", "branch": "dev", "package_source": "branch", "allow_force": true }
  }
}
```

Without `channels`, the installer downloads the latest release asset `<name>.tar.gz` (or `<name>.tar`). `package_source: "branch"` downloads the branch archive instead. Explicit `package_urls` and `manifest_url` are allowed only under `https://github.com/<owner>/<repo>/`, `https://raw.githubusercontent.com/<owner>/<repo>/`, `https://codeload.github.com/<owner>/<repo>/` or `https://api.github.com/repos/<owner>/<repo>/` for the same repository. Other URLs are ignored. The installer extracts with `tar --strip-components` set by the channel's `archive_strip_components` (default `1`, maximum `3`), so by default the archive must hold one enclosing folder, such as `ExamplePlugin/manifest.json`; GitHub branch archives already do. A flat archive with `manifest.json` as a top-level entry loses it under the default and needs `"archive_strip_components": 0`. The `manifest.json` at the extracted root must have the same `name`. The installer records the source repository as `git_repo` in the installed manifest.

Plugins are identified by repository, not display name. An install or update is refused if `ext/<name>/` already belongs to a different repository or to an unknown source. Delete the existing plugin first to replace it deliberately. `featured` and `icon` branding is not taken from discovered manifests.

[ui/data/plugin_repository.json](../ui/data/plugin_repository.json) is no longer a source of listings. Its entries are applied only as backwards-compatible overrides, matched by `git_repo`, for plugins that are discovered or already installed. This keeps existing channels, download links and featured branding. A repository listed only there will not appear until it has the topic. Authors can remove an override once their own manifest carries equivalent metadata.

## Validate before distributing

1. Lint changed PHP files; inspect an existing test and its prerequisites in [building.md](building.md).
2. Test hooks on matching client/server revisions with absent optional dependencies, invalid input and two plugins enabled together.
3. In a disposable server, test clean install and upgrade, checksum rejection, mutable-path preservation, and migration failure/rollback. Review `unittests/tests/PluginPackageManagerTest.php` for the current package contract.
4. For game actions, verify the actual actor result and response round trip; installation success alone is insufficient.
5. Inspect the archive for secrets, saves, generated media, logs and development files. Include source links, version requirements and scoped instructions in your plugin's README/AGENTS.

Migrations must be ordered, idempotent and append-only after release. Core memory/playthrough rules still apply. Publish only when requested; developing or testing a plugin does not authorize installing it into someone's running game or server.
