<?php
// Plugin Manager discovery: public GitHub repositories tagged with the chim-plugin topic.
// A listing is a community submission, not an endorsement. Nothing fetched here is executed;
// installation remains an explicit, confirmed action in ui/server_plugin_installer.php.
// The standard package is the chim-plugin.tar.gz asset of the latest stable release; an author
// manifest.json is optional and only drives the legacy channels of already published plugins.

const CHIM_PLUGIN_TOPIC = 'chim-plugin';
const CHIM_PLUGIN_CACHE_SCHEMA = 2;           // 2: GitHub release metadata per repository
const CHIM_PLUGIN_ASSETS = ['chim-plugin.tar.gz', 'chim-plugin.tar'];
const CHIM_PLUGIN_RELEASE_BYTES = 1048576;
const CHIM_PLUGIN_CACHE_TTL = 21600;          // six hours
const CHIM_PLUGIN_MIN_REFRESH = 60;           // manual refresh spacing after a success
const CHIM_PLUGIN_MAX_BACKOFF = 1800;
const CHIM_PLUGIN_PAGE_SIZE = 50;
const CHIM_PLUGIN_MAX_REPOS = 100;
const CHIM_PLUGIN_MANIFEST_BYTES = 65536;
const CHIM_PLUGIN_SEARCH_BYTES = 2097152;
const CHIM_PLUGIN_PACKAGE_BYTES = 268435456;
// Bundled extensions and installer files that a downloaded package must never replace.
const CHIM_PLUGIN_RESERVED_NAMES = ['herika_heal', 'time_awareness', 'xlifelink_plugin', 'relationship_system', 'generic_installer.php'];

function chimPluginValidRepo($repo): bool
{
    if (!is_string($repo) || preg_match('#^([A-Za-z0-9_.-]{1,39})/([A-Za-z0-9_.-]{1,100})$#', $repo, $m) !== 1) {
        return false;
    }
    return !in_array($m[1], ['.', '..'], true) && !in_array($m[2], ['.', '..'], true);
}

function chimPluginRepoKey(string $repo): string
{
    return strtolower($repo);
}

function chimPluginSameRepo($a, $b): bool
{
    return is_string($a) && is_string($b) && $a !== '' && strcasecmp($a, $b) === 0;
}

// Package names become ext/<name>; keep them to one safe path segment.
function chimPluginValidPackageName($name): bool
{
    return is_string($name)
        && preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,63}$/', $name) === 1
        && !in_array(strtolower($name), CHIM_PLUGIN_RESERVED_NAMES, true);
}

function chimPluginText($value, int $max): string
{
    if (!is_scalar($value)) {
        return '';
    }
    $text = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string)$value) ?? '');
    return function_exists('mb_substr') ? mb_substr($text, 0, $max, 'UTF-8') : substr($text, 0, $max);
}

// Links opened in the user's browser (GitHub page, mod download): http(s) only.
function chimPluginSafeLink($url): string
{
    $url = is_string($url) ? trim($url) : '';
    if ($url === '' || strlen($url) > 500 || preg_match('#^https?://[^\s<>"\'`]+$#i', $url) !== 1) {
        return '';
    }
    return $url;
}

// Server-side fetches are limited to the plugin's own GitHub repository.
function chimPluginAllowedFetchUrl($url, string $repo): bool
{
    if (!is_string($url) || !chimPluginValidRepo($repo) || preg_match('/[\s\\\\]/', $url) || strpos($url, '..') !== false) {
        return false;
    }
    $parts = parse_url($url);
    if (!is_array($parts) || strtolower($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass'])
        || (isset($parts['port']) && (int)$parts['port'] !== 443)) {
        return false;
    }
    $host = strtolower($parts['host'] ?? '');
    $path = strtolower($parts['path'] ?? '');
    // Encoded dots, separators, escapes or NULs could be decoded into traversal after the prefix check.
    if (preg_match('/%(2e|2f|5c|25|00)/i', $path) || strpos(rawurldecode($path), '..') !== false) {
        return false;
    }
    $repoPath = '/' . strtolower($repo) . '/';
    if ($host === 'api.github.com') {
        return strpos($path, '/repos' . $repoPath) === 0;
    }
    if (in_array($host, ['github.com', 'raw.githubusercontent.com', 'codeload.github.com'], true)) {
        return strpos($path, $repoPath) === 0;
    }
    return false;
}

// GitHub redirects release assets and renamed repositories to its own download hosts.
function chimPluginAllowedRedirectUrl($url): bool
{
    $parts = is_string($url) ? parse_url($url) : false;
    if (!is_array($parts) || strtolower($parts['scheme'] ?? '') !== 'https' || isset($parts['user'])
        || (isset($parts['port']) && (int)$parts['port'] !== 443)) {
        return false;
    }
    return in_array(strtolower($parts['host'] ?? ''), [
        'github.com', 'api.github.com', 'raw.githubusercontent.com', 'codeload.github.com',
        'objects.githubusercontent.com', 'release-assets.githubusercontent.com',
    ], true);
}

function chimPluginCurlHandle(string $url, array $opts, array &$state)
{
    $ch = curl_init();
    $max = (int)($opts['max_bytes'] ?? CHIM_PLUGIN_MANIFEST_BYTES);
    $sink = $opts['sink'] ?? null;
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => (int)($opts['timeout'] ?? 20),
        CURLOPT_USERAGENT => 'CHIM-Server Plugin Manager',
        CURLOPT_HTTPHEADER => [
            'Accept: ' . ($opts['accept'] ?? 'application/vnd.github+json, application/json, */*'),
            'X-GitHub-Api-Version: 2022-11-28',
        ],
        CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$state) {
            if (preg_match('/^(retry-after|x-ratelimit-remaining|x-ratelimit-reset|location):\s*(.+)$/i', trim($line), $m)) {
                $state['headers'][strtolower($m[1])] = trim($m[2]);
            }
            return strlen($line);
        },
        CURLOPT_WRITEFUNCTION => function ($ch, $chunk) use (&$state, $max, $sink) {
            $len = strlen($chunk);
            $state['bytes'] += $len;
            if ($state['bytes'] > $max) {
                $state['too_large'] = true;
                return 0;
            }
            if ($sink) {
                return fwrite($sink, $chunk) === $len ? $len : 0;
            }
            $state['body'] .= $chunk;
            return $len;
        },
    ]);
    return $ch;
}

function chimPluginHttpResult($ch, array $state, $curlOk): array
{
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $result = ['ok' => false, 'status' => $status, 'body' => $state['body'], 'error' => '', 'transient' => false,
        'retry_after' => 0, 'rate_limited' => false, 'location' => (string)curl_getinfo($ch, CURLINFO_REDIRECT_URL)];
    $headers = $state['headers'];
    if ($state['too_large']) {
        $result['error'] = 'Response exceeded the size limit.';
    } elseif ($curlOk === false || $status === 0) {
        $result['error'] = 'Network error: ' . chimPluginText(curl_error($ch), 160);
        $result['transient'] = true;
    } elseif ($status >= 200 && $status < 300) {
        $result['ok'] = true;
    } else {
        $result['error'] = 'HTTP ' . $status;
        $rateLimited = $status === 429 || ($status === 403 && ($headers['x-ratelimit-remaining'] ?? '') === '0');
        $result['transient'] = $rateLimited || $status >= 500 || ($status >= 300 && $status < 400);
        if ($rateLimited) {
            $result['rate_limited'] = true;
            $result['error'] = 'GitHub rate limit reached';
            $wait = (int)($headers['retry-after'] ?? 0);
            if (isset($headers['x-ratelimit-reset'])) {
                $wait = max($wait, (int)$headers['x-ratelimit-reset'] - time());
            }
            $result['retry_after'] = max(0, $wait);
        }
    }
    return $result;
}

// Bounded HTTPS GET for the plugin's own repository; redirects are re-checked per hop.
function chimPluginHttpGet(string $url, string $repo, array $opts = []): array
{
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'status' => 0, 'body' => '', 'error' => 'The PHP curl extension is required.', 'transient' => false, 'retry_after' => 0];
    }
    if (!chimPluginAllowedFetchUrl($url, $repo)) {
        return ['ok' => false, 'status' => 0, 'body' => '', 'error' => 'URL is outside the plugin repository: ' . chimPluginText($url, 200), 'transient' => false, 'retry_after' => 0];
    }
    for ($hop = 0; $hop <= 5; $hop++) {
        if (!empty($opts['sink'])) {
            ftruncate($opts['sink'], 0);
            rewind($opts['sink']);
        }
        $state = ['body' => '', 'bytes' => 0, 'too_large' => false, 'headers' => []];
        $ch = chimPluginCurlHandle($url, $opts, $state);
        $curlOk = curl_exec($ch);
        $result = chimPluginHttpResult($ch, $state, $curlOk);
        curl_close($ch);
        if ($result['status'] < 300 || $result['status'] >= 400) {
            return $result;
        }
        $next = $result['location'];
        if ($next === '' || !chimPluginAllowedRedirectUrl($next)) {
            $result['error'] = 'Refused redirect to ' . chimPluginText($next, 200);
            $result['transient'] = false;
            return $result;
        }
        $url = $next;
    }
    return ['ok' => false, 'status' => 0, 'body' => '', 'error' => 'Too many redirects.', 'transient' => false, 'retry_after' => 0];
}

// Concurrent repository reads. raw.githubusercontent.com does not consume the REST API quota;
// once api.github.com reports its rate limit, the remaining requests are not sent.
function chimPluginHttpGetMany(array $requests, int $concurrency = 8): array
{
    $results = [];
    $limited = null;
    foreach (array_chunk($requests, $concurrency, true) as $batch) {
        if ($limited) {
            foreach (array_keys($batch) as $key) {
                $results[$key] = $limited;
            }
            continue;
        }
        $multi = curl_multi_init();
        $handles = [];
        $states = [];
        foreach ($batch as $key => $request) {
            if (!chimPluginAllowedFetchUrl($request['url'], $request['repo'])) {
                $results[$key] = ['ok' => false, 'status' => 0, 'body' => '', 'error' => 'URL is outside the plugin repository.', 'transient' => false, 'retry_after' => 0];
                continue;
            }
            $states[$key] = ['body' => '', 'bytes' => 0, 'too_large' => false, 'headers' => []];
            $handles[$key] = chimPluginCurlHandle($request['url'], ['timeout' => 12, 'accept' => $request['accept'] ?? 'application/json, text/plain, */*',
                'max_bytes' => $request['max_bytes'] ?? CHIM_PLUGIN_MANIFEST_BYTES], $states[$key]);
            curl_multi_add_handle($multi, $handles[$key]);
        }
        do {
            $status = curl_multi_exec($multi, $running);
            if ($running && curl_multi_select($multi, 1.0) === -1) {
                usleep(50000);
            }
        } while ($running && $status === CURLM_OK);
        foreach ($handles as $key => $ch) {
            $results[$key] = chimPluginHttpResult($ch, $states[$key], curl_errno($ch) === 0);
            if ($results[$key]['rate_limited']) {
                $limited = $results[$key];
            }
            curl_multi_remove_handle($multi, $ch);
            curl_close($ch);
        }
        curl_multi_close($multi);
    }
    return $results;
}

function chimPluginDecodeManifest(string $body)
{
    $data = json_decode($body, true);
    if (is_array($data) && isset($data['content']) && is_string($data['content'])) {
        $data = json_decode((string)base64_decode($data['content']), true);
    }
    return is_array($data) ? $data : false;
}

// Keeps only documented catalog fields from an author manifest or a legacy override entry.
function chimPluginCatalogFields(array $source): array
{
    $catalog = [];
    foreach (['display_name' => 80, 'description' => 600] as $field => $max) {
        $text = chimPluginText($source[$field] ?? '', $max);
        if ($text !== '') {
            $catalog[$field] = $text;
        }
    }
    // Keep the <version> template; it is validated again after expansion where the link is shown.
    $download = is_string($source['mod_download_url'] ?? null) ? trim($source['mod_download_url']) : '';
    if ($download !== '' && chimPluginSafeLink(strtr($download, ['<version>' => '0'])) !== '') {
        $catalog['mod_download_url'] = $download;
    }
    $default = (string)($source['default_channel'] ?? '');
    if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,31}$/', $default) === 1) {
        $catalog['default_channel'] = $default;
    }
    if (isset($source['channels']) && is_array($source['channels'])) {
        $channels = [];
        foreach (array_slice($source['channels'], 0, 6, true) as $id => $config) {
            $id = (string)$id;
            if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,31}$/', $id) !== 1) {
                continue;
            }
            if (is_string($config)) {
                $config = ['branch' => $config];
            }
            if (!is_array($config)) {
                continue;
            }
            $channel = [];
            $label = chimPluginText($config['label'] ?? '', 32);
            if ($label !== '') {
                $channel['label'] = $label;
            }
            $branch = (string)($config['branch'] ?? '');
            if ($branch !== '' && preg_match('#^[A-Za-z0-9._/-]{1,100}$#', $branch) === 1 && strpos($branch, '..') === false) {
                $channel['branch'] = $branch;
            }
            if (in_array($config['package_source'] ?? '', ['release', 'branch'], true)) {
                $channel['package_source'] = $config['package_source'];
            }
            foreach (['manifest_url', 'package_url'] as $field) {
                if (isset($config[$field]) && is_string($config[$field])) {
                    $channel[$field] = substr($config[$field], 0, 500);
                }
            }
            if (isset($config['package_urls']) && is_array($config['package_urls'])) {
                $channel['package_urls'] = array_values(array_map(function ($url) {
                    return substr((string)$url, 0, 500);
                }, array_filter(array_slice($config['package_urls'], 0, 4), 'is_string')));
            }
            if (isset($config['archive_strip_components'])) {
                $channel['archive_strip_components'] = max(0, min(3, (int)$config['archive_strip_components']));
            }
            if (isset($config['allow_force'])) {
                $channel['allow_force'] = (bool)$config['allow_force'];
            }
            $channels[$id] = $channel;
        }
        if ($channels) {
            $catalog['channels'] = $channels;
        }
    }
    return $catalog;
}

// Resolves channel definitions; every fetched URL must belong to $githubRepo.
function chimPluginNormalizeChannels(array $entry, string $packageName, string $githubRepo): array
{
    $channels = [];
    $tokens = function ($value, $channelId, $branch) use ($packageName, $githubRepo) {
        return strtr((string)$value, ['<package>' => $packageName, '<repo>' => $githubRepo, '<channel>' => $channelId, '<branch>' => $branch]);
    };
    $branchPath = function ($branch) {
        return implode('/', array_map('rawurlencode', explode('/', $branch)));
    };
    $rawChannels = $entry['channels'] ?? [];
    foreach (is_array($rawChannels) ? $rawChannels : [] as $channelId => $config) {
        $channelId = (string)$channelId;
        if (is_string($config)) {
            $config = ['branch' => $config];
        }
        if (!is_array($config)) {
            continue;
        }
        $branch = (string)($config['branch'] ?? $channelId);
        $manifestUrl = (string)($config['manifest_url'] ?? '');
        if ($manifestUrl === '' && $branch !== '') {
            $manifestUrl = 'https://raw.githubusercontent.com/' . $githubRepo . '/' . $branchPath($branch) . '/manifest.json';
        }
        $packageUrls = [];
        if (isset($config['package_urls']) && is_array($config['package_urls'])) {
            $packageUrls = $config['package_urls'];
        } elseif (isset($config['package_url'])) {
            $packageUrls = [$config['package_url']];
        }
        if (empty($packageUrls)) {
            $packageSource = $config['package_source'] ?? '';
            if ($packageSource === 'branch' || (!in_array($channelId, ['main', 'live', 'stable'], true) && $branch !== '')) {
                $packageUrls = ['https://github.com/' . $githubRepo . '/archive/refs/heads/' . $branchPath($branch) . '.tar.gz'];
            } else {
                $packageUrls = [
                    'https://github.com/' . $githubRepo . '/releases/latest/download/' . $packageName . '.tar.gz',
                    'https://github.com/' . $githubRepo . '/releases/latest/download/' . $packageName . '.tar',
                ];
            }
        }
        $packageUrls = array_values(array_filter(array_map(function ($url) use ($tokens, $channelId, $branch) {
            return $tokens($url, $channelId, $branch);
        }, $packageUrls), function ($url) use ($githubRepo) {
            // <version> is resolved later; validate with a neutral placeholder.
            return chimPluginAllowedFetchUrl(strtr($url, ['<version>' => '0']), $githubRepo);
        }));
        $manifestUrl = $tokens($manifestUrl, $channelId, $branch);
        if (!chimPluginAllowedFetchUrl($manifestUrl, $githubRepo) || empty($packageUrls)) {
            continue;
        }
        $isBranch = count(array_filter($packageUrls, function ($url) {
            return strpos($url, '/archive/') !== false;
        })) === count($packageUrls);
        $channels[$channelId] = [
            'id' => $channelId,
            'label' => chimPluginText($config['label'] ?? '', 32) ?: ucfirst($channelId),
            'kind' => $isBranch ? 'branch' : 'release',
            'branch' => $branch,
            'manifest_url' => $manifestUrl,
            'package_urls' => $packageUrls,
            'archive_strip_components' => max(0, min(3, (int)($config['archive_strip_components'] ?? 1))),
            'allow_force' => (bool)($config['allow_force'] ?? ($channelId !== 'main')),
        ];
    }
    if (empty($channels)) {
        $channels['main'] = [
            'id' => 'main',
            'label' => 'Live',
            'kind' => 'release',
            'branch' => '',
            'manifest_url' => 'https://api.github.com/repos/' . $githubRepo . '/contents/manifest.json',
            'package_urls' => [
                'https://github.com/' . $githubRepo . '/releases/latest/download/' . $packageName . '.tar.gz',
                'https://github.com/' . $githubRepo . '/releases/latest/download/' . $packageName . '.tar',
            ],
            'archive_strip_components' => 1,
            'allow_force' => false,
        ];
    }
    return $channels;
}

// The standard release package replaces legacy release channels under the 'main' id, so existing
// installs keep their channel; legacy branch channels (such as Dev) stay available. Legacy channels
// are offered only when a legacy manifest or override describes them.
function chimPluginInstallChannels(array $catalog, string $packageName, string $githubRepo, ?array $release, bool $legacy): array
{
    $channels = $legacy ? chimPluginNormalizeChannels($catalog, $packageName, $githubRepo) : [];
    if (($release['status'] ?? '') !== 'ok') {
        return $channels;
    }
    $standard = [
        'id' => 'main',
        'label' => $channels['main']['label'] ?? 'Live',
        'kind' => 'standard',
        'branch' => '',
        'manifest_url' => '',
        'package_urls' => [$release['url']],
        'archive_strip_components' => 0,
        'allow_force' => false,
        'tag' => $release['tag'],
        'asset' => $release['asset'],
    ];
    $branches = array_filter($channels, function ($channel) {
        return $channel['kind'] === 'branch';
    });
    return ['main' => $standard] + array_diff_key($branches, ['main' => 1]);
}

function chimPluginDefaultChannel(array $catalog, array $channels): string
{
    if (($channels['main']['kind'] ?? '') === 'standard') {
        return 'main';
    }
    $default = (string)($catalog['default_channel'] ?? 'main');
    return isset($channels[$default]) || empty($channels) ? $default : (string)array_key_first($channels);
}

// Precedence: legacy override, then the author's discovered manifest, then the installed manifest.
// Channels and their default are taken together from the highest layer that defines channels.
// A discovered repository is always named and described by GitHub when it has a description.
function chimPluginEffectiveCatalog(?array $override, ?array $discovered, ?array $localManifest): array
{
    $layers = [];
    if ($localManifest) {
        $layers[] = chimPluginCatalogFields($localManifest);
    }
    if ($discovered) {
        $layers[] = $discovered['legacy']['catalog'] ?? [];
    }
    if ($override) {
        $layers[] = $override;
    }
    $catalog = [];
    foreach ($layers as $layer) {
        foreach (['display_name', 'description', 'mod_download_url'] as $field) {
            if (isset($layer[$field]) && $layer[$field] !== '') {
                $catalog[$field] = $layer[$field];
            }
        }
        if (!empty($layer['channels'])) {
            $catalog['channels'] = $layer['channels'];
            $catalog['default_channel'] = $layer['default_channel'] ?? 'main';
        } elseif (isset($layer['default_channel']) && empty($catalog['channels'])) {
            $catalog['default_channel'] = $layer['default_channel'];
        }
    }
    if ($discovered) {
        $catalog['display_name'] = $discovered['display_name'];
        if ($discovered['repo_description'] !== '' || empty($catalog['description'])) {
            $catalog['description'] = $discovered['repo_description'];
        }
    }
    return $catalog;
}

function chimPluginRemoteManifestVersion(array $channel, string $githubRepo): string
{
    $response = chimPluginHttpGet($channel['manifest_url'], $githubRepo, ['timeout' => 15]);
    $manifest = $response['ok'] ? chimPluginDecodeManifest($response['body']) : false;
    return is_array($manifest) ? chimPluginText($manifest['version'] ?? '', 40) : '';
}

// ui/data/plugin_repository.json: legacy overrides keyed by repository, never a listing source.
function chimPluginLoadOverrides(): array
{
    $path = dirname(__DIR__) . '/ui/data/plugin_repository.json';
    $data = is_file($path) ? json_decode((string)file_get_contents($path), true) : null;
    $overrides = [];
    foreach ((is_array($data) && is_array($data['plugins'] ?? null)) ? $data['plugins'] : [] as $id => $entry) {
        if (!is_array($entry) || !chimPluginValidRepo($entry['git_repo'] ?? null)) {
            continue;
        }
        $override = chimPluginCatalogFields($entry);
        $override['_plugin_id'] = (string)$id;
        $override['git_repo'] = $entry['git_repo'];
        if (chimPluginValidPackageName($entry['name'] ?? null)) {
            $override['name'] = $entry['name'];
        }
        $override['featured'] = !empty($entry['featured']);
        $icon = chimPluginSafeLink($entry['icon'] ?? '');
        if ($icon !== '') {
            $override['icon'] = $icon;
        }
        $overrides[chimPluginRepoKey($entry['git_repo'])] = $override;
    }
    return $overrides;
}

function chimPluginCacheDir(): string
{
    return dirname(__DIR__) . '/conf/plugin_discovery';
}

// Owner or root repairs mode and group (taken from $groupOf) in place, so a root-created file stays shared.
function chimPluginShare(string $path, int $mode, string $groupOf): void
{
    $uid = function_exists('posix_geteuid') ? posix_geteuid() : null;
    clearstatcache();
    if ($uid !== null && $uid !== 0 && @fileowner($path) !== $uid) {
        return;
    }
    $group = @filegroup($groupOf);
    if ($group !== false && @filegroup($path) !== $group) {
        @chgrp($path, $group);
        clearstatcache();
    }
    if ((@fileperms($path) & 07777) !== $mode) {
        @chmod($path, $mode);
    }
}

// Group-writable, in the group of conf/, so the web and CLI server accounts can share the cache.
function chimPluginEnsureCacheDir(): bool
{
    $dir = chimPluginCacheDir();
    if (!is_dir($dir) && !@mkdir($dir, 02775, true) && !is_dir($dir)) {
        return false;
    }
    chimPluginShare($dir, 02775, dirname($dir));
    return is_writable($dir);
}

// The lock is repaired in place, never replaced: peers must flock the same inode.
function chimPluginOpenRefreshLock()
{
    $dir = chimPluginCacheDir();
    $path = $dir . '/refresh.lock';
    if (is_file($path)) {
        chimPluginShare($path, 0664, $dir);
    }
    $lock = @fopen($path, 'c');
    if ($lock) {
        chimPluginShare($path, 0664, $dir);
    }
    return $lock;
}

function chimPluginDiscoveryLoad(): array
{
    $path = chimPluginCacheDir() . '/cache.json';
    $data = is_file($path) ? json_decode((string)@file_get_contents($path), true) : null;
    $empty = ['topic' => CHIM_PLUGIN_TOPIC, 'schema' => CHIM_PLUGIN_CACHE_SCHEMA, 'fetched_at' => 0, 'attempted_at' => 0, 'failures' => 0,
        'next_attempt_at' => 0, 'error' => '', 'plugins' => [], 'partial' => null, 'total_count' => 0];
    if (!is_array($data) || ($data['topic'] ?? '') !== CHIM_PLUGIN_TOPIC || !is_array($data['plugins'] ?? null)) {
        return $empty;
    }
    if ((int)($data['schema'] ?? 1) === CHIM_PLUGIN_CACHE_SCHEMA) {
        return $data + $empty;
    }
    // Older caches hold manifest-only entries. Keep them visible as legacy entries, but refresh now.
    $plugins = [];
    foreach ($data['plugins'] as $key => $old) {
        if (!is_array($old) || !chimPluginValidRepo($old['repo'] ?? null) || chimPluginRepoKey($old['repo']) !== $key) {
            continue;
        }
        $repo = [
            'repo' => $old['repo'],
            'repo_id' => (int)($old['repo_id'] ?? 0),
            'default_branch' => (string)($old['default_branch'] ?? 'HEAD'),
            'repo_description' => chimPluginText($old['repo_description'] ?? '', 600),
            'stars' => (int)($old['stars'] ?? 0),
            'pushed_at' => chimPluginText($old['pushed_at'] ?? '', 40),
        ];
        $legacy = chimPluginLegacyFromManifest(['name' => $old['name'] ?? null, 'version' => $old['version'] ?? '',
            'display_name' => $old['display_name'] ?? '', 'description' => $old['description'] ?? ''] + (is_array($old['catalog'] ?? null) ? $old['catalog'] : []));
        $plugins[$key] = chimPluginBuildEntry($repo, $legacy, chimPluginReleaseUnknown('not checked yet'));
    }
    return ['plugins' => $plugins, 'total_count' => (int)($data['total_count'] ?? count($plugins))] + $empty;
}

// Atomic replace: readers see the previous complete cache or the new one, never a partial file.
function chimPluginDiscoverySave(array $cache): bool
{
    if (!chimPluginEnsureCacheDir()) {
        return false;
    }
    $dir = chimPluginCacheDir();
    $json = json_encode($cache, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    $tmp = $dir . '/.cache-' . bin2hex(random_bytes(6)) . '.tmp';
    if ($json === false || @file_put_contents($tmp, $json . "\n", LOCK_EX) === false) {
        @unlink($tmp);
        return false;
    }
    chimPluginShare($tmp, 0664, $dir);
    if (!@rename($tmp, $dir . '/cache.json')) {
        @unlink($tmp);
        return false;
    }
    return true;
}

function chimPluginSearchRepositories(): array
{
    $repos = [];
    $total = 0;
    $query = 'topic:' . CHIM_PLUGIN_TOPIC . ' archived:false fork:false';
    for ($page = 1; $page <= (int)ceil(CHIM_PLUGIN_MAX_REPOS / CHIM_PLUGIN_PAGE_SIZE); $page++) {
        $url = 'https://api.github.com/search/repositories?' . http_build_query([
            'q' => $query, 'sort' => 'updated', 'order' => 'desc', 'per_page' => CHIM_PLUGIN_PAGE_SIZE, 'page' => $page,
        ], '', '&', PHP_QUERY_RFC3986);
        $response = chimPluginSearchGet($url);
        if (!$response['ok']) {
            return ['ok' => false, 'error' => 'GitHub search failed: ' . $response['error'], 'retry_after' => $response['retry_after']];
        }
        $data = json_decode($response['body'], true);
        if (!is_array($data) || !is_array($data['items'] ?? null)) {
            return ['ok' => false, 'error' => 'GitHub search returned an unexpected response.', 'retry_after' => 0];
        }
        // A timed-out search may omit repositories; keep the last complete list instead.
        if (!empty($data['incomplete_results'])) {
            return ['ok' => false, 'error' => 'GitHub search returned incomplete results.', 'retry_after' => 0];
        }
        $total = (int)($data['total_count'] ?? 0);
        foreach ($data['items'] as $item) {
            $repo = $item['full_name'] ?? null;
            if (!is_array($item) || !chimPluginValidRepo($repo) || !empty($item['private']) || !empty($item['fork'])
                || !empty($item['archived']) || !empty($item['disabled'])) {
                continue;
            }
            $branch = (string)($item['default_branch'] ?? '');
            $repos[chimPluginRepoKey($repo)] = [
                'repo' => $repo,
                'repo_id' => (int)($item['id'] ?? 0),
                'default_branch' => preg_match('#^[A-Za-z0-9._/-]{1,100}$#', $branch) === 1 && strpos($branch, '..') === false ? $branch : 'HEAD',
                'repo_description' => chimPluginText($item['description'] ?? '', 600),
                'stars' => (int)($item['stargazers_count'] ?? 0),
                'pushed_at' => chimPluginText($item['pushed_at'] ?? '', 40),
            ];
        }
        if (count($data['items']) < CHIM_PLUGIN_PAGE_SIZE || $page * CHIM_PLUGIN_PAGE_SIZE >= $total) {
            break;
        }
    }
    return ['ok' => true, 'repos' => array_slice($repos, 0, CHIM_PLUGIN_MAX_REPOS, true), 'total_count' => $total];
}

// The search endpoint is not repository-scoped, so it uses its own fixed URL check.
function chimPluginSearchGet(string $url): array
{
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'error' => 'the PHP curl extension is required', 'retry_after' => 0];
    }
    if (strpos($url, 'https://api.github.com/search/repositories?') !== 0) {
        return ['ok' => false, 'error' => 'invalid search URL', 'retry_after' => 0];
    }
    $state = ['body' => '', 'bytes' => 0, 'too_large' => false, 'headers' => []];
    $ch = chimPluginCurlHandle($url, ['max_bytes' => CHIM_PLUGIN_SEARCH_BYTES, 'timeout' => 20], $state);
    $curlOk = curl_exec($ch);
    $result = chimPluginHttpResult($ch, $state, $curlOk);
    curl_close($ch);
    return $result;
}

// Optional legacy author manifest: only its package name, version and catalog fields are kept.
function chimPluginLegacyFromManifest($manifest): ?array
{
    if (!is_array($manifest) || !chimPluginValidPackageName($manifest['name'] ?? null)) {
        return null;
    }
    return ['name' => $manifest['name'], 'version' => chimPluginText($manifest['version'] ?? '', 40), 'catalog' => chimPluginCatalogFields($manifest)];
}

function chimPluginReleaseUnknown(string $error): array
{
    return ['status' => 'unknown', 'reason' => 'Release information could not be checked (' . $error . ').', 'stale' => true];
}

// The latest stable release qualifies only with an uploaded chim-plugin.tar.gz (or .tar) asset whose
// download URL is the tag-specific URL of this repository. That URL is stored, never a moving "latest".
function chimPluginParseRelease($data, string $githubRepo): array
{
    if (!is_array($data)) {
        return ['status' => 'error', 'reason' => 'GitHub returned unexpected release information.'];
    }
    if (!empty($data['draft']) || !empty($data['prerelease'])) {
        return ['status' => 'none', 'reason' => 'No stable release is published.'];
    }
    $tag = $data['tag_name'] ?? '';
    if (!is_string($tag) || preg_match('/^[A-Za-z0-9][A-Za-z0-9._+-]{0,99}$/', $tag) !== 1) {
        return ['status' => 'no_asset', 'reason' => 'The latest release tag cannot be used as a plugin version.'];
    }
    $assets = [];
    foreach (is_array($data['assets'] ?? null) ? $data['assets'] : [] as $asset) {
        if (is_array($asset) && is_string($asset['name'] ?? null) && ($asset['state'] ?? 'uploaded') === 'uploaded') {
            $assets[$asset['name']] = $asset;
        }
    }
    foreach (CHIM_PLUGIN_ASSETS as $assetName) {
        if (!isset($assets[$assetName])) {
            continue;
        }
        $url = 'https://github.com/' . $githubRepo . '/releases/download/' . rawurlencode($tag) . '/' . $assetName;
        $reported = (string)($assets[$assetName]['browser_download_url'] ?? '');
        if (strcasecmp($reported, $url) !== 0 || !chimPluginAllowedFetchUrl($url, $githubRepo)) {
            return ['status' => 'no_asset', 'tag' => $tag, 'reason' => 'Release ' . $tag . ' has a ' . $assetName . ' asset outside this repository release.'];
        }
        if ((int)($assets[$assetName]['size'] ?? 0) > CHIM_PLUGIN_PACKAGE_BYTES) {
            return ['status' => 'no_asset', 'tag' => $tag, 'reason' => 'Release ' . $tag . ' has a ' . $assetName . ' asset larger than the installer limit.'];
        }
        return ['status' => 'ok', 'tag' => $tag, 'asset' => $assetName, 'url' => $url,
            'published_at' => chimPluginText($data['published_at'] ?? '', 40), 'reason' => ''];
    }
    return ['status' => 'no_asset', 'tag' => $tag, 'reason' => 'Release ' . $tag . ' has no chim-plugin.tar.gz asset.'];
}

// One release response; transient failures return null so the caller keeps its last known result.
function chimPluginReleaseFromResponse(array $response, string $githubRepo): ?array
{
    if ($response['ok'] ?? false) {
        return chimPluginParseRelease(json_decode((string)$response['body'], true), $githubRepo);
    }
    if ((int)($response['status'] ?? 0) === 404) {
        return ['status' => 'none', 'reason' => 'No release is published.'];
    }
    if (!empty($response['transient'])) {
        return null;
    }
    return ['status' => 'error', 'reason' => 'Release information could not be read (' . ($response['error'] ?? 'unknown error') . ').'];
}

function chimPluginReleaseRequest(string $githubRepo): array
{
    return ['url' => 'https://api.github.com/repos/' . $githubRepo . '/releases/latest', 'repo' => $githubRepo,
        'max_bytes' => CHIM_PLUGIN_RELEASE_BYTES, 'accept' => 'application/vnd.github+json'];
}

// Live check used by the installer; falls back to $cached on a transient failure.
function chimPluginFetchRelease(string $githubRepo, ?array $cached): array
{
    $request = chimPluginReleaseRequest($githubRepo);
    $response = chimPluginHttpGet($request['url'], $githubRepo, ['timeout' => 20, 'max_bytes' => $request['max_bytes'], 'accept' => $request['accept']]);
    $release = chimPluginReleaseFromResponse($response, $githubRepo);
    if ($release !== null) {
        return $release;
    }
    return $cached ? ['stale' => true] + $cached : chimPluginReleaseUnknown($response['error'] ?? 'network error');
}

// Listing identity comes from GitHub; the legacy manifest and release decide only how it installs.
function chimPluginBuildEntry(array $repo, ?array $legacy, array $release): array
{
    $name = substr($repo['repo'], strpos($repo['repo'], '/') + 1);
    $version = ($release['status'] ?? '') === 'ok' ? $release['tag'] : ($legacy['version'] ?? '');
    return $repo + [
        'name' => $name,
        'display_name' => $name,
        'version' => $version,
        'legacy' => $legacy,
        'release' => $release,
    ];
}

function chimPluginDiscoveryRefresh(array $previous): array
{
    $cache = $previous;
    $now = time();
    $cache['attempted_at'] = $now;
    $search = chimPluginSearchRepositories();
    if (!$search['ok']) {
        $cache['failures'] = (int)($previous['failures'] ?? 0) + 1;
        $backoff = min(CHIM_PLUGIN_MAX_BACKOFF, 60 * (2 ** min(5, $cache['failures'] - 1)));
        $cache['next_attempt_at'] = $now + max($backoff, min(3600, (int)$search['retry_after']));
        $cache['error'] = $search['error'];
        return $cache;
    }
    $requests = [];
    foreach ($search['repos'] as $key => $repo) {
        $branch = implode('/', array_map('rawurlencode', explode('/', $repo['default_branch'])));
        $requests['m:' . $key] = ['url' => 'https://raw.githubusercontent.com/' . $repo['repo'] . '/' . $branch . '/manifest.json', 'repo' => $repo['repo']];
    }
    // Release reads use the REST quota; the longest-unchecked repositories go first.
    $order = array_keys($search['repos']);
    usort($order, function ($a, $b) use ($previous) {
        return [(int)($previous['plugins'][$a]['release']['checked_at'] ?? 0), $a] <=> [(int)($previous['plugins'][$b]['release']['checked_at'] ?? 0), $b];
    });
    foreach ($order as $key) {
        $requests['r:' . $key] = chimPluginReleaseRequest($search['repos'][$key]['repo']);
    }
    $responses = function_exists('curl_multi_init') ? chimPluginHttpGetMany($requests) : [];
    $plugins = [];
    $unchecked = 0;
    $uncheckedError = '';
    $retryAfter = 0;
    foreach ($search['repos'] as $key => $repo) {
        $old = $previous['plugins'][$key] ?? null;
        $manifestResponse = $responses['m:' . $key] ?? ['ok' => false, 'transient' => true, 'error' => 'not fetched'];
        if ($manifestResponse['ok']) {
            $legacy = chimPluginLegacyFromManifest(chimPluginDecodeManifest($manifestResponse['body']));
        } elseif (!empty($manifestResponse['transient'])) {
            $legacy = $old['legacy'] ?? null;
        } else {
            $legacy = null;
        }
        $releaseResponse = $responses['r:' . $key] ?? ['ok' => false, 'transient' => true, 'error' => 'not fetched'];
        $release = chimPluginReleaseFromResponse($releaseResponse, $repo['repo']);
        if ($release === null) {
            // Never drop or downgrade a repository because its release could not be read this time.
            $unchecked++;
            $uncheckedError = $releaseResponse['error'] ?? 'network error';
            $retryAfter = max($retryAfter, (int)($releaseResponse['retry_after'] ?? 0));
            $release = isset($old['release']) && ($old['release']['status'] ?? '') !== 'unknown'
                ? ['stale' => true] + $old['release'] : chimPluginReleaseUnknown($uncheckedError);
        } else {
            $release['checked_at'] = $now;
        }
        $plugins[$key] = chimPluginBuildEntry($repo, $legacy, $release);
    }
    $cache['schema'] = CHIM_PLUGIN_CACHE_SCHEMA;
    $cache['plugins'] = $plugins;
    $cache['total_count'] = (int)$search['total_count'];
    $cache['fetched_at'] = $now;
    $cache['failures'] = 0;
    $cache['error'] = '';
    $cache['partial'] = $unchecked > 0 ? ['count' => $unchecked, 'error' => $uncheckedError] : null;
    // A partial refresh retries on its own once GitHub allows it, within the normal backoff bounds.
    $cache['next_attempt_at'] = $now + ($unchecked > 0 ? max(300, min(3600, $retryAfter)) : CHIM_PLUGIN_MIN_REFRESH);
    unset($cache['skipped']);
    return $cache;
}

function chimPluginDiscoveryExpired(array $cache, int $now): bool
{
    return $now - (int)$cache['fetched_at'] >= CHIM_PLUGIN_CACHE_TTL
        || (!empty($cache['partial']) && $now >= (int)$cache['next_attempt_at']);
}

// Returns the listing plus a display state: ok, stale (last-known-good), error or pending.
function chimPluginDiscoveryGet(bool $manualRefresh = false): array
{
    $cache = chimPluginDiscoveryLoad();
    $now = time();
    $notice = '';
    $expired = chimPluginDiscoveryExpired($cache, $now);
    if ($manualRefresh || $expired) {
        $lock = chimPluginEnsureCacheDir() ? chimPluginOpenRefreshLock() : false;
        if (!$lock) {
            $notice = 'The plugin list cache is not writable by this server account (' . chimPluginCacheDir() . ' and its refresh.lock must be group-writable); refresh is disabled until it is.';
        } elseif (!flock($lock, LOCK_EX | LOCK_NB)) {
            $notice = 'Another plugin list refresh is in progress.';
        } else {
            $cache = chimPluginDiscoveryLoad();
            $expired = chimPluginDiscoveryExpired($cache, $now);
            $wait = (int)$cache['next_attempt_at'] - $now;
            if ($wait > 0 && ($manualRefresh || $expired)) {
                if ($manualRefresh) {
                    $notice = 'Refresh is available again in ' . $wait . ' seconds.';
                }
            } elseif ($manualRefresh || $expired) {
                $cache = chimPluginDiscoveryRefresh($cache);
                if (!chimPluginDiscoverySave($cache)) {
                    $notice = 'The plugin list was fetched but could not be cached; check that ' . chimPluginCacheDir() . ' is writable by the web server.';
                }
            }
            flock($lock, LOCK_UN);
        }
        if ($lock) {
            fclose($lock);
        }
    }
    if ((int)$cache['fetched_at'] === 0 && !empty($cache['plugins'])) {
        $state = 'stale';
    } elseif ((int)$cache['fetched_at'] === 0) {
        $state = $cache['error'] !== '' ? 'error' : 'pending';
    } else {
        $state = ($cache['error'] !== '' || $now - (int)$cache['fetched_at'] >= CHIM_PLUGIN_CACHE_TTL) ? 'stale' : 'ok';
    }
    return $cache + ['state' => $state, 'notice' => $notice];
}

// Installed extensions keyed by folder; their manifest records the source repository.
function chimPluginInstalledExtensions(string $extRoot): array
{
    $installed = [];
    foreach (is_dir($extRoot) ? scandir($extRoot) : [] as $folder) {
        if ($folder[0] === '.' || !is_dir($extRoot . '/' . $folder)) {
            continue;
        }
        // null: no manifest; []: unreadable manifest (still listed, owner unknown).
        $path = $extRoot . '/' . $folder . '/manifest.json';
        $installed[$folder] = null;
        if (is_file($path)) {
            $manifest = json_decode((string)file_get_contents($path), true);
            $installed[$folder] = is_array($manifest) ? $manifest : [];
        }
    }
    return $installed;
}

// Which repository owns ext/<name>: '' if unknown, null if the folder is free.
function chimPluginInstalledOwner(array $installed, string $packageName, array $overrides): ?string
{
    if (!array_key_exists($packageName, $installed)) {
        return null;
    }
    $manifest = $installed[$packageName];
    if (is_array($manifest) && chimPluginValidRepo($manifest['git_repo'] ?? null)) {
        return $manifest['git_repo'];
    }
    // Legacy catalog installs without git_repo are attributed through the override file.
    foreach ($overrides as $override) {
        if (($override['name'] ?? '') === $packageName && is_array($manifest) && ($manifest['name'] ?? '') === $packageName) {
            return $override['git_repo'];
        }
    }
    return '';
}

// ext/<folder> for a repository: the folder it already owns (also after a GitHub rename, matched by
// the repository id this installer recorded), else the repository name for the standard package,
// else the legacy manifest name. 'reason' explains why it cannot be installed.
function chimPluginResolvePackage(array $entry, array $installed, array $overrides): array
{
    $repoId = (int)($entry['repo_id'] ?? 0);
    foreach ($installed as $folder => $manifest) {
        $folder = (string)$folder;
        $owner = chimPluginInstalledOwner($installed, $folder, $overrides);
        $sameId = $repoId > 0 && is_array($manifest) && ($manifest['source'] ?? '') === 'github-release' && (int)($manifest['repo_id'] ?? 0) === $repoId;
        if (chimPluginValidPackageName($folder) && (chimPluginSameRepo($owner, $entry['repo']) || $sameId)) {
            return ['name' => $folder, 'installed' => true, 'reason' => ''];
        }
    }
    $name = ($entry['release']['status'] ?? '') === 'ok' ? $entry['name'] : ($entry['legacy']['name'] ?? $entry['name']);
    if (!chimPluginValidPackageName($name)) {
        return ['name' => '', 'installed' => false, 'reason' => 'The repository name cannot be used as a plugin folder.'];
    }
    foreach (array_keys($installed) as $folder) {
        if (strcasecmp((string)$folder, $name) === 0) {
            $owner = chimPluginInstalledOwner($installed, (string)$folder, $overrides);
            return ['name' => $name, 'installed' => false,
                'reason' => 'ext/' . $folder . ' is already used by ' . ($owner ? $owner : 'a plugin from another source') . '.'];
        }
    }
    return ['name' => $name, 'installed' => false, 'reason' => ''];
}

// Why a discovered repository has no install channel, for display.
function chimPluginUnavailableReason(array $entry): string
{
    $release = $entry['release'] ?? [];
    $reason = (string)($release['reason'] ?? '');
    return ($reason !== '' ? $reason . ' ' : '') . 'Installation needs a chim-plugin.tar.gz asset on the latest stable release.';
}

// Rejects anything but regular files and directories with safe relative names before extraction.
function chimPluginCheckArchiveListing(string $archiveFile, bool $gzip): string
{
    $names = [];
    $long = [];
    exec('tar ' . ($gzip ? '-tzf' : '-tf') . ' ' . escapeshellarg($archiveFile), $names, $status);
    exec('tar ' . ($gzip ? '-tvzf' : '-tvf') . ' ' . escapeshellarg($archiveFile), $long, $longStatus);
    if ($status !== 0 || $longStatus !== 0 || empty($names)) {
        return 'The package is not a readable tar archive.';
    }
    if (count($long) !== count($names)) {
        return 'The package listing could not be verified.';
    }
    foreach ($names as $index => $name) {
        $type = substr((string)$long[$index], 0, 1);
        if ($type !== '-' && $type !== 'd') {
            return 'The package contains a link or special file: ' . chimPluginText($name, 200);
        }
        if ($name === '' || $name[0] === '/' || strpos($name, '\\') !== false || preg_match('#(^|/)\.\.(/|$)#', $name) === 1) {
            return 'The package contains an unsafe path: ' . chimPluginText($name, 200);
        }
    }
    return '';
}

// After private extraction: no links or special files, and the server files sit at the archive root.
function chimPluginCheckStagedTree(string $stagingDir): string
{
    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($stagingDir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    foreach ($items as $path => $item) {
        if (is_link($path) || (!$item->isFile() && !$item->isDir())) {
            return 'The package contains a link or special file: ' . chimPluginText(substr($path, strlen($stagingDir) + 1), 200);
        }
    }
    $root = array_values(array_diff(scandir($stagingDir), ['.', '..']));
    $rootFiles = array_filter($root, function ($name) use ($stagingDir) {
        return is_file($stagingDir . DIRECTORY_SEPARATOR . $name);
    });
    if (empty($root)) {
        return 'The package is empty.';
    }
    if (empty($rootFiles)) {
        return 'chim-plugin.tar.gz must contain the server files at its root, not inside a folder (found ' . chimPluginText(implode(', ', $root), 200) . ').';
    }
    return '';
}

// manifest.json for the existing Plugin Manager loaders, generated from the verified repository and
// release. Authors do not need to ship one; a bundled manifest's other fields are kept, but it cannot
// set the name, source, version, channels, branding or settings page. A settings page is recorded only
// when index.php or index.html is actually present.
function chimPluginGeneratedManifest(string $stagingDir, string $packageName, array $source, string $webRoot): array
{
    $bundled = [];
    $path = $stagingDir . DIRECTORY_SEPARATOR . 'manifest.json';
    if (is_file($path) && !is_link($path) && filesize($path) <= CHIM_PLUGIN_MANIFEST_BYTES) {
        $decoded = json_decode((string)file_get_contents($path), true);
        $bundled = is_array($decoded) ? $decoded : [];
    }
    $reserved = ['name', 'display_name', 'version', 'description', 'git_repo', 'repo_id', 'source', 'release_tag', 'release_asset',
        'package_url', 'package_urls', 'manifest_url', 'channel', 'channel_label', 'channels', 'default_channel', 'schema_version',
        'config_url', 'config_url_target', 'featured', 'icon', 'generated_by', 'generated_note', 'installed_at'];
    $extra = array_diff_key($bundled, array_flip($reserved));
    if (isset($extra['mod_download_url']) && !isset(chimPluginCatalogFields($extra)['mod_download_url'])) {
        unset($extra['mod_download_url']);
    }
    $description = $source['description'] !== '' ? $source['description'] : chimPluginText($bundled['description'] ?? '', 600);
    $manifest = [
        'name' => $packageName,
        'display_name' => $source['display_name'],
        'version' => $source['tag'],
        'description' => $description,
        'git_repo' => $source['repo'],
        'repo_id' => (int)$source['repo_id'],
        'source' => 'github-release',
        'release_tag' => $source['tag'],
        'release_asset' => $source['asset'],
        'package_url' => $source['url'],
        'channel' => $source['channel'],
        'channel_label' => $source['channel_label'],
        'generated_by' => 'CHIM Plugin Manager',
        'generated_note' => 'Generated at install from the GitHub repository and release; plugin authors do not need to provide manifest.json.',
        'installed_at' => gmdate('c'),
    ];
    foreach (['index.php', 'index.html'] as $page) {
        $pagePath = $stagingDir . DIRECTORY_SEPARATOR . $page;
        if (is_file($pagePath) && !is_link($pagePath)) {
            $manifest['schema_version'] = 2;
            $manifest['config_url'] = $webRoot . '/ext/' . rawurlencode($packageName) . '/' . $page;
            $manifest['config_url_target'] = '_blank';
            break;
        }
    }
    return $manifest + $extra;
}
