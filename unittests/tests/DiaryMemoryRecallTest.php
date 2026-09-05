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

    public function testNarratorSearchesTheGlobalMemoryBankByDefault(): void
    {
        $this->assertSame('TRUE', dataGetMemoryCompanionConditionSql(''));
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
