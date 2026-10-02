<?php

if (!function_exists('chimCommitmentNormalizeHours')) {
    function chimCommitmentNormalizeHours($value): float
    {
        $hours = is_numeric($value) ? (float)$value : 0.0;
        return max(0.25, min(8760.0, $hours));
    }
}
if (!function_exists('chimCommitmentHoursToGamets')) {
    function chimCommitmentHoursToGamets($hours): int
    {
        return (int)round(chimCommitmentNormalizeHours($hours) / 0.0000024);
    }
}

if (!function_exists('chimCommitmentNormalizeRepeatHours')) {
    function chimCommitmentNormalizeRepeatHours($value): float
    {
        if (!is_numeric($value) || (float)$value <= 0.0) {
            return 0.0;
        }

        return chimCommitmentNormalizeHours($value);
    }
}

if (!function_exists('chimCommitmentNextDueGamets')) {
    function chimCommitmentNextDueGamets(int $dueGamets, int $currentGamets, int $repeatIntervalGamets): int
    {
        if ($repeatIntervalGamets <= 0) {
            return $dueGamets;
        }

        $elapsed = max(0, $currentGamets - $dueGamets);
        $intervals = intdiv($elapsed, $repeatIntervalGamets) + 1;
        return $dueGamets + ($intervals * $repeatIntervalGamets);
    }
}

if (!function_exists('chimCommitmentNormalizeType')) {
    function chimCommitmentNormalizeType($value): string
    {
        $type = strtolower(trim((string)$value));
        return in_array($type, ['meeting', 'message_delivery', 'fetch', 'escort', 'errand', 'other'], true)
            ? $type
            : 'other';
    }
}

if (!function_exists('chimCommitmentParseHoursFromText')) {
    function chimCommitmentParseHoursFromText(string $text, string $prefix): ?float
    {
        $numbers = [
            'one' => 1, 'two' => 2, 'three' => 3, 'four' => 4, 'five' => 5,
            'six' => 6, 'seven' => 7, 'eight' => 8, 'nine' => 9, 'ten' => 10,
            'twelve' => 12, 'twenty-four' => 24,
        ];
        $numberPattern = '(\d+(?:\.\d+)?|one|two|three|four|five|six|seven|eight|nine|ten|twelve|twenty-four)';
        if (!preg_match('/\b' . $prefix . '\s+' . $numberPattern . '\s*(?:in-game\s+)?(hours?|days?)\b/i', $text, $matches)) {
            return null;
        }

        $rawNumber = strtolower($matches[1]);
        $value = is_numeric($rawNumber) ? (float)$rawNumber : (float)($numbers[$rawNumber] ?? 0);
        if (str_starts_with(strtolower($matches[2]), 'day')) {
            $value *= 24;
        }

        return $value > 0 ? chimCommitmentNormalizeHours($value) : null;
    }
}

if (!function_exists('chimCommitmentCleanRequestText')) {
    function chimCommitmentCleanRequestText(string $text): string
    {
        $text = preg_replace('/\s*\(Talking to [^)]*\)\s*$/i', '', trim($text));
        $text = preg_replace('/^[^:\r\n]{1,80}:\s*/', '', (string)$text);
        return trim((string)$text, " \t\n\r\0\x0B\"'");
    }
}

if (!function_exists('chimCommitmentInferSubject')) {
    function chimCommitmentInferSubject(string $requestText, string $dialogueMessage = ''): string
    {
        $requestText = chimCommitmentCleanRequestText($requestText);
        $patterns = [
            '/\b(?:task|duty|job)\s+(?:is\s+)?(?:to\s+)?(.+?)(?=\s*[,.;]?\s*\b(?:every|in|after)\b|$)/i',
            '/\b(?:remember|agree|promise|need|should|must|will)\s+(?:that\s+)?(?:you\s+)?(?:to\s+)?(.+?)(?=\s*[,.;]?\s*\b(?:every|in|after)\b|$)/i',
        ];
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $requestText, $matches)) {
                $subject = trim((string)$matches[1], " \t\n\r\0\x0B,.;:!?\"'");
                if ($subject !== '') {
                    return ucfirst($subject);
                }
            }
        }

        if ($requestText !== '') {
            $subject = preg_replace('/\s*[,.;]?\s*\b(?:every|in|after)\s+(?:\d+(?:\.\d+)?|one|two|three|four|five|six|seven|eight|nine|ten|twelve|twenty-four)\s*(?:in-game\s+)?(?:hours?|days?)\b.*$/i', '', $requestText);
            $subject = preg_replace('/\s*\btomorrow\b.*$/i', '', (string)$subject);
            $subject = trim((string)$subject, " \t\n\r\0\x0B,.;:!?\"'");
            if ($subject !== '') {
                return ucfirst($subject);
            }
        }

        $dialogueMessage = trim(strip_tags($dialogueMessage));
        $dialogueMessage = preg_replace('/\*[^*]*\*/', '', $dialogueMessage);
        return trim((string)$dialogueMessage, " \t\n\r\0\x0B,.;:!?\"'");
    }
}

if (!function_exists('chimCommitmentPrepareCreatePayload')) {
    function chimCommitmentPrepareCreatePayload(array $payload, string $requestText = '', string $dialogueMessage = ''): array
    {
        if (trim((string)($payload['subject'] ?? '')) === '') {
            $payload['subject'] = chimCommitmentInferSubject($requestText, $dialogueMessage);
        }

        if (trim((string)($payload['type'] ?? '')) === '') {
            $haystack = strtolower($requestText . ' ' . ($payload['subject'] ?? ''));
            if (str_contains($haystack, 'meet')) {
                $payload['type'] = 'meeting';
            } elseif (str_contains($haystack, 'message') || str_contains($haystack, 'tell ')) {
                $payload['type'] = 'message_delivery';
            } elseif (str_contains($haystack, 'fetch') || str_contains($haystack, 'bring ') || str_contains($haystack, 'get ')) {
                $payload['type'] = 'fetch';
            } elseif (str_contains($haystack, 'escort')) {
                $payload['type'] = 'escort';
            } elseif (str_contains($haystack, 'errand')) {
                $payload['type'] = 'errand';
            } else {
                $payload['type'] = 'other';
            }
        }

        if (!isset($payload['repeat_every_hours']) || !is_numeric($payload['repeat_every_hours'])) {
            $repeatHours = chimCommitmentParseHoursFromText($requestText, 'every');
            if ($repeatHours !== null) {
                $payload['repeat_every_hours'] = $repeatHours;
            }
        }

        if (!isset($payload['due_in_hours']) || !is_numeric($payload['due_in_hours'])) {
            $dueHours = chimCommitmentParseHoursFromText($requestText, '(?:in|after)');
            if ($dueHours === null && preg_match('/\btomorrow\b/i', $requestText)) {
                $dueHours = 24;
            }
            if ($dueHours === null && is_numeric($payload['repeat_every_hours'] ?? null) && (float)$payload['repeat_every_hours'] > 0) {
                $dueHours = (float)$payload['repeat_every_hours'];
            }
            $payload['due_in_hours'] = $dueHours ?? 24;
        }

        return $payload;
    }
}

if (!function_exists('chimCommitmentOwner')) {
    // Exact task owner: a core_npc_master row (id plus its own canonical key at call time). A bare name is
    // legacy/admin only and addresses only legacy rows never bound to a row (npc_id IS NULL); it never
    // adopts or reaches a row-bound task of a namesake.
    function chimCommitmentOwner($actor): ?array
    {
        if (!is_array($actor) || (int)($actor['id'] ?? 0) <= 0) {
            return null;
        }
        if (!function_exists('chimNpcRowActorKey')) {
            require_once __DIR__ . '/npc_reference.php';
        }
        return [
            'npc_id' => (int)$actor['id'],
            'actor_key' => chimNpcRowActorKey($actor),
            'name' => trim((string)($actor['npc_name'] ?? '')),
        ];
    }
}

if (!function_exists('chimCommitmentOwnerName')) {
    function chimCommitmentOwnerName($actor): string
    {
        return is_array($actor) ? trim((string)($actor['npc_name'] ?? '')) : trim((string)$actor);
    }
}

if (!function_exists('chimCommitmentOwnerSql')) {
    // WHERE fragment applied before any ORDER/LIMIT. $includeLegacy lets the admin view of a row also list
    // unbound legacy same-name tasks (visible, flagged, not adopted).
    function chimCommitmentOwnerSql($actor, bool $includeLegacy = false): ?string
    {
        $db = $GLOBALS['db'];
        $name = chimCommitmentOwnerName($actor);
        $legacy = "(npc_id IS NULL AND lower(actor_name) = lower('" . $db->escape($name) . "'))";
        if (!is_array($actor)) {
            return $name === '' ? null : $legacy;
        }
        $owner = chimCommitmentOwner($actor);
        if ($owner === null) {
            return null;
        }
        // An id-bound task written before actor_key existed (schedules) stays with that id; a recorded key must match.
        $keySql = $owner['actor_key'] === null ? 'actor_key IS NULL'
            : "(actor_key IS NULL OR actor_key = '" . $db->escape($owner['actor_key']) . "')";
        $exact = "(npc_id = {$owner['npc_id']} AND {$keySql})";
        return $includeLegacy && $name !== '' ? "({$exact} OR {$legacy})" : $exact;
    }
}

if (!function_exists('chimCommitmentTimelineEpoch')) {
    // Runtime generation (playthrough switch) plus the live clock load epoch, as _relTimelineEpoch().
    function chimCommitmentTimelineEpoch(): string
    {
        $path = dirname(__DIR__, 2) . '/log/playthrough_runtime/generation';
        clearstatcache(true, $path);
        $generation = is_file($path) ? trim((string)@file_get_contents($path)) : '';
        try {
            $row = $GLOBALS['db']->fetchOne("SELECT value FROM conf_opts WHERE id = 'DYNAMIC_PROFILE_CLOCK'");
            $clock = json_decode((string)($row['value'] ?? ''), true);
            $epoch = is_array($clock) ? (string)($clock['epoch'] ?? '') : '';
        } catch (Throwable $e) {
            $epoch = '';
        }
        return $generation . '|' . $epoch;
    }
}

if (!function_exists('chimCommitmentQueueCreate')) {
    // Counterparty key only from an explicit selection: a valid actor key the caller supplied, or a counterparty
    // written as an exact identifier ("Name [RefID: X]" / ref:/dyn:). Bare or ambiguous names stay key-less.
    function chimCommitmentExplicitCounterpartyKey(array $payload): ?string
    {
        if (!function_exists('chimIsActorKey') || !class_exists('NpcMaster')) { return null; }
        $master = new NpcMaster();
        $given = $payload['counterparty_key'] ?? null;
        try {
            if (is_string($given) && chimIsActorKey($given)) {
                $row = $master->getByActorKey($given);
                return $row ? chimNpcRowActorKey($row) : null;
            }
            $text = trim((string)($payload['counterparty'] ?? ''));
            if ($text === '' || !preg_match('/(\[RefID:\s*(?:0x)?[0-9a-f]{1,8}\]\s*$)|^(ref|dyn):/i', $text)) { return null; }
            $row = $master->getByPromptIdentifier($text);
            return is_array($row) && !empty($row['id']) ? chimNpcRowActorKey($row) : null;
        } catch (Throwable $e) {
            return null;
        }
    }

    function chimCommitmentQueueCreate($actor, array $payload, int $currentGamets, string $requestText = ''): array
    {
        if (!isset($GLOBALS['db']) || !is_object($GLOBALS['db'])) {
            return ['ok' => false, 'error' => 'database_unavailable'];
        }

        $actorName = chimCommitmentOwnerName($actor);
        $owner = chimCommitmentOwner($actor);
        if ($actorName === '' || $owner === null) {
            return ['ok' => false, 'error' => 'actor_required'];
        }

        $queueId = 'npc_commitment_queue_' . time() . '_' . uniqid('', true);
        $queueData = [
            'actor_name' => $actorName,
            // Exact owner, binding and timeline frozen at enqueue; the worker rechecks them after the LLM.
            'npc_id' => $owner['npc_id'],
            'actor_key' => $owner['actor_key'],
            'binding' => function_exists('chimNpcProfileBinding') ? chimNpcProfileBinding($actor) : null,
            'timeline' => chimCommitmentTimelineEpoch(),
            'counterparty_key' => chimCommitmentExplicitCounterpartyKey($payload),
            'payload' => $payload,
            'request_text' => $requestText,
            'current_gamets' => max(0, $currentGamets),
            'queued_at' => time(),
        ];
        $encoded = json_encode($queueData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($encoded === false) {
            return ['ok' => false, 'error' => 'queue_encoding_failed'];
        }

        $ok = $GLOBALS['db']->upsertRowOnConflict('conf_opts', [
            'id' => $queueId,
            'value' => $encoded,
        ], 'id');

        return [
            'ok' => $ok !== false,
            'queue_id' => $ok !== false ? $queueId : null,
            'error' => $ok !== false ? null : 'queue_write_failed',
        ];
    }
}

if (!function_exists('chimCommitmentDbReady')) {
    function chimCommitmentDbReady(): bool
    {
        static $ready = null;
        if ($ready !== null) {
            return $ready;
        }

        if (!isset($GLOBALS['db']) || !is_object($GLOBALS['db'])) {
            return false;
        }

        try {
            $row = $GLOBALS['db']->fetchOne("SELECT to_regclass('public.npc_commitments') AS table_name");
            $ready = !empty($row['table_name']);
        } catch (Throwable $e) {
            $ready = false;
        }

        return $ready;
    }
}

if (!function_exists('chimCommitmentCreate')) {
    function chimCommitmentCreate($actor, array $payload, int $currentGamets): array
    {
        if (!chimCommitmentDbReady()) {
            return ['ok' => false, 'error' => 'commitment_storage_unavailable'];
        }

        $actorName = chimCommitmentOwnerName($actor);
        $owner = chimCommitmentOwner($actor);
        $subject = trim((string)($payload['subject'] ?? ''));
        if ($actorName === '' || $subject === '') {
            return ['ok' => false, 'error' => 'actor_and_subject_required'];
        }

        $type = chimCommitmentNormalizeType($payload['type'] ?? 'other');
        $counterparty = trim((string)($payload['counterparty'] ?? ''));
        $location = trim((string)($payload['location'] ?? ''));
        $hours = chimCommitmentNormalizeHours($payload['due_in_hours'] ?? 24);
        $dueGamets = max(1, $currentGamets) + chimCommitmentHoursToGamets($hours);
        $repeatHours = chimCommitmentNormalizeRepeatHours($payload['repeat_every_hours'] ?? 0);
        $repeatIntervalGamets = $repeatHours > 0
            ? chimCommitmentHoursToGamets($repeatHours)
            : 0;

        $db = $GLOBALS['db'];
        $actorSql = $db->escape($actorName);
        $typeSql = $db->escape($type);
        $subjectSql = $db->escape($subject);
        $counterpartySql = $db->escape($counterparty);
        $locationSql = $db->escape($location);
        $payloadSql = $db->escape(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        // Row-bound owner; a counterparty key only when the caller resolved it explicitly. Legacy name-only
        // creation stays unbound (NULLs), never guessed.
        $npcIdSql = $owner === null ? 'NULL' : (string)$owner['npc_id'];
        $actorKeySql = $owner === null || $owner['actor_key'] === null ? 'NULL' : "'" . $db->escape($owner['actor_key']) . "'";
        $counterpartyKey = $payload['counterparty_key'] ?? null;
        $counterpartyKeySql = is_string($counterpartyKey) && function_exists('chimIsActorKey') && chimIsActorKey($counterpartyKey)
            ? "'" . $db->escape($counterpartyKey) . "'" : 'NULL';

        $row = $db->fetchOne("
            INSERT INTO public.npc_commitments
                (actor_name, npc_id, actor_key, commitment_type, subject, counterparty, counterparty_key, location_name, status,
                 created_gamets, due_gamets, repeat_interval_gamets, payload_json, updated_at)
            VALUES
                ('{$actorSql}', {$npcIdSql}, {$actorKeySql}, '{$typeSql}', '{$subjectSql}', '{$counterpartySql}', {$counterpartyKeySql}, '{$locationSql}',
                 'scheduled', {$currentGamets}, {$dueGamets}, {$repeatIntervalGamets}, '{$payloadSql}'::jsonb, NOW())
            RETURNING id, due_gamets, repeat_interval_gamets
        ");

        return [
            'ok' => !empty($row['id']),
            'id' => isset($row['id']) ? (int)$row['id'] : null,
            'due_gamets' => isset($row['due_gamets']) ? (int)$row['due_gamets'] : $dueGamets,
            'hours' => $hours,
            'repeat_every_hours' => $repeatHours,
        ];
    }
}

if (!function_exists('chimCommitmentSetStatus')) {
    function chimCommitmentSetStatus($actor, int $commitmentId, string $status, string $outcome, int $currentGamets): array
    {
        if (!chimCommitmentDbReady()) {
            return ['ok' => false, 'error' => 'commitment_storage_unavailable'];
        }

        $allowed = ['completed', 'failed', 'cancelled'];
        $status = strtolower(trim($status));
        if (!in_array($status, $allowed, true) || $commitmentId <= 0) {
            return ['ok' => false, 'error' => 'invalid_status_or_id'];
        }

        $db = $GLOBALS['db'];
        $ownerSql = chimCommitmentOwnerSql($actor);
        if ($ownerSql === null) {
            return ['ok' => false, 'error' => 'task_not_found_or_not_owned'];
        }
        $outcomeSql = $db->escape(trim($outcome));
        $active = $db->fetchOne("
            SELECT id, due_gamets, repeat_interval_gamets, occurrence_count
              FROM public.npc_commitments
             WHERE id = {$commitmentId}
               AND {$ownerSql}
               AND status IN ('scheduled', 'due')
             LIMIT 1
        ");
        if (empty($active['id'])) {
            return ['ok' => false, 'error' => 'task_not_found_or_not_owned'];
        }

        $expectedDue = (int)$active['due_gamets'];
        $expectedCount = (int)$active['occurrence_count'];
        $repeatIntervalGamets = (int)($active['repeat_interval_gamets'] ?? 0);
        $isRepeating = $repeatIntervalGamets > 0 && $status !== 'cancelled';
        if ($isRepeating) {
            $nextDueGamets = chimCommitmentNextDueGamets(
                (int)$active['due_gamets'],
                $currentGamets,
                $repeatIntervalGamets
            );
            $row = $db->fetchOne("
                UPDATE public.npc_commitments
                   SET status = 'scheduled',
                       outcome = '{$outcomeSql}',
                       last_resolved_gamets = {$currentGamets},
                       resolved_gamets = NULL,
                       occurrence_count = occurrence_count + 1,
                       due_gamets = {$nextDueGamets},
                       updated_at = NOW()
                 WHERE id = {$commitmentId}
                   AND {$ownerSql}
                   AND status IN ('scheduled', 'due')
                   AND due_gamets = {$expectedDue} AND occurrence_count = {$expectedCount}
                RETURNING id, due_gamets, occurrence_count
            ");

            return [
                'ok' => !empty($row['id']),
                'id' => isset($row['id']) ? (int)$row['id'] : null,
                'repeated' => !empty($row['id']),
                'next_due_gamets' => isset($row['due_gamets']) ? (int)$row['due_gamets'] : $nextDueGamets,
                'occurrence_count' => isset($row['occurrence_count'])
                    ? (int)$row['occurrence_count']
                    : ((int)($active['occurrence_count'] ?? 0) + 1),
            ];
        }

        $statusSql = $db->escape($status);
        $occurrenceIncrement = $status === 'cancelled' ? 0 : 1;
        $row = $db->fetchOne("
            UPDATE public.npc_commitments
               SET status = '{$statusSql}',
                   outcome = '{$outcomeSql}',
                   last_resolved_gamets = {$currentGamets},
                   resolved_gamets = {$currentGamets},
                   occurrence_count = occurrence_count + {$occurrenceIncrement},
                   updated_at = NOW()
             WHERE id = {$commitmentId}
               AND {$ownerSql}
               AND status IN ('scheduled', 'due')
               AND due_gamets = {$expectedDue} AND occurrence_count = {$expectedCount}
            RETURNING id
        ");

        return [
            'ok' => !empty($row['id']),
            'id' => isset($row['id']) ? (int)$row['id'] : null,
            'repeated' => false,
        ];
    }
}

if (!function_exists('chimCommitmentGetActive')) {
    function chimCommitmentGetActive($actor, int $currentGamets, int $limit = 8, bool $includeLegacy = false): array
    {
        if (!chimCommitmentDbReady() || chimCommitmentOwnerName($actor) === '') {
            return [];
        }

        $db = $GLOBALS['db'];
        $ownerSql = chimCommitmentOwnerSql($actor, $includeLegacy);
        if ($ownerSql === null) {
            return [];
        }
        $limit = max(1, min(20, $limit));
        $currentGamets = max(0, $currentGamets);

        $db->execQuery("
            UPDATE public.npc_commitments
               SET status = 'due', updated_at = NOW()
             WHERE {$ownerSql}
               AND status = 'scheduled'
               AND due_gamets <= {$currentGamets}
        ");

        return $db->fetchAll("
            SELECT id, commitment_type, subject, counterparty, location_name, status,
                   created_gamets, due_gamets, repeat_interval_gamets, occurrence_count,
                   last_resolved_gamets, payload_json
              FROM public.npc_commitments
             WHERE {$ownerSql}
               AND status IN ('scheduled', 'due')
             ORDER BY CASE WHEN status = 'due' THEN 0 ELSE 1 END, due_gamets ASC, id ASC
             LIMIT {$limit}
        ");
    }
}

if (!function_exists('chimCommitmentGetAll')) {
    function chimCommitmentGetAll($actor, int $limit = 100, bool $includeLegacy = false): array
    {
        if (!chimCommitmentDbReady() || chimCommitmentOwnerName($actor) === '') {
            return [];
        }

        $db = $GLOBALS['db'];
        $ownerSql = chimCommitmentOwnerSql($actor, $includeLegacy);
        if ($ownerSql === null) {
            return [];
        }
        $limit = max(1, min(250, $limit));
        return $db->fetchAll("
            SELECT id, commitment_type, subject, counterparty, location_name, status,
                   created_gamets, due_gamets, repeat_interval_gamets, occurrence_count,
                   last_resolved_gamets, resolved_gamets, outcome, created_at, updated_at,
                   npc_id, actor_key, counterparty_key, (npc_id IS NULL) AS legacy_unassigned
              FROM public.npc_commitments
             WHERE {$ownerSql}
             ORDER BY CASE WHEN status = 'due' THEN 0
                           WHEN status = 'scheduled' THEN 1
                           ELSE 2 END,
                      due_gamets ASC, id DESC
             LIMIT {$limit}
        ");
    }
}

if (!function_exists('chimCommitmentFormatContext')) {
    function chimCommitmentFormatContext($actor, int $currentGamets): string
    {
        $actorName = chimCommitmentOwnerName($actor);
        $rows = chimCommitmentGetActive($actor, $currentGamets);
        if (empty($rows)) {
            return '';
        }

        $lines = [];
        foreach ($rows as $row) {
            $status = strtolower((string)($row['status'] ?? 'scheduled'));
            $dueGamets = (int)($row['due_gamets'] ?? 0);
            $hours = ($dueGamets - $currentGamets) * 0.0000024;
            $timing = $status === 'due'
                ? 'DUE NOW'
                : 'due in about ' . max(1, (int)round($hours)) . ' in-game hour(s)';
            $details = [];
            $taskPayload = json_decode((string)($row['payload_json'] ?? '{}'), true);
            if (!empty($taskPayload['schedule_issue'])) $details[] = 'Schedule not active; ask for clarification: ' . $taskPayload['schedule_issue'];
            if (!empty($row['counterparty'])) {
                $details[] = 'with ' . trim((string)$row['counterparty']);
            }
            if (!empty($row['location_name'])) {
                $details[] = 'at ' . trim((string)$row['location_name']);
            }
            $repeatIntervalGamets = (int)($row['repeat_interval_gamets'] ?? 0);
            if ($repeatIntervalGamets > 0) {
                $repeatHours = max(0.25, $repeatIntervalGamets * 0.0000024);
                $details[] = 'repeats every about ' . round($repeatHours, 2) . ' in-game hour(s)';
                $details[] = 'resolved ' . (int)($row['occurrence_count'] ?? 0) . ' time(s)';
            }
            $suffix = empty($details) ? '' : ' (' . implode(', ', $details) . ')';
            $lines[] = sprintf(
                '#%d [%s, %s] %s%s',
                (int)$row['id'],
                str_replace('_', ' ', (string)$row['commitment_type']),
                $timing,
                trim((string)$row['subject']),
                $suffix
            );
        }

        return "<tasks>\n# ACTIVE TASKS FOR {$actorName}\n"
            . "These tasks persist across conversations. Due tasks should be acted on using available actions, then resolved. Repeating tasks automatically advance to their next scheduled occurrence when resolved.\n## "
            . implode("\n## ", $lines)
            . "\n</tasks>";
    }
}
