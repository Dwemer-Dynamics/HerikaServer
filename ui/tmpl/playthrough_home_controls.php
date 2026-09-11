<?php
require_once dirname(__DIR__, 2) . '/lib/playthrough_preferences.php';
$pthRoot = rtrim($webRoot ?? '', '/');
$pthManager = ptp_product()['meta'] === 'stobe_meta' ? 'controlpanel_hub.php' : 'control_panel.php';
$pthEscape = static fn($value) => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
?>
<link rel="stylesheet" href="<?= $pthEscape($pthRoot) ?>/ui/css/playthrough_home.css?v=2">
<section class="pth-home" aria-label="Playthrough Saves" data-party="<?= ptp_product()['meta'] === 'stobe_meta' ? 'true' : 'false' ?>" data-endpoint="<?= $pthEscape($pthRoot) ?>/ui/api/playthrough_manager.php">
    <div class="pth-row">
        <strong>Playthrough Saves</strong>
        <span id="pth-current" class="pth-current">Loading current save…</span>
        <button type="button" id="pth-choose" aria-haspopup="dialog" aria-controls="pth-picker">Switch playthrough</button>
        <button type="button" id="pth-new" disabled>New playthrough</button>
        <a href="<?= $pthEscape($pthRoot . '/ui/' . $pthManager) ?>?tab=storage">Manage saves</a>
    </div>
    <p id="pth-help">Close the game before switching. Then load its matching game save.</p>
    <p id="pth-status" role="status" aria-live="polite"></p>
    <noscript>Enable JavaScript to switch here, or open Manage saves.</noscript>
</section>
<dialog id="pth-picker" class="pth-dialog pth-picker" aria-labelledby="pth-picker-title" aria-describedby="pth-picker-help">
    <div class="pth-picker-heading">
        <h2 id="pth-picker-title">Choose a Playthrough Save</h2>
        <button type="button" id="pth-picker-close" autofocus>Close</button>
    </div>
    <p id="pth-picker-help">Compare saved copies below. Your current progress is saved before switching.</p>
    <p id="pth-picker-status" role="status" aria-live="polite">Loading saves…</p>
    <button type="button" id="pth-retry" hidden>Try again</button>
    <div class="pth-table-wrap" id="pth-table-wrap" hidden>
        <table class="pth-save-table">
            <caption class="pth-sr-only">Saved mod data available to load</caption>
            <thead><tr><th scope="col">Save</th><th scope="col"><?= ptp_product()['meta'] === 'stobe_meta' ? 'Party' : 'Character' ?></th><th scope="col">Game date</th><th scope="col">Created</th><th scope="col">Size</th><th scope="col"><span class="pth-sr-only">Action</span></th></tr></thead>
            <tbody id="pth-save-rows"></tbody>
        </table>
    </div>
</dialog>
<dialog id="pth-dialog" class="pth-dialog" aria-labelledby="pth-title" aria-describedby="pth-description pth-game-help">
    <form id="pth-form">
        <h2 id="pth-title">Switch playthrough?</h2>
        <p id="pth-description"></p>
        <p id="pth-game-help"><strong>Close the game first.</strong> After switching, load the matching game save.</p>
        <div id="pth-name-field" hidden>
            <label for="pth-name">Playthrough name</label>
            <input id="pth-name" name="name" maxlength="160" autocomplete="off">
        </div>
        <p id="pth-error" role="alert"></p>
        <div class="pth-dialog-actions">
            <button type="button" id="pth-cancel" autofocus>Cancel</button>
            <button type="submit" id="pth-confirm">Switch playthrough</button>
        </div>
    </form>
</dialog>
<!-- These controls are ready here; do not wait for unrelated dashboard scripts. -->
<script src="<?= $pthEscape($pthRoot) ?>/ui/js/playthrough_home.js?v=7"></script>
