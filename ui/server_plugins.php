<?php

$enginePath = __DIR__ . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR;

require_once($enginePath . "lib" . DIRECTORY_SEPARATOR . "runtime_bootstrap.php");
chimRuntimeBootstrap($enginePath, [
    'load_general_settings' => true,
    'load_player_name' => true,
    'load_narrator' => true,
]);

require_once($enginePath . "lib" . DIRECTORY_SEPARATOR . "logger.php");
require_once($enginePath . "lib" . DIRECTORY_SEPARATOR . "plugin_discovery.php");

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (empty($_SESSION['chim_plugin_csrf'])) {
    $_SESSION['chim_plugin_csrf'] = bin2hex(random_bytes(32));
}
$pluginManagerCsrf = (string)$_SESSION['chim_plugin_csrf'];

// Determine web root (match other pages)
$scriptPath = $_SERVER['SCRIPT_NAME'];
$uiPos = strpos($scriptPath, '/ui/');
if ($uiPos !== false) { $webRoot = substr($scriptPath, 0, $uiPos); } else { $webRoot = ''; }
if ($webRoot == '/') $webRoot = '';
$webRoot = rtrim($webRoot, '/');

$TITLE = "CHIM - Server Plugins";
ob_start();
include(__DIR__.DIRECTORY_SEPARATOR."tmpl".DIRECTORY_SEPARATOR."head.html");

$isEmbedded = (isset($_GET['embed']) && $_GET['embed']);
if (!$isEmbedded) {
    include(__DIR__.DIRECTORY_SEPARATOR."tmpl".DIRECTORY_SEPARATOR."navbar.php");
}
?>

<link rel="stylesheet" href="<?php echo $webRoot; ?>/ui/css/main.css">
<style>
main { padding: <?php echo $isEmbedded ? '10px' : '80px'; ?> 10px 24px; }
footer { display: <?php echo $isEmbedded? 'none' : 'block'; ?>; }
/* MagicCards font import and heading styling to match core pages */
@font-face {
    font-family: 'MagicCards';
    src: url('<?php echo $webRoot; ?>/ui/css/font/MagicCardsNormal.ttf') format('truetype');
    font-weight: normal;
    font-style: normal;
    font-display: swap;
}
h1 { font-family: 'MagicCards', serif; letter-spacing: 1.5px; }
/* Page header is the shared compact inline row (.chim-page-head in chim-theme.css). */
.page-header h1, #page-title, #title-text { font-family:'MagicCards', serif !important; }
.table-container { 
    background: linear-gradient(135deg, rgba(42, 42, 42, 0.95), rgba(34, 34, 34, 0.98));
    border-radius: 10px; 
    padding: 15px; 
    margin-bottom: 20px; 
    overflow-x: auto; 
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15), inset 0 1px rgba(255, 255, 255, 0.03);
    border: 1px solid #3a3a3a;
    transition: border-color 0.3s ease, box-shadow 0.3s ease;
}
.table-container:hover {
    border-color: rgba(242, 124, 17, 0.3);
    box-shadow: 0 4px 16px rgba(0, 0, 0, 0.25), inset 0 1px rgba(255, 255, 255, 0.05);
}
table { 
    width: 100%; 
    border-collapse: collapse; 
    background: linear-gradient(135deg, rgba(58, 58, 58, 0.5), rgba(48, 48, 48, 0.6));
    margin-bottom: 20px; 
    font-size: small;
    border-radius: 8px;
    overflow: hidden;
}
th { 
    background: linear-gradient(180deg, rgba(26, 26, 26, 0.95), rgba(20, 20, 20, 0.98));
    color: rgb(242, 124, 17); 
    font-weight: bold; 
    padding: 12px; 
    text-align: left; 
    border-bottom: 2px solid rgba(242, 124, 17, 0.3);
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
}
td { 
    padding: 10px; 
    text-align: left; 
    border-bottom: 1px solid #3a3a3a; 
    color: #f8f9fa;
}
tr:hover td {
    background: rgba(242, 124, 17, 0.05);
}
/* Use main.css button system; do not override rounded corners/colors here */
/* .btn-* styles intentionally inherited from main.css */
.btn-base:disabled { opacity: 0.6; cursor: not-allowed; }
/* Extra styling parity with index.php */
.title-with-button { display:flex; align-items:center; }
.title-with-button h2 { margin-right:10px; margin-bottom:0; }
/* Featured-plugin branding (SHARMAT) - matches the SHARMAT UI language exactly:
   gold-glow name = .gold-glow-text (#FDF5D0 + neonPulse purple halo),
   buttons = .create-style-btn (dark indigo #252233, cream border, creamPulse breathing),
   row = batchCardBreathing purple over the dark indigo wash. */
@keyframes sharmatNeonPulse {
    from { text-shadow: none; }
    to {
        text-shadow:
            0 0 8px #C9A0DC,
            0 0 25px rgba(180, 130, 200, 0.8),
            0 0 50px rgba(150, 100, 180, 0.6),
            0 0 70px rgba(107, 91, 122, 0.4);
    }
}
@keyframes sharmatCreamPulse {
    from {
        text-shadow: 0 0 3px rgba(253, 245, 208, 0.2);
        box-shadow: 0 0 5px rgba(253, 245, 208, 0.2);
        border-color: rgba(253, 245, 208, 0.7);
    }
    to {
        text-shadow: 0 0 8px rgba(253, 245, 208, 0.6), 0 0 15px rgba(253, 245, 208, 0.4);
        box-shadow: 0 0 12px rgba(253, 245, 208, 0.5), 0 0 20px rgba(253, 245, 208, 0.3);
        border-color: #FDF5D0;
    }
}
/* All SHARMAT breathing runs on the SAME clock: 3s ease-in-out alternate, from = rest, to = peak,
   so the lady, the SHARMAT wording, the row, and the buttons inhale and exhale together. */
@keyframes sharmatRowBreathing {
    from {
        border-color: rgba(139, 92, 246, 0.4);
        box-shadow: inset 0 0 20px rgba(139, 92, 246, 0.05);
    }
    to {
        border-color: rgba(168, 85, 247, 0.7);
        box-shadow: inset 0 0 30px rgba(168, 85, 247, 0.1);
    }
}
@keyframes sharmatIconPulse {
    from { filter: drop-shadow(0 0 5px rgba(168, 85, 247, 0.7)); }
    to { filter: drop-shadow(0 0 12px rgba(168, 85, 247, 1)) drop-shadow(0 0 18px rgba(253, 245, 208, 0.45)); }
}
.featured-plugin-cell { display: inline-flex; align-items: center; gap: 12px; }
.featured-plugin-icon { width: 52px; height: 52px; object-fit: contain; animation: sharmatIconPulse 3s ease-in-out infinite alternate; }
.featured-plugin-name {
    font-family: 'MagicCards', 'Segoe UI', sans-serif;
    font-weight: 600;
    font-size: 1.6em;
    letter-spacing: 1px;
    word-spacing: 6px;
    color: #FDF5D0;
    animation: sharmatNeonPulse 3s ease-in-out infinite alternate;
}
.btn-sharmat {
    font-family: 'MagicCards', 'Segoe UI', sans-serif;
    font-size: 14px;
    letter-spacing: 1px;
    word-spacing: 6px;
    color: #FDF5D0 !important;
    background: #252233 !important;
    border: 2px solid #FDF5D0 !important;
    border-radius: 10px;
    padding: 6px 14px;
    cursor: pointer;
    animation: sharmatCreamPulse 3s ease-in-out infinite alternate;
}
.btn-sharmat:hover { background: #2A2740 !important; }
.btn-sharmat:disabled { opacity: 0.75; }
/* SHARMAT delete button: maroon-red body, GOLD text + gold trim, breathing the SAME
   sharmatCreamPulse gold glow as the strip buttons so it's locked to their cadence. */
.btn-sharmat-danger {
    font-family: 'MagicCards', 'Segoe UI', sans-serif;
    font-size: 14px;
    letter-spacing: 1px;
    word-spacing: 6px;
    font-weight: 600;
    color: #FDF5D0 !important;
    background: linear-gradient(135deg, #3E0E22 0%, #2A0816 100%) !important;
    border: 2px solid #FDF5D0 !important;
    border-radius: 10px;
    padding: 6px 14px;
    cursor: pointer;
    animation: sharmatCreamPulse 3s ease-in-out infinite alternate;
}
.btn-sharmat-danger:hover {
    background: linear-gradient(135deg, #4E1230 0%, #360A1E 100%) !important;
}
/* The SHARMAT row itself: dark indigo wash + breathing purple separators instead of the gray line.
   Row text = the SHARMAT UI's regular note font in the lavender wording color, no glow.
   The channel label ("Live") keeps MagicCards + the gold glow via .featured-live. */
tr.featured-plugin-row td {
    background: linear-gradient(135deg, rgba(28, 26, 36, 0.9), rgba(37, 34, 51, 0.95)) !important;
    border-top: 1px solid rgba(139, 92, 246, 0.5);
    border-bottom: 1px solid rgba(139, 92, 246, 0.5) !important;
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    font-weight: normal;
    font-size: 1em;
    line-height: 1.6;
    color: #C9A8FF;
    animation: sharmatRowBreathing 3s ease-in-out infinite alternate;
}
.featured-live {
    font-family: 'MagicCards', 'Segoe UI', sans-serif;
    font-weight: 600;
    font-size: 1.15em;
    letter-spacing: 1px;
    word-spacing: 6px;
    color: #FDF5D0;
    animation: sharmatNeonPulse 3s ease-in-out infinite alternate;
}
.package-sync-card {
    display: grid;
    grid-template-columns: minmax(260px, 1fr) auto;
    gap: 10px 14px;
    align-items: center;
    margin-bottom: 10px;
    padding: 10px 12px;
    border: 1px solid #3a3a3a;
    border-radius: 8px;
    background: #242424;
}
.package-sync-card h2 { margin: 0 0 3px; color: #f27c11; font-size: 1.2em; }
.package-sync-card p { margin: 0; color: #bbb; font-size: 0.9em; }
.package-sync-status { grid-column: 1 / -1; padding: 8px 10px; border-radius: 5px; background: #181818; color: #ddd; }
.package-sync-list { display: grid; gap: 6px; margin-top: 8px; }
.package-sync-row { display: flex; justify-content: space-between; gap: 16px; padding: 7px 9px; background: #202020; border: 1px solid #363636; border-radius: 4px; }
.package-sync-version { color: #a9e7b7; white-space: nowrap; }
@media (max-width: 900px) {
    .package-sync-card { grid-template-columns: 1fr; }
}
.plugin-repo-id { margin-top: 3px; color: #999; font-size: 0.85em; font-family: 'Segoe UI', Tahoma, sans-serif; }
.plugin-discovery-status { margin: 0 0 12px; padding: 8px 10px; border-radius: 5px; background: #181818; color: #ddd; }
.plugin-discovery-skipped { margin: -6px 0 12px; color: #bbb; font-size: 0.9em; }
.plugin-discovery-skipped summary { cursor: pointer; }
.plugin-discovery-skipped summary:focus-visible { outline: 2px solid #f27c11; outline-offset: 2px; }
</style>

<main>
    <div class="page-header chim-page-head">
        <h1 id="page-title" class="chim-page-head-title"><span id="title-text">Server Plugins</span></h1>
        <p class="page-subtitle chim-page-head-note">Manage and install plugins to extend CHIM functionality</p>
    </div>

    <section class="package-sync-card">
        <div>
            <h2>Automatic Game Plugin Sync</h2>
            <p>CHIM transfers bundled server plugins automatically when a save is loaded. No manual upload is required.</p>
        </div>
        <button id="package-sync-refresh" type="button" class="btn-base btn-primary">Refresh Status</button>
        <div id="package-sync-status" class="package-sync-status" role="status" aria-live="polite">Loading installed packages...</div>
    </section>

    <div class="table-container">
        <?php
        // Helpers
        function rrmdir($dir) {
            if (is_dir($dir)) {
                $objects = scandir($dir);
                foreach ($objects as $object) {
                    if ($object != '.' && $object != '..') {
                        $path = $dir . DIRECTORY_SEPARATOR . $object;
                        if (is_dir($path)) rrmdir($path); else @unlink($path);
                    }
                }
                @rmdir($dir);
            }
        }

        // Links are carried as data attributes and opened by the delegated handler below.
        function pluginManagerButton($label, $url, $class) {
            return ' <button type="button" data-open-url="' . htmlspecialchars($url, ENT_QUOTES) . '" class="' . $class . '">' . htmlspecialchars($label, ENT_QUOTES) . '</button>';
        }

        function pluginManagerAge($timestamp) {
            $seconds = max(0, time() - (int)$timestamp);
            if ($seconds < 90) return 'just now';
            if ($seconds < 5400) return round($seconds / 60) . ' minutes ago';
            if ($seconds < 172800) return round($seconds / 3600) . ' hours ago';
            return round($seconds / 86400) . ' days ago';
        }

        function buildPluginInstallerUrl($packageName, $gitRepo, $channelId = 'main', $force = false) {
            $params = [
                'PACKAGE_NAME' => $packageName,
                'GITHUB_REPO' => $gitRepo,
                'CHANNEL' => $channelId,
            ];
            if ($force) {
                $params['FORCE'] = '1';
            }
            return 'server_plugin_installer.php?' . http_build_query($params);
        }

        $pluginOverrides = chimPluginLoadOverrides();
        $pluginFoldersRoot = __DIR__ . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . "ext" . DIRECTORY_SEPARATOR;
        $installedExtensions = chimPluginInstalledExtensions($pluginFoldersRoot);
        $hiddenFolders = ['xLifeLink_plugin', 'herika_heal', 'time_awareness'];

        // Handle POST actions
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!hash_equals($pluginManagerCsrf, (string)($_POST['csrf_token'] ?? ''))) {
                $_SESSION['chim_plugin_notice'] = 'The request expired. Reload the page and try again.';
            } elseif (isset($_POST['delete_plugin'])) {
                $pluginToDelete = (string)$_POST['delete_plugin'];
                // Only an installed, visible extension folder; never a path.
                if (basename($pluginToDelete) === $pluginToDelete && array_key_exists($pluginToDelete, $installedExtensions)
                    && !in_array($pluginToDelete, $hiddenFolders, true) && chimPluginValidPackageName($pluginToDelete)) {
                    rrmdir($pluginFoldersRoot . $pluginToDelete);
                } else {
                    $_SESSION['chim_plugin_notice'] = 'That plugin cannot be deleted here.';
                }
            } elseif (isset($_POST['refresh_plugins'])) {
                $refreshed = chimPluginDiscoveryGet(true);
                if ($refreshed['notice'] !== '') {
                    $_SESSION['chim_plugin_notice'] = $refreshed['notice'];
                } elseif ($refreshed['error'] !== '') {
                    $_SESSION['chim_plugin_notice'] = 'Plugin list refresh failed: ' . $refreshed['error'];
                } else {
                    $_SESSION['chim_plugin_notice'] = 'Plugin list refreshed.';
                }
            }
            header('Location: ' . $_SERVER['REQUEST_URI']);
            exit;
        }

        $pluginNotice = (string)($_SESSION['chim_plugin_notice'] ?? '');
        unset($_SESSION['chim_plugin_notice']);
        // Release the session lock before any GitHub request.
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        $discovery = chimPluginDiscoveryGet(false);
        $discovered = $discovery['plugins'];
        $csrfField = '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($pluginManagerCsrf, ENT_QUOTES) . '">';

        echo '<form method="post" style="margin:0 0 12px 0;">' . $csrfField;
        echo '<button type="submit" name="refresh_plugins" value="1" class="btn-base btn-primary">Refresh Plugins</button>';
        echo '</form>';
        if ($pluginNotice !== '') {
            echo '<p class="plugin-discovery-status" role="status">' . htmlspecialchars($pluginNotice, ENT_QUOTES) . '</p>';
        }

        echo '<table border="1">';
        echo '<tr>
                <th>Plugin</th>
                <th>Description</th>
                <th>Current Version</th>
                <th>Channel</th>
                <th>Latest Channel Version</th>
                <th>Plugin Menu</th>
                <th>Delete Plugin</th>
            </tr>';

        foreach ($installedExtensions as $folder => $manifest) {
            if (in_array($folder, $hiddenFolders, true) || !is_array($manifest)) {
                continue;
            }
            $name = (string)($manifest['name'] ?? $folder);
            $description = $manifest['description'] ?? 'No description available';
            $configUrl = (string)($manifest['config_url'] ?? '');
            $version = (string)($manifest['version'] ?? '');
            $gitRepo = chimPluginValidRepo($manifest['git_repo'] ?? null) ? $manifest['git_repo'] : '';
            $modDownloadUrl = chimPluginSafeLink(strtr((string)($manifest['mod_download_url'] ?? ''), ['<version>' => $version]));
            // Overrides attach by repository; plugins installed before git_repo existed fall back to name.
            $repositoryEntry = null;
            if ($gitRepo !== '') {
                $repositoryEntry = $pluginOverrides[chimPluginRepoKey($gitRepo)] ?? null;
            } else {
                foreach ($pluginOverrides as $override) {
                    if (($override['name'] ?? '') === $name) { $repositoryEntry = $override; break; }
                }
            }
            $discoveredEntry = $gitRepo !== '' ? ($discovered[chimPluginRepoKey($gitRepo)] ?? null) : null;
            $catalog = chimPluginEffectiveCatalog($repositoryEntry, $discoveredEntry, $manifest);
            $channels = $gitRepo !== '' ? chimPluginNormalizeChannels($catalog, $folder, $gitRepo) : [];
            $currentChannelId = (string)($manifest['channel'] ?? ($catalog['default_channel'] ?? 'main'));
            if (!isset($channels[$currentChannelId]) && !empty($channels)) {
                $currentChannelId = array_key_first($channels);
            }
            $currentChannel = !empty($channels) ? $channels[$currentChannelId] : ['id' => $currentChannelId, 'label' => ($currentChannelId ?: 'legacy'), 'allow_force' => false];

            // The discovery cache already holds the default-branch manifest; other branches are read live.
            $latestVersion = '';
            if ($gitRepo !== '' && !empty($channels)) {
                $branch = (string)($currentChannel['branch'] ?? '');
                if (is_array($discoveredEntry) && $discoveredEntry['version'] !== '' && ($branch === '' || $branch === $discoveredEntry['default_branch'])) {
                    $latestVersion = $discoveredEntry['version'];
                } else {
                    $latestVersion = chimPluginRemoteManifestVersion($currentChannel, $gitRepo);
                }
            }

            // Featured-plugin branding (display_name / icon / featured from manifest or repository entry)
            $displayName = (string)($manifest['display_name'] ?? ($repositoryEntry['display_name'] ?? $name));
            $isFeatured = !empty($manifest['featured']) || !empty($repositoryEntry['featured']);
            $iconRef = (string)($manifest['icon'] ?? ($repositoryEntry['icon'] ?? ''));
            $iconUrl = '';
            if ($iconRef !== '') {
                $iconUrl = preg_match('#^https?://#i', $iconRef) ? chimPluginSafeLink($iconRef) : ($webRoot . '/ext/' . rawurlencode($folder) . '/' . implode('/', array_map('rawurlencode', explode('/', ltrim($iconRef, '/')))));
            }
            $rowBtnPrimary = $isFeatured ? 'btn-base btn-sharmat' : 'btn-base btn-primary';
            $rowBtnSave = $isFeatured ? 'btn-base btn-sharmat' : 'btn-base btn-save';
            $repoLine = $gitRepo !== '' ? '<div class="plugin-repo-id">' . htmlspecialchars($gitRepo, ENT_QUOTES) . '</div>' : '';

            echo $isFeatured ? '<tr class="featured-plugin-row">' : '<tr>';
            if ($isFeatured) {
                echo '<td><span class="featured-plugin-cell">' . ($iconUrl !== '' ? '<img src="' . htmlspecialchars($iconUrl, ENT_QUOTES) . '" class="featured-plugin-icon" alt="">' : '') . '<span class="featured-plugin-name">' . htmlspecialchars($displayName, ENT_QUOTES) . '</span></span>' . $repoLine . '</td>';
            } else {
                echo '<td>' . htmlspecialchars($displayName, ENT_QUOTES) . $repoLine . '</td>';
            }
            echo '<td>' . htmlspecialchars((string)$description, ENT_QUOTES) . '</td>';
            echo '<td>' . htmlspecialchars($version, ENT_QUOTES) . '</td>';
            $channelLabelHtml = htmlspecialchars((string)($currentChannel['label'] ?? $currentChannelId), ENT_QUOTES);
            echo '<td>' . ($isFeatured ? '<span class="featured-live">' . $channelLabelHtml . '</span>' : $channelLabelHtml) . '</td>';
            if (!empty($latestVersion) && !empty($version) && version_compare($latestVersion, $version, '>')) {
                echo '<td style="color: #ff4444; font-weight: bold;">' . htmlspecialchars($latestVersion, ENT_QUOTES) . ' <span title="Update Available">⬆️</span></td>';
            } else {
                echo '<td>' . htmlspecialchars($latestVersion, ENT_QUOTES) . '</td>';
            }
            echo '<td>';
            if (!empty($configUrl)) {
                echo pluginManagerButton('Plugin Page', $configUrl, $rowBtnPrimary);
                if (isset($manifest['schema_version']) && $manifest['schema_version']==2 && $gitRepo !== '') {
                    $forceCurrentChannel = !empty($currentChannel['allow_force']);
                    $updateUrl = buildPluginInstallerUrl($folder, $gitRepo, $currentChannelId, $forceCurrentChannel);
                    echo pluginManagerButton('Update ' . ($currentChannel['label'] ?? 'Plugin'), $updateUrl, $rowBtnSave);
                    foreach ($channels as $channelId => $channel) {
                        if ($channelId === $currentChannelId) {
                            continue;
                        }
                        echo pluginManagerButton('Switch to ' . $channel['label'], buildPluginInstallerUrl($folder, $gitRepo, $channelId, true), $rowBtnPrimary);
                    }
                }
                if (!empty($modDownloadUrl)) {
                    echo pluginManagerButton('Download Skyrim Modfile', $modDownloadUrl, $rowBtnSave);
                }

            } else {
                echo 'No Plugin Page';
            }
            echo '</td>';
            echo '<td>';
            $deletePrompt = json_encode('Are you sure you want to delete the ' . $name . ' plugin?', JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
            echo '<form method="post" style="margin:0;" onsubmit="return confirm(' . htmlspecialchars($deletePrompt, ENT_QUOTES) . ');">' . $csrfField;
            echo '<input type="hidden" name="delete_plugin" value="' . htmlspecialchars($folder, ENT_QUOTES) . '">';
            echo '<button type="submit" class="btn-base ' . ($isFeatured ? 'btn-sharmat-danger' : 'btn-danger') . '">Delete Plugin</button>';
            echo '</form>';
            echo '</td>';
            echo '</tr>';
        }
        echo '</table>';

        echo '<br>';
        echo '<div class="table-container" style="margin-top: 30px;">';
        echo '<h1 style="margin: 0 0 15px 0; text-align: center; color: rgb(242, 124, 17); font-family: \'MagicCards\', serif; font-size: 1.8em;">CHIM Plugins Repository</h1>';
        echo '<p style="text-align: center; color: #bbb; margin: 0 0 6px 0;">Community plugins from public GitHub repositories tagged <code>' . CHIM_PLUGIN_TOPIC . '</code>. A listing is not a review or endorsement: plugins run with full server access, so install only from authors you trust.</p>';
        echo '<p style="text-align: center; color: #bbb; margin: 0 0 20px 0;">Built a plugin of your own? See <a href="https://github.com/Dwemer-Dynamics/HerikaServer/blob/unstable/docs/custom-plugins.md#list-a-plugin-in-plugin-manager" target="_blank" rel="noopener noreferrer">how to get your plugin listed here</a>.</p>';

        $listedCount = count($discovered);
        if ($discovery['state'] === 'error') {
            $statusText = 'Could not load the plugin list from GitHub: ' . $discovery['error'] . '. Installed plugins are unaffected.';
        } elseif ($discovery['state'] === 'pending') {
            $statusText = 'The plugin list has not been loaded yet. Use Refresh Plugins to try again.';
        } elseif ($discovery['state'] === 'stale') {
            $statusText = 'Showing the list from ' . pluginManagerAge($discovery['fetched_at']) . '.';
            if ($discovery['error'] !== '') {
                $statusText .= ' The latest refresh failed: ' . $discovery['error'] . '.';
            }
        } elseif ($listedCount === 0) {
            $statusText = 'No public repositories tagged ' . CHIM_PLUGIN_TOPIC . ' with a valid manifest.json were found.';
        } else {
            $statusText = $listedCount . ' plugins listed, updated ' . pluginManagerAge($discovery['fetched_at']) . '.';
        }
        if ((int)($discovery['total_count'] ?? 0) > CHIM_PLUGIN_MAX_REPOS) {
            $statusText .= ' Only the ' . CHIM_PLUGIN_MAX_REPOS . ' most recently updated of ' . (int)$discovery['total_count'] . ' tagged repositories are checked.';
        }
        if ($discovery['notice'] !== '') {
            $statusText .= ' ' . $discovery['notice'];
        }
        echo '<p class="plugin-discovery-status" role="status">' . htmlspecialchars($statusText, ENT_QUOTES) . '</p>';
        if (!empty($discovery['skipped'])) {
            echo '<details class="plugin-discovery-skipped"><summary>' . count($discovery['skipped']) . ' tagged repositories could not be listed</summary><ul>';
            foreach ($discovery['skipped'] as $skip) {
                echo '<li>' . htmlspecialchars($skip['repo'] . ': ' . $skip['reason'], ENT_QUOTES) . '</li>';
            }
            echo '</ul></details>';
        }

        uasort($discovered, function ($a, $b) use ($pluginOverrides) {
            $featuredA = !empty($pluginOverrides[chimPluginRepoKey($a['repo'])]['featured']);
            $featuredB = !empty($pluginOverrides[chimPluginRepoKey($b['repo'])]['featured']);
            return [$featuredB, strtolower($a['display_name'])] <=> [$featuredA, strtolower($b['display_name'])];
        });

        if ($listedCount > 0) {
            echo '<table border="1">';
            echo '<tr>
                    <th>Plugin</th>
                    <th>Description</th>
                    <th>Plugin Menu</th>
                </tr>';
        }
        foreach ($discovered as $repoKey => $plugin) {
            $gitRepo = $plugin['repo'];
            $name = $plugin['name'];
            $override = $pluginOverrides[$repoKey] ?? null;
            $catalog = chimPluginEffectiveCatalog($override, $plugin, null);
            $description = $catalog['description'] ?? 'No description available';
            $githubUrl = 'https://github.com/' . $gitRepo;
            $modDownloadUrl = (string)($catalog['mod_download_url'] ?? '');
            if (strpos($modDownloadUrl, '<version>') !== false) {
                $modDownloadUrl = $plugin['version'] !== '' ? chimPluginSafeLink(strtr($modDownloadUrl, ['<version>' => rawurlencode($plugin['version'])])) : '';
            }
            $channels = chimPluginNormalizeChannels($catalog, $name, $gitRepo);
            $owner = chimPluginInstalledOwner($installedExtensions, $name, $pluginOverrides);

            // Featured-plugin branding (SHARMAT) is a curated override, never self-declared.
            $displayName = (string)($catalog['display_name'] ?? $name);
            $isFeatured = !empty($override['featured']);
            $repoIconUrl = (string)($override['icon'] ?? '');
            $rowBtnPrimary = $isFeatured ? 'btn-base btn-sharmat' : 'btn-base btn-primary';
            $rowBtnSave = $isFeatured ? 'btn-base btn-sharmat' : 'btn-base btn-save';
            $repoLine = '<div class="plugin-repo-id">' . htmlspecialchars($gitRepo . ($plugin['version'] !== '' ? ' · v' . $plugin['version'] : ''), ENT_QUOTES) . '</div>';

            echo $isFeatured ? '<tr class="featured-plugin-row">' : '<tr>';
            if ($isFeatured) {
                echo '<td><span class="featured-plugin-cell">' . ($repoIconUrl !== '' ? '<img src="' . htmlspecialchars($repoIconUrl, ENT_QUOTES) . '" class="featured-plugin-icon" alt="">' : '') . '<span class="featured-plugin-name">' . htmlspecialchars($displayName, ENT_QUOTES) . '</span></span>' . $repoLine . '</td>';
            } else {
                echo '<td>' . htmlspecialchars($displayName, ENT_QUOTES) . $repoLine . '</td>';
            }
            echo '<td>' . htmlspecialchars($description, ENT_QUOTES) . '</td>';
            echo '<td>';
            if ($owner !== null && chimPluginSameRepo($owner, $gitRepo)) {
                echo '<button type="button" class="btn-base' . ($isFeatured ? ' btn-sharmat' : '') . '" disabled style="opacity: 0.6;">Already Installed</button>';
            } elseif ($owner !== null) {
                $conflict = 'ext/' . $name . ' is already used by ' . ($owner !== '' ? $owner : 'a plugin from another source') . '.';
                echo '<button type="button" class="btn-base" disabled style="opacity: 0.6;" title="' . htmlspecialchars($conflict, ENT_QUOTES) . '">Name In Use</button>';
            } else {
                $defaultChannelId = (string)($catalog['default_channel'] ?? 'main');
                foreach ($channels as $channelId => $channel) {
                    $installUrl = buildPluginInstallerUrl($name, $gitRepo, $channelId, false);
                    $installBase = $isFeatured ? 'Install ' . $displayName : 'Install Plugin';
                    $installLabel = ($channelId === $defaultChannelId || count($channels) === 1) ? $installBase : 'Install ' . $channel['label'];
                    echo pluginManagerButton($installLabel, $installUrl, $rowBtnSave);
                }
            }
            echo pluginManagerButton('GitHub', $githubUrl, $rowBtnPrimary);
            if (!empty($modDownloadUrl)) {
                echo pluginManagerButton('Mod Download', $modDownloadUrl, $rowBtnPrimary);
            }
            echo '</td>';
            echo '</tr>';
        }
        if ($listedCount > 0) {
            echo '</table>';
        }

        echo '</div>'; // Close the second table-container
        ?>
    </div>
</main>

<script>
(() => {
    // Remote links are data, never interpolated into inline script.
    document.addEventListener('click', (event) => {
        const button = event.target.closest('[data-open-url]');
        if (!button) return;
        const url = new URL(button.dataset.openUrl, window.location.href);
        if (url.protocol === 'https:' || url.protocol === 'http:') window.open(url.href, '_blank', 'noopener');
    });
})();
</script>

<script>
(() => {
    const refresh = document.getElementById('package-sync-refresh');
    const status = document.getElementById('package-sync-status');
    const endpoint = <?php echo json_encode($webRoot . '/ui/api/plugin_packages.php'); ?>;

    const loadPackages = async () => {
        refresh.disabled = true;
        status.textContent = 'Loading installed packages...';
        try {
            const response = await fetch(`${endpoint}?action=packages`, { cache: 'no-store' });
            const payload = await response.json();
            if (!response.ok || !payload.ok) throw new Error(payload.error || 'Could not read automatic package status.');
            if (!payload.packages.length) {
                status.textContent = 'No game-bundled server plugins have synchronized yet.';
            } else {
                status.innerHTML = '<strong>Installed from game:</strong><div class="package-sync-list"></div>';
                const list = status.querySelector('.package-sync-list');
                for (const plugin of payload.packages) {
                    const row = document.createElement('div');
                    row.className = 'package-sync-row';
                    const name = document.createElement('span');
                    name.textContent = plugin.name;
                    const version = document.createElement('span');
                    version.className = 'package-sync-version';
                    version.textContent = plugin.version;
                    row.append(name, version);
                    list.append(row);
                }
            }
        } catch (error) {
            status.textContent = error.message;
        } finally {
            refresh.disabled = false;
        }
    };
    refresh.addEventListener('click', loadPackages);
    loadPackages();
})();
</script>

<?php
include(__DIR__.DIRECTORY_SEPARATOR."tmpl".DIRECTORY_SEPARATOR."footer.html");
$buffer = ob_get_contents();
ob_end_clean();
$title = $TITLE;
$buffer = preg_replace('/(<title>)(.*?)(<\/title>)/i', '$1' . $title . '$3', $buffer);
echo $buffer;
?>

