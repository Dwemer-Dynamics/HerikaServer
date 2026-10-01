<?php
/**
 * RELATIONSHIP SYSTEM - NPC Save Handler
 *
 * This file processes the relationships_jsonb field when an NPC is saved.
 * It merges the relationship data into extended_data.relationships.
 *
 * INSTALLATION:
 * Add this line in npc_master.php BEFORE the extended_data processing (around line 195):
 *
 *   // Merge relationship editor data into extended_data
 *   if (file_exists(__DIR__."/../../ext/relationship_system/npc_save_handler.php")) {
 *       include(__DIR__."/../../ext/relationship_system/npc_save_handler.php");
 *   }
 */

// Ensure Logger is available (parent may have already loaded it)
if (!class_exists('Logger')) {
    require_once $GLOBALS["ENGINE_PATH"] . "lib/logger.php";
}
if (!class_exists('RelationshipManager')) {
    require_once $GLOBALS["ENGINE_PATH"] . "lib/relationship_manager.php";
}

if (!class_exists('ChimRelationshipFormConflict')) {
    /** A stale relationship form (stored target or extended_data changed since it was opened). */
    class ChimRelationshipFormConflict extends RuntimeException {}
}

// Only process if relationships_jsonb was submitted
if (isset($_POST['relationships_jsonb']) && $_POST['relationships_jsonb'] !== '') {
    $relJsonbRaw = $_POST['relationships_jsonb'];
    $relData = json_decode($relJsonbRaw, true);

    if (is_array($relData)) {
        foreach ($relData as &$relationship) {
            if (is_array($relationship) && array_key_exists('custom_info', $relationship)) {
                $relationship['custom_info'] = is_scalar($relationship['custom_info'])
                    ? trim((string)$relationship['custom_info'])
                    : '';
            }
        }
        unset($relationship);
        $relData = RelationshipManager::normalizeRelationshipMap($relData);

        // Get existing extended_data
        $extRaw = isset($_POST['extended_data']) ? (string)$_POST['extended_data'] : '{}';
        $extData = json_decode($extRaw, true);
        if (!is_array($extData)) {
            $extData = [];
        }

        // Get the old relationships for logging
        $oldRels = $extData['relationships'] ?? [];

        // Typed targets are server-owned. A stored actor/concept edge keeps its stored target; a posted target
        // that no longer matches it means the form is stale (refused). A new actor edge needs the selected
        // physical row id and key, validated here and rechecked under row locks in the guarded write; it is
        // stored under the effective (keeper) key with the selected row's label. New concepts must be explicit.
        $storedRels = is_array($oldRels) ? $oldRels : [];
        $postedTargets = is_array($decodedRaw = json_decode($relJsonbRaw, true)) ? $decodedRaw : [];
        $sourceId = (int)($_POST['id'] ?? 0);
        $sourceRow = $sourceId > 0 && isset($npc) ? $npc->getActorById($sourceId) : null;
        $validatedRels = [];
        $targetRows = [];
        foreach ($relData as $relKey => $rel) {
            $relKey = (string)$relKey;
            $postedTarget = $postedTargets[$relKey]['target'] ?? null;
            $storedTarget = $storedRels[$relKey]['target'] ?? null;
            unset($rel['target'], $rel['target_npc_id']);
            if (array_key_exists($relKey, $storedRels)) {
                $playerTarget = $relKey === 'Player' && is_array($postedTarget) && ($postedTarget['kind'] ?? '') === 'player';
                if ($postedTarget !== null && !$playerTarget && $postedTarget != $storedTarget) {
                    throw new ChimRelationshipFormConflict('Relationship "' . ($storedTarget['label'] ?? $relKey)
                        . '" no longer has that target. Reopen this NPC before saving.');
                }
                if (is_array($storedTarget)) { $rel['target'] = $storedTarget; }
                $validatedRels[$relKey] = $rel;
                continue;
            }
            $kind = is_array($postedTarget) ? (string)($postedTarget['kind'] ?? '') : '';
            if ($kind === 'actor') {
                if (!$sourceRow || ($postedTarget['key'] ?? null) !== $relKey) {
                    throw new InvalidArgumentException('Save this NPC before choosing an actor relationship target.');
                }
                $taken = array_merge(array_map('strval', array_keys($storedRels)), array_map('strval', array_keys($validatedRels)),
                    array_values(array_filter(array_map('strval', array_keys($relData)), static fn($k) => $k !== $relKey)));
                $targetId = (int)($postedTargets[$relKey]['target_npc_id'] ?? 0);
                [$identity, $targetRow] = RelationshipManager::validateNewActorTarget($relKey, $targetId, $sourceRow, $taken);
                RelationshipManager::storeTargetEdge($validatedRels, $identity, null, $rel);
                $targetRows[$targetId] = $targetRow;
                continue;
            }
            if ($kind === 'concept') {
                $label = $postedTarget['label'] ?? null;
                if (!is_string($label) || $label !== trim($label) || $label !== $relKey || ($postedTarget['key'] ?? null) !== $relKey
                    || chimIsActorKey($label) || RelationshipManager::normalizeTargetName($label) === 'Player') {
                    throw new InvalidArgumentException('Invalid concept relationship "' . $relKey . '"');
                }
                RelationshipManager::storeTargetEdge($validatedRels, ['kind' => 'concept', 'key' => $relKey, 'label' => $label], null, $rel);
                continue;
            }
            // Plain-name entries stay legacy/display-only; an actor-shaped key without a validated target is dropped.
            if ($postedTarget === null && !RelationshipManager::isActorEdgeKey($relKey)) {
                $validatedRels[$relKey] = $rel;
            }
        }
        $relData = $validatedRels;
        if ($targetRows) {
            // Binding and physical/keeper keys of new actor targets are rechecked under row locks in the write.
            foreach ($targetRows as $targetId => $targetRow) {
                $_POST['_expected_bindings'][$targetId] = chimNpcProfileBinding($targetRow);
            }
            $_POST['_expected_keys'] = RelationshipManager::expectedKeysFor(array_values($targetRows));
        }

        // Merge the new relationships
        $extData['relationships'] = $relData;

        // RELATIONSHIP LOCK: persist the editor's lock checkbox (hidden field always submits 0/1) so the relationship
        // model skips this NPC and stops overwriting manual edits.
        if (isset($_POST['relationships_locked'])) {
            $extData['relationships_locked'] = filter_var($_POST['relationships_locked'], FILTER_VALIDATE_BOOLEAN);
        }

        // Log the changes
        $npcName = $_POST['npc_name'] ?? 'Unknown';
        $changeCount = 0;

        foreach ($relData as $target => $newData) {
            $oldData = $oldRels[$target] ?? null;

            if ($oldData === null) {
                // New relationship
                Logger::info("[REL-UI] {$npcName}: Added relationship -> {$target}: aff={$newData['aff']}, type={$newData['type']}");
                $changeCount++;
            } elseif ($oldData['aff'] !== $newData['aff'] || $oldData['type'] !== $newData['type']) {
                // Modified relationship
                $oldAff = $oldData['aff'] ?? 0;
                $oldType = $oldData['type'] ?? 'neutral';
                Logger::info("[REL-UI] {$npcName}: Updated {$target}: aff {$oldAff} -> {$newData['aff']}, type {$oldType} -> {$newData['type']}");
                $changeCount++;
            }
        }

        // Log removed relationships
        foreach ($oldRels as $target => $oldData) {
            if (!isset($relData[$target])) {
                Logger::info("[REL-UI] {$npcName}: Removed relationship -> {$target}");
                $changeCount++;
            }
        }

        if ($changeCount > 0) {
            Logger::info("[REL-UI] {$npcName}: {$changeCount} relationship change(s) saved via UI");
        }

        // Update extended_data in POST. The structured editor is authoritative;
        // prevent the deprecated legacy textarea named "relationships" from
        // being treated as a relationship seed later by NpcMaster normalization.
        $_POST['extended_data'] = json_encode($extData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        unset($_POST['relationships'], $_POST['npc_relationships']);
    }
}
