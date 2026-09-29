<?php
// The individual Test button uses the same isolated checks as profile/global batches.
$connectorId = intval($_GET['connector_id'] ?? $_GET['edit'] ?? 0);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>LLM Connector Test</title>
    <style>
        body { background:#2a2a2a; color:#e8eef9; font-family:Arial,sans-serif; margin:0; padding:18px; }
        main { max-width:1100px; margin:auto; }
        h1 { font-size:20px; color:rgb(242,124,17); }
        .panel { border:1px solid #555; border-radius:8px; padding:12px; margin:12px 0; overflow-wrap:anywhere; }
        .pass { color:#8de2a4; } .warn { color:#ffd180; } .fail { color:#ff9898; }
        pre { white-space:pre-wrap; overflow-wrap:anywhere; }
    </style>
</head>
<body>
<main>
    <h1>LLM Connector Test</h1>
    <p>Tests this connector directly. No game actions are executed. Provider usage may be billed.</p>
    <div id="status" class="panel" role="status" aria-live="polite">Testing connector…</div>
    <div id="checks"></div>
    <pre id="details" class="panel" hidden></pre>
</main>
<script>
(async () => {
    const status = document.getElementById('status');
    const checks = document.getElementById('checks');
    const details = document.getElementById('details');
    const id = <?= json_encode($connectorId) ?>;
    try {
        if (id <= 0) throw new Error('Missing or invalid connector ID.');
        const response = await fetch('../../api/profile_connector_tests.php?action=test&type=llm&id=' + id, {credentials:'same-origin', cache:'no-store'});
        const payload = await response.json();
        if (!response.ok || !payload.ok) throw new Error(payload.error || 'Connector test failed.');
        const result = payload.result;
        status.textContent = result.status.toUpperCase() + ': ' + result.message;
        status.className = 'panel ' + result.status;
        for (const [name, check] of Object.entries(result.details.checks || {})) {
            const row = document.createElement('div');
            row.className = 'panel ' + check.status;
            row.textContent = name + ': ' + check.status + ' — ' + check.message;
            checks.appendChild(row);
        }
        const info = result.details;
        const lines = ['Total: ' + result.elapsed_ms + ' ms'];
        if (info.timings && info.timings.ttft_ms != null) lines.push('First token: ' + info.timings.ttft_ms + ' ms');
        if (info.response_preview) lines.push('Response: ' + info.response_preview);
        if (info.errors) info.errors.forEach(error => lines.push('Warning: ' + error.message));
        details.textContent = lines.join('\n');
        details.hidden = false;
    } catch (error) {
        status.textContent = error.message;
        status.className = 'panel fail';
    }
})();
</script>
</body>
</html>
