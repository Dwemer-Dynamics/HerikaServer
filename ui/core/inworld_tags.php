<?php
/**
 * Inworld TTS tag editor.
 * Edits the paralinguistic tag dictionary and stores it in every Inworld TTS connector
 * (metadata PARALINGUISTIC_TAGS_DICTIONARY). See lib/core/inworld_tags.php.
 */

$enginePath = __DIR__ . DIRECTORY_SEPARATOR . "../../";

require_once($enginePath . "lib" . DIRECTORY_SEPARATOR . "runtime_bootstrap.php");
chimRuntimeBootstrap($enginePath, [
    'load_general_settings' => true,
    'load_player_name' => true,
    'load_narrator' => true,
    'load_tts_connector' => true,
]);
require_once($enginePath . "lib" . DIRECTORY_SEPARATOR . "logger.php");
require_once($enginePath . "lib" . DIRECTORY_SEPARATOR . "core" . DIRECTORY_SEPARATOR . "api_badge.class.php");
require_once($enginePath . "lib" . DIRECTORY_SEPARATOR . "core" . DIRECTORY_SEPARATOR . "tts_connector.class.php");

$scriptPath = $_SERVER['SCRIPT_NAME'] ?? '';
$uiPos = strpos($scriptPath, '/ui/');
$webRoot = ($uiPos !== false) ? rtrim(substr($scriptPath, 0, $uiPos), '/') : '';
$isEmbed = isset($_GET['embed']) && strval($_GET['embed']) === '1';

require_once($enginePath . "lib" . DIRECTORY_SEPARATOR . "core" . DIRECTORY_SEPARATOR . "inworld_tags.php");

function iwtH($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function iwtDefaults(): array
{
    return chimInworldTagDefaults();
}

function iwtCleanTag(string $raw): array
{
    return chimInworldCleanTag($raw);
}

function iwtIsFlash(string $modelId): bool
{
    return chimInworldIsFlash($modelId);
}

function iwtFreeFor(array $data, string $modelId): bool
{
    return chimInworldFreeform($data, $modelId);
}

function iwtBuild(array $data, string $modelId): array
{
    return chimInworldBuildTags($data, $modelId);
}

const IWT_SOUND_WORDS = CHIM_INWORLD_SOUND_WORDS;

/**
 * Parse pasted text. Accepted per line:
 *   tag | style|sound | both|tts2|flash | note      (export format)
 *   [smug], [angry], [laugh]                          (any text with [tags] in it)
 *   smug                                              (one bare tag per line)
 * Lines starting with # are ignored. Returns [rows, skippedCount].
 */
function iwtParseImport(string $text, bool $on): array
{
    $rows = [];
    $skipped = 0;
    $add = static function (string $raw, ?string $type, ?string $models, string $note) use (&$rows, &$skipped, $on) {
        [$tag, $err] = iwtCleanTag($raw);
        if (in_array($tag, ['reset', 'tag', 'tags', 'style', 'sound'], true)) {
            return; // control word or placeholder, not a tag
        }
        if ($tag === '' || $err !== '') {
            if (trim($raw) !== '') $skipped++;
            return;
        }
        $type = in_array($type, ['style', 'sound'], true) ? $type : (in_array($tag, IWT_SOUND_WORDS, true) ? 'sound' : 'style');
        if (!in_array($models, ['both', 'tts2', 'flash'], true)) {
            $models = ($type === 'style' && strpos($tag, ' ') !== false) ? 'tts2' : 'both';
        }
        $rows[$tag] = ['on' => $on, 'tag' => $tag, 'type' => $type, 'models' => $models, 'note' => mb_substr($note !== '' ? $note : 'imported', 0, 120)];
    };
    foreach (preg_split('/\r\n|\r|\n/', $text) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        if (strpos($line, '|') !== false && strpos($line, '[') === false) {
            $p = array_map('trim', explode('|', $line));
            $add($p[0], strtolower($p[1] ?? ''), strtolower($p[2] ?? ''), $p[3] ?? '');
            continue;
        }
        if (preg_match_all('/\[([^\[\]]{1,60})\]/', $line, $m)) {
            foreach ($m[1] as $t) $add($t, null, null, '');
            continue;
        }
        if (strpos($line, ',') !== false) {
            foreach (explode(',', $line) as $t) $add($t, null, null, '');
            continue;
        }
        $add($line, null, null, '');
    }
    return [array_values($rows), $skipped];
}

function iwtExportText(array $data): string
{
    $out = "# tag | style/sound | both/tts2/flash | note\n";
    foreach ($data['tags'] as $t) {
        $out .= $t['tag'] . ' | ' . $t['type'] . ' | ' . $t['models'] . ' | ' . ($t['note'] ?? '') . "\n";
    }
    return $out;
}

$ttsConnector = new TTSConnector();
$inworld = array_values(array_filter($ttsConnector->readAll() ?: [], static function ($row) use ($ttsConnector) {
    return $ttsConnector->normalizeDriverValue($row['driver'] ?? '') === 'inworld';
}));

$message = '';
$importNote = null;
$errors = [];
// The dictionary is the same in every Inworld connector; read it from the first one that has it.
$data = iwtDefaults();
foreach ($inworld as $row) {
    $meta = $ttsConnector->decodeMetadata($row['metadata'] ?? '{}');
    if (!empty($meta['PARALINGUISTIC_TAGS_DICTIONARY'])) {
        $data = chimInworldTagDictionary($meta['PARALINGUISTIC_TAGS_DICTIONARY']);
        break;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'save';
    if ($action === 'defaults') {
        $data = iwtDefaults();
    } elseif ($action === 'reset_text') {
        $data['template'] = iwtDefaults()['template'];
        $data['template_free'] = iwtDefaults()['template_free'];
    } elseif ($action === 'import') {
        $importText = (string)($_POST['import_text'] ?? '');
        if (!empty($_FILES['import_file']['tmp_name']) && is_uploaded_file($_FILES['import_file']['tmp_name'])
            && ($_FILES['import_file']['size'] ?? 0) < 512000) {
            $importText .= "\n" . file_get_contents($_FILES['import_file']['tmp_name']);
        }
        [$imported, $skipped] = iwtParseImport($importText, !empty($_POST['import_on']));
        if (!$imported) {
            $errors[] = 'nothing to import (paste tags like [smug], [mad as hell], [laugh] or the export format)';
        } else {
            $replace = ($_POST['import_mode'] ?? 'add') === 'replace';
            $byTag = [];
            if (!$replace) {
                foreach ($data['tags'] as $t) $byTag[$t['tag']] = $t;
            }
            $added = 0;
            foreach ($imported as $t) {
                if (!$replace && isset($byTag[$t['tag']])) continue;
                $byTag[$t['tag']] = $t;
                $added++;
            }
            $data['tags'] = array_values($byTag);
            $importNote = "Imported $added tag(s)" . ($skipped ? ", skipped $skipped invalid" : '') . ($replace ? ' (list replaced)' : ', existing tags kept') . '. ';
        }
    } else {
        $tags = [];
        $seen = [];
        foreach (($_POST['tags'] ?? []) as $row) {
            [$tag, $err] = iwtCleanTag((string)($row['tag'] ?? ''));
            if ($err !== '') {
                $errors[] = $err;
                continue;
            }
            if ($tag === '' || isset($seen[$tag])) {
                continue;
            }
            $seen[$tag] = true;
            $tags[] = [
                'on' => !empty($row['on']),
                'tag' => $tag,
                'type' => ($row['type'] ?? '') === 'sound' ? 'sound' : 'style',
                'models' => in_array($row['models'] ?? '', ['both', 'tts2', 'flash'], true) ? $row['models'] : 'both',
                'note' => mb_substr(trim((string)($row['note'] ?? '')), 0, 120),
            ];
        }
        $template = trim((string)($_POST['template'] ?? ''));
        $templateFree = trim((string)($_POST['template_free'] ?? ''));
        $data = [
            'template' => $template !== '' ? $template : iwtDefaults()['template'],
            'template_free' => $templateFree !== '' ? $templateFree : iwtDefaults()['template_free'],
            'freeform' => !empty($_POST['freeform']),
            'max_style' => max(0, min(5, (int)($_POST['max_style'] ?? 2))),
            'tags' => $tags,
        ];
    }

    if (!$errors) {
        $dictionary = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $updated = 0;
        foreach ($inworld as $row) {
            $meta = $ttsConnector->decodeMetadata($row['metadata'] ?? '{}');
            $meta['PARALINGUISTIC_TAGS_DICTIONARY'] = $dictionary;
            $ttsConnector->update($row['id'], ['driver' => $row['driver'], 'metadata' => json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
            $updated++;
        }
        $message = ($importNote ?? '') . ($action === 'defaults' ? 'Defaults restored. ' : ($action === 'reset_text' ? 'Instructions reset. ' : 'Tags saved. '))
            . "Updated $updated Inworld TTS connector(s). New dialogue uses them right away.";
        $inworld = array_values(array_filter($ttsConnector->readAll() ?: [], static function ($row) use ($ttsConnector) {
            return $ttsConnector->normalizeDriverValue($row['driver'] ?? '') === 'inworld';
        }));
    }
}

if (!$isEmbed) {
    require_once(__DIR__ . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . "profile_loader.php");
}

$TITLE = "Inworld TTS Tags";
ob_start();
include(__DIR__ . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . "tmpl" . DIRECTORY_SEPARATOR . "head.html");
if (!$isEmbed) {
    include(__DIR__ . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . "tmpl" . DIRECTORY_SEPARATOR . "navbar.php");
}
?>
<link rel="stylesheet" href="<?php echo $webRoot; ?>/ui/css/main.css?v=<?php echo (int) @filemtime(dirname(__DIR__) . '/css/main.css'); ?>">
<style>
main { padding: <?php echo $isEmbed ? '10px 5px 5px' : '30px 5px 5px'; ?>; }
.iwt { max-width: 1200px; margin: 0 auto; }
.iwt .box { background: linear-gradient(180deg, rgba(42,42,42,.95), rgba(34,34,34,.98)); border: 1px solid #3a3a3a; border-radius: 10px; padding: 14px; margin-bottom: 16px; }
.iwt h2 { margin: 0 0 10px; color: #f3d6a8; font-size: 1.15em; }
.iwt .help { color: #8fa0bb; font-size: 12px; line-height: 1.5; margin: 4px 0 10px; }
.iwt .notice { margin-bottom: 14px; padding: 10px 12px; border-radius: 8px; border: 1px solid rgba(242,124,17,.25); background: rgba(42,42,42,.9); color: #e7cfac; }
.iwt .notice.bad { border-color: #a33; color: #ffb4b4; }
.iwt table { width: 100%; border-collapse: collapse; }
.iwt th { text-align: left; color: #c9b38f; font-weight: 600; font-size: 13px; padding: 6px; border-bottom: 1px solid #3a3a3a; }
.iwt td { padding: 4px 6px; border-bottom: 1px solid #2c2c2c; vertical-align: middle; }
.iwt input[type=text], .iwt select, .iwt textarea { width: 100%; box-sizing: border-box; background: rgba(26,26,26,.82); color: #eef3ff; border: 1px solid #3a3a3a; border-radius: 6px; padding: 7px 9px; font: inherit; }
.iwt textarea { min-height: 110px; resize: vertical; }
.iwt .tagcell input { font-family: monospace; }
.iwt .actions { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 12px; }
.iwt .actions button { margin: 0; }
.iwt pre { white-space: pre-wrap; word-break: break-word; background: #0c0f14; color: #cfd8e6; border-radius: 8px; padding: 10px; font-size: 12px; margin: 6px 0 0; }
.iwt .ok { color: #6dd19c; } .iwt .off { color: #ffb862; }
@media (max-width: 800px) { .iwt .hide-sm { display: none; } }
</style>

<main>
<div class="iwt">
    <div class="page-header chim-page-head">
        <h1 class="api-title chim-page-head-title">Inworld TTS Tags</h1>
        <p class="page-subtitle chim-page-head-note">Delivery and sound tags the LLM may write for Inworld TTS.</p>
    </div>

    <?php if ($message): ?><div class="notice"><?php echo iwtH($message); ?></div><?php endif; ?>
    <?php if ($errors): ?><div class="notice bad">Not saved: <?php echo iwtH(implode('; ', $errors)); ?></div><?php endif; ?>

    <form method="post">
    <div class="box">
        <h2>Tags</h2>
        <p class="help">
            Write tags without brackets, up to 4 words. <b>Style</b> = how the sentence is spoken (<code>[smug]</code>, <code>[mad as hell]</code>), <b>Sound</b> = a sound at that spot (<code>[laugh]</code>, <code>[chuckle]</code>).<br>
            <b>Flash</b> (inworld-tts-2-flash) ignores multi-word style tags, so they are only offered to <b>TTS-2</b> connectors. Tags not in the list are removed by CHIM before speech.
        </p>
        <table id="iwt_table">
            <thead><tr><th><input type="checkbox" title="All on / off" onclick="document.querySelectorAll('#iwt_table tbody input[type=checkbox]').forEach(c => c.checked = this.checked)"> On</th><th>Tag</th><th>Type</th><th>Models</th><th class="hide-sm">Note</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($data['tags'] as $i => $t): ?>
                <tr>
                    <td><input type="checkbox" name="tags[<?php echo $i; ?>][on]" value="1" <?php echo !empty($t['on']) ? 'checked' : ''; ?>></td>
                    <td class="tagcell"><input type="text" name="tags[<?php echo $i; ?>][tag]" value="<?php echo iwtH($t['tag']); ?>"></td>
                    <td><select name="tags[<?php echo $i; ?>][type]">
                        <option value="style" <?php echo ($t['type'] ?? '') === 'style' ? 'selected' : ''; ?>>Style</option>
                        <option value="sound" <?php echo ($t['type'] ?? '') === 'sound' ? 'selected' : ''; ?>>Sound</option>
                    </select></td>
                    <td><select name="tags[<?php echo $i; ?>][models]">
                        <option value="both" <?php echo ($t['models'] ?? '') === 'both' ? 'selected' : ''; ?>>Both</option>
                        <option value="tts2" <?php echo ($t['models'] ?? '') === 'tts2' ? 'selected' : ''; ?>>TTS-2 only</option>
                        <option value="flash" <?php echo ($t['models'] ?? '') === 'flash' ? 'selected' : ''; ?>>Flash only</option>
                    </select></td>
                    <td class="hide-sm"><input type="text" name="tags[<?php echo $i; ?>][note]" value="<?php echo iwtH($t['note'] ?? ''); ?>"></td>
                    <td><button type="button" class="btn-danger" onclick="this.closest('tr').remove()">✕</button></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <div class="actions"><button type="button" class="btn-primary" id="iwt_add">+ Add tag</button></div>
    </div>

    <div class="box">
        <h2>Instruction for the LLM</h2>
        <label style="display:block;margin-bottom:8px"><input type="checkbox" name="freeform" value="1" <?php echo !empty($data['freeform']) ? 'checked' : ''; ?>>
            <b>TTS-2: let the LLM write its own tags</b> (1–4 English words). The style tags above are then only examples. Flash always uses the list.</label>
        <label style="display:block;margin-bottom:8px"><b>Direction tags per reply, at most:</b>
            <input type="number" name="max_style" min="0" max="5" value="<?php echo (int)($data['max_style'] ?? 2); ?>" style="width:70px;display:inline-block">
            <span class="help">0 = no limit. Extra direction tags are removed before speech. A direction is carried over to the following sentences of the same reply, so one tag at the start covers the whole reply. Sound tags are not counted.</span></label>
        <p class="help"><code>{MAX_TAGS}</code> = this limit. <code>{STYLE_TAGS}</code> and <code>{SOUND_TAGS}</code> are replaced with the enabled tags that fit each connector's model.</p>
        <label class="help" style="display:block">TTS-2 with own tags:</label>
        <textarea name="template_free"><?php echo iwtH($data['template_free']); ?></textarea>
        <label class="help" style="display:block;margin-top:10px">Flash, and TTS-2 when own tags are off (list only):</label>
        <textarea name="template"><?php echo iwtH($data['template']); ?></textarea>
        <div class="actions">
            <button type="submit" class="btn-save" name="action" value="save">Save and apply</button>
            <button type="submit" class="btn-secondary" name="action" value="reset_text" onclick="return confirm('Replace both instructions with the default text? Tags are kept.');">Reset instructions</button>
            <button type="submit" class="btn-secondary" name="action" value="defaults" onclick="return confirm('Replace all tags and the instruction with the defaults?');">Restore defaults</button>
        </div>
    </div>
    </form>

    <div class="box">
        <h2>Import tags</h2>
        <p class="help">
            Paste tags in any of these forms and press <b>Import</b>:<br>
            • tags in square brackets, e.g. <code>[smug], [mad as hell], [laugh]</code> (every [ ] in the pasted text is taken as a tag, so paste a list, not a whole document)<br>
            • one tag per line, e.g. <code>eager</code><br>
            • the export format below: <code>tag | style/sound | both/tts2/flash | note</code><br>
            Sound tags (laugh, sigh, growl…) are recognised automatically, multi-word style tags are set to <b>TTS-2 only</b>. Lines starting with <code>#</code> are ignored.
        </p>
        <form method="post" enctype="multipart/form-data">
            <p class="help" style="margin:0 0 6px">Choose a .txt file <input type="file" name="import_file" accept=".txt,.md,.csv"> or paste below.</p>
            <textarea name="import_text" placeholder="[smug] [angry] [mad as hell] [quietly amused] [laugh] [chuckle]"></textarea>
            <div class="actions" style="align-items:center">
                <label><input type="radio" name="import_mode" value="add" checked> Add to the list (keep existing)</label>
                <label><input type="radio" name="import_mode" value="replace"> Replace the whole list</label>
                <label><input type="checkbox" name="import_on" value="1"> Turn imported tags on</label>
                <button type="submit" class="btn-save" name="action" value="import">Import</button>
            </div>
        </form>
        <h2 style="margin-top:16px">Export</h2>
        <p class="help">Copy this to back up or share your list. It can be imported again.</p>
        <textarea readonly onclick="this.select()"><?php echo iwtH(iwtExportText($data)); ?></textarea>
    </div>

    <div class="box">
        <h2>Inworld TTS connectors</h2>
        <p class="help">Saving stores these tags in every connector below. The tag list and the instruction are built for the model a connector uses at the moment it speaks, so changing the model needs no new save here. Turn tags on with <b>Paralinguistic Tags Enabled</b> in the connector. Below is exactly what each connector sends.</p>
        <?php if (!$inworld): ?>
            <p class="help">No Inworld TTS connector found.</p>
        <?php endif; ?>
        <?php foreach ($inworld as $row):
            $meta = $ttsConnector->decodeMetadata($row['metadata'] ?? '{}');
            $model = (string)($meta['model_id'] ?? 'inworld-tts-2');
            $enabled = !empty($meta['PARALINGUISTIC_TAGS_ENABLED']);
            [$list, $prompt] = iwtBuild($data, $model);
        ?>
            <div style="margin-bottom:14px">
                <b><?php echo iwtH($row['label'] ?: ('#' . $row['id'])); ?></b>
                · <?php echo iwtH($model); ?> (<?php echo iwtIsFlash($model) ? 'Flash' : 'TTS-2'; ?>)
                · <span class="<?php echo $enabled ? 'ok' : 'off'; ?>"><?php echo $enabled ? 'tags enabled' : 'tags disabled in connector'; ?></span>
                · <?php echo iwtFreeFor($data, $model) ? 'own tags allowed (1–4 words) + list' : 'list only'; ?>
                · <a href="tts_connectors.php?edit=<?php echo (int)$row['id']; ?>">open connector</a>
                <pre><?php echo iwtH($list); ?></pre>
                <pre><?php echo iwtH($prompt); ?></pre>
            </div>
        <?php endforeach; ?>
    </div>
</div>
</main>

<script>
(function () {
    const body = document.querySelector('#iwt_table tbody');
    let next = <?php echo count($data['tags']); ?> + 1000;
    document.getElementById('iwt_add').addEventListener('click', function () {
        const i = next++;
        const tr = document.createElement('tr');
        tr.innerHTML =
            '<td><input type="checkbox" name="tags[' + i + '][on]" value="1" checked></td>' +
            '<td class="tagcell"><input type="text" name="tags[' + i + '][tag]" placeholder="e.g. eager"></td>' +
            '<td><select name="tags[' + i + '][type]"><option value="style">Style</option><option value="sound">Sound</option></select></td>' +
            '<td><select name="tags[' + i + '][models]"><option value="both">Both</option><option value="tts2">TTS-2 only</option><option value="flash">Flash only</option></select></td>' +
            '<td class="hide-sm"><input type="text" name="tags[' + i + '][note]" placeholder="not tested yet"></td>' +
            '<td><button type="button" class="btn-danger" onclick="this.closest(\'tr\').remove()">✕</button></td>';
        body.appendChild(tr);
        tr.querySelector('.tagcell input').focus();
    });
})();
</script>

<?php
include(__DIR__ . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . "tmpl" . DIRECTORY_SEPARATOR . "footer.html");
$buffer = ob_get_contents();
ob_end_clean();
$buffer = preg_replace('/(<title>)(.*?)(<\/title>)/i', '$1' . $TITLE . '$3', $buffer);
echo $buffer;
