<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/utils_game_timestamp.php';
require_once __DIR__ . '/../../lib/settings.php';
require_once __DIR__ . '/../../lib/core/prisma_settings_catalog.php';
require_once __DIR__ . '/../../lib/core/settings_presets.php';

/**
 * PROMPT_TIMESTAMP_EXACT dates the event context with the in-game date and time. These tests pin
 * the divider text, when a new dated block starts, the relative hint, and the setting's registration.
 */
final class ExactTimestampDividerTest extends TestCase
{
    private const DAY = 10000000;            // gamets per in-game day
    private const HOUR = self::DAY / 24;
    private const MINUTE = self::DAY / 1440;

    private string $timezone = 'UTC';

    protected function setUp(): void
    {
        $this->timezone = date_default_timezone_get();
        date_default_timezone_set('UTC');
        unset($GLOBALS['SKYRIM_START_DATE'], $GLOBALS['skyrim_start_date'], $GLOBALS['PROMPT_TIMESTAMP_EXACT']);
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->timezone);
        unset($GLOBALS['PROMPT_TIMESTAMP_EXACT']);
    }

    public function testFormatsTheReferenceDateWithARealOrdinal(): void
    {
        // The file's own reference value; convert_gamets2skyrim_long_date renders this day as "23th".
        $this->assertSame("Sundas, 3:23 PM, 23rd of Sun's Dusk, 4E 201", chimGametsToExactDateTime(986414848));
        $this->assertSame('Sundas, 12:00 AM, 17th of Last Seed, 4E 201', chimGametsToExactDateTime(1));
        $this->assertSame('', chimGametsToExactDateTime(0));
    }

    public function testOrdinalsCoverTheTeensAndTheOnesTwosAndThrees(): void
    {
        // Day 0 is the 17th of Last Seed; Last Seed has 31 days, so day 15 is the 1st of Hearthfire.
        $expected = [
            4 => '21st of Last Seed',
            15 => '1st of Hearthfire',
            16 => '2nd of Hearthfire',
            17 => '3rd of Hearthfire',
            25 => '11th of Hearthfire',
            26 => '12th of Hearthfire',
            27 => '13th of Hearthfire',
            36 => '22nd of Hearthfire',
        ];
        foreach ($expected as $dayIndex => $fragment) {
            $this->assertStringContainsString($fragment, chimGametsToExactDateTime($dayIndex * self::DAY + 9 * self::HOUR));
        }
    }

    public function testFirstRowAlwaysOpensADatedBlock(): void
    {
        $row = 4 * self::DAY + 15 * self::HOUR + 40 * self::MINUTE;   // Furdas, 3:40 PM, 21st of Last Seed
        $now = 5 * self::DAY + 9 * self::HOUR + 20 * self::MINUTE;    // the next morning

        $this->assertSame(
            '--- Furdas, 3:40 PM, 21st of Last Seed, 4E 201 (yesterday) ---',
            chimExactTimestampDivider($row, $now, null)
        );
    }

    public function testRowsWithinAnHourShareTheBlock(): void
    {
        $first = 4 * self::DAY + 15 * self::HOUR + 40 * self::MINUTE;
        $now = 5 * self::DAY + 9 * self::HOUR + 20 * self::MINUTE;

        $this->assertNull(chimExactTimestampDivider($first + 5 * self::MINUTE, $now, $first));
        $this->assertNull(chimExactTimestampDivider($first + 50 * self::MINUTE, $now, $first));

        $later = chimExactTimestampDivider($first + 70 * self::MINUTE, $now, $first);
        $this->assertNotNull($later);
        $this->assertStringContainsString('4:50 PM, 21st of Last Seed', $later);
    }

    public function testADayChangeOpensANewBlockEvenMinutesLater(): void
    {
        $lateEvening = 4 * self::DAY + 23 * self::HOUR + 50 * self::MINUTE;
        $justPastMidnight = 5 * self::DAY + 5 * self::MINUTE;
        $now = 5 * self::DAY + 9 * self::HOUR + 20 * self::MINUTE;

        $divider = chimExactTimestampDivider($justPastMidnight, $now, $lateEvening);
        $this->assertNotNull($divider);
        $this->assertStringContainsString('12:05 AM, 22nd of Last Seed, 4E 201 (today, about 9 hours ago)', $divider);
    }

    public function testRelativeHintFollowsTheCalendarDay(): void
    {
        $now = 10 * self::DAY + 12 * self::HOUR;

        $this->assertSame('today, less than an hour ago', chimExactTimestampRelativeHint($now - 30 * self::MINUTE, $now));
        $this->assertSame('today, about 1 hour ago', chimExactTimestampRelativeHint($now - self::HOUR - 5 * self::MINUTE, $now));
        $this->assertSame('today, about 2 hours ago', chimExactTimestampRelativeHint($now - 2 * self::HOUR - 5 * self::MINUTE, $now));
        // Sixteen hours ago, but on the previous calendar day: "yesterday", not "about 16 hours ago".
        $this->assertSame('yesterday', chimExactTimestampRelativeHint(9 * self::DAY + 20 * self::HOUR, $now));
        $this->assertSame('3 days ago', chimExactTimestampRelativeHint(7 * self::DAY + 12 * self::HOUR, $now));
    }

    public function testUnknownOrFutureTimesGetNoHintAndNoDividerForUnknownRows(): void
    {
        $now = 10 * self::DAY + 12 * self::HOUR;

        $this->assertSame('', chimExactTimestampRelativeHint(0, $now));
        $this->assertSame('', chimExactTimestampRelativeHint($now + 1, $now));
        $this->assertNull(chimExactTimestampDivider(0, $now, null));

        // An event after the request's game time (older save loaded) is still dated, without a hint.
        $future = chimExactTimestampDivider($now + self::DAY, $now, null);
        $this->assertNotNull($future);
        $this->assertStringEndsWith('4E 201 ---', $future);
        $this->assertStringNotContainsString('(', $future);
    }

    public function testEnabledFlagAcceptsStoredStrings(): void
    {
        $this->assertFalse(chimPromptTimestampExactEnabled());
        $GLOBALS['PROMPT_TIMESTAMP_EXACT'] = 'false';
        $this->assertFalse(chimPromptTimestampExactEnabled());
        $GLOBALS['PROMPT_TIMESTAMP_EXACT'] = 'true';
        $this->assertTrue(chimPromptTimestampExactEnabled());
        $GLOBALS['PROMPT_TIMESTAMP_EXACT'] = true;
        $this->assertTrue(chimPromptTimestampExactEnabled());
    }

    public function testSettingIsRegisteredNextToTheTimestampSetting(): void
    {
        $this->assertContains('PROMPT_TIMESTAMP_EXACT', chimGetManagedGeneralSettingIds());
        $this->assertSame(
            chimGetOverrideableGeneralSettingCategory('PROMPT_TIMESTAMP'),
            chimGetOverrideableGeneralSettingCategory('PROMPT_TIMESTAMP_EXACT')
        );
        $this->assertSame('Exact Timestamps (Beta)', chimPrettySettingLabel('PROMPT_TIMESTAMP_EXACT'));

        $context = chimPrismaGlobalSettingsSections()['Context'];
        $names = array_column($context, 'name');
        $this->assertSame(array_search('PROMPT_TIMESTAMP', $names, true) + 1, array_search('PROMPT_TIMESTAMP_EXACT', $names, true));
        $field = array_column($context, null, 'name')['PROMPT_TIMESTAMP_EXACT'];
        $this->assertSame('boolean', $field['type']);
        $this->assertFalse($field['default']);
        $this->assertSame('Exact Timestamps (Beta)', $field['label']);

        $schema = json_decode(file_get_contents(__DIR__ . '/../../conf/conf_schema.json'), true);
        $this->assertSame('boolean', $schema['PROMPT_TIMESTAMP_EXACT']['type']);
        $this->assertFalse($schema['PROMPT_TIMESTAMP_EXACT']['default']);
        $this->assertSame('global', $schema['PROMPT_TIMESTAMP_EXACT']['scope']);

        $this->assertFalse(chimSettingsPresetDefaultGlobalSettings()['PROMPT_TIMESTAMP_EXACT']);
        $this->assertPresetsPairTheSettings(chimSettingsPresetBuiltIns());

        $portability = file_get_contents(__DIR__ . '/../../ui/cmd/settings_portability.php');
        $this->assertStringContainsString("'PROMPT_TIMESTAMP_EXACT' => 'boolean'", $portability);
    }

    /** Every preset that sets PROMPT_TIMESTAMP must also set PROMPT_TIMESTAMP_EXACT, off. */
    private function assertPresetsPairTheSettings(array $node): void
    {
        if (array_key_exists('PROMPT_TIMESTAMP', $node)) {
            $this->assertArrayHasKey('PROMPT_TIMESTAMP_EXACT', $node);
            $this->assertFalse($node['PROMPT_TIMESTAMP_EXACT']);
        }
        foreach ($node as $child) {
            if (is_array($child)) {
                $this->assertPresetsPairTheSettings($child);
            }
        }
    }
}
