<?php // Standalone controls use the same server API as the Dashboard. ?>
<section id="retention-section" class="content-section" data-api="<?= htmlspecialchars($webRoot . '/ui/api/playthrough_retention.php', ENT_QUOTES) ?>" data-csrf="<?= htmlspecialchars($csrfToken, ENT_QUOTES) ?>">
    <h2>Playthrough Saves and cleanup</h2>
    <p id="ps-status" role="status" aria-live="polite">Loading settings...</p>
    <div id="ps-controls"></div>
</section>
<style>
#retention-section { margin-top:20px; overflow-wrap:anywhere; }
#retention-section .ps-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(min(100%,260px),1fr)); gap:12px; }
#retention-section fieldset { min-width:0; border:1px solid #777; border-radius:6px; padding:12px; margin:12px 0; }
#retention-section legend { width:auto; font-size:1rem; color:inherit; padding:0 5px; }
#retention-section label { display:block; margin:8px 0; }
#retention-section input[type=number] { width:90px; margin:0 8px; }
#retention-section input[type=checkbox] { width:auto; margin-right:8px; }
#retention-section button { margin:4px; padding:8px 12px; cursor:pointer; }
#retention-section button:focus-visible, #retention-section input:focus-visible, #retention-section select:focus-visible { outline:2px solid #ffb862; outline-offset:2px; }
#retention-section .ps-row { border-bottom:1px solid #777; padding:8px 0; display:flex; flex-wrap:wrap; align-items:center; gap:8px; }
#retention-section .ps-row label { flex:1; min-width:150px; }
#retention-section [role=alert] { color:#ffb4b4; }
#retention-section .ps-cleanup-row { border:1px solid #777; border-radius:6px; padding:12px; margin:8px 0; }
#retention-section .ps-cleanup-row summary { display:flex; flex-wrap:wrap; gap:8px 16px; cursor:pointer; align-items:center; }
#retention-section .ps-cleanup-row summary strong { flex:1; min-width:140px; }
#retention-section .ps-cleanup-row summary span:last-child { font-size:0.85em; text-decoration:underline; }
#retention-section .ps-cleanup-row summary:focus-visible { outline:2px solid #ffb862; outline-offset:4px; }
#retention-section .ps-kept-row { display:flex; flex-wrap:wrap; gap:4px 16px; padding:8px 0; border-bottom:1px solid #777; }
#retention-section .ps-kept-row strong { flex:1; }
#retention-section .ps-kept-row p { flex-basis:100%; margin:0; font-size:0.9em; }
</style>
<script src="<?= htmlspecialchars($webRoot . '/ui/js/playthrough_retention.js?v=' . filemtime(dirname(__DIR__) . '/js/playthrough_retention.js'), ENT_QUOTES) ?>"></script>
