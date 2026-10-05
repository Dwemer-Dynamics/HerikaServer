<?php

// Reject incomplete structured output as a whole; legacy custom prompts may still return one record.
function chimParseMemoryEpisodes($buffer): array
{
    if (!is_string($buffer) || strlen($buffer) > 131072) return [];
    $text = trim($buffer);
    if (preg_match('/^```(?:json)?\s*([\s\S]*?)\s*```$/iD', $text, $match)) {
        $text = trim($match[1]);
    }
    if ($text === '') return [];
    $structured = in_array($text[0], ['{', '[', '`'], true);
    if ($structured) {
        $decoded = json_decode($text, true);
        if (!is_array($decoded) || !isset($decoded['memories']) || !is_array($decoded['memories'])
            || !array_is_list($decoded['memories']) || count($decoded['memories']) < 1
            || count($decoded['memories']) > 12) return [];
        $episodes = [];
        foreach ($decoded['memories'] as $entry) {
            if (!is_array($entry) || !isset($entry['summary'], $entry['tags'])
                || !is_string($entry['summary']) || !is_array($entry['tags'])
                || !array_is_list($entry['tags']) || count($entry['tags']) > 24) return [];
            $body = trim($entry['summary']);
            if ($body === '' || strlen($body) > 16384 || preg_match('/#(?:Summary|Tags):/i', $body)) return [];
            $tags = [];
            foreach ($entry['tags'] as $tag) {
                if (!is_string($tag) || strlen($tag) > 128) return [];
                $tag = preg_replace('/[^\p{L}\p{N}_]/u', '', $tag);
                if ($tag === null || $tag === '') return [];
                $tags[] = '#' . $tag;
            }
            $tags = array_values(array_unique($tags));
            $episodes[] = ['summary' => '#Summary: ' . $body . "\n\n#Tags: " . implode(' ', $tags),
                'tags' => implode(' ', $tags)];
        }
        return $episodes;
    }
    // Require the established marker so error prose and JSON wrapped in commentary stay retryable.
    $text = str_replace('**', '', $text);
    if (!preg_match('/^#Summary:/i', $text)) return [];
    $parts = preg_split('/#Tags:/i', substr($text, 9), 2);
    $body = trim($parts[0]);
    if ($body === '' || preg_match('/^[{\[`]/', $body)) return [];
    return [['summary' => $text, 'tags' => trim($parts[1] ?? '')]];
}

// One statement atomically claims an unfinished bucket and inserts every sibling.
// The conditional UPDATE serializes concurrent writers; a completed source cannot be duplicated.
function chimSaveMemoryEpisodes($db, array $source, array $episodes): bool
{
    if (!$episodes) return false;
    try {
        $saved = $db->fetchOne("WITH episodes AS (
                SELECT episode->>'summary' AS summary, episode->>'tags' AS tags, position
                FROM jsonb_array_elements($1::jsonb) WITH ORDINALITY AS item(episode,position)
            ), canonical AS (
                UPDATE memory_summary ms SET summary=e.summary, tags=e.tags,
                    scope=COALESCE(ms.scope,'global'),
                    native_vec=setweight(to_tsvector(e.tags),'A') || setweight(to_tsvector(e.summary),'B')
                FROM episodes e WHERE e.position=1 AND ms.rowid=$2 AND ms.summary IS NULL
                    AND ms.source_rowid IS NULL AND ms.packed_message IS NOT DISTINCT FROM $3
                    AND ms.uid=$4 AND ms.gamets_truncated=$5
                    AND ms.classifier=$6 AND COALESCE(ms.scope,'global')=COALESCE($7,'global')
                RETURNING ms.*
            ), siblings AS (
                INSERT INTO memory_summary
                    (gamets_truncated,n,packed_message,summary,classifier,uid,companions,scope,tags,native_vec,source_rowid)
                SELECT c.gamets_truncated,0,NULL,e.summary,c.classifier,c.uid,c.companions,c.scope,e.tags,
                    setweight(to_tsvector(e.tags),'A') || setweight(to_tsvector(e.summary),'B'),c.rowid
                FROM canonical c CROSS JOIN episodes e WHERE e.position>1 ORDER BY e.position
                RETURNING rowid
            ) SELECT rowid, (SELECT count(*) FROM siblings)+1 AS episode_count FROM canonical",
            [json_encode($episodes, JSON_THROW_ON_ERROR), intval($source['rowid']), $source['packed_message'],
                $source['uid'], $source['gamets_truncated'], $source['classifier'], $source['scope'] ?? null]);
        return !empty($saved['rowid']) && intval($saved['episode_count']) === count($episodes);
    } catch (Throwable $e) {
        Logger::warn('Memory episode save failed; source remains retryable.');
        return false;
    }
}
