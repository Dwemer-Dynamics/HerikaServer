<?php

function chimPhysicalDiaryEscape($value)
{
    return $GLOBALS['db']->escape(trim((string)$value));
}

function chimPhysicalDiaryTitle($npcName)
{
    return trim((string)$npcName) . "'s Diary";
}

function chimPhysicalDiarySettingEnabled($metadata)
{
    if (is_string($metadata)) {
        $metadata = json_decode($metadata, true);
    }
    if (!is_array($metadata)) {
        return false;
    }

    $value = $metadata['MATERIALIZE_DIARY_ENABLED'] ?? false;
    if (is_bool($value)) {
        return $value;
    }
    if (is_int($value) || is_float($value)) {
        return (int)$value === 1;
    }

    return in_array(strtolower(trim((string)$value)), ['1', 'true', 'yes', 'on'], true);
}

// Exact physical row context. Only a keyed core_npc_master row resolves; the typed narrator and
// name-only callers never do (no namesake lookup). A physical NPC named "The Narrator" stays physical.
function chimPhysicalDiaryResolveNpcContext($actorRow)
{
    if (!is_array($actorRow) || (int)($actorRow['id'] ?? 0) <= 0) {
        return [];
    }
    require_once __DIR__ . DIRECTORY_SEPARATOR . 'npc_reference.php';
    if (!class_exists('NpcMaster')) {
        require_once __DIR__ . DIRECTORY_SEPARATOR . 'npc_master.class.php';
    }
    if (!class_exists('CoreProfile')) {
        require_once __DIR__ . DIRECTORY_SEPARATOR . 'core_profiles.class.php';
    }

    $npcData = (new NpcMaster())->getById((int)$actorRow['id']);
    $authorKey = is_array($npcData) ? chimNpcRowActorKey($npcData) : null;
    if ($authorKey === null || $authorKey !== chimNpcRowActorKey($actorRow)) {
        return [];
    }
    $profileId = (int)($npcData['profile_id'] ?? 0);
    $profileData = $profileId > 0 ? (new CoreProfile())->getById($profileId) : [];
    if (empty($profileData)) {
        return [];
    }

    return [
        'author_key' => $authorKey,
        'name' => (string)($npcData['npc_name'] ?? ''),
        'refid' => $npcData['refid'] ?? '',
        'profile_metadata' => $profileData['metadata'] ?? '{}',
    ];
}

function chimPhysicalDiaryBookKey($authorKey)
{
    return 'diary:' . $authorKey;
}

// Optional 6th spawnBook argument (native C9b): identity travels beside the title, never inside it.
function chimPhysicalDiaryBookIdentity($authorKey, $recipientKey)
{
    return [
        'identity_version' => 1,
        'book_key' => chimPhysicalDiaryBookKey($authorKey),
        'author_key' => $authorKey,
        'recipient_key' => $recipientKey,
    ];
}

function chimPhysicalDiaryRefIdToSignedInt($refId)
{
    $value = trim((string)$refId);
    if ($value === '') {
        return null;
    }

    $value = preg_replace('/^0x/i', '', $value);
    if (!ctype_xdigit($value)) {
        return null;
    }

    $unsigned = hexdec($value) & 0xFFFFFFFF;
    if ($unsigned >= 0x80000000) {
        $unsigned -= 0x100000000;
    }

    return (int)$unsigned;
}

// Entries written by exactly this physical author. Legacy unassigned rows and namesakes never match.
function chimPhysicalDiaryEntries($authorKey, $limit = 5)
{
    require_once __DIR__ . DIRECTORY_SEPARATOR . 'npc_reference.php';
    if (!chimIsActorKey($authorKey)) {
        return [];
    }
    $safeKey = chimPhysicalDiaryEscape($authorKey);
    $limit = max(1, min(10, (int)$limit));
    $rows = $GLOBALS['db']->fetchAll(
        "SELECT rowid, topic, content, location, gamets, localts
         FROM diarylog
         WHERE author_key = '{$safeKey}'
         ORDER BY gamets DESC, rowid DESC
         LIMIT {$limit}"
    );

    return array_reverse(is_array($rows) ? $rows : []);
}

function chimPhysicalDiaryContent(array $entries, $maxCharacters = 1800)
{
    $sections = [];
    foreach ($entries as $entry) {
        $headingParts = [];
        $topic = trim((string)($entry['topic'] ?? ''));
        $location = trim((string)($entry['location'] ?? ''));
        if ($topic !== '') {
            $headingParts[] = $topic;
        }
        if ($location !== '' && stripos($topic, $location) === false) {
            $headingParts[] = $location;
        }

        $content = trim((string)($entry['content'] ?? ''));
        if ($content === '') {
            continue;
        }

        $heading = empty($headingParts) ? '' : '[' . implode(' - ', $headingParts) . "]\n";
        $sections[] = $heading . $content;
    }

    $text = trim(implode("\n\n", $sections));
    $maxCharacters = max(200, (int)$maxCharacters);
    if (mb_strlen($text, 'UTF-8') > $maxCharacters) {
        $text = mb_substr($text, -$maxCharacters, null, 'UTF-8');
        $firstBreak = mb_strpos($text, "\n\n", 0, 'UTF-8');
        if ($firstBreak !== false) {
            $text = mb_substr($text, $firstBreak + 2, null, 'UTF-8');
        }
        $text = "Earlier pages omitted.\n\n" . ltrim($text);
    }

    return $text;
}

function chimPhysicalDiaryRender($title, $content, $renderer = null, $resourceKey = null)
{
    if ($renderer === null) {
        require_once __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'rolemaster_helpers.php';
        $renderer = 'createLetter';
    }

    if (!is_callable($renderer)) {
        return false;
    }

    ob_start();
    try {
        call_user_func($renderer, $title, $content, $resourceKey);
    } finally {
        ob_end_clean();
    }
    return true;
}

// Generated diary books are looked up by book_key, never by title: same-named authors stay separate.
function chimPhysicalDiaryStoreBook($title, $content, $gamets, array $identity)
{
    $safeBookKey = chimPhysicalDiaryEscape($identity['book_key']);
    $existing = $GLOBALS['db']->fetchOne(
        "SELECT rowid FROM books WHERE sess = 'physical_diary' AND book_key = '{$safeBookKey}' ORDER BY rowid DESC LIMIT 1"
    );

    if (!empty($existing['rowid'])) {
        $safeContent = chimPhysicalDiaryEscape($content);
        $safeTitle = chimPhysicalDiaryEscape($title);
        $GLOBALS['db']->execQuery(
            "UPDATE books SET content = '{$safeContent}', title = '{$safeTitle}', gamets = " . (int)$gamets . ", localts = " . time() .
            " WHERE rowid = " . (int)$existing['rowid']
        );
        return;
    }

    $GLOBALS['db']->insert('books', [
        'ts' => time(),
        'gamets' => (int)$gamets,
        'content' => $content,
        'sess' => 'physical_diary',
        'localts' => time(),
        'title' => $title,
        'book_key' => $identity['book_key'],
        'author_key' => $identity['author_key'],
        'recipient_key' => $identity['recipient_key'],
    ]);
}

function chimPhysicalDiaryMaterialize($authorKey, $npcName, $refId, $gamets, $renderer = null, $queueSpawn = true)
{
    require_once __DIR__ . DIRECTORY_SEPARATOR . 'npc_reference.php';
    $npcName = trim((string)$npcName);
    if ($npcName === '' || !chimIsActorKey($authorKey)
        || $authorKey === CHIM_ACTOR_KEY_PLAYER || $authorKey === CHIM_ACTOR_KEY_NARRATOR) {
        return ['ok' => false, 'error' => 'missing_npc_reference'];
    }

    $safeKey = chimPhysicalDiaryEscape($authorKey);
    $safeName = chimPhysicalDiaryEscape($npcName);
    $existing = $GLOBALS['db']->fetchOne(
        "SELECT author_key FROM physical_npc_diaries WHERE author_key = '{$safeKey}' LIMIT 1"
    );
    $created = empty($existing);
    $signedRefId = chimPhysicalDiaryRefIdToSignedInt($refId);
    if ($queueSpawn && $signedRefId === null) {
        return ['ok' => false, 'error' => 'missing_npc_reference'];
    }

    $entries = chimPhysicalDiaryEntries($authorKey);
    if (empty($entries)) {
        return ['ok' => false, 'error' => 'no_diary_entries'];
    }

    $title = chimPhysicalDiaryTitle($npcName);
    $content = chimPhysicalDiaryContent($entries);
    if ($content === '' || !chimPhysicalDiaryRender($title, $content, $renderer, chimPhysicalDiaryBookKey($authorKey))) {
        return ['ok' => false, 'error' => 'render_failed'];
    }

    // The author keeps the book: the signed runtime recipient is the author's own reference.
    $identity = chimPhysicalDiaryBookIdentity($authorKey, $authorKey);
    $safeTitle = chimPhysicalDiaryEscape($title);
    $latestLocalts = max(array_map(static fn($entry) => (int)($entry['localts'] ?? 0), $entries));
    $now = time();

    if ($created) {
        $inserted = $GLOBALS['db']->fetchOne(
            "INSERT INTO physical_npc_diaries (npc_name, author_key, title, last_diary_localts, created_at, updated_at)
             VALUES ('{$safeName}', '{$safeKey}', '{$safeTitle}', {$latestLocalts}, {$now}, {$now})
             ON CONFLICT (author_key) WHERE author_key IS NOT NULL DO NOTHING
             RETURNING author_key"
        );
        $created = !empty($inserted);
    }
    if (!$created) {
        $GLOBALS['db']->execQuery(
            "UPDATE physical_npc_diaries
             SET npc_name = '{$safeName}', title = '{$safeTitle}', last_diary_localts = {$latestLocalts}, updated_at = {$now}
             WHERE author_key = '{$safeKey}'"
        );
    }

    chimPhysicalDiaryStoreBook($title, $content, $gamets, $identity);

    if ($queueSpawn) {
        $taskId = str_replace('@', '', (string)($GLOBALS['taskId'] ?? '0'));
        $safeCommandTitle = str_replace('@', '', $title);
        $encodedContent = base64_encode($content);
        $encodedIdentity = base64_encode(json_encode($identity, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $GLOBALS['db']->insert('responselog', [
            'localts' => $now,
            'sent' => 0,
            'actor' => 'rolemaster',
            'text' => '',
            'action' => "rolecommand|spawnBook@{$safeCommandTitle}@0@{$signedRefId}@{$taskId}@b64:{$encodedContent}@b64:{$encodedIdentity}",
            'tag' => '',
        ]);
    }

    return [
        'ok' => true,
        'created' => $created,
        'title' => $title,
        'book_key' => $identity['book_key'],
        'entry_count' => count($entries),
    ];
}

// $actorRow is the exact physical row captured before generation; names alone never resolve an author.
function chimPhysicalDiarySyncForNpc($actorRow, $gamets, $renderer = null, $contextResolver = null)
{
    $label = is_array($actorRow) ? (string)($actorRow['npc_name'] ?? '') : trim((string)$actorRow);
    try {
        $context = is_callable($contextResolver)
            ? call_user_func($contextResolver, $actorRow)
            : chimPhysicalDiaryResolveNpcContext($actorRow);
        if (!is_array($context) || !chimPhysicalDiarySettingEnabled($context['profile_metadata'] ?? null)) {
            return ['ok' => true, 'enabled' => false];
        }
        if (empty($context['author_key'])) {
            return ['ok' => false, 'enabled' => true, 'error' => 'missing_author_key'];
        }

        $result = chimPhysicalDiaryMaterialize(
            $context['author_key'],
            $context['name'] ?? $label,
            $context['refid'] ?? '',
            (int)$gamets,
            $renderer
        );
        $result['enabled'] = true;
        return $result;
    } catch (Throwable $e) {
        if (class_exists('Logger')) {
            Logger::warn('Physical diary sync failed for ' . $label . ': ' . $e->getMessage());
        }
        return ['ok' => false, 'enabled' => true, 'error' => 'sync_failed'];
    }
}

// Diary read scope for the speaking actor: its own physical key plus explicitly linked profile members
// (raw authors are never rewritten). The typed narrator reads only narrator-authored entries. Name-only,
// unkeyed and legacy unassigned rows never match a runtime reader.
function chimDiaryReadKeys(string $npcName, $actorRow = null): array
{
    require_once __DIR__ . DIRECTORY_SEPARATOR . 'npc_reference.php';
    if ($actorRow === CHIM_ACTOR_KEY_NARRATOR || $actorRow === CHIM_ACTOR_KEY_PLAYER) {
        return [$actorRow];
    }
    if ($actorRow === null && function_exists('chimMemoryContextActorRow')) {
        $actorRow = chimMemoryContextActorRow($npcName);
    }
    if (is_array($actorRow) && chimNpcRowActorKey($actorRow) !== null) {
        if (!function_exists('chimNpcProfileActorKeys')) {
            require_once __DIR__ . DIRECTORY_SEPARATOR . 'npc_profile_sharing.php';
        }
        // Current link state of the same physical row; a re-keyed row reads only its captured key.
        $current = $GLOBALS['db']->fetchOne('SELECT * FROM core_npc_master WHERE id = ' . (int)($actorRow['id'] ?? 0));
        if (!is_array($current) || chimNpcRowActorKey($current) !== chimNpcRowActorKey($actorRow)) {
            return [chimNpcRowActorKey($actorRow)];
        }
        return chimNpcProfileActorKeys($current);
    }
    $narrator = class_exists('Narrator') ? Narrator::CANONICAL_NAME : 'The Narrator';
    $current = $GLOBALS['CHIM_CORE_CURRENT_NPC_DATA'] ?? null;
    // Only the typed narrator turn (no physical row in context) maps to the narrator principal.
    if (strcasecmp(trim($npcName), $narrator) === 0 && !(is_array($current) && (int)($current['id'] ?? 0) > 1)) {
        return [CHIM_ACTOR_KEY_NARRATOR];
    }
    return [];
}

function chimDiaryAuthorKeysWhereClause(array $keys, string $column = 'author_key'): string
{
    $quoted = [];
    foreach ($keys as $key) {
        if (chimIsActorKey($key)) { $quoted[$key] = "'" . $GLOBALS['db']->escape($key) . "'"; }
    }
    return $quoted ? "$column IN (" . implode(',', $quoted) . ')' : 'FALSE';
}
