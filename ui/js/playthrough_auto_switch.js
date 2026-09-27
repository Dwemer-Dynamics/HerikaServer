(() => {
    const panel = document.querySelector('.pth-auto');
    if (!panel || panel.dataset.initialized) return;
    panel.dataset.initialized = 'true';
    const toggle = panel.querySelector('.pth-auto-toggle');
    const status = panel.querySelector('.pth-auto-status');
    const association = panel.querySelector('.pth-auto-associate');
    const choices = panel.querySelector('select');
    const use = panel.querySelector('.pth-auto-use');
    const refresh = panel.querySelector('.pth-auto-refresh');
    let state, csrf, busy = false;

    function disable(value) {
        toggle.disabled = value || !state?.available;
        use.disabled = value || !state || !choices.value;
        choices.disabled = value || !state;
        refresh.disabled = value;
    }
    async function load() {
        if (busy) return;
        busy = true; disable(true);
        try {
            const response = await fetch(panel.dataset.endpoint, {credentials:'same-origin', cache:'no-store', signal:AbortSignal.timeout(10000)});
            const result = await response.json();
            if (!response.ok || !result.ok) throw new Error();
            state = result.state; csrf = result.csrf_token;
            toggle.checked = state.auto_switch === true;
            association.hidden = !toggle.checked || !state.auto_switch_pending;
            choices.replaceChildren();
            for (const row of state.playthroughs || []) {
                if (!row.available) continue;
                choices.add(new Option(row.label || row.name, String(row.id)));
            }
            status.textContent = state.available
                ? (state.auto_switch_pending && toggle.checked ? state.auto_switch_status : (result.notice || (toggle.checked ? (state.auto_switch_status || 'Automatic switching is on.') : 'Automatic switching is off.')))
                : 'Open Manage saves to set up Playthrough Saves first.';
        } catch (_) {
            state = null;
            status.textContent = 'Could not load automatic switching. Refresh status to try again.';
        } finally { busy = false; disable(false); }
    }
    async function save(action) {
        if (busy || !state) return;
        busy = true; disable(true);
        status.textContent = action === 'associate' ? 'Saving current progress and preparing the selected playthrough…' : 'Saving setting…';
        const body = new URLSearchParams({action, csrf_token:csrf, expected_token:state.token});
        if (action === 'auto_switch') body.set('enabled', toggle.checked ? '1' : '0');
        else body.set('profile_id', choices.value);
        try {
            const response = await fetch(panel.dataset.endpoint, {method:'POST', body, credentials:'same-origin'});
            const result = await response.json();
            if (!response.ok || !result.ok) throw new Error(result.message || 'The change could not finish.');
            status.textContent = result.message;
            // Other playthrough controls may have cached a token invalidated by this operation.
            state = null;
            status.textContent += ' Refresh this page before making another change.';
        } catch (error) {
            toggle.checked = state.auto_switch === true;
            status.textContent = `${error.message || 'Connection interrupted.'} Refresh status before trying again; the change may have completed.`;
            state = null;
        } finally { busy = false; disable(false); }
    }
    toggle.addEventListener('change', () => save('auto_switch'));
    use.addEventListener('click', () => save('associate'));
    refresh.addEventListener('click', load);
    load();
})();
