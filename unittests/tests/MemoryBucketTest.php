<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once(__DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'logger.php');
require_once(__DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'data_functions.php');

/**
 * A summary bucket can be stored as several episode rows sharing one gamets_truncated.
 * These helpers read memory by bucket and join a bucket's episodes back into one entry.
 */
final class MemoryBucketTest extends TestCase
{
    private function row(int $gamets, string $summary): array
    {
        return ['summary' => $summary, 'gamets_truncated' => (string) $gamets];
    }

    public function testRowsSqlAppliesTheFilterInsideAndOutside(): void
    {
        $sql = dataMemoryBucketRowsSql("scope='x' AND companions='Lydia'", 10);

        $this->assertSame(2, substr_count($sql, "companions='Lydia'"));
        $this->assertStringContainsString('SELECT DISTINCT gamets_truncated', $sql);
        $this->assertStringContainsString('ORDER BY gamets_truncated DESC LIMIT 10', $sql);
        $this->assertStringContainsString('ORDER BY gamets_truncated DESC, rowid ASC', $sql);
    }

    public function testRowsSqlOldestPicksAscending(): void
    {
        $sql = dataMemoryBucketRowsSql('summary IS NOT NULL', 4, true);

        $this->assertStringContainsString('ORDER BY gamets_truncated ASC LIMIT 4', $sql);
        $this->assertStringContainsString('ORDER BY gamets_truncated DESC, rowid ASC', $sql);
    }

    public function testGroupsEpisodesByBucketNewestFirst(): void
    {
        $out = dataGroupMemoryBuckets([
            $this->row(400, "#Summary: A\n\n#Tags: #b"),
            $this->row(900, "#Summary: C\n\n#Tags: #a"),
            $this->row(400, '#Summary: B'),
        ]);

        $this->assertSame([
            ['gamets_truncated' => 900, 'content' => 'C'],
            ['gamets_truncated' => 400, 'content' => "A\n\nB"],
        ], $out);
    }

    public function testLegacySingleSummaryIsStrippedNotChanged(): void
    {
        $out = dataGroupMemoryBuckets([$this->row(300, "#Summary: The road.\n\n#Tags: #Whiterun")]);

        $this->assertSame([['gamets_truncated' => 300, 'content' => 'The road.']], $out);
    }

    public function testAllEmptyBucketIsDropped(): void
    {
        $out = dataGroupMemoryBuckets([
            $this->row(700, "#Summary:\n\n#Tags: #x"),
            $this->row(600, '#Summary: Real.'),
        ]);

        $this->assertSame([['gamets_truncated' => 600, 'content' => 'Real.']], $out);
    }
}
