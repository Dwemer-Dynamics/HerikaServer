<?php

define('BOOK_READ_STATE_KEY', 'book_reading_state');
define('BOOK_READ_PENDING_TIMEOUT_SECONDS', 90);
define('BOOK_READ_CHUNK_SIZE', 512);       // characters per LLM formatting chunk
define('BOOK_READ_QUEUE_DEPTH', 4);        // lines kept in-flight in the ScriptQueue
define('BOOK_READ_MIN_BUFFERED_LINES', 4); // don't start TTS enqueue until this many lines are formatted
if (!defined('MAXIMUM_SENTENCE_SIZE')) {
    define('MAXIMUM_SENTENCE_SIZE', 125);
}
if (!defined('MINIMUM_SENTENCE_SIZE')) {
    define('MINIMUM_SENTENCE_SIZE', 15);
}


// ─── Public API helpers for outside callers ───────────────────────────────────

/**
 * Load the current book-reading state from conf_opts.
 *
 * @return array|null The decoded state, or null if none exists.
 */
function bookReadStateGet()
{
    $row = $GLOBALS["db"]->fetchOne("SELECT value FROM public.conf_opts WHERE id='" . $GLOBALS["db"]->escape(BOOK_READ_STATE_KEY) . "' LIMIT 1");
    if (!$row || empty($row['value'])) {
        return null;
    }
    $decoded = json_decode($row['value'], true);
    return is_array($decoded) ? $decoded : null;
}

/**
 * Save the book-reading state to conf_opts.
 *
 * @param array $state
 */
function bookReadStateSet(array $state)
{
    $GLOBALS["db"]->upsertRowOnConflict('conf_opts', [
        'id' => BOOK_READ_STATE_KEY,
        'value' => json_encode($state),
    ], 'id');
}

/**
 * Normalize a book title for comparison, stripping decoration characters.
 *
 * @param string $title
 * @return string
 */
function bookReadNormalizeTitle($title)
{
    return strtolower(trim(preg_replace('/^[^A-Za-z0-9]+|[^A-Za-z0-9]+$/', '', (string) $title)));
}

/**
 * Compare a natural-language book request with an exact inventory title.
 */
function bookReadTitleMatchesQuery($title, $query)
{
    if (bookReadNormalizeTitle($title) === bookReadNormalizeTitle($query)) {
        return true;
    }

    $stopWords = [
        'a',
        'aloud',
        'an',
        'book',
        'can',
        'could',
        'it',
        'me',
        'please',
        'read',
        'that',
        'the',
        'this',
        'to',
        'will',
        'would',
        'you',
    ];
    $tokenize = static function ($value) use ($stopWords) {
        preg_match_all('/[[:alnum:]]+/u', mb_strtolower(strval($value)), $matches);
        return array_values(array_unique(array_filter(
            $matches[0] ?? [],
            static fn($token) => !in_array($token, $stopWords, true)
        )));
    };

    $titleTokens = $tokenize($title);
    $queryTokens = $tokenize($query);
    if (empty($titleTokens) || empty($queryTokens)) {
        return false;
    }

    foreach ($queryTokens as $queryToken) {
        if (!in_array($queryToken, $titleTokens, true)) {
            return false;
        }
    }

    return true;
}

/**
 * Normalize a Skyrim runtime FormID for request correlation.
 *
 * @param string $formId
 * @return string|null
 */
function bookReadNormalizeFormId($formId)
{
    $value = trim((string) $formId);
    if (stripos($value, '0x') === 0) {
        $value = substr($value, 2);
    }

    if ($value === '' || strlen($value) > 8 || !ctype_xdigit($value)) {
        return null;
    }

    return sprintf('0x%08X', hexdec($value));
}

/**
 * Split the BaseID:BookTitle identifier used by inventory prompt entries.
 *
 * @param string $identifier
 * @return array{form_id: string|null, title: string}
 */
function bookReadParseBookIdentifier($identifier)
{
    $value = trim((string) $identifier);
    if (strlen($value) >= 2 && $value[0] === '`' && substr($value, -1) === '`') {
        $value = trim(substr($value, 1, -1));
    }

    if (preg_match('/^((?:0x[0-9A-Fa-f]{1,8})|(?:[0-9A-Fa-f]{8})):(.+)$/s', $value, $matches)) {
        $formId = bookReadNormalizeFormId($matches[1]);
        $title = trim($matches[2]);
        if ($formId !== null && $title !== '') {
            return ['form_id' => $formId, 'title' => $title];
        }
    }

    return ['form_id' => null, 'title' => $value];
}

/**
 * Build a minimal fresh reading state for a book candidate.
 *
 * @param array $bookCandidate Book row with at least 'title' and 'rowid'.
 * @param string $narratorName
 * @param string $playerName
 * @param string $commenter Optional actor that comments on the reading. Defaults to narratorName.
 * @return array
 */
function bookReadStateForBook(array $bookCandidate, $narratorName, $playerName, $commenter = null)
{
    return [
        'title' => bookReadNormalizeTitle($bookCandidate['title']),
        'rowid' => $bookCandidate['rowid'],
        'narrator' => $narratorName,
        'player' => $playerName,
        'commenter' => $commenter ?: $narratorName,
    ];
}

/**
 * Confirm that a reading state contains the generated playback data required by active statuses.
 */
function bookReadStateHasInitializedSession($state)
{
    return is_array($state)
        && intval($state['rowid'] ?? 0) > 0
        && isset($state['chunks'])
        && is_array($state['chunks'])
        && isset($state['lines'])
        && is_array($state['lines']);
}

/**
 * Pause initialized playback for a new user turn without changing pending content requests.
 */
function bookReadStatePauseForInput()
{
    $state = bookReadStateGet();
    if (!is_array($state)) {
        return false;
    }

    $status = strval($state['status'] ?? '');
    if ($status === 'paused' && !bookReadStateHasInitializedSession($state)) {
        $state['status'] = 'done';
        $state['animation_end_done'] = true;
        bookReadStateSet($state);
        error_log("[book_read] Cleared malformed paused state without initialized book content.");
        return false;
    }

    if (
        bookReadStateHasInitializedSession($state)
        && in_array($status, ['reading', 'unpaused', 'resume_requested'], true)
    ) {
        $state['status'] = 'paused';
        bookReadStateSet($state);
        return true;
    }

    return false;
}

/**
 * Build the shared waiting state for an uncached Skyrim book upload.
 *
 * @return array The saved waiting state, including its correlation token and a transient creation flag.
 */
function bookReadStateCreateContentRequest($bookTitle, $formId, $narratorName, $playerName, $commenter = null, $allowTitleLookup = false, $titleIsQuery = false)
{
    $normalizedFormId = bookReadNormalizeFormId($formId);
    if ($normalizedFormId === null && !$allowTitleLookup) {
        throw new InvalidArgumentException('A valid book FormID is required to request content.');
    }

    $requestedAt = time();
    $normalizedTitle = bookReadNormalizeTitle($bookTitle);
    $resolvedCommenter = $commenter ?: $narratorName;
    $requestedTitleQuery = $titleIsQuery ? $normalizedTitle : '';
    $currentState = bookReadStateGet();
    if (
        is_array($currentState)
        && ($currentState['status'] ?? '') === 'waiting_for_content'
        && intval($currentState['expires_at'] ?? 0) >= $requestedAt
        && bookReadNormalizeTitle($currentState['title'] ?? '') === $normalizedTitle
        && bookReadNormalizeFormId($currentState['requested_form_id'] ?? '') === $normalizedFormId
        && trim(strval($currentState['requested_title_query'] ?? '')) === $requestedTitleQuery
        && strval($currentState['narrator'] ?? '') === strval($narratorName)
        && strval($currentState['player'] ?? '') === strval($playerName)
        && strval($currentState['commenter'] ?? ($currentState['narrator'] ?? '')) === strval($resolvedCommenter)
    ) {
        $currentState['_request_created'] = false;
        return $currentState;
    }

    $state = [
        'title' => $normalizedTitle,
        'rowid' => null,
        'narrator' => $narratorName,
        'player' => $playerName,
        'commenter' => $resolvedCommenter,
        'status' => 'waiting_for_content',
        'requested_form_id' => $normalizedFormId,
        'request_token' => bin2hex(random_bytes(16)),
        'requested_at' => $requestedAt,
        'expires_at' => $requestedAt + BOOK_READ_PENDING_TIMEOUT_SECONDS,
    ];
    if ($titleIsQuery) {
        $state['requested_title_query'] = bookReadNormalizeTitle($bookTitle);
    }

    bookReadStateSet($state);
    $state['_request_created'] = true;
    return $state;
}

/**
 * Persist a bounded request for CHIM to upload an uncached Skyrim book by FormID.
 *
 * @return array The saved waiting state, including its correlation token.
 */
function bookReadStateRequestContent($bookTitle, $formId, $narratorName, $playerName, $commenter = null)
{
    return bookReadStateCreateContentRequest(
        $bookTitle,
        $formId,
        $narratorName,
        $playerName,
        $commenter,
        false
    );
}

/**
 * Persist a bounded request for CHIM to find an uncached book in the reader or player inventory.
 *
 * @return array The saved waiting state, including its correlation token.
 */
function bookReadStateRequestContentByTitle($bookTitle, $narratorName, $playerName, $commenter = null)
{
    return bookReadStateCreateContentRequest(
        $bookTitle,
        null,
        $narratorName,
        $playerName,
        $commenter,
        true
    );
}

/**
 * Persist a bounded request using the player's natural-language book description.
 */
function bookReadStateRequestContentByQuery($bookQuery, $narratorName, $playerName, $commenter = null)
{
    return bookReadStateCreateContentRequest(
        $bookQuery,
        null,
        $narratorName,
        $playerName,
        $commenter,
        true,
        true
    );
}

/**
 * Complete a waiting request only when the upload matches server-owned state.
 */
function bookReadStateAcceptUploadedContent(array $bookCandidate, $formId, $requestToken)
{
    $state = bookReadStateGet();
    if (($state['status'] ?? '') !== 'waiting_for_content') {
        return false;
    }

    if (intval($state['expires_at'] ?? 0) < time()) {
        return false;
    }

    $normalizedFormId = bookReadNormalizeFormId($formId);
    $expectedFormId = bookReadNormalizeFormId($state['requested_form_id'] ?? '');
    if ($normalizedFormId === null || ($expectedFormId !== null && $normalizedFormId !== $expectedFormId)) {
        return false;
    }

    $expectedToken = strval($state['request_token'] ?? '');
    $providedToken = trim(strval($requestToken));
    if ($expectedToken === '' || $providedToken === '' || !hash_equals($expectedToken, $providedToken)) {
        return false;
    }

    $requestedTitleQuery = trim(strval($state['requested_title_query'] ?? ''));
    if ($requestedTitleQuery !== '') {
        if (!bookReadTitleMatchesQuery($bookCandidate['title'] ?? '', $requestedTitleQuery)) {
            return false;
        }
    } else if (
        $expectedFormId === null
        && bookReadNormalizeTitle($bookCandidate['title'] ?? '') !== bookReadNormalizeTitle($state['title'] ?? '')
    ) {
        return false;
    }

    bookReadStateSet(bookReadStateForBook(
        $bookCandidate,
        $state['narrator'] ?? '',
        $state['player'] ?? '',
        $state['commenter'] ?? null
    ));
    return true;
}

/**
 * Handle resuming or replacing a book-reading session from outside code.
 *
 * @param array $bookCandidate Book row with at least 'title' and 'rowid'.
 * @param string $narratorName
 * @param string $playerName
 * @param string $commenter Optional actor that comments on the reading. Defaults to narratorName.
 * @return string 'resumed' if a paused session of the same book was unpaused, 'replaced' otherwise.
 */
function bookReadStateHandleBookAction(array $bookCandidate, $narratorName, $playerName, $commenter = null)
{
    $state = bookReadStateGet();
    $normalizedCandidateTitle = bookReadNormalizeTitle($bookCandidate['title']);

    if (!empty($state['status'])) {
        if ($state['status'] === 'paused' && bookReadNormalizeTitle($state['title'] ?? '') === $normalizedCandidateTitle) {

            if (isset($state['resume_mode']) && $state['resume_mode'] == "stop")
                $state['status'] = 'unpaused';
            else
                $state['status'] = 'resume_requested';


            error_log("[book_read] Queued resume after pending speech for '{$state['title']}' {$state['status']}");
            bookReadStateSet($state);
            return (isset($state['resume_mode']) && $state['resume_mode'] == "stop") ? "unpaused" : "resumed";
        }

        if ($state['status'] === 'done' || $state['status'] === 'paused' || $state['status'] === 'waiting_for_content') {
            bookReadStateSet(bookReadStateForBook($bookCandidate, $narratorName, $playerName, $commenter));
            error_log("[book_read] Replaced reading session with new book '{$bookCandidate['title']}'");
            return 'replaced';
        }

        // Unknown status: log and leave state untouched.
        error_log("[book_read] Unknown book reading status: requested '{$bookCandidate['title']}',obtained $normalizedCandidateTitle  '{$state["status"]}'");
        return 'ignored';
    }

    // No previous state: start fresh.
    bookReadStateSet(bookReadStateForBook($bookCandidate, $narratorName, $playerName, $commenter));
    error_log("[book_read] Started reading session for '{$bookCandidate['title']}'");
    return 'started';
}

/**
 * Check whether a given NPC is currently reading a book.
 *
 * Returns the normalized book title if there is an active reading session
 * (status reading, paused, or unpaused) and the session's narrator matches
 * the provided NPC name. Returns false otherwise.
 * Normalization is applied to both names so in-game decoration is ignored.
 *
 * @param string $npcName
 * @return string|false
 */
function bookReadIsNpcReading($npcName)
{
    $state = bookReadStateGet();
    if (empty($state) || empty($state['status'])) {
        return false;
    }

    $activeStatuses = ['reading', 'paused', 'unpaused', 'resume_requested'];
    if (!in_array($state['status'], $activeStatuses, true) || !bookReadStateHasInitializedSession($state)) {
        return false;
    }

    $sessionNarrator = $state['narrator'] ?? '';
    if (bookReadNormalizeTitle($sessionNarrator) === bookReadNormalizeTitle($npcName)) {
        return $state['title'] ?? false;
    }

    return false;
}



// ─── BookReader class ─────────────────────────────────────────────────────────

class BookReader
{
    private $db;
    private $lastTs;
    private $lastGamets;
    private $narratorName;
    private $playerName;
    private $commenter;
    private $animationsEnabled;
    private $linesPerBatch;
    private $bookReadingVoice;
    private $resumeMode;

    public function __construct($db, $lastTs, $lastGamets, $narratorName, $playerName, $commenter, $animationsEnabled, $linesPerBatch = 8, $bookReadingVoice = true, $mode = 'auto')
    {
        $this->db = $db;
        $this->lastTs = $lastTs;
        $this->lastGamets = $lastGamets;
        $this->narratorName = $narratorName;
        $this->playerName = $playerName;
        $this->commenter = $commenter;
        $this->animationsEnabled = $animationsEnabled;
        $this->linesPerBatch = max(1, intval($linesPerBatch));
        $this->bookReadingVoice = filter_var($bookReadingVoice, FILTER_VALIDATE_BOOLEAN);
        $this->resumeMode = $mode;
    }

    /**
     * Main single-shot entry point for the book-reading worker.
     *
     * The worker is designed to be invoked repeatedly (e.g. from a scheduler or cron loop).
     * Each call performs exactly one incremental task, persists state, and exits:
     *
     *   1. Resolve which book we are working on (CLI arg or existing state).
     *   2. Load persisted state from conf_opts.book_reading_state.
     *   3. Handle CLI switches (--reset-queue, --unpause, --restart).
     *   4. Dequeue any lines already spoken by the game.
     *   5. If paused/done, handle idle behaviour and stop-reading animations.
     *   6. If this is a new book, initialize a session (chunking only, no LLM yet).
     *   7. If enough formatted lines are buffered, enqueue a small batch into the ScriptQueue.
     *   8. Otherwise format the next raw chunk via the LLM.
     *   9. Detect completion and trigger end-of-book behaviour.
     *
     * @param array $argv Standard CLI argument array.
     */
    public function run(array $argv)
    {
        // ── 1. Determine the requested book title ─────────────────────────────
        // If no title is passed on the command line, fall back to the title
        // stored in the active reading session. If neither exists, exit cleanly.
        $requestedTitle = trim((string) ($argv[1] ?? ''));
        if ($requestedTitle === '') {
            $currentReading = $this->db->fetchOne("SELECT value FROM public.conf_opts WHERE id='" . $this->db->escape(BOOK_READ_STATE_KEY) . "' LIMIT 1");
            $currentReading = json_decode($currentReading['value'] ?? '', true);
            if ($currentReading && isset($currentReading['title'])) {
                $requestedTitle = $currentReading['title'];
            }
            if ($requestedTitle === '') {
                error_log("[book_read] No book title provided and no active reading session found. Exiting.");
                exit(0);
            }
        }

        // ── 2. Parse CLI switches ─────────────────────────────────────────────
        $resetQueue = in_array('--reset-queue', $argv, true);
        $unpause = in_array('--unpause', $argv, true);
        $restart = in_array('--restart', $argv, true);

        // ── 3. Load persisted state and decide if we are resuming ─────────────
        $state = $this->loadState();
        if (($state['status'] ?? '') === 'paused' && !bookReadStateHasInitializedSession($state)) {
            $state['status'] = 'done';
            $state['animation_end_done'] = true;
            $this->saveState($state);
            error_log("[book_read] Cleared malformed paused state without initialized book content.");
        }
        if (($state['status'] ?? '') === 'waiting_for_content') {
            $this->handleWaitingForContent($state);
        }
        $resuming = (
            isset($state["status"])
            && isset($state['title'])
            && $this->normalizeTitle($state['title']) === $this->normalizeTitle($requestedTitle)
            && ($state['status'] ?? '') !== 'done'
        );

        // ── 4. Set up narrator / player context ───────────────────────────────
        // Prefer values stored in state so a resumed session keeps the same voices.
        $this->narratorName = isset($state['narrator']) ? $state['narrator'] : $this->narratorName;
        // Default the commenter to the narrator if no explicit commenter is configured.
        $this->commenter = isset($state['commenter']) ? $state['commenter'] : $this->commenter;
        if (empty($this->commenter)) {
            $this->commenter = $this->narratorName;
        }
        $this->setupNarratorProfile($this->narratorName);

        error_log(date("d/m/Y H:i:s") . " [book_read] Using TTS " . $GLOBALS["TTSFUNCTION"] . " voice id " . $GLOBALS["PATCH_OVERRIDE_VOICE"] . "\n");

        // Convert old string-based line arrays into structured line objects once.
        $this->migrateLegacyLines($state);

        $this->playerName = isset($state['player']) ? $state['player'] : $this->playerName;
        $totalChunks = (isset($state['chunks']) ? count($state['chunks']) : 0);
        $allChunksFormatted = isset($state['chunk_position']) ? $state['chunk_position'] >= $totalChunks : 0;
        $bufferedUnqueuedLines = isset($state['lines']) ? $this->countBufferedUnqueued($state['lines']) : 0;

        // ── 5. Handle command-line switches (these exit after saving state) ───
        if ($resetQueue) {
            $this->resetQueue($state, $resuming, $requestedTitle);
        }

        if ($unpause || (isset($state['status']) && $state['status'] === 'unpaused')) {
            $this->unpause($state, $resuming, $requestedTitle);
        }

        if ($restart) {
            $this->restart($state, $requestedTitle);
        }

        // ── 6. Remove already-spoken lines from the in-flight queue ───────────
        $this->dequeueSpoken($state);

        // ── 7. Idle states: paused and done ───────────────────────────────────
        if ($resuming && isset($state['status']) && $state['status'] === 'paused') {
            $this->handlePaused($state);
        }

        if ($resuming && isset($state['status']) && $state['status'] === 'resume_requested') {
            $this->handleResumeRequested($state);
        }

        if (isset($state['status']) && $state['status'] === 'done') {
            $this->handleDone($state);
        }

        // ── 8. New book: create a session and continue into the first enqueue ─
        if (!$resuming) {
            $state = $this->initializeSession($requestedTitle, $state['rowid'] ?? null);
            $totalChunks = count($state['chunks']);
            $allChunksFormatted = $state['chunk_position'] >= $totalChunks;
            $bufferedUnqueuedLines = $this->countBufferedUnqueued($state['lines']);
        }

        // ── 9. Enqueue the next batch of lines if the queue has room ──────────
        // We wait until enough lines are formatted so the worker makes steady
        // progress without calling the LLM for every single line.
        $nextIndex = $this->findNextUnqueuedLineIndex($state['lines']);
        if (
            count($state['queue']) < BOOK_READ_QUEUE_DEPTH
            && $nextIndex !== null
            && ($bufferedUnqueuedLines >= BOOK_READ_MIN_BUFFERED_LINES || $allChunksFormatted)
        ) {
            $this->enqueueLines($state, $totalChunks);
        }

        // ── 11. Check whether the whole book has been read ────────────────────
        $this->finishIfComplete($state, $allChunksFormatted);

        // ── 12. Nothing to do this cycle: queue is full and waiting on speech ─
        if (!empty($state['queue'])) {
            error_log("[book_read] Waiting on utterance {$state['lines'][$state['queue'][0]]['utterance_id']} to be spoken.");
        } else {
            error_log("[book_read] Nothing to do right now.");
        }
    }

    // ─── State persistence ─────────────────────────────────────────────────────

    private function loadState()
    {
        $row = $this->db->fetchOne("SELECT value FROM public.conf_opts WHERE id='" . $this->db->escape(BOOK_READ_STATE_KEY) . "' LIMIT 1");
        if (!$row || empty($row['value'])) {
            return null;
        }
        $decoded = json_decode($row['value'], true);
        return is_array($decoded) ? $decoded : null;
    }

    private function saveState($state)
    {
        $this->db->upsertRowOnConflict('conf_opts', [
            'id' => BOOK_READ_STATE_KEY,
            'value' => json_encode($state),
        ], 'id');
    }

    // ─── Book lookup ───────────────────────────────────────────────────────────

    private function findBook($title, $rowId = null)
    {
        if (intval($rowId) > 0) {
            return $this->db->fetchOne(
                "SELECT * FROM public.books WHERE rowid=" . intval($rowId) . " AND content IS NOT NULL LIMIT 1"
            );
        }

        $escapedTitle = $this->db->escape($title);
        return $this->db->fetchOne(
            "SELECT * FROM public.books WHERE title ILIKE '%{$escapedTitle}%' and content is not null ORDER BY rowid DESC LIMIT 1"
        );
    }

    // Strips decoration characters (e.g. leading '*') so titles can be compared regardless of in-game formatting.
    private function normalizeTitle($title)
    {
        return strtolower(trim(preg_replace('/^[^A-Za-z0-9]+|[^A-Za-z0-9]+$/', '', (string) $title)));
    }

    // Split raw text into ~$size character chunks, prioritizing paragraphs,
    // sentences, and words over the exact character size.
    // [pagebreak] is treated as a paragraph boundary and removed from the result.

    private function chunkText(
        string $text,
        int $size = 100,
        int $minLineLength = 50
    ): array {
        // Normalize line endings.
        $text = str_replace(["\r\n", "\r"], "\n", $text);

        // Drop the transport title prefix; the book's formatted cover follows the first page break.
        $text = preg_replace('/^\s*Title:\s*[^\n]*?\s*\[pagebreak\]\s*/i', '', $text, 1);

        // Remove pagebreak markers.
        $text = preg_replace('/\[pagebreak\]/i', "\n\n", $text);

        // Remove separator lines such as "==".
        $text = preg_replace('/^\s*==\s*$/m', '', $text);

        // Extract book metadata.
        $title = null;
        $author = null;

        if (preg_match('/^Title:\s*(.+)$/mi', $text, $match)) {
            $title = trim($match[1]);
        }

        if (preg_match('/^\s*By\s*\n\s*(.+)$/mi', $text, $match)) {
            $author = trim($match[1]);
        }

        // Remove the title/author cover block from the body.
        if ($title !== null) {
            $coverPattern =
                '/Title:.*?' .
                '(?:\n\s*)+By\s*\n\s*' .
                preg_quote($author ?? '', '/') .
                '\s*/is';

            $text = preg_replace($coverPattern, '', $text, 1);
        }

        // Split the text into paragraphs.
        $paragraphs = preg_split('/\n\s*\n+/', trim($text));

        $lines = [];

        // Add book metadata as the first line.
        if ($title !== null) {
            $header = 'Title: ' . trim($title, " *");

            if ($author !== null) {
                $header .= ', By ' . $author;
            }

            $lines[] = $header;
        }

        foreach ($paragraphs as $paragraph) {

            $paragraph = trim($paragraph);

            if ($paragraph === '') {
                continue;
            }

            // Join artificial line breaks inside paragraphs.
            $paragraph = preg_replace('/\s*\n\s*/', ' ', $paragraph);

            // Normalize whitespace.
            $paragraph = preg_replace('/\s+/', ' ', $paragraph);

            $paragraph = trim($paragraph);

            if ($paragraph === '') {
                continue;
            }

            // Split the paragraph into sentences.
            $sentences = preg_split(
                '/(?<=[.!?])\s+(?=[A-Z"\“\‘])/',
                $paragraph,
                -1,
                PREG_SPLIT_NO_EMPTY
            );

            $current = '';

            foreach ($sentences as $sentence) {

                $sentence = trim($sentence);

                if ($sentence === '') {
                    continue;
                }

                /*
                 * If a single sentence is longer than the target size,
                 * flush the current line and wrap the sentence by words.
                 */
                if (mb_strlen($sentence) > $size) {

                    if ($current !== '') {
                        $lines[] = $current;
                        $current = '';
                    }

                    $wrapped = wordwrap(
                        $sentence,
                        $size,
                        "\n",
                        false
                    );

                    foreach (explode("\n", $wrapped) as $line) {

                        $line = trim($line);

                        if ($line !== '') {
                            $lines[] = $line;
                        }
                    }

                    continue;
                }

                // Start a new line.
                if ($current === '') {
                    $current = $sentence;
                    continue;
                }

                // Try to append the sentence to the current line.
                $candidate = $current . ' ' . $sentence;

                if (mb_strlen($candidate) <= $size) {
                    $current = $candidate;
                } else {
                    $lines[] = $current;
                    $current = $sentence;
                }
            }

            // Flush the last line of the paragraph.
            if ($current !== '') {
                $lines[] = $current;
            }
        }

        /*
         * TTS cleanup strategy.
         *
         * If a line is too short, append it to the previous line,
         * even if this causes the previous line to exceed $size.
         *
         * Example:
         *
         *   Line A ........................................ 82 chars
         *   Short line .................................... 31 chars
         *
         * becomes:
         *
         *   Line A ........................................ 114 chars
         *   (short line removed)
         *
         * This is preferable for TTS because it avoids very short
         * speech segments.
         */
        $result = [];

        foreach ($lines as $line) {

            $line = preg_replace('/\s+/', ' ', trim($line));

            // Ignore empty lines.
            if ($line === '') {
                continue;
            }

            // Ignore separator-only lines.
            if (preg_match('/^(?:==+|--+|__+|\*\*+)\s*$/', $line)) {
                continue;
            }

            /*
             * If this is a short line, merge it into the previous line.
             */
            if (
                !empty($result) &&
                mb_strlen($line) < $minLineLength
            ) {
                $lastIndex = count($result) - 1;

                $result[$lastIndex] .= ' ' . $line;

                continue;
            }

            $result[] = $line;
        }

        /*
         * If the first line is still too short, merge it into
         * the following line.
         *
         * This is mainly useful when the title/metadata is short.
         */
        if (
            count($result) > 1 &&
            mb_strlen($result[0]) < $minLineLength
        ) {
            $result[1] = $result[0] . ' ' . $result[1];
            array_shift($result);
        }

        return array_values(array_filter(
            $result,
            fn($line) => trim($line) !== ''
        ));
    }




    private function getTtsLines(
        string $text,
        int $size = 100,
        int $minLineLength = 50
    ): array {
        /*
         * Try several target sizes and keep the result that produces
         * the best distribution of line lengths.
         *
         * Example:
         *
         * 100 -> 12 short lines
         * 90  ->  7 short lines
         * 80  ->  3 short lines  <-- selected
         * 70  ->  8 short lines
         */
        $sizes = [];

        // Try sizes from the requested size down to 50.
        for ($currentSize = $size; $currentSize >= $minLineLength; $currentSize -= 10) {
            $sizes[] = $currentSize;
        }

        // Make sure the minimum size is always tested.
        if (!in_array($minLineLength, $sizes, true)) {
            $sizes[] = $minLineLength;
        }

        $bestLines = [];
        $bestShortLines = PHP_INT_MAX;
        $bestMinLength = -1;
        $bestTotalLines = PHP_INT_MAX;

        foreach ($sizes as $currentSize) {

            $lines = $this->chunkText($text, $currentSize);

            $shortLines = 0;
            $minimumLength = PHP_INT_MAX;
            $totalLength = 0;
            $lineCount = 0;

            foreach ($lines as $line) {

                $line = trim($line);

                if ($line === '') {
                    continue;
                }

                $length = mb_strlen($line);

                $lineCount++;
                $totalLength += $length;

                if ($length < $minimumLength) {
                    $minimumLength = $length;
                }

                if ($length < $minLineLength) {
                    $shortLines++;
                }
            }

            /*
             * Pick the best result using these priorities:
             *
             * 1. Fewer lines below the minimum length.
             * 2. Better minimum line length.
             * 3. Fewer total lines.
             */
            $isBetter =
                $shortLines < $bestShortLines ||
                (
                    $shortLines === $bestShortLines &&
                    $minimumLength > $bestMinLength
                ) ||
                (
                    $shortLines === $bestShortLines &&
                    $minimumLength === $bestMinLength &&
                    $lineCount < $bestTotalLines
                );

            if ($isBetter) {
                $bestLines = $lines;
                $bestShortLines = $shortLines;
                $bestMinLength = $minimumLength;
                $bestTotalLines = $lineCount;
            }

            /*
             * Perfect result.
             *
             * No line is shorter than the requested minimum,
             * so there is no reason to test more sizes.
             */
            if ($shortLines === 0) {
                break;
            }
        }

        $bestLines[] = "... and well, this is the end of the book ...";

        return array_values(array_filter(
            $bestLines,
            fn($line) => trim($line) !== ''
        ));
    }

    // ─── ScriptQueue helpers ───────────────────────────────────────────────────

    // Calls returnLines() to generate TTS audio for a line, then pushes it to the reader's ScriptQueue.

    private function queueLine($lineText, $bookName)
    {
        $GLOBALS["HERIKA_NAME"] = $this->narratorName;

        // Ensure the TTS filter array is initialized before assigning filters.
        // Some connectors check is_array() and skip processing when it is missing.
        if (!isset($GLOBALS["TTS_FFMPEG_FILTERS"]) || !is_array($GLOBALS["TTS_FFMPEG_FILTERS"])) {
            $GLOBALS["TTS_FFMPEG_FILTERS"] = [];
        }


        if ($this->bookReadingVoice) {
            // Audiobook-style EQ, compression, slight reverb, and speed adjustment.
            $GLOBALS["TTS_FFMPEG_FILTERS"]['highpass'] = 'highpass=f=70';
            $GLOBALS["TTS_FFMPEG_FILTERS"]['lowpass'] = 'lowpass=f=14500';
            $GLOBALS["TTS_FFMPEG_FILTERS"]['warmth'] = 'equalizer=f=120:t=q:w=0.8:g=1.5';
            $GLOBALS["TTS_FFMPEG_FILTERS"]['clarity_cut'] = 'equalizer=f=320:t=q:w=1.0:g=-1.5';
            $GLOBALS["TTS_FFMPEG_FILTERS"]['presence'] = 'equalizer=f=3000:t=q:w=0.9:g=2.0';
            $GLOBALS["TTS_FFMPEG_FILTERS"]['compressor'] = 'acompressor=threshold=-18dB:ratio=2.5:attack=8:release=120:makeup=2';
            $GLOBALS["TTS_FFMPEG_FILTERS"]['aecho'] = 'aecho=1.0:0.92:55:0.16';
            $GLOBALS["TTS_FFMPEG_FILTERS"]['speed'] = 'atempo=0.85';
            $GLOBALS["TTS_FFMPEG_FILTERS"]['loudnorm'] = 'loudnorm=I=-16:TP=-1.5:LRA=7';
            $GLOBALS["TTS_FFMPEG_FILTERS"]['aresample'] = 'aresample=24000';
        }
        $GLOBALS["SCRIPTLINE_ANIMATION_SENT"] = true;  // To avoid returnlines from sending any animation.
        $GLOBALS["AVOID_TTS_CACHE"] = true;
        returnLines([$lineText], true);
        // returnLines can change the line text (e.g. to expand abbreviations), so we capture the final version it returns.
        $output = $GLOBALS["DEBUG_DATA"]["OUTPUT_LOG"];
        $output_parts = explode("|", $output);
        $output_parts_queue = explode("/", $output_parts[2]);

        if (count($output_parts_queue) < 3) {
            Logger::error("[book_read] Failed to generate TTS for line: '{$output}'");
            return null;
        }

        $lineText = trim($output_parts_queue[0]);
        $phoneticText = trim($output_parts_queue[4]);
        error_log("[book_read] Generated TTS for line: '{$lineText}'");

        $utteranceId = $GLOBALS["SCRIPTLINE_UTTERANCE_ID"] ?? chimGenerateUtteranceId();
        $queuedText = "{$lineText}////{$phoneticText}/1/explicit_disable_rechat/{$utteranceId}";

        $this->db->insert('responselog', [
            'localts' => time(),
            'sent' => 0,
            'actor' => $this->narratorName,
            'text' => $queuedText,
            'action' => 'ScriptQueue',
            'tag' => '',
        ]);

        $eventStruct[0] = "chat";
        $eventStruct[1] = $this->lastTs++;
        $eventStruct[2] = $this->lastGamets++;
        $eventStruct[3] = "{$this->narratorName} is reading '{$bookName}': {$lineText}";
        $eventStruct[5] = ["utterance_id" => $utteranceId];
        logEvent($eventStruct, DataBeingsInCloseRange(true, true));

        return $utteranceId;
    }


    private function wasSpoken($utteranceId)
    {
        $row = $this->db->fetchOne(
            "SELECT * FROM public.speech WHERE utterance_id='" . $this->db->escape($utteranceId) . "' LIMIT 1"
        );
        return !empty($row);
    }

    // Helpers for the structured lines array.
    private function findNextUnqueuedLineIndex(array $lines): ?int
    {
        foreach ($lines as $index => $line) {
            if (!($line['enqueued'] ?? false) && !($line['spoken'] ?? false)) {
                return $index;
            }
        }
        return null;
    }

    private function countBufferedUnqueued(array $lines): int
    {
        $count = 0;
        foreach ($lines as $line) {
            if (!($line['enqueued'] ?? false) && !($line['spoken'] ?? false)) {
                $count++;
            }
        }
        return $count;
    }

    private function formatLines(array $textLines): array
    {
        return array_map(fn($text) => [
            'text' => $text,
            'enqueued' => false,
            'spoken' => false,
            'utterance_id' => null,
        ], $textLines);
    }

    // ─── Narrator / profile setup ──────────────────────────────────────────────

    private function setupNarratorProfile($narratorName)
    {
        $configuredNarratorName = function_exists('chimGetNarratorRoleplayName')
            ? chimGetNarratorRoleplayName()
            : Narrator::DEFAULT_ROLEPLAY_NAME;
        $isNarrator = strcasecmp((string) $narratorName, Narrator::CANONICAL_NAME) === 0
            || strcasecmp((string) $narratorName, $configuredNarratorName) === 0;

        if ($isNarrator) {
            $narrator = new Narrator();
            $narratorData = $narrator->getNarratorData();
            $narrator->loadIntoGlobals();

            $profile = new CoreProfile();
            $currentProfileData = $profile->getById($narratorData["profile_id"]);
            $GLOBALS["CHIM_CORE_CURRENT_PROFILE_DATA"] = $currentProfileData;
            $npcMaster = new NpcMaster(); // Still needed for LLMRandomizer compatibility
            $profile->setOldGlobals($currentProfileData);
            $narrator->loadCharacterIntoGlobals();
        } else {
            error_log("[book_read] Using custom narrator '{$narratorName}' instead of configured narrator '{$configuredNarratorName}'.");
            $npcMaster = new NpcMaster();
            $npcData = $npcMaster->getByName($narratorName);

            $profile = new CoreProfile();
            $currentProfileData = $profile->getById($npcData["profile_id"]);
            $GLOBALS["CHIM_CORE_CURRENT_PROFILE_DATA"] = $currentProfileData;

            $GLOBALS["CHIM_CORE_CURRENT_NPC_DATA"] = $npcData;
            $npcMaster->setOldGlobalsFromCurrentNpcData($npcData);

            $profile->setOldGlobals($currentProfileData);
            $npcMaster->setOldGlobalsFromCurrentNpcData($npcData);
        }
    }

    // ─── Legacy migration ──────────────────────────────────────────────────────

    private function migrateLegacyLines(&$state)
    {
        if (empty($state) || empty($state['lines']) || !is_string($state['lines'][0])) {
            return;
        }
        $state['lines'] = array_map(fn($text) => [
            'text' => $text,
            'enqueued' => false,
            'spoken' => false,
            'utterance_id' => null,
        ], $state['lines']);
        foreach ($state['queue'] as $q) {
            foreach ($state['lines'] as &$lineRef) {
                if ($lineRef['text'] === $q['text']) {
                    $lineRef['enqueued'] = true;
                    $lineRef['utterance_id'] = $q['utterance_id'];
                }
            }
            unset($lineRef);
        }
        $this->saveState($state);
    }

    // ─── Command switches ──────────────────────────────────────────────────────

    private function resetQueue(&$state, $resuming, $requestedTitle)
    {
        if (!$resuming) {
            fwrite(STDERR, "No active reading session for '{$requestedTitle}' to reset.\n");
            exit(1);
        }

        foreach ($state['lines'] as &$lineRef) {
            $lineRef['enqueued'] = false;
            $lineRef['spoken'] = false;
            $lineRef['utterance_id'] = null;
        }
        unset($lineRef);
        $state['queue'] = [];
        $state['lines_queued_in_batch'] = 0;
        $this->saveState($state);
        error_log("[book_read] Reset queue for '{$state['title']}'");
        exit(0);
    }

    private function unpause(&$state, $resuming, $requestedTitle)
    {
        if (!$resuming) {
            fwrite(STDERR, "No active reading session for '{$requestedTitle}' to unpause.\n");
            exit(1);
        }


        $state['status'] = 'reading';
        $state['lines_queued_in_batch'] = 0;

        // Defensive: ensure we have a structured lines array to work with.
        if (empty($state['lines']) || !is_array($state['lines'])) {
            $state['queue'] = [];
            $this->saveState($state);
            error_log("[book_read] Unpaused but no lines present for '{$state['title']}'.");
        } else {
            // Normalize any legacy/odd queue shapes to numeric indices that actually exist.
            $normalizedQueue = [];
            if (!empty($state['queue']) && is_array($state['queue'])) {
                foreach ($state['queue'] as $q) {
                    if (is_int($q) || ctype_digit((string) $q)) {
                        $idx = (int) $q;
                        if (isset($state['lines'][$idx])) {
                            $normalizedQueue[] = $idx;
                        }
                    }
                }
            }

            // Find the last line that was marked as spoken so we can rewind it.
            $lastSpoken = null;
            for ($i = count($state['lines']) - 1; $i >= 0; $i--) {
                if (!empty($state['lines'][$i]['spoken'])) {
                    $lastSpoken = $i;
                    break;
                }
            }

            if ($lastSpoken !== null) {
                // Mark the last spoken line as NOT spoken so it will be repeated.
                $state['lines'][$lastSpoken]['spoken'] = false;
                $state['lines'][$lastSpoken]['enqueued'] = false;
                $state['lines'][$lastSpoken]['utterance_id'] = null;

                // Clear any enqueued/spoken flags for later lines to avoid inconsistent state.
                for ($j = $lastSpoken + 1; $j < count($state['lines']); $j++) {
                    $state['lines'][$j]['enqueued'] = false;
                    $state['lines'][$j]['spoken'] = false;
                    $state['lines'][$j]['utterance_id'] = null;
                }

                // Let the normal enqueue logic pick up the rewound line next cycle.
                $state['queue'] = [];

            } elseif (!empty($normalizedQueue)) {
                // No recorded spoken lines: rewind one before the first queued index.
                $firstQueued = $normalizedQueue[0];
                $rewindIdx = max(0, $firstQueued - 1);

                $state['lines'][$rewindIdx]['spoken'] = false;
                $state['lines'][$rewindIdx]['enqueued'] = false;
                $state['lines'][$rewindIdx]['utterance_id'] = null;

                for ($j = $rewindIdx + 1; $j < count($state['lines']); $j++) {
                    $state['lines'][$j]['enqueued'] = false;
                    $state['lines'][$j]['spoken'] = false;
                    $state['lines'][$j]['utterance_id'] = null;
                }
                $state['queue'] = [];

            } else {
                // Nothing to rewind: clear any lingering enqueued markers.
                foreach ($state['lines'] as $idx => &$lineRef) {
                    $lineRef['enqueued'] = false;
                }
                unset($lineRef);
                $state['queue'] = [];
            }
        }

        error_log("[book_read] Unpaused reading session for '{$state['title']}', rewound one line to resume from the last spoken line.");

        $expressions = [
            "I read.",
            "Let me read.",
            "Time to read.",
            "Let's see what this says.",
            "I read ... ahem .. (clears throat)"
        ];

        $expression = $expressions[array_rand($expressions)];
        $GLOBALS["SCRIPTLINE_ANIMATION_SENT"] = true;  // To avoid returnlines from sending any animation.
        returnLines([$expression], true);
        $output = $GLOBALS["DEBUG_DATA"]["OUTPUT_LOG"];
        $output_parts = explode("|", $output);
        $output_parts_queue = explode("/", $output_parts[2]);

        if (count($output_parts_queue) < 3) {
            error_log("[book_read] Failed to generate TTS for line: '{$output}'");
        } else {

            $lineText = trim($output_parts_queue[0]);
            $phoneticText = trim($output_parts_queue[4]);
            $utteranceId = $GLOBALS["SCRIPTLINE_UTTERANCE_ID"] ?? chimGenerateUtteranceId();
            $queuedText = "{$lineText}///IdleStop/{$phoneticText}/1/explicit_disable_rechat/{$utteranceId}";

            $this->db->insert('responselog', [
                'localts' => time(),
                'sent' => 0,
                'actor' => $this->narratorName,
                'text' => $queuedText,
                'action' => 'ScriptQueue',
                'tag' => '',
            ]);
            error_log("[book_read] Directly queued TTS for expression: '{$expression}'");
        }



        $this->saveState($state);

        // Trigger the reading idle animation when resuming
        $this->playReadingAnimation(state: $state);
        //exit(0);
    }

    private function restart(&$state, $requestedTitle)
    {
        $state['status'] = 'reading';
        foreach ($state['lines'] as &$lineRef) {
            $lineRef['enqueued'] = false;
            $lineRef['spoken'] = false;
            $lineRef['utterance_id'] = null;
        }
        unset($lineRef);
        $state['queue'] = [];
        $state['lines_queued_in_batch'] = 0;
        $this->saveState($state);
        error_log("[book_read] Restarted reading session for '{$requestedTitle}'.");

        // Trigger the reading idle animation when resuming
        $this->playReadingAnimation(state: $state);

        exit(0);
    }

    // ─── Main task helpers ─────────────────────────────────────────────────────

    private function dequeueSpoken(&$state)
    {
        if (empty($state)) {
            return;
        }
        $spokenCount = 0;
        $stateChanged = false;
        while (!empty($state['queue']) && $this->wasSpoken($state['lines'][$state['queue'][0]]['utterance_id'] ?? '')) {
            $spokenIndex = array_shift($state['queue']);
            $state['lines'][$spokenIndex]['spoken'] = true;
            $spokenCount++;
            error_log("[book_read] Line spoken, dequeued: '{$state['lines'][$spokenIndex]['text']}'");
        }

        if (isset($state['resume_mode']) && $state['resume_mode'] == "stop" && $state['status'] == "paused") {
            $linesEnqueued = 0;
            $linesSpoken = 0;
            $nextLine = null;
            $totalLines = count($state["lines"]);
            foreach ($state["lines"] as $lineRef) {
                if ($lineRef['enqueued'] ?? false) {
                    $linesEnqueued++;
                }
                if ($lineRef['spoken'] ?? false) {
                    $linesSpoken++;
                }
                if ($nextLine === null && !($lineRef['enqueued'] ?? false) && !($lineRef['spoken'] ?? false)) {
                    $nextLine = $lineRef;
                }
            }
            $nextLineSpoilerText = "";
            if ($nextLine !== null) {
                $nextLineSpoilerText = "(Spoiler alert, next line continues this way: '" . ($nextLine['text'] ?? '') . "')";
                $nextLineSpoilerText = "";
            }

            if ($linesEnqueued - $linesSpoken <= 1 && empty($state['comment_instruction_sent'])) {

                if ($this->commenter == $this->narratorName) {
                    $this->db->insert(
                        'responselog',
                        [
                            'localts' => time(),
                            'sent' => 0,
                            'actor' => "rolemaster",
                            'text' => "",
                            'action' => "rolecommand|Suggestion@{$this->commenter}@Briefly comments on {$this->narratorName}'s reading of '{$state['title']}' without making any spoilers. Short sentence.{$nextLineSpoilerText}@0",
                            'tag' => "",
                        ]
                    );
                } else {

                    $this->db->insert(
                        'responselog',
                        [
                            'localts' => time(),
                            'sent' => 0,
                            'actor' => "rolemaster",
                            'text' => "",
                            'action' => "rolecommand|Suggestion@{$this->commenter}@Briefly comments on reading of '{$state['title']}' - without making any spoilers -. Short sentence.{$nextLineSpoilerText}@0",
                            'tag' => "",
                        ]
                    );
                }


                $this->db->insert('eventlog', [
                    'ts' => $this->lastTs,
                    'gamets' => $this->lastGamets + 1,
                    'type' => 'innerchat',
                    'data' => "{$this->narratorName} has finished reading some pages of the book '{$state['title']}'.(Readed: {$linesSpoken} lines, Total: {$totalLines} lines). Will pause reading and will wait for comments.",
                    'sess' => 0,
                    'localts' => time(),
                    'people' => "|{$this->narratorName}|{$this->playerName}|",
                    'location' => null,
                    'party' => '',
                ]);

                $state['comment_instruction_sent'] = true;
                $stateChanged = true;
            }
        }

        if ($spokenCount > 0 || $stateChanged) {
            $this->saveState($state);
        }
    }

    private function hasPendingPlayback(array $state): bool
    {
        foreach ($state['lines'] ?? [] as $line) {
            if (($line['enqueued'] ?? false) && !($line['spoken'] ?? false)) {
                return true;
            }
        }
        return false;
    }

    private function handlePaused(&$state)
    {
        // Pending playback means any line has been queued for TTS but not yet spoken.
        // We no longer rely on queue indices alone because all lines are created on the
        // first run; a line can be enqueued=true while its index is not in the queue.
        $pendingPlayback = $this->hasPendingPlayback($state);
        error_log(date("d/m/Y H:i:s") . " [book_read] PAUSED\n");

        if (!$pendingPlayback && !($state['animation_end_done'] ?? false)) {
            $this->playStopReadingAnimation($state);
            error_log(date("d/m/Y H:i:s") . "[book_read] Paused session has no pending playback; triggered stop reading animation. reason: pendingPlayback={$pendingPlayback}, animation_end_done={$state['animation_end_done']}");
        }

        if (!$pendingPlayback && (empty($state['comment_instruction_sent']) || $state['comment_instruction_sent'] == false) && !$this->allLinesEnqued()) {

            if (!isset($state['resume_mode']) || $state['resume_mode'] == "auto") {
                $this->db->insert(
                    'responselog',
                    [
                        'localts' => time(),
                        'sent' => 0,
                        'actor' => "rolemaster",
                        'text' => "",
                        'action' => "rolecommand|Instruction@{$this->commenter}@Briefly comment on {$this->narratorName}'s reading of '{$state['title']}', then use the Read_Book action with item '{$state['title']}' so the reading continues. Short sentence.@0",
                        'tag' => "",
                    ]
                );


                $this->db->insert('eventlog', [
                    'ts' => $this->lastTs,
                    'gamets' => $this->lastGamets + 1,
                    'type' => 'innerchat',
                    'data' => "{$this->narratorName} has finished reading some pages of the book '{$state['title']}' and will continue after a brief comment.",
                    'sess' => 0,
                    'localts' => time(),
                    'people' => "|{$this->narratorName}|{$this->playerName}|",
                    'location' => null,
                    'party' => '',
                ]);
                $state['comment_instruction_sent'] = true;
            }


            $this->saveState($state);
            error_log("[book_read] Requested comment after the current batch finished playing.");
        } else if ($pendingPlayback) {
            error_log(date("d/m/Y H:i:s") . "[book_read] Reading of '{$state['title']}' is paused, doing nothing.");
        } else if (empty($state['comment_instruction_sent']) || $state['comment_instruction_sent'] == false) {
            error_log("[book_read] Waiting for the comment action to continue '{$state['title']}'.");
        }

        exit(0);
    }

    /**
     * Return true if all lines in the current saved state have been enqueued or spoken.
     */
    private function allLinesEnqued(): bool
    {
        $state = $this->loadState();
        if (empty($state) || empty($state['lines']) || !is_array($state['lines'])) {
            return false;
        }

        foreach ($state['lines'] as $line) {
            if (!($line['enqueued'] ?? false) && !($line['spoken'] ?? false)) {
                return false;
            }
        }

        return true;
    }

    private function handleResumeRequested(&$state)
    {
        if ($this->hasPendingPlayback($state)) {
            error_log("[book_read] Resume requested for '{$state['title']}', waiting for the current batch to finish.");
            exit(0);
        }

        $state['status'] = 'reading';
        $state['lines_queued_in_batch'] = 0;
        $state['comment_instruction_sent'] = false;
        $this->saveState($state);
        $this->playReadingAnimation($state);
        error_log("[book_read] Continued reading '{$state['title']}' after the comment break.");
    }

    private function handleWaitingForContent(&$state)
    {
        $expiresAt = intval($state['expires_at'] ?? 0);
        if ($expiresAt > time()) {
            error_log("[book_read] Waiting for CHIM to upload '{$state['title']}'.");
            exit(0);
        }

        $state['status'] = 'done';
        $this->saveState($state);

        $safeTitle = trim(preg_replace('/[@|\r\n]+/', ' ', strval($state['title'] ?? 'book')));
        $this->db->insert('responselog', [
            'localts' => time(),
            'sent' => 0,
            'actor' => 'rolemaster',
            'text' => '',
            'action' => "rolecommand|DebugNotification@Could not retrieve book content for {$safeTitle}. Make sure the book is in your inventory or the reader's, then try again.",
            'tag' => '',
        ]);

        error_log("[book_read] Timed out waiting for CHIM to upload '{$safeTitle}'.");
        exit(0);
    }

    private function handleDone(&$state)
    {
        if (!$state['animation_end_done']) {
            $this->playStopReadingAnimation($state);
        }
        error_log("[book_read] Reading of '{$state['title']}' is done, doing nothing.");
        exit(0);
    }

    // ─── Animation helpers ─────────────────────────────────────────────────────

    /**
     * Send a Skyrim PlayIdle/EvaluatePackage command pair for the narrator.
     *
     */
    private function playIdleAnimation($intent)
    {
        if (!$this->animationsEnabled) {
            return;
        }

        $npcMaster = new NpcMaster();
        $npc = $npcMaster->getByName($this->narratorName);
        $when = time();
        $metadata = $npcMaster->getMetadata($npc);
        if ($intent === "stop") {
            $animationFormIdFinal = "0x000e4242";// stop reading animation

        } else if ($intent === "read") {

            if ($metadata["activity_status"]["is_sitting"] ?? false) {
                $animationFormIdFinal = "0x0003505c";// Sitting reading idle animation
            } else {
                // Make NPC wait, so idles can apply properly. This is a workaround for Skyrim's animation system.

                $GLOBALS["db"]->insert(
                    'responselog',
                    array(
                        'localts' => time(),
                        'sent' => 0,
                        'text' => "WaitHere@",
                        'actor' => $this->narratorName,
                        'action' => 'command'
                    )
                );
                $when = time() + 1;// Ensure wait time is respected before playing the idle animation.
                $idlesFormIds = ["0x0002ee6a", "0x000bb053", "0x000bb052"];
                $animationFormId = array_rand($idlesFormIds, 1);
                $animationFormIdFinal = $idlesFormIds[$animationFormId];// reading idle animation

                $skyrimCmd = new SkyrimCommandBuilder();

                $json = $skyrimCmd->Actor->PlayIdle("0x{$npc["refid"]}", "0x000e4242");// Reinit, maybe dialogue triggered animation.
                $skyrimCmd->send(cmd: $json, localts: $when);

                $when += 3;// Arbitrary delay to ensure the reinit idle has time to play before the reading idle.
            }
        }


        $skyrimCmd = new SkyrimCommandBuilder();
        $json = $skyrimCmd->Actor->PlayIdle("0x{$npc["refid"]}", $animationFormIdFinal);
        $skyrimCmd->send(cmd: $json, localts: $when);

        //if ($intent === "stop") {
        $json = $skyrimCmd->Actor->EvaluatePackage("0x{$npc["refid"]}");
        $skyrimCmd->send(cmd: $json, localts: $when);
        //}
    }

    /**
     * Play the reading idle animation when a new line is queued.
     */
    private function playReadingAnimation(&$state)
    {
        $this->playIdleAnimation("read");
        $state['animation_end_done'] = false;
        $this->saveState($state);
    }

    /**
     * Play the stop-reading animation and mark it as done in state.
     *
     * @param array $state Passed by reference; animation_end_done is set to true.
     */
    private function playStopReadingAnimation(&$state)
    {
        $this->playIdleAnimation("stop");
        $state['animation_end_done'] = true;
        $this->saveState($state);
    }

    private function initializeSession($requestedTitle, $requestedRowId = null)
    {
        $book = $this->findBook($requestedTitle, $requestedRowId);
        if (!$book) {
            fwrite(STDERR, "Book not found: {$requestedTitle}\n");
            exit(1);
        }


        $bookTitle = $this->normalizeTitle($book['title']);

        // Since LLM formatting is disabled, split all raw chunks into lines
        // immediately during session initialization. This lets subsequent runs
        // jump straight to enqueuing without any per-chunk LLM calls.
        $allLines = $this->getTtsLines($book['content'], 125, 50);

        $rawChunks = $allLines;
        $state = [
            'title' => $bookTitle,
            'rowid' => $book['rowid'],
            'chunks' => $rawChunks,
            'chunk_position' => count($rawChunks),
            'lines' => $allLines,
            'queue' => [],
            'narrator' => $this->narratorName,
            'player' => $this->playerName,
            'commenter' => $this->commenter,
            'status' => 'reading',
            'lines_queued_in_batch' => 0,
            'animation_end_done' => false,
            'comment_instruction_sent' => false,
            'resume_mode' => $this->resumeMode,
        ];

        error_log("[book_read] Initialized reading session for '{$state['title']}' (" . count($rawChunks) . " chunks, " . count($allLines) . " lines).");
        $this->playReadingAnimation($state);
        $this->saveState($state);
        return $state;
    }

    private function enqueueLines(&$state, $totalChunks)
    {
        $queuedCount = 0;
        while (count($state['queue']) < BOOK_READ_QUEUE_DEPTH) {
            $nextIndex = $this->findNextUnqueuedLineIndex($state['lines']);
            if ($nextIndex === null) {
                break;
            }
            $lineText = $state['lines'][$nextIndex]['text'];
            $utteranceId = $this->queueLine($lineText, $state['title']);
            if ($utteranceId === null) {
                $this->saveState($state);
                error_log("[book_read] TTS generation failed for line " . ($nextIndex + 1) . "; leaving it unqueued for retry.");
                exit(1);
            }
            $state['queue'][] = $nextIndex;
            $state['lines'][$nextIndex]['enqueued'] = true;
            $state['lines'][$nextIndex]['utterance_id'] = $utteranceId;
            $queuedCount++;
            $state['lines_queued_in_batch'] = ($state['lines_queued_in_batch'] ?? 0) + 1;
            error_log("[book_read] Queued line (" . ($nextIndex + 1) . "/" . count($state['lines']) . "): '{$lineText}'");

            $state['animation_end_done'] = false; // Reset the end animation flag so it will be played when the book is finished.

            // Auto-pause after enqueuing the configured batch size so the caller can rest between batches.
            if (($state['lines_queued_in_batch'] ?? 0) >= $this->linesPerBatch) {
                $state['status'] = 'paused';
                $state['comment_instruction_sent'] = false;
                $this->saveState($state);

                error_log("[book_read] Auto-paused after {$state['lines_queued_in_batch']} line(s) queued this batch; waiting for playback before requesting a comment.");
                exit(0);
            }
        }
        $this->saveState($state);
        error_log("[book_read] Queued {$queuedCount} line(s) this run.");
        exit(0);
    }

    private function finishIfComplete(&$state, $allChunksFormatted)
    {
        $allLinesSpoken = count($state['lines']) > 0
            && $this->findNextUnqueuedLineIndex($state['lines']) === null
            && empty($state['queue']);
        if ($allLinesSpoken && $allChunksFormatted) {
            $state['status'] = 'done';
            $this->saveState($state);
            error_log("[book_read] Finished reading '{$state['title']}'.");

            $this->db->insert(
                'responselog',
                [
                    'localts' => time(),
                    'sent' => 0,
                    'actor' => "rolemaster",
                    'text' => "",
                    'action' => "rolecommand|Suggestion@{$this->commenter}@{$this->commenter}\'s reading of '{$state['title']}' ended,{$this->commenter} should comment the narrator's reading, expressing interest and wondering about the plot end@0",
                    'tag' => "",
                ]
            );
            // Stop the reading idle animation when resuming
            $this->playStopReadingAnimation($state);
            exit(0);
        }
    }
}



// ─── Example external caller usage (commented out) ────────────────────────────
/*
require_once 'debug/book_read.php'; // or include the relevant helpers

if ($bookCandidate) {
    $result = bookReadStateHandleBookAction($bookCandidate, $GLOBALS["HERIKA_NAME"], $playerName);
    // $result is one of: 'resumed', 'replaced', 'started', 'ignored'
    unset($actionsCopy[$n]);
}
*/
