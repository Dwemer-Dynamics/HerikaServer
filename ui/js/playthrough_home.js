(() => {
    const panel = document.querySelector('.pth-home');
    if (!panel) return;
    const select = document.getElementById('pth-select');
    const newButton = document.getElementById('pth-new');
    const status = document.getElementById('pth-status');
    const dialog = document.getElementById('pth-dialog');
    const form = document.getElementById('pth-form');
    const nameInput = document.getElementById('pth-name');
    const confirm = document.getElementById('pth-confirm');
    const cancel = document.getElementById('pth-cancel');
    const error = document.getElementById('pth-error');
    let state, csrf, action, target, opener, busy = false;

    // Selection opens a confirmation; the selector continues to show the active save.
    function open(actionName, profile = null) {
        if (busy || !state?.available) return;
        action = actionName; target = profile; opener = action === 'new' ? newButton : select;
        error.textContent = ''; nameInput.value = '';
        document.getElementById('pth-name-field').hidden = action !== 'new';
        nameInput.required = action === 'new';
        document.getElementById('pth-title').textContent = action === 'new' ? 'Start a new playthrough?' : `Switch to ${profile.name}?`;
        document.getElementById('pth-description').textContent = action === 'new'
            ? 'Your current progress will be saved. The new playthrough starts with empty game history and memories, keeping your current settings and NPC setup.'
            : 'Your current progress will be saved before this playthrough loads.';
        document.getElementById('pth-game-help').textContent = action === 'new'
            ? 'Close the game first. After creating this playthrough, start your new game.'
            : 'Close the game first. After switching, load the matching game save.';
        confirm.textContent = action === 'new' ? 'Start new playthrough' : 'Switch playthrough';
        dialog.showModal();
        (action === 'new' ? nameInput : cancel).focus();
    }
    newButton.addEventListener('click', () => open('new'));
    select.addEventListener('change', () => {
        const choice = state.playthroughs.find(row => row.id === Number(select.value));
        select.value = String(state.active_id);
        if (choice && choice.id !== state.active_id) open('switch', choice);
    });
    cancel.addEventListener('click', () => { if (!busy) dialog.close(); });
    dialog.addEventListener('cancel', event => { if (busy) event.preventDefault(); });
    dialog.addEventListener('close', () => opener?.focus());
    form.addEventListener('submit', async event => {
        event.preventDefault();
        if (busy || !form.reportValidity()) return;
        if (action === 'new' && !nameInput.value.trim()) { error.textContent = 'Enter a playthrough name.'; nameInput.focus(); return; }
        busy = true; confirm.disabled = true; cancel.disabled = true;
        error.textContent = 'Saving current progress and preparing your playthrough. Please wait…';
        const body = new URLSearchParams({action, csrf_token: csrf, expected_token: state.token});
        if (action === 'new') body.set('name', nameInput.value.trim());
        else body.set('profile_id', String(target.id));
        try {
            const response = await fetch(panel.dataset.endpoint, {method: 'POST', body, credentials: 'same-origin'});
            const result = await response.json();
            if (!result.ok) {
                error.textContent = result.message || 'The playthrough could not be changed.';
                if (result.retryable === true) {
                    busy = false; confirm.disabled = false; cancel.disabled = false;
                    if (action === 'new') nameInput.focus();
                    return;
                }
                busy = false; cancel.disabled = false; newButton.disabled = true; select.disabled = true;
            status.textContent = 'Reload this page before changing playthroughs again.';
                // Reload state before another attempt, including late stale-tab responses.
                confirm.textContent = 'Reload page';
                confirm.type = 'button'; confirm.disabled = false;
                confirm.onclick = () => location.reload();
                return;
            }
            location.reload();
        } catch (_) {
            // A lost response can follow a committed switch. Never retry the write automatically.
            error.textContent = 'The connection was interrupted. The change may have completed. Reload this page to check before trying again.';
            busy = false; cancel.disabled = false; newButton.disabled = true; select.disabled = true;
            status.textContent = 'Reload this page before changing playthroughs again.';
            confirm.textContent = 'Reload page'; confirm.type = 'button'; confirm.disabled = false;
            confirm.onclick = () => location.reload();
        }
    });
    fetch(panel.dataset.endpoint, {credentials: 'same-origin', cache: 'no-store'})
        .then(response => response.json()).then(result => {
            if (!result.ok) throw new Error(result.message);
            state = result.state; csrf = result.csrf_token;
            select.replaceChildren();
            if (!state.active_id) select.add(new Option('Current progress (not yet saved)', '0'));
            for (const row of state.playthroughs) select.add(new Option(row.name + (row.active ? ' (active)' : ''), String(row.id)));
            select.value = String(state.active_id);
            select.disabled = !state.available || state.playthroughs.filter(row => !row.active).length === 0;
            newButton.disabled = !state.available;
            status.textContent = state.available ? (result.notice || '') : 'Open Manage saves to set up Playthrough Saves.';
        }).catch(failure => { select.replaceChildren(new Option('Saves unavailable', '')); status.textContent = failure.message || 'Could not load saves. Reload this page.'; });
})();
