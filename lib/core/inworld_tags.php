<?php
/**
 * Inworld TTS paralinguistic tags.
 *
 * The LLM may write short tags in square brackets that Inworld uses for delivery and sounds:
 *   [smug] Nice swing. [laugh]                         (inworld-tts-2-flash: single-word tags from a list)
 *   [mad as hell] What are you doing?! [growl]         (inworld-tts-2: own directions of 1-4 words)
 *
 * One tag dictionary is stored in the Inworld TTS connector metadata (PARALINGUISTIC_TAGS_DICTIONARY, JSON) and
 * edited on ui/core/inworld_tags.php. When the connector is loaded, chimInworldApplyTagSettings() turns it into the
 * values the generic paralinguistic-tag code uses (PARALINGUISTIC_TAGS_PROMPT / _LIST) for the model the connector
 * uses right now, plus PARALINGUISTIC_TAGS_FREEFORM / _SOUNDS / _MAX_STYLE for cleanResponse() and tts-inworld.php.
 */

/** Words that are sounds rather than delivery directions. */
const CHIM_INWORLD_SOUND_WORDS = ['laugh', 'laughs', 'laughing', 'chuckle', 'chuckles', 'giggle', 'growl', 'sigh', 'breathe', 'breath',
    'cough', 'yawn', 'clear throat', 'hmm', 'gasp', 'groan', 'sniff', 'shush', 'scream', 'sob', 'cry', 'hum', 'snort', 'moan', 'grunt',
    'hiccup', 'whistle', 'gulp', 'pant', 'huff', 'scoff', 'tsk', 'whimper'];

function chimInworldTagDefaults(): array
{
    $t = static fn(string $tag, string $type, string $models, string $note, bool $on = true)
        => ['on' => $on, 'tag' => $tag, 'type' => $type, 'models' => $models, 'note' => $note];
    return [
        'template' => "Your lines are voiced by Inworld TTS. Begin each reply with one delivery tag in square brackets that fits its mood, chosen from: {STYLE_TAGS}. "
            . "Add a new one only if the mood clearly changes, at most {MAX_TAGS} per reply. Sounds you may place where they happen: {SOUND_TAGS}. "
            . "Use only these tags and never round brackets for them.",
        'template_free' => "Your lines are voiced by Inworld TTS-2. Begin each reply with one short acting direction in square brackets, 1 to 4 English words, "
            . "that fits this moment and line, e.g. {STYLE_TAGS}. Add a new one only if the mood clearly changes, at most {MAX_TAGS} per reply. "
            . "Sounds you may place where they happen: {SOUND_TAGS}. Never use round brackets for directions.",
        'freeform' => true,
        'max_style' => 2,
        'tags' => [
            $t('smug', 'style', 'both', 'tested on Flash'),
            $t('angry', 'style', 'both', 'tested on Flash'),
            $t('funny', 'style', 'both', 'tested on Flash'),
            $t('miserable', 'style', 'both', 'tested on Flash'),
            $t('devilish', 'style', 'both', 'tested on Flash'),
            $t('flirtatious', 'style', 'both', 'tested on Flash'),
            $t('mad as hell', 'style', 'tts2', 'TTS-2 example'),
            $t('quietly amused', 'style', 'tts2', 'TTS-2 example; Flash ignores multi-word directions'),
            $t('laugh', 'sound', 'both', 'documented by Inworld'),
            $t('chuckle', 'sound', 'both', 'tested on Flash: softer than laugh'),
            $t('growl', 'sound', 'both', 'tested on Flash, not documented'),
            $t('sigh', 'sound', 'both', 'documented by Inworld'),
            $t('breathe', 'sound', 'both', 'documented by Inworld'),
            $t('cough', 'sound', 'both', 'documented by Inworld'),
            $t('yawn', 'sound', 'both', 'documented by Inworld'),
            $t('clear throat', 'sound', 'both', 'documented by Inworld'),
        ],
    ];
}

/** Dictionary from connector metadata (array or JSON string), completed with defaults. */
function chimInworldTagDictionary($raw): array
{
    $data = is_array($raw) ? $raw : (is_string($raw) && $raw !== '' ? json_decode($raw, true) : null);
    if (!is_array($data) || !isset($data['tags']) || !is_array($data['tags'])) {
        return chimInworldTagDefaults();
    }
    return $data + chimInworldTagDefaults();
}

/** Returns [cleanTag, error]. Tags are lower case English words, 1-4 words. */
function chimInworldCleanTag(string $raw): array
{
    $tag = strtolower(trim(trim($raw), "[]() \t"));
    $tag = preg_replace('/\s+/', ' ', $tag);
    if ($tag === '') {
        return ['', ''];
    }
    if (!preg_match("/^[a-z][a-z' -]*$/", $tag)) {
        return ['', "\"$raw\": only English letters, spaces, - and ' are allowed"];
    }
    if (count(explode(' ', $tag)) > 4 || strlen($tag) > 40) {
        return ['', "\"$raw\": at most 4 words"];
    }
    return [$tag, ''];
}

function chimInworldIsFlash(string $modelId): bool
{
    return stripos($modelId, 'flash') !== false;
}

function chimInworldFreeform(array $data, string $modelId): bool
{
    return !empty($data['freeform']) && !chimInworldIsFlash($modelId);
}

/** Enabled tags usable with the model: ['style' => ['[smug]', ...], 'sound' => [...]]. */
function chimInworldTagsForModel(array $data, string $modelId): array
{
    $flash = chimInworldIsFlash($modelId);
    $out = ['style' => [], 'sound' => []];
    foreach ($data['tags'] as $t) {
        if (empty($t['on']) || !isset($t['tag'])) {
            continue;
        }
        $models = $t['models'] ?? 'both';
        if ($models !== 'both' && $models !== ($flash ? 'flash' : 'tts2')) {
            continue;
        }
        $type = ($t['type'] ?? 'style') === 'sound' ? 'sound' : 'style';
        if ($flash && $type === 'style' && strpos($t['tag'], ' ') !== false) {
            continue; // Flash ignores multi-word directions
        }
        $out[$type][] = '[' . $t['tag'] . ']';
    }
    return $out;
}

/** [tag list for cleanResponse(), instruction for the LLM] for the model. */
function chimInworldBuildTags(array $data, string $modelId): array
{
    $tags = chimInworldTagsForModel($data, $modelId);
    $list = implode(',', array_merge($tags['style'], $tags['sound']));
    $free = chimInworldFreeform($data, $modelId);
    $styles = $tags['style'];
    if ($free) {
        // Own directions allowed: show a few examples only, multi-word ones first, so the LLM writes its own.
        usort($styles, static fn($a, $b) => substr_count($b, ' ') <=> substr_count($a, ' '));
        if (count(array_filter($styles, static fn($s) => strpos($s, ' ') !== false)) < 2) {
            $styles = array_merge(['[mad as hell]', '[quietly amused]'], $styles);
        }
        $styles = array_slice(array_values(array_unique($styles)), 0, 6);
    }
    $prompt = strtr($free ? (string)$data['template_free'] : (string)$data['template'], [
        '{STYLE_TAGS}' => $styles ? implode(' ', $styles) : '(none)',
        '{SOUND_TAGS}' => $tags['sound'] ? implode(' ', $tags['sound']) : '(none)',
        '{MAX_TAGS}' => (string)max(1, (int)($data['max_style'] ?? 2)),
    ]);
    return [$list, $prompt];
}

/** All known sound tags (enabled or not), so tts-inworld.php never treats a sound as a direction. */
function chimInworldSoundList(array $data): string
{
    $out = [];
    foreach ($data['tags'] as $t) {
        if (($t['type'] ?? '') === 'sound' && isset($t['tag'])) {
            $out[] = '[' . $t['tag'] . ']';
        }
    }
    foreach (CHIM_INWORLD_SOUND_WORDS as $w) {
        $out[] = '[' . $w . ']';
    }
    return implode(',', array_values(array_unique($out)));
}

/** Called after the Inworld connector metadata was copied into $GLOBALS["TTS"]["INWORLD"]. */
function chimInworldApplyTagSettings(): void
{
    if (empty($GLOBALS["TTS"]["INWORLD"]) || !is_array($GLOBALS["TTS"]["INWORLD"])
        || empty($GLOBALS["TTS"]["INWORLD"]["PARALINGUISTIC_TAGS_ENABLED"])) {
        return;
    }
    $cfg = &$GLOBALS["TTS"]["INWORLD"];
    $data = chimInworldTagDictionary($cfg["PARALINGUISTIC_TAGS_DICTIONARY"] ?? null);
    $modelId = strval($cfg["model_id"] ?? 'inworld-tts-2');
    [$cfg["PARALINGUISTIC_TAGS_LIST"], $cfg["PARALINGUISTIC_TAGS_PROMPT"]] = chimInworldBuildTags($data, $modelId);
    $cfg["PARALINGUISTIC_TAGS_FREEFORM"] = chimInworldFreeform($data, $modelId);
    $cfg["PARALINGUISTIC_TAGS_SOUNDS"] = chimInworldSoundList($data);
    $cfg["PARALINGUISTIC_TAGS_MAX_STYLE"] = max(0, min(5, (int)($data['max_style'] ?? 2)));
    unset($cfg);
}
