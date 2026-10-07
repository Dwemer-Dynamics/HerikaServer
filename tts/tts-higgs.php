<?php
require_once dirname(__DIR__) . '/lib/speech_trace.php';

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
    // The client requests soundcache/md5(trim(text)).wav; the sidecar binds cache reuse to this voice and these exact bytes.
    $hash = md5(trim($stringforhash));
    $cacheKey = md5('higgs|' . $endpoint . '|' . $model . '|' . $voice . '|' . $text);
    $cache = dirname(__DIR__) . '/soundcache/';
    $output = $cache . $hash . '.wav';
    $keyFile = $output . '.higgs';
    if (($GLOBALS['AVOID_TTS_CACHE'] ?? true) === false && is_file($output) && filesize($output) > 44
        && @file_get_contents($keyFile) === $cacheKey . '|' . @md5_file($output)) return chimTraceCachedTts('soundcache/' . $hash . '.wav', 'higgs');
    $request = ['model' => $model, 'input' => $text, 'voice' => $voice];
    if (!in_array(strtolower(strval(parse_url($endpoint, PHP_URL_SCHEME))), ['http', 'https'], true)) return false;
    // Uploaded samples travel with the request to remote services; named voices remain the fallback.
    require_once __DIR__ . '/audio_cpp_voice_ref.php';
    $reference = chimAudioCppVoiceReference($endpoint, $voice, $request, ['/home/dwemer/higgs-tts/voices/']);
    if ($reference['status'] === 'error') {
        Logger::warn('Higgs: ' . $reference['error']);
        return false;
    }
    if (isset($reference['voice_ref'])) {
        unset($request['voice']);
        $request['voice_ref'] = $reference['voice_ref'];
    }
    $body = json_encode($request, CHIM_AUDIO_CPP_JSON_FLAGS);
    if (!is_string($body)) {
        Logger::warn('Higgs: the speech request could not be encoded as JSON.');
        return false;
    }
    $url = str_ends_with($endpoint, '/v1/audio/speech') ? $endpoint : $endpoint . '/v1/audio/speech';
    $curl = curl_init($url);
    curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 120,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: audio/wav'],
        CURLOPT_POSTFIELDS => $body]);
    $start = microtime(true);
    $audio = curl_exec($curl);
    $status = intval(curl_getinfo($curl, CURLINFO_HTTP_CODE));
    curl_close($curl);
    unset($request, $body);
    $rejection = ($reference['status'] ?? '') === 'inline' ? chimAudioCppInlineRejection('Higgs', $status) : null;
    if ($rejection !== null) {
        $GLOBALS['CHIM_AUDIO_CPP_VOICE_REF_ERROR'] = $rejection;
        Logger::warn($rejection);
        return false;
    }
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
        $digest = md5_file($converted);
        if (!rename($converted, $output)) return false;
        // A failed sidecar write only disables reuse; a WAV later overwritten by another connector no longer matches.
        if ($digest === false || @file_put_contents($keyFile, $cacheKey . '|' . $digest, LOCK_EX) === false) @unlink($keyFile);
    } finally {
        if (is_file($converted)) unlink($converted);
        unlink($raw);
    }
    $GLOBALS['DEBUG_DATA'][] = round(microtime(true) - $start, 3) . ' secs in Higgs TTS';
    return 'soundcache/' . $hash . '.wav';
};
