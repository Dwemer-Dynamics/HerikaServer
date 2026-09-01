<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/data_functions.php';
require_once __DIR__ . '/../../lib/chat_helper_functions.php';

final class DiaryMemoryRecallTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['db'] = new class {
            public array $queries = [];

            /** @var array<string, array|null> */
            public array $narratorSettings = [];

            public ?array $latestDiaryEntry = null;

            public function escape($value): string
            {
                return str_replace("'", "''", (string)$value);
            }

            public function query(string $query): bool
            {
                $this->queries[] = $query;
                return true;
            }

            public function fetchOne(string $query)
            {
                $this->queries[] = $query;

                if (strpos($query, 'core_narrator') !== false) {
                    foreach ($this->narratorSettings as $key => $row) {
                        if (strpos($query, "id = '{$key}'") !== false) {
                            return $row;
                        }
                    }

                    return null;
                }

                return $this->latestDiaryEntry;
            }
        };
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['db'], $GLOBALS['NARRATOR_ONLY_DIARY_ACCESS']);
    }

    public function testNarratorSearchesTheGlobalMemoryBankByDefault(): void
    {
        $this->assertSame('TRUE', dataGetMemoryCompanionConditionSql(''));
    }

    public function testForcedDiaryRecallRestrictsSearchToDiaryMemories(): void
    {
        $this->assertSame(
            "memory_summary.classifier IN ('diary','auto_diary','backgroundlife_diary')",
            dataGetMemoryClassifierConditionSql(true, 'memory_summary.classifier')
        );
        $this->assertSame('TRUE', dataGetMemoryClassifierConditionSql(false));
    }

    public function testNarratorCanBeRestrictedToItsOwnDiary(): void
    {
        $GLOBALS['NARRATOR_ONLY_DIARY_ACCESS'] = true;

        $this->assertSame(
            "(COALESCE(memory_summary.classifier, '') NOT IN ('diary','auto_diary','backgroundlife_diary')"
                . " OR memory_summary.companions LIKE '%|The Narrator|%'"
                . " OR memory_summary.companions='The Narrator')",
            dataGetMemoryCompanionConditionSql(
                '',
                'memory_summary.companions',
                'memory_summary.classifier'
            )
        );
    }

    public function testDiaryPackingWritesCanonicalOwnerFormat(): void
    {
        PackIntoSummary(true);

        $this->assertCount(1, $GLOBALS['db']->queries);
        $this->assertStringContainsString("'|' || trim(both '|' from trim(speaker)) || '|'", $GLOBALS['db']->queries[0]);
        $this->assertStringContainsString("event in ('diary','auto_diary','backgroundlife_diary')", $GLOBALS['db']->queries[0]);
    }

    public function testNpcRecallMatchesCanonicalAndLegacyDiaryOwners(): void
    {
        $this->assertSame(
            "(memory_summary.companions LIKE '%|Embry|%' OR memory_summary.companions='Embry')",
            dataGetMemoryCompanionConditionSql('Embry', 'memory_summary.companions')
        );
    }

    public function testNpcNameIsEscapedInRecallCondition(): void
    {
        $this->assertSame(
            "(companions LIKE '%|M''aiq''s Friend|%' OR companions='M''aiq''s Friend')",
            dataGetMemoryCompanionConditionSql("M'aiq's Friend")
        );
    }

    public function testExplicitNarratorDiaryRequestForcesRecall(): void
    {
        $this->assertTrue(chimShouldForceNarratorDiaryRecall([
            'narrator_inputtext',
            100,
            200,
            'Wrong. Use the diary. I am referring to the Covenant spy character.',
        ]));
    }

    public function testNarratorSceneContinuationForcesRecall(): void
    {
        $this->assertTrue(chimShouldForceNarratorDiaryRecall([
            'narrator_inputtext',
            100,
            200,
            'Write the story as we return to the character who sent us to the Jarl.',
        ]));
    }

    public function testOrdinaryNarratorRequestKeepsNormalRecallClassification(): void
    {
        $this->assertFalse(chimShouldForceNarratorDiaryRecall([
            'narrator_inputtext',
            100,
            200,
            'Describe the weather over Falkreath.',
        ]));
    }

    public function testNpcDiaryMentionDoesNotUseNarratorRecallOverride(): void
    {
        $this->assertFalse(chimShouldForceNarratorDiaryRecall([
            'inputtext',
            100,
            200,
            'Use the diary to remember the Covenant spy.',
        ]));
    }

    public function testForcedDiaryRecallKeepsRecentMemoryEligible(): void
    {
        $this->assertFalse(chimShouldDiscardRecentMemory(48.0, 140.0, true));
        $this->assertTrue(chimShouldDiscardRecentMemory(48.0, 140.0, false));
    }

    private function narratorProfile(bool $latestDiaryContextEnabled): array
    {
        return ['metadata' => json_encode(['LATEST_DIARY_CONTEXT_ENABLED' => $latestDiaryContextEnabled])];
    }

    public function testNarratorInheritsProfileSettingWhenOverrideWasNeverSaved(): void
    {
        $this->assertTrue(chimIsLatestDiaryContextEnabledFor('The Narrator', $this->narratorProfile(true)));
        $this->assertFalse(chimIsLatestDiaryContextEnabledFor('The Narrator', $this->narratorProfile(false)));
    }

    public function testSavedNarratorOverrideWinsOverAssignedProfile(): void
    {
        $GLOBALS['db']->narratorSettings['latest_diary_context_enabled'] = ['value' => '0'];
        $this->assertFalse(chimIsLatestDiaryContextEnabledFor('The Narrator', $this->narratorProfile(true)));

        $GLOBALS['db']->narratorSettings['latest_diary_context_enabled'] = ['value' => '1'];
        $this->assertTrue(chimIsLatestDiaryContextEnabledFor('The Narrator', $this->narratorProfile(false)));
    }

    public function testOrdinaryNpcsAlwaysUseTheirOwnProfileSetting(): void
    {
        $GLOBALS['db']->narratorSettings['latest_diary_context_enabled'] = ['value' => '1'];

        $this->assertFalse(chimIsLatestDiaryContextEnabledFor('Embry', $this->narratorProfile(false)));
        $this->assertTrue(chimIsLatestDiaryContextEnabledFor('Embry', $this->narratorProfile(true)));
    }

    public function testNarratorOverrideGatesTheLatestDiaryContextBlock(): void
    {
        $GLOBALS['db']->latestDiaryEntry = ['topic' => 'Sundas', 'content' => 'We left Falkreath at dawn.'];

        $GLOBALS['db']->narratorSettings['latest_diary_context_enabled'] = ['value' => '0'];
        $this->assertSame('', chimBuildLatestDiaryContextBlock('The Narrator', $this->narratorProfile(true)));

        $GLOBALS['db']->narratorSettings['latest_diary_context_enabled'] = ['value' => '1'];
        $this->assertStringContainsString(
            'We left Falkreath at dawn.',
            chimBuildLatestDiaryContextBlock('The Narrator', $this->narratorProfile(false))
        );
    }
}
