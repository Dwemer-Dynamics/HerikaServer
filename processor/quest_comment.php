<?php

// Resolve one quest speaker before the normal profile/connector loading path.
$GLOBALS['QUEST_COMMENT_SELECTED'] = false;
$questText = trim((string)($gameRequest[3] ?? ''));
if ($questText === '' || stripos($questText, 'Storyline Tracker') !== false
    || str_contains($questText, 'quest ""')
    || ((float)$gameRequest[2] >= 13333332 && (float)$gameRequest[2] <= 13333334)
    || !chimInteractionAllowed()) {
    Logger::debug('[QUEST_COMMENT] Ignored excluded or passive quest event');
    return;
}

$narrator = new Narrator();
$cooldownSeconds = max(1, min(60, $narrator->getInt('quest_comment_cooldown', 3))) * 60;
$lastComment = $db->fetchOne("SELECT value FROM conf_opts WHERE id='QUEST_COMMENT_LAST_TIMESTAMP'");
if ($lastComment && time() - (int)$lastComment['value'] < $cooldownSeconds) {
    Logger::debug('[QUEST_COMMENT] Shared cooldown active');
    return;
}

// New clients supply only nearby NPCs eligible for automatic dialogue. Old clients
// without this snapshot keep their existing Narrator-only behavior.
$snapshot = json_decode(base64_decode((string)($gameRequest[4] ?? ''), true) ?: '', true);
$names = is_array($snapshot) && ($snapshot['source'] ?? '') === 'quest_objective_v1'
    && is_array($snapshot['speakers'] ?? null) ? array_slice($snapshot['speakers'], 0, 32) : [];
$names = array_values(array_unique(array_filter($names, static function ($name) {
    return is_string($name) && $name !== '' && $name !== Narrator::CANONICAL_NAME
        && $name !== ($GLOBALS['PLAYER_NAME'] ?? '');
})));
$candidates = [];
if ($names) {
    $quotedNames = array_map(static fn($name) => "'" . $db->escape($name) . "'", $names);
    $rows = $db->fetchAll('SELECT n.npc_name, n.metadata, n.extended_data, p.metadata AS profile_metadata '
        . 'FROM core_npc_master n JOIN core_profiles p ON p.id=n.profile_id '
        . 'WHERE n.npc_name IN (' . implode(',', $quotedNames) . ')');
    foreach ($rows as $npc) {
        $settings = json_decode($npc['profile_metadata'] ?? '{}', true);
        $settings = is_array($settings) ? $settings : [];
        // Match normal loading: NPC metadata, then extended overrides, win over the profile.
        foreach (['metadata', 'extended_data'] as $field) {
            $overrides = json_decode($npc[$field] ?? '{}', true);
            foreach (is_array($overrides) ? $overrides : [] as $key => $value) {
                if (!empty($value) || is_numeric($value) || is_bool($value)) {
                    $settings[$key] = $value;
                }
            }
        }
        if (filter_var($settings['QUEST_COMMENT'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $candidates[] = ['name' => $npc['npc_name'], 'chance' => max(0, min(100, (int)($settings['QUEST_COMMENT_CHANCE'] ?? 10)))];
        }
    }
}
// Roll once for one enabled NPC, so a larger party does not multiply the chance.
if ($candidates) {
    $candidate = $candidates[random_int(0, count($candidates) - 1)];
    if (random_int(1, 100) <= $candidate['chance']) {
        $_GET['profile'] = md5($candidate['name']);
        $GLOBALS['QUEST_COMMENT_SPEAKER'] = $candidate['name'];
        $GLOBALS['QUEST_COMMENT_SELECTED'] = true;
        Logger::info('[QUEST_COMMENT] Selected NPC ' . $candidate['name']);
        return;
    }
}

if ($narrator->getBool('enabled', true) && $narrator->getBool('quest_comment_enabled', false)
    && random_int(1, 100) <= max(0, min(100, $narrator->getInt('quest_comment_chance', 10)))) {
    $_GET['profile'] = md5(Narrator::CANONICAL_NAME);
    $gameRequest[0] = 'narrator_quest_comment';
    $GLOBALS['QUEST_COMMENT_SELECTED'] = true;
    Logger::info('[QUEST_COMMENT] Selected Narrator fallback');
} else {
    Logger::debug('[QUEST_COMMENT] No speaker selected');
}
