<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/core/tts_studio_provider_detection.php';

final class TtsStudioProviderDetectionTest extends TestCase
{
    private function probe(int $status, $decoded = []): array
    {
        return [
            'response' => $status > 0 ? json_encode($decoded) : false,
            'decoded' => $decoded,
            'http_code' => $status,
            'curl_error' => $status > 0 ? '' : 'Connection refused',
        ];
    }

    public function testDetectsAudioCppFromCapabilitiesWithoutUsingThePort(): void
    {
        $runtime = chimTtsStudioClassifyPocketTtsRuntime(
            'http://127.0.0.1:9000',
            [],
            $this->probe(200, ['status' => 'ok']),
            $this->probe(200, ['data' => [['id' => 'pocket-tts', 'family' => 'pocket_tts']]]),
            $this->probe(404)
        );

        $this->assertTrue($runtime['reachable']);
        $this->assertSame('audio_cpp', $runtime['mode']);
    }

    public function testDetectsStandardApiFromSpeakersEndpoint(): void
    {
        $runtime = chimTtsStudioClassifyPocketTtsRuntime(
            'http://127.0.0.1:8024',
            ['api_format' => 'audio_cpp'],
            $this->probe(404),
            $this->probe(404),
            $this->probe(200, ['voices' => ['sample']])
        );

        $this->assertTrue($runtime['reachable']);
        $this->assertSame('standard', $runtime['mode']);
    }

    public function testRejectsOtherEnginesRegisteredAsPocketTts(): void
    {
        $higgs = chimTtsStudioClassifyPocketTtsRuntime('http://localhost:8025', [],
            $this->probe(200), $this->probe(200, ['data' => [['id' => 'higgs-v3', 'family' => 'higgs_audio_tts']]]), $this->probe(404));
        $this->assertFalse($higgs['reachable']);
        foreach (['xtts-fastapi', 'chatterbox'] as $provider) {
            $runtime = chimTtsStudioClassifyPocketTtsRuntime('http://localhost:8020', [],
                $this->probe(404), $this->probe(404), $this->probe(200, ['sample']), $provider);
            $this->assertFalse($runtime['reachable']);
        }
    }

    public function testUnassignedSelectionPrefersDefaultEndpointButPreservesAssignments(): void
    {
        $rows = [['id' => 50, 'url' => 'http://localhost:8025/v1/audio/speech'],
            ['id' => 39, 'url' => 'http://localhost:8020'], ['id' => 41, 'url' => 'http://localhost:8086/v1/audio/speech/']];
        $url = fn(array $row): string => $row['url'];
        $default = 'http://localhost:8086';
        $this->assertSame(41, chimTtsStudioSelectPocketTtsConnector($rows, 48, [], $default, $url)['id']);
        $this->assertSame(50, chimTtsStudioSelectPocketTtsConnector($rows, 50, [], $default, $url)['id']);
        $this->assertSame(50, chimTtsStudioSelectPocketTtsConnector($rows, 48, [50 => 1], $default, $url)['id']);
        $this->assertSame(50, chimTtsStudioSelectPocketTtsConnector($rows, 48, [], 'http://remote:9000', $url)['id']);
    }

    public function testFallsBackToConfiguredModeWhenServiceIsOffline(): void
    {
        $runtime = chimTtsStudioClassifyPocketTtsRuntime(
            'http://127.0.0.1:8086',
            [],
            $this->probe(0),
            $this->probe(0),
            $this->probe(0)
        );

        $this->assertFalse($runtime['reachable']);
        $this->assertSame('audio_cpp', $runtime['mode']);
    }

    public function testNormalizesDedicatedServiceIdentities(): void
    {
        $this->assertSame('chatterbox', chimTtsStudioNormalizeProviderIdentity('Chatterbox'));
        $this->assertSame('pockettts', chimTtsStudioNormalizeProviderIdentity('pocket_tts'));
        $this->assertSame('xtts-fastapi', chimTtsStudioNormalizeProviderIdentity('xtts'));
        $this->assertSame('', chimTtsStudioNormalizeProviderIdentity('unknown'));
    }

    public function testIdentifiesReleasedServicesFromOpenApiFingerprints(): void
    {
        $this->assertSame('chatterbox', chimTtsStudioProviderFromOpenApi([
            'info' => ['title' => 'Chatterbox TTS API'],
            'paths' => ['/speakers_list' => [], '/sample/{file_name}' => []],
        ]));
        $this->assertSame('pockettts', chimTtsStudioProviderFromOpenApi([
            'info' => ['title' => 'FastAPI'],
            'paths' => ['/speakers_list' => [], '/tts_to_audio_form' => []],
        ]));
        $this->assertSame('xtts-fastapi', chimTtsStudioProviderFromOpenApi([
            'info' => ['title' => 'FastAPI'],
            'paths' => [
                '/speakers_list' => [],
                '/speakers' => [],
                '/sample/{file_name}' => [],
                '/set_tts_settings' => [],
                '/languages' => [],
            ],
        ]));
    }
}
