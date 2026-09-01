<?php

// Detect direct Narrator requests whose meaning depends on stored diary continuity.
function chimShouldForceNarratorDiaryRecall(array $gameRequest): bool
{
    if (($gameRequest[0] ?? '') !== 'narrator_inputtext') {
        return false;
    }

    $input = mb_strtolower(trim((string)($gameRequest[3] ?? '')), 'UTF-8');
    if ($input === '') {
        return false;
    }

    $mentionsDiary = preg_match('/\b(?:diary|journal)\b/u', $input) === 1;
    $requestsRecall = preg_match('/\b(?:use|read|check|consult|pull|from|remember|recall|refer|according)\b/u', $input) === 1;
    if ($mentionsDiary && $requestsRecall) {
        return true;
    }

    $continuesPriorScene = preg_match('/\b(?:continue|resume|return(?:ing)?|back)\b/u', $input) === 1;
    $referencesPriorScene = preg_match('/\b(?:scene|story|plot|character|person|agent)\b/u', $input) === 1;
    return $continuesPriorScene && $referencesPriorScene;
}

// Keep explicit continuity requests eligible even when the matching diary is inside the normal recent window.
function chimShouldDiscardRecentMemory(float $hoursAgo, float $contextHours, bool $forceRecall): bool
{
    return !$forceRecall && $hoursAgo <= $contextHours;
}

function dataGetMemoryClassifierConditionSql(bool $diaryOnly, string $column = 'classifier'): string
{
    return $diaryOnly
        ? "$column IN ('diary','auto_diary','backgroundlife_diary')"
        : 'TRUE';
}
