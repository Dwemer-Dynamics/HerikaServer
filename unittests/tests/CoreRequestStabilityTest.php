<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/logger.php';
Logger::setCustomLog(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'chim-core-request-stability-test.log');
$GLOBALS['HERIKA_NAME'] = 'Test NPC';
$GLOBALS['PLAYER_NAME'] = 'Test Player';
require_once __DIR__ . '/../../lib/chat_helper_functions.php';
require_once __DIR__ . '/../../lib/data_functions.php';
require_once __DIR__ . '/../../lib/dynamic_update_util.php';
require_once __DIR__ . '/../../functions/json_response.php';

final class DynamicProfileQueueTestDb
{
    public array $rows = [];
    public bool $lockAvailable = true;

    public function upsertRowOnConflict(string $table, array $data, string $conflictColumn): void
    {
        $this->rows[(string)$data['id']] = (string)$data['value'];
    }

    public function fetchAll(string $query): array
    {
        if (str_contains($query, 'pg_try_advisory_lock')) {
            return [['acquired' => $this->lockAvailable ? 't' : 'f']];
        }
        if (str_contains($query, 'pg_advisory_unlock')) {
            return [['released' => 't']];
        }
        if (str_contains($query, "id LIKE 'dynamic_profiles_queue_%'")) {
            $result = [];
            foreach ($this->rows as $id => $value) {
                if (str_starts_with($id, 'dynamic_profiles_queue_')) {
                    $result[] = ['id' => $id, 'value' => $value];
                }
            }
            return array_slice($result, 0, 5);
        }
        return [];
    }

    public function delete(string $table, string $where): void
    {
        if (preg_match("/id = '([^']+)'/", $where, $match)) {
            unset($this->rows[$match[1]]);
        }
    }

    public function escape($value): string
    {
        return str_replace("'", "''", (string)$value);
    }
}

final class SupersedingUserInputTestDb
{
    public array $queries = [];
    public array $rows = [];

    public function fetchAll(string $query): array
    {
        $this->queries[] = $query;
        return $this->rows;
    }
}

final class CoreRequestStabilityTest extends TestCase
{
    private bool $warningHandlerInstalled = false;

    public function testJevReusesDecisionOnRetryAndFallsBackWithoutPartialState(): void
    {
        require_once __DIR__ . '/../../lib/decision_router.php';
        $GLOBALS['db'] = new class {
            public string $key = 'fixture-key';
            public function escape($value) { return $value; }
            public function fetchOne($query) { return ['api_key' => $this->key]; }
        };
        $saved = [];
        foreach (['FUNCTIONS_ARE_ENABLED', 'EMOTEMOODS', 'use_emotions_expression'] as $name) $saved[$name] = $GLOBALS[$name] ?? null;
        $GLOBALS['FUNCTIONS_ARE_ENABLED'] = false;
        $GLOBALS['EMOTEMOODS'] = 'kindly';
        $GLOBALS['use_emotions_expression'] = false;
        $calls = 0;
        $request = static function () use (&$calls) { $calls++; return ['action' => 'v0', 'mood' => 'v0']; };
        try {
            chimJevPrepare([['role' => 'user', 'content' => 'Hello']], $request);
            $this->assertSame('Talk', $GLOBALS['CHIM_JEV_DECISION']['action']);
            chimJevPrepare([['role' => 'user', 'content' => 'Hello']], $request);
            $this->assertSame(1, $calls);
            unset($GLOBALS['CHIM_JEV_ATTEMPTED'], $GLOBALS['CHIM_JEV_DECISION']);
            chimJevPrepare([], static function () { throw new RuntimeException('http_529'); });
            $this->assertArrayNotHasKey('CHIM_JEV_DECISION', $GLOBALS);
            unset($GLOBALS['CHIM_JEV_ATTEMPTED']);
            $GLOBALS['db']->key = '';
            chimJevPrepare([], $request);
            $this->assertSame(1, $calls);
            $this->assertArrayNotHasKey('CHIM_JEV_DECISION', $GLOBALS);
        } finally {
            unset($GLOBALS['CHIM_JEV_ATTEMPTED'], $GLOBALS['CHIM_JEV_DECISION']);
            foreach ($saved as $name => $value) $GLOBALS[$name] = $value;
        }
    }

    public function testJevRejectsUncertainMissingAndInventedChoices(): void
    {
        require_once __DIR__ . '/../../lib/decision_router.php';
        [$question, $map] = chimJevChoice('Action', ['Talk', 'Follow']);
        $this->assertSame(['v0' => 'Talk', 'v1' => 'Follow'], $map);
        foreach ([[], ['type' => 'choice', 'choice' => 'invented', 'confidence' => 1],
            ['type' => 'choice', 'choice' => 'v1', 'confidence' => 0.49]] as $answer) {
            try {
                chimJevReadChoices(['answers' => ['action' => $answer]], ['action' => $question]);
                $this->fail('Unsafe Jev response was accepted');
            } catch (RuntimeException $error) {
                $this->assertSame('invalid_or_uncertain_decision', $error->getMessage());
            }
        }
        $this->assertSame(['action' => 'v1'], chimJevReadChoices(['answers' => ['action' => [
            'type' => 'choice', 'choice' => 'v1', 'confidence' => 0.9,
        ]]], ['action' => $question]));
    }

    public function testJevExpressionThresholdDoesNotDiscardValidAction(): void
    {
        require_once __DIR__ . '/../../lib/decision_router.php';
        $questions = $answers = [];
        foreach (['action' => ['Talk', 'Follow'], 'mood' => ['neutral', 'happy'],
            'emotion' => ['calm', 'happy'], 'emotion_intensity' => ['low', 'high']] as $field => $options) {
            [$questions[$field]] = chimJevChoice($field, $options);
            $answers[$field] = ['type' => 'choice', 'choice' => 'v1', 'confidence' => 0.5];
        }
        $answers['mood']['confidence'] = 0.47;
        $answers['emotion']['confidence'] = 0.25;
        $answers['emotion_intensity']['confidence'] = 0.24;
        $this->assertSame(['action' => 'v1', 'mood' => 'v1', 'emotion' => 'v1', 'emotion_intensity' => 'v0'],
            chimJevReadChoices(['answers' => $answers], $questions));
        foreach (['mood', 'emotion', 'emotion_intensity'] as $field) $answers[$field]['confidence'] = 0;
        $this->assertSame(['action' => 'v1', 'mood' => 'v0', 'emotion' => 'v0', 'emotion_intensity' => 'v0'],
            chimJevReadChoices(['answers' => $answers], $questions));
        [$parameter] = chimJevChoice('target', ['Player']);
        $this->expectException(RuntimeException::class);
        chimJevReadChoices(['answers' => ['target' => ['type' => 'choice', 'choice' => 'v0', 'confidence' => 0.49]]], ['target' => $parameter]);
    }

    public function testJevOwnsDecisionsButPreservesSpeechAndListener(): void
    {
        require_once __DIR__ . '/../../lib/decision_router.php';
        $speech = ['message' => 'I will follow you.', 'listener' => 'Player', 'action' => 'Attack', 'mood' => 'angry'];
        unset($GLOBALS['CHIM_JEV_DECISION']);
        $this->assertSame($speech, chimJevMergeResponse($speech));
        try {
            $GLOBALS['CHIM_JEV_DECISION'] = ['action' => 'Follow', 'mood' => 'kindly', 'target' => 'Player'];
            $merged = chimJevMergeResponse($speech);
            $this->assertSame('I will follow you.', $merged['message']);
            $this->assertSame('Player', $merged['listener']);
            $this->assertSame('Follow', $merged['action']);
            $this->assertSame('kindly', $merged['mood']);
            $this->assertSame([$merged], chimJevMergeResponse([$speech]));
            $this->assertNull(chimJevMergeResponse(null));
        } finally {
            unset($GLOBALS['CHIM_JEV_DECISION']);
        }
    }

    public function testJevParameterCandidatesStayBoundToObservedInventory(): void
    {
        require_once __DIR__ . '/../../lib/decision_router.php';
        [$options, $counts] = chimJevParameterOptions('GiveItemTo', ['parameters' => ['properties' => [
            'target' => ['type' => 'string'], 'item' => ['type' => 'string'], 'amount' => ['type' => 'integer'],
        ]]], ['inventory' => [
            ['baseid' => '0000000F', 'name' => 'Gold', 'count' => 0],
            ['baseid' => '00065C97', 'name' => 'Bread', 'count' => 2],
        ]], ['Lydia [RefID: 000A2C94]']);
        $this->assertSame(['Lydia [RefID: 000A2C94]'], $options['target']);
        $this->assertCount(1, $options['item']);
        $this->assertStringEndsWith(':Bread', $options['item'][0]);
        $this->assertSame(2, $counts[$options['item'][0]]);
        $this->assertSame([1, 2], $options['amount']);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('freeform_parameter');
        chimJevParameterOptions('CustomStory', ['parameters' => ['properties' => [
            'target' => ['type' => 'string', 'description' => 'Write a story'],
        ]]], [], []);
    }

    public function testDirectorUsesSceneTemplatesAndRestoresConnectorState(): void
    {
        require_once __DIR__ . '/../../lib/director_scene.php';
        $actors = ['Sarah' => [], 'Leona' => []];
        $catalog = ['MoveTo' => ['speakers' => ['Sarah'], 'parameters' => ['properties' => [
            'target' => ['type' => 'string'], 'speed' => ['type' => 'integer', 'minimum' => 1],
        ], 'required' => ['target']]]];
        $connection = new class {
            public array $captured = [];
            public string $response = '';
            public function open($prompt, $options): void {
                $this->captured = ['options' => $options, 'template' => $GLOBALS['responseTemplate'],
                    'schema' => $GLOBALS['structuredOutputTemplate'], 'connector' => $GLOBALS['CONNECTOR'],
                    'functions' => $GLOBALS['FUNCTIONS_ARE_ENABLED'], 'patch' => $GLOBALS['PATCH']];
            }
            public function process(): void {}
            public function isDone(): bool { return true; }
            public function close($name): string { return $this->response; }
        };
        $keys = ['CURRENT_CONNECTOR', 'CONNECTOR', 'PATCH', 'FUNCTIONS_ARE_ENABLED', 'responseTemplate', 'structuredOutputTemplate'];
        $original = array_intersect_key($GLOBALS, array_flip($keys));
        try {
            $GLOBALS['CURRENT_CONNECTOR'] = 'openrouterjson';
            $GLOBALS['PATCH'] = ['PREAPPEND' => '{"character":"Sarah",'];
            $GLOBALS['FUNCTIONS_ARE_ENABLED'] = true;
            $GLOBALS['responseTemplate'] = ['message' => 'Normal dialogue'];
            $GLOBALS['structuredOutputTemplate'] = ['normal' => true];
            foreach ([false, true] as $schemaEnabled) {
                $GLOBALS['CONNECTOR'] = ['openrouterjson' => ['json_schema' => $schemaEnabled, 'PREFILL_JSON' => true]];
                $before = array_intersect_key($GLOBALS, array_flip($keys));
                foreach ([false, true] as $malformed) {
                    $connection->response = $malformed ? '{"lines":[]} trailing junk' : json_encode([
                        'lines' => [['speaker' => 'Sarah', 'listener' => 'Tom', 'text' => 'Hello.']],
                        'actions' => [['speaker' => 'Sarah', 'after_line' => 1, 'command_name' => 'MoveTo',
                            'parameters' => ['target' => 'Leona', 'speed' => null]]],
                    ]);
                    try {
                        $scene = chimRequestDirectorScene($connection, [], $actors, $catalog, 'Tom');
                        $this->assertFalse($malformed);
                        $this->assertSame(['target' => 'Leona'], $scene['actions'][0]['parameters']);
                    } catch (RuntimeException $error) {
                        $this->assertTrue($malformed);
                        $this->assertStringContainsString('Director did not return JSON: Syntax error', $error->getMessage());
                    }
                    $this->assertSame($before, array_intersect_key($GLOBALS, array_flip($keys)));
                    $this->assertSame($schemaEnabled ? 'json_schema' : 'json_object', $connection->captured['options']['response_format']['type']);
                    $this->assertArrayNotHasKey('message', $connection->captured['template']);
                    $this->assertArrayNotHasKey('PREAPPEND', $connection->captured['patch']);
                    $this->assertFalse($connection->captured['functions']);
                    $this->assertFalse($connection->captured['connector']['openrouterjson']['PREFILL_JSON']);
                    $schema = $connection->captured['schema']['json_schema']['schema'];
                    $this->assertSame(['Sarah', 'Leona'], $schema['properties']['lines']['items']['properties']['speaker']['enum']);
                    $this->assertContains('Tom', $schema['properties']['lines']['items']['properties']['listener']['enum']);
                }
            }
        } finally {
            foreach ($keys as $key) {
                if (array_key_exists($key, $original)) $GLOBALS[$key] = $original[$key];
                else unset($GLOBALS[$key]);
            }
        }
    }

    public function testDirectorEndsAtPlayerListenerAndDiscardsLaterActions(): void
    {
        require_once __DIR__ . '/../../lib/director_scene_contract.php';
        $actors = ['Sarah' => [], 'Leona' => []];
        $catalog = ['MoveTo' => ['speakers' => ['Sarah'], 'parameters' => [
            'properties' => ['target' => ['type' => 'string']], 'required' => ['target']]]];
        $opening = ['speaker' => 'Sarah', 'listener' => 'Leona', 'text' => 'Come over here.'];
        $handoff = ['speaker' => 'Sarah', 'listener' => 'Tom', 'text' => 'What do you think?'];
        $action = ['speaker' => 'Sarah', 'after_line' => 2, 'command_name' => 'MoveTo',
            'parameters' => ['target' => 'Tom']];
        $scene = dwemerValidateDirectorScene(['lines' => [$opening, $handoff,
            ['speaker' => 'Tom', 'listener' => 'Sarah', 'text' => 'Invented player response.'], $opening],
            'actions' => [$action, array_replace($action, ['after_line' => 4])]], $actors, $catalog, 'Tom');
        $this->assertSame([$opening, $handoff], $scene['lines']);
        $this->assertSame([$action], $scene['actions']);
        $npcOnly = dwemerValidateDirectorScene(['lines' => [$opening,
            ['speaker' => 'Leona', 'listener' => 'Sarah', 'text' => 'All right.']]], $actors, $catalog, 'Tom');
        $this->assertCount(2, $npcOnly['lines']);
    }

    public function testDirectorRejectsPlayerSpeechEvenIfPlayerIsInActorMap(): void
    {
        require_once __DIR__ . '/../../lib/director_scene_contract.php';
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Director line 1: player or narrator cannot speak');
        dwemerValidateDirectorScene(['lines' => [['speaker' => 'Tom', 'listener' => 'Sarah', 'text' => 'Hello.']]],
            ['Sarah' => [], 'Tom' => []], [], 'Tom');
    }

    protected function tearDown(): void
    {
        if ($this->warningHandlerInstalled) {
            restore_error_handler();
            $this->warningHandlerInstalled = false;
        }
        unset($GLOBALS['db'], $GLOBALS['TTSFUNCTION'], $GLOBALS['TTS']);
    }

    private function failOnWarning(): void
    {
        set_error_handler(static function (int $severity, string $message): never {
            throw new ErrorException($message, 0, $severity);
        });
        $this->warningHandlerInstalled = true;
    }

    public function testEquipmentKeywordMetadataIsNotTreatedAsAnItem(): void
    {
        $equipment = [
            'armor' => 'Dawnguard Heavy Armor',
            'armor_baseid' => '0200F3FA',
            'armor_keywords' => ['ArmorHeavy', 'ArmorCuirass'],
            'boots' => 'Dawnguard Boots',
            'boots_baseid' => '0200F400',
            'boots_keywords' => ['ArmorLight', 'ArmorBoots'],
        ];

        $this->failOnWarning();

        $slots = chimProfileEquipmentSlotsFromData($equipment, ['armor', 'boots']);
        $parts = chimFormatProfileEquipmentParts($equipment, $slots, false);

        $this->assertSame(['armor', 'boots'], $slots);
        $this->assertSame(['Dawnguard Heavy Armor', 'Dawnguard Boots'], $parts);
    }

    public function testEquipmentFormatterDefensivelySkipsStructuredValues(): void
    {
        $this->failOnWarning();

        $parts = chimFormatProfileEquipmentParts(
            ['armor' => ['name' => 'Invalid structured item'], 'boots' => 'Leather Boots'],
            ['armor', 'boots'],
            false
        );

        $this->assertSame(['Leather Boots'], $parts);
    }

    public function testRechatMemorySearchUsesOriginLineInsteadOfControlJson(): void
    {
        $payload = json_encode([
            'speaker' => 'Lydia',
            'listener_hint' => 'Prisoner',
            'origin_line' => 'Lydia: The old barrow may contain the missing claw.',
            'chain_id' => 'chain:with|operators',
        ], JSON_THROW_ON_ERROR);

        $input = chimMemorySearchInputFromRequest(['rechat', '0', '0', $payload]);

        $this->assertSame('Lydia: The old barrow may contain the missing claw.', $input);
        $this->assertStringNotContainsString('chain_id', $input);
    }

    public function testTsQueryTermsStripJsonAndPostgresOperators(): void
    {
        $terms = chimNormalizeTsQueryTerms('{"chain_id":"abc|def", "line":"claw & barrow: old"}');

        $this->assertSame(['chain_id', 'abc', 'def', 'line', 'claw', 'barrow', 'old'], $terms);
        $this->assertSame([], chimNormalizeTsQueryTerms('{} | & :'));
    }

    public function testSupersedingUserInputLookupUsesStrictRequestTimestamp(): void
    {
        $db = new SupersedingUserInputTestDb();
        $db->rows = [['rowid' => '42', 'ts' => '1002', 'data' => 'inputtext']];

        $result = chimFindSupersedingUserInput($db, '1001', 'instruction');

        $this->assertSame(['rowid' => '42', 'ts' => '1002'], $result);
        $this->assertCount(1, $db->queries);
        $this->assertStringContainsString('FROM eventlog ORDER BY rowid DESC LIMIT 100', $db->queries[0]);
        $this->assertStringContainsString("type='user_input' AND ts>1001", $db->queries[0]);
        $this->assertStringNotContainsString("COALESCE(data, '')<>'instruction'", $db->queries[0]);
        $this->assertStringContainsString('ORDER BY rowid DESC LIMIT 1', $db->queries[0]);
    }

    public function testDirectPlayerInputLookupExcludesAutomaticInstruction(): void
    {
        $db = new SupersedingUserInputTestDb();
        $db->rows = [['rowid' => '42', 'ts' => '1002']];

        $this->assertSame(['rowid' => '42', 'ts' => '1002'], chimFindSupersedingUserInput($db, '1001', 'inputtext'));
        $this->assertStringContainsString("COALESCE(data, '')<>'instruction'", $db->queries[0]);
    }

    public function testSupersedingUserInputLookupRejectsInvalidTimestampWithoutQuery(): void
    {
        $db = new SupersedingUserInputTestDb();

        $this->assertNull(chimFindSupersedingUserInput($db, '1001 OR 1=1'));
        $this->assertSame([], $db->queries);
    }

    public function testReturnLinesRunsBoundaryCheckBeforeTts(): void
    {
        $hadForcedStop = array_key_exists('FORCED_STOP', $GLOBALS);
        $savedForcedStop = $GLOBALS['FORCED_STOP'] ?? null;
        $GLOBALS['FORCED_STOP'] = false;
        $boundaryCheckRan = false;

        try {
            returnLines(
                ['Boundary test sentence.'],
                false,
                static function () use (&$boundaryCheckRan): void {
                    $boundaryCheckRan = true;
                    throw new RuntimeException('boundary-check-ran');
                }
            );
            $this->fail('Speech boundary check was not invoked');
        } catch (RuntimeException $e) {
            $this->assertSame('boundary-check-ran', $e->getMessage());
            $this->assertTrue($boundaryCheckRan);
        } finally {
            if ($hadForcedStop) {
                $GLOBALS['FORCED_STOP'] = $savedForcedStop;
            } else {
                unset($GLOBALS['FORCED_STOP']);
            }
        }
    }

    public function testZonosCheckDoesNotWarnWhenTtsIsNotInitialized(): void
    {
        unset($GLOBALS['TTSFUNCTION'], $GLOBALS['TTS']);
        $this->failOnWarning();

        $this->assertFalse(zonosIsActive());
    }

    public function testLegacyDynamicProfileTimerCannotQueueWork(): void
    {
        $db = new DynamicProfileQueueTestDb();
        $GLOBALS['db'] = $db;
        $result = queueDynamicProfileBatch(['Lydia'], ['updateprofiles_batch_async', '1', '2']);
        $this->assertSame('server-managed', $result);
        $this->assertSame([], $db->rows);
    }

    public function testDynamicProfileRequiresAllThresholdsAndAppliesRetryCooldown(): void
    {
        require_once __DIR__ . '/../../lib/dynamic_profile_scheduler.php';
        $policy = dps_policy([]);
        $state = ['attempt'=>1000, 'last_game'=>10000000, 'total'=>30, 'consumed'=>0];
        $this->assertFalse(dps_due($state, $policy, 20000000, 1299));
        $this->assertTrue(dps_due($state, $policy, 20000000, 1300));
        $this->assertFalse(dps_due($state, $policy, 19999999, 1300));
        $state['total'] = 29;
        $this->assertFalse(dps_due($state, $policy, 20000000, 1300));
    }
}
