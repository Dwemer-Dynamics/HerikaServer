<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

$GLOBALS['ENGINE_PATH'] = __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR;
require_once $GLOBALS['ENGINE_PATH'] . 'lib' . DIRECTORY_SEPARATOR . 'relationship_manager.php';
require_once $GLOBALS['ENGINE_PATH'] . 'lib' . DIRECTORY_SEPARATOR . 'eventlog_helper.php';
require_once $GLOBALS['ENGINE_PATH'] . 'lib' . DIRECTORY_SEPARATOR . 'core'
    . DIRECTORY_SEPARATOR . 'npc_master.class.php';
require_once $GLOBALS['ENGINE_PATH'] . 'ext' . DIRECTORY_SEPARATOR . 'relationship_system'
    . DIRECTORY_SEPARATOR . 'relationship_llm.php';

final class RelationshipTypeValidationTest extends TestCase
{
    public function testBuiltInTypesAndKnownAliasesAreCanonicalized(): void
    {
        $this->assertSame('romantic', RelationshipManager::canonicalizeRelationshipType('Romantic'));
        $this->assertSame('romantic', RelationshipManager::canonicalizeRelationshipType('Romance'));
        $this->assertSame('betrayed', RelationshipManager::canonicalizeRelationshipType('Betrayal'));
        $this->assertSame('enemy', RelationshipManager::canonicalizeRelationshipType('Enemies'));
    }

    public function testModelCannotCreateANewRelationshipType(): void
    {
        $this->assertNull(RelationshipManager::canonicalizeRelationshipType('soulmate'));
        $this->assertNull(RelationshipManager::canonicalizeRelationshipType('new made up type'));
        $this->assertNull(RelationshipManager::canonicalizeRelationshipType(42));
    }

    public function testExistingPlayerCustomTypeCanBeSelectedButNotRecreatedAfterRemoval(): void
    {
        $this->assertSame(
            'trusted',
            RelationshipManager::canonicalizeRelationshipType('Trusted', ['trusted'])
        );
        $this->assertNull(RelationshipManager::canonicalizeRelationshipType('trusted'));
    }

    public function testCustomTypeExtractionExcludesBuiltInsAliasesAndMalformedValues(): void
    {
        $relationships = [
            ['type' => 'trusted'],
            ['type' => 'Romance'],
            ['type' => 'enemy'],
            ['type' => 'bad type'],
        ];

        $this->assertSame(['trusted'], RelationshipManager::getCustomRelationshipTypes($relationships));
    }

    public function testRelationshipMapRepairsLegacyAliasesWithoutDestroyingCustomTypes(): void
    {
        $normalized = RelationshipManager::normalizeRelationshipMap([
            'Player' => ['aff' => 60, 'type' => 'Romance'],
            'Lydia' => ['aff' => 20, 'type' => 'Trusted'],
        ]);

        $this->assertSame('romantic', $normalized['Player']['type']);
        $this->assertSame('trusted', $normalized['Lydia']['type']);
    }

    public function testAutomaticEvaluationChanceHonorsBoundariesAndDeterministicRolls(): void
    {
        $this->assertFalse(RelationshipManager::shouldRunAutomaticEvaluation(0, 1));
        $this->assertTrue(RelationshipManager::shouldRunAutomaticEvaluation(100, 100));
        $this->assertTrue(RelationshipManager::shouldRunAutomaticEvaluation(25, 25));
        $this->assertFalse(RelationshipManager::shouldRunAutomaticEvaluation(25, 26));
        $this->assertTrue(RelationshipManager::shouldRunAutomaticEvaluation('invalid', 50));
        $this->assertFalse(RelationshipManager::shouldRunAutomaticEvaluation('invalid', 51));
    }

    public function testLegacyRelationshipTextIsNotSentForInitialization(): void
    {
        $hadDatabase = array_key_exists('db', $GLOBALS);
        $previousDatabase = $hadDatabase ? $GLOBALS['db'] : null;
        $GLOBALS['db'] = new class {
            public function fetchOne(string $query): array
            {
                return [
                    'id' => 7,
                    'npc_name' => 'Lydia',
                    'relationships' => str_repeat('legacy relationship text ', 200),
                    'extended_data' => '{}',
                ];
            }
        };

        try {
            $llm = (new ReflectionClass(RelationshipLLM::class))->newInstanceWithoutConstructor();
            $result = $llm->analyzeNpc(7, true);
        } finally {
            if ($hadDatabase) {
                $GLOBALS['db'] = $previousDatabase;
            } else {
                unset($GLOBALS['db']);
            }
        }

        $this->assertTrue($result['ok']);
        $this->assertTrue($result['skipped']);
        $this->assertSame('No structured relationships yet', $result['reason']);
    }

    public function testInitialLlmAnalysisCannotPersistAnInventedType(): void
    {
        $llm = (new ReflectionClass(RelationshipLLM::class))->newInstanceWithoutConstructor();
        $parse = new ReflectionMethod(RelationshipLLM::class, 'parseResponse');
        $parsed = $parse->invoke($llm, json_encode([
            'relationships' => [
                'Player' => ['aff' => 60, 'type' => 'Soulmate'],
                'Lydia' => ['aff' => 60, 'type' => 'Romance'],
            ],
        ]));

        $this->assertSame('neutral', $parsed['Player']['type']);
        $this->assertSame('romantic', $parsed['Lydia']['type']);
    }

    public function testConcurrentRebaseRevalidatesTheRequestedType(): void
    {
        $llm = (new ReflectionClass(RelationshipLLM::class))->newInstanceWithoutConstructor();
        $rebase = new ReflectionMethod(RelationshipLLM::class, 'rebaseRelationshipChange');
        $rebased = $rebase->invoke(
            $llm,
            ['aff' => 20, 'type' => 'platonic'],
            ['aff' => 25, 'type' => 'soulmate'],
            ['delta' => 5, 'requested_type' => 'soulmate'],
            []
        );

        $this->assertSame(25, $rebased['aff']);
        $this->assertSame('platonic', $rebased['type']);
    }

    public function testRelationshipChangeEventsDescribeEffectiveSavedChanges(): void
    {
        $GLOBALS['PLAYER_NAME'] = 'RANGROO';
        $events = RelationshipManager::buildRelationshipChangeSummaries(
            'Lydia',
            ['Player' => ['aff' => 28, 'type' => 'neutral', 'note' => 'Older note']],
            ['Player' => ['aff' => 33, 'type' => 'platonic', 'note' => 'Appreciated the help']]
        );

        $this->assertCount(1, $events);
        $this->assertSame('up', $events[0]['direction']);
        $this->assertSame('|Lydia|RANGROO|', $events[0]['people']);
        $this->assertSame(
            "Lydia's affinity toward RANGROO increased by 5 (28 to 33, now Friendly) and the relationship changed from neutral to platonic. Appreciated the help.",
            $events[0]['data']
        );
        unset($GLOBALS['PLAYER_NAME']);
    }

    public function testRelationshipChangeEventsSkipNoOpsAndDoNotReuseOldReasons(): void
    {
        $this->assertSame([], RelationshipManager::buildRelationshipChangeSummaries(
            'Lydia',
            ['Player' => ['aff' => 100, 'type' => 'platonic', 'note' => 'Saved my life']],
            ['Player' => ['aff' => 100, 'type' => 'platonic', 'note' => 'Saved my life']]
        ));

        $events = RelationshipManager::buildRelationshipChangeSummaries(
            'Lydia',
            ['Player' => ['aff' => 20, 'type' => 'neutral', 'note' => 'Old reason']],
            ['Player' => ['aff' => 17, 'type' => 'neutral', 'note' => 'Old reason']]
        );
        $this->assertSame(
            "Lydia's affinity toward Player decreased by 3 (20 to 17).",
            $events[0]['data']
        );
    }

    public function testRelationshipHistorySnapshotBecomesAReadOnlyTimelineRow(): void
    {
        $rows = chimBuildRelationshipHistoryTimelineRows([[
            'history_id' => 42,
            'npc_name' => 'Lydia',
            'gamets_last_updated' => 12345,
            'localts' => 1770000000,
            'previous_extended_data' => json_encode([
                'relationships' => ['Player' => ['aff' => 10, 'type' => 'neutral']],
            ]),
            'extended_data' => json_encode([
                'relationships' => ['Player' => ['aff' => 15, 'type' => 'platonic']],
            ]),
        ]]);

        $this->assertCount(1, $rows);
        $this->assertSame('relationship', $rows[0]['type']);
        $this->assertSame('relationship:42', $rows[0]['rowid']);
        $this->assertSame('relationship_history', $rows[0]['source']);
        $this->assertStringContainsString('increased by 5', $rows[0]['data']);
    }

    public function testRelationshipRestoreClearsOnlyRowsWithoutEligibleHistory(): void
    {
        $query = chimRelationshipFutureClearQuery(100);

        $this->assertStringContainsString('UPDATE public.core_npc_master AS c', $query);
        $this->assertStringContainsString('AND NOT EXISTS', $query);
        $this->assertStringContainsString('h.npc_id = c.id', $query);
        $this->assertStringContainsString('h.gamets_last_updated <= 100', $query);
        $this->assertStringContainsString("h.extended_data ? 'relationships'", $query);
    }

    public function testNeverClearSettingPreservesLiveRelationshipsAndSkipsTimelineRollback(): void
    {
        $hadDatabase = array_key_exists('db', $GLOBALS);
        $previousDatabase = $hadDatabase ? $GLOBALS['db'] : null;
        $hadSetting = array_key_exists('NEVER_CLEAR_RELATIONSHIP_DATA', $GLOBALS);
        $previousSetting = $hadSetting ? $GLOBALS['NEVER_CLEAR_RELATIONSHIP_DATA'] : null;
        $GLOBALS['NEVER_CLEAR_RELATIONSHIP_DATA'] = true;
        $GLOBALS['db'] = new class {
            public array $queries = [];
            public array $fetchQueries = [];

            public function query($query)
            {
                $this->queries[] = $query;
                return true;
            }

            public function fetchOne($query)
            {
                $this->fetchQueries[] = $query;
                return ['affected' => 0, 'sample_names' => ''];
            }

            public function execQuery($query)
            {
                return true;
            }
        };

        try {
            $npcMaster = new NpcMaster();
            $this->assertTrue($npcMaster->restoreNPC(100));
            $restoreQuery = $GLOBALS['db']->queries[0] ?? '';

            $this->assertStringContainsString('WHEN TRUE THEN', $restoreQuery);
            $this->assertStringContainsString("d.current_extended_data -> 'relationships'", $restoreQuery);
            $this->assertStringContainsString('NOT TRUE', $restoreQuery);
            $this->assertSame([], $GLOBALS['db']->fetchQueries);
        } finally {
            if ($hadDatabase) {
                $GLOBALS['db'] = $previousDatabase;
            } else {
                unset($GLOBALS['db']);
            }
            if ($hadSetting) {
                $GLOBALS['NEVER_CLEAR_RELATIONSHIP_DATA'] = $previousSetting;
            } else {
                unset($GLOBALS['NEVER_CLEAR_RELATIONSHIP_DATA']);
            }
        }
    }

    public function testDefaultRestoreStillRunsTimelineRollbackAndFutureOnlyCleanup(): void
    {
        $hadDatabase = array_key_exists('db', $GLOBALS);
        $previousDatabase = $hadDatabase ? $GLOBALS['db'] : null;
        $hadSetting = array_key_exists('NEVER_CLEAR_RELATIONSHIP_DATA', $GLOBALS);
        $previousSetting = $hadSetting ? $GLOBALS['NEVER_CLEAR_RELATIONSHIP_DATA'] : null;
        $GLOBALS['NEVER_CLEAR_RELATIONSHIP_DATA'] = false;
        $GLOBALS['db'] = new class {
            public array $queries = [];
            public array $fetchQueries = [];

            public function query($query)
            {
                $this->queries[] = $query;
                return true;
            }

            public function fetchOne($query)
            {
                $this->fetchQueries[] = $query;
                return ['affected' => 0, 'sample_names' => ''];
            }

            public function execQuery($query)
            {
                return true;
            }
        };

        try {
            $npcMaster = new NpcMaster();
            $this->assertTrue($npcMaster->restoreNPC(100));
            $restoreQuery = $GLOBALS['db']->queries[0] ?? '';

            $this->assertStringContainsString('WHEN FALSE THEN', $restoreQuery);
            $this->assertCount(2, $GLOBALS['db']->fetchQueries);
            $this->assertStringContainsString('WITH restore AS', $GLOBALS['db']->fetchQueries[0]);
            $this->assertStringContainsString('WITH cleared AS', $GLOBALS['db']->fetchQueries[1]);
            $this->assertStringContainsString('AND NOT EXISTS', $GLOBALS['db']->fetchQueries[1]);
        } finally {
            if ($hadDatabase) {
                $GLOBALS['db'] = $previousDatabase;
            } else {
                unset($GLOBALS['db']);
            }
            if ($hadSetting) {
                $GLOBALS['NEVER_CLEAR_RELATIONSHIP_DATA'] = $previousSetting;
            } else {
                unset($GLOBALS['NEVER_CLEAR_RELATIONSHIP_DATA']);
            }
        }
    }

    public function testTimelineRowIdentifiersPreserveRelationshipHistoryIds(): void
    {
        $this->assertSame(
            ['source' => 'relationship_history', 'id' => 50, 'rowid' => 'relationship:50'],
            chimParseTimelineRowIdentifier('relationship:00050')
        );
        $this->assertSame(
            ['source' => 'eventlog', 'id' => 21220, 'rowid' => '21220'],
            chimParseTimelineRowIdentifier('21220')
        );
        $this->assertNull(chimParseTimelineRowIdentifier('relationship:nope'));
        $this->assertNull(chimParseTimelineRowIdentifier('0'));
    }

    public function testRelationshipUndoRestoresOnlyRelationshipStateAndPreservesCustomInfo(): void
    {
        $current = [
            'relationships' => [
                'Player' => ['aff' => 15, 'type' => 'hostile', 'custom_info' => 'Player note'],
                'Lydia' => ['aff' => 5, 'type' => 'neutral', 'custom_info' => 'Lydia note'],
            ],
            'relationships_analyzed' => true,
            'relationships_model' => 'current-model',
            'unrelated' => ['keep' => true],
        ];
        $previous = [
            'relationships' => [
                'Player' => ['aff' => 2, 'type' => 'neutral'],
            ],
            'relationships_analyzed' => false,
            'relationships_model' => 'old-model',
            'unrelated' => ['keep' => false],
        ];

        $restored = chimBuildRelationshipUndoExtendedData(
            json_encode($current),
            json_encode($previous)
        );

        $this->assertSame(2, $restored['relationships']['Player']['aff']);
        $this->assertSame('neutral', $restored['relationships']['Player']['type']);
        $this->assertSame('Player note', $restored['relationships']['Player']['custom_info']);
        $this->assertSame(0, $restored['relationships']['Lydia']['aff']);
        $this->assertSame('neutral', $restored['relationships']['Lydia']['type']);
        $this->assertSame('Lydia note', $restored['relationships']['Lydia']['custom_info']);
        $this->assertTrue($restored['relationships_analyzed']);
        $this->assertSame('current-model', $restored['relationships_model']);
        $this->assertSame(['keep' => true], $restored['unrelated']);
    }

    public function testRelationshipUndoCanRemoveTheFirstRelationshipChange(): void
    {
        $restored = chimBuildRelationshipUndoExtendedData(
            json_encode([
                'relationships' => [
                    'Player' => ['aff' => -10, 'type' => 'enemy', 'custom_info' => 'Keep me'],
                ],
                'unrelated' => 'preserved',
            ]),
            null
        );

        $this->assertSame([
            'Player' => ['aff' => 0, 'type' => 'neutral', 'custom_info' => 'Keep me'],
        ], $restored['relationships']);
        $this->assertSame('preserved', $restored['unrelated']);
    }

    public function testRelationshipUndoComparisonIgnoresCustomInfoOnly(): void
    {
        $left = [
            'relationships' => [
                'Player' => ['aff' => 7, 'type' => 'platonic', 'custom_info' => 'First note'],
            ],
            'unrelated' => 'left',
        ];
        $right = [
            'relationships' => [
                'Player' => ['aff' => 7, 'type' => 'platonic', 'custom_info' => 'Second note'],
            ],
            'unrelated' => 'right',
        ];

        $this->assertSame(
            chimRelationshipUndoComparableState(json_encode($left)),
            chimRelationshipUndoComparableState(json_encode($right))
        );
        $right['relationships']['Player']['aff'] = 8;
        $this->assertNotSame(
            chimRelationshipUndoComparableState(json_encode($left)),
            chimRelationshipUndoComparableState(json_encode($right))
        );
    }
}
