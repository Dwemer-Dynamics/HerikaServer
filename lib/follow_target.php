<?php
/** Resolve player aliases before trusting model-supplied actor reference IDs. */
function chimResolveFollowTarget(string $target, string $playerName, string $speakerName, string $speakerRef = ''): ?string
{
    $target = trim($target);
    $pattern = '/\s*\[\s*RefID\s*:\s*(?:0x)?([0-9a-f]{1,8})\s*\]\s*/i';
    $ref = preg_match($pattern, $target, $match) ? strtoupper(str_pad($match[1], 8, '0', STR_PAD_LEFT)) : '';
    $name = trim((string)preg_replace($pattern, ' ', $target), " \t\r\n,;");
    $aliases = ['player', 'me'];
    if (trim($playerName) !== '') $aliases[] = strtolower(trim($playerName));
    if (in_array(strtolower($name), $aliases, true) || $ref === '00000014') {
        return 'FollowPlayer@';
    }
    $speakerRef = strtoupper(str_pad(preg_replace('/^0x/i', '', trim($speakerRef)), 8, '0', STR_PAD_LEFT));
    if ($target === '' || ($ref === '' && $name !== '' && strcasecmp($name, trim($speakerName)) === 0)
        || ($ref !== '' && $speakerRef !== '00000000' && $ref === $speakerRef)) {
        return null;
    }
    // A valid ID belonging to another actor remains intact for duplicate-name support.
    return 'Follow@' . $target;
}

function chimResolveFollowCommand(string $speaker, string $target): ?string
{
    $ref = '';
    if (isset($GLOBALS['db'])) {
        $escaped = $GLOBALS['db']->escape($speaker);
        $row = $GLOBALS['db']->fetchOne("SELECT refid FROM core_npc_master WHERE lower(npc_name)=lower('$escaped') LIMIT 1");
        $ref = (string)($row['refid'] ?? '');
    }
    return chimResolveFollowTarget($target, (string)($GLOBALS['PLAYER_NAME'] ?? ''), $speaker, $ref);
}
