<?php

require_once(__DIR__ . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'narrator.class.php');
require_once(__DIR__ . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'tts_connector.class.php');

// Build the saved connector and installed voice choices used by pronunciation previews.
function chimTtsPronunciationPreviewOptions(string $enginePath): array
{
    $enginePath = rtrim($enginePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    $ttsConnector = new TTSConnector();
    $connectors = [];
    foreach ($ttsConnector->readAll() as $row) {
        $id = intval($row['id'] ?? 0);
        $driver = $ttsConnector->normalizeDriverValue($row['driver'] ?? '');
        if ($id <= 0 || $driver === '' || $driver === 'none') {
            continue;
        }
        $connectors[] = [
            'id' => $id,
            'label' => trim(strval($row['label'] ?? '')),
            'driver' => $driver,
        ];
    }

    $voices = [];
    $voiceFiles = glob($enginePath . 'data' . DIRECTORY_SEPARATOR . 'voices' . DIRECTORY_SEPARATOR . '*.wav');
    if (is_array($voiceFiles)) {
        foreach ($voiceFiles as $voiceFile) {
            $voice = trim(pathinfo($voiceFile, PATHINFO_FILENAME));
            if ($voice !== '') {
                $voices[strtolower($voice)] = $voice;
            }
        }
    }
    natcasesort($voices);
    $voices = array_values($voices);

    $narrator = new Narrator();
    $configuredVoice = trim(strval($narrator->get('voiceid') ?? Narrator::CANONICAL_NAME));
    $defaultVoice = '';
    foreach ($voices as $voice) {
        if (strcasecmp($voice, $configuredVoice) === 0) {
            $defaultVoice = $voice;
            break;
        }
    }
    if ($defaultVoice === '') {
        foreach ($voices as $voice) {
            if (strcasecmp($voice, Narrator::CANONICAL_NAME) === 0) {
                $defaultVoice = $voice;
                break;
            }
        }
    }
    if ($defaultVoice === '' && !empty($voices)) {
        $defaultVoice = strval($voices[0]);
    }

    $defaultConnectorId = 0;
    $profileId = $narrator->getProfileId();
    if ($profileId !== null && $profileId > 0) {
        $profile = $GLOBALS['db']->fetchOne(
            'SELECT tts_connector_id FROM public.core_profiles WHERE id = ' . intval($profileId) . ' LIMIT 1'
        );
        $defaultConnectorId = intval($profile['tts_connector_id'] ?? 0);
    }
    if ($defaultConnectorId <= 0) {
        $profile = $GLOBALS['db']->fetchOne(
            "SELECT tts_connector_id
             FROM public.core_profiles
             WHERE LOWER(COALESCE(default_narrator, '')) IN ('1', 't', 'true', 'yes', 'on')
             ORDER BY id
             LIMIT 1"
        );
        $defaultConnectorId = intval($profile['tts_connector_id'] ?? 0);
    }

    $availableConnectorIds = array_map(static fn(array $row): int => intval($row['id']), $connectors);
    if (!in_array($defaultConnectorId, $availableConnectorIds, true)) {
        $defaultConnectorId = intval($connectors[0]['id'] ?? 0);
    }

    return [
        'connectors' => $connectors,
        'voices' => $voices,
        'default_connector_id' => $defaultConnectorId,
        'default_voice' => $defaultVoice,
    ];
}

