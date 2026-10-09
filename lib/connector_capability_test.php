<?php

// Validate the real connector's output without dispatching actions, speech or fallback requests.
function chimValidateConnectorDialogue(string $response, bool $jsonDriver): array
{
    if (!$jsonDriver) {
        return [
            'dialogue' => ['status' => trim($response) !== '' ? 'pass' : 'fail', 'message' => 'Plain-text response'],
            'actions' => ['status' => 'skipped', 'message' => 'Native tool calls are not exercised by this dialogue test'],
        ];
    }
    $object = json_decode($response, true);
    $valid = is_array($object) && !array_is_list($object);
    foreach (['character', 'listener', 'message', 'mood'] as $key) {
        $valid = $valid && is_string($object[$key] ?? null);
    }
    $valid = $valid && trim($object['message'] ?? '') !== '';
    $actions = is_array($object) && ($object['action'] ?? null) === 'Talk'
        && isset($object['target']) && is_string($object['target']) && trim($object['target']) === '';
    foreach (['item', 'amount'] as $key) {
        if (is_array($object) && array_key_exists($key, $object) && (!is_string($object[$key]) || trim($object[$key]) !== '')) {
            $actions = false;
        }
    }
    return [
        'dialogue' => ['status' => $valid ? 'pass' : 'fail', 'message' => $valid
            ? 'Complete dialogue JSON with typed fields' : 'Expected a JSON object with character, listener, message and mood strings and nonempty speech'],
        'actions' => ['status' => $actions ? 'pass' : 'fail', 'message' => $actions
            ? 'Talk action and empty target; no action executed' : 'Expected action Talk with empty target, item and amount'],
    ];
}

// Use the selected driver's streaming path. Recovery health and fallback routing are deliberately not invoked.
function chimRunConnectorCapabilityTest(object $handler, string $driver): array
{
    $jsonDriver = in_array($driver, ['openaijson', 'openrouterjson', 'google_openaijson', 'groqjson', 'koboldcppjson'], true);
    $context = [
        ['role' => 'system', 'content' => 'This is an isolated connection test, not a game scene. Respond with a short greeting. Do not request any action.'],
        ['role' => 'user', 'content' => $jsonDriver
            ? 'Use the required dialogue JSON. Set action to Talk, target to an empty string, and item and amount to empty strings if present. Say Hello in message.'
            : 'Reply with Hello.'],
    ];
    $started = microtime(true);
    $deadline = $started + 120;
    $chunks = '';
    $response = '';
    $failure = '';
    $opened = false;
    $done = false;
    $completion = null;
    $diagnostics = [];
    try {
        $openResult = $handler->open($context, []);
        $opened = $openResult !== false;
        if (!$opened) {
            throw new RuntimeException('Connector could not open the request');
        }
        for ($iterations = 0; $iterations < 2000; $iterations++) {
            if ($handler->isDone()) {
                $done = true;
                break;
            }
            if (microtime(true) >= $deadline) {
                throw new RuntimeException('Test exceeded the 120 second limit');
            }
            $chunk = $handler->process();
            if ($chunk === -1) {
                throw new RuntimeException('Connector stopped: ' . ($handler->recoveryFailure ?? 'stream error'));
            }
            $chunks .= (string) $chunk;
            if (strlen($chunks) > 1048576) {
                throw new RuntimeException('Test response exceeded the 1 MB limit');
            }
        }
        $done = $done || $handler->isDone();
        if (!$done) {
            throw new RuntimeException('Test stopped before the connector completed');
        }
        if (method_exists($handler, 'providerTestCompletion')) {
            $completion = $handler->providerTestCompletion();
            if (!$completion['complete'] || $completion['refused'] || $completion['failure'] !== null
                || in_array($completion['finish_reason'], ['length', 'content_filter'], true)) {
                $reason = $completion['failure'] ?? $completion['finish_reason'] ?? 'truncated';
                if ($completion['refused']) $reason = 'refused';
                throw new RuntimeException('Response did not finish successfully: ' . $reason);
            }
        }
    } catch (Throwable $e) {
        $failure = $e->getMessage();
    } finally {
        if (method_exists($handler, 'providerDiagnostics')) {
            $diagnostics = $handler->providerDiagnostics();
        }
        // Legacy close() can drain a still-open stream. On error, close its resource directly.
        if ($failure !== '') {
            if (isset($handler->primary_handler) && is_resource($handler->primary_handler)) {
                fclose($handler->primary_handler);
            }
        } elseif ($opened) {
            try {
                $closed = $handler->close('connector_capability_test');
                $response = is_string($closed) && $closed !== '' ? $closed : $chunks;
            } catch (Throwable $e) {
                $failure = $e->getMessage();
            }
        }
    }
    $httpStatus = method_exists($handler, 'getHttpStatusCode') ? $handler->getHttpStatusCode() : 0;
    $connected = $opened && ($httpStatus === 0 ? ($response !== '' || $chunks !== '') : ($httpStatus >= 200 && $httpStatus < 300));
    $connectionMessage = $connected ? 'Driver returned data; HTTP status unavailable' : 'No successful connection established';
    if ($httpStatus > 0) $connectionMessage = 'HTTP ' . $httpStatus;
    $completionStatus = 'warn';
    $completionMessage = 'Driver ended; provider finish status is unavailable';
    if ($failure !== '') {
        $completionStatus = 'fail';
        $completionMessage = $failure;
    } elseif ($completion !== null) {
        $completionStatus = 'pass';
        $completionMessage = 'Provider completed the response';
    }
    $checks = [
        'connection' => ['status' => $connected ? 'pass' : 'fail', 'message' => $connectionMessage],
        'completion' => ['status' => $completionStatus, 'message' => $completionMessage],
    ];
    $checks += chimValidateConnectorDialogue($response, $jsonDriver);
    $statuses = array_column($checks, 'status');
    $status = 'pass';
    if (in_array('fail', $statuses, true)) $status = 'fail';
    elseif (in_array('warn', $statuses, true)) $status = 'warn';
    return ['status' => $status, 'checks' => $checks, 'response_preview' => mb_substr($response, 0, 180),
        'timings' => $diagnostics, 'elapsed_ms' => (int) round((microtime(true) - $started) * 1000)];
}

// Decision connectors answer one fixed choice through the shared jev_request instead of writing dialogue.
function chimRunDecisionCapabilityTest(object $handler): array
{
    $criteria = [
        'comedy' => 'The dialogue is primarily humorous, playful, or intended to amuse.',
        'horror' => 'The dialogue is primarily frightening, supernatural, or disturbing.',
        'default' => 'No listed genre clearly describes the dialogue.',
    ];
    $started = microtime(true);
    $failure = '';
    $response = [];
    try {
        $response = $handler->jev_request(
            ['dialogue' => "Lydia: Why did the mudcrab cross the road? To get to the other tide!\nPlayer: That joke is terrible, Lydia. I love it."],
            'This is an isolated connection test. Choose the dominant genre of the dialogue.',
            $criteria,
            'connector_capability_test'
        );
    } catch (Throwable $e) {
        $failure = $e->getMessage();
    }
    $answer = is_array($response) ? ($response['answers']['genre'] ?? null) : null;
    $choice = is_array($answer) ? ($answer['choice'] ?? null) : null;
    $confidence = is_array($answer) ? ($answer['confidence'] ?? null) : null;
    $connected = is_array($response) && $response !== [];
    $valid = is_string($choice) && array_key_exists($choice, $criteria)
        && (is_int($confidence) || is_float($confidence)) && is_finite((float) $confidence)
        && $confidence >= 0 && $confidence <= 1;
    $connectionMessage = $failure !== '' ? $failure : 'No usable decision response; see the server log';
    if ($connected) $connectionMessage = 'Decision endpoint returned a response';
    $decisionStatus = 'fail';
    $decisionMessage = 'Expected a listed choice with a confidence from 0 to 1';
    if ($valid) {
        $decisionStatus = $choice === 'comedy' ? 'pass' : 'warn';
        $decisionMessage = sprintf('Chose %s with confidence %.2f%s', $choice, $confidence, $choice === 'comedy' ? '' : ' (expected comedy)');
    }
    $checks = [
        'connection' => ['status' => $connected ? 'pass' : 'fail', 'message' => $connectionMessage],
        'decision' => ['status' => $decisionStatus, 'message' => $decisionMessage],
        'dialogue' => ['status' => 'skipped', 'message' => 'Decision connectors cannot generate dialogue'],
    ];
    $statuses = array_column($checks, 'status');
    $status = 'pass';
    if (in_array('fail', $statuses, true)) $status = 'fail';
    elseif (in_array('warn', $statuses, true)) $status = 'warn';
    return ['status' => $status, 'checks' => $checks, 'response_preview' => $valid ? $choice : '',
        'timings' => [], 'elapsed_ms' => (int) round((microtime(true) - $started) * 1000)];
}

// A semantic vision check is separate from the successful image request itself.
function chimValidateConnectorVision(string $response): array
{
    $answer = strtolower(trim($response, " \t\n\r\0\x0B.\"'`"));
    $correct = preg_match('/^red\s*[,;\-]\s*blue$/', $answer) === 1;
    return ['status' => $correct ? 'pass' : 'warn', 'message' => $correct
        ? 'Correctly identified the left and right colours' : 'Image request returned text, but colour recognition was not confirmed (expected red, blue)'];
}
