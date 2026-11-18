<?php declare(strict_types=1);

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class SpeechAudienceBackfillTest extends TestCase
{
    private object $fakeDb;

    protected function setUp(): void
    {
        require_once __DIR__ . '/../../lib/chat_helper_functions.php';
        $this->fakeDb = new class {
            public array $rows = [];
            public array $updates = [];
            public function fetchAll($sql) {
                if (preg_match('/rowid IN \(([\d,]+)\)/', $sql, $m)) {
                    $ids = array_map('intval', explode(',', $m[1]));
                    return array_values(array_filter($this->rows, fn($r) => in_array($r['rowid'], $ids, true)));
                }
                if (preg_match("/gamets=(\d+) AND data LIKE '(.*):%'/", $sql, $m)) {
                    return array_values(array_filter($this->rows, fn($r) => $r['gamets'] === (int)$m[1]
                        && str_starts_with($r['data'], stripslashes($m[2]) . ':')));
                }
                return [];
            }
            public function escape($s) { return str_replace("'", "''", (string)$s); }
            public function execQuery($sql) {
                preg_match("/people='(.*)' WHERE rowid=(\d+)/s", $sql, $m);
                $this->updates[(int)$m[2]] = $m[1];
            }
        };
        $GLOBALS['db'] = $this->fakeDb;
    }

    private function row(int $id, string $people, string $data, int $gamets = 100): void
    {
        $this->fakeDb->rows[$id] = ['rowid' => $id, 'gamets' => $gamets, 'people' => $people, 'data' => $data];
    }

    public function testAddsEveryoneWhoHeardTheLine(): void
    {
        $this->row(1, '|Mila Valentia|Freya|', 'Mila Valentia: We leave at dawn. (talking to Freya)');
        $this->assertSame(1, chimWidenChatPeopleFromSpeech([1], '|Mila Valentia|Lucia|Freya|'));
        $this->assertSame('|Mila Valentia|Freya|Lucia|', $this->fakeDb->updates[1]);
    }

    public function testWidensTheWholeTurnFromTheFirstSentence(): void
    {
        $this->row(1, '|Mila Valentia|Freya|', 'Mila Valentia: We leave at dawn. (talking to Freya)');
        $this->row(2, '|Mila Valentia|Freya|', 'Mila Valentia: Pack light. (talking to Freya)');
        $this->row(3, '|Lucia|Freya|', 'Lucia: Already packed. (talking to Freya)');
        $this->row(4, '|Mila Valentia|Freya|', 'Mila Valentia: Earlier turn. (talking to Freya)', 90);
        $this->assertSame(2, chimWidenChatPeopleFromSpeech([1], '|Mila Valentia|Lucia|Freya|'));
        $this->assertSame([1, 2], array_keys($this->fakeDb->updates));
    }

    public function testNeverRemovesAnyone(): void
    {
        $this->row(1, '|Mila Valentia|Lucia|Freya|Carlotta Valentia|', 'Mila Valentia: Hm. (talking to Freya)');
        $this->assertSame(0, chimWidenChatPeopleFromSpeech([1], '|Mila Valentia|Freya|'));
        $this->assertSame([], $this->fakeDb->updates);
    }

    public function testPrivateLinesAreLeftAsWritten(): void
    {
        $this->row(1, '|Mila Valentia|Freya|', 'Mila Valentia: Not here. (whispering to Freya)');
        $this->row(2, '|Mila Valentia|Freya|', 'Mila Valentia: Not here. (speaking privately to Freya)', 101);
        $this->assertSame(0, chimWidenChatPeopleFromSpeech([1, 2], '|Mila Valentia|Lucia|Freya|'));
    }

    public function testStateSuffixDoesNotDuplicateAName(): void
    {
        $this->row(1, '|Mila Valentia|Lucia (busy)|Freya|', 'Mila Valentia: Look. (talking to Freya)');
        $this->assertSame(0, chimWidenChatPeopleFromSpeech([1], '|Mila Valentia|Lucia|Freya|'));
    }

    public function testNothingToDoWithoutHearersOrRows(): void
    {
        $this->row(1, '|Mila Valentia|Freya|', 'Mila Valentia: Look. (talking to Freya)');
        $this->assertSame(0, chimWidenChatPeopleFromSpeech([1], ''));
        $this->assertSame(0, chimWidenChatPeopleFromSpeech([], '|Lucia|'));
    }
}
