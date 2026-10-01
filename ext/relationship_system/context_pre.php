<?php
/**
 * RELATIONSHIP SYSTEM - Context Injection
 *
 * This file is automatically loaded by main.php at line 1792:
 *   requireFilesRecursively(__DIR__."/ext/","context.php");
 *
 * It injects relationship context into the AI prompt.
 *
 * ASYNC QUEUE PROCESSING:
 * Before injecting context, we process any pending relationship evaluations
 * that were queued by the PREVIOUS request. This ensures relationship data
 * is up-to-date before building the prompt, without blocking the response.
 *
 * TWO MODES:
 * 1. RELLLM_CONNECTOR set: Token-efficient mode
 *    - Only injects tier labels (Fond, Wary, etc.)
 *    - NO #REL: command instructions (RelationshipLLM handles scoring)
 *
 * 2. RELLLM_CONNECTOR not set: Full mode
 *    - Injects numbers and tiers
 *    - Adds #REL: command instructions for conversation model
 */

error_log("TRACE:\t".__LINE__. "\t".__FILE__.":\t".(microtime(true) - $GLOBALS["startTime"]));

// Master toggle - if disabled, skip everything in this file
if (empty($GLOBALS['RELATIONSHIP_SYSTEM_ENABLED'])) {
    return;
}

require_once $GLOBALS["ENGINE_PATH"] . "lib/relationship_manager.php";
require_once $GLOBALS["ENGINE_PATH"] . "lib/logger.php";

// AUTO-START WORKER DAEMON
// When RELLLM_CONNECTOR is set, ensure the background worker is running
// Uses proc_open for proper detachment - no cron, no sudo, no permissions needed
$useRelLLM = !empty($GLOBALS['RELLLM_CONNECTOR']) && $GLOBALS['RELLLM_CONNECTOR'] > 0;
if ($useRelLLM) {
    _relEnsureWorkerRunning();
}

error_log("TRACE:\t".__LINE__. "\t".__FILE__.":\t".(microtime(true) - $GLOBALS["startTime"]));

/**
 * Ensure the relationship worker daemon is running
 * Spawns it in background if not already active - instant, non-blocking
 * Uses proc_open for proper process detachment (Apache can't kill it)
 */
function _relEnsureWorkerRunning() {
    static $checked = false;
    if ($checked) return; // Only check once per PHP request
    $checked = true;

    
    $pidFile = $GLOBALS["ENGINE_PATH"] . 'log/relationship_worker.pid';
    $workerPath = __DIR__ . '/worker.php';
    $logPath = $GLOBALS["ENGINE_PATH"] . 'log/relationship_worker.log';

    Logger::trace("[REL-WORKER-START] Checking worker status...");

    // Check PID file first (fast path)
    if (file_exists($pidFile)) {
        $pid = trim(file_get_contents($pidFile));
        Logger::trace("[REL-WORKER-START] PID file exists with PID: {$pid}");
        if (!empty($pid) && file_exists("/proc/{$pid}")) {
            Logger::trace("[REL-WORKER-START] Worker already running at PID {$pid}");
            return; // Worker is running
        }
        // Stale PID file - worker died, clean up
        Logger::debug("[REL-WORKER-START] Stale PID file, process {$pid} not running, deleting");
        @unlink($pidFile);
    }

    // Double-check with pgrep (in case PID file was missing)
    // Use pgrep -a php and grep to avoid self-matching issues
    $pidCheck = shell_exec("pgrep -a php 2>/dev/null | grep 'worker.php.*--daemon' | awk '{print \$1}' | head -1");
    if (!empty(trim($pidCheck))) {
        // Worker is running, save its PID for next time
        $foundPid = trim(explode("\n", $pidCheck)[0]);
        Logger::debug("[REL-WORKER-START] Found running worker via pgrep: {$foundPid}");
        file_put_contents($pidFile, $foundPid);
        return;
    }

    Logger::info("[REL-WORKER-START] No worker running, spawning new one...");

    // Worker not running - spawn it using proc_open for full detachment
    // This creates a process that survives Apache request termination

    // Ensure log file exists and is writable
    if (!file_exists($logPath)) {
        @touch($logPath);
        Logger::debug("[REL-WORKER-START] Created log file: {$logPath}");
    } elseif (!is_writable($logPath)) {
        @unlink($logPath);
        @touch($logPath);
        Logger::debug("[REL-WORKER-START] Recreated log file (was not writable)");
    }

    $logTarget = is_writable($logPath) ? $logPath : '/dev/null';
    Logger::debug("[REL-WORKER-START] Log target: {$logTarget}");

    $cmd = "/usr/bin/php " . escapeshellarg($workerPath) . " --daemon";
    Logger::debug("[REL-WORKER-START] Command: {$cmd}");

    $descriptors = [
        0 => ['file', '/dev/null', 'r'],
        1 => ['file', $logTarget, 'a'],
        2 => ['file', $logTarget, 'a'],
    ];

    $proc = proc_open($cmd, $descriptors, $pipes);
    if (is_resource($proc)) {
        Logger::debug("[REL-WORKER-START] proc_open succeeded, closing handle");
        proc_close($proc);
    } else {
        Logger::error("[REL-WORKER-START] proc_open FAILED!");
        return;
    }

    // Give the daemon a moment to fork and write its PID
    usleep(200000); // 200ms

    // Verify it started
    if (file_exists($pidFile)) {
        $newPid = trim(file_get_contents($pidFile));
        if (!empty($newPid) && file_exists("/proc/{$newPid}")) {
            Logger::info("[REL-WORKER-START] SUCCESS - worker daemon started (PID: {$newPid})");
            return;
        }
        Logger::warn("[REL-WORKER-START] PID file exists ({$newPid}) but process not found");
    } else {
        Logger::warn("[REL-WORKER-START] No PID file created after spawn");
    }

    // Fallback: check pgrep
    // Use pgrep -a php and grep to avoid self-matching issues
    $pidCheck = shell_exec("pgrep -a php 2>/dev/null | grep 'worker.php.*--daemon' | awk '{print \$1}' | head -1");
    if (!empty(trim($pidCheck))) {
        $newPid = trim(explode("\n", $pidCheck)[0]);
        file_put_contents($pidFile, $newPid);
        Logger::info("[REL-WORKER-START] SUCCESS via pgrep (PID: {$newPid})");
    } else {
        Logger::error("[REL-WORKER-START] FAILED - worker did not start! Check {$logPath} for errors");
    }
}

// Get the current NPC name
$npcName = $GLOBALS["HERIKA_NAME"] ?? null;

Logger::info("[REL-CONTEXT] npcName=" . ($npcName ?? 'NULL') . ", CACHE_PEOPLE=" . substr($GLOBALS["CACHE_PEOPLE"] ?? 'NULL', 0, 100));

if ($npcName) {
    // Exact speaker row and the captured scene roster: same-name actors keep separate edges.
    $sourceRow = is_array($GLOBALS["CHIM_CORE_CURRENT_NPC_DATA"] ?? null) && !empty($GLOBALS["CHIM_CORE_CURRENT_NPC_DATA"]["id"])
        ? $GLOBALS["CHIM_CORE_CURRENT_NPC_DATA"] : $npcName;
    $nearbyNpcs = function_exists('DataCloseRangeActorRoster') ? DataCloseRangeActorRoster() : [];

    // Also include actors mentioned in recent dialogue, by their typed edges only (never legacy names).
    $mentionedNpcs = [];
    if (!empty($GLOBALS["HERIKA_CONTEXT"])) {
        foreach (RelationshipManager::getRelationships($sourceRow) as $key => $rel) {
            $target = is_array($rel) ? ($rel['target'] ?? null) : null;
            if (!is_array($target) || !in_array($target['kind'] ?? '', ['actor', 'concept'], true)) continue;
            $label = (string)($target['label'] ?? '');
            if ($label !== '' && stripos($GLOBALS["HERIKA_CONTEXT"], $label) !== false) {
                $mentionedNpcs[] = ['kind' => $target['kind'], 'key' => (string)$key, 'label' => $label];
            }
        }
    }

    // Build the relationship context block
    // This automatically uses tier-only mode if RELLLM_CONNECTOR is set
    $relationshipContext = RelationshipManager::buildContext($sourceRow, array_merge($nearbyNpcs, $mentionedNpcs));

    Logger::debug("[REL-CONTEXT] buildContext returned " . strlen($relationshipContext) . " chars for " . $npcName);

    // Inject into the character section of the prompt
    // We append to HERIKA_PERS which gets included in the <character> block
    if (!empty($relationshipContext)) {
        $GLOBALS["HERIKA_PERS"] .= "\n\n" . $relationshipContext;
        Logger::debug("[REL-CONTEXT] Injected " . strlen($relationshipContext) . " chars for {$npcName}");
    } else {
        Logger::warn("[REL-CONTEXT] No context to inject for {$npcName}");
    }

    // Only add #REL: command instructions if NOT using dedicated RelationshipLLM
    // When RELLLM_CONNECTOR is set, the relationship model handles all scoring
    // and the conversation model doesn't need to embed commands
    $useRelLLM = !empty($GLOBALS['RELLLM_CONNECTOR']) && $GLOBALS['RELLLM_CONNECTOR'] > 0;

    if (!$useRelLLM) {
        // Add the relationship system instructions to COMMAND_PROMPT
        // This teaches the AI how to use #REL: and #TYPE: commands
        $relationshipInstructions = RelationshipManager::getSystemPromptAddition();
        if (!empty($GLOBALS["COMMAND_PROMPT"])) {
            $GLOBALS["COMMAND_PROMPT"] .= "\n\n" . $relationshipInstructions;
        }
    }
}

error_log("TRACE:\t".__LINE__. "\t".__FILE__.":\t".(microtime(true) - $GLOBALS["startTime"]));

?>