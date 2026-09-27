<?php

require_once(__DIR__ . DIRECTORY_SEPARATOR . 'npc_commitments.php');

if (!function_exists('chimCommitmentExtractJsonObject')) {
    function chimCommitmentExtractJsonObject(string $text): ?array
    {
        $text = trim($text);
        $text = preg_replace('/^```(?:json)?\s*/i', '', $text);
        $text = preg_replace('/\s*```$/', '', (string)$text);
        $start = strpos((string)$text, '{');
        $end = strrpos((string)$text, '}');
        if ($start === false || $end === false || $end < $start) {
            return null;
        }

        $decoded = json_decode(substr((string)$text, $start, $end - $start + 1), true);
        return is_array($decoded) ? $decoded : null;
    }
}

if (!function_exists('chimCommitmentFilterExtractedPayload')) {
    function chimCommitmentFilterExtractedPayload(array $payload): array
    {
        $allowed = ['type', 'subject', 'counterparty', 'location', 'due_in_hours', 'repeat_every_hours'];
        return array_intersect_key($payload, array_fill_keys($allowed, true));
    }
}

if (!function_exists('chimCommitmentNotificationText')) {
    function chimCommitmentNotificationText(string $actorName, string $subject): string
    {
        $clean = static function (string $value, int $limit): string {
            $value = str_replace(["\r", "\n", '|', '@'], ' ', $value);
            $value = trim((string)preg_replace('/\s+/', ' ', $value));
            return function_exists('mb_substr') ? mb_substr($value, 0, $limit) : substr($value, 0, $limit);
        };

        $actorName = $clean($actorName, 80);
        $subject = $clean($subject, 160);
        if ($actorName === '') {
            $actorName = 'NPC';
        }
        if ($subject === '') {
            $subject = 'Untitled task';
        }

        return "Task created for {$actorName}: {$subject}";
    }
}

if (!function_exists('chimCommitmentQueueCreatedNotification')) {
    function chimCommitmentQueueCreatedNotification(string $actorName, string $subject): bool
    {
        return $GLOBALS['db']->insertReturningId('responselog', [
            'localts' => time(),
            'sent' => 0,
            'actor' => 'rolemaster',
            'text' => '',
            'action' => 'rolecommand|DebugNotification@' . chimCommitmentNotificationText($actorName, $subject),
            'tag' => '',
        ], 'rowid') > 0;
    }
}

if (!function_exists('chimCommitmentExtractWithLlm')) {
    function chimCommitmentExtractWithLlm(array $job): ?array
    {
        require_once(__DIR__ . DIRECTORY_SEPARATOR . 'npc_master.class.php');
        require_once(__DIR__ . DIRECTORY_SEPARATOR . 'core_profiles.class.php');
        require_once(__DIR__ . DIRECTORY_SEPARATOR . 'llm_connector.class.php');

        $actorName = trim((string)($job['actor_name'] ?? ''));
        $npcData = (new NpcMaster())->getByName($actorName);
        if (empty($npcData['profile_id'])) {
            return null;
        }

        $profileData = (new CoreProfile())->getById((int)$npcData['profile_id']);
        if (!is_array($profileData)) {
            return null;
        }

        $connectorId = (int)($profileData['llm_formatter_id'] ?? 0);
        if ($connectorId <= 0) {
            $connectorId = (int)($profileData['llm_primary_id'] ?? 0);
        }
        if ($connectorId <= 0) {
            return null;
        }

        $connector = new LLMConnector();
        $connectorData = $connector->getById($connectorId);
        if (!is_array($connectorData)) {
            return null;
        }

        $connector->setOldGlobals($connectorData);
        $GLOBALS['CHIM_CORE_CURRENT_CONNECTOR_DATA'] = $connectorData;
        unset($GLOBALS['CHIM_CORE_CURRENT_CONNECTOR_DATA']['stop']);
        $handler = $connector->getConnector($connectorData);

        $partial = chimCommitmentFilterExtractedPayload(
            is_array($job['payload'] ?? null) ? $job['payload'] : []
        );
        $context = [
            [
                'role' => 'system',
                'content' => 'Convert an accepted NPC duty into one task record. Return only a JSON object with exactly these keys: type, subject, counterparty, location, due_in_hours, repeat_every_hours. type must be meeting, message_delivery, fetch, escort, errand, or other. subject must be a short concrete action. Times are in-game hours. Use an empty string for unknown counterparty/location and 0 for no recurrence. Do not add commentary.',
            ],
            [
                'role' => 'user',
                'content' => json_encode([
                    'npc' => $actorName,
                    'current_game_time_days' => ((int)($job['current_gamets'] ?? 0)) / 10000000,
                    'request' => chimCommitmentCleanRequestText((string)($job['request_text'] ?? '')),
                    'partial_task' => $partial,
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ],
        ];

        $buffer = $handler->fast_request($context, ['MAX_TOKENS' => 300], 'profile');
        return chimCommitmentExtractJsonObject((string)$buffer);
    }
}

if (!function_exists('chimCommitmentProcessQueue')) {
    function chimCommitmentProcessQueue(int $limit = 10, ?callable $extractor = null): array
    {
        $db = $GLOBALS['db'];
        $result = ['locked' => false, 'jobs' => 0, 'created' => 0, 'failed' => 0];
        $lockRows = $db->fetchAll("SELECT pg_try_advisory_lock(hashtext('herika_npc_commitment_worker')) AS acquired");
        $acquired = !empty($lockRows) && in_array($lockRows[0]['acquired'] ?? false, [true, 1, '1', 't', 'true'], true);
        if (!$acquired) {
            return $result;
        }

        $result['locked'] = true;
        $extractor = $extractor ?? 'chimCommitmentExtractWithLlm';
        $limit = max(1, min(50, $limit));
        try {
            $rows = $db->fetchAll("SELECT id, value FROM conf_opts WHERE id LIKE 'npc_commitment_queue_%' ORDER BY id LIMIT {$limit}");
            foreach ($rows as $row) {
                $result['jobs']++;
                $job = json_decode((string)($row['value'] ?? ''), true);
                if (!is_array($job) || empty($job['actor_name'])) {
                    $db->delete('conf_opts', "id = '" . $db->escape((string)$row['id']) . "'");
                    $result['failed']++;
                    continue;
                }

                $partial = is_array($job['payload'] ?? null) ? $job['payload'] : [];
                try {
                    $extracted = $extractor($job);
                    if (is_array($extracted)) {
                        foreach (chimCommitmentFilterExtractedPayload($extracted) as $key => $value) {
                            if ($value !== '' && $value !== null) {
                                $partial[$key] = $value;
                            }
                        }
                    }
                } catch (Throwable $e) {
                    Logger::warn('[NPC TASKS] Formatter extraction failed, using deterministic fallback: ' . $e->getMessage());
                }

                $payload = chimCommitmentPrepareCreatePayload(
                    $partial,
                    (string)($job['request_text'] ?? '')
                );
                // Claim and persist together: a retry cannot create a second task.
                $db->execQuery('BEGIN');
                try {
                    $queueIdSql = $db->escape((string)$row['id']);
                    $claimed = $db->fetchOne("DELETE FROM conf_opts WHERE id = '{$queueIdSql}' RETURNING id");
                    if (empty($claimed['id'])) {
                        $db->execQuery('ROLLBACK');
                        continue;
                    }
                    $createResult = null;
                    if (trim((string)($payload['location'] ?? '')) !== '') {
                        require_once __DIR__ . '/npc_schedules.php';
                        $actorSql = $db->escape((string)$job['actor_name']);
                        $locationSql = $db->escape(trim((string)$payload['location']));
                        $npcs = $db->fetchAll("SELECT * FROM core_npc_master WHERE lower(npc_name)=lower('{$actorSql}') LIMIT 2");
                        $locations = $db->fetchAll("SELECT DISTINCT name,formid FROM locations WHERE lower(name)=lower('{$locationSql}') LIMIT 2");
                        if (count($npcs) === 1 && count($locations) === 1) {
                            try {
                                $scheduleId = chimScheduleSave($npcs[0], [
                                    'subject'=>$payload['subject'], 'mode'=>'task',
                                    'location_id'=>$locations[0]['formid'],
                                    'due_gamets'=>(int)$job['current_gamets'] + chimCommitmentHoursToGamets($payload['due_in_hours']),
                                    'repeat_hours'=>$payload['repeat_every_hours'] ?? 0,
                                ], 0, true);
                                $createResult = ['ok'=>true, 'id'=>$scheduleId, 'pending_validation'=>true];
                            } catch (InvalidArgumentException $e) { $payload['schedule_issue']=$e->getMessage(); }
                        } else $payload['schedule_issue']='Clarify the exact NPC and recognised destination before scheduling travel.';
                    }
                    if ($createResult === null) $createResult = chimCommitmentCreate(
                        (string)$job['actor_name'], $payload, (int)($job['current_gamets'] ?? 0)
                    );
                    if (empty($createResult['ok'])) {
                        throw new RuntimeException($createResult['error'] ?? 'Task insert failed');
                    }
                    if (empty($createResult['pending_validation']) && !chimCommitmentQueueCreatedNotification((string)$job['actor_name'], (string)$payload['subject'])) {
                        throw new RuntimeException('Task notification failed');
                    }
                    if (!$db->execQuery('COMMIT')) throw new RuntimeException('Task commit failed');
                    $result['created']++;
                    Logger::info('[NPC TASKS] Created task #' . $createResult['id'] . ' for ' . $job['actor_name']);
                } catch (Throwable $e) {
                    $db->execQuery('ROLLBACK');
                    Logger::error('[NPC TASKS] Could not persist queued task: ' . $e->getMessage());
                    $result['failed']++;
                }
            }
        } finally {
            $db->fetchAll("SELECT pg_advisory_unlock(hashtext('herika_npc_commitment_worker')) AS released");
        }

        return $result;
    }
}
