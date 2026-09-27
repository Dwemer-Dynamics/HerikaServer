<?php
if (defined('CHIM_AUTO_SWITCH_CONTROL')) return;
define('CHIM_AUTO_SWITCH_CONTROL', true);
$autoRoot = htmlspecialchars(rtrim($webRoot ?? '', '/'), ENT_QUOTES, 'UTF-8');
?>
<div class="pth-auto" data-endpoint="<?= $autoRoot ?>/ui/api/playthrough_manager.php">
    <label><input type="checkbox" class="pth-auto-toggle" disabled aria-describedby="pth-auto-help"> Automatically switch playthroughs</label>
    <small id="pth-auto-help">Match your loaded Skyrim character and save the current playthrough before switching.</small>
    <p class="pth-auto-status" role="status" aria-live="polite">Loading automatic switching…</p>
    <div class="pth-auto-associate" hidden>
        <label for="pth-auto-choice">Playthrough for the loaded character</label>
        <select id="pth-auto-choice"></select>
        <button type="button" class="pth-auto-use">Use for loaded character</button>
        <p>This associates the selected save with the loaded character. Current progress is saved before switching.</p>
    </div>
    <button type="button" class="pth-auto-refresh">Refresh status</button>
</div>
<style>
.pth-auto { margin:10px 0; overflow-wrap:anywhere; }
.pth-auto > label { display:inline-flex; gap:8px; align-items:center; }
.pth-auto input[type=checkbox] { width:auto; }
.pth-auto small { display:block; margin:4px 0; }
.pth-auto p { margin:6px 0; }
.pth-auto select { max-width:100%; }
.pth-auto button { margin:4px; min-height:36px; }
.pth-auto :focus-visible { outline:2px solid #ffb862; outline-offset:2px; }
</style>
<script src="<?= $autoRoot ?>/ui/js/playthrough_auto_switch.js?v=1"></script>
