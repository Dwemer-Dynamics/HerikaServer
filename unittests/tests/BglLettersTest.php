<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'bgl_letters.php';

/**
 * In-memory stand-in for the PostgreSQL wrapper. Answers the queries bgl_letters.php makes by
 * their shape and records every write.
 */
final class BglLettersFakeDb
{
    public int $gamets = 1000000;
    public array $letterRows = [];
    public array $awaiting = [];
    public array $due = [];
    public array $activeCourier = [];
    public bool $stale = false;
    public int $sentToday = 0;
    public array $seenEvents = [];
    public array $inserts = [];
    public array $updates = [];
    public array $queries = [];

    public function fetchAll($query, $log = false): array
    {
        if (str_contains($query, 'MAX(gamets) AS m_gts')) {
            return [['m_gts' => (string)$this->gamets]];
        }
        if (str_contains($query, 'MAX(ts) AS m_gts')) {
            return [['m_gts' => '1700000000']];
        }
        if (str_contains($query, "status = 'awaiting_courier'")) {
            return $this->awaiting;
        }
        if (str_contains($query, "status = 'in_transit'")) {
            return $this->due;
        }
        if (str_contains($query, 'FROM bgl_letters')) {
            return $this->letterRows;
        }
        return [];
    }

    public function fetchOne($query, array $params = []): array
    {
        if (str_contains($query, 'to_regclass')) {
            return ['t' => 'bgl_letters'];
        }
        if (str_contains($query, "courier_state IN ('spawn_requested'")) {
            return $this->activeCourier;
        }
        if (str_contains($query, 'state_changed_localts <')) {
            return $this->stale ? ['id' => 1] : [];
        }
        if (str_contains($query, 'COUNT(*) AS n')) {
            return ['n' => (string)$this->sentToday];
        }
        if (str_contains($query, 'MAX(rowid)')) {
            return ['r' => '500'];
        }
        if (str_contains($query, 'position($3 in data)')) {
            foreach ($this->seenEvents as [$type, $needle]) {
                if ($params[1] === $type && $params[2] === $needle) {
                    return ['x' => 1];
                }
            }
            return [];
        }
        return [];
    }

    public function escape($value): string
    {
        return str_replace("'", "''", (string)$value);
    }

    public function insert($table, $data)
    {
        $this->inserts[] = [$table, $data];
        return true;
    }

    public function updateRow($table, $data, $where): bool
    {
        $this->updates[] = [$table, $data, $where];
        return true;
    }

    public function execQuery($query)
    {
        $this->queries[] = $query;
        return true;
    }

    public function actions(): array
    {
        return array_values(array_map(
            fn($insert) => $insert[1]['action'],
            array_filter($this->inserts, fn($insert) => $insert[0] === 'responselog')
        ));
    }

    public function actionText(): string
    {
        return implode("\n", $this->actions());
    }

    /** Final state of one letter after all updates, in order. */
    public function stateOf(int $id): array
    {
        $state = [];
        foreach ($this->updates as [$table, $data, $where]) {
            if ($where === "id = {$id}") {
                $state = array_merge($state, $data);
            }
        }
        return $state;
    }
}

final class BglLettersTest extends TestCase
{
    private BglLettersFakeDb $db;
    private array $saved = [];

    protected function setUp(): void
    {
        foreach (['db', 'PLAYER_NAME', 'BGL_LETTER_FEE', 'BGL_LETTER_DELAY_HOURS', 'BGL_LETTER_COURIER_NAME', 'BGL_LETTER_DAILY_LIMIT'] as $key) {
            $this->saved[$key] = $GLOBALS[$key] ?? null;
        }
        $this->db = new BglLettersFakeDb();
        $GLOBALS['db'] = $this->db;
        $GLOBALS['PLAYER_NAME'] = 'Varek';
        unset($GLOBALS['BGL_LETTER_FEE'], $GLOBALS['BGL_LETTER_DELAY_HOURS'], $GLOBALS['BGL_LETTER_COURIER_NAME'], $GLOBALS['BGL_LETTER_DAILY_LIMIT']);
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $key => $value) {
            if ($value === null) {
                unset($GLOBALS[$key]);
            } else {
                $GLOBALS[$key] = $value;
            }
        }
    }

    private function lead(string $state, int $age, int $attempts = 0): array
    {
        return [
            'id' => 12, 'npc_name' => 'Lydia', 'fee' => 10, 'status' => 'awaiting_courier',
            'courier_state' => $state, 'courier_name' => 'Letter Carrier', 'courier_event_rowid' => 400,
            'courier_attempts' => $attempts, 'state_changed_localts' => time() - $age,
        ];
    }

    // ─── Helpers and meet-up context ─────────────────────────────────────────

    public function testWireSafeRemovesCommandSeparators(): void
    {
        $this->assertSame('A letter from Lydia-Varek (Morndas)', chimLetterWireSafe("A letter @from| Lydia/Varek\n(Morndas)"));
    }

    public function testSignedRefIdMatchesPapyrusIntegers(): void
    {
        $this->assertSame(0x0001A694, chimLetterSignedRefId('0001A694'));
        $this->assertSame(0xFF0010D8 - 0x100000000, chimLetterSignedRefId('0xFF0010D8'));
    }

    public function testRecipientNamesReadNaturally(): void
    {
        $this->assertSame('Lydia', chimLetterJoinNames(['Lydia']));
        $this->assertSame('Lydia, Faendal and Camilla', chimLetterJoinNames(['Lydia', 'Faendal', 'Camilla']));
        $this->assertSame('A, B, C and 3 others', chimLetterJoinNames(['A', 'B', 'C', 'D', 'E', 'F']));
    }

    public function testCorrespondenceBlockFramesLettersAsWrittenAndMarksThemDiscussed(): void
    {
        $this->db->letterRows = [
            ['id' => 7, 'direction' => 'to_player', 'status' => 'sent', 'body' => 'Bring wine.', 'sent_gamets' => 999000, 'deliver_gamets' => null],
            ['id' => 5, 'direction' => 'to_npc', 'status' => 'delivered', 'body' => 'I found your amulet.', 'sent_gamets' => 990000, 'deliver_gamets' => 995000],
        ];

        $block = chimLetterBuildCorrespondenceBlock('Lydia', true);

        $this->assertStringContainsString('<letter_correspondence>', $block);
        $this->assertStringContainsString('written, not spoken', $block);
        $this->assertStringContainsString('Letter from Varek to Lydia, received', $block);
        $this->assertStringContainsString('I found your amulet.', $block);
        $this->assertStringContainsString('Lydia does not know yet whether it arrived', $block);
        $this->assertLessThan(strpos($block, 'Bring wine.'), strpos($block, 'I found your amulet.'), 'Oldest first');
        $this->assertCount(1, $this->db->queries);
        $this->assertStringContainsString('SET discussed_gamets = 1000000', $this->db->queries[0]);
        $this->assertStringContainsString('id IN (7,5)', $this->db->queries[0]);
    }

    public function testCorrespondenceBlockDoesNotMarkDiscussedOutsidePlayerSpeech(): void
    {
        $this->db->letterRows = [
            ['id' => 5, 'direction' => 'to_npc', 'status' => 'delivered', 'body' => 'Hello.', 'sent_gamets' => 990000, 'deliver_gamets' => 995000],
        ];

        $this->assertNotSame('', chimLetterBuildCorrespondenceBlock('Lydia', false));
        $this->assertSame([], $this->db->queries);
    }

    public function testCorrespondenceBlockSkipsTheNarrator(): void
    {
        $this->db->letterRows = [['id' => 1, 'direction' => 'to_npc', 'status' => 'delivered', 'body' => 'x', 'sent_gamets' => 1, 'deliver_gamets' => 2]];

        $this->assertSame('', chimLetterBuildCorrespondenceBlock('The Narrator', true));
    }

    // ─── Courier: batching ───────────────────────────────────────────────────

    public function testOneCourierVisitCollectsEveryWaitingLetter(): void
    {
        $this->db->activeCourier = $this->lead('approaching', 5);
        $this->db->awaiting = [
            $this->db->activeCourier,
            ['id' => 13, 'npc_name' => 'Faendal', 'fee' => 10, 'courier_state' => 'queued'],
            ['id' => 14, 'npc_name' => 'Camilla', 'fee' => 10, 'courier_state' => 'queued'],
        ];
        $this->db->seenEvents = [['status_msg', 'reached_destination_player@Letter Carrier']];

        chimLetterCourierTick(new NpcMaster());

        $actions = $this->db->actionText();
        $this->assertStringContainsString(
            'Instruction@Letter Carrier@Greet Varek briefly, take the 3 sealed letters for Lydia, Faendal and Camilla and the 30 gold fee',
            $actions
        );
        $this->assertSame(1, substr_count($actions, 'ScriptProxy@'), 'One combined fee');
        $this->assertStringContainsString('"aiCount":30', $actions);
        $this->assertStringContainsString('The courier takes your 3 letters to Lydia, Faendal and Camilla for 30 gold.', $actions);

        $deliverAt = 1000000 + (int)round(6 / 0.0000024);
        foreach ([12, 13, 14] as $id) {
            $this->assertSame('in_transit', $this->db->stateOf($id)['status']);
            $this->assertSame($deliverAt, $this->db->stateOf($id)['deliver_gamets']);
        }
        $this->assertSame('collected', $this->db->stateOf(13)['courier_state']);
        $this->assertSame('departing', $this->db->stateOf(12)['courier_state']);
    }

    public function testNoNewCourierWhileOneIsActive(): void
    {
        $this->db->activeCourier = $this->lead('approaching', 5);

        chimLetterCourierTick(new NpcMaster());

        $this->assertStringNotContainsString('spawnCharacter', $this->db->actionText());
    }

    // ─── Courier: fail-safes ─────────────────────────────────────────────────

    public function testCourierThatNeverSpawnsTeleportsLettersAndStillDespawns(): void
    {
        $this->db->activeCourier = $this->lead('spawn_requested', 3600);
        $this->db->awaiting = [array_merge($this->db->activeCourier, ['fee' => 0])];

        chimLetterCourierTick(new NpcMaster());

        $actions = $this->db->actionText();
        $this->assertStringNotContainsString('ScriptProxy', $actions, 'No fee is taken when the fee is zero');
        $this->assertStringContainsString('A courier collected your letter to Lydia.', $actions);
        $this->assertStringContainsString('rolecommand|Despawn@Letter Carrier@0', $actions, 'A late spawn is cleaned up');
        $state = $this->db->stateOf(12);
        $this->assertSame('in_transit', $state['status']);
        $this->assertSame('dismissing', $state['courier_state']);
        $this->assertSame(1, $state['courier_attempts']);
    }

    public function testCourierThatNeverArrivesTeleportsLetters(): void
    {
        $this->db->activeCourier = $this->lead('approaching', 3600);
        $this->db->awaiting = [$this->db->activeCourier];

        chimLetterCourierTick(new NpcMaster());

        $actions = $this->db->actionText();
        $this->assertStringContainsString('A courier collected your letter to Lydia for 10 gold.', $actions);
        $this->assertStringContainsString('Despawn@Letter Carrier@0', $actions);
        $this->assertSame('in_transit', $this->db->stateOf(12)['status']);
    }

    public function testWatchdogTeleportsLettersStuckInAnyState(): void
    {
        $this->db->stale = true;
        $this->db->awaiting = [['id' => 20, 'npc_name' => 'Faendal', 'fee' => 10, 'courier_state' => 'queued']];

        chimLetterCourierTick(new NpcMaster());

        $this->assertStringContainsString('A courier collected your letter to Faendal for 10 gold.', $this->db->actionText());
        $this->assertSame('in_transit', $this->db->stateOf(20)['status']);
    }

    public function testClosedGamePausesCourierTimers(): void
    {
        chimLetterPauseCourierClock();

        $this->assertCount(1, $this->db->queries);
        $this->assertStringContainsString('SET state_changed_localts = ', $this->db->queries[0]);
        $this->assertStringContainsString("status = 'awaiting_courier'", $this->db->queries[0]);
    }

    // ─── Courier: cleanup ────────────────────────────────────────────────────

    public function testCourierStillSeenAfterDespawnIsDespawnedAgain(): void
    {
        $this->db->activeCourier = $this->lead('dismissing', 25, 1);
        $this->db->seenEvents = [['infonpc', 'Letter Carrier']];

        chimLetterCourierTick(new NpcMaster());

        $this->assertStringContainsString('Despawn@Letter Carrier@0', $this->db->actionText());
        $this->assertSame(2, $this->db->stateOf(12)['courier_attempts']);
    }

    public function testDismissalGivesUpAfterThreeAttempts(): void
    {
        $this->db->activeCourier = $this->lead('dismissing', 25, 3);
        $this->db->seenEvents = [['infonpc_close', 'Letter Carrier']];

        chimLetterCourierTick(new NpcMaster());

        $this->assertStringNotContainsString('Despawn', $this->db->actionText());
        $this->assertSame('done', $this->db->stateOf(12)['courier_state']);
    }

    public function testDismissalCompletesOnceTheCourierIsGone(): void
    {
        $this->db->activeCourier = $this->lead('dismissing', 31, 1);

        chimLetterCourierTick(new NpcMaster());

        $this->assertSame('done', $this->db->stateOf(12)['courier_state']);
    }

    // ─── Delivery ────────────────────────────────────────────────────────────

    public function testDeliveryPutsTheNoteInTheNpcInventory(): void
    {
        $this->db->due = [['id' => 30, 'npc_name' => 'Lydia', 'npc_refid' => '000A2C94', 'title' => 'A letter from Varek to Lydia', 'body' => 'Hello.', 'delivery_attempts' => 0]];

        chimLetterCourierTick(new NpcMaster());

        $this->assertStringContainsString('rolecommand|spawnBook@A letter from Varek to Lydia@0@' . 0x000A2C94 . '@', $this->db->actionText());
        $this->assertSame('delivered', $this->db->stateOf(30)['status']);
        $memoryRow = array_values(array_filter($this->db->inserts, fn($i) => $i[0] === 'eventlog'))[0][1];
        $this->assertSame('Lydia', $memoryRow['people']);
        $this->assertStringContainsString('<letter_content>', $memoryRow['data']);
    }

    public function testDeliveryAfterRepeatedFailuresSkipsTheNoteButStillDelivers(): void
    {
        $this->db->due = [['id' => 31, 'npc_name' => 'Lydia', 'npc_refid' => '000A2C94', 'title' => 'T', 'body' => 'Hello.', 'delivery_attempts' => 3]];

        chimLetterCourierTick(new NpcMaster());

        $this->assertStringNotContainsString('spawnBook', $this->db->actionText());
        $this->assertSame('delivered', $this->db->stateOf(31)['status']);
    }

    public function testDailyLimitIsOffByDefaultAndEnforcedWhenSet(): void
    {
        $this->db->sentToday = 50;
        $this->assertFalse(chimLetterDailyLimitReached());

        $GLOBALS['BGL_LETTER_DAILY_LIMIT'] = 5;
        $this->assertTrue(chimLetterDailyLimitReached());
    }
}
