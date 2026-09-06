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
        $db->rows = [['rowid' => '42', 'ts' => '1002']];

        $result = chimFindSupersedingUserInput($db, '1001');

        $this->assertSame(['rowid' => '42', 'ts' => '1002'], $result);
        $this->assertCount(1, $db->queries);
        $this->assertStringContainsString('FROM eventlog ORDER BY rowid DESC LIMIT 100', $db->queries[0]);
        $this->assertStringContainsString("type='user_input' AND ts>1001", $db->queries[0]);
        $this->assertStringContainsString('ORDER BY rowid DESC LIMIT 1', $db->queries[0]);
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

    public function testDynamicProfileBatchIsQueuedAndConsumedOnce(): void
    {
        $db = new DynamicProfileQueueTestDb();
        $GLOBALS['db'] = $db;

        $queueId = queueDynamicProfileBatch([' Lydia ', 'Lydia', 'Aela'], ['updateprofiles_batch_async', '1', '2']);
        $processed = [];
        $result = triggerImmediateProfileProcessing(static function (string $npcName, array $gameRequest) use (&$processed): bool {
            $processed[] = [$npcName, $gameRequest[0]];
            return true;
        });

        $this->assertArrayNotHasKey($queueId, $db->rows);
        $this->assertSame([['Lydia', 'updateprofiles_batch_async'], ['Aela', 'updateprofiles_batch_async']], $processed);
        $this->assertSame(['locked' => true, 'jobs' => 1, 'npcs' => 2, 'updated' => 2], $result);
    }

    public function testDynamicProfileQueueDoesNothingWhenAnotherWorkerOwnsLock(): void
    {
        $db = new DynamicProfileQueueTestDb();
        $GLOBALS['db'] = $db;
        $queueId = queueDynamicProfileBatch(['Lydia'], ['updateprofiles_batch_async', '1', '2']);
        $db->lockAvailable = false;

        $result = triggerImmediateProfileProcessing(static fn(): bool => true);

        $this->assertArrayHasKey($queueId, $db->rows);
        $this->assertSame(['locked' => false, 'jobs' => 0, 'npcs' => 0, 'updated' => 0], $result);
    }
}
