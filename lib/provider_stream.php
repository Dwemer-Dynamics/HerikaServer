<?php

// Bounded reads shared by the JSON dialogue connectors; fast_request keeps its own transport.
trait ChimProviderStream
{
    private float $recoveryDeadline = 0;
    private float $recoveryNextPoll = 0;
    private int $recoveryStatus = 0;
    private int $recoveryRetry = 0;
    private string $recoveryPending = '';
    private bool $recoveryCompleted = false;
    private bool $recoveryRefused = false;
    public ?string $recoveryFailure = null;
    public $recoveryPoll = null;

    private function recoveryStart(int $timeout): void
    {
        $this->recoveryDeadline = microtime(true) + $timeout;
        $this->recoveryNextPoll = 0;
        $this->recoveryStatus = 0;
        $this->recoveryRetry = 0;
        $this->recoveryPending = '';
        $this->recoveryCompleted = false;
        $this->recoveryRefused = false;
        $this->recoveryFailure = null;
    }

    private function recoveryHeaders(): void
    {
        if (!is_resource($this->primary_handler)) return;
        $meta = isset($GLOBALS['mockConnectorResponseMetaData'])
            ? call_user_func($GLOBALS['mockConnectorResponseMetaData']) : stream_get_meta_data($this->primary_handler);
        foreach (($meta['wrapper_data'] ?? []) as $header) {
            if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $header, $match)) {
                $this->recoveryStatus = (int)$match[1];
                $this->recoveryRetry = 0;
            } elseif (stripos($header, 'Retry-After:') === 0) {
                $value = trim(substr($header, 12));
                $seconds = ctype_digit($value) ? (int)$value : ((strtotime($value) ?: time()) - time());
                $this->recoveryRetry = max(0, min(300, $seconds));
            }
        }
        stream_set_blocking($this->primary_handler, false);
    }

    public function getHttpStatusCode(): int { return $this->recoveryStatus; }
    public function recoveryRetryAfter(): int { return $this->recoveryRetry; }

    // Keep partial SSE lines intact while checking cancellation and the absolute attempt deadline.
    private function recoveryRead(bool $whole = false): string
    {
        while (is_resource($this->primary_handler)) {
            $now = microtime(true);
            if ($this->recoveryPoll && $now >= $this->recoveryNextPoll) {
                ($this->recoveryPoll)('provider_wait');
                $this->recoveryNextPoll = $now + 0.25;
            }
            if ($now >= $this->recoveryDeadline) {
                $this->recoveryFailure = 'timeout';
                return '';
            }
            $newline = strpos($this->recoveryPending, "\n");
            if (!$whole && $newline !== false) {
                $line = substr($this->recoveryPending, 0, $newline + 1);
                $this->recoveryPending = substr($this->recoveryPending, $newline + 1);
                return $line;
            }
            if (feof($this->primary_handler)) {
                $line = $this->recoveryPending;
                $this->recoveryPending = '';
                return $line;
            }
            $chunk = fread($this->primary_handler, 8192);
            if ($chunk === false) {
                $this->recoveryFailure = 'read';
                return '';
            }
            if ($chunk !== '') { $this->recoveryPending .= $chunk; continue; }
            $read = [$this->primary_handler];
            $write = $except = [];
            if (@stream_select($read, $write, $except, 0, 100000) === false) {
                $this->recoveryFailure = 'read';
                return '';
            }
        }
        $this->recoveryFailure = 'connection';
        return '';
    }

    private function recoveryObserve(string $line, bool $whole = false): void
    {
        $payload = trim($line);
        if (str_starts_with($payload, 'data:')) $payload = trim(substr($payload, 5));
        if ($payload === '[DONE]') { $this->recoveryCompleted = true; return; }
        $data = json_decode($payload, true);
        if (!is_array($data)) return;
        if (isset($data['error'])) { $this->recoveryFailure = 'provider'; return; }
        $choice = $data['choices'][0] ?? [];
        if (isset($choice['finish_reason']) || ($whole && isset($choice['message']))) $this->recoveryCompleted = true;
        if (!empty($choice['delta']['refusal']) || !empty($choice['message']['refusal'])
            || ($choice['finish_reason'] ?? '') === 'content_filter') $this->recoveryRefused = true;
    }

    public function recoveryStreamDone(): bool
    {
        return $this->recoveryFailure !== null || ($this->recoveryPending === ''
            && ($this->recoveryCompleted || !is_resource($this->primary_handler) || feof($this->primary_handler)));
    }

    public function recoveryUsable(): bool
    {
        if ($this->recoveryFailure !== null) return false;
        if ($this->recoveryRefused) return true;
        if (!$this->recoveryCompleted) { $this->recoveryFailure = 'truncated'; return false; }
        $response = __jpd_decode_lazy($this->_buffer);
        if (!is_array($response)) { $this->recoveryFailure = 'invalid'; return false; }
        if (isset($response[0]) && is_array($response[0])) $response = $response[0];
        $message = $response['message'] ?? '';
        $hasMessage = is_string($message) ? trim($message) !== '' : (is_array($message) && count($message) > 0);
        $action = $response['action'] ?? '';
        $hasAction = is_string($action) && trim($action) !== '' && !in_array(strtolower($action), ['talk', 'none'], true);
        if (!$hasMessage && !$hasAction) { $this->recoveryFailure = 'empty'; return false; }
        return true;
    }
}
