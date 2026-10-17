<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/request_performance.php';
require_once __DIR__ . '/../../lib/provider_stream.php';

final class RequestPerformanceTest extends TestCase
{
    public function testProviderTimingIgnoresHeartbeatsAndSeparatesReasoningFromContent(): void
    {
        $now = 10.0;
        chimRequestPerformanceInitialize(static function () use (&$now): float { return $now; });
        $handler = new class { use ChimProviderStream; };
        $start = new ReflectionMethod($handler, 'recoveryStart');
        $observe = new ReflectionMethod($handler, 'recoveryObserve');
        $start->invoke($handler, 30);
        $now = 10.125;
        $observe->invoke($handler, ': heartbeat');
        $observe->invoke($handler, 'data: {"choices":[{"delta":{"role":"assistant"}}]}');
        $this->assertNull($handler->providerDiagnostics()['ttft_ms']);
        $observe->invoke($handler, 'data: {"provider":"Example Provider","choices":[{"delta":{"reasoning":"private text"}}]}');
        $now = 10.500;
        $observe->invoke($handler, 'data: {"choices":[{"delta":{"content":"0"}}]}');
        $now = 11.0;
        $observe->invoke($handler, 'data: {"choices":[{"delta":{"content":"more"}}]}');
        $this->assertSame(['ttft_ms' => 125.0, 'first_content_ms' => 500.0,
            'upstream_provider' => 'Example Provider', 'response_mode' => 'streaming'], $handler->providerDiagnostics());
        $start->invoke($handler, 30);
        $now = 11.250;
        $observe->invoke($handler, '{"choices":[{"message":{"content":"buffered"}}]}', true);
        $this->assertNull($handler->providerDiagnostics()['ttft_ms']);
        $this->assertNull($handler->providerDiagnostics()['upstream_provider']);
        $this->assertSame(250.0, $handler->providerDiagnostics()['first_content_ms']);
    }

    public function testProviderPayloadRecordsFailureFallbackAndInterruptedAttemptsWithoutSecrets(): void
    {
        $now = 20.0;
        chimRequestPerformanceInitialize(static function () use (&$now): float { return $now; });
        $connector = ['id' => 7, 'driver' => 'openaijson', 'model' => 'example/model',
            'API_KEY' => 'secret-sentinel', 'url' => 'secret-endpoint'];
        $first = chimProviderDiagnosticBegin($connector, 'primary', null);
        $now = 20.500;
        chimProviderDiagnosticEnd($first, 'failed', 'http_429');
        $second = chimProviderDiagnosticBegin($connector, 'fallback', 'http_429');
        $GLOBALS['CHIM_REQUEST_PERFORMANCE']['providers'][$second]['_health'] = new class {
            public function diagnostics(): array { return ['state' => 'cooldown', 'retry_in_s' => 30]; }
        };
        $now = 21.0;
        $payload = chimRequestPerformanceFinish('complete', false);
        $this->assertSame(500.0, $payload['providers'][0]['elapsed_ms']);
        $this->assertSame('http_429', $payload['providers'][1]['selection_reason']);
        $this->assertSame('interrupted', $payload['providers'][1]['status']);
        $this->assertSame(30, $payload['providers'][1]['health']['retry_in_s']);
        $this->assertNull($payload['providers'][1]['ttft_ms']);
        $this->assertStringNotContainsString('secret-', json_encode($payload));
        $this->assertArrayNotHasKey('_health', $payload['providers'][1]);
        $this->assertNull(chimProviderDiagnosticBegin($connector, 'primary', null));
    }

    protected function tearDown(): void
    {
        unset(
            $GLOBALS['CHIM_REQUEST_PERFORMANCE'],
            $GLOBALS['CHIM_REQUEST_PERFORMANCE_CLOCK'],
            $GLOBALS['DB_EXECUTION_TIME'],
            $GLOBALS['runid'],
            $GLOBALS['ERROR_TRIGGERED']
        );
    }

    public function testRecordsPhaseDeltasAndTotalTime(): void
    {
        $times = [10.0, 10.125, 10.400, 10.500];
        chimRequestPerformanceInitialize(static function () use (&$times): float {
            return array_shift($times);
        });
        chimRequestPerformanceSetRequestType('InputText');
        chimRequestPerformanceMark('lock_acquired');
        chimRequestPerformanceMark('context_ready');

        $GLOBALS['DB_EXECUTION_TIME'] = 0.032;
        $GLOBALS['runid'] = 'run_test';
        $payload = chimRequestPerformanceFinish('complete', false);

        $this->assertSame('run_test', $payload['run_id']);
        $this->assertSame('inputtext', $payload['request_type']);
        $this->assertSame(500.0, $payload['total_ms']);
        $this->assertSame(32.0, $payload['sql_ms']);
        $this->assertSame('lock_acquired', $payload['phases'][0]['name']);
        $this->assertSame(125.0, $payload['phases'][0]['delta_ms']);
        $this->assertSame(400.0, $payload['phases'][1]['total_ms']);
    }

    public function testFinishIsIdempotent(): void
    {
        $times = [2.0, 2.25, 9.0];
        chimRequestPerformanceInitialize(static function () use (&$times): float {
            return array_shift($times);
        });

        $first = chimRequestPerformanceFinish('complete', false);
        $second = chimRequestPerformanceFinish('failed', false);

        $this->assertSame($first, $second);
        $this->assertSame('complete', $second['status']);
    }

    public function testTerminalStatusRecognizesFatalErrorsButNotWarnings(): void
    {
        $this->assertSame('error', chimRequestPerformanceTerminalStatus(['type' => E_ERROR]));
        $this->assertSame('error', chimRequestPerformanceTerminalStatus(['type' => E_PARSE]));
        $this->assertSame('complete', chimRequestPerformanceTerminalStatus(['type' => E_WARNING]));
        $this->assertSame('complete', chimRequestPerformanceTerminalStatus(null));

        $GLOBALS['ERROR_TRIGGERED'] = true;
        $this->assertSame('error', chimRequestPerformanceTerminalStatus(null));
    }
}
