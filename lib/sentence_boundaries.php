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
