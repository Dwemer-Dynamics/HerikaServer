<?php
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

$enginePath = __DIR__ . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR;
require_once($enginePath . "lib" . DIRECTORY_SEPARATOR . "plugin_discovery.php");

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (empty($_SESSION["chim_plugin_csrf"])) {
    $_SESSION["chim_plugin_csrf"] = bin2hex(random_bytes(32));
}
$csrfToken = (string)$_SESSION["chim_plugin_csrf"];
session_write_close();

function chimPluginInstallerEscape($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, "UTF-8");
}

function chimPluginInstallerReadJson($path) {
    if (!file_exists($path)) {
        return false;
    }
    $data = json_decode(file_get_contents($path), true);
    return json_last_error() === JSON_ERROR_NONE ? $data : false;
}

function chimPluginInstallerStringEndsWith($value, $suffix) {
    if ($suffix === "") {
        return true;
    }
    return substr($value, -strlen($suffix)) === $suffix;
}

function chimPluginInstallerGetRemoteManifest($channel, $githubRepo) {
    $response = chimPluginHttpGet($channel["manifest_url"], $githubRepo, ["timeout" => 30]);
    return $response["ok"] ? chimPluginDecodeManifest($response["body"]) : false;
}

function chimPluginInstallerRemoveDirectory($dir) {
    if (!is_dir($dir)) {
        return;
    }
    $items = scandir($dir);
    foreach ($items as $item) {
        if ($item === "." || $item === "..") {
            continue;
        }
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path) && !is_link($path)) {
            chimPluginInstallerRemoveDirectory($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}

function chimPluginInstallerConnectToDatabase() {
    $connStr = "host=localhost port=5432 dbname=dwemer user=dwemer password=dwemer";
    $conn = pg_connect($connStr);
    if (!$conn) {
        throw new Exception("Failed to connect to database: " . pg_last_error());
    }
    return $conn;
}

function chimPluginInstallerRunMigrations($targetDir, $packageName) {
    $migrationsDir = $targetDir . DIRECTORY_SEPARATOR . "migrations";
    if (!is_dir($migrationsDir)) {
        echo "<p class='log-info'>No migrations directory found, skipping database migrations.</p>\n";
        return true;
    }

    try {
        $conn = chimPluginInstallerConnectToDatabase();
        pg_query($conn, "CREATE SCHEMA IF NOT EXISTS plugins");
        pg_query($conn, "CREATE TABLE IF NOT EXISTS plugins.plugin_migrations (plugin_name VARCHAR(255), migration_name VARCHAR(255), executed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (plugin_name, migration_name))");

        $migrations = glob($migrationsDir . DIRECTORY_SEPARATOR . "*.sql");
        if (empty($migrations)) {
            echo "<p class='log-info'>No migration files found.</p>\n";
            pg_close($conn);
            return true;
        }

        sort($migrations);
        foreach ($migrations as $migrationFile) {
            $migrationName = basename($migrationFile);
            $result = pg_query_params($conn, "SELECT 1 FROM plugins.plugin_migrations WHERE plugin_name = $1 AND migration_name = $2", [$packageName, $migrationName]);
            if ($result && pg_num_rows($result) > 0) {
                echo "<p class='log-skipped'>Skipping already executed migration: " . chimPluginInstallerEscape($migrationName) . "</p>\n";
                continue;
            }

            echo "<p class='log-running'>Running migration: " . chimPluginInstallerEscape($migrationName) . "</p>\n";
            $sql = file_get_contents($migrationFile);
            $migrationResult = pg_query($conn, $sql);
            if ($migrationResult === false) {
                throw new Exception("Migration failed: " . $migrationName . " - " . pg_last_error($conn));
            }
            pg_query_params($conn, "INSERT INTO plugins.plugin_migrations (plugin_name, migration_name) VALUES ($1, $2)", [$packageName, $migrationName]);
            echo "<p class='log-completed'>Migration completed: " . chimPluginInstallerEscape($migrationName) . "</p>\n";
        }

        pg_close($conn);
        return true;
    } catch (Exception $e) {
        echo "<p class='log-error'>Error running migrations: " . chimPluginInstallerEscape($e->getMessage()) . "</p>\n";
        return false;
    }
}

function chimPluginInstallerRunComposer($targetDir) {
    $composerJson = $targetDir . DIRECTORY_SEPARATOR . "composer.json";
    if (!file_exists($composerJson)) {
        echo "<p class='log-info'>No composer.json found, skipping dependency installation.</p>\n";
        return true;
    }

    echo "<p class='log-action'>Installing dependencies with Composer...</p>\n";
    $installCmd = "cd " . escapeshellarg($targetDir) . " && COMPOSER_HOME=" . escapeshellarg(sys_get_temp_dir()) . " /usr/bin/composer --no-ansi -v install";
    ob_start();
    system($installCmd, $installStatus);
    $installOutput = ob_get_clean();
    echo "<div class='system-command-output'>" . nl2br(chimPluginInstallerEscape($installOutput)) . "</div>";

    if ($installStatus !== 0) {
        throw new Exception("Composer install failed with status " . $installStatus);
    }
    return true;
}

function chimPluginInstallerDownloadPackage($channel, $targetParent, $packageName, $githubRepo) {
    foreach ($channel["package_urls"] as $packageUrl) {
        echo "<p class='log-action'>Trying package URL: " . chimPluginInstallerEscape($packageUrl) . "</p>\n";
        $packagePath = parse_url($packageUrl, PHP_URL_PATH) ?? "";
        $extension = chimPluginInstallerStringEndsWith($packagePath, ".tar") ? ".tar" : ".tar.gz";
        $archiveFile = $targetParent . DIRECTORY_SEPARATOR . "." . $packageName . "-download-" . uniqid("", true) . $extension;
        $sink = @fopen($archiveFile, "w+b");
        if (!$sink) {
            throw new Exception("Failed to write downloaded archive.");
        }
        $response = chimPluginHttpGet($packageUrl, $githubRepo, ["sink" => $sink, "max_bytes" => CHIM_PLUGIN_PACKAGE_BYTES, "timeout" => 300, "accept" => "application/octet-stream, */*"]);
        fclose($sink);
        if (!$response["ok"]) {
            @unlink($archiveFile);
            echo "<p class='log-skipped'>Download failed (" . chimPluginInstallerEscape($response["error"]) . "), trying next URL if available.</p>\n";
            continue;
        }
        return [$archiveFile, $packageUrl];
    }

    throw new Exception("Failed to download package from all configured channel URLs.");
}

// $source is set for the standard release package: its metadata comes from GitHub, not the archive.
function chimPluginInstallerInstallPackage($channel, $targetDir, $packageName, $githubRepo, $source = null, $webRoot = "") {
    $targetParent = dirname($targetDir);
    if (!is_dir($targetParent) || !is_writable($targetParent)) {
        throw new Exception("Target parent is not writable: " . $targetParent);
    }

    [$archiveFile, $downloadedUrl] = chimPluginInstallerDownloadPackage($channel, $targetParent, $packageName, $githubRepo);
    $isGzip = !chimPluginInstallerStringEndsWith($archiveFile, ".tar");
    if ($source !== null) {
        $listingError = chimPluginCheckArchiveListing($archiveFile, $isGzip);
        if ($listingError !== "") {
            @unlink($archiveFile);
            throw new Exception($listingError);
        }
    }
    $stagingDir = $targetParent . DIRECTORY_SEPARATOR . "." . $packageName . "-install-" . uniqid("", true);
    if (!mkdir($stagingDir, 0700, true)) {
        @unlink($archiveFile);
        throw new Exception("Failed to create staging directory.");
    }

    $tarFlags = $isGzip ? "xvfz" : "xvf";
    $stripComponents = max(0, (int)($channel["archive_strip_components"] ?? 1));
    $extractCmd = "tar " . $tarFlags . " " . escapeshellarg($archiveFile) . " -C " . escapeshellarg($stagingDir) . " --strip-components=" . $stripComponents;
    if ($source !== null) {
        $extractCmd .= " --no-same-owner";
    }

    echo "<p class='log-action'>Extracting package...</p>\n";
    ob_start();
    system($extractCmd, $extractStatus);
    $extractOutput = ob_get_clean();
    echo "<div class='system-command-output'>" . nl2br(chimPluginInstallerEscape($extractOutput)) . "</div>";
    @unlink($archiveFile);

    if ($extractStatus !== 0) {
        chimPluginInstallerRemoveDirectory($stagingDir);
        throw new Exception("Failed to extract archive from " . $downloadedUrl);
    }

    $manifestPath = $stagingDir . DIRECTORY_SEPARATOR . "manifest.json";
    if ($source !== null) {
        $layoutError = chimPluginCheckStagedTree($stagingDir);
        if ($layoutError !== "") {
            chimPluginInstallerRemoveDirectory($stagingDir);
            throw new Exception($layoutError);
        }
        // The installed manifest.json is generated metadata for Plugin Manager, not an author requirement.
        $manifest = chimPluginGeneratedManifest($stagingDir, $packageName, $source, $webRoot);
        echo "<p class='log-info'>Generated plugin metadata for " . chimPluginInstallerEscape($githubRepo . " " . $source["tag"]) . (isset($manifest["config_url"]) ? " with its plugin page." : " (no plugin page found).") . "</p>\n";
    } else {
        $manifest = chimPluginInstallerReadJson($manifestPath);
        if (!is_array($manifest)) {
            chimPluginInstallerRemoveDirectory($stagingDir);
            throw new Exception("Package did not contain a valid manifest.json at its root.");
        }
        // The package must identify itself as the folder it is about to occupy.
        if (($manifest["name"] ?? "") !== $packageName) {
            chimPluginInstallerRemoveDirectory($stagingDir);
            throw new Exception("Package manifest name '" . (string)($manifest["name"] ?? "") . "' does not match the expected package '" . $packageName . "'.");
        }

        $manifest["channel"] = $channel["id"];
        $manifest["channel_label"] = $channel["label"];
        // Record the source repository: it identifies this installation for later updates.
        $manifest["git_repo"] = $githubRepo;
    }
    if (file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n") === false) {
        chimPluginInstallerRemoveDirectory($stagingDir);
        throw new Exception("Failed to write the plugin metadata.");
    }
    @chmod($stagingDir, 0755);

    // Swap via a backup so a failed move restores the previous installation.
    $backupDir = "";
    if (is_dir($targetDir)) {
        $backupDir = $targetParent . DIRECTORY_SEPARATOR . "." . $packageName . "-previous-" . uniqid("", true);
        if (!rename($targetDir, $backupDir)) {
            chimPluginInstallerRemoveDirectory($stagingDir);
            throw new Exception("Failed to move the existing plugin aside; it was left unchanged.");
        }
    }
    if (!rename($stagingDir, $targetDir)) {
        chimPluginInstallerRemoveDirectory($stagingDir);
        if ($backupDir !== "") {
            rename($backupDir, $targetDir);
        }
        throw new Exception("Failed to move staged plugin into target directory.");
    }
    if ($backupDir !== "") {
        chimPluginInstallerRemoveDirectory($backupDir);
    }

    echo "<p class='log-info'>Checking for database migrations...</p>\n";
    if (!chimPluginInstallerRunMigrations($targetDir, $packageName)) {
        throw new Exception("Failed to run database migrations.");
    }

    chimPluginInstallerRunComposer($targetDir);
    echo "<p class='log-success'>Package successfully installed/updated.</p>\n";
    return true;
}

$pluginId = (string)($_GET["PLUGIN_ID"] ?? "");
$packageName = (string)($_GET["PACKAGE_NAME"] ?? "");
$githubRepo = (string)($_GET["GITHUB_REPO"] ?? "");
$requestedChannel = (string)($_GET["CHANNEL"] ?? "");
$forceInstall = isset($_GET["FORCE"]) && $_GET["FORCE"] !== "0";
$confirmed = $_SERVER["REQUEST_METHOD"] === "POST";

$overrides = chimPluginLoadOverrides();
// Legacy links identified the plugin by its catalog id.
if ($githubRepo === "" && $pluginId !== "") {
    foreach ($overrides as $override) {
        if ($override["_plugin_id"] === $pluginId) {
            $githubRepo = $override["git_repo"];
        }
    }
}

$errors = [];
if ($githubRepo === "" || !chimPluginValidRepo($githubRepo)) {
    $errors[] = "Invalid or missing GITHUB_REPO.";
}
$repoKey = chimPluginRepoKey($githubRepo);
$discovery = chimPluginDiscoveryLoad();
$discoveredEntry = $discovery["plugins"][$repoKey] ?? null;
$override = $overrides[$repoKey] ?? null;

$localManifest = false;
$channels = [];
$catalog = [];
$release = null;
if (empty($errors)) {
    // The folder is resolved here, never taken from the link: the repository's existing folder,
    // else its repository name (standard package) or legacy package name.
    $installedExtensions = chimPluginInstalledExtensions($enginePath . "ext");
    $entry = $discoveredEntry ?: ["repo" => $githubRepo, "repo_id" => 0, "name" => (string)($override["name"] ?? $packageName), "legacy" => null, "release" => null];
    $resolved = chimPluginResolvePackage($entry, $installedExtensions, $overrides);
    if (!$resolved["installed"] && !$discoveredEntry && !$override) {
        $errors[] = "This repository is not in the discovered CHIM plugin list. Refresh the Plugin Manager list and try again.";
    } elseif ($resolved["reason"] !== "") {
        $errors[] = $resolved["reason"] . " Delete it first if you want to replace it with " . $githubRepo . ".";
    } elseif ($packageName !== "" && $packageName !== $resolved["name"]) {
        $errors[] = "This install link is out of date (expected ext/" . $resolved["name"] . "). Refresh the Plugin Manager list and try again.";
    }
    $packageName = $resolved["name"];
    $localManifest = $resolved["installed"] && is_array($installedExtensions[$packageName]) ? $installedExtensions[$packageName] : false;
}
if (empty($errors)) {
    $catalog = chimPluginEffectiveCatalog($override, $discoveredEntry, $localManifest ?: null);
    $cachedRelease = $discoveredEntry["release"] ?? null;
    $release = $discoveredEntry ? chimPluginFetchRelease($githubRepo, ($cachedRelease["status"] ?? "") === "ok" ? $cachedRelease : null) : null;
    $legacy = !empty($discoveredEntry["legacy"]) || $override !== null
        || (is_array($localManifest) && ($localManifest["source"] ?? "") !== "github-release");
    $channels = chimPluginInstallChannels($catalog, $packageName, $githubRepo, $release, $legacy);
    if (empty($channels) && !$discoveredEntry) {
        $errors[] = "This repository is not currently in the discovered CHIM plugin list, so it cannot be updated here. The installed plugin is unchanged.";
    } elseif (empty($channels)) {
        $errors[] = chimPluginUnavailableReason(["release" => $release]);
    }
}
$targetDir = $enginePath . "ext" . DIRECTORY_SEPARATOR . $packageName;
$currentChannel = is_array($localManifest) ? (string)($localManifest["channel"] ?? "") : "";
$defaultChannel = chimPluginDefaultChannel($catalog, $channels);
$requestedChannel = $requestedChannel !== "" ? $requestedChannel : $defaultChannel;

if (empty($errors) && !isset($channels[$requestedChannel])) {
    $errors[] = "Unknown plugin channel: " . $requestedChannel;
}
if ($confirmed && empty($errors) && !hash_equals($csrfToken, (string)($_POST["csrf_token"] ?? ""))) {
    $errors[] = "The install request expired. Reopen the installer from Plugin Manager.";
}

$channel = empty($errors) ? $channels[$requestedChannel] : null;
$isStandard = $channel && $channel["kind"] === "standard";
$installed = is_array($localManifest);
$channelChanged = $installed && $currentChannel !== "" && $currentChannel !== $requestedChannel;
$updateAvailable = !$installed || $channelChanged || $forceInstall;
$standardSource = null;
if ($isStandard) {
    // Release identity, not version ordering: any other tag (or a legacy install) is an update.
    $remoteVersion = $channel["tag"];
    $currentVersion = (string)($localManifest["release_tag"] ?? ($localManifest["version"] ?? ""));
    $updateAvailable = $updateAvailable || ($localManifest["source"] ?? "") !== "github-release" || ($localManifest["release_tag"] ?? "") !== $remoteVersion;
    $standardSource = [
        "repo" => $discoveredEntry["repo"],
        "repo_id" => (int)($discoveredEntry["repo_id"] ?? 0),
        "display_name" => $discoveredEntry["display_name"],
        "description" => (string)($catalog["description"] ?? ""),
        "tag" => $channel["tag"],
        "asset" => $channel["asset"],
        "url" => $channel["package_urls"][0],
        "channel" => $channel["id"],
        "channel_label" => $channel["label"],
    ];
} else {
    $remoteManifest = $channel ? chimPluginInstallerGetRemoteManifest($channel, $githubRepo) : false;
    $remoteVersion = is_array($remoteManifest) ? chimPluginText($remoteManifest["version"] ?? "", 40) : "";
    if ($channel && $remoteVersion !== "") {
        $channel["package_urls"] = array_map(function ($url) use ($remoteVersion) {
            return strtr($url, ["<version>" => rawurlencode($remoteVersion)]);
        }, $channel["package_urls"]);
    }
    $currentVersion = is_array($localManifest) ? (string)($localManifest["version"] ?? "") : "";
    if (!$updateAvailable && $remoteVersion !== "" && $currentVersion !== "") {
        $updateAvailable = version_compare($remoteVersion, $currentVersion, ">");
    }
}
$scriptPath = (string)($_SERVER["SCRIPT_NAME"] ?? "");
$webRoot = rtrim(strpos($scriptPath, "/ui/") !== false ? substr($scriptPath, 0, strpos($scriptPath, "/ui/")) : "", "/");

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CHIM Plugin Installer: <?php echo chimPluginInstallerEscape($packageName); ?></title>
    <link rel="icon" type="image/x-icon" href="images/favicon.ico">
    <link rel="stylesheet" href="css/main.css">
    <link rel="stylesheet" href="css/chim-theme.css?v=<?php echo filemtime(__DIR__ . DIRECTORY_SEPARATOR . 'css' . DIRECTORY_SEPARATOR . 'chim-theme.css'); ?>">
    <style>
        body { padding: 20px; background-color: #2c2c2c; color: #f8f9fa; font-family: 'Futura CondensedLight', Arial, sans-serif; }
        .installer-container { max-width: 900px; margin: 40px auto; background-color: #1a1a1a; padding: 30px; border-radius: 8px; border: 1px solid #3a3a3a; }
        h1 { color: #fff; text-align: center; }
        h3 { color: rgb(242, 124, 17); margin-top: 30px; border-bottom: 1px solid #3a3a3a; padding-bottom: 8px; }
        .version-info-block { background-color: #2d2d2d; padding: 20px; border-radius: 6px; margin-bottom: 30px; border: 1px solid #4a4a4a; }
        .installer-log { background-color: #111; color: #ccc; padding: 20px; border-radius: 6px; font-family: 'Spline Sans Mono', monospace; font-size: 14px; white-space: pre-wrap; word-wrap: break-word; max-height: 450px; overflow-y: auto; border: 1px solid #333; margin-top: 15px; }
        .installer-log p { margin: 6px 0; padding: 3px 0; line-height: 1.5; }
        .log-info { color: #5bc0de; }
        .log-action, .log-running { color: #f0ad4e; }
        .log-completed, .log-success { color: #28a745; }
        .log-skipped { color: #888; }
        .log-error, .log-failed { color: #d9534f; font-weight: bold; }
        .system-command-output { border-left: 3px solid #444; padding-left: 10px; margin: 8px 0 12px 15px; font-size: 0.85em; color: #aaa; }
        .status-message { padding: 15px 20px; margin-top: 25px; border-radius: 6px; font-weight: bold; text-align: center; }
        .status-success { background-color: #28a745; color: white; border: 1px solid #1e7e34; }
        .status-error { background-color: #d9534f; color: white; border: 1px solid #c9302c; }
        .install-confirm { display: flex; flex-wrap: wrap; align-items: center; gap: 12px; margin-top: 20px; }
        .install-confirm p { flex: 1 1 360px; margin: 0; color: #ddd; }
    </style>
</head>
<body>
    <div class="installer-container">
        <h1>CHIM Plugin Installer</h1>
        <a href="core/config_hub.php?tab=serverplugins" class="button btn-primary">&laquo; Back to Plugin Manager</a>

        <div class="version-info-block">
            <h3>Version Information</h3>
            <?php if (!empty($errors)): ?>
                <?php foreach ($errors as $error): ?>
                    <p class="log-error"><?php echo chimPluginInstallerEscape($error); ?></p>
                <?php endforeach; ?>
            <?php else: ?>
                <p><strong>Package:</strong> <?php echo chimPluginInstallerEscape($packageName); ?></p>
                <p><strong>GitHub Repo:</strong> <a href="<?php echo chimPluginInstallerEscape("https://github.com/" . $githubRepo); ?>" target="_blank" rel="noopener noreferrer"><?php echo chimPluginInstallerEscape($githubRepo); ?></a></p>
                <p><strong>Selected Channel:</strong> <?php echo chimPluginInstallerEscape($channel["label"]); ?> <span style="color:#aaa;">(<?php echo chimPluginInstallerEscape($channel["id"]); ?>)</span></p>
                <?php if ($installed): ?>
                    <p><strong>Current Version:</strong> <?php echo chimPluginInstallerEscape($currentVersion); ?></p>
                    <p><strong>Current Channel:</strong> <?php echo chimPluginInstallerEscape($currentChannel ?: "legacy"); ?></p>
                <?php endif; ?>
                <?php if ($isStandard): ?>
                    <p><strong>Latest Release:</strong> <?php echo chimPluginInstallerEscape($remoteVersion . " (" . $channel["asset"] . ")"); ?></p>
                    <?php if (!empty($release["stale"])): ?>
                        <p class="log-skipped">GitHub could not be reached just now; using the release recorded at the last plugin list refresh.</p>
                    <?php endif; ?>
                <?php elseif ($remoteVersion !== ""): ?>
                    <p><strong>Remote Version:</strong> <?php echo chimPluginInstallerEscape($remoteVersion); ?></p>
                <?php else: ?>
                    <p class="log-error">Could not retrieve remote version information for this channel.</p>
                <?php endif; ?>
                <p><strong>Install Needed:</strong> <?php echo $updateAvailable ? "Yes" : "No"; ?></p>
            <?php endif; ?>
        </div>

        <?php
        if (!empty($errors)) {
            echo '<div class="status-message status-error">Could not proceed due to installer configuration errors.</div>';
        } elseif ($updateAvailable && !$confirmed) {
            // Installing runs third-party PHP, migrations and Composer, so it needs an explicit, same-site confirmation.
            echo '<form method="post" class="install-confirm">';
            echo '<input type="hidden" name="csrf_token" value="' . chimPluginInstallerEscape($csrfToken) . '">';
            echo '<p>This package comes from the community repository <strong>' . chimPluginInstallerEscape($githubRepo) . '</strong>. Plugin Manager listings are not reviewed or endorsed by Dwemer Dynamics. The plugin will run with full server access and may change the database.</p>';
            echo '<button type="submit" class="btn-base btn-save" autofocus>' . ($installed ? 'Update Plugin' : 'Install Plugin') . '</button>';
            echo '</form>';
        } elseif ($updateAvailable) {
            echo '<h3>Installation Log</h3>';
            echo '<div class="installer-log">';
            try {
                chimPluginInstallerInstallPackage($channel, $targetDir, $packageName, $githubRepo, $standardSource, $webRoot);
                echo '</div>';
                echo '<div class="status-message status-success">Installation/update process completed.</div>';
            } catch (Exception $e) {
                echo '<p class="log-error">Error: ' . chimPluginInstallerEscape($e->getMessage()) . '</p>';
                echo '</div>';
                echo '<div class="status-message status-error">Installation/update process failed.</div>';
            }
        } else {
            echo '<div class="status-message status-success">Plugin is already installed and up to date for this channel.</div>';
        }
        ?>
    </div>
</body>
</html>
