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
            <li><strong>Built-in entries</strong> cannot be deleted, but any of them can be disabled.</li>
        </ul>
    </div>

    <div class="content-section full-width-section">
        <h1>Add Custom Pronunciation</h1>
        <p>Original text is matched as a whole term. Leave every access field blank to apply the entry to all NPCs.</p>

        <form action="<?php echo $pronPostAction; ?>" method="post" class="pron-cols pron-add-row">
            <input type="hidden" name="action" value="save_tts_pronunciation">
            <div>
                <label class="pron-label" for="pron-add-source">Original</label>
                <input class="pron-field" type="text" id="pron-add-source" name="source_text"
                       maxlength="120" required autocomplete="off" spellcheck="false"
                       placeholder="Jorrvaskr">
            </div>
            <div>
                <label class="pron-label" for="pron-add-spoken">Spoken version</label>
                <input class="pron-field" type="text" id="pron-add-spoken" name="spoken_text"
                       maxlength="240" required autocomplete="off" spellcheck="false"
                       placeholder="Yorvaskr">
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
                            <input class="pron-field" type="text" id="pron-source-<?php echo $pronKey; ?>"
                                   name="source_text" value="<?php echo htmlspecialchars($pronSource); ?>"
                                   maxlength="120" required autocomplete="off" spellcheck="false">
                        </div>
                        <div>
                            <label class="pron-label" for="pron-spoken-<?php echo $pronKey; ?>">Spoken version</label>
                            <input class="pron-field" type="text" id="pron-spoken-<?php echo $pronKey; ?>"
                                   name="spoken_text" value="<?php echo htmlspecialchars((string)($pronRow['spoken_text'] ?? '')); ?>"
                                   maxlength="240" required autocomplete="off" spellcheck="false">
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
        <p>Shipped defaults for common lore names. They cannot be deleted, but any of them can be disabled and replaced with a custom entry above. The <strong>Applies To</strong> column shows who each default actually reaches.</p>

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
                    $pronGroups = $pronScopeGroups($pronRow);
                    ?>
                    <form action="<?php echo $pronPostAction; ?>" method="post"
                          class="pron-cols pron-row <?php echo $pronEnabled ? '' : 'is-disabled'; ?>">
                        <input type="hidden" name="action" value="toggle_tts_pronunciation">
                        <input type="hidden" name="id" value="<?php echo htmlspecialchars($pronId); ?>">
                        <div class="pron-static"><?php echo htmlspecialchars((string)($pronRow['source_text'] ?? '')); ?></div>
                        <div class="pron-static"><?php echo htmlspecialchars((string)($pronRow['spoken_text'] ?? '')); ?></div>
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
                                   aria-label="Enable <?php echo htmlspecialchars((string)($pronRow['source_text'] ?? '')); ?>"
                                   <?php echo $pronEnabled ? 'checked' : ''; ?>>
                            <label class="pron-label pron-toggle-label" for="pron-enabled-<?php echo $pronKey; ?>">Enabled</label>
                        </div>
                        <div class="pron-actions">
                            <button type="submit" id="pron-apply-<?php echo $pronKey; ?>" class="action-button edit pron-btn">Apply</button>
                        </div>
                    </form>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>
