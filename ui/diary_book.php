<?php
session_start();

date_default_timezone_set('UTC');
error_reporting(E_ALL);
ini_set('display_errors', '1');

// DB
$host = 'localhost';
$port = '5432';
$dbname = 'dwemer';
$schema = 'public';
$username = 'dwemer';
$password = 'dwemer';

require_once(dirname(__DIR__).DIRECTORY_SEPARATOR."lib/utils_game_timestamp.php");

$scriptPath = $_SERVER['SCRIPT_NAME'];
$webRoot = dirname(dirname($scriptPath));
if ($webRoot == '/') $webRoot = '';
$webRoot = rtrim($webRoot, '/');

$conn = pg_connect("host=$host port=$port dbname=$dbname user=$username password=$password");
if (!$conn) {
    http_response_code(500);
    echo "<p>DB connection failed.</p>";
    exit;
}

require_once(dirname(__DIR__).DIRECTORY_SEPARATOR."lib".DIRECTORY_SEPARATOR."core".DIRECTORY_SEPARATOR."npc_reference.php");

// One exact author: ?author=<key>, or ?npc_id=<selected row> (its physical key), else ?person=<legacy
// unassigned name>. A schema without author_key (older snapshots) reads legacy names only.
$person = isset($_GET['person']) ? trim((string)$_GET['person']) : '';
$author = isset($_GET['author']) ? trim((string)$_GET['author']) : '';
$keyColumn = pg_query_params($conn, "SELECT 1 FROM information_schema.columns WHERE table_schema = $1 AND table_name = 'diarylog' AND column_name = 'author_key'", [$schema]);
$hasAuthorKey = $keyColumn && pg_num_rows($keyColumn) > 0;
$npcId = (int)($_GET['npc_id'] ?? 0);
if ($author === '' && $npcId > 0) {
    $npcResult = pg_query_params($conn, "SELECT * FROM {$schema}.core_npc_master WHERE id = $1", [$npcId]);
    $npcRow = $npcResult ? pg_fetch_assoc($npcResult) : null;
    if (!$npcRow) {
        http_response_code(404);
        echo "<p>NPC not found.</p>";
        exit;
    }
    $author = (string)(chimNpcRowActorKey($npcRow) ?? '');
    if ($author === '' && $person === '') { $person = trim((string)$npcRow['npc_name']); }
}
if ($author !== '') {
    if (!$hasAuthorKey || !chimIsActorKey($author)) {
        echo "<p>Unknown diary author.</p>";
        exit;
    }
    $scopeSql = 'author_key = $1';
    $scopeParam = $author;
} elseif ($person !== '') {
    $scopeSql = ($hasAuthorKey ? 'author_key IS NULL AND ' : '') . "$1 = ANY (SELECT trim(x) FROM unnest(string_to_array(trim(people, '|'), '|')) x)";
    $scopeParam = $person;
} else {
    echo "<p>Missing person.</p>";
    exit;
}

$result = pg_query_params($conn, "SELECT rowid, content, people, location, localts, gamets FROM {$schema}.diarylog WHERE {$scopeSql}", [$scopeParam]);
$entries = [];
if ($result) {
    while ($row = pg_fetch_assoc($result)) { $entries[] = $row; }
}

usort($entries, function($a, $b) { return ((int)$a['localts']) - ((int)$b['localts']); });

pg_close($conn);

if ($author !== '') {
    // Display label: the author's most recent recorded name; the key is never the title.
    $person = '';
    foreach (array_reverse($entries) as $entry) {
        if (trim((string)$entry['people'], '|') !== '') { $person = trim((string)$entry['people'], '|'); break; }
    }
    if ($person === '') { $person = 'Unknown author'; }
}
$safeTitle = htmlspecialchars($person);
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>The Diary of <?php echo $safeTitle; ?></title>
    <link rel="stylesheet" href="<?php echo $webRoot; ?>/ui/css/main.css">
    <link rel="stylesheet" href="<?php echo $webRoot; ?>/ui/css/diary_adventure.css">
    <style>
        @font-face {
            font-family: 'MagicCards';
            src: url('<?php echo $webRoot; ?>/ui/css/font/MagicCardsNormal.ttf') format('truetype');
            font-weight: normal;
            font-style: normal;
            font-display: swap;
        }

        @font-face {
            font-family: 'SkyrimBooks_Handwritten_Bold';
            src: url('<?php echo $webRoot; ?>/ui/css/font/SkyrimBooks_Handwritten_Bold-Regular.ttf') format('truetype');
            font-weight: normal;
            font-style: normal;
            font-display: swap;
        }

        body { background: #1a1a1a; color: #f8f9fa; margin: 0; }
        .book { max-width: 980px; margin: 0 auto; padding: 30px 16px 60px; }
        .book-header { text-align: center; padding: 20px 0 10px; }
        .book-header h1 { font-family: 'MagicCards', serif; color: rgb(242, 124, 17); text-shadow: 2px 2px 4px rgba(0,0,0,0.5); margin: 0; font-size: 32px; letter-spacing: 1px; }
        .actions { position: sticky; top: 0; background: rgba(26,26,26,.92); padding: 8px 0; border-bottom: 1px solid #333; display: flex; gap: 8px; justify-content: flex-end; z-index: 2; }
        .btn { background: rgb(212, 94, 0); color: #fff; border: 2px solid rgb(172, 76, 0); padding: 8px 12px; border-radius: 6px; cursor: pointer; font-size: 14px; }
        .btn:hover { background: rgb(172, 76, 0); }

        /* Entry parchment block, matching CHIM modal look */
        .entry { page-break-inside: avoid; margin: 18px auto; max-width: 900px; }
        .entry .paper {
            background: url('<?php echo $webRoot; ?>/ui/images/paper.jpg') center/cover;
            padding: 36px;
            border-radius: 6px;
            box-shadow: 0 4px 8px rgba(0,0,0,0.5);
            color: #000;
            font-family: SkyrimBooks_Handwritten_Bold, Arial, sans-serif !important;
            font-size: 1.2em;
            line-height: 1.75;
            white-space: pre-wrap;
        }

        @media print {
            .actions { display: none; }
            body { background: #fff; color: #000; }
            .entry .paper { box-shadow: none; }
        }
    </style>
</head>
<body>
    <div class="book">
        <div class="actions">
            <button class="btn" onclick="window.print()">Print / Save as PDF</button>
        </div>
        <div class="book-header">
            <h1>The Diary of <?php echo $safeTitle; ?></h1>
        </div>
        <?php foreach ($entries as $e): ?>
            <?php $content = (string)$e['content']; ?>
            <div class="entry">
                <div class="paper"><?php echo nl2br(htmlspecialchars($content)); ?></div>
            </div>
        <?php endforeach; ?>
    </div>
</body>
</html>


