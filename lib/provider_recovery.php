<?php
require_once __DIR__ . '/request_performance.php';

// Optional installation-wide health cache. Locks protect both updates and single recovery probes.
final class ChimProviderHealth
{
    private string $key;
    private ?string $probe = null;
    private string $path;
    private array $diagnostic = ['state' => 'ready', 'retry_in_s' => 0];

    public function diagnostics(): array { return $this->diagnostic; }

    public function __construct(array $connector, ?string $path = null)
    {
        $this->key = hash('sha256', json_encode($connector));
        $this->path = $path ?? __DIR__ . '/../conf/provider_recovery/state.json';
        register_shutdown_function(function () { if ($this->probe !== null) $this->finish(null); });
    }

    // An unavailable cache must never prevent ordinary connector attempts.
    private function update(callable $change)
    {
        $handle = null;
        try {
            $dir = dirname($this->path);
            $newDirectory = !is_dir($dir);
            $mask = umask(0002);
            try {
                if (!is_dir($dir) && !@mkdir($dir, 02775, true) && !is_dir($dir)) {
                    throw new RuntimeException('Cannot create provider health directory');
                }
                foreach ([$dir => 02775, $this->path => 0664] as $path => $mode) {
                    if (file_exists($path) && (!function_exists('posix_geteuid') || fileowner($path) === posix_geteuid())) {
                        if ($path === $dir && $newDirectory && function_exists('posix_geteuid')) @chgrp($path, filegroup(dirname($path)));
                        @chmod($path, $mode);
                    }
                }
                $handle = @fopen($this->path, 'c+');
                if ($handle && (!function_exists('posix_geteuid') || fileowner($this->path) === posix_geteuid())) {
                    @chmod($this->path, 0664);
                }
            } finally { umask($mask); }
            if (!$handle) throw new RuntimeException('Cannot open provider health state');
            // Contention is not cache failure: skip this candidate rather than duplicate a probe.
            if (!flock($handle, LOCK_EX | LOCK_NB)) {
                $this->diagnostic = ['state' => 'busy', 'retry_in_s' => 0];
                return false;
            }
            $raw = stream_get_contents($handle);
            $state = $raw === '' ? [] : json_decode($raw, true);
            if (!is_array($state)) throw new RuntimeException('Invalid provider health state');
            $now = time();
            foreach ($state as $key => $entry) {
                if (($entry['updated'] ?? 0) < $now - 86400) unset($state[$key]);
            }
            $entry = $state[$this->key] ?? ['failures' => 0, 'until' => 0, 'lease' => 0];
            $result = $change($entry, $now);
            $this->diagnostic = ['state' => 'ready', 'retry_in_s' => 0];
            if (($entry['until'] ?? 0) > $now) {
                $this->diagnostic = ['state' => 'cooldown', 'retry_in_s' => $entry['until'] - $now];
            } elseif (($entry['lease'] ?? 0) > $now) {
                $this->diagnostic = ['state' => 'probe', 'retry_in_s' => $entry['lease'] - $now];
            }
            $entry['updated'] = $now;
            unset($state[$this->key]);
            $state[$this->key] = $entry;
            $state = array_slice($state, -128, null, true);
            $json = json_encode($state);
            rewind($handle);
            if (!ftruncate($handle, 0) || fwrite($handle, $json) !== strlen($json) || !fflush($handle)) {
                throw new RuntimeException('Cannot write provider health state');
            }
            return $result;
        } catch (Throwable $e) {
            $this->diagnostic = ['state' => 'unavailable', 'retry_in_s' => 0];
            error_log('[PROVIDER_RECOVERY] ' . $e->getMessage() . '; using ordinary fallback');
            return true;
        } finally { if (is_resource($handle)) fclose($handle); }
    }

    public function begin(int $timeout): bool
    {
        return $this->update(function (&$entry, $now) use ($timeout) {
            if (($entry['until'] ?? 0) > $now || ($entry['lease'] ?? 0) > $now) return false;
            if (($entry['failures'] ?? 0) >= 2) {
                $this->probe = bin2hex(random_bytes(12));
                $entry['probe'] = $this->probe;
                $entry['lease'] = $now + $timeout + 10;
            }
            return true;
        });
    }

    public function finish(?bool $success, int $retryAfter = 0): void
    {
        $this->update(function (&$entry, $now) use ($success, $retryAfter) {
            if ($this->probe !== null && ($entry['probe'] ?? null) !== $this->probe) return;
            // An older request cannot clear another request's active probe.
            if ($this->probe === null && ($entry['lease'] ?? 0) > $now) return;
            $entry['lease'] = 0;
            unset($entry['probe']);
            if ($success === true) {
                $entry['failures'] = 0;
                $entry['until'] = 0;
            } elseif ($success === false) {
                $entry['failures'] = $retryAfter > 0 ? 2 : min(2, ($entry['failures'] ?? 0) + 1);
                if ($entry['failures'] >= 2 || $retryAfter > 0) $entry['until'] = $now + max(30, min(300, $retryAfter));
            }
        });
        $this->probe = null;
    }
}

// Run at most one configured fallback; never replay output that has reached a consumer.
function chimCallWithProviderRecovery(callable $attempt): bool
{
    $original = $GLOBALS['CHIM_CORE_CURRENT_CONNECTOR_DATA'] ?? null;
    if (!$original) return $attempt();
    $connector = new LLMConnector();
    $profile = $GLOBALS['CHIM_CORE_CURRENT_PROFILE_DATA'] ?? [];
    $metadata = $profile['metadata'] ?? [];
    if (is_string($metadata)) $metadata = json_decode($metadata, true);
    $fallback = null;
    if (filter_var($metadata['LLM_FALLBACK_ENABLED'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
        $id = class_exists('LLMRandomizer') ? LLMRandomizer::getConnectorIdForField($profile, 'llm_fallback_id') : ($profile['llm_fallback_id'] ?? null);
        if ($id && $id != ($original['id'] ?? null)) $fallback = $connector->getById($id);
    }
    $attemptGlobals = ['SCRIPTLINE_LISTENER', 'SCRIPTLINE_ANIMATION', 'SCRIPTLINE_EXPRESSION', 'LLM_LANG',
        'LAST_LLM_RESPONSE', 'PATCH', 'PATCH_STORE_FUNC_RES_ACTION', 'PATCH_STORE_FUNC_RES', 'FUNCTIONS_ARE_ENABLED'];
    $saved = [];
    foreach (array_merge(['CONNECTOR', 'IN_FALLBACK_MODE'], $attemptGlobals) as $key) {
        $saved[$key] = [array_key_exists($key, $GLOBALS), $GLOBALS[$key] ?? null];
    }
    $attempted = false;
    $success = false;
    $selectionReason = null;
    $GLOBALS['CHIM_PROVIDER_RECOVERY_HANDLED'] = false;
    try {
        foreach (array_filter([$original, $fallback]) as $candidate) {
            chimInteractionRequire();
            $diagnostic = chimProviderDiagnosticBegin($candidate, $candidate['id'] == $original['id'] ? 'primary' : 'fallback', $selectionReason);
            $GLOBALS['CHIM_PROVIDER_DIAGNOSTIC_INDEX'] = $diagnostic;
            $GLOBALS['CHIM_CORE_CURRENT_CONNECTOR_DATA'] = $candidate;
            $connector->setOldGlobals($candidate);
            $supported = in_array($candidate['driver'], ['openaijson', 'openrouterjson'], true);
            if ($supported) $GLOBALS['CHIM_PROVIDER_RECOVERY_HANDLED'] = true;
            $health = null;
            if ($supported && $fallback) {
                $health = new ChimProviderHealth([$candidate, $GLOBALS['CONNECTOR'][$candidate['driver']] ?? []]);
                if ($diagnostic !== null) $GLOBALS['CHIM_REQUEST_PERFORMANCE']['providers'][$diagnostic]['_health'] = $health;
                if (!$health->begin(max((int)($GLOBALS['HTTP_TIMEOUT'] ?? 30), !empty($candidate['reasoning_model']) ? 90 : 30))) {
                    $selectionReason = $health->diagnostics()['state'];
                    chimProviderDiagnosticEnd($diagnostic, 'skipped', $selectionReason);
                    error_log('[PROVIDER_RECOVERY] Connector ' . $candidate['id'] . ' cooling down');
                    continue;
                }
            }
            foreach ($attemptGlobals as $key) {
                if ($saved[$key][0]) $GLOBALS[$key] = $saved[$key][1]; else unset($GLOBALS[$key]);
            }
            if ($attempted) $GLOBALS['IN_FALLBACK_MODE'] = true;
            $attempted = true;
            $GLOBALS['CHIM_PROVIDER_ATTEMPT'] = ['committed' => false, 'failure' => 'connection', 'handler' => null];
            $success = $attempt();
            $state = $GLOBALS['CHIM_PROVIDER_ATTEMPT'];
            $handler = $state['handler'];
            $status = $handler && method_exists($handler, 'getHttpStatusCode') ? (int)$handler->getHttpStatusCode() : 0;
            if ($diagnostic !== null) $GLOBALS['CHIM_REQUEST_PERFORMANCE']['providers'][$diagnostic]['http_status'] = $status ?: null;
            $transient = $status === 0 || $status === 408 || $status === 429 || $status >= 500 || ($status < 300 && $supported);
            if ($health) $health->finish($success ? true : ($transient ? false : null), $handler && method_exists($handler, 'recoveryRetryAfter') ? $handler->recoveryRetryAfter() : 0);
            $selectionReason = $status >= 300 ? 'http_' . $status : $state['failure'];
            chimProviderDiagnosticEnd($diagnostic, $success ? 'success' : 'failed', $success ? null : $selectionReason);
            if ($success) return true;
            error_log('[PROVIDER_RECOVERY] Connector ' . ($candidate['id'] ?? '?') . ' failed: ' . $state['failure'] . ' HTTP ' . $status);
            if ($state['committed']) { $GLOBALS['ERROR_TRIGGERED'] = true; return false; }
            // Older connectors retain opening-failure fallback only.
            if (!$supported && $state['failure'] !== 'connection') break;
        }
        chimInteractionRequire();
        if (chimFindSupersedingUserInput($GLOBALS['db'], $GLOBALS['gameRequest'][1] ?? '', $GLOBALS['gameRequest'][0] ?? '') !== null) return false;
        $GLOBALS['ERROR_TRIGGERED'] = true;
        if (Translation::isEnabled()) {
            Translation::translate($GLOBALS['ERROR_OPENAI']);
            Translation::$sentences = [Translation::$response];
        }
        returnLines([$GLOBALS['ERROR_OPENAI']], true);
        return false;
    } finally {
        $GLOBALS['CHIM_CORE_CURRENT_CONNECTOR_DATA'] = $original;
        $restore = $success ? ['CONNECTOR', 'IN_FALLBACK_MODE'] : array_merge(['CONNECTOR', 'IN_FALLBACK_MODE'], $attemptGlobals);
        foreach ($restore as $key) {
            if ($saved[$key][0]) $GLOBALS[$key] = $saved[$key][1]; else unset($GLOBALS[$key]);
        }
        unset($GLOBALS['CHIM_PROVIDER_ATTEMPT']);
        unset($GLOBALS['CHIM_PROVIDER_DIAGNOSTIC_INDEX']);
    }
}
