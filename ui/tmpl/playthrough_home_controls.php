<?php
require_once dirname(__DIR__, 2) . '/lib/playthrough_preferences.php';
$pthRoot = rtrim($webRoot ?? '', '/');
$pthManager = ptp_product()['meta'] === 'stobe_meta' ? 'controlpanel_hub.php' : 'control_panel.php';
$pthEscape = static fn($value) => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
?>
<link rel="stylesheet" href="<?= $pthEscape($pthRoot) ?>/ui/css/playthrough_home.css?v=1">
<section class="pth-home" aria-label="Playthrough Saves" data-endpoint="<?= $pthEscape($pthRoot) ?>/ui/api/playthrough_manager.php">
    <div class="pth-row">
        <label for="pth-select">Playthrough Saves</label>
        <select id="pth-select" disabled aria-describedby="pth-help"><option>Loading saves…</option></select>
        <button type="button" id="pth-new" disabled>New playthrough</button>
        <a href="<?= $pthEscape($pthRoot . '/ui/' . $pthManager) ?>?tab=storage">Manage saves</a>
    </div>
    <p id="pth-help">Close the game before switching. Then load its matching game save.</p>
    <p id="pth-status" role="status" aria-live="polite"></p>
    <noscript>Enable JavaScript to switch here, or open Manage saves.</noscript>
</section>
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
<script src="<?= $pthEscape($pthRoot) ?>/ui/js/playthrough_home.js?v=6"></script>
