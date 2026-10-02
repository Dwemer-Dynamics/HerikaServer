<?php

if (!function_exists('chimTtsStudioProbeSucceeded')) {
    function chimTtsStudioProbeSucceeded(array $probe): bool
    {
        $httpCode = intval($probe['http_code'] ?? 0);
        return ($probe['response'] ?? false) !== false && $httpCode >= 200 && $httpCode < 300;
    }
}

if (!function_exists('chimTtsStudioNormalizeProviderIdentity')) {
    function chimTtsStudioNormalizeProviderIdentity(string $provider): string
    {
        return match (strtolower(trim($provider))) {
            'xtts', 'xtts-fastapi' => 'xtts-fastapi',
            'pocket_tts', 'pocket-tts', 'pockettts' => 'pockettts',
            'chatterbox' => 'chatterbox',
            'omnivoice' => 'omnivoice',
            default => '',
        };
    }
}

// Prefer the provider default over an alphabetical test connector, without changing assignments.
function chimTtsStudioSelectPocketTtsConnector(array $rows, int $playerId, array $profileUsage, string $defaultUrl, callable $resolveUrl): array
{
    $preferred = $rows[0];
    $preferredId = intval($preferred['id']);
    if ($preferredId === $playerId || ($profileUsage[$preferredId] ?? 0) > 0) {
        return $preferred;
    }
    $defaultUrl = preg_replace('~/v1/audio/speech$~', '', rtrim($defaultUrl, '/'));
    foreach ($rows as $row) {
        $url = preg_replace('~/v1/audio/speech$~', '', rtrim($resolveUrl($row), '/'));
        if ($defaultUrl !== '' && $url === $defaultUrl) {
            return $row;
        }
    }
    return $preferred;
}

if (!function_exists('chimTtsStudioClassifyPocketTtsRuntime')) {
    function chimTtsStudioClassifyPocketTtsRuntime(
        string $endpoint,
        array $metadata,
        array $healthProbe,
        array $modelsProbe,
        array $speakersProbe,
        string $standardProvider = ''
    ): array {
        if (chimTtsStudioProbeSucceeded($healthProbe) && chimTtsStudioProbeSucceeded($modelsProbe)) {
            $hasPocketTts = false;
            foreach (($modelsProbe['decoded']['data'] ?? []) as $model) {
                if (($model['family'] ?? '') === 'pocket_tts' || ($model['id'] ?? '') === 'pocket-tts') {
                    $hasPocketTts = true;
                    break;
                }
            }
            return [
                'reachable' => $hasPocketTts,
                'mode' => 'audio_cpp',
                'reason' => $hasPocketTts ? 'audio.cpp PocketTTS model is available' : 'audio.cpp is online but does not advertise a PocketTTS model',
            ];
        }

        if (chimTtsStudioProbeSucceeded($speakersProbe) && is_array($speakersProbe['decoded'] ?? null)) {
            return [
                'reachable' => $standardProvider === '' || $standardProvider === 'pockettts',
                'mode' => 'standard',
                'reason' => ($standardProvider === '' || $standardProvider === 'pockettts')
                    ? 'Standard PocketTTS speakers endpoint responded'
                    : 'Endpoint belongs to ' . $standardProvider . ', not PocketTTS',
            ];
        }

        $apiFormatValue = $metadata['api_format'] ?? '';
        $apiFormat = is_scalar($apiFormatValue) ? strtolower(trim(strval($apiFormatValue))) : '';
        $normalizedEndpoint = rtrim(strtolower(trim($endpoint)), '/');
        $fallbackMode = ($apiFormat === 'audio_cpp'
            || $apiFormat === 'audiocpp'
            || strpos($normalizedEndpoint, ':8086') !== false
            || str_ends_with($normalizedEndpoint, '/v1/audio/speech'))
            ? 'audio_cpp'
            : 'standard';

        $probe = $fallbackMode === 'audio_cpp' ? $healthProbe : $speakersProbe;
        $reason = trim(strval($probe['curl_error'] ?? ''));
        if ($reason === '') {
            $httpCode = intval($probe['http_code'] ?? 0);
            $reason = 'HTTP ' . ($httpCode > 0 ? strval($httpCode) : 'no response');
        }

        return [
            'reachable' => false,
            'mode' => $fallbackMode,
            'reason' => $reason,
        ];
    }
}

if (!function_exists('chimTtsStudioProviderFromOpenApi')) {
    function chimTtsStudioProviderFromOpenApi(array $document): string
    {
        $title = strtolower(trim(strval($document['info']['title'] ?? '')));
        $paths = is_array($document['paths'] ?? null) ? array_keys($document['paths']) : [];
        // Released XTTS exposes several generic speaker/settings routes also used
        // by the other compatible APIs, so test its unique routes first.
        if (in_array('/languages', $paths, true)
            || in_array('/get_models_list', $paths, true)) {
            return 'xtts-fastapi';
        }
        if (str_contains($title, 'chatterbox')
            || (in_array('/sample/{file_name}', $paths, true)
                && in_array('/speakers_list_extended', $paths, true))) {
            return 'chatterbox';
        }
        if (in_array('/tts_to_audio_form', $paths, true)
            || (in_array('/tts_to_audio', $paths, true)
                && in_array('/voices/{voice_id}', $paths, true))) {
            return 'pockettts';
        }
        return '';
    }
}
