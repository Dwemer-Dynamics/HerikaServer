<?php

if (!isset($activeTab, $webRoot) || !function_exists('chimTtsPronunciationBoolean')) {
    return;
}

// Prepare the global and access-scoped rows for the TTS Studio view.
$pronRows = (isset($ttsPronunciationRows) && is_array($ttsPronunciationRows)) ? $ttsPronunciationRows : [];
$pronTags = (isset($ttsPronunciationTags) && is_array($ttsPronunciationTags)) ? $ttsPronunciationTags : [];
$pronFilter = isset($ttsPronunciationFilter) ? trim((string)$ttsPronunciationFilter) : '';
$pronPostAction = $webRoot . '/ui/xtts_clone.php?tab=pronunciations';
$pronBuiltinRows = [];
$pronCustomRows = [];
foreach ($pronRows as $pronRow) {
    if (chimTtsPronunciationBoolean($pronRow['is_builtin'] ?? false)) {
        $pronBuiltinRows[] = $pronRow;
    } else {
        $pronCustomRows[] = $pronRow;
    }
}

// Collect only the populated access dimensions so a row never claims a filter it does not use.
$pronScopeGroups = static function (array $row): array {
    $groups = [];
    $names = chimTtsPronunciationNormalizeScopeValues($row['npc_names'] ?? '');
    if (!empty($names)) {
        $groups[] = ['label' => 'NPC names', 'values' => $names];
    }
    $races = chimTtsPronunciationNormalizeScopeValues($row['races'] ?? '');
    if (!empty($races)) {
        $groups[] = ['label' => 'Races', 'values' => $races];
    }
    $tags = chimTtsPronunciationNormalizeTags($row['oghma_tags'] ?? '');
    if (!empty($tags)) {
        $groups[] = ['label' => 'Oghma tags', 'values' => $tags];
    }
    return $groups;
};

// Preview inputs come from the backend. A missing piece only disables the play
// controls, it never removes the strip or breaks the rest of the page.
$pronPreviewConnectors = (isset($ttsPronunciationPreviewConnectors) && is_array($ttsPronunciationPreviewConnectors)) ? $ttsPronunciationPreviewConnectors : [];
$pronPreviewVoices = (isset($ttsPronunciationPreviewVoices) && is_array($ttsPronunciationPreviewVoices)) ? $ttsPronunciationPreviewVoices : [];
$pronPreviewEndpoint = isset($ttsPronunciationPreviewEndpoint) ? trim((string)$ttsPronunciationPreviewEndpoint) : '';
$pronPreviewDefaultConnectorId = isset($ttsPronunciationPreviewDefaultConnectorId) ? (int)$ttsPronunciationPreviewDefaultConnectorId : 0;
$pronPreviewDefaultVoice = isset($ttsPronunciationPreviewDefaultVoice) ? (string)$ttsPronunciationPreviewDefaultVoice : '';

$pronPreviewNotice = '';
if ($pronPreviewEndpoint === '') {
    $pronPreviewNotice = 'Preview is unavailable: this server has no preview endpoint configured.';
} elseif (empty($pronPreviewConnectors)) {
    $pronPreviewNotice = 'Preview is unavailable: no TTS connector is installed yet.';
} elseif (empty($pronPreviewVoices)) {
    $pronPreviewNotice = 'Preview is unavailable: no installed voices were found.';
}
$pronPreviewReady = $pronPreviewNotice === '';

// One helper keeps the play control identical in the add row, the editable
// custom rows, and the read-only built-in rows.
$pronPlayButton = static function (string $label, ?string $inputId = null, ?string $text = null, string $context = '') use ($pronPreviewReady): string {
    $name = 'Play ' . $label . ($context !== '' ? ' for ' . $context : '');
    $attrs = ' data-pron-play="1"';
    if ($inputId !== null && $inputId !== '') {
        $attrs .= ' data-pron-input="' . htmlspecialchars($inputId) . '"';
    }
    if ($text !== null) {
        $attrs .= ' data-pron-text="' . htmlspecialchars($text) . '"';
    }
    if (!$pronPreviewReady) {
        $attrs .= ' disabled';
    }

    return '<button type="button" class="pron-play"' . $attrs . ' title="' . htmlspecialchars($name) . '">'
        . '<span class="pron-play-icon" aria-hidden="true"></span>'
        . '<span class="pron-play-text">' . htmlspecialchars($name) . '</span>'
        . '</button>';
};
?>

<div class="tab-content pron-section <?php echo $activeTab === 'pronunciations' ? 'active' : ''; ?>">
    <div class="content-section full-width-section">
        <h1>Pronunciations</h1>
        <p>Rewrite how the TTS engine says a word without changing anything the player reads. These entries apply to every TTS connector.</p>
        <ul class="pron-intro-list">
            <li><strong>Audio only:</strong> subtitles and saved dialogue keep the original spelling &mdash; only the text sent to the voice engine is rewritten.</li>
            <li><strong>Blank field:</strong> that filter is not applied. With NPC names, races, and Oghma tags all blank the entry is global and every NPC uses it.</li>
            <li><strong>Commas inside one field</strong> are alternatives &mdash; <em>Nord, Dunmer</em> matches either race.</li>
            <li><strong>Two or more fields filled:</strong> the speaking NPC must match all of them, so <em>Nord</em> plus <em>companions</em> only fires for a Nord carrying that Oghma tag.</li>
            <li><strong>Built-in entries</strong> keep their original term, but their spoken version can be edited and they can be disabled or deleted.</li>
        </ul>

        <div class="pron-preview<?php echo $pronPreviewReady ? '' : ' is-unavailable'; ?>" id="pron-preview"
             data-pron-endpoint="<?php echo htmlspecialchars($pronPreviewEndpoint); ?>"
             data-pron-ready="<?php echo $pronPreviewReady ? '1' : '0'; ?>">
            <p class="pron-preview-caption">Preview voice</p>
            <div class="pron-preview-field">
                <label class="pron-label" for="pron-preview-connector">Connector</label>
                <select class="pron-field" id="pron-preview-connector" <?php echo $pronPreviewReady ? '' : 'disabled'; ?>>
                    <?php if (empty($pronPreviewConnectors)): ?>
                        <option value="">No connector installed</option>
                    <?php else: ?>
                        <?php foreach ($pronPreviewConnectors as $pronConnector): ?>
                            <?php
                            $pronConnectorId = (string)($pronConnector['id'] ?? '');
                            $pronConnectorLabel = trim((string)($pronConnector['label'] ?? ''));
                            $pronConnectorDriver = trim((string)($pronConnector['driver'] ?? ''));
                            if ($pronConnectorLabel === '') {
                                $pronConnectorLabel = $pronConnectorDriver !== '' ? $pronConnectorDriver : ('Connector ' . $pronConnectorId);
                            } elseif ($pronConnectorDriver !== '') {
                                $pronConnectorLabel .= ' (' . $pronConnectorDriver . ')';
                            }
                            ?>
                            <option value="<?php echo htmlspecialchars($pronConnectorId); ?>" <?php echo (int)$pronConnectorId === $pronPreviewDefaultConnectorId ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($pronConnectorLabel); ?>
                            </option>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </select>
            </div>
            <div class="pron-preview-field">
                <label class="pron-label" for="pron-preview-voice">Voice</label>
                <select class="pron-field" id="pron-preview-voice" <?php echo $pronPreviewReady ? '' : 'disabled'; ?>>
                    <?php if (empty($pronPreviewVoices)): ?>
                        <option value="">No voice installed</option>
                    <?php else: ?>
                        <?php foreach ($pronPreviewVoices as $pronPreviewVoice): ?>
                            <?php $pronPreviewVoice = (string)$pronPreviewVoice; ?>
                            <option value="<?php echo htmlspecialchars($pronPreviewVoice); ?>" <?php echo $pronPreviewVoice === $pronPreviewDefaultVoice ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($pronPreviewVoice); ?>
                            </option>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </select>
            </div>
            <div class="pron-preview-player">
                <span class="pron-label" id="pron-preview-player-label">Last preview</span>
                <audio class="pron-preview-audio" id="pron-preview-audio" controls preload="none"
                       aria-labelledby="pron-preview-player-label"></audio>
            </div>
            <p class="pron-preview-status" id="pron-preview-status" role="status" aria-live="polite"><?php
                echo $pronPreviewReady
                    ? 'Pick a connector and voice, then use a play button beside any entry.'
                    : htmlspecialchars($pronPreviewNotice);
            ?></p>
        </div>
    </div>

    <div class="content-section full-width-section">
        <h1>Add Custom Pronunciation</h1>
        <p>Original text is matched as a whole term. Leave every access field blank to apply the entry to all NPCs.</p>

        <form action="<?php echo $pronPostAction; ?>" method="post" class="pron-cols pron-add-row">
            <input type="hidden" name="action" value="save_tts_pronunciation">
            <div>
                <label class="pron-label" for="pron-add-source">Original</label>
                <div class="pron-input-row">
                    <input class="pron-field" type="text" id="pron-add-source" name="source_text"
                           maxlength="120" required autocomplete="off" spellcheck="false"
                           placeholder="Jorrvaskr">
                    <?php echo $pronPlayButton('Original', 'pron-add-source'); ?>
                </div>
            </div>
            <div>
                <label class="pron-label" for="pron-add-spoken">Spoken version</label>
                <div class="pron-input-row">
                    <input class="pron-field" type="text" id="pron-add-spoken" name="spoken_text"
                           maxlength="240" required autocomplete="off" spellcheck="false"
                           placeholder="Yorvaskr">
                    <?php echo $pronPlayButton('Spoken version', 'pron-add-spoken'); ?>
                </div>
            </div>
            <div class="pron-access">
                <p class="pron-scope pron-scope-hint" id="pron-add-access-help">Blank fields add no restriction. Fill more than one and the speaker must match them all.</p>
                <div class="pron-access-field">
                    <label class="pron-label" for="pron-add-names">NPC names (optional)</label>
                    <input class="pron-field" type="text" id="pron-add-names" name="npc_names"
                           maxlength="512" autocomplete="off" spellcheck="false"
                           placeholder="Lydia, Aela the Huntress"
                           aria-describedby="pron-add-access-help">
                </div>
                <div class="pron-access-field">
                    <label class="pron-label" for="pron-add-races">Races (optional)</label>
                    <input class="pron-field" type="text" id="pron-add-races" name="races"
                           maxlength="512" autocomplete="off" spellcheck="false"
                           placeholder="Nord, Dunmer"
                           aria-describedby="pron-add-access-help">
                </div>
                <div class="pron-access-field">
                    <label class="pron-label" for="pron-add-tags">Oghma tags (optional)</label>
                    <input class="pron-field" type="text" id="pron-add-tags" name="oghma_tags"
                           maxlength="512" autocomplete="off" spellcheck="false"
                           list="pron-tag-options" placeholder="companions, whiterun"
                           aria-describedby="pron-add-access-help">
                </div>
            </div>
            <div class="pron-toggle">
                <input type="checkbox" id="pron-add-enabled" name="enabled" value="1" checked>
                <label class="pron-label pron-toggle-label" for="pron-add-enabled">Enabled</label>
            </div>
            <div class="pron-actions">
                <button type="submit" class="action-button upload-csv pron-btn">Add Entry</button>
            </div>
        </form>

        <datalist id="pron-tag-options">
            <?php foreach ($pronTags as $pronTagOption): ?>
                <option value="<?php echo htmlspecialchars((string)$pronTagOption); ?>"></option>
            <?php endforeach; ?>
        </datalist>
    </div>

    <div class="content-section full-width-section">
        <h1>Custom Pronunciations</h1>

        <form action="<?php echo $webRoot; ?>/ui/xtts_clone.php" method="get" class="pron-toolbar">
            <input type="hidden" name="tab" value="pronunciations">
            <div class="pron-toolbar-field">
                <label class="pron-label" for="pron-tag-filter">Filter by Oghma tag</label>
                <select class="pron-field" id="pron-tag-filter" name="oghma_tag">
                    <option value="" <?php echo $pronFilter === '' ? 'selected' : ''; ?>>All tags</option>
                    <?php foreach ($pronTags as $pronTagOption): ?>
                        <?php $pronTagOption = (string)$pronTagOption; ?>
                        <option value="<?php echo htmlspecialchars($pronTagOption); ?>" <?php echo $pronFilter === $pronTagOption ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($pronTagOption); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="action-button edit pron-btn">Apply Filter</button>
            <?php if ($pronFilter !== ''): ?>
                <a class="pron-clear-filter" href="<?php echo $webRoot; ?>/ui/xtts_clone.php?tab=pronunciations">Clear filter</a>
            <?php endif; ?>
        </form>

        <p class="pron-count">
            <?php echo count($pronCustomRows); ?> custom <?php echo count($pronCustomRows) === 1 ? 'entry' : 'entries'; ?><?php echo $pronFilter !== '' ? ' tagged &quot;' . htmlspecialchars($pronFilter) . '&quot;' : ''; ?>.
        </p>

        <div class="pron-grid">
            <div class="pron-cols pron-head" aria-hidden="true">
                <span>Original</span>
                <span>Spoken Version</span>
                <span>Applies To</span>
                <span>Enabled</span>
                <span>Actions</span>
            </div>

            <?php if (empty($pronCustomRows)): ?>
                <p class="pron-empty">
                    <?php if ($pronFilter !== ''): ?>
                        No custom entries use the tag &quot;<?php echo htmlspecialchars($pronFilter); ?>&quot;. Choose <strong>All tags</strong> to see every entry.
                    <?php else: ?>
                        No custom pronunciations yet. Add one above to override how a word is spoken.
                    <?php endif; ?>
                </p>
            <?php else: ?>
                <?php foreach ($pronCustomRows as $pronRow): ?>
                    <?php
                    $pronId = (string)($pronRow['id'] ?? '');
                    $pronKey = preg_replace('/[^A-Za-z0-9_-]/', '', $pronId);
                    $pronEnabled = chimTtsPronunciationBoolean($pronRow['enabled'] ?? false);
                    $pronSource = (string)($pronRow['source_text'] ?? '');
                    $pronGroups = $pronScopeGroups($pronRow);
                    ?>
                    <form action="<?php echo $pronPostAction; ?>" method="post"
                          class="pron-cols pron-row <?php echo $pronEnabled ? '' : 'is-disabled'; ?>">
                        <input type="hidden" name="action" value="save_tts_pronunciation">
                        <input type="hidden" name="id" value="<?php echo htmlspecialchars($pronId); ?>">
                        <div>
                            <label class="pron-label" for="pron-source-<?php echo $pronKey; ?>">Original</label>
                            <div class="pron-input-row">
                                <input class="pron-field" type="text" id="pron-source-<?php echo $pronKey; ?>"
                                       name="source_text" value="<?php echo htmlspecialchars($pronSource); ?>"
                                       maxlength="120" required autocomplete="off" spellcheck="false">
                                <?php echo $pronPlayButton('Original', 'pron-source-' . $pronKey, null, $pronSource); ?>
                            </div>
                        </div>
                        <div>
                            <label class="pron-label" for="pron-spoken-<?php echo $pronKey; ?>">Spoken version</label>
                            <div class="pron-input-row">
                                <input class="pron-field" type="text" id="pron-spoken-<?php echo $pronKey; ?>"
                                       name="spoken_text" value="<?php echo htmlspecialchars((string)($pronRow['spoken_text'] ?? '')); ?>"
                                       maxlength="240" required autocomplete="off" spellcheck="false">
                                <?php echo $pronPlayButton('Spoken version', 'pron-spoken-' . $pronKey, null, $pronSource); ?>
                            </div>
                        </div>
                        <div class="pron-access">
                            <p class="pron-scope" id="pron-scope-<?php echo $pronKey; ?>">
                                <?php if (empty($pronGroups)): ?>
                                    <span class="pron-badge">Global</span>
                                    <span class="pron-scope-text">Every NPC uses this entry.</span>
                                <?php else: ?>
                                    <span class="pron-scope-text">Speaker must match
                                        <?php foreach ($pronGroups as $pronGroupIndex => $pronGroup): ?><?php echo $pronGroupIndex > 0 ? ' <strong>and</strong> ' : ''; ?><span class="pron-scope-label"><?php echo htmlspecialchars($pronGroup['label']); ?>:</span> <?php echo htmlspecialchars(implode(' or ', $pronGroup['values'])); ?><?php endforeach; ?>.
                                    </span>
                                <?php endif; ?>
                            </p>
                            <div class="pron-access-field">
                                <label class="pron-label" for="pron-names-<?php echo $pronKey; ?>">NPC names</label>
                                <input class="pron-field" type="text" id="pron-names-<?php echo $pronKey; ?>"
                                       name="npc_names" value="<?php echo htmlspecialchars((string)($pronRow['npc_names'] ?? '')); ?>"
                                       maxlength="512" autocomplete="off" spellcheck="false"
                                       placeholder="Blank = any name"
                                       aria-describedby="pron-scope-<?php echo $pronKey; ?>">
                            </div>
                            <div class="pron-access-field">
                                <label class="pron-label" for="pron-races-<?php echo $pronKey; ?>">Races</label>
                                <input class="pron-field" type="text" id="pron-races-<?php echo $pronKey; ?>"
                                       name="races" value="<?php echo htmlspecialchars((string)($pronRow['races'] ?? '')); ?>"
                                       maxlength="512" autocomplete="off" spellcheck="false"
                                       placeholder="Blank = any race"
                                       aria-describedby="pron-scope-<?php echo $pronKey; ?>">
                            </div>
                            <div class="pron-access-field">
                                <label class="pron-label" for="pron-tags-<?php echo $pronKey; ?>">Oghma tags</label>
                                <input class="pron-field" type="text" id="pron-tags-<?php echo $pronKey; ?>"
                                       name="oghma_tags" value="<?php echo htmlspecialchars((string)($pronRow['oghma_tags'] ?? '')); ?>"
                                       maxlength="512" autocomplete="off" spellcheck="false"
                                       list="pron-tag-options" placeholder="Blank = any tag"
                                       aria-describedby="pron-scope-<?php echo $pronKey; ?>">
                            </div>
                        </div>
                        <div class="pron-toggle">
                            <input type="checkbox" id="pron-enabled-<?php echo $pronKey; ?>" name="enabled" value="1"
                                   aria-label="Enable <?php echo htmlspecialchars($pronSource); ?>"
                                   <?php echo $pronEnabled ? 'checked' : ''; ?>>
                            <label class="pron-label pron-toggle-label" for="pron-enabled-<?php echo $pronKey; ?>">Enabled</label>
                        </div>
                        <div class="pron-actions">
                            <button type="submit" id="pron-save-<?php echo $pronKey; ?>" class="action-button upload-csv pron-btn">Save</button>
                            <button type="submit" form="pron-delete-form-<?php echo $pronKey; ?>"
                                    class="action-button delete pron-btn"
                                    onclick="return confirm('Delete the custom pronunciation for <?php echo htmlspecialchars(str_replace(["\\", "'"], ["\\\\", "\\'"], $pronSource), ENT_QUOTES); ?>?');">Delete</button>
                        </div>
                    </form>
                    <form action="<?php echo $pronPostAction; ?>" method="post"
                          id="pron-delete-form-<?php echo $pronKey; ?>" class="pron-hidden-form">
                        <input type="hidden" name="action" value="delete_tts_pronunciation">
                        <input type="hidden" name="id" value="<?php echo htmlspecialchars($pronId); ?>">
                    </form>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <div class="content-section full-width-section">
        <h1>Built-in Pronunciations</h1>

        <div class="pron-grid">
            <div class="pron-cols pron-head" aria-hidden="true">
                <span>Original</span>
                <span>Spoken Version</span>
                <span>Applies To</span>
                <span>Enabled</span>
                <span>Actions</span>
            </div>

            <?php if (empty($pronBuiltinRows)): ?>
                <p class="pron-empty">No built-in pronunciations are available.</p>
            <?php else: ?>
                <?php foreach ($pronBuiltinRows as $pronIndex => $pronRow): ?>
                    <?php
                    $pronId = (string)($pronRow['id'] ?? '');
                    $pronKey = 'b' . preg_replace('/[^A-Za-z0-9_-]/', '', $pronId) . '-' . $pronIndex;
                    $pronEnabled = chimTtsPronunciationBoolean($pronRow['enabled'] ?? false);
                    $pronSource = (string)($pronRow['source_text'] ?? '');
                    $pronSpoken = (string)($pronRow['spoken_text'] ?? '');
                    $pronGroups = $pronScopeGroups($pronRow);
                    // Fallback rows have no database id, so they can be previewed but not saved.
                    $pronSavable = (int)($pronRow['id'] ?? 0) > 0;
                    ?>
                    <form action="<?php echo $pronPostAction; ?>" method="post"
                          class="pron-cols pron-row <?php echo $pronEnabled ? '' : 'is-disabled'; ?>"
                          data-pron-builtin-row>
                        <input type="hidden" name="action" value="toggle_tts_pronunciation" data-pron-action>
                        <input type="hidden" name="id" value="<?php echo htmlspecialchars($pronId); ?>">
                        <div>
                            <span class="pron-label">Original</span>
                            <div class="pron-static-row">
                                <div class="pron-static"><?php echo htmlspecialchars($pronSource); ?></div>
                                <?php echo $pronPlayButton('Original', null, $pronSource, $pronSource); ?>
                            </div>
                        </div>
                        <div>
                            <label class="pron-label" for="pron-spoken-<?php echo $pronKey; ?>">Spoken version</label>
                            <div class="pron-static-row pron-built-in-display" data-pron-display>
                                <div class="pron-static"><?php echo htmlspecialchars($pronSpoken); ?></div>
                                <?php echo $pronPlayButton('Spoken version', null, $pronSpoken, $pronSource); ?>
                            </div>
                            <div class="pron-input-row pron-built-in-editor" id="pron-editor-<?php echo $pronKey; ?>"
                                 data-pron-editor hidden>
                                <input class="pron-field" type="text" id="pron-spoken-<?php echo $pronKey; ?>"
                                       name="spoken_text" value="<?php echo htmlspecialchars($pronSpoken); ?>"
                                       maxlength="240" required autocomplete="off" spellcheck="false"
                                       <?php echo $pronSavable ? '' : 'readonly'; ?>>
                                <?php echo $pronPlayButton('Spoken version', 'pron-spoken-' . $pronKey, null, $pronSource); ?>
                            </div>
                        </div>
                        <div>
                            <?php if (empty($pronGroups)): ?>
                                <span class="pron-badge">Global</span>
                            <?php else: ?>
                                <?php foreach ($pronGroups as $pronGroup): ?>
                                    <p class="pron-scope">
                                        <span class="pron-scope-label"><?php echo htmlspecialchars($pronGroup['label']); ?>:</span>
                                        <span class="pron-scope-text"><?php echo htmlspecialchars(implode(' or ', $pronGroup['values'])); ?></span>
                                    </p>
                                <?php endforeach; ?>
                                <?php if (count($pronGroups) > 1): ?>
                                    <p class="pron-scope pron-scope-hint">All of these must match.</p>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                        <div class="pron-toggle">
                            <input type="checkbox" id="pron-enabled-<?php echo $pronKey; ?>" name="enabled" value="1"
                                   aria-label="Enable <?php echo htmlspecialchars($pronSource); ?>"
                                   <?php echo $pronEnabled ? 'checked' : ''; ?>>
                            <label class="pron-label pron-toggle-label" for="pron-enabled-<?php echo $pronKey; ?>">Enabled</label>
                        </div>
                        <div class="pron-actions">
                            <button type="submit" id="pron-apply-<?php echo $pronKey; ?>"
                                    data-pron-apply
                                    class="action-button upload-csv pron-btn"
                                    <?php echo $pronSavable ? '' : 'disabled title="Apply database updates before changing built-in entries."'; ?>>Apply</button>
                            <button type="button" class="action-button pron-btn"
                                    data-pron-edit aria-expanded="false"
                                    aria-controls="pron-editor-<?php echo $pronKey; ?>"
                                    <?php echo $pronSavable ? '' : 'disabled title="Apply database updates before editing built-in entries."'; ?>>Edit</button>
                            <button type="submit" form="pron-delete-form-<?php echo $pronKey; ?>"
                                    class="action-button delete pron-btn"
                                    <?php echo $pronSavable ? '' : 'disabled title="Apply database updates before deleting built-in entries."'; ?>
                                    onclick="return confirm('Delete the built-in pronunciation for <?php echo htmlspecialchars(str_replace(["\\", "'"], ["\\\\", "\\'"], $pronSource), ENT_QUOTES); ?>?');">Delete</button>
                        </div>
                    </form>
                    <form action="<?php echo $pronPostAction; ?>" method="post"
                          id="pron-delete-form-<?php echo $pronKey; ?>" class="pron-hidden-form">
                        <input type="hidden" name="action" value="delete_tts_pronunciation">
                        <input type="hidden" name="id" value="<?php echo htmlspecialchars($pronId); ?>">
                    </form>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <script>
    (function () {
        var root = document.getElementById('pron-preview');
        if (!root || root.getAttribute('data-pron-bound') === '1') {
            return;
        }
        root.setAttribute('data-pron-bound', '1');

        var endpoint = root.getAttribute('data-pron-endpoint') || '';
        var ready = root.getAttribute('data-pron-ready') === '1';
        var connectorSelect = document.getElementById('pron-preview-connector');
        var voiceSelect = document.getElementById('pron-preview-voice');
        var audio = document.getElementById('pron-preview-audio');
        var statusEl = document.getElementById('pron-preview-status');
        var pending = null;

        function setStatus(message) {
            if (statusEl) {
                statusEl.textContent = message;
            }
        }

        // Editable rows are read at click time so a freshly typed value wins.
        function readText(button) {
            var inputId = button.getAttribute('data-pron-input');
            if (inputId) {
                var field = document.getElementById(inputId);
                return field ? String(field.value == null ? '' : field.value).trim() : '';
            }
            return String(button.getAttribute('data-pron-text') || '').trim();
        }

        function setBusy(button, busy) {
            var text = button.querySelector('.pron-play-text');
            if (busy) {
                button.setAttribute('data-pron-title', button.getAttribute('title') || '');
                if (text) {
                    button.setAttribute('data-pron-label', text.textContent);
                    text.textContent = 'Generating preview';
                }
                button.setAttribute('title', 'Generating preview');
                button.setAttribute('aria-busy', 'true');
                button.classList.add('is-busy');
                button.disabled = true;
                return;
            }
            if (text && button.hasAttribute('data-pron-label')) {
                text.textContent = button.getAttribute('data-pron-label');
            }
            if (button.hasAttribute('data-pron-title')) {
                button.setAttribute('title', button.getAttribute('data-pron-title'));
            }
            button.removeAttribute('aria-busy');
            button.classList.remove('is-busy');
            button.disabled = false;
        }

        function requestPreview(button) {
            if (!ready || !endpoint) {
                return;
            }
            if (pending) {
                setStatus('A preview is still generating. Wait for it to finish, then try again.');
                return;
            }

            var text = readText(button);
            if (text === '') {
                setStatus('That field is empty. Type some text, then press play.');
                return;
            }

            var connectorId = connectorSelect ? connectorSelect.value : '';
            var voice = voiceSelect ? voiceSelect.value : '';
            if (connectorId === '' || voice === '') {
                setStatus('Choose a connector and a voice before previewing.');
                return;
            }

            var payload = new FormData();
            payload.append('connector_id', connectorId);
            payload.append('voice', voice);
            payload.append('text', text);

            pending = button;
            setBusy(button, true);
            setStatus('Generating preview for "' + text + '"...');

            fetch(endpoint, { method: 'POST', body: payload, credentials: 'same-origin' })
                .then(function (response) {
                    return response.text().then(function (body) {
                        var data = null;
                        try {
                            data = JSON.parse(body);
                        } catch (parseError) {
                            data = null;
                        }
                        return { ok: response.ok, status: response.status, data: data };
                    });
                })
                .then(function (result) {
                    var data = result.data;
                    if (!result.ok || !data || data.ok !== true || !data.audio_url) {
                        throw new Error(
                            (data && data.error)
                                ? String(data.error)
                                : ('Preview failed (HTTP ' + result.status + ').')
                        );
                    }
                    if (!audio) {
                        setStatus('Preview was generated, but no player is available on this page.');
                        return;
                    }
                    audio.src = data.audio_url;
                    audio.load();
                    setStatus('Playing "' + text + '".');
                    var started = audio.play();
                    if (started && typeof started.catch === 'function') {
                        started.catch(function () {
                            setStatus('Preview ready. Press play on the player above to listen.');
                        });
                    }
                })
                .catch(function (error) {
                    setStatus((error && error.message) ? error.message : 'Preview failed. Try again.');
                })
                .then(function () {
                    setBusy(button, false);
                    pending = null;
                });
        }

        var buttons = document.querySelectorAll('[data-pron-play]');
        Array.prototype.forEach.call(buttons, function (button) {
            button.addEventListener('click', function (event) {
                // Never let a preview reach the surrounding save/delete/toggle form.
                event.preventDefault();
                event.stopPropagation();
                requestPreview(button);
            });
        });

        var editButtons = document.querySelectorAll('[data-pron-edit]');
        Array.prototype.forEach.call(editButtons, function (editButton) {
            editButton.addEventListener('click', function () {
                var row = editButton.closest('[data-pron-builtin-row]');
                if (!row) {
                    return;
                }

                var action = row.querySelector('[data-pron-action]');
                var applyButton = row.querySelector('[data-pron-apply]');
                var display = row.querySelector('[data-pron-display]');
                var editor = row.querySelector('[data-pron-editor]');
                var field = editor ? editor.querySelector('.pron-field') : null;
                var editing = editButton.getAttribute('aria-expanded') === 'true';

                if (editing) {
                    if (field) {
                        field.value = field.defaultValue;
                    }
                    if (display) {
                        display.hidden = false;
                    }
                    if (editor) {
                        editor.hidden = true;
                    }
                    if (action) {
                        action.value = 'toggle_tts_pronunciation';
                    }
                    if (applyButton) {
                        applyButton.textContent = 'Apply';
                    }
                    editButton.textContent = 'Edit';
                    editButton.setAttribute('aria-expanded', 'false');
                    return;
                }

                if (display) {
                    display.hidden = true;
                }
                if (editor) {
                    editor.hidden = false;
                }
                if (action) {
                    action.value = 'save_builtin_tts_pronunciation';
                }
                if (applyButton) {
                    applyButton.textContent = 'Save';
                }
                editButton.textContent = 'Cancel';
                editButton.setAttribute('aria-expanded', 'true');
                if (field) {
                    field.focus();
                    field.select();
                }
            });

            var editorId = editButton.getAttribute('aria-controls');
            var editor = editorId ? document.getElementById(editorId) : null;
            var field = editor ? editor.querySelector('.pron-field') : null;
            if (field) {
                field.addEventListener('keydown', function (event) {
                    if (event.key === 'Escape') {
                        event.preventDefault();
                        editButton.click();
                        editButton.focus();
                    }
                });
            }
        });
    })();
    </script>
</div>
