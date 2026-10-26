<?php declare(strict_types=1);

require_once 'DatabaseTestCase.php';
require_once(__DIR__.DIRECTORY_SEPARATOR."..".DIRECTORY_SEPARATOR."..".DIRECTORY_SEPARATOR."lib".DIRECTORY_SEPARATOR."data_functions.php");
require_once(__DIR__.DIRECTORY_SEPARATOR."..".DIRECTORY_SEPARATOR."..".DIRECTORY_SEPARATOR."lib".DIRECTORY_SEPARATOR."chat_helper_functions.php");

final class HistoricContextTestDbAdapter
{
    private string $connString;

    public function __construct(string $connString)
    {
        $this->connString = $connString;
    }

    private function connect()
    {
        $connection = pg_connect($this->connString);
        if ($connection === false) {
            throw new RuntimeException("Failed to connect to test database.");
        }

        return $connection;
    }

    public function fetchAll(string $query): array
    {
        $connection = $this->connect();
        $result = pg_query($connection, $query);
        if ($result === false) {
            pg_close($connection);
            return [];
        }

        $rows = [];
        while ($row = pg_fetch_assoc($result)) {
            $rows[] = $row;
        }

        pg_close($connection);
        return $rows;
    }

    public function escape(string $value): string
    {
        $connection = $this->connect();
        $escaped = pg_escape_string($connection, $value);
        pg_close($connection);
        return $escaped;
    }
}

final class HistoricContextTest extends DatabaseTestCase
{
    private string $connString = "host=localhost dbname=testdb user=dwemer password=dwemer";

    public function setUp(): void
    {
        parent::setUp();
        require("conf.php");
        $GLOBALS["db"] = new HistoricContextTestDbAdapter($this->connString);
        $GLOBALS["PLAYER_NAME"] = "Prisoner";
        $GLOBALS["HERIKA_NAME"] = "Lydia";
        $GLOBALS["gameRequest"] = ["chat", "200", "200", ""];
    }

    public function tearDown(): void
    {
        unset(
            $GLOBALS["db"],
            $GLOBALS["gameRequest"],
            $GLOBALS["PLAYER_NAME"],
            $GLOBALS["HERIKA_NAME"]
        );

        parent::tearDown();
    }

    private function insertEvent(
        string $type,
        string $data,
        string $people,
        int $ts,
        int $gameTs,
        int $localTs,
        ?string $deliveryState = null
    ): void {
        $connection = pg_connect($this->connString);
        $this->assertNotFalse($connection);
        pg_query_params(
            $connection,
            "INSERT INTO eventlog (ts, gamets, type, data, sess, localts, people, location, party, delivery_state)
             VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, $10)",
            [
                (string)$ts,
                (string)$gameTs,
                $type,
                $data,
                "pending",
                $localTs,
                $people,
                "",
                "[]",
                $deliveryState,
            ]
        );
        pg_close($connection);
    }

    public function testBuildHistoricContextIncludesRestrainedAudienceRows(): void
    {
        $this->insertEvent(
            "inputtext",
            "Prisoner: Keep your voice down.",
            "|Lydia (restrained)|Prisoner|",
            100,
            100,
            100
        );

        $context = buildHistoricContext("Lydia", -5);
        $contents = array_map(static function (array $row): string {
            return (string)($row["content"] ?? "");
        }, $context);

        $this->assertContains("Prisoner: Keep your voice down.", $contents);
    }

    public function testBuildHistoricContextKeepsInjectedEventForOffSceneRecipient(): void
    {
        $injectedEvent = "(Camilla wrote to Farengar that the Prisoner will ask for the sealed letter.)";
        $this->insertEvent("inputtext", $injectedEvent, "|Camilla|Lydia|", 90, 90, 90);

        for ($index = 0; $index < 8; $index++) {
            $this->insertEvent(
                "inputtext",
                "(Unrelated quest event {$index}.)",
                "|Belethor|",
                100 + $index,
                100 + $index,
                100 + $index
            );
        }

        $lydiaContents = array_map(static function (array $row): string {
            return (string)($row["content"] ?? "");
        }, buildHistoricContext("Lydia", -5));
        $ysoldaContents = array_map(static function (array $row): string {
            return (string)($row["content"] ?? "");
        }, buildHistoricContext("Ysolda", -5));

        $this->assertContains($injectedEvent, $lydiaContents);
        $this->assertNotContains($injectedEvent, $ysoldaContents);
    }

    public function testBuildHistoricContextIncludesNarratorRowsForSharedAudience(): void
    {
        $this->insertEvent(
            "chat",
            "The Narrator: A cold wind sweeps through the inn.",
            "|Lydia|Prisoner|The Narrator|",
            100,
            100,
            100
        );

        $context = buildHistoricContext("Lydia", -5);
        $contents = array_map(static function (array $row): string {
            return (string)($row["content"] ?? "");
        }, $context);

        $this->assertContains("The Narrator: A cold wind sweeps through the inn.", $contents);
    }

    public function testBuildHistoricContextStillExcludesNonNarratorRowsForFarAwayAudience(): void
    {
        $this->insertEvent(
            "chat",
            "Belethor: Everything's for sale, my friend.",
            "|Lydia (far away)|Prisoner|Belethor|",
            100,
            100,
            100
        );

        $context = buildHistoricContext("Lydia", -5);
        $contents = array_map(static function (array $row): string {
            return (string)($row["content"] ?? "");
        }, $context);

        $this->assertNotContains("Belethor: Everything's for sale, my friend.", $contents);
    }

    public function testBuildHistoricContextIncludesEmittedChatRows(): void
    {
        $this->insertEvent(
            "chat",
            "Lydia: The perimeter is secure, my Thane. (talking to Prisoner)",
            "|Lydia|Prisoner|",
            100,
            100,
            100,
            "emitted"
        );

        $context = buildHistoricContext("Lydia", -5);
        $contents = array_map(static function (array $row): string {
            return (string)($row["content"] ?? "");
        }, $context);

        $this->assertContains("Lydia: The perimeter is secure, my Thane. (talking to Prisoner)", $contents);
    }

    public function testBuildHistoricContextIncludesOwnContextPrefixedBackgroundDialogue(): void
    {
        $line = "(Context location: Abandoned House,Hold: Markarth background chat) Hivorate [Dremora]: Mortal, we've come to test your worth.";
        $this->insertEvent(
            "chat_background",
            $line,
            "|Demienna|Molag Grunda|Gniledan [Dremora]|Hivorate [Dremora]|Gatanas [Dremora]|",
            100,
            100,
            100,
            "spoken"
        );

        $context = buildHistoricContext("Hivorate [Dremora]", -5);
        $contents = array_map(static function (array $row): string {
            return (string)($row["content"] ?? "");
        }, $context);

        $this->assertContains("Hivorate [Dremora]: Mortal, we've come to test your worth.", $contents);
    }

    public function testBuildHistoricContextStillExcludesContextPrefixedDialogueFromBystanders(): void
    {
        $line = "(Context location: Abandoned House,Hold: Markarth background chat) Hivorate [Dremora]: Mortal, we've come to test your worth.";
        $this->insertEvent(
            "chat",
            $line,
            "|Gniledan [Dremora]|Hivorate [Dremora]|",
            100,
            100,
            100,
            "spoken"
        );

        $context = buildHistoricContext("Gniledan [Dremora]", -5);
        $contents = array_map(static function (array $row): string {
            return (string)($row["content"] ?? "");
        }, $context);

        $this->assertNotContains("Hivorate [Dremora]: Mortal, we've come to test your worth.", $contents);
    }

    public function testBuildHistoricContextExcludesPendingChatRows(): void
    {
        $this->insertEvent(
            "chat",
            "Lydia: I have not actually said this yet. (talking to Prisoner)",
            "|Lydia|Prisoner|",
            100,
            100,
            100,
            "pending"
        );

        $context = buildHistoricContext("Lydia", -5);
        $contents = array_map(static function (array $row): string {
            return (string)($row["content"] ?? "");
        }, $context);

        $this->assertNotContains("Lydia: I have not actually said this yet. (talking to Prisoner)", $contents);
    }

    public function testBuildHistoricContextExcludesAbortedChatRows(): void
    {
        $this->insertEvent(
            "chat",
            "Lydia: This line was canceled. (talking to Prisoner)",
            "|Lydia|Prisoner|",
            100,
            100,
            100,
            "aborted"
        );

        $context = buildHistoricContext("Lydia", -5);
        $contents = array_map(static function (array $row): string {
            return (string)($row["content"] ?? "");
        }, $context);

        $this->assertNotContains("Lydia: This line was canceled. (talking to Prisoner)", $contents);
    }

    public function testNormalizeActorNameForComparisonStripsRestrainedSuffix(): void
    {
        $this->assertSame("lydia", normalizeActorNameForComparison("Lydia (restrained)"));
    }

    public function testExactTimestampsDateEachBlockOfTheHistory(): void
    {
        $day = 10000000; // gamets per in-game day; day 0 is the 17th of Last Seed
        $GLOBALS["PROMPT_TIMESTAMP_EXACT"] = true;
        $GLOBALS["gameRequest"] = ["chat", "300", (string)(5 * $day + 3750000), ""]; // 22nd of Last Seed, 9:00 AM
        try {
            $this->insertEvent("inputtext", "Prisoner: That troll nearly had us.", "|Lydia|Prisoner|", 100, 4 * $day + 6527778, 100); // 21st, 3:40 PM
            $this->insertEvent("inputtext", "Prisoner: Morning, Lydia.", "|Lydia|Prisoner|", 200, 5 * $day + 3680556, 200);          // 22nd, 8:50 AM

            $contents = array_map(static function (array $row): string {
                return (string)($row["content"] ?? "");
            }, buildHistoricContext("Lydia", -5));
        } finally {
            unset($GLOBALS["PROMPT_TIMESTAMP_EXACT"]);
        }

        $indexOf = static function (string $needle) use ($contents): int {
            foreach ($contents as $i => $content) {
                if (str_contains($content, $needle)) {
                    return $i;
                }
            }
            return -1;
        };
        $yesterday = $indexOf("21st of Last Seed, 4E 201 (yesterday) ---");
        $troll = $indexOf("Prisoner: That troll nearly had us.");
        $today = $indexOf("22nd of Last Seed, 4E 201 (today, less than an hour ago) ---");
        $morning = $indexOf("Prisoner: Morning, Lydia.");

        $this->assertGreaterThanOrEqual(0, $yesterday, "the first block is dated");
        $this->assertGreaterThanOrEqual(0, $today, "a new day opens a new dated block");
        $this->assertTrue($yesterday < $troll && $troll < $today && $today < $morning, "dividers precede their events: " . implode(" | ", $contents));
        $this->assertCount(2, array_filter($contents, static fn(string $c): bool => str_starts_with($c, "--- ")));
    }
}
