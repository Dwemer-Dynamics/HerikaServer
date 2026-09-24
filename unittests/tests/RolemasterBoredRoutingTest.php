<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/rolemaster_bored.php';

final class RolemasterBoredActivityDb
{
    public array $queries = [];
    private array $results;

    public function __construct(array $results)
    {
        $this->results = $results;
    }

    public function fetchOne(string $query)
    {
        $this->queries[] = $query;
        return array_shift($this->results) ?? false;
    }
}

final class RolemasterBoredRoutingTest extends TestCase
{
    public function testActivitySnapshotUsesLatestEventAndSpeechPositions(): void
    {
        $db = new RolemasterBoredActivityDb([[
            'event_rowid' => '42',
            'speech_rowid' => '900',
        ]]);

        $this->assertSame(
            ['event_rowid' => 42, 'speech_rowid' => 900],
            chimBoredEventCaptureActivitySnapshot($db)
        );
        $this->assertStringContainsString('MAX(rowid)', $db->queries[0]);
        $this->assertStringContainsString('FROM speech', $db->queries[0]);
        $this->assertStringContainsString('AS speech_rowid', $db->queries[0]);
    }

    public function testActivitySnapshotRejectsFailedQuery(): void
    {
        $db = new RolemasterBoredActivityDb([false]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unable to capture a valid bored activity snapshot');

        chimBoredEventCaptureActivitySnapshot($db);
    }

    public function testActivitySnapshotRejectsIncompleteResult(): void
    {
        $db = new RolemasterBoredActivityDb([[
            'event_rowid' => '42',
        ]]);

        $this->expectException(RuntimeException::class);

        chimBoredEventCaptureActivitySnapshot($db);
    }

    public function testSupersedingConversationActivityIsReturned(): void
    {
        $db = new RolemasterBoredActivityDb([[
            'activity_rowid' => '45',
            'activity_type' => 'chat',
        ]]);

        $this->assertSame(
            ['rowid' => 45, 'type' => 'chat'],
            chimBoredEventFindSupersedingActivity($db, [
                'event_rowid' => 42,
                'speech_rowid' => 900,
            ])
        );
        $this->assertStringContainsString('rowid > 42', $db->queries[0]);
        $this->assertStringContainsString("type = 'user_input'", $db->queries[0]);
        $this->assertStringContainsString("type = 'chat'", $db->queries[0]);
        $this->assertStringContainsString("IN ('emitted', 'spoken')", $db->queries[0]);
        $this->assertStringContainsString('FROM speech', $db->queries[0]);
        $this->assertStringContainsString('rowid > 900', $db->queries[0]);
    }

    public function testSupersedingSpeechActivityIsReturned(): void
    {
        $db = new RolemasterBoredActivityDb([[
            'activity_rowid' => '91',
            'activity_type' => 'speech',
        ]]);

        $this->assertSame(
            ['rowid' => 91, 'type' => 'speech'],
            chimBoredEventFindSupersedingActivity($db, [
                'event_rowid' => 42,
                'speech_rowid' => 900,
            ])
        );
    }

    public function testMissingSupersedingActivityReturnsNull(): void
    {
        $db = new RolemasterBoredActivityDb([[
            'activity_rowid' => null,
            'activity_type' => null,
        ]]);

        $this->assertNull(chimBoredEventFindSupersedingActivity($db, [
            'event_rowid' => 42,
            'speech_rowid' => 900,
        ]));
    }

    public function testActivityValidationRejectsFailedQuery(): void
    {
        $db = new RolemasterBoredActivityDb([false]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unable to validate the bored activity snapshot');

        chimBoredEventFindSupersedingActivity($db, [
            'event_rowid' => 42,
            'speech_rowid' => 900,
        ]);
    }

    public function testBoredEventChanceUsesZeroBasedPercentageBoundary(): void
    {
        $this->assertFalse(chimBoredEventChancePasses(0, 0));
        $this->assertTrue(chimBoredEventChancePasses(35, 34));
        $this->assertFalse(chimBoredEventChancePasses(35, 35));
        $this->assertTrue(chimBoredEventChancePasses(100, 99));
        $this->assertFalse(chimBoredEventChancePasses(100, 100));
        $this->assertFalse(chimBoredEventChancePasses(-1, 0));
        $this->assertTrue(chimBoredEventChancePasses(101, 99));
    }

    public function testActorMapExcludesPlayerAndKeepsSeed(): void
    {
        $actors = chimRolemasterBoredActorMap(
            '|Camilla Valerius|RANGROO|Lucan Valerius|',
            'RANGROO',
            'Camilla Valerius'
        );

        $this->assertSame([
            'camilla valerius' => 'Camilla Valerius',
            'lucan valerius' => 'Lucan Valerius',
        ], $actors);
    }

    public function testInstructionsRejectInventedActorsAndRequireSeed(): void
    {
        $actors = chimRolemasterBoredActorMap('|Camilla Valerius|Lucan Valerius|', 'RANGROO', 'Camilla Valerius');
        $instructions = [
            ['character' => 'Siddgeir', 'instruction' => 'Appear from nowhere'],
            ['character' => 'lucan valerius', 'instruction' => 'Ask about the shop'],
            ['character' => 'camilla valerius', 'instruction' => 'Answer Lucan'],
        ];

        $filtered = chimRolemasterFilterBoredInstructions($instructions, $actors, 'Camilla Valerius');

        $this->assertCount(1, $filtered);
        $this->assertSame('Camilla Valerius', $filtered[0]['character']);
        $this->assertSame('Answer Lucan', $filtered[0]['instruction']);
        $this->assertSame(
            [],
            chimRolemasterFilterBoredInstructions(
                [['character' => 'Lucan Valerius', 'instruction' => 'Speak alone']],
                $actors,
                'Camilla Valerius'
            )
        );
    }

    public function testListenerRequirementOnlyUsesKnownNearbyActor(): void
    {
        $actors = chimRolemasterBoredActorMap('|Camilla Valerius|Lucan Valerius|', 'RANGROO', 'Camilla Valerius');

        $this->assertSame(
            ' The dialogue listener must be Lucan Valerius.',
            chimRolemasterBoredListenerRequirement('lucan valerius', $actors)
        );
        $this->assertSame('', chimRolemasterBoredListenerRequirement('RANGROO', $actors));
        $this->assertSame('', chimRolemasterBoredListenerRequirement('everyone', $actors));
    }

    public function testDedicatedBoredSystemPromptDelegatesFinalDialogue(): void
    {
        $systemPrompt = chimRolemasterDefaultBoredSystemPrompt();

        $this->assertStringContainsString('selected initiating actor', $systemPrompt);
        $this->assertStringContainsString("actor's own model will produce the in-character response", $systemPrompt);
        $this->assertStringNotContainsString('generate new content/events', $systemPrompt);
        $this->assertStringNotContainsString('You are a game director', $systemPrompt);
    }

    public function testBoredEventRulesRenderPromptManagerPlaceholders(): void
    {
        $actors = chimRolemasterBoredActorMap(
            '|Camilla Valerius|Lucan Valerius|',
            'RANGROO',
            'Camilla Valerius'
        );

        $rules = chimRolemasterRenderBoredEventRules(
            chimRolemasterDefaultBoredEventRules(),
            'Camilla Valerius',
            'RANGROO',
            $actors,
            "  ** Follow\n  ** Talk"
        );

        $this->assertStringContainsString(
            'Return exactly one instruction, using the selected initiating actor: Camilla Valerius.',
            $rules
        );
        $this->assertStringContainsString(
            "Do not generate the listener's reply.",
            $rules
        );
        $this->assertStringContainsString(
            'does not need to introduce a new topic or advance the plot',
            $rules
        );
        $this->assertStringContainsString('Prefer JustTalk', $rules);
        $this->assertStringContainsString("  ** Follow\n  ** Talk", $rules);
        $this->assertStringNotContainsString('override general Director', $rules);
        $this->assertStringContainsString(
            'Camilla Valerius, Lucan Valerius',
            $rules
        );
        $this->assertStringContainsString(
            'Do not target or comment on RANGROO',
            $rules
        );
        $this->assertStringNotContainsString('{SEED_ACTOR_RULE}', $rules);
        $this->assertStringNotContainsString('{NEARBY_ACTORS}', $rules);
        $this->assertStringNotContainsString('{PLAYER_NAME}', $rules);
        $this->assertStringNotContainsString('{FUNCTION_LIST}', $rules);
    }

    public function testBoredEventRulesAllowCustomPromptWithoutSeed(): void
    {
        $rules = chimRolemasterRenderBoredEventRules(
            "Actors: {NEARBY_ACTORS}\n{SEED_ACTOR_RULE}\nPlayer: {PLAYER_NAME}",
            '',
            'RANGROO',
            ['lucan valerius' => 'Lucan Valerius']
        );

        $this->assertSame("Actors: Lucan Valerius\n\nPlayer: RANGROO", $rules);
    }
}
