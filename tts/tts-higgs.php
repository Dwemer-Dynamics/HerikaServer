<?php

// Generate reference-conditioned speech through the isolated Higgs audio.cpp service.
$GLOBALS['TTS_IN_USE'] = function ($textString, $mood, $stringforhash) {
    $settings = $GLOBALS['TTS']['HIGGS'] ?? [];
    $endpoint = rtrim(trim(strval($settings['endpoint'] ?? 'http://127.0.0.1:8025')), '/');
    $model = trim(strval($settings['model'] ?? 'higgs-v3')) ?: 'higgs-v3';
    $voice = trim(strval($GLOBALS['PATCH_OVERRIDE_VOICE'] ?? ''));
    if ($voice === '') $voice = trim(strval($GLOBALS['TTS']['FORCED_VOICE_DEV'] ?? ''));
    if ($voice === '') $voice = trim(strval($settings['voiceid'] ?? ''));
    if ($voice === '') {
        Logger::warn('Higgs requires an NPC voice sample or an explicit fallback voice.');
        return false;
    }
    $voice = basename(str_replace('\\', '/', $voice), '.wav');
    if ($voice === '' || $voice === '.' || $voice === '..') return false;
    $text = strval($textString);
    foreach (['HIGGS_TEXTMODIFIER', 'XTTS_TEXTMODIFIER'] as $group) {
        foreach (($GLOBALS['HOOKS'][$group] ?? []) as $hook) $text = call_user_func($hook, $text);
    }
    $hash = md5('higgs|' . $endpoint . '|' . $model . '|' . $voice . '|' . trim($stringforhash) . '|' . $text);
    $cache = dirname(__DIR__) . '/soundcache/';
    $output = $cache . $hash . '.wav';
    if (($GLOBALS['AVOID_TTS_CACHE'] ?? true) === false && is_file($output) && filesize($output) > 44) return 'soundcache/' . $hash . '.wav';
    $request = ['model' => $model, 'input' => $text, 'voice' => $voice];
    $host = strtolower(strval(parse_url($endpoint, PHP_URL_HOST)));
    if (!in_array(strtolower(strval(parse_url($endpoint, PHP_URL_SCHEME))), ['http', 'https'], true)) return false;
    // Filesystem references are valid only when inference shares this machine.
    if (in_array($host, ['localhost', '127.0.0.1', '::1', '[::1]'], true)) {
        foreach ([dirname(__DIR__) . '/data/voices/', '/home/dwemer/higgs-tts/voices/'] as $directory) {
            $sample = $directory . $voice . '.wav';
            if (is_readable($sample)) {
                unset($request['voice']);
                $request['voice_ref'] = $sample;
                break;
            }
        }
    }
    $url = str_ends_with($endpoint, '/v1/audio/speech') ? $endpoint : $endpoint . '/v1/audio/speech';
    $curl = curl_init($url);
    curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 120,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: audio/wav'],
        CURLOPT_POSTFIELDS => json_encode($request)]);
    $start = microtime(true);
    $audio = curl_exec($curl);
    $status = intval(curl_getinfo($curl, CURLINFO_HTTP_CODE));
    curl_close($curl);
    if ($status !== 200 || !is_string($audio) || strlen($audio) <= 44 || substr($audio, 0, 4) !== 'RIFF' || substr($audio, 8, 4) !== 'WAVE') {
        Logger::warn('Higgs speech generation failed (HTTP ' . $status . '). Check the service and selected voice.');
        return false;
    }
    $raw = tempnam($cache, 'higgs-');
    if ($raw === false) return false;
    $converted = $raw . '.wav';
    try {
        if (file_put_contents($raw, $audio) !== strlen($audio)) return false;
        $filters = $GLOBALS['TTS_FFMPEG_FILTERS'] ?? [];
        $filterArgs = is_array($filters) && count($filters) ? ' -af ' . escapeshellarg(implode(',', $filters)) : '';
        $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $command = escapeshellarg(getenv('FFMPEG_PATH') ?: 'ffmpeg') . ' -y -i ' . escapeshellarg($raw)
            . $filterArgs . ' -ac 1 -c:a pcm_s16le ' . escapeshellarg($converted) . " >$null 2>&1";
        exec($command, $unused, $exitCode);
        if ($exitCode !== 0 || !is_file($converted) || filesize($converted) <= 44) {
            Logger::warn('Higgs audio conversion failed.');
            return false;
        }
        if (!rename($converted, $output)) return false;
    } finally {
        if (is_file($converted)) unlink($converted);
        unlink($raw);
    }
    $GLOBALS['DEBUG_DATA'][] = round(microtime(true) - $start, 3) . ' secs in Higgs TTS';
    return 'soundcache/' . $hash . '.wav';
};
