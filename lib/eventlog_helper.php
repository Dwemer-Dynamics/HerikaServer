<?php

if (!function_exists('chimGetVisibleEventLogExcludedTypes')) {
    function chimGetVisibleEventLogExcludedTypes()
    {
        return [
            'prechat',
            'rechat',
            'infonpc',
            'request',
            'infonpc_close',
            'addnpc',
            'addbgnpc',
            'user_input',
            'infosave',
            'init',
            'playerinfo',
            'oghma_import',
            'biography_import',
            'dynamic_oghma_import',
            'infoitems',
            'description_import',
            'traditional_quest_import',
            'backgroundaction',
            'innerchat',
            'npc_reanimated',
            'npcvoice_refresh',
            'status_msg',
            'region',
            'ext_nsfw_physics_raw',
        ];
    }
}

if (!function_exists('chimBuildVisibleEventLogWhereClause')) {
    function chimBuildVisibleEventLogWhereClause($db, $selectedType = '', $additionalExcludedTypes = [])
    {
        $excludedTypes = array_values(array_unique(array_merge(
            chimGetVisibleEventLogExcludedTypes(),
            is_array($additionalExcludedTypes) ? $additionalExcludedTypes : []
        )));
        $escapedTypes = array_map(function ($type) use ($db) {
            return "'" . $db->escape($type) . "'";
        }, $excludedTypes);

        $clauses = [
            "type NOT IN (" . implode(',', $escapedTypes) . ")",
        ];

        $selectedType = trim((string)$selectedType);
        if ($selectedType !== '') {
            $clauses[] = "type = '" . $db->escape($selectedType) . "'";
        }

        return implode(' AND ', $clauses);
    }
}

if (!function_exists('chimBuildNpcEventLogPeopleWhereClause')) {
    // Match one NPC token without allowing partial-name matches or far-away audience markers.
    function chimBuildNpcEventLogPeopleWhereClause($db, $npcName, $peopleColumn = 'people')
    {
        $peopleColumn = trim((string)$peopleColumn);
        if (!preg_match('/^(?:[A-Za-z_][A-Za-z0-9_]*\.)?[A-Za-z_][A-Za-z0-9_]*$/', $peopleColumn)) {
            $peopleColumn = 'people';
        }

        $escapedNpcName = $db->escape(trim((string)$npcName));
        return "EXISTS (
            SELECT 1
            FROM unnest(string_to_array(trim(BOTH '|' FROM COALESCE({$peopleColumn}, '')), '|')) AS chim_person(person_name)
            WHERE lower(regexp_replace(btrim(chim_person.person_name), ' \\((busy|hostile|in combat|restrained)\\)$', '', 'i')) = lower('{$escapedNpcName}')
        )";
    }
}

if (!function_exists('chimGetVisibleEventLogTypes')) {
    function chimGetVisibleEventLogTypes($db, $additionalExcludedTypes = [])
    {
        $visibleWhereClause = chimBuildVisibleEventLogWhereClause($db, '', $additionalExcludedTypes);

        $types = $db->fetchAll("
            SELECT type, COUNT(*) AS total
            FROM eventlog
            WHERE {$visibleWhereClause}
            GROUP BY type
            ORDER BY type ASC
        ");

        if (!in_array('relationship', $additionalExcludedTypes, true)) {
            $relationshipRow = $db->fetchOne(
                "SELECT 1 AS available
                 FROM core_npc_master_history
                 WHERE extended_data ->> '_chim_history_source' = 'relationship'
                 LIMIT 1"
            );
            if ($relationshipRow) {
                $types[] = [
                    'type' => 'relationship',
                    'total' => 1,
                ];
                usort($types, static function (array $left, array $right): int {
                    return strcmp((string)($left['type'] ?? ''), (string)($right['type'] ?? ''));
                });
            }
        }

        return $types;
    }
}

if (!function_exists('chimRelationshipHistoryTimelineCte')) {
    /**
     * Pair each NPC history snapshot with its predecessor without copying relationship data into eventlog.
     */
    function chimRelationshipHistoryTimelineCte()
    {
        return "WITH ordered_relationship_history AS (
            SELECT
                history_id,
                npc_id,
                npc_name,
                extended_data,
                gamets_last_updated,
                created,
                LAG(extended_data) OVER (
                    PARTITION BY npc_id
                    ORDER BY gamets_last_updated ASC NULLS FIRST, created ASC, history_id ASC
                ) AS previous_extended_data
            FROM core_npc_master_history
        ), visible_relationship_history AS (
            SELECT *
            FROM ordered_relationship_history
            WHERE extended_data ->> '_chim_history_source' = 'relationship'
              AND COALESCE(extended_data -> 'relationships', '{}'::jsonb)
                  IS DISTINCT FROM COALESCE(previous_extended_data -> 'relationships', '{}'::jsonb)
        )";
    }
}

if (!function_exists('chimCountRelationshipHistoryTimelineRows')) {
    function chimCountRelationshipHistoryTimelineRows($db)
    {
        $row = $db->fetchOne(
            chimRelationshipHistoryTimelineCte()
            . " SELECT COUNT(*) AS total FROM visible_relationship_history"
        );
        return intval($row['total'] ?? 0);
    }
}

if (!function_exists('chimGetLatestRelationshipHistoryId')) {
    function chimGetLatestRelationshipHistoryId($db)
    {
        $row = $db->fetchOne(
            "SELECT COALESCE(MAX(history_id), 0) AS latest_id
             FROM core_npc_master_history
             WHERE extended_data ->> '_chim_history_source' = 'relationship'"
        );
        return intval($row['latest_id'] ?? 0);
    }
}

if (!function_exists('chimBuildRelationshipHistoryTimelineRows')) {
    /**
     * Turn persisted relationship snapshots into virtual timeline rows for user-facing views.
     */
    function chimBuildRelationshipHistoryTimelineRows(array $snapshots, $includeChangeDetails = false)
    {
        if (!class_exists('RelationshipManager')) {
            require_once __DIR__ . DIRECTORY_SEPARATOR . 'relationship_manager.php';
        }

        $rows = [];
        foreach ($snapshots as $snapshot) {
            $historyId = intval($snapshot['history_id'] ?? 0);
            if ($historyId <= 0) {
                continue;
            }

            $currentExtended = json_decode((string)($snapshot['extended_data'] ?? ''), true);
            $previousExtended = json_decode((string)($snapshot['previous_extended_data'] ?? ''), true);
            $currentExtended = is_array($currentExtended) ? $currentExtended : [];
            $previousExtended = is_array($previousExtended) ? $previousExtended : [];
            $changes = RelationshipManager::buildRelationshipChangeSummaries(
                (string)($snapshot['npc_name'] ?? ''),
                $previousExtended['relationships'] ?? [],
                $currentExtended['relationships'] ?? []
            );
            if (empty($changes)) {
                continue;
            }

            $people = [];
            $descriptions = [];
            foreach ($changes as $change) {
                $description = trim((string)($change['data'] ?? ''));
                if ($description !== '') {
                    $descriptions[] = $description;
                }
                foreach (explode('|', trim((string)($change['people'] ?? ''), '|')) as $person) {
                    $person = trim($person);
                    if ($person !== '' && !in_array($person, $people, true)) {
                        $people[] = $person;
                    }
                }
            }
            if (empty($descriptions)) {
                continue;
            }

            $localTimestamp = intval($snapshot['localts'] ?? 0);
            $rows[] = [
                'type' => 'relationship',
                'data' => implode(' ', $descriptions),
                'people' => '|' . implode('|', $people) . '|',
                'gamets' => (int)($snapshot['gamets_last_updated'] ?? 0),
                'localts' => $localTimestamp,
                'ts' => $localTimestamp,
                'rowid' => 'relationship:' . $historyId,
                'relationship_history_id' => $historyId,
                'source' => 'relationship_history',
            ];
            // Additive opt-in: 'data' keeps the full prose for every existing consumer.
            if ($includeChangeDetails) {
                $rows[count($rows) - 1]['changes'] = chimBuildRelationshipChangeDetails($snapshot);
            }
        }

        return $rows;
    }
}

if (!function_exists('chimFetchRelationshipHistoryTimelineRows')) {
    function chimFetchRelationshipHistoryTimelineRows(
        $db,
        $limit,
        $offset = 0,
        $sinceHistoryId = 0,
        $sinceGamets = 0,
        $includeChangeDetails = false
    ) {
        $limit = max(1, min(5000, intval($limit)));
        $offset = max(0, intval($offset));
        $sinceHistoryId = max(0, intval($sinceHistoryId));
        $sinceGamets = max(0, intval($sinceGamets));

        $where = [];
        if ($sinceHistoryId > 0) {
            $where[] = "history_id > {$sinceHistoryId}";
        }
        if ($sinceGamets > 0) {
            $where[] = "gamets_last_updated >= {$sinceGamets}";
        }
        $whereSql = empty($where) ? '' : 'WHERE ' . implode(' AND ', $where);
        $incremental = $sinceHistoryId > 0;
        $orderSql = $incremental
            ? 'history_id ASC'
            : 'gamets_last_updated DESC NULLS LAST, created DESC, history_id DESC';

        $snapshots = $db->fetchAll(
            chimRelationshipHistoryTimelineCte()
            . " SELECT
                    history_id,
                    npc_name,
                    extended_data,
                    previous_extended_data,
                    gamets_last_updated,
                    EXTRACT(EPOCH FROM created)::bigint AS localts
                FROM visible_relationship_history
                {$whereSql}
                ORDER BY {$orderSql}
                LIMIT {$limit} OFFSET {$offset}"
        );

        return chimBuildRelationshipHistoryTimelineRows($snapshots, $includeChangeDetails);
    }
}

if (!function_exists('chimBuildRelationshipChangeDetails')) {
    /**
     * Break one relationship snapshot into structured, per-target change details.
     *
     * Additive companion to chimBuildRelationshipHistoryTimelineRows(): it keeps
     * RelationshipManager::buildRelationshipChangeSummaries() as the single source
     * of truth for which targets changed, then pairs each summary with the numbers
     * behind the sentence (signed affinity delta, tier hop, type hop, reason note)
     * so compact panels can render a terse row instead of the full prose. Nothing
     * is read from or written to eventlog.
     */
    function chimBuildRelationshipChangeDetails(array $snapshot)
    {
        if (!class_exists('RelationshipManager')) {
            require_once __DIR__ . DIRECTORY_SEPARATOR . 'relationship_manager.php';
        }

        $currentExtended = json_decode((string)($snapshot['extended_data'] ?? ''), true);
        $previousExtended = json_decode((string)($snapshot['previous_extended_data'] ?? ''), true);
        $currentExtended = is_array($currentExtended) ? $currentExtended : [];
        $previousExtended = is_array($previousExtended) ? $previousExtended : [];

        $changes = RelationshipManager::buildRelationshipChangeSummaries(
            (string)($snapshot['npc_name'] ?? ''),
            $previousExtended['relationships'] ?? [],
            $currentExtended['relationships'] ?? []
        );
        if (empty($changes)) {
            return [];
        }

        $oldMap = RelationshipManager::normalizeRelationshipMap($previousExtended['relationships'] ?? []);
        $newMap = RelationshipManager::normalizeRelationshipMap($currentExtended['relationships'] ?? []);

        $details = [];
        foreach ($changes as $change) {
            $target = (string)($change['target'] ?? '');
            $old = isset($oldMap[$target]) && is_array($oldMap[$target]) ? $oldMap[$target] : [];
            $new = isset($newMap[$target]) && is_array($newMap[$target]) ? $newMap[$target] : [];

            $oldAffinity = (int)($old['aff'] ?? 0);
            $newAffinity = (int)($new['aff'] ?? 0);
            $oldType = strtolower(trim((string)($old['type'] ?? 'neutral')));
            $newType = strtolower(trim((string)($new['type'] ?? 'neutral')));
            $oldType = $oldType !== '' ? $oldType : 'neutral';
            $newType = $newType !== '' ? $newType : 'neutral';
            $oldNote = trim((string)($old['note'] ?? ''));
            $newNote = trim((string)($new['note'] ?? ''));

            $details[] = [
                'target' => $target,
                'direction' => (string)($change['direction'] ?? 'neutral'),
                'delta' => $newAffinity - $oldAffinity,
                'affinity_from' => $oldAffinity,
                'affinity_to' => $newAffinity,
                'tier_from' => RelationshipManager::getTierLabel($oldAffinity),
                'tier_to' => RelationshipManager::getTierLabel($newAffinity),
                'type_changed' => $oldType !== $newType,
                'type_from' => $oldType,
                'type_to' => $newType,
                // Only surface a note the model actually rewrote for this change.
                'reason' => ($newNote !== '' && $newNote !== $oldNote) ? $newNote : '',
                'data' => (string)($change['data'] ?? ''),
            ];
        }

        return $details;
    }
}

if (!function_exists('chimBuildRelationshipChangePresentation')) {
    /**
     * Reduce one structured change detail to the compact bits every view renders.
     *
     * Shared by the dashboard widget and the Events & Memories event log so both
     * agree on the sign, the colour class, the spoken description and whether the
     * tier label is worth repeating. Presentation only: no reads, no writes.
     *
     * @param array $change One entry from chimBuildRelationshipChangeDetails()
     * @return array Keys: badge_class, badge_label, badge_spoken, reason, tier
     */
    function chimBuildRelationshipChangePresentation(array $change)
    {
        $delta = intval($change['delta'] ?? 0);
        if (!array_key_exists('delta', $change)) {
            // Row carries no structured detail at all.
            $badgeClass = 'is-type';
            $badgeLabel = 'Change';
            $badgeSpoken = 'Relationship change.';
        } elseif ($delta !== 0) {
            // The sign carries the direction, so colour is reinforcement only.
            $badgeClass = $delta > 0 ? 'is-up' : 'is-down';
            $badgeLabel = sprintf('%+d', $delta);
            $badgeSpoken = 'Affinity ' . $badgeLabel . '.';
        } else {
            // Type-only change: there is no number to show, so use a neutral badge.
            $badgeClass = 'is-type';
            $badgeLabel = 'Type';
            $badgeSpoken = 'Relationship type change.';
        }

        $reason = trim((string)($change['reason'] ?? ''));
        $tierFrom = trim((string)($change['tier_from'] ?? ''));
        $tierTo = trim((string)($change['tier_to'] ?? ''));
        $typeTo = trim((string)($change['type_to'] ?? ''));
        $tierHopped = $tierTo !== '' && $tierTo !== $tierFrom;

        // Prefer the note the model wrote; otherwise state the shortest useful outcome.
        $reasonText = $reason;
        if ($reasonText === '') {
            if (!empty($change['type_changed']) && $typeTo !== '') {
                $reasonText = 'Now ' . $typeTo;
            } elseif ($tierHopped) {
                $reasonText = 'Now ' . $tierTo;
            } else {
                $reasonText = 'No reason recorded';
            }
        }

        return [
            'badge_class' => $badgeClass,
            'badge_label' => $badgeLabel,
            'badge_spoken' => $badgeSpoken,
            'reason' => $reasonText,
            // The tier hop only earns its own chip when the reason text is not already it.
            'tier' => ($reason !== '' && $tierHopped) ? $tierTo : '',
        ];
    }
}

if (!function_exists('chimRenderRelationshipChangeCellHtml')) {
    /**
     * Compact markup for the Events cell of one relationship-history timeline row.
     *
     * Mirrors the dashboard widget: every change leads with a signed affinity delta
     * (or a neutral badge when only the relationship type moved) followed by the
     * stored reason, with target and tier kept as secondary metadata. Web views
     * only; raw API responses and non-UI consumers keep the prose in 'data'.
     *
     * @param array  $changes      Details from chimBuildRelationshipChangeDetails()
     * @param string $fallbackText Prose to escape and return when no details exist
     * @return string Escaped HTML
     */
    function chimRenderRelationshipChangeCellHtml($changes, $fallbackText = '')
    {
        $changes = is_array($changes) ? array_filter($changes, 'is_array') : [];
        if (empty($changes)) {
            return htmlspecialchars(trim((string)$fallbackText), ENT_QUOTES, 'UTF-8');
        }

        $html = "<ul class='relationship-change-cell' role='list'>";
        foreach ($changes as $change) {
            $presentation = chimBuildRelationshipChangePresentation($change);
            $target = trim((string)($change['target'] ?? ''));

            $metaHtml = '';
            if ($target !== '') {
                $metaHtml .= "<span class='relationship-change-sr'> toward </span>"
                    . "<span class='relationship-change-arrow' aria-hidden='true'>&rarr;</span>"
                    . "<span class='relationship-change-target'>"
                    . htmlspecialchars($target, ENT_QUOTES, 'UTF-8')
                    . "</span>";
            }
            if ($presentation['tier'] !== '') {
                $metaHtml .= "<span class='relationship-change-tier'>"
                    . htmlspecialchars($presentation['tier'], ENT_QUOTES, 'UTF-8')
                    . "</span>";
            }

            $html .= "<li class='relationship-change-entry'>"
                . "<span class='relationship-change-delta {$presentation['badge_class']}'>"
                . "<span class='relationship-change-sr'>"
                . htmlspecialchars($presentation['badge_spoken'], ENT_QUOTES, 'UTF-8')
                . " </span>"
                . "<span aria-hidden='true'>"
                . htmlspecialchars($presentation['badge_label'], ENT_QUOTES, 'UTF-8')
                . "</span>"
                . "</span>"
                . "<span class='relationship-change-entry-body'>"
                . "<span class='relationship-change-reason'>"
                . htmlspecialchars($presentation['reason'], ENT_QUOTES, 'UTF-8')
                . "</span>"
                . ($metaHtml !== '' ? "<span class='relationship-change-entry-meta'>{$metaHtml}</span>" : '')
                . "</span>"
                . "</li>";
        }

        return $html . '</ul>';
    }
}

if (!function_exists('chimFetchRecentRelationshipHistoryChanges')) {
    /**
     * Read-only feed for compact relationship panels (dashboard widget, NPC editor).
     *
     * Reuses the derived timeline rows so relationship history stays in
     * core_npc_master_history; nothing is copied into eventlog. Pass $npcId to
     * scope the feed to a single NPC (the snapshot owner), 0 for every NPC.
     */
    function chimFetchRecentRelationshipHistoryChanges($db, $limit = 5, $npcId = 0)
    {
        $limit = max(1, min(50, intval($limit)));
        $npcId = max(0, intval($npcId));
        // Snapshots whose summaries resolve to nothing are dropped by the row builder,
        // so read a slightly wider window than the caller asked for.
        $sourceWindow = min(200, $limit * 4);
        $whereSql = $npcId > 0 ? "WHERE npc_id = {$npcId}" : '';

        $snapshots = $db->fetchAll(
            chimRelationshipHistoryTimelineCte()
            . " SELECT
                    history_id,
                    npc_id,
                    npc_name,
                    extended_data,
                    previous_extended_data,
                    gamets_last_updated,
                    EXTRACT(EPOCH FROM created)::bigint AS localts
                FROM visible_relationship_history
                {$whereSql}
                ORDER BY gamets_last_updated DESC NULLS LAST, created DESC, history_id DESC
                LIMIT {$sourceWindow}"
        );
        if (!is_array($snapshots) || empty($snapshots)) {
            return [];
        }

        $npcNames = [];
        foreach ($snapshots as $snapshot) {
            $npcNames[intval($snapshot['history_id'] ?? 0)] = trim((string)($snapshot['npc_name'] ?? ''));
        }

        $rows = chimBuildRelationshipHistoryTimelineRows($snapshots, true);
        foreach ($rows as $index => $row) {
            $rows[$index]['npc_name'] = $npcNames[intval($row['relationship_history_id'] ?? 0)] ?? '';
        }

        return array_slice($rows, 0, $limit);
    }
}

if (!function_exists('chimMergeTimelineRows')) {
    function chimMergeTimelineRows(array $eventRows, array $relationshipRows, $limit = 0, $offset = 0)
    {
        $rows = array_merge($eventRows, $relationshipRows);
        usort($rows, static function (array $left, array $right): int {
            foreach (['gamets', 'ts', 'localts'] as $key) {
                $comparison = ((float)($right[$key] ?? 0)) <=> ((float)($left[$key] ?? 0));
                if ($comparison !== 0) {
                    return $comparison;
                }
            }
            return intval($right['relationship_history_id'] ?? $right['rowid'] ?? 0)
                <=> intval($left['relationship_history_id'] ?? $left['rowid'] ?? 0);
        });

        $offset = max(0, intval($offset));
        $limit = max(0, intval($limit));
        return $limit > 0 ? array_slice($rows, $offset, $limit) : array_slice($rows, $offset);
    }
}

if (!function_exists('chimNormalizeEventLogTypeList')) {
    function chimNormalizeEventLogTypeList($types)
    {
        if (!is_array($types)) {
            return [];
        }

        $normalized = [];
        foreach ($types as $type) {
            $type = trim((string)$type);
            if ($type === '') {
                continue;
            }
            $normalized[$type] = $type;
        }

        return array_values($normalized);
    }
}

if (!function_exists('chimGetPersistedEventLogHiddenTypes')) {
    function chimGetPersistedEventLogHiddenTypes($db)
    {
        $confKey = 'chim_eventlog_hidden_types';
        $row = $db->fetchOne("SELECT value FROM conf_opts WHERE id='" . $db->escape($confKey) . "' LIMIT 1");
        $rawValue = trim((string)($row['value'] ?? ''));
        if ($rawValue === '') {
            return [];
        }

        $decoded = json_decode($rawValue, true);
        if (is_array($decoded)) {
            return chimNormalizeEventLogTypeList($decoded);
        }

        return chimNormalizeEventLogTypeList(explode(',', $rawValue));
    }
}

if (!function_exists('chimSavePersistedEventLogHiddenTypes')) {
    function chimSavePersistedEventLogHiddenTypes($db, $types)
    {
        $confKey = 'chim_eventlog_hidden_types';
        $normalizedTypes = chimNormalizeEventLogTypeList($types);

        if (empty($normalizedTypes)) {
            $db->delete('conf_opts', "id='" . $db->escape($confKey) . "'");
            return true;
        }

        return $db->upsertRowOnConflict('conf_opts', [
            'id' => $confKey,
            'value' => json_encode(array_values($normalizedTypes), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ], 'id');
    }
}

if (!function_exists('chimParseTimelineRowIdentifier')) {
    /**
     * Normalize an ordinary event row ID or a virtual relationship-history row ID.
     */
    function chimParseTimelineRowIdentifier($rowId)
    {
        $value = trim((string)$rowId);
        if (preg_match('/^relationship:0*([1-9][0-9]*)$/', $value, $matches)) {
            return [
                'source' => 'relationship_history',
                'id' => intval($matches[1]),
                'rowid' => 'relationship:' . intval($matches[1]),
            ];
        }
        if (preg_match('/^[1-9][0-9]*$/', $value)) {
            return [
                'source' => 'eventlog',
                'id' => intval($value),
                'rowid' => (string)intval($value),
            ];
        }

        return null;
    }
}

if (!function_exists('chimDecodeRelationshipUndoExtendedData')) {
    function chimDecodeRelationshipUndoExtendedData($extendedData)
    {
        if (is_array($extendedData)) {
            return $extendedData;
        }
        if (!is_string($extendedData) || trim($extendedData) === '') {
            return null;
        }

        $decoded = json_decode($extendedData, true);
        return is_array($decoded) ? $decoded : null;
    }
}

if (!function_exists('chimRelationshipUndoComparableState')) {
    /**
     * Compare relationship state without treating player-authored Custom Info as an AI relationship change.
     */
    function chimRelationshipUndoComparableState($extendedData)
    {
        $extended = chimDecodeRelationshipUndoExtendedData($extendedData);
        if (!is_array($extended)) {
            return null;
        }

        $state = [];
        if (array_key_exists('relationships', $extended)) {
            $state['relationships'] = $extended['relationships'];
        }
        if (isset($state['relationships']) && is_array($state['relationships'])) {
            foreach ($state['relationships'] as &$relationship) {
                if (is_array($relationship)) {
                    unset($relationship['custom_info']);
                }
            }
            unset($relationship);
        }

        return $state;
    }
}

if (!function_exists('chimBuildRelationshipUndoExtendedData')) {
    /**
     * Restore relationship-owned keys from the predecessor while preserving current Custom Info and unrelated data.
     */
    function chimBuildRelationshipUndoExtendedData($currentExtendedData, $previousExtendedData)
    {
        $current = chimDecodeRelationshipUndoExtendedData($currentExtendedData);
        $previous = chimDecodeRelationshipUndoExtendedData($previousExtendedData);
        if ($previous === null && ($previousExtendedData === null || trim((string)$previousExtendedData) === '')) {
            $previous = [];
        }
        if (!is_array($current) || !is_array($previous)) {
            return null;
        }

        $currentCustomInfo = [];
        $currentRelationships = $current['relationships'] ?? [];
        if (is_array($currentRelationships)) {
            foreach ($currentRelationships as $target => $relationship) {
                if (is_array($relationship) && array_key_exists('custom_info', $relationship)) {
                    $currentCustomInfo[(string)$target] = $relationship['custom_info'];
                }
            }
        }

        if (array_key_exists('relationships', $previous)) {
            $current['relationships'] = $previous['relationships'];
        } else {
            unset($current['relationships']);
        }

        $restoredRelationships = $current['relationships'] ?? [];
        if (is_array($restoredRelationships)) {
            foreach ($restoredRelationships as &$relationship) {
                if (is_array($relationship)) {
                    unset($relationship['custom_info']);
                }
            }
            unset($relationship);

            foreach ($currentCustomInfo as $target => $customInfo) {
                if (!isset($restoredRelationships[$target]) || !is_array($restoredRelationships[$target])) {
                    $restoredRelationships[$target] = [
                        'aff' => 0,
                        'type' => 'neutral',
                    ];
                }
                $restoredRelationships[$target]['custom_info'] = $customInfo;
            }
            $current['relationships'] = $restoredRelationships;
        } elseif (!empty($currentCustomInfo)) {
            $current['relationships'] = [];
            foreach ($currentCustomInfo as $target => $customInfo) {
                $current['relationships'][$target] = [
                    'aff' => 0,
                    'type' => 'neutral',
                    'custom_info' => $customInfo,
                ];
            }
        }

        return $current;
    }
}

if (!function_exists('chimFetchRelationshipUndoSnapshot')) {
    function chimFetchRelationshipUndoSnapshot($db, $historyId)
    {
        $historyId = intval($historyId);
        if ($historyId <= 0) {
            return [];
        }

        return $db->fetchOne(
            chimRelationshipHistoryTimelineCte()
            . " SELECT
                    history_id,
                    npc_id,
                    npc_name,
                    extended_data,
                    previous_extended_data,
                    gamets_last_updated,
                    created
                FROM visible_relationship_history
                WHERE history_id = {$historyId}
                LIMIT 1"
        );
    }
}

if (!function_exists('chimUndoRelationshipHistoryRowInTransaction')) {
    /**
     * Undo the latest displayed relationship change for one NPC inside the caller's transaction.
     */
    function chimUndoRelationshipHistoryRowInTransaction($db, $historyId)
    {
        $snapshot = chimFetchRelationshipUndoSnapshot($db, $historyId);
        if (!$snapshot) {
            return [
                'ok' => true,
                'rowid' => 'relationship:' . intval($historyId),
                'deleted_count' => 0,
                'relationship_undo_count' => 0,
                'message' => 'Relationship change is no longer available.',
            ];
        }

        $npcId = intval($snapshot['npc_id'] ?? 0);
        if ($npcId <= 0) {
            return [
                'ok' => false,
                'deleted_count' => 0,
                'relationship_undo_count' => 0,
                'message' => 'Relationship change has no valid NPC.',
            ];
        }

        $lockId = 1001000000 + $npcId;
        if ($db->execQuery("SELECT pg_advisory_xact_lock({$lockId})") === false) {
            return [
                'ok' => false,
                'deleted_count' => 0,
                'relationship_undo_count' => 0,
                'message' => 'Could not lock the NPC relationship.',
            ];
        }

        $snapshot = chimFetchRelationshipUndoSnapshot($db, $historyId);
        if (!$snapshot) {
            return [
                'ok' => true,
                'rowid' => 'relationship:' . intval($historyId),
                'deleted_count' => 0,
                'relationship_undo_count' => 0,
                'message' => 'Relationship change is no longer available.',
            ];
        }

        $latest = $db->fetchOne(
            chimRelationshipHistoryTimelineCte()
            . " SELECT history_id
                FROM visible_relationship_history
                WHERE npc_id = {$npcId}
                ORDER BY history_id DESC
                LIMIT 1"
        );
        if (intval($latest['history_id'] ?? 0) !== intval($historyId)) {
            return [
                'ok' => false,
                'deleted_count' => 0,
                'relationship_undo_count' => 0,
                'message' => 'Undo newer relationship changes for this NPC first.',
            ];
        }

        $current = $db->fetchOne(
            "SELECT extended_data FROM core_npc_master WHERE id = {$npcId} FOR UPDATE"
        );
        if (!$current) {
            return [
                'ok' => false,
                'deleted_count' => 0,
                'relationship_undo_count' => 0,
                'message' => 'NPC relationship state is no longer available.',
            ];
        }

        $currentState = chimRelationshipUndoComparableState($current['extended_data'] ?? null);
        $snapshotState = chimRelationshipUndoComparableState($snapshot['extended_data'] ?? null);
        if ($currentState === null || $snapshotState === null || $currentState != $snapshotState) {
            return [
                'ok' => false,
                'deleted_count' => 0,
                'relationship_undo_count' => 0,
                'message' => 'This relationship has changed since the event was recorded. Refresh and undo the newest change first.',
            ];
        }

        $laterHistory = $db->fetchAll(
            "SELECT history_id, extended_data
             FROM core_npc_master_history
             WHERE npc_id = {$npcId}
               AND history_id > " . intval($historyId) . "
             ORDER BY history_id ASC
             FOR UPDATE"
        );
        foreach ($laterHistory as $laterRow) {
            $laterState = chimRelationshipUndoComparableState($laterRow['extended_data'] ?? null);
            if ($laterState === null || $laterState != $snapshotState) {
                return [
                    'ok' => false,
                    'deleted_count' => 0,
                    'relationship_undo_count' => 0,
                    'message' => 'Undo newer relationship changes for this NPC first.',
                ];
            }
        }

        $restored = chimBuildRelationshipUndoExtendedData(
            $current['extended_data'] ?? null,
            $snapshot['previous_extended_data'] ?? null
        );
        if (!is_array($restored)) {
            return [
                'ok' => false,
                'deleted_count' => 0,
                'relationship_undo_count' => 0,
                'message' => 'The previous relationship state is unavailable.',
            ];
        }

        $encoded = json_encode($restored, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($encoded === false || !$db->updateRow('core_npc_master', ['extended_data' => $encoded], "id = {$npcId}")) {
            return [
                'ok' => false,
                'deleted_count' => 0,
                'relationship_undo_count' => 0,
                'message' => 'Failed to restore the previous relationship state.',
            ];
        }

        foreach ($laterHistory as $laterRow) {
            $laterRestored = chimBuildRelationshipUndoExtendedData(
                $laterRow['extended_data'] ?? null,
                $snapshot['previous_extended_data'] ?? null
            );
            $laterEncoded = is_array($laterRestored)
                ? json_encode($laterRestored, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                : false;
            $laterHistoryId = intval($laterRow['history_id'] ?? 0);
            if ($laterHistoryId <= 0 || $laterEncoded === false
                || !$db->updateRow(
                    'core_npc_master_history',
                    ['extended_data' => $laterEncoded],
                    "history_id = {$laterHistoryId} AND npc_id = {$npcId}"
                )) {
                return [
                    'ok' => false,
                    'deleted_count' => 0,
                    'relationship_undo_count' => 0,
                    'message' => 'Failed to update later relationship history.',
                ];
            }
        }

        $deleted = $db->fetchOne(
            "DELETE FROM core_npc_master_history
             WHERE history_id = " . intval($historyId) . "
               AND npc_id = {$npcId}
               AND extended_data ->> '_chim_history_source' = 'relationship'
             RETURNING history_id"
        );
        if (intval($deleted['history_id'] ?? 0) !== intval($historyId)) {
            return [
                'ok' => false,
                'deleted_count' => 0,
                'relationship_undo_count' => 0,
                'message' => 'Failed to remove the relationship event.',
            ];
        }

        return [
            'ok' => true,
            'rowid' => 'relationship:' . intval($historyId),
            'deleted_count' => 1,
            'relationship_undo_count' => 1,
            'message' => 'Relationship change undone.',
        ];
    }
}

if (!function_exists('chimDeleteTimelineRows')) {
    /**
     * Delete eventlog rows and undo relationship rows atomically as one visible timeline operation.
     */
    function chimDeleteTimelineRows($db, array $rowIds)
    {
        $targets = [];
        foreach ($rowIds as $rowId) {
            $parsed = chimParseTimelineRowIdentifier($rowId);
            if ($parsed !== null) {
                $targets[$parsed['rowid']] = $parsed;
            }
        }
        if (empty($targets)) {
            return [
                'ok' => false,
                'deleted_count' => 0,
                'relationship_undo_count' => 0,
                'message' => 'No valid timeline rows were selected.',
            ];
        }

        uasort($targets, static function (array $left, array $right): int {
            if ($left['source'] !== $right['source']) {
                return $left['source'] === 'relationship_history' ? -1 : 1;
            }
            return $right['id'] <=> $left['id'];
        });

        $transactionStarted = false;
        $deletedCount = 0;
        $relationshipUndoCount = 0;
        $failureMessage = 'Failed to delete timeline events.';
        try {
            if ($db->execQuery('BEGIN') === false) {
                throw new RuntimeException('Could not start timeline delete transaction.');
            }
            $transactionStarted = true;

            foreach ($targets as $target) {
                $result = $target['source'] === 'relationship_history'
                    ? chimUndoRelationshipHistoryRowInTransaction($db, $target['id'])
                    : chimDeleteEventLogRow($db, $target['id']);
                if (empty($result['ok'])) {
                    $failureMessage = (string)($result['message'] ?? $failureMessage);
                    throw new RuntimeException($failureMessage);
                }
                $deletedCount += intval($result['deleted_count'] ?? 0);
                $relationshipUndoCount += intval($result['relationship_undo_count'] ?? 0);
            }

            if ($db->execQuery('COMMIT') === false) {
                throw new RuntimeException('Could not commit timeline delete transaction.');
            }
            $transactionStarted = false;
        } catch (Throwable $e) {
            if ($transactionStarted) {
                $db->execQuery('ROLLBACK');
            }
            error_log('[EVENTLOG] Timeline delete failed: ' . $e->getMessage());
            return [
                'ok' => false,
                'deleted_count' => 0,
                'relationship_undo_count' => 0,
                'message' => $failureMessage,
            ];
        }

        return [
            'ok' => true,
            'deleted_count' => $deletedCount,
            'relationship_undo_count' => $relationshipUndoCount,
            'message' => $relationshipUndoCount > 0
                ? 'Timeline events deleted and relationship changes undone.'
                : 'Timeline events deleted.',
        ];
    }
}

if (!function_exists('chimDeleteTimelineRow')) {
    function chimDeleteTimelineRow($db, $rowId)
    {
        $result = chimDeleteTimelineRows($db, [$rowId]);
        $result['rowid'] = trim((string)$rowId);
        return $result;
    }
}

if (!function_exists('chimDeleteLatestVisibleTimelineRows')) {
    /**
     * Apply Delete Latest to the combined timeline, undoing relationship rows in newest-first order.
     */
    function chimDeleteLatestVisibleTimelineRows(
        $db,
        $deleteCount,
        $selectedType = '',
        $additionalExcludedTypes = []
    ) {
        $deleteCount = intval($deleteCount);
        if (!in_array($deleteCount, [5, 10, 20, 50, 100], true)) {
            return [
                'ok' => false,
                'deleted_count' => 0,
                'relationship_undo_count' => 0,
                'message' => 'Unsupported delete count.',
            ];
        }

        $visibleWhereClause = chimBuildVisibleEventLogWhereClause($db, $selectedType, $additionalExcludedTypes);
        $eventRows = $db->fetchAll("SELECT type, data, people, gamets, localts, ts, rowid
            FROM eventlog
            WHERE {$visibleWhereClause}
            ORDER BY gamets DESC, ts DESC, localts DESC, rowid DESC
            LIMIT {$deleteCount}");

        $includeRelationships = ($selectedType === '' || $selectedType === 'relationship')
            && !in_array('relationship', $additionalExcludedTypes, true);
        if (array_key_exists('RELATIONSHIP_SYSTEM_ENABLED', $GLOBALS)
            && empty($GLOBALS['RELATIONSHIP_SYSTEM_ENABLED'])) {
            $includeRelationships = false;
        }
        $relationshipRows = $includeRelationships
            ? chimFetchRelationshipHistoryTimelineRows($db, $deleteCount)
            : [];

        $timelineRows = chimMergeTimelineRows($eventRows, $relationshipRows, $deleteCount);
        $rowIds = array_map(static function (array $row) {
            return $row['rowid'] ?? '';
        }, $timelineRows);
        if (empty($rowIds)) {
            return [
                'ok' => true,
                'deleted_count' => 0,
                'relationship_undo_count' => 0,
                'requested_count' => $deleteCount,
                'message' => 'No visible timeline events were available.',
            ];
        }

        $result = chimDeleteTimelineRows($db, $rowIds);
        $result['requested_count'] = $deleteCount;
        return $result;
    }
}

if (!function_exists('chimDeleteLatestVisibleEventLogRows')) {
    function chimDeleteLatestVisibleEventLogRows($db, $deleteCount, $selectedType = '', $additionalExcludedTypes = [])
    {
        $deleteCount = intval($deleteCount);
        if (!in_array($deleteCount, [5, 10, 20, 50, 100], true)) {
            return [
                'ok' => false,
                'deleted_count' => 0,
                'message' => 'Unsupported delete count.',
            ];
        }

        $visibleWhereClause = chimBuildVisibleEventLogWhereClause($db, $selectedType, $additionalExcludedTypes);
        $targetRows = $db->fetchAll("
            SELECT rowid
            FROM eventlog
            WHERE {$visibleWhereClause}
            ORDER BY gamets DESC, ts DESC, localts DESC, rowid DESC
            LIMIT {$deleteCount}
        ");

        $targetRowids = [];
        foreach ($targetRows as $targetRow) {
            $targetRowid = intval($targetRow['rowid'] ?? 0);
            if ($targetRowid > 0) {
                $targetRowids[] = $targetRowid;
            }
        }

        if (!empty($targetRowids)) {
            $targetRowidsStr = implode(',', $targetRowids);
            $db->query("DELETE FROM eventlog WHERE rowid IN ({$targetRowidsStr})");
        }

        return [
            'ok' => true,
            'deleted_count' => count($targetRowids),
            'requested_count' => $deleteCount,
            'message' => 'Deleted latest visible events.',
        ];
    }
}

if (!function_exists('chimDeleteEventLogRow')) {
    function chimDeleteEventLogRow($db, $rowId)
    {
        $rowId = intval($rowId);
        if ($rowId <= 0) {
            return [
                'ok' => false,
                'deleted_count' => 0,
                'message' => 'Invalid event row.',
            ];
        }

        $visibleWhereClause = chimBuildVisibleEventLogWhereClause($db);
        $existing = $db->fetchOne("SELECT rowid FROM eventlog WHERE rowid={$rowId} AND {$visibleWhereClause} LIMIT 1");
        if (!$existing) {
            return [
                'ok' => true,
                'rowid' => $rowId,
                'deleted_count' => 0,
                'message' => 'Event is no longer available.',
            ];
        }

        if (!$db->delete('eventlog', "rowid={$rowId}")) {
            return [
                'ok' => false,
                'rowid' => $rowId,
                'deleted_count' => 0,
                'message' => 'Failed to delete event.',
            ];
        }

        return [
            'ok' => true,
            'rowid' => $rowId,
            'deleted_count' => 1,
            'message' => 'Event deleted.',
        ];
    }
}
