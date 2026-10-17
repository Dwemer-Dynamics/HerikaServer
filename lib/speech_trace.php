<?php

// Diagnostics only: reuse utterance IDs without adding events to NPC context or memory.
function chimSpeechTrace(string $stage, array $details = [], ?string $utteranceId = null): void
{
    $id = $utteranceId ?? ($GLOBALS['CHIM_SPEECH_TRACE_ID'] ?? '');
    if ($id === '' || !class_exists('Logger')) return;
    static $requestId = null;
    $requestId ??= uniqid('speech_', true);
    $record = ['utterance_id' => substr($id, 0, 128), 'request_id' => $requestId,
        'run_id' => substr((string)($GLOBALS['runid'] ?? $GLOBALS['AUDIT_RUNID'] ?? ''), 0, 128),
        'side' => 'server', 'stage' => $stage,
        'monotonic_ms' => round(hrtime(true) / 1000000, 3)];
    // Do not accept dialogue, provider URLs, credentials, or arbitrary client fields.
    foreach (['sentence', 'connector', 'preset', 'duration_ms', 'bytes', 'reason', 'cache'] as $key) {
        if (isset($details[$key]) && is_scalar($details[$key])) {
            $record[$key] = is_string($details[$key]) ? substr($details[$key], 0, 128) : $details[$key];
        }
    }
    try {
        Logger::info('[SPEECH_TRACE] ' . json_encode($record, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES));
    } catch (Throwable $e) {
        // Diagnostics must not change speech delivery.
    }
}

// Record actual connector cache returns without changing cache policy or the returned path.
function chimTraceCachedTts(string $path, string $connector): string
{
    chimSpeechTrace('tts_cache_hit', ['connector' => $connector]);
    return $path;
}
