<?php

if (!function_exists('chimEventHasBackgroundChatMarker')) {
    function chimEventHasBackgroundChatMarker($data): bool
    {
        return preg_match('/\bbackground\s+chat\s*\)/iu', (string)$data) === 1;
    }
}

if (!function_exists('chimNormalizeLoggedEventType')) {
    function chimNormalizeLoggedEventType($eventType, $data = ''): string
    {
        $eventType = trim((string)$eventType);
        $normalized = strtolower($eventType);

        if ($normalized === 'chat_background') {
            return 'chat_background';
        }

        if ($normalized === 'chat' && chimEventHasBackgroundChatMarker($data)) {
            return 'chat_background';
        }

        return $eventType;
    }
}

if (!function_exists('chimEffectiveEventType')) {
    function chimEffectiveEventType(array $row): string
    {
        return strtolower(chimNormalizeLoggedEventType(
            $row['type'] ?? '',
            $row['data'] ?? ''
        ));
    }
}

if (!function_exists('chimFilterRowsByEventType')) {
    function chimFilterRowsByEventType(array $rows, $filter): array
    {
        $blocked = array_values(array_filter(array_map(
            static fn($type): string => strtolower(trim((string)$type)),
            explode(',', (string)$filter)
        ), static fn(string $type): bool => $type !== ''));

        if (empty($blocked)) {
            return $rows;
        }

        return array_filter($rows, static function (array $row) use ($blocked): bool {
            return !in_array(chimEffectiveEventType($row), $blocked, true);
        });
    }
}

if (!function_exists('chimIsMagicCastEvent')) {
    function chimIsMagicCastEvent($eventType, $data = ''): bool
    {
        $eventType = strtolower(trim((string)$eventType));
        if ($eventType === 'spellcast' || $eventType === 'npcspellcast') {
            return true;
        }

        // CHIM AI cast commands log "<actor> casts <spell> at <target>" with no
        // marker prefix or sentence punctuation; matching prose is indistinguishable.
        return $eventType === 'infoaction'
            && preg_match('/^[^\[#<(\r\n][^\r\n]*? casts [^\r\n]+? at [^\r\n]*?[^.!?\s]\s*$/u', (string)$data) === 1;
    }
}

if (!function_exists('chimMagicEventBlacklisted')) {
    function chimMagicEventBlacklisted($eventType, $data = ''): bool
    {
        static $cachedBlacklist = null;
        static $terms = [];

        $blacklist = (string)($GLOBALS['MAGIC_EVENT_BLACKLIST'] ?? '');
        if (trim($blacklist) === '' || !chimIsMagicCastEvent($eventType, $data)) {
            return false;
        }

        if ($blacklist !== $cachedBlacklist) {
            $cachedBlacklist = $blacklist;
            $terms = array_values(array_filter(
                array_map('trim', explode(',', strtolower($blacklist))),
                static fn(string $term): bool => $term !== '' && $term !== '0'
            ));
        }

        $data = strtolower((string)$data);
        foreach ($terms as $term) {
            if (strpos($data, $term) !== false) {
                return true;
            }
        }

        return false;
    }
}
