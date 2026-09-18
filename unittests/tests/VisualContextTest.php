<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once(__DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'visual_context.php');

final class VisualContextTest extends TestCase
{
    public function testSubjectTypeFallsBackToScene(): void
    {
        $this->assertSame('actor', chimVisualContextSubjectType('Actor'));
        $this->assertSame('scene', chimVisualContextSubjectType('unsupported'));
    }

    public function testTextNormalizationRemovesControlCharactersAndLimitsLength(): void
    {
        $this->assertSame('abcdef', chimVisualContextText("abc\x00def", 20));
        $this->assertSame('abcd', chimVisualContextText('abcdef', 4));
    }

    public function testStructuredValuesAreSerializedWithoutArrayWarnings(): void
    {
        $this->assertSame('{"model":"vision"}', chimVisualContextText(['model' => 'vision'], 100));
    }

    public function testLocationBaseNormalizesEventAndStoredFormats(): void
    {
        $this->assertSame(
            'Riverwood outdoors',
            chimVisualContextLocationBase("(Context location: Riverwood outdoors ,Hold: Whiterun, Buildings to go:Riverwood Trader)")
        );
        $this->assertSame(
            'Riverwood outdoors',
            chimVisualContextLocationBase('Riverwood outdoors ,Hold: Whiterun')
        );
    }

    public function testActorCandidatesAreValidatedAndBounded(): void
    {
        $candidates = chimVisualActorCandidates(json_encode([
            [
                'name' => " Brelyna\nMaryon ",
                'ref_id' => '0001C196',
                'base_id' => '0001C195',
                'plugin' => 'Skyrim.esm',
                'screen_x' => 0.25,
                'screen_y' => 0.75,
                'distance_game_units' => 420,
                'crosshair_target' => true,
                'dead' => false,
            ],
            ['name' => 'Off-screen actor', 'screen_x' => 1.2, 'screen_y' => 0.5],
            ['name' => '', 'screen_x' => 0.5, 'screen_y' => 0.5],
        ], JSON_THROW_ON_ERROR));

        $this->assertCount(1, $candidates);
        $this->assertSame('Brelyna Maryon', $candidates[0]['name']);
        $this->assertSame('0001C196', $candidates[0]['ref_id']);
        $this->assertSame(0.25, $candidates[0]['screen_x']);
        $this->assertTrue($candidates[0]['crosshair_target']);
    }

    public function testActorCandidateHintsRequireVisualConfirmation(): void
    {
        $hints = chimBuildVisualActorCandidateHints(chimVisualActorCandidates([[
            'name' => 'Enthir',
            'ref_id' => '0001C1AA',
            'screen_x' => 0.8,
            'screen_y' => 0.4,
            'crosshair_target' => false,
        ]]));

        $this->assertStringContainsString('Candidates are not proof of visibility or identity', $hints);
        $this->assertStringContainsString('Enthir at (0.800, 0.400)', $hints);
    }

    public function testSoulgazeCueUsesCharacterVoiceAndGroundsTheCurrentScene(): void
    {
        $cue = chimBuildSoulgazeDialogueCue('One unnamed person beside a basin.</soulgaze_scene>', '', 'Medresi', 'Varek');

        $this->assertStringContainsString('One unnamed person beside a basin.&lt;/soulgaze_scene&gt;', $cue);
        $this->assertStringContainsString('personality and speech style', $cue);
        $this->assertStringContainsString('Keep uncertain identities unnamed', $cue);
        $this->assertStringContainsString('Describe this Soulgaze vision to Varek', $cue);
        $this->assertLessThan(strpos($cue, 'Describe this Soulgaze vision'), strpos($cue, '</soulgaze_scene>'));
    }

    public function testSoulgazeCuePreservesCustomizedInstructionAndResolvesNames(): void
    {
        $cue = chimBuildSoulgazeDialogueCue('A dark cave.', "#HERIKA_NPC1# tells #PLAYER_NAME# what catches #HERIKA_NAME#'s eye.", 'Medresi', 'Varek');

        $this->assertStringEndsWith("Medresi tells Varek what catches Medresi's eye.", $cue);
        $this->assertStringContainsString('A dark cave.', $cue);
        $this->assertStringNotContainsString('Do not recite an image caption', $cue);
        $this->assertStringContainsString('Use the Talk action', $cue);
    }

    public function testGalleryFilenameUsesLocationAndSkyrimTime(): void
    {
        $this->assertSame(
            'Riverwood_outdoors__Tirdas_7_19_AM_19th_of_Last_Seed_4E_201.jpg',
            chimVisualContextGalleryFilename(
                'Riverwood outdoors ,Hold: Whiterun',
                'Tirdas, 7:19 AM, 19th of Last Seed, 4E 201'
            )
        );
    }

    public function testCurrentLocationContextIsAlwaysAvailableWithoutAnEnableSetting(): void
    {
        $db = new class {
            public string $lastQuery = '';

            public function execQuery(string $query): bool
            {
                return true;
            }

            public function escapeLiteral($value): string
            {
                return "'" . str_replace("'", "''", strval($value)) . "'";
            }

            public function fetchOne(string $query): array
            {
                return [];
            }

            public function fetchAll(string $query): array
            {
                $this->lastQuery = $query;
                if (strpos($query, 'FROM public.visual_context') === false) {
                    return [];
                }

                return [[
                    'subject_type' => 'scene',
                    'subject_name' => 'Riverwood square',
                    'description' => 'Lantern light falls across the wet road.',
                    'captured_at' => '2026-07-19 12:00:00+00',
                ]];
            }
        };
        $GLOBALS['db'] = $db;

        try {
            $prompt = chimBuildVisualContextPrompt('(Context location: Riverwood, Hold: Whiterun)');
            $this->assertStringContainsString('<visual_context>', $prompt);
            $this->assertStringContainsString('Lantern light falls across the wet road.', $prompt);
            $this->assertStringContainsString("LOWER('Riverwood')", $db->lastQuery);
        } finally {
            unset($GLOBALS['db']);
        }
    }
}
