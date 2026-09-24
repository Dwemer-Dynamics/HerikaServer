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
    public ?array $activeCourier = null;
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
        if (str_contains($query, "courier_state IN ('spawn_requested', 'approaching', 'departing')")) {
            return $this->activeCourier ?? [];
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

    public function responselogActions(): array
    {
        return array_values(array_map(
            fn($insert) => $insert[1]['action'],
            array_filter($this->inserts, fn($insert) => $insert[0] === 'responselog')
        ));
    }

    public function mergedUpdates(): array
    {
        return array_merge(...array_map(fn($update) => $update[1], $this->updates));
    }
}

final class BglLettersTest extends TestCase
{
    private BglLettersFakeDb $db;
    private array $saved = [];

    protected function setUp(): void
    {
        foreach (['db', 'PLAYER_NAME', 'BGL_LETTER_FEE', 'BGL_LETTER_DELAY_HOURS', 'BGL_LETTER_COURIER_NAME'] as $key) {
            $this->saved[$key] = $GLOBALS[$key] ?? null;
        }
        $this->db = new BglLettersFakeDb();
        $GLOBALS['db'] = $this->db;
        $GLOBALS['PLAYER_NAME'] = 'Varek';
        unset($GLOBALS['BGL_LETTER_FEE'], $GLOBALS['BGL_LETTER_DELAY_HOURS'], $GLOBALS['BGL_LETTER_COURIER_NAME']);
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

    public function testWireSafeRemovesCommandSeparators(): void
    {
        $this->assertSame('A letter from Lydia-Varek (Morndas)', chimLetterWireSafe("A letter @from| Lydia/Varek\n(Morndas)"));
    }

    public function testSignedRefIdMatchesPapyrusIntegers(): void
    {
        $this->assertSame(0x0001A694, chimLetterSignedRefId('0001A694'));
        $this->assertSame(0xFF0010D8 - 0x100000000, chimLetterSignedRefId('0xFF0010D8'));
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
        // Oldest first, so the conversation reads in order.
        $this->assertLessThan(strpos($block, 'Bring wine.'), strpos($block, 'I found your amulet.'));
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

    public function testCourierArrivalTakesFeeAndStartsTravel(): void
    {
        $this->db->activeCourier = [
            'id' => 12, 'npc_name' => 'Lydia', 'fee' => 10, 'courier_state' => 'approaching',
            'courier_name' => 'Letter Carrier', 'courier_event_rowid' => 400, 'state_changed_localts' => time(),
        ];
        $this->db->seenEvents = [['status_msg', 'reached_destination_player@Letter Carrier']];

        chimLetterCourierTick(new NpcMaster());

        $actions = implode("\n", $this->db->responselogActions());
        $this->assertStringContainsString('rolecommand|Instruction@Letter Carrier@Greet Varek briefly', $actions);
        $this->assertStringContainsString('rolecommand|ScriptProxy@', $actions);
        $this->assertStringContainsString('"akItemToRemove":"0x0000000F"', $actions);
        $this->assertStringContainsString('"aiCount":10', $actions);
        $this->assertStringContainsString('The courier takes your letter to Lydia for 10 gold.', $actions);

        $updates = $this->db->mergedUpdates();
        $this->assertSame('in_transit', $updates['status']);
        $this->assertSame(1000000 + (int)round(6 / 0.0000024), $updates['deliver_gamets']);
        $this->assertSame('departing', $updates['courier_state']);
    }

    public function testCourierThatNeverSpawnsFallsBackWithoutBlocking(): void
    {
        $this->db->activeCourier = [
            'id' => 13, 'npc_name' => 'Lydia', 'fee' => 0, 'courier_state' => 'spawn_requested',
            'courier_name' => 'Letter Carrier', 'courier_event_rowid' => 400, 'state_changed_localts' => time() - 3600,
        ];

        chimLetterCourierTick(new NpcMaster());

        $actions = implode("\n", $this->db->responselogActions());
        $this->assertStringNotContainsString('ScriptProxy', $actions, 'No fee is taken when the fee is zero');
        $this->assertStringContainsString('A courier collected your letter to Lydia.', $actions);
        $updates = $this->db->mergedUpdates();
        $this->assertSame('in_transit', $updates['status']);
        $this->assertSame('done', $updates['courier_state']);
    }
}
