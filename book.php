<?php
require_once __DIR__ . "/lib/playthrough_switching.php";
pas_http_guard();


/* POST book  entry point */


$path = dirname((__FILE__)) . DIRECTORY_SEPARATOR;
require_once($path . "lib" . DIRECTORY_SEPARATOR . "runtime_bootstrap.php");
chimRuntimeBootstrap($path, [
    'load_general_settings' => true,
    'load_stt_connector' => false,
    'load_itt_connector' => false,
    'load_player_name' => true,
    'load_narrator' => true,
]);
require_once($path . "lib" . DIRECTORY_SEPARATOR . "model_dynmodel.php");
require_once($path . "lib" . DIRECTORY_SEPARATOR . "data_functions.php");
require_once($path . "lib" . DIRECTORY_SEPARATOR . "chat_helper_functions.php");
require_once($path . "lib" . DIRECTORY_SEPARATOR . "auditing.php");
require_once($path . "lib" . DIRECTORY_SEPARATOR . "logger.php");
require_once($path . "lib" . DIRECTORY_SEPARATOR . "core" . DIRECTORY_SEPARATOR . "book_read.class.php");



$startTime = microtime(true);
Logger::trace("Audit run ID: " . $GLOBALS["AUDIT_RUNID"] . " (BOOK) started: " . $startTime);
$GLOBALS["AUDIT_RUNID_REQUEST"] = "BOOK";

$finalName = __DIR__ . DIRECTORY_SEPARATOR . "soundcache/_book_" . md5($_FILES["file"]["tmp_name"]) . ".txt";

if (!$_FILES["file"]["tmp_name"]) {
    Logger::error("BOOK error, no data given: " . print_r($_POST, true));
    die("BOOK error, no data given");

}
@copy($_FILES["file"]["tmp_name"], $finalName);

$db = $GLOBALS["db"] ?? new sql();
$GLOBALS["db"] = $db;


$title = $_GET["title"];
$titleEsc = $db->escape($title);

// Native identity books (C10: generated diaries) carry book_key/author_key/recipient_key/book_instance/
// content_version/reader_key. Only a book_key the server issued for that exact author is trusted, and only
// when content_version is the native FNV-1a 64 of the body the client uploaded after its "Title: <title>"
// line (DiaryBookIdentityUtils::ContentHash). An explicit identity that fails is rejected with 409 and no
// writes; it never falls back to title ownership. Uploads without book_key (legacy, notes, letters) are unchanged.
require_once($path . "lib" . DIRECTORY_SEPARATOR . "core" . DIRECTORY_SEPARATOR . "npc_reference.php");
$bookIdentity = null;
$identityParams = array_map(static fn($v) => trim(strval($v)), array_intersect_key($_GET,
    array_flip(['book_key', 'author_key', 'recipient_key', 'book_instance', 'content_version', 'reader_key'])));
if (($identityParams['book_key'] ?? '') !== '') {
    $authorKey = $identityParams['author_key'] ?? '';
    $recipientKey = $identityParams['recipient_key'] ?? '';
    $readerKey = $identityParams['reader_key'] ?? '';
    $valid = chimIsActorKey($authorKey) && preg_match('/^(ref|dyn):/', $authorKey)
        && $identityParams['book_key'] === 'diary:' . $authorKey
        && ($recipientKey === '' || chimIsActorKey($recipientKey))
        && ($readerKey === '' || chimIsActorKey($readerKey))
        && preg_match('/^[0-9a-f]{16}$/D', $identityParams['content_version'] ?? '')
        && preg_match('/^[A-Za-z0-9:_.-]{1,80}$/D', $identityParams['book_instance'] ?? '');
    $issued = $valid ? $db->fetchOne("SELECT rowid FROM books WHERE sess = 'physical_diary' AND book_key = '"
        . $db->escape($identityParams['book_key']) . "' AND author_key = '" . $db->escape($authorKey) . "' LIMIT 1") : null;
    $uploaded = (string)@file_get_contents($finalName);
    $prefix = 'Title: ' . strval($title) . "\n";
    $versionMatches = $valid && str_starts_with($uploaded, $prefix)
        && hash('fnv1a64', substr($uploaded, strlen($prefix))) === $identityParams['content_version'];
    if ($valid && !empty($issued) && $versionMatches) {
        $bookIdentity = [
            'book_key' => $identityParams['book_key'],
            'author_key' => $authorKey,
            'recipient_key' => $recipientKey !== '' ? $recipientKey : null,
            'reader_key' => $readerKey !== '' ? $readerKey : null,
            'book_instance' => $identityParams['book_instance'],
            'content_version' => $identityParams['content_version'],
        ];
    } else {
        Logger::warn("BOOK identity rejected (malformed, not issued or content_version mismatch); upload refused");
        @unlink($finalName);
        http_response_code(409);
        die("BOOK identity rejected");
    }
}

// Identity books use their own immutable uploaded copy, never another book that shares the title.
$alreadyinDb = $bookIdentity === null
    ? $db->fetchOne("select * from books where title = '$titleEsc' and sess='generated' ")
    : null;

if ($alreadyinDb) {
    Logger::info("BOOK already in DB, skipping insert for title: " . $title);

    $db->insert(
        'books',
        array(
            'ts' => $_GET["ts"],
            'gamets' => $_GET["gamets"],
            'content' => $alreadyinDb['content'],
            'sess' => 'pending',
            'localts' => time(),
            'title' => $title
        )
    );

    $db->insert(
        'eventlog',
        array(
            'ts' => $_GET["ts"],
            'gamets' => $_GET["gamets"],
            'type' => "contentbook",
            'data' => $alreadyinDb['content'],
            'sess' => 'pending',
            'localts' => time()
        )
    );


} else {
    Logger::info("BOOK inserting new book entry for title: " . $title);
    $db->insert(
        'books',
        array(
            'ts' => $_GET["ts"],
            'gamets' => $_GET["gamets"],
            'content' => strip_tags(file_get_contents($finalName)),
            'sess' => 'pending',
            'localts' => time(),
            'title' => $title
        ) + ($bookIdentity ?? [])
    );

    $db->insert(
        'eventlog',
        array(
            'ts' => $_GET["ts"],
            'gamets' => $_GET["gamets"],
            'type' => "contentbook",
            'data' => strip_tags(file_get_contents($finalName)),
            'sess' => 'pending',
            'localts' => time()
        )
    );

}

// A Background Life letter the player just opened: mark it read and let the sender know.
try {
    require_once($path . "lib" . DIRECTORY_SEPARATOR . "bgl_letters.php");
    if ($bookIdentity === null) chimLetterMarkReadByTitle((string)$title, (int)($_GET["gamets"] ?? 0), (int)($_GET["ts"] ?? 0));
} catch (Throwable $e) {
    Logger::warn("[BGL_LETTERS] Could not record letter read: " . $e->getMessage());
}

$readRequestId = trim(strval($_GET['read_request_id'] ?? ''));
$bookFormId = trim(strval($_GET['book_form_id'] ?? ''));
if ($readRequestId !== '' || $bookFormId !== '') {
    $validRequestId = preg_match('/^[0-9A-Fa-f]{32}$/', $readRequestId) === 1;
    $normalizedBookFormId = bookReadNormalizeFormId($bookFormId);

    if (!$validRequestId || $normalizedBookFormId === null) {
        Logger::warn("BOOK ignored invalid read request correlation data");
    } else {
        $bookCandidate = $db->fetchOne($bookIdentity === null
            ? "SELECT * FROM books WHERE LOWER(title)=LOWER('{$titleEsc}') AND book_key IS NULL AND content IS NOT NULL AND BTRIM(content) <> '' ORDER BY rowid DESC LIMIT 1"
            : "SELECT * FROM books WHERE sess = 'pending' AND book_key = '" . $db->escape($bookIdentity['book_key'])
                . "' AND book_instance = '" . $db->escape($bookIdentity['book_instance'])
                . "' AND content_version = '" . $db->escape($bookIdentity['content_version'])
                . "' AND content IS NOT NULL AND BTRIM(content) <> '' ORDER BY rowid DESC LIMIT 1"
        );

        if ($bookCandidate && bookReadStateAcceptUploadedContent($bookCandidate, $normalizedBookFormId, $readRequestId)) {
            Logger::info("BOOK completed pending reading request for title: " . $title);
        } else {
            Logger::warn("BOOK upload did not match an active reading request for title: " . $title);
        }
    }
}



?>
