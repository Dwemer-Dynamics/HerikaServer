<?php

// Return byte offsets after complete sentence endings, preserving UTF-8 and closing punctuation.
function chimSentenceBoundaries(string $text): iterable
{
    // Require lookahead: a stream ending in "Mr. " or "Really?" may still be incomplete.
    preg_match_all('/[.!?。？！]+["\x{2019}\x{201D}\x{00BB}\x{300D}\x{300F}\x{FF09}\x{3011}\x{0027})\]]*/u', $text, $matches, PREG_OFFSET_CAPTURE);
    $titles = ['mr','mrs','ms','dr','prof','sr','jr','st','capt','sgt','lt','rev'];
    $language = strtolower(str_replace('_', '-', (string)($GLOBALS['CORE_LANG'] ?? 'en')));
    $language = explode('-', $language)[0];
    $localized = [
        'de' => ['hr','fr','bzw','usw','z.b','d.h'],
        'es' => ['sr','sra','srta','dra','ud','uds','p.ej'],
        'fr' => ['m','mme','mlle','pr','p.ex'],
        'it' => ['sig','sig.ra','dott','dott.ssa','ecc'],
        'pt' => ['sr','sra','srta','dra','ex'],
        'pl' => ['mgr','inż','np','tj'],
        'ru' => ['г','ул','им','т.д','т.п'],
    ];
    $abbreviations = array_merge($titles, ['e.g','i.e','vs'], $localized[$language] ?? []);
    foreach ($matches[0] as [$ending, $offset]) {
        // An ASCII stop needs whitespace; CJK stops can delimit adjacent characters.
        $end = $offset + strlen($ending);
        $tail = substr($text, $end);
        if (!preg_match('/\S/u', $tail)) continue;
        if (!preg_match('/^[。？！]/u', $ending) && !preg_match('/^\s/u', $tail)) {
            continue;
        }
        if ($text[$offset] === '.') {
            if (str_starts_with($ending, '..') || ($offset > 0 && $text[$offset - 1] === '.')) {
                continue;
            }
            preg_match('/([\p{L}\p{N}]+(?:\.[\p{L}\p{N}]+)*)$/u', substr($text, 0, $offset), $word);
            $token = $word[1] ?? '';
            // Initials and dotted initialisms remain attached to the following name/word.
            if (preg_match('/^(?:\p{Lu}\.)*\p{Lu}$/u', $token)
                || in_array(mb_strtolower($token, 'UTF-8'), $abbreviations, true)) {
                continue;
            }
        }
        if (hasUnclosedSingleAsteriskBlock(substr($text, 0, $end))) {
            continue;
        }
        yield $end;
    }
}

// Byte ceiling for one subtitle/audio line. The game-side capacity is unconfirmed and a
// 1000-byte subtitle has crashed it, so stay well below that with UTF-8 headroom.
if (!defined('CHIM_SPEECH_SEGMENT_MAX_BYTES')) define('CHIM_SPEECH_SEGMENT_MAX_BYTES', 900);

// Largest offset <= $limit that does not split a UTF-8 character.
function chimUtf8CutOffset(string $text, int $limit): int
{
    if ($limit >= strlen($text)) return strlen($text);
    while ($limit > 0 && (ord($text[$limit]) & 0xC0) === 0x80) $limit--;
    return $limit;
}

// Cut one head of at most $budget bytes, preferring a sentence end, then whitespace, then a
// character boundary. An open *...* span is closed in the head and reopened in the rest.
function chimSpeechSegmentCut(string $text, int $budget): ?array
{
    $limit = $budget - 1; // room for a closing asterisk
    if ($limit < 1) return null;
    $hasContent = static fn(int $cut): bool => (bool)preg_match('/[^\s*]/', substr($text, 0, $cut));
    // Mask single asterisks (same byte length) so boundaries inside narration spans count.
    $window = substr($text, 0, chimUtf8CutOffset($text, $limit + 16));
    $masked = preg_replace('/(?<!\*)\*(?!\*)/', ' ', $window);
    $cut = 0;
    // Invalid UTF-8 cannot be matched by the sentence rules; fall back to whitespace.
    foreach (preg_match('//u', $masked) ? chimSentenceBoundaries($masked) : [] as $end) {
        if ($end > $limit) break;
        if ($hasContent($end)) $cut = $end;
    }
    if ($cut === 0 && preg_match_all('/[ \t\r\n\f\v]/', substr($text, 0, $limit + 1), $spaces, PREG_OFFSET_CAPTURE)) {
        foreach (array_reverse($spaces[0]) as [, $offset]) {
            if ($hasContent($offset)) { $cut = $offset; break; }
        }
    }
    if ($cut === 0) $cut = chimUtf8CutOffset($text, $limit);
    if ($cut === 0) return null;

    $head = rtrim(substr($text, 0, $cut));
    $rest = ltrim(substr($text, $cut));
    if (hasUnclosedSingleAsteriskBlock($head)) {
        if (preg_match('/^\*(?!\*)/', $rest)) {
            // The span closes right at the cut.
            $head .= '*';
            $rest = ltrim(substr($rest, 1));
        } elseif (preg_match('/(?<!\*)\*$/', $head)) {
            // The span opens right at the cut.
            $head = rtrim(substr($head, 0, -1));
            $rest = '*' . $rest;
        } else {
            $head .= '*';
            $rest = '*' . $rest;
        }
    }
    return [$head, $rest];
}

// Split text into speech lines of at most $maxBytes, keeping all words in order. Text
// already within the limit is returned unchanged.
function chimSplitSpeechSegments(string $text, int $maxBytes = CHIM_SPEECH_SEGMENT_MAX_BYTES): array
{
    $segments = [];
    $rest = $text;
    while (strlen($rest) > $maxBytes) {
        $cut = chimSpeechSegmentCut($rest, $maxBytes);
        if ($cut === null) break;
        [$head, $rest] = $cut;
        if ($head !== '') $segments[] = $head;
    }
    if ($rest !== '' || !$segments) $segments[] = $rest;
    return $segments;
}

// Spread split parts over $slots lines in order, leaving '' where a side has fewer parts.
// The pairing is positional only; it does not claim that paired parts mean the same thing.
function chimSpreadSpeechSegments(array $parts, int $slots): array
{
    $parts = array_values($parts);
    $count = count($parts);
    if ($count >= $slots) return $parts;
    $spread = array_fill(0, $slots, '');
    foreach ($parts as $index => $part) {
        $spread[intdiv($index * $slots, $count)] = $part;
    }
    return $spread;
}

// Last-resort byte clamp for text sent to the game; segmentation should make it a no-op.
function chimClampSpeechBytes(string $text, int $maxBytes = CHIM_SPEECH_SEGMENT_MAX_BYTES): string
{
    if (strlen($text) <= $maxBytes) return $text;
    error_log('[SPEECH_SEGMENTS] Clamped ' . strlen($text) . '-byte speech text to ' . $maxBytes . ' bytes');
    return substr($text, 0, chimUtf8CutOffset($text, $maxBytes));
}
