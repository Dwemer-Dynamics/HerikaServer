<?php
// A per-installation barrier keeps old requests from writing into a restored playthrough.
function ptr_runtime_file(string $name)
{
    $directory = dirname(__DIR__) . '/log/playthrough_runtime';
    if (!is_dir($directory) && !@mkdir($directory, 02775, true) && !is_dir($directory)) {
        throw new RuntimeException('Cannot pause background work. Check the server log folder permissions.');
    }
    @chmod($directory, 02775);
    $path = $directory . '/' . $name;
    $handle = @fopen($path, 'c+e'); // Do not carry locks into exec'd background processes.
    if (!$handle) throw new RuntimeException('Cannot open the Playthrough Saves runtime lock.');
    @chmod($path, 0664);
    return $handle;
}

function ptr_runtime_write($handle, string $value): void
{
    rewind($handle);
    if (!ftruncate($handle, 0) || fwrite($handle, $value) !== strlen($value) || !fflush($handle)) {
        throw new RuntimeException('Cannot update the Playthrough Saves runtime state.');
    }
}

function ptr_runtime_paused(): bool
{
    $marker = dirname(__DIR__) . '/log/playthrough_runtime/paused';
    clearstatcache(true, $marker);
    if (!is_file($marker)) return false;
    // A crashed controller must not leave the mod permanently paused.
    $lock = ptr_runtime_file('switch.lock');
    $paused = !flock($lock, LOCK_EX | LOCK_NB);
    fclose($lock);
    return $paused;
}

// Hold one shared lease for the whole request, including forked manager tasks.
function ptr_runtime_enter(): void
{
    if (!empty($GLOBALS['ptr_runtime_controller']) || isset($GLOBALS['ptr_runtime_lease'])) return;
    $lease = ptr_runtime_file('work.lock');
    if (ptr_runtime_paused() || !flock($lease, LOCK_SH | LOCK_NB) || ptr_runtime_paused()) {
        fclose($lease);
        if (PHP_SAPI !== 'cli') {
            http_response_code(503);
            header('Retry-After: 2');
            header('Content-Type: application/json');
            echo json_encode(['error' => 'A Playthrough Save is loading. Try again shortly.']);
        }
        exit(75);
    }
    $GLOBALS['ptr_runtime_lease'] = $lease;
    $GLOBALS['ptr_runtime_generation'] = trim((string)@file_get_contents(dirname(__DIR__) . '/log/playthrough_runtime/generation'));
    // PHP closes the descriptor on exit. An explicit LOCK_UN would also unlock forked children.
}

// Only freshly bootstrapped processes may acknowledge the generation they acquired.
function ptr_runtime_ready(string $worker = 'manager'): void
{
    if (empty($GLOBALS['ptr_runtime_generation'])) return;
    $ready = ptr_runtime_file($worker . '.ready');
    ptr_runtime_write($ready, $GLOBALS['ptr_runtime_generation']);
    fclose($ready);
    if ($worker === 'relationship') @unlink(dirname(__DIR__) . '/log/playthrough_runtime/relationship.pending');
}

// Persistent workers finish their current batch, release the lease, and re-exec with fresh settings.
function ptr_runtime_refresh_worker(): void
{
    if (!ptr_runtime_paused()) return;
    $pending = ptr_runtime_file('relationship.pending');
    ptr_runtime_write($pending, (string)getmypid());
    fclose($pending);
    if (isset($GLOBALS['ptr_runtime_lease'])) fclose($GLOBALS['ptr_runtime_lease']);
    unset($GLOBALS['ptr_runtime_lease']);
    while (ptr_runtime_paused()) usleep(200000);
    if (function_exists('pcntl_exec')) {
        pcntl_exec(PHP_BINARY, $GLOBALS['argv']);
    }
    error_log('Playthrough Saves: relationship worker could not reload. Restart this mod server.');
    exit(1);
}

// Acquire before database/advisory locks so active requests can drain without waiting on the switch.
function ptr_runtime_begin_switch(float $timeoutSeconds = 30.0, $connection = null): array
{
    if (isset($GLOBALS['ptr_runtime_lease'])) fclose($GLOBALS['ptr_runtime_lease']);
    unset($GLOBALS['ptr_runtime_lease']);
    $GLOBALS['ptr_runtime_controller'] = true;
    $lock = ptr_runtime_file('switch.lock');
    if (!flock($lock, LOCK_EX | LOCK_NB)) {
        fclose($lock);
        throw new RuntimeException('Another Playthrough Save is loading. Try again shortly.');
    }
    @set_time_limit(0);
    ignore_user_abort(true);
    $state = ['lock' => $lock, 'lease' => null, 'generation' => '', 'relationship' => false, 'connection' => $connection];
    try {
        // Shutdown cleanup also covers fatal PHP errors and client disconnects.
        $GLOBALS['ptr_runtime_switch'] = $state;
        register_shutdown_function(function () {
            if (!isset($GLOBALS['ptr_runtime_switch'])) return;
            $state = $GLOBALS['ptr_runtime_switch'];
            try {
                if ($state['connection'] !== null && pg_transaction_status($state['connection']) !== PGSQL_TRANSACTION_IDLE) {
                    @pg_query($state['connection'], 'ROLLBACK');
                }
            } finally {
                ptr_runtime_release_switch($state);
            }
        });
        $marker = ptr_runtime_file('paused');
        fclose($marker);
        $lease = ptr_runtime_file('work.lock');
        $state['lease'] = $lease;
        $GLOBALS['ptr_runtime_switch'] = $state;
        $deadline = microtime(true) + $timeoutSeconds;
        while (!flock($lease, LOCK_EX | LOCK_NB)) {
            if (microtime(true) >= $deadline) {
                throw new RuntimeException('Background work is still busy. Nothing was restored. Wait a moment and try again.');
            }
            usleep(100000);
        }
        $state['generation'] = bin2hex(random_bytes(16));
        $generation = ptr_runtime_file('generation');
        ptr_runtime_write($generation, $state['generation']);
        fclose($generation);
        $state['relationship'] = is_file(dirname(__DIR__) . '/log/playthrough_runtime/relationship.pending');
        $GLOBALS['ptr_runtime_switch'] = $state;
        return $state;
    } catch (Throwable $e) {
        ptr_runtime_release_switch($state);
        throw $e;
    }
}

// Resume on both success and rollback; only the owning controller removes the pause.
function ptr_runtime_release_switch(array &$state): void
{
    @unlink(dirname(__DIR__) . '/log/playthrough_runtime/paused');
    if (is_resource($state['lease'])) fclose($state['lease']);
    if (is_resource($state['lock'])) fclose($state['lock']);
    $state['lease'] = $state['lock'] = null;
    unset($GLOBALS['ptr_runtime_switch']);
}

// Run after commit/rollback. Health failure is a warning, never a failed database restore.
function ptr_runtime_finish_switch(?array &$state, float $timeoutSeconds = 12.0): bool
{
    if ($state === null) return true;
    $ready = false;
    try {
        @unlink(dirname(__DIR__) . '/log/playthrough_runtime/paused');
        if (is_resource($state['lease'])) fclose($state['lease']);
        $state['lease'] = null;
        $GLOBALS['ptr_runtime_switch'] = $state;
        require_once __DIR__ . '/background_processor.php';
        herikaEnsureBackgroundProcessorRunning(false);
        $deadline = microtime(true) + $timeoutSeconds;
        do {
            $directory = dirname(__DIR__) . '/log/playthrough_runtime/';
            $ready = trim((string)@file_get_contents($directory . 'manager.ready')) === $state['generation'];
            if ($state['relationship']) {
                $ready = $ready && trim((string)@file_get_contents($directory . 'relationship.ready')) === $state['generation'];
            }
            if ($ready) break;
            usleep(100000);
        } while (microtime(true) < $deadline);
    } catch (Throwable $e) {
        error_log('Playthrough Saves: background refresh failed: ' . $e->getMessage());
    } finally {
        ptr_runtime_release_switch($state);
        $state = null;
    }
    if (!$ready) error_log('Playthrough Saves: fresh background processing was not confirmed. Restart this mod server.');
    return $ready;
}
