<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/data_functions.php';
require_once __DIR__ . '/../../lib/chat_helper_functions.php';

final class DiaryMemoryRecallTest extends TestCase
{
    public function testRecallAgeUsesCurrentGameTimeAndRejectsFutureMemories(): void
    {
        $memory = 1000000000;
        foreach ([3 => 'About 3 days', 18 => 'About 2 weeks', 75 => 'About 2 months', 365 => 'About 1 year'] as $days => $label) {
            $this->assertStringStartsWith($label, chimMemoryAgeLabel($memory, $memory + $days * 10000000));
        }
        $this->assertStringContainsString('4E ', chimMemoryAgeLabel($memory, $memory + 30000000));
        $this->assertNull(chimMemoryAgeLabel($memory, $memory - 1));
        $this->assertSame('Date unknown', chimMemoryAgeLabel(null, $memory));
        $this->assertSame('Date unknown', chimMemoryAgeLabel(0, $memory));
    }

    protected function setUp(): void
    {
        $GLOBALS['db'] = new class {
            public array $queries = [];

            public ?array $narratorSetting = null;

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

            public function fetchOne(string $query): ?array
            {
                $this->queries[] = $query;

                return strpos($query, 'core_narrator') !== false
                    ? $this->narratorSetting
                    : $this->latestDiaryEntry;
            }
        };
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['db'], $GLOBALS['NARRATOR_ONLY_DIARY_ACCESS']);
    }

    public function testTypedNarratorSearchesTheGlobalMemoryBankByDefault(): void
    {
        $this->assertSame('TRUE', dataGetMemoryCompanionConditionSql('', 'companions', 'classifier', CHIM_ACTOR_KEY_NARRATOR));
    }

    public function testTypedNarratorCanBeRestrictedToItsOwnDiary(): void
    {
        $GLOBALS['NARRATOR_ONLY_DIARY_ACCESS'] = true;

        $this->assertSame(
            "(COALESCE(memory_summary.classifier, '') NOT IN ('diary','auto_diary','backgroundlife_diary')"
                . " OR (memory_summary.audience_keys IS NOT NULL AND memory_summary.audience_keys && ARRAY['narrator']::text[]))",
            dataGetMemoryCompanionConditionSql(
                '',
                'memory_summary.companions',
                'memory_summary.classifier',
                CHIM_ACTOR_KEY_NARRATOR
            )
        );
    }

    public function testUntypedEmptyOrNarratorNameReadsNothing(): void
    {
        unset($GLOBALS['CHIM_CORE_CURRENT_NPC_DATA'], $GLOBALS['CHIM_CONTEXT_ACTOR_PRINCIPAL']);
        $this->assertSame('FALSE', dataGetMemoryCompanionConditionSql(''));
        $this->assertSame('FALSE', dataGetMemoryCompanionConditionSql('The Narrator'));
    }

    public function testDiaryPackingWritesCanonicalOwnerFormat(): void
    {
        PackIntoSummary(true);

        $this->assertCount(1, $GLOBALS['db']->queries);
        $this->assertStringContainsString("'|' || trim(both '|' from trim(speaker)) || '|'", $GLOBALS['db']->queries[0]);
        $this->assertStringContainsString("event in ('diary','auto_diary','backgroundlife_diary')", $GLOBALS['db']->queries[0]);
    }

    public function testNpcNameWithoutSelectedPhysicalRowReadsNothing(): void
    {
        unset($GLOBALS['CHIM_CORE_CURRENT_NPC_DATA']);
        $this->assertSame('FALSE', dataGetMemoryCompanionConditionSql('Embry', 'memory_summary.companions'));
        $this->assertSame('FALSE', dataGetMemoryCompanionConditionSql("M'aiq's Friend"));
    }

    private function profileWithLatestDiaryContext(bool $enabled): array
    {
        return ['metadata' => json_encode(['LATEST_DIARY_CONTEXT_ENABLED' => $enabled])];
    }

    public function testNarratorInheritsProfileSettingUntilTheOverrideIsSaved(): void
    {
        $this->assertTrue(chimIsLatestDiaryContextEnabledFor('The Narrator', $this->profileWithLatestDiaryContext(true)));
        $this->assertFalse(chimIsLatestDiaryContextEnabledFor('The Narrator', $this->profileWithLatestDiaryContext(false)));
    }

    public function testSavedNarratorOverrideWinsOverTheAssignedProfile(): void
    {
        $GLOBALS['db']->narratorSetting = ['value' => '0'];
        $this->assertFalse(chimIsLatestDiaryContextEnabledFor('The Narrator', $this->profileWithLatestDiaryContext(true)));

        $GLOBALS['db']->narratorSetting = ['value' => '1'];
        $this->assertTrue(chimIsLatestDiaryContextEnabledFor('The Narrator', $this->profileWithLatestDiaryContext(false)));
    }

    public function testOrdinaryNpcsKeepUsingTheirOwnProfileSetting(): void
    {
        $GLOBALS['db']->narratorSetting = ['value' => '1'];

        $this->assertFalse(chimIsLatestDiaryContextEnabledFor('Embry', $this->profileWithLatestDiaryContext(false)));
        $this->assertTrue(chimIsLatestDiaryContextEnabledFor('Embry', $this->profileWithLatestDiaryContext(true)));
    }

    public function testNarratorOverrideGatesTheLatestDiaryContextBlock(): void
    {
        $GLOBALS['db']->latestDiaryEntry = ['topic' => 'Sundas', 'content' => 'We left Falkreath at dawn.'];

        $GLOBALS['db']->narratorSetting = ['value' => '0'];
        $this->assertSame('', chimBuildLatestDiaryContextBlock('The Narrator', $this->profileWithLatestDiaryContext(true)));

        $GLOBALS['db']->narratorSetting = ['value' => '1'];
        $this->assertStringContainsString(
            'We left Falkreath at dawn.',
            chimBuildLatestDiaryContextBlock('The Narrator', $this->profileWithLatestDiaryContext(false))
        );
    }
}
