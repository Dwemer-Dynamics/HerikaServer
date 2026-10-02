<?php
error_reporting(E_ERROR);
session_start();

// Define base paths
define('BASE_PATH', dirname(dirname(__DIR__)));
define('CONFIG_PATH', BASE_PATH . DIRECTORY_SEPARATOR . 'conf');
define('LIB_PATH', BASE_PATH . DIRECTORY_SEPARATOR . 'lib');

$configFilepath = CONFIG_PATH . DIRECTORY_SEPARATOR;

if (!file_exists($configFilepath."conf.php")) {
    http_response_code(500);
    echo json_encode(['error' => 'Configuration file not found']);
    exit;
}

// Load profiles through the centralized profile loader
require_once(dirname(__DIR__).DIRECTORY_SEPARATOR."profile_loader.php");

require_once(LIB_PATH .DIRECTORY_SEPARATOR."logger.php");
require_once(LIB_PATH .DIRECTORY_SEPARATOR."{$GLOBALS["DBDRIVER"]}.class.php");
require_once(LIB_PATH .DIRECTORY_SEPARATOR."utils_game_timestamp.php");

$db = new sql();

// Set JSON header
header('Content-Type: application/json');

// Determine which data to return
$mode = isset($_GET['list']) ? $_GET['list'] : null;
$person = isset($_GET['person']) ? $_GET['person'] : null;
$entryId = isset($_GET['entry']) ? intval($_GET['entry']) : null;

function diaryAudioEndpoint(int $entryId): string
{
    $scriptPath = strval($_SERVER['SCRIPT_NAME'] ?? '');
    $uiPosition = strpos($scriptPath, '/ui/');
    $webRoot = $uiPosition !== false ? substr($scriptPath, 0, $uiPosition) : '';
    $host = trim(strval($_SERVER['HTTP_HOST'] ?? ''));
    $isHttps = !empty($_SERVER['HTTPS']) && strtolower(strval($_SERVER['HTTPS'])) !== 'off';
    $origin = $host !== '' ? (($isHttps ? 'https' : 'http') . '://' . $host) : '';

    return $origin . rtrim($webRoot, '/') . '/ui/api/chim_diary_audio.php?entry=' . $entryId;
}

require_once(LIB_PATH . DIRECTORY_SEPARATOR . "core" . DIRECTORY_SEPARATOR . "npc_reference.php");
$authorKey = isset($_GET['author_key']) ? trim(strval($_GET['author_key'])) : (isset($_GET['author']) ? trim(strval($_GET['author'])) : null);
$readGroup = !empty($_GET['group']);

if ($mode === 'people') {
    // Selectors: one per exact physical/typed author key, plus legacy unassigned names kept separate.
    // Labels are display-only; selectors never resolve an author by name.
    $keyed = $db->fetchAll("
        SELECT author_key,
               (array_agg(trim(people, '|') ORDER BY gamets DESC, rowid DESC))[1] AS name,
               COUNT(*) AS count
        FROM diarylog
        WHERE author_key IS NOT NULL AND topic NOT IN ('Sent Letter', 'Journal Note')
        GROUP BY author_key
    ");
    $legacy = $db->fetchAll("
        WITH split_people AS (
            SELECT d.rowid, trim(unnest(string_to_array(trim(d.people, '|'), '|'))) AS person
            FROM diarylog d
            WHERE d.author_key IS NULL AND d.people IS NOT NULL AND d.people != ''
            AND d.topic NOT IN ('Sent Letter', 'Journal Note')
        )
        SELECT person AS name, COUNT(DISTINCT rowid) AS count
        FROM split_people WHERE person != '' GROUP BY person
    ");
    if ($keyed === false || $legacy === false) {
        echo json_encode(['success' => false, 'error' => 'Failed to fetch people list']);
        exit;
    }
    $labels = [];
    foreach ((array)$keyed as $row) { $labels[strtolower((string)$row['name'])][] = $row['author_key']; }
    $people = [];
    foreach ((array)$keyed as $row) {
        $people[] = [
            'selector' => 'author:' . $row['author_key'],
            'author_key' => $row['author_key'],
            'name' => (string)$row['name'],
            'label' => (string)$row['name'],
            'count' => intval($row['count']),
            'legacy' => false,
            'label_shared' => count($labels[strtolower((string)$row['name'])]) > 1,
        ];
    }
    foreach ((array)$legacy as $row) {
        $people[] = [
            'selector' => 'legacy:' . $row['name'],
            'name' => (string)$row['name'],
            'label' => (string)$row['name'],
            'count' => intval($row['count']),
            'legacy' => true,
            'label_shared' => isset($labels[strtolower((string)$row['name'])]),
        ];
    }
    usort($people, static fn($a, $b) => [$b['count'], $a['name'], $a['legacy']] <=> [$a['count'], $b['name'], $b['legacy']]);
    echo json_encode(['success' => true, 'people' => $people]);

} elseif ($authorKey !== null || $person) {
    // Exact author (optionally its explicitly linked profile group) or one legacy unassigned name.
    if ($authorKey !== null) {
        if (!chimIsActorKey($authorKey)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid author key']);
            exit;
        }
        $keys = [$authorKey];
        if ($readGroup && str_contains($authorKey, ':')) {
            require_once(LIB_PATH . DIRECTORY_SEPARATOR . "core" . DIRECTORY_SEPARATOR . "npc_profile_sharing.php");
            $rows = $db->fetchAll("SELECT * FROM core_npc_master ORDER BY id");
            foreach ((array)$rows as $row) {
                if (chimNpcRowActorKey($row) === $authorKey) { $keys = chimNpcProfileActorKeys($row) ?: $keys; break; }
            }
        }
        $quoted = implode(',', array_map(static fn($key) => "'" . $db->escape($key) . "'", $keys));
        $scope = "author_key IN ($quoted)";
    } else {
        $personEsc = $db->escape(trim(strval($person)));
        $scope = "author_key IS NULL AND '$personEsc' = ANY (SELECT trim(x) FROM unnest(string_to_array(trim(people, '|'), '|')) x)";
    }
    $query = "
        SELECT 
            rowid, 
            topic, 
            content, 
            people, 
            author_key,
            location, 
            localts, 
            gamets
        FROM diarylog
        WHERE $scope
        AND topic NOT IN ('Sent Letter', 'Journal Note')
        ORDER BY localts DESC, rowid DESC
    ";
    
    $results = $db->fetchAll($query);
    
    if ($results) {
        $entries = [];
        foreach ($results as $row) {
            // Create preview (first 80 characters)
            $content = $row['content'];
            $preview = strlen($content) > 80 ? substr($content, 0, 80) . '...' : $content;
            
            // Format Tamrielic date if gamets available
            $tamrielicDate = '';
            if (isset($row['gamets']) && $row['gamets'] > 0) {
                $tamrielicDate = convert_gamets2skyrim_long_date_no_time($row['gamets']);
            }
            
            // Extract location from the location string
            $locationStr = trim($row['location'], '()');
            $location = 'Unknown';
            if (preg_match('/Context new location:\s*([^,]+)/i', $locationStr, $matches)) {
                $location = trim($matches[1]);
            } elseif (preg_match('/Hold:\s*([^,]+)/i', $locationStr, $matches)) {
                $location = trim($matches[1]);
            }
            
            $entries[] = [
                'rowid' => intval($row['rowid']),
                'preview' => $preview,
                'date' => $tamrielicDate,
                'location' => $location,
                'author_key' => $row['author_key'],
                'author' => trim((string)$row['people'], '|'),
                'localts' => intval($row['localts'])
            ];
        }
        
        echo json_encode([
            'success' => true,
            'person' => $person,
            'author_key' => $authorKey,
            'person_label' => $authorKey !== null ? ($entries[0]['author'] ?? '') : strval($person),
            'entries' => $entries
        ]);
    } else {
        echo json_encode([
            'success' => true,
            'person' => $person,
            'author_key' => $authorKey,
            'person_label' => $authorKey !== null ? '' : strval($person),
            'entries' => []
        ]);
    }
    
} elseif ($entryId) {
    // Return a single diary entry by rowid
    $query = "
        SELECT 
            rowid, 
            topic, 
            content, 
            people, 
            author_key,
            location, 
            localts, 
            gamets
        FROM diarylog
        WHERE rowid = $entryId
        LIMIT 1
    ";
    
    $result = $db->fetchOne($query);
    
    if ($result) {
        // Format Tamrielic date
        $tamrielicDate = '';
        if (isset($result['gamets']) && $result['gamets'] > 0) {
            $tamrielicDate = convert_gamets2skyrim_long_date2($result['gamets']);
        }
        
        // Extract location
        $locationStr = trim($result['location'], '()');
        $location = 'Unknown';
        if (preg_match('/Context new location:\s*([^,]+)/i', $locationStr, $matches)) {
            $location = trim($matches[1]);
        } elseif (preg_match('/Hold:\s*([^,]+)/i', $locationStr, $matches)) {
            $location = trim($matches[1]);
        }
        
        // Get author from people field
        $peopleStr = trim($result['people'], '|');
        $peopleArray = !empty($peopleStr) ? explode('|', $peopleStr) : [];
        $author = !empty($peopleArray) ? $peopleArray[0] : 'Unknown';
        
        echo json_encode([
            'success' => true,
            'entry' => [
                'rowid' => intval($result['rowid']),
                'content' => $result['content'],
                'date' => $tamrielicDate,
                'author' => $author,
                'author_key' => $result['author_key'],
                'author_label' => $author,
                'legacy' => $result['author_key'] === null,
                'location' => $location,
                'audio_endpoint' => diaryAudioEndpoint(intval($result['rowid']))
            ]
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'error' => 'Entry not found'
        ]);
    }
    
} else {
    // Invalid request
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Invalid request. Use ?list=people, ?author_key=key[&group=1], ?person=LegacyName, or ?entry=123'
    ]);
}
