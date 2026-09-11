<?php
$ptxRoot=rtrim($webRoot??'','/');
$ptxEscape=static fn($value)=>htmlspecialchars($value,ENT_QUOTES,'UTF-8');
?>
<link rel="stylesheet" href="<?= $ptxEscape($ptxRoot) ?>/ui/css/playthrough_transfer.css?v=1">
<dialog id="ptx-dialog" class="ptx-dialog" aria-labelledby="ptx-title" data-endpoint="<?= $ptxEscape($ptxRoot) ?>/ui/api/playthrough_transfer.php" data-state-endpoint="<?= $ptxEscape($ptxRoot) ?>/ui/api/playthrough_manager.php">
    <h2 id="ptx-title">Import a Playthrough Save</h2>
    <p>Contains mod data only. Keep the matching game save too. Global settings and shared profiles stay the same.</p>
    <div id="ptx-upload">
        <label for="ptx-file">Playthrough file (.playthrough.zip)</label>
        <input type="file" id="ptx-file" accept=".zip,application/zip">
        <button type="button" id="ptx-check" disabled>Check file</button>
    </div>
    <div id="ptx-preview" hidden>
        <dl id="ptx-details"></dl>
        <label for="ptx-name">Save name</label>
        <input type="text" id="ptx-name" maxlength="160" autocomplete="off">
        <div id="ptx-profiles"></div>
        <p>The imported copy stays in your save list until you choose to switch to it.</p>
    </div>
    <progress id="ptx-progress" hidden aria-label="Transfer progress"></progress>
    <p id="ptx-status" role="status" aria-live="polite"></p>
    <p id="ptx-error" role="alert"></p>
    <div class="ptx-actions">
        <button type="button" id="ptx-close">Close</button>
        <a id="ptx-download-link" hidden>Download file</a>
        <button type="button" id="ptx-import-confirm" hidden disabled>Import save</button>
    </div>
</dialog>
<script src="<?= $ptxEscape($ptxRoot) ?>/ui/js/playthrough_transfer.js?v=1"></script>
