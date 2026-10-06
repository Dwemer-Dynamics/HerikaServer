<?php

// Conservative request body cap shared by both connectors, based on the 1 MiB
// max_request_body_bytes that the DwemerDistro Higgs service ships with.
defined('CHIM_AUDIO_CPP_MAX_BODY_BYTES') || define('CHIM_AUDIO_CPP_MAX_BODY_BYTES', 1048576);
defined('CHIM_AUDIO_CPP_JSON_FLAGS') || define('CHIM_AUDIO_CPP_JSON_FLAGS', JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

if (!function_exists('chimAudioCppIsLoopback')) {
    function chimAudioCppIsLoopback(string $endpoint): bool
    {
        $host = strtolower(strval(parse_url($endpoint, PHP_URL_HOST)));
        return in_array($host, ['localhost', '127.0.0.1', '::1', '[::1]'], true);
    }
}

if (!function_exists('chimAudioCppReadReference')) {
    // Reads a complete WAV no larger than $maxBytes, or returns null.
    function chimAudioCppReadReference(string $path, int $maxBytes): ?string
    {
        clearstatcache(true, $path);
        $size = is_file($path) && is_readable($path) ? intval(@filesize($path)) : 0;
        if ($size <= 44 || $size > $maxBytes) {
            return null;
        }
        $bytes = @file_get_contents($path, false, null, 0, $maxBytes + 1);
        if (!is_string($bytes) || strlen($bytes) !== $size
            || substr($bytes, 0, 4) !== 'RIFF' || substr($bytes, 8, 4) !== 'WAVE') {
            return null;
        }
        return $bytes;
    }
}

if (!function_exists('chimAudioCppVoiceReference')) {
    // Resolves the voice_ref for an audio.cpp speech request without changing the voice.
    // Same-host services read the sample path. Remote services receive the uploaded
    // data/voices sample inline as base64, downmixed to 24 kHz mono only when the
    // original does not fit the request body cap. Names containing path separators,
    // control characters or a leading dot are rejected, never rewritten.
    // Returns status path|inline|none|error; failures also set CHIM_AUDIO_CPP_VOICE_REF_ERROR.
    function chimAudioCppVoiceReference(string $endpoint, string $voice, array $request, array $serviceDirs = []): array
    {
        $fail = function (string $message): array {
            $GLOBALS['CHIM_AUDIO_CPP_VOICE_REF_ERROR'] = $message;
            return ['status' => 'error', 'error' => $message];
        };
        $name = trim($voice);
        if (strtolower(substr($name, -4)) === '.wav') {
            $name = substr($name, 0, -4);
        }
        if ($name === '') {
            return ['status' => 'none'];
        }
        if (strpbrk($name, '/\\') !== false || $name[0] === '.' || preg_match('/[\x00-\x1f\x7f]/', $name)) {
            return $fail('Voice name is not a valid sample name. Choose a voice without path characters.');
        }
        $uploadDir = dirname(__DIR__) . '/data/voices/';
        if (chimAudioCppIsLoopback($endpoint)) {
            foreach (array_merge([$uploadDir], $serviceDirs) as $directory) {
                if (is_readable($directory . $name . '.wav')) {
                    return ['status' => 'path', 'voice_ref' => $directory . $name . '.wav'];
                }
            }
            return ['status' => 'none'];
        }

        $root = realpath($uploadDir);
        $sample = realpath($uploadDir . $name . '.wav');
        if ($root === false || $sample === false || !is_file($sample)) {
            return ['status' => 'none'];
        }
        if (dirname($sample) !== $root) {
            return $fail("Voice sample {$name}.wav must be a regular file inside data/voices. Upload it again.");
        }
        $size = intval(@filesize($sample));
        $header = @file_get_contents($sample, false, null, 0, 12);
        if (!is_readable($sample) || $size <= 44 || $size > 64 * 1048576
            || !is_string($header) || substr($header, 0, 4) !== 'RIFF' || substr($header, 8, 4) !== 'WAVE') {
            return $fail("Voice sample {$name}.wav is unreadable or not a valid WAV file. Upload it again.");
        }

        $request['voice_ref'] = ['type' => 'base64', 'data' => ''];
        unset($request['voice']);
        $envelope = json_encode($request, CHIM_AUDIO_CPP_JSON_FLAGS);
        if (!is_string($envelope)) {
            return $fail('The speech request could not be encoded as JSON. Check the text for invalid characters.');
        }
        $maxRaw = intdiv(max(0, CHIM_AUDIO_CPP_MAX_BODY_BYTES - strlen($envelope)), 4) * 3;
        $tooLong = function () use ($fail, $name, $maxRaw): array {
            $seconds = max(1, intdiv($maxRaw, 48000));
            return $fail("Voice sample {$name}.wav is too long for the remote audio.cpp request limit. "
                . "Trim it to about {$seconds} seconds and upload it again.");
        };
        if ($maxRaw <= 44) {
            return $tooLong();
        }
        if ($size <= $maxRaw) {
            $bytes = chimAudioCppReadReference($sample, $maxRaw);
            if ($bytes === null) {
                return $fail("Voice sample {$name}.wav is unreadable or not a valid WAV file. Upload it again.");
            }
            return ['status' => 'inline', 'voice_ref' => ['type' => 'base64', 'data' => base64_encode($bytes)]];
        }

        // Reuse one downmixed copy per sample version; the original upload is never modified.
        // The copy is published by atomic rename, so a reader never sees a partial file.
        $cacheDir = dirname(__DIR__) . '/soundcache/';
        $cached = $cacheDir . 'voiceref-' . md5($sample . '|' . filemtime($sample) . '|' . $size) . '.wav';
        $bytes = chimAudioCppReadReference($cached, $maxRaw);
        if ($bytes === null && is_file($cached) && intval(@filesize($cached)) > $maxRaw) {
            return $tooLong();
        }
        if ($bytes === null) {
            $temp = @tempnam($cacheDir, 'voiceref-');
            if ($temp === false) {
                return $fail("Voice sample {$name}.wav is too large to send to the remote audio.cpp service and could not be converted.");
            }
            $converted = $temp . '.wav';
            $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
            exec(escapeshellarg(getenv('FFMPEG_PATH') ?: 'ffmpeg') . ' -y -i ' . escapeshellarg($sample)
                . ' -ac 1 -ar 24000 -c:a pcm_s16le ' . escapeshellarg($converted) . " >$null 2>&1", $unused, $exitCode);
            @unlink($temp);
            if ($exitCode !== 0 || !is_file($converted)) {
                @unlink($converted);
                return $fail("Voice sample {$name}.wav is too large to send to the remote audio.cpp service and could not be converted.");
            }
            if (intval(@filesize($converted)) > $maxRaw) {
                @unlink($converted);
                return $tooLong();
            }
            $bytes = chimAudioCppReadReference($converted, $maxRaw);
            // Web and worker accounts may differ; make our own new copy readable before sharing it.
            @chmod($converted, 0644);
            if ($bytes === null || !@rename($converted, $cached)) {
                @unlink($converted);
            }
            if ($bytes === null) {
                return $fail("Voice sample {$name}.wav could not be converted to a valid WAV file.");
            }
        }
        return ['status' => 'inline', 'voice_ref' => ['type' => 'base64', 'data' => base64_encode($bytes)]];
    }
}

if (!function_exists('chimAudioCppInlineRejection')) {
    // Explains why a remote service rejected an inline voice reference, or returns null.
    // The service's response body is deliberately not included.
    function chimAudioCppInlineRejection(string $service, int $status): ?string
    {
        if ($status === 413) {
            return "{$service} rejected the voice sample as too large (HTTP 413). Trim the voice sample and upload it again.";
        }
        if (in_array($status, [400, 415, 422], true)) {
            return "{$service} rejected the uploaded voice sample sent with the request (HTTP {$status}). "
                . 'Update the remote audio.cpp service to a version that accepts base64 voice references.';
        }
        if ($status === 500) {
            // Older audio.cpp builds and invalid samples both return 500, so neither cause is assumed.
            return "{$service} was unable to process the uploaded voice sample (HTTP 500). "
                . 'Check that the sample is a valid WAV file and that the remote audio.cpp service supports base64 voice references.';
        }
        return null;
    }
}
