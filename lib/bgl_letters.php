<?php

/*
 * Two-way courier correspondence between the player and Background Life NPCs.
 *
 * bgl_letters is the single record of every letter in both directions:
 *   to_npc    : written by the player in the Prisma panel. A spawned courier walks to the
 *               player, takes the letter and a fee, and after a travel delay the letter lands
 *               in the NPC's inventory and memory.
 *               status: awaiting_courier -> in_transit -> delivered | failed
 *   to_player : written by the NPC through Background Life and sent with the vanilla courier.
 *               status: sent -> read
 *
 * Meet-up awareness comes from chimLetterBuildCorrespondenceBlock(), which main.php injects
 * into the NPC's character section until the letters have been discussed in person.
 */

require_once __DIR__ . DIRECTORY_SEPARATOR . 'utils_game_timestamp.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'logger.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'background_life_requests.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'scriptproxy_papyrus.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'npc_master.class.php';

if (!defined('CHIM_LETTER_GAMETS_PER_HOUR')) {
    define('CHIM_LETTER_GAMETS_PER_HOUR', 1 / 0.0000024);
}
if (!defined('CHIM_LETTER_MAX_BODY')) {
    define('CHIM_LETTER_MAX_BODY', 2000);
}

// ─── Settings ────────────────────────────────────────────────────────────────

function chimLetterDelayHours(): float
{
    $hours = (float)($GLOBALS['BGL_LETTER_DELAY_HOURS'] ?? 6);
    return $hours > 0 ? $hours : 6.0;
}

function chimLetterFee(): int
{
    return max(0, (int)($GLOBALS['BGL_LETTER_FEE'] ?? 10));
}

// Real seconds a courier may take to spawn or reach the player before the fallback runs.
function chimLetterCourierTimeout(): int
{
    return max(30, (int)($GLOBALS['BGL_LETTER_COURIER_TIMEOUT'] ?? 180));
}

// Distinct from the vanilla courier ("Courier") so CHIM never confuses the two actors.
function chimLetterCourierName(): string
{
    $name = trim((string)($GLOBALS['BGL_LETTER_COURIER_NAME'] ?? 'Letter Carrier'));
    $name = chimLetterWireSafe($name);
    return $name !== '' ? $name : 'Letter Carrier';
}

// ─── Small helpers ───────────────────────────────────────────────────────────

// Command fields are split on @ and | by the plugin, and BackgroundCmd arguments on /.
function chimLetterWireSafe(string $value): string
{
    $value = str_replace(['@', '|', '/', "\r", "\n"], ['', '', '-', ' ', ' '], $value);
    return trim(preg_replace('/\s+/u', ' ', $value) ?? '');
}

function chimLetterPlayerName(): string
{
    return trim((string)($GLOBALS['PLAYER_NAME'] ?? 'Traveler')) ?: 'Traveler';
}

function chimLetterNowGamets(): int
{
    return (int)DataLastKnownGameTS();
}

function chimLetterNowTs(): int
{
    return (int)DataLastKnownTS();
}

function chimLetterTableReady(): bool
{
    static $ready = null;
    if ($ready === true) {
        return true;
    }
    $row = $GLOBALS['db']->fetchOne("SELECT to_regclass('public.bgl_letters') AS t");
    $ready = !empty($row['t']);
    if (!$ready) {
        Logger::warn('[BGL_LETTERS] bgl_letters table is missing; run the database update.');
    }
    return $ready;
}

function chimLetterQueueCommand(string $action): void
{
    $GLOBALS['db']->insert('responselog', [
        'localts' => time(),
        'sent' => 0,
        'actor' => 'rolemaster',
        'text' => '',
        'action' => $action,
        'tag' => '',
    ]);
}

function chimLetterNotify(string $message): void
{
    $message = chimLetterWireSafe($message);
    if ($message !== '') {
        chimLetterQueueCommand("rolecommand|DebugNotification@{$message}");
    }
}

function chimLetterSignedRefId(string $refid): int
{
    $value = hexdec(preg_replace('/[^0-9A-Fa-f]/', '', $refid) ?: '0');
    if ($value >= 0x80000000) {
        $value -= 0x100000000;
    }
    return (int)$value;
}

function chimLetterGetById(int $id): array
{
    if ($id <= 0) {
        return [];
    }
    return $GLOBALS['db']->fetchOne('SELECT * FROM bgl_letters WHERE id = $1', [$id]) ?: [];
}

function chimLetterUpdate(int $id, array $data): bool
{
    if ($id <= 0 || !$data) {
        return false;
    }
    return (bool)$GLOBALS['db']->updateRow('bgl_letters', $data, 'id = ' . (int)$id);
}

// A title unique among letters and books, because note images are keyed by title hash.
function chimLetterUniqueTitle(string $base): string
{
    $base = chimLetterWireSafe($base);
    $title = $base;
    for ($n = 2; $n < 50; $n++) {
        $taken = $GLOBALS['db']->fetchOne(
            'SELECT 1 AS x FROM bgl_letters WHERE lower(title) = lower($1)
             UNION ALL SELECT 1 FROM books WHERE lower(title) = lower($1) LIMIT 1',
            [$title]
        );
        if (!$taken) {
            return $title;
        }
        $title = "{$base} {$n}";
    }
    return $base . ' ' . substr(md5((string)microtime(true)), 0, 6);
}

function chimLetterHistory(string $npcName, string $category, string $data): void
{
    $GLOBALS['db']->insert('bgl_history', [
        'npc' => $npcName,
        'ts' => chimLetterNowTs(),
        'gamets' => chimLetterNowGamets(),
        'localts' => time(),
        'data' => $data,
        'category' => $category,
    ]);
}

// ─── Player -> NPC ───────────────────────────────────────────────────────────

/**
 * Queue a letter from the player to an enrolled Background Life NPC.
 * The courier state machine in service/processors/letters picks it up.
 */
function chimLetterSendFromPlayer(NpcMaster $npcMaster, string $refid, string $npcName, string $body, int $replyTo = 0): array
{
    if (!chimLetterTableReady()) {
        throw new RuntimeException('Letters are not available yet. Run the database update.');
    }

    $body = trim(str_replace("\r\n", "\n", $body));
    $length = mb_strlen($body, 'UTF-8');
    if ($length === 0) {
        throw new InvalidArgumentException('The letter is empty.');
    }
    if ($length > CHIM_LETTER_MAX_BODY) {
        throw new InvalidArgumentException('The letter is longer than ' . CHIM_LETTER_MAX_BODY . ' characters.');
    }

    $npc = chimBglResolveNpc($npcMaster, $refid, $npcName);
    if (!$npc) {
        throw new DomainException('NPC has not been discovered by CHIM.');
    }
    $status = chimBglNpcStatus($npcMaster, $npc, $refid, $npcName);
    if (!$status['background_life_enabled']) {
        throw new DomainException('Enable Background Life for this NPC before writing to them.');
    }
    $npcName = $status['name'];

    $pending = $GLOBALS['db']->fetchOne(
        "SELECT id FROM bgl_letters WHERE direction = 'to_npc' AND status = 'awaiting_courier' AND lower(npc_name) = lower($1) LIMIT 1",
        [$npcName]
    );
    if ($pending) {
        throw new DomainException("A letter to {$npcName} is still waiting for the courier.");
    }

    if ($replyTo > 0) {
        $original = chimLetterGetById($replyTo);
        if (!$original || $original['direction'] !== 'to_player' || strcasecmp($original['npc_name'], $npcName) !== 0) {
            throw new InvalidArgumentException('The letter being answered does not belong to this NPC.');
        }
    }

    $gamets = chimLetterNowGamets();
    $player = chimLetterPlayerName();
    $title = chimLetterUniqueTitle("A letter from {$player} to {$npcName} (" . convert_gamets2skyrim_long_date($gamets) . ')');

    $id = $GLOBALS['db']->insertReturningId('bgl_letters', [
        'npc_name' => $npcName,
        'npc_refid' => $status['refid'],
        'direction' => 'to_npc',
        'title' => $title,
        'body' => $body,
        'in_reply_to' => $replyTo > 0 ? $replyTo : null,
        'status' => 'awaiting_courier',
        'courier_state' => 'queued',
        'fee' => chimLetterFee(),
        'sent_gamets' => $gamets,
        'localts' => time(),
        'state_changed_localts' => time(),
    ], 'id');
    if (!$id) {
        throw new RuntimeException('Could not save the letter.');
    }

    chimLetterHistory($npcName, 'letter_out', "{$player} writes a letter to {$npcName}");
    chimLetterNotify("Your letter to {$npcName} is sealed. A courier is on the way.");

    return chimLetterGetById((int)$id);
}

/**
 * Hand the letter over: take the fee, start the travel timer.
 * $viaCourier is false when the courier never arrived and the fallback runs.
 */
function chimLetterCollect(array $letter, bool $viaCourier): void
{
    $fee = (int)($letter['fee'] ?? 0);
    if ($fee > 0) {
        $builder = new SkyrimCommandBuilder();
        // 0x14 is the player, 0xF is gold. Skyrim removes what the player has if they are short.
        $builder->send($builder->ObjectReference->RemoveItem('0x00000014', '0x0000000F', $fee, true));
    }

    $npcName = $letter['npc_name'];
    $feeText = $fee > 0 ? " for {$fee} gold" : '';
    chimLetterNotify($viaCourier
        ? "The courier takes your letter to {$npcName}{$feeText}."
        : "A courier collected your letter to {$npcName}{$feeText}.");

    chimLetterUpdate((int)$letter['id'], [
        'status' => 'in_transit',
        'deliver_gamets' => chimLetterNowGamets() + (int)round(chimLetterDelayHours() * CHIM_LETTER_GAMETS_PER_HOUR),
    ]);
}

/**
 * The letter reaches the NPC: a physical note in their inventory, a history row they will see
 * in dialogue, a long-term memory, and a nudge so Background Life gives them a chance to answer.
 */
function chimLetterDeliver(NpcMaster $npcMaster, array $letter): bool
{
    $npcName = $letter['npc_name'];
    $player = chimLetterPlayerName();
    $body = (string)$letter['body'];
    $gamets = chimLetterNowGamets();
    $ts = chimLetterNowTs();

    $npc = $npcMaster->getByName($npcName);
    $refid = trim((string)($npc['refid'] ?? $letter['npc_refid'] ?? ''));
    if ($refid !== '') {
        chimLetterPlaceInInventory((string)$letter['title'], $body, chimLetterSignedRefId($refid));
    }

    $text = "The Narrator:{$npcName} received a letter from {$player}, delivered by courier. "
        . "{$player} was not present; this was written, not spoken.\n<letter_content>\n{$body}\n</letter_content>";
    $GLOBALS['db']->insert('eventlog', [
        'ts' => $ts,
        'gamets' => $gamets,
        'type' => 'innerchat',
        'data' => $text,
        'sess' => (string)time(),
        'localts' => time(),
        'people' => $npcName,
        'location' => '',
        'party' => '',
    ]);

    if (function_exists('logMemory')) {
        logMemory($player, $npcName, "{$player} sent {$npcName} a letter by courier: {$body}", time(), $gamets, 'letter_received', $ts);
    }

    chimLetterHistory($npcName, 'letter_in', "{$npcName} receives a letter from {$player}");

    chimLetterUpdate((int)$letter['id'], ['status' => 'delivered', 'deliver_gamets' => $gamets]);

    // Nudge: make this NPC due for a Background Life pass so they can decide to write back.
    if ($npc) {
        $extended = $npcMaster->getExtendedData($npc);
        if (chimBglBoolean($extended['background_life_letters'] ?? false)) {
            $extended['background_life_last_updated'] = 0;
            $npcMaster->updateExtendedKeysByName($npcName, $extended);
        }
    }

    return true;
}

/**
 * Put a readable letter in an actor's inventory. This is the tail of SkCreateItem without the
 * LLM step: render the note image, spawn the note, have the plugin download the image, and store
 * the text so book.php can resolve it when read.
 */
function chimLetterPlaceInInventory(string $title, string $body, int $actorRefId): void
{
    if (function_exists('createLetter')) {
        ob_start();
        try {
            createLetter($title, $body);
        } finally {
            ob_end_clean();
        }
    }
    $taskId = substr(md5($title), 0, 8);
    chimLetterQueueCommand("rolecommand|spawnBook@{$title}@0@{$actorRefId}@{$taskId}@{$title}");
    chimLetterQueueCommand("rolecommand|generateLetter@{$title}");

    $GLOBALS['db']->insert('books', [
        'ts' => 0,
        'gamets' => 0,
        'content' => $body,
        'sess' => 'generated',
        'localts' => time(),
        'title' => $title,
    ]);
}

// ─── NPC -> player ───────────────────────────────────────────────────────────

/**
 * Record a Background Life letter to the player. Called by both runners after the vanilla
 * courier has been queued. Returns the new id, or 0 when the table is not ready.
 */
function chimLetterRecordToPlayer(string $npcName, string $refid, string $title, string $body, int $replyTo = 0): int
{
    if (!chimLetterTableReady() || trim($body) === '') {
        return 0;
    }
    $id = $GLOBALS['db']->insertReturningId('bgl_letters', [
        'npc_name' => $npcName,
        'npc_refid' => $refid,
        'direction' => 'to_player',
        'title' => $title,
        'body' => trim($body),
        'in_reply_to' => $replyTo > 0 ? $replyTo : null,
        'status' => 'sent',
        'sent_gamets' => chimLetterNowGamets(),
        'localts' => time(),
        'state_changed_localts' => time(),
    ], 'id');
    return (int)$id;
}

// A player letter counts as answered once the NPC has written back after receiving it. Every NPC
// letter prompt includes the unanswered player letters, so any later NPC letter was written with them in view.
function chimLetterAnsweredSql(string $alias): string
{
    return "EXISTS (SELECT 1 FROM bgl_letters r
                    WHERE r.direction = 'to_player' AND lower(r.npc_name) = lower({$alias}.npc_name)
                      AND (r.in_reply_to = {$alias}.id OR r.sent_gamets >= {$alias}.deliver_gamets))";
}

/** Player letters this NPC has received but not answered, oldest first. */
function chimLetterUnansweredFromPlayer(string $npcName, int $limit = 3): array
{
    if (!chimLetterTableReady()) {
        return [];
    }
    $name = $GLOBALS['db']->escape($npcName);
    $limit = max(1, $limit);
    return $GLOBALS['db']->fetchAll(
        "SELECT l.* FROM bgl_letters l
         WHERE l.direction = 'to_npc' AND l.status = 'delivered' AND lower(l.npc_name) = lower('{$name}')
           AND NOT " . chimLetterAnsweredSql('l') . "
         ORDER BY l.deliver_gamets ASC, l.id ASC
         LIMIT {$limit}"
    ) ?: [];
}

/** Prompt block listing unanswered player letters, for the Background Life runners. */
function chimLetterUnansweredPromptBlock(array $letters): string
{
    if (!$letters) {
        return '';
    }
    $player = chimLetterPlayerName();
    $out = "<letters_from_player>\n";
    foreach ($letters as $letter) {
        $date = convert_gamets2skyrim_long_date((int)($letter['deliver_gamets'] ?? $letter['sent_gamets'] ?? 0));
        $out .= "Letter from {$player}, received {$date}:\n" . trim((string)$letter['body']) . "\n\n";
    }
    return $out . "</letters_from_player>\n";
}

// ─── Read-back ───────────────────────────────────────────────────────────────

/** Called from book.php. Marks an NPC letter read and tells the sender. */
function chimLetterMarkReadByTitle(string $title, int $gamets, int $ts): void
{
    if (!chimLetterTableReady()) {
        return;
    }
    $letter = $GLOBALS['db']->fetchOne(
        "SELECT * FROM bgl_letters WHERE direction = 'to_player' AND status = 'sent' AND lower(title) = lower($1) ORDER BY id DESC LIMIT 1",
        [trim($title)]
    );
    if (!$letter) {
        return;
    }
    chimLetterUpdate((int)$letter['id'], ['status' => 'read', 'read_gamets' => $gamets]);

    $player = chimLetterPlayerName();
    $GLOBALS['db']->insert('eventlog', [
        'ts' => $ts,
        'gamets' => $gamets,
        'type' => 'infoaction',
        'data' => "{$player} received and read the letter {$letter['npc_name']} sent by courier.",
        'sess' => (string)time(),
        'localts' => time(),
        'people' => $letter['npc_name'],
        'location' => '',
        'party' => '',
    ]);
}

// ─── Meet-up awareness ───────────────────────────────────────────────────────

/**
 * Correspondence the NPC knows about and has not yet talked through in person.
 * With $markDiscussed, the letters count as discussed from now on; the block stays for one
 * in-game hour so the whole conversation keeps it, then history and memory take over.
 */
function chimLetterBuildCorrespondenceBlock(string $npcName, bool $markDiscussed): string
{
    $npcName = trim($npcName);
    if ($npcName === '' || strcasecmp($npcName, 'The Narrator') === 0 || !chimLetterTableReady()) {
        return '';
    }

    $now = chimLetterNowGamets();
    $window = (int)round(CHIM_LETTER_GAMETS_PER_HOUR);
    $name = $GLOBALS['db']->escape($npcName);
    $rows = $GLOBALS['db']->fetchAll(
        "SELECT * FROM bgl_letters
         WHERE lower(npc_name) = lower('{$name}')
           AND ((direction = 'to_npc' AND status = 'delivered') OR (direction = 'to_player' AND status IN ('sent', 'read')))
           AND (discussed_gamets IS NULL OR discussed_gamets > " . ($now - $window) . ")
         ORDER BY COALESCE(deliver_gamets, sent_gamets) DESC, id DESC
         LIMIT 6"
    ) ?: [];
    if (!$rows) {
        return '';
    }

    $player = chimLetterPlayerName();
    $lines = [];
    foreach (array_reverse($rows) as $row) {
        $date = convert_gamets2skyrim_long_date((int)($row['deliver_gamets'] ?? $row['sent_gamets'] ?? 0));
        if ($row['direction'] === 'to_npc') {
            $head = "Letter from {$player} to {$npcName}, received {$date}:";
        } else {
            $state = $row['status'] === 'read'
                ? "{$player} has received and read it"
                : "{$npcName} does not know yet whether it arrived";
            $head = "Letter from {$npcName} to {$player}, sent {$date} ({$state}):";
        }
        $lines[] = $head . "\n" . trim((string)$row['body']);
    }

    if ($markDiscussed) {
        $ids = implode(',', array_map(fn($r) => (int)$r['id'], $rows));
        $GLOBALS['db']->execQuery("UPDATE bgl_letters SET discussed_gamets = {$now} WHERE discussed_gamets IS NULL AND id IN ({$ids})");
    }

    $text = htmlspecialchars(implode("\n\n", $lines), ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8');
    return "\n<letter_correspondence>\n"
        . "{$npcName} and {$player} exchanged these letters by courier. They were written, not spoken, "
        . "and have not yet been talked through face to face. {$npcName} remembers them and may bring them up.\n\n"
        . "{$text}\n</letter_correspondence>\n";
}

// ─── Courier state machine ───────────────────────────────────────────────────

function chimLetterMaxEventRowId(): int
{
    $row = $GLOBALS['db']->fetchOne('SELECT COALESCE(MAX(rowid), 0) AS r FROM eventlog');
    return (int)($row['r'] ?? 0);
}

// True when the plugin reported $needle (a status_msg prefix, or a nearby actor) after $sinceRowId.
function chimLetterEventSeen(string $type, string $needle, int $sinceRowId): bool
{
    $row = $GLOBALS['db']->fetchOne(
        'SELECT 1 AS x FROM eventlog WHERE rowid > $1 AND type = $2 AND position($3 in data) > 0 LIMIT 1',
        [$sinceRowId, $type, $needle]
    );
    return !empty($row);
}

function chimLetterSetCourierState(array $letter, string $state, array $extra = []): void
{
    chimLetterUpdate((int)$letter['id'], array_merge([
        'courier_state' => $state,
        'state_changed_localts' => time(),
    ], $extra));
}

function chimLetterSpawnCourier(array $letter): void
{
    $name = chimLetterCourierName();
    $races = ['nord', 'imperial', 'breton'];
    $genders = ['male', 'female'];
    $marker = chimLetterMaxEventRowId();

    $queued = function_exists('npcProfileBase')
        ? npcProfileBase($name, 'merchant', $races[array_rand($races)], $genders[array_rand($genders)], 'nearby', '0')
        : false;

    if (!$queued) {
        Logger::warn("[BGL_LETTERS] Could not queue courier spawn for letter {$letter['id']}; using fallback");
        chimLetterCollect($letter, false);
        chimLetterSetCourierState($letter, 'done');
        return;
    }
    chimLetterSetCourierState($letter, 'spawn_requested', ['courier_name' => $name, 'courier_event_rowid' => $marker]);
}

// Give the spawned courier a small profile and make sure it is friendly, then walk to the player.
function chimLetterSendCourierToPlayer(NpcMaster $npcMaster, array $letter): bool
{
    $name = $letter['courier_name'];
    $courier = $npcMaster->getByName($name);
    $refid = trim((string)($courier['refid'] ?? ''));
    if (!$courier || $refid === '') {
        return false; // Registered on a later tick.
    }

    $player = chimLetterPlayerName();
    $courier['core'] = "{$name}. A courier who carries letters across Skyrim for a small fee.";
    $courier['npc_static_bio'] = "{$name} is a courier. They collect sealed letters from travelers and deliver them anywhere in Skyrim.";
    $courier['speechstyle'] = 'Brisk, polite and practical, like someone with many more letters to deliver today.';
    $courier['goals'] = "Collect a sealed letter from {$player} addressed to {$letter['npc_name']}, take the courier fee, promise delivery, then leave. Never fight.";
    $npcMaster->updateByArray($courier);

    $builder = new SkyrimCommandBuilder();
    $builder->send($builder->Actor->RemoveFromAllFactions("0x{$refid}"));
    $builder->send($builder->Actor->AddToFaction("0x{$refid}", '0x0001dd09')); // WEPlayerFriend
    $builder->send($builder->Actor->SetFactionRank("0x{$refid}", '0x0001dd09', 1));

    $marker = chimLetterMaxEventRowId();
    chimLetterQueueCommand("rolecommand|moveToPlayer@{$name}@letter{$letter['id']}@7");
    chimLetterSetCourierState($letter, 'approaching', ['courier_event_rowid' => $marker]);
    return true;
}

function chimLetterDismissCourier(array $letter): void
{
    $name = (string)($letter['courier_name'] ?? '');
    if ($name !== '') {
        chimLetterQueueCommand("rolecommand|Despawn@{$name}@0");
    }
}

/** One step of the courier pipeline plus due deliveries. Called every service tick. */
function chimLetterCourierTick(NpcMaster $npcMaster): void
{
    if (!chimLetterTableReady()) {
        return;
    }

    $timeout = chimLetterCourierTimeout();
    $now = time();

    // Only one courier is ever in the world.
    $active = $GLOBALS['db']->fetchOne(
        "SELECT * FROM bgl_letters WHERE courier_state IN ('spawn_requested', 'approaching', 'departing') ORDER BY id ASC LIMIT 1"
    );

    if ($active) {
        $state = $active['courier_state'];
        $age = $now - (int)$active['state_changed_localts'];
        $name = (string)$active['courier_name'];
        $marker = (int)($active['courier_event_rowid'] ?? 0);

        if ($state === 'spawn_requested') {
            if (chimLetterEventSeen('status_msg', "spawned@{$name}@", $marker)) {
                chimLetterSendCourierToPlayer($npcMaster, $active);
            } elseif ($age > $timeout) {
                Logger::warn("[BGL_LETTERS] Courier did not spawn for letter {$active['id']}; using fallback");
                chimLetterCollect($active, false);
                chimLetterSetCourierState($active, 'done');
            }
        } elseif ($state === 'approaching') {
            $arrived = chimLetterEventSeen('status_msg', "reached_destination_player@{$name}", $marker)
                || chimLetterEventSeen('infonpc_close', $name, $marker);
            if ($arrived) {
                $player = chimLetterWireSafe(chimLetterPlayerName());
                $fee = (int)$active['fee'];
                $feeText = $fee > 0 ? " and the {$fee} gold fee" : '';
                chimLetterQueueCommand(
                    "rolecommand|Instruction@{$name}@Greet {$player} briefly, take the sealed letter for "
                    . chimLetterWireSafe($active['npc_name']) . "{$feeText}, promise it will be delivered, then say farewell.@0"
                );
                chimLetterCollect($active, true);
                chimLetterSetCourierState($active, 'departing');
            } elseif ($age > $timeout) {
                Logger::warn("[BGL_LETTERS] Courier never reached the player for letter {$active['id']}; using fallback");
                chimLetterCollect($active, false);
                chimLetterDismissCourier($active);
                chimLetterSetCourierState($active, 'done');
            }
        } elseif ($state === 'departing' && $age > 40) {
            // Leave time for the farewell line before the courier vanishes.
            chimLetterDismissCourier($active);
            chimLetterSetCourierState($active, 'done');
        }
    } else {
        $next = $GLOBALS['db']->fetchOne(
            "SELECT * FROM bgl_letters WHERE status = 'awaiting_courier' AND courier_state = 'queued' ORDER BY id ASC LIMIT 1"
        );
        if ($next) {
            chimLetterSpawnCourier($next);
        }
    }

    // Deliveries whose travel time has passed.
    $nowGamets = chimLetterNowGamets();
    $due = $GLOBALS['db']->fetchAll(
        "SELECT * FROM bgl_letters WHERE direction = 'to_npc' AND status = 'in_transit' AND deliver_gamets <= {$nowGamets} ORDER BY id ASC LIMIT 5"
    ) ?: [];
    foreach ($due as $letter) {
        try {
            chimLetterDeliver($npcMaster, $letter);
        } catch (Throwable $e) {
            Logger::error("[BGL_LETTERS] Delivery failed for letter {$letter['id']}: " . $e->getMessage());
            chimLetterUpdate((int)$letter['id'], ['status' => 'failed']);
        }
    }
}

// ─── Panel listing ───────────────────────────────────────────────────────────

/** Both directions for one NPC, newest first, shaped for the Prisma panel. */
function chimLetterThread(string $npcName, int $limit = 50): array
{
    if (!chimLetterTableReady()) {
        return [];
    }
    $name = $GLOBALS['db']->escape($npcName);
    $limit = max(1, min(200, $limit));
    $rows = $GLOBALS['db']->fetchAll(
        "SELECT l.*, (l.direction = 'to_npc' AND l.status = 'delivered' AND " . chimLetterAnsweredSql('l') . ") AS answered
         FROM bgl_letters l WHERE lower(l.npc_name) = lower('{$name}')
         ORDER BY l.id DESC LIMIT {$limit}"
    ) ?: [];

    return array_map(function ($row) {
        $when = (int)($row['deliver_gamets'] ?? 0) ?: (int)($row['sent_gamets'] ?? 0);
        return [
            'id' => (int)$row['id'],
            'direction' => $row['direction'],
            'title' => $row['title'],
            'body' => $row['body'],
            'status' => $row['status'],
            'in_reply_to' => $row['in_reply_to'] !== null ? (int)$row['in_reply_to'] : null,
            'answered' => chimBglBoolean($row['answered'] ?? false),
            'fee' => (int)($row['fee'] ?? 0),
            'tamrielic_time' => $when > 0 ? convert_gamets2skyrim_long_date($when) : '',
        ];
    }, $rows);
}
