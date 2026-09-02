<?php

const CHIM_TTS_FILTER_PRESET_VERSION = 1;

/**
 * Return the server-owned NPC voice-filter catalog.
 */
function ttsFilterPresetCatalog()
{
    return [
        'none' => [
            'id' => 'none',
            'label' => 'None (default)',
            'description' => 'No NPC-specific filter. Dialogue uses the voice engine output.',
            'exposed' => true,
            'filters' => [],
        ],
        'warm' => [
            'id' => 'warm',
            'label' => 'Warm',
            'description' => 'Adds subtle warmth and presence while keeping volume even.',
            'exposed' => true,
            'filters' => [
                'highpass=f=70',
                'lowpass=f=15000',
                'equalizer=f=140:t=q:w=0.9:g=2.0',
                'equalizer=f=3000:t=q:w=1.0:g=1.0',
                'acompressor=threshold=-20dB:ratio=2:attack=10:release=120:makeup=1.5',
                'loudnorm=I=-16:TP=-1.5:LRA=8',
                'aresample=24000',
            ],
        ],
        'deep' => [
            'id' => 'deep',
            'label' => 'Deep',
            'description' => 'Adds low-end weight and reduces harshness without changing speed.',
            'exposed' => true,
            'filters' => [
                'highpass=f=55',
                'lowpass=f=11500',
                'equalizer=f=100:t=q:w=0.8:g=3.0',
                'equalizer=f=250:t=q:w=1.0:g=-1.0',
                'equalizer=f=2200:t=q:w=1.0:g=-1.5',
                'acompressor=threshold=-20dB:ratio=3:attack=10:release=150:makeup=2',
                'loudnorm=I=-16:TP=-1.5:LRA=7',
                'aresample=24000',
            ],
        ],
        'ethereal' => [
            'id' => 'ethereal',
            'label' => 'Ethereal',
            'description' => 'Adds airy presence with a soft, short double echo.',
            'exposed' => true,
            'filters' => [
                'highpass=f=120',
                'lowpass=f=12000',
                'equalizer=f=3500:t=q:w=1.0:g=1.5',
                'acompressor=threshold=-22dB:ratio=2:attack=12:release=180:makeup=1.5',
                'aecho=0.8:0.88:45|90:0.18|0.08',
                'loudnorm=I=-17:TP=-1.5:LRA=8',
                'aresample=24000',
            ],
        ],
        'sinister' => [
            'id' => 'sinister',
            'label' => 'Sinister',
            'description' => 'Darkens the voice and adds a restrained echo.',
            'exposed' => true,
            'filters' => [
                'highpass=f=60',
                'lowpass=f=9500',
                'equalizer=f=110:t=q:w=0.8:g=2.5',
                'equalizer=f=1800:t=q:w=1.0:g=-2.0',
                'equalizer=f=4200:t=q:w=1.1:g=1.0',
                'acompressor=threshold=-20dB:ratio=2.8:attack=10:release=160:makeup=2',
                'aecho=1.0:0.90:65:0.12',
                'loudnorm=I=-16:TP=-1.5:LRA=7',
                'aresample=24000',
            ],
        ],
        'automaton' => [
            'id' => 'automaton',
            'label' => 'Automaton',
            'description' => 'Adds a band-limited mechanical tone with light digital texture.',
            'exposed' => true,
            'filters' => [
                'highpass=f=240',
                'lowpass=f=3800',
                'equalizer=f=1200:t=q:w=0.8:g=3.0',
                'acompressor=threshold=-24dB:ratio=4:attack=5:release=90:makeup=2',
                'acrusher=bits=12:mix=0.18:mode=log',
                'aecho=1.0:0.85:28:0.08',
                'loudnorm=I=-16:TP=-1.5:LRA=6',
                'aresample=24000',
            ],
        ],
        'radio' => [
            'id' => 'radio',
            'label' => 'Radio',
            'description' => 'Creates a compressed communications tone with light digital grit.',
            'exposed' => true,
            'filters' => [
                'highpass=f=300',
                'lowpass=f=3400',
                'equalizer=f=1300:t=q:w=0.9:g=3',
                'acompressor=threshold=-24dB:ratio=4:attack=4:release=80:makeup=2',
                'acrusher=bits=13:mix=0.10:mode=log:samples=2',
                'loudnorm=I=-16:TP=-1.5:LRA=6',
                'aresample=24000',
            ],
        ],
        'haunted' => [
            'id' => 'haunted',
            'label' => 'Haunted',
            'description' => 'Darkens the voice with slow movement and a lingering double echo.',
            'exposed' => true,
            'filters' => [
                'highpass=f=90',
                'lowpass=f=10000',
                'equalizer=f=1800:t=q:w=1.0:g=-1.5',
                'aphaser=in_gain=0.65:out_gain=0.75:delay=3:decay=0.35:speed=0.35:type=sinusoidal',
                'aecho=0.85:0.88:95|190:0.16|0.07',
                'acompressor=threshold=-22dB:ratio=2.5:attack=10:release=160:makeup=1.5',
                'loudnorm=I=-17:TP=-1.5:LRA=8',
                'aresample=24000',
            ],
        ],
        'arcane' => [
            'id' => 'arcane',
            'label' => 'Arcane',
            'description' => 'Adds bright presence and a gentle layered shimmer.',
            'exposed' => true,
            'filters' => [
                'highpass=f=110',
                'lowpass=f=12500',
                'equalizer=f=3600:t=q:w=1.0:g=1.5',
                'chorus=in_gain=0.75:out_gain=0.75:delays=18|24:decays=0.18|0.12:speeds=0.35|0.50:depths=1.5|2.0',
                'acompressor=threshold=-22dB:ratio=2:attack=12:release=160:makeup=1.5',
                'loudnorm=I=-17:TP=-1.5:LRA=8',
                'aresample=24000',
            ],
        ],
        'cavernous' => [
            'id' => 'cavernous',
            'label' => 'Cavernous',
            'description' => 'Adds body and a pair of long, spacious echoes.',
            'exposed' => true,
            'filters' => [
                'highpass=f=75',
                'lowpass=f=11500',
                'equalizer=f=220:t=q:w=0.9:g=1.0',
                'acompressor=threshold=-22dB:ratio=2.5:attack=10:release=180:makeup=1.5',
                'aecho=0.80:0.82:180|360:0.20|0.09',
                'loudnorm=I=-17:TP=-1.5:LRA=9',
                'aresample=24000',
            ],
        ],
        'underwater' => [
            'id' => 'underwater',
            'label' => 'Underwater',
            'description' => 'Heavily muffles the voice and adds slow, fluid movement.',
            'exposed' => true,
            'filters' => [
                'highpass=f=45',
                'lowpass=f=1500',
                'equalizer=f=280:t=q:w=0.8:g=3.0',
                'flanger=delay=2.5:depth=1.5:regen=5:width=22:speed=0.25:shape=sinusoidal:interp=quadratic',
                'acompressor=threshold=-22dB:ratio=3:attack=12:release=180:makeup=2',
                'loudnorm=I=-17:TP=-1.5:LRA=7',
                'aresample=24000',
            ],
        ],
        'warped' => [
            'id' => 'warped',
            'label' => 'Warped',
            'description' => 'Adds uneasy pitch and volume movement with a faint short echo.',
            'exposed' => true,
            'filters' => [
                'highpass=f=100',
                'lowpass=f=10500',
                'vibrato=f=4.5:d=0.12',
                'tremolo=f=3.2:d=0.10',
                'acompressor=threshold=-22dB:ratio=2.5:attack=10:release=150:makeup=1.5',
                'aecho=1.0:0.92:55:0.07',
                'loudnorm=I=-17:TP=-1.5:LRA=8',
                'aresample=24000',
            ],
        ],
        'book_reading' => [
            'id' => 'book_reading',
            'label' => 'Book reading',
            'description' => 'Internal audiobook processing used by BookReader.',
            'exposed' => false,
            'filters' => [
                'highpass=f=70',
                'lowpass=f=14500',
                'equalizer=f=120:t=q:w=0.8:g=1.5',
                'equalizer=f=320:t=q:w=1.0:g=-1.5',
                'equalizer=f=3000:t=q:w=0.9:g=2.0',
                'acompressor=threshold=-18dB:ratio=2.5:attack=8:release=120:makeup=2',
                'aecho=1.0:0.92:55:0.16',
                'atempo=0.85',
                'loudnorm=I=-16:TP=-1.5:LRA=7',
                'aresample=24000',
            ],
        ],
    ];
}

function ttsFilterPresetOptions($exposedOnly = true)
{
    $catalog = ttsFilterPresetCatalog();
    if (!$exposedOnly) {
        return $catalog;
    }

    return array_filter($catalog, function ($preset) {
        return !empty($preset['exposed']);
    });
}

function normalizeTtsFilterPresetId($value, $allowInternal = false)
{
    $id = strtolower(trim(strval($value)));
    $presets = ttsFilterPresetOptions(!$allowInternal);
    return isset($presets[$id]) ? $id : 'none';
}

function setActiveTtsFilterPreset($value, $allowInternal = false)
{
    $id = normalizeTtsFilterPresetId($value, $allowInternal);
    if ($id === 'none') {
        unset($GLOBALS['CHIM_TTS_FILTER_PRESET_ID']);
        return 'none';
    }

    $GLOBALS['CHIM_TTS_FILTER_PRESET_ID'] = $id;
    return $id;
}

function clearActiveTtsFilterPreset()
{
    unset($GLOBALS['CHIM_TTS_FILTER_PRESET_ID']);
}

function getActiveTtsFilterPresetId()
{
    return normalizeTtsFilterPresetId($GLOBALS['CHIM_TTS_FILTER_PRESET_ID'] ?? '', true);
}

function mergeTtsFilterPresetIntoMetadata($metadataValue, $requestedPreset)
{
    if (is_array($metadataValue)) {
        $metadata = $metadataValue;
    } else {
        $metadata = json_decode(strval($metadataValue), true);
        if (!is_array($metadata)) {
            $metadata = [];
        }
    }

    foreach (array_keys($metadata) as $metadataKey) {
        if (strcasecmp(strval($metadataKey), 'tts_filter_preset') === 0) {
            unset($metadata[$metadataKey]);
        }
    }

    $presetId = normalizeTtsFilterPresetId($requestedPreset);
    if ($presetId !== 'none') {
        $metadata['tts_filter_preset'] = $presetId;
    }

    return json_encode((object)$metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function ttsFilterPresetGraph($presetId)
{
    $presetId = normalizeTtsFilterPresetId($presetId, true);
    $preset = ttsFilterPresetCatalog()[$presetId] ?? null;
    $filters = is_array($preset) && is_array($preset['filters'] ?? null) ? $preset['filters'] : [];
    return implode(',', $filters);
}

/**
 * Resolve connector output only when it points inside this server's sound cache.
 */
function resolveTtsFilterAudioPath($ttsOutput)
{
    if (!is_string($ttsOutput) || trim($ttsOutput) === '') {
        return null;
    }

    $serverRoot = dirname(__DIR__, 2);
    $cacheRoot = realpath($serverRoot . DIRECTORY_SEPARATOR . 'soundcache');
    if ($cacheRoot === false) {
        return null;
    }

    $relativeOutput = ltrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, trim($ttsOutput)), DIRECTORY_SEPARATOR);
    $candidates = [trim($ttsOutput), $serverRoot . DIRECTORY_SEPARATOR . $relativeOutput];
    foreach ($candidates as $candidate) {
        if (!is_file($candidate)) {
            continue;
        }

        $resolved = realpath($candidate);
        if ($resolved === false) {
            continue;
        }

        $cachePrefix = rtrim($cacheRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if (strncasecmp($resolved, $cachePrefix, strlen($cachePrefix)) === 0) {
            return $resolved;
        }
    }

    return null;
}

function logTtsFilterPresetMessage($level, $message)
{
    if (class_exists('Logger') && is_callable(['Logger', $level])) {
        Logger::$level($message);
        return;
    }

    error_log($message);
}

/**
 * Apply the active trusted preset to a connector-generated WAV and preserve the original on failure.
 */
function applyActiveTtsFilterPresetToOutput($ttsOutput)
{
    $presetId = getActiveTtsFilterPresetId();
    if ($presetId === 'none' || !$ttsOutput) {
        return $ttsOutput;
    }

    $audioPath = resolveTtsFilterAudioPath($ttsOutput);
    $filterGraph = ttsFilterPresetGraph($presetId);
    if ($audioPath === null || $filterGraph === '') {
        logTtsFilterPresetMessage('error', "[TTS FILTER] Cannot process preset '{$presetId}': connector output is not a readable soundcache WAV.");
        return $ttsOutput;
    }

    try {
        $nonce = bin2hex(random_bytes(6));
    } catch (Throwable $e) {
        $nonce = str_replace('.', '', uniqid('', true));
    }
    $temporaryPath = $audioPath . '.ttsfilter.' . $nonce . '.wav';
    $ffmpegBinary = trim(strval($GLOBALS['FFMPEG_BINARY'] ?? 'ffmpeg'));
    if ($ffmpegBinary === '') {
        $ffmpegBinary = 'ffmpeg';
    }

    $command = escapeshellarg($ffmpegBinary)
        . ' -hide_banner -loglevel error -y -i ' . escapeshellarg($audioPath)
        . ' -af ' . escapeshellarg($filterGraph)
        . ' ' . escapeshellarg($temporaryPath)
        . ' 2>&1';
    $commandOutput = [];
    $exitCode = 1;
    exec($command, $commandOutput, $exitCode);

    if ($exitCode !== 0 || !is_file($temporaryPath) || filesize($temporaryPath) <= 44) {
        @unlink($temporaryPath);
        $details = trim(implode(' ', array_slice($commandOutput, -3)));
        logTtsFilterPresetMessage('error', "[TTS FILTER] FFmpeg failed for preset '{$presetId}' (exit {$exitCode}). {$details}");
        return $ttsOutput;
    }

    if (!@rename($temporaryPath, $audioPath)) {
        @unlink($temporaryPath);
        logTtsFilterPresetMessage('error', "[TTS FILTER] Could not replace the connector output for preset '{$presetId}'.");
        return $ttsOutput;
    }

    logTtsFilterPresetMessage('debug', "[TTS FILTER] Applied preset '{$presetId}' version " . CHIM_TTS_FILTER_PRESET_VERSION . '.');
    return $ttsOutput;
}
