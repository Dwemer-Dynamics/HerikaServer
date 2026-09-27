(() => {
    const panel = document.querySelector('.pth-home');
    if (!panel) return;
    const chooseButton = document.getElementById('pth-choose');
    const current = document.getElementById('pth-current');
    const picker = document.getElementById('pth-picker');
    const pickerStatus = document.getElementById('pth-picker-status');
    const rows = document.getElementById('pth-save-rows');
    const tableWrap = document.getElementById('pth-table-wrap');
    const retry = document.getElementById('pth-retry');
    const isParty = panel.dataset.party === 'true';
    const newButton = document.getElementById('pth-new');
    const status = document.getElementById('pth-status');
    const dialog = document.getElementById('pth-dialog');
    const form = document.getElementById('pth-form');
    const nameInput = document.getElementById('pth-name');
    const deleteInput = document.getElementById('pth-delete-word');
    const confirm = document.getElementById('pth-confirm');
    const cancel = document.getElementById('pth-cancel');
    const error = document.getElementById('pth-error');
    let state, csrf, action, target, opener, busy = false, loading = false, reloadRequired = false;

    // Selecting a row opens the existing confirmation before any save is changed.
    function open(actionName, profile = null) {
        if (busy || !state?.available) return;
        action = actionName; target = profile; opener = action === 'new' ? newButton : chooseButton;
        error.textContent = ''; nameInput.value = ''; deleteInput.value = '';
        dialog.dataset.action = action; confirm.disabled = action === 'delete';
        document.getElementById('pth-delete-field').hidden = action !== 'delete';
        deleteInput.required = action === 'delete';
        document.getElementById('pth-game-help').hidden = action === 'delete';
        document.getElementById('pth-name-field').hidden = action !== 'new';
        nameInput.required = action === 'new';
        document.getElementById('pth-title').textContent = action === 'new' ? 'Start a new playthrough?' : `Switch to ${profile.label || profile.name}?`;
        document.getElementById('pth-description').textContent = action === 'new'
            ? 'Your current progress will be saved. The new playthrough starts with no NPCs, memories or game progress. Global settings and libraries stay the same.'
            : 'Your current progress will be saved before this playthrough loads.';
        document.getElementById('pth-game-help').textContent = action === 'new'
            ? 'Close the game first. After creating this playthrough, start your new game.'
            : 'Close the game first. After switching, load the matching game save.';
        confirm.textContent = action === 'new' ? 'Start new playthrough' : 'Switch playthrough';
        if (action === 'delete') {
            document.getElementById('pth-title').textContent = `Delete Save #${profile.id} — ${profile.label || profile.name}?`;
            document.getElementById('pth-description').textContent = 'Are you sure? This permanently deletes this saved copy of mod data. It cannot be undone. Your current playthrough and in-game save files are kept.';
            confirm.textContent = 'Delete save';
        }
        dialog.showModal();
        if (action === 'delete') deleteInput.focus();
        else (action === 'new' ? nameInput : cancel).focus();
    }
    deleteInput.addEventListener('input', () => {
        if (action === 'delete' && !busy && !reloadRequired) confirm.disabled = deleteInput.value !== 'Delete';
    });
    newButton.addEventListener('click', () => open('new'));
    chooseButton.addEventListener('click', () => {
        if (busy || reloadRequired) return;
        picker.showModal();
        loadState();
    });
    document.getElementById('pth-picker-close').addEventListener('click', () => picker.close());
    picker.addEventListener('close', () => { if (!dialog.open) chooseButton.focus(); });
    retry.addEventListener('click', loadState);
    cancel.addEventListener('click', () => { if (!busy) dialog.close(); });
    dialog.addEventListener('cancel', event => { if (busy) event.preventDefault(); });
    dialog.addEventListener('close', () => {
        if (action !== 'new' && !busy && !reloadRequired) {
            picker.showModal();
            rows.querySelector(`[data-save-id="${target.id}"][data-action="${action}"]`)?.focus();
        } else opener?.focus();
    });
    form.addEventListener('submit', async event => {
        event.preventDefault();
        if (busy || !form.reportValidity()) return;
        if (action === 'new' && !nameInput.value.trim()) { error.textContent = 'Enter a playthrough name.'; nameInput.focus(); return; }
        if (action === 'delete' && deleteInput.value !== 'Delete') { error.textContent = 'Type Delete exactly to confirm.'; deleteInput.focus(); return; }
        busy = true; confirm.disabled = true; cancel.disabled = true;
        error.textContent = action === 'delete' ? 'Deleting this saved copy. Please wait…' : 'Saving current progress and preparing your playthrough. Please wait…';
        const body = new URLSearchParams({action, csrf_token: csrf, expected_token: state.token});
        if (action === 'new') body.set('name', nameInput.value.trim());
        else body.set('profile_id', String(target.id));
        if (action === 'delete') { body.set('delete_confirmation',deleteInput.value); body.set('delete_token',target.delete_token); }
        try {
            const response = await fetch(panel.dataset.endpoint, {method: 'POST', body, credentials: 'same-origin'});
            const result = await response.json();
            if (!result.ok) {
                error.textContent = result.message || 'The playthrough could not be changed.';
                if (result.retryable === true) {
                    busy = false; confirm.disabled = action === 'delete' && deleteInput.value !== 'Delete'; cancel.disabled = false;
                    if (action === 'new') nameInput.focus();
                    return;
                }
                busy = false; cancel.disabled = false; newButton.disabled = true; chooseButton.disabled = true; reloadRequired = true;
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
            busy = false; cancel.disabled = false; newButton.disabled = true; chooseButton.disabled = true; reloadRequired = true;
            status.textContent = 'Reload this page before changing playthroughs again.';
            confirm.textContent = 'Reload page'; confirm.type = 'button'; confirm.disabled = false;
            confirm.onclick = () => location.reload();
        }
    });
    // Construct cells as text so saved names and party metadata cannot become HTML.
    function renderSaves() {
        rows.replaceChildren();
        const kinds = {manual: 'Manual save', dragon_break: 'Automatic save', before_switch: 'Before switching', unclassified: 'Older save'};
        for (const row of state.playthroughs) {
            const tr = document.createElement('tr');
            if (row.active) tr.classList.add('pth-active-row');
            const cell = (label, text = '') => {
                const td = document.createElement('td');
                td.dataset.label = label; td.textContent = text; tr.append(td); return td;
            };
            const save = cell('Save');
            const title = document.createElement('strong');
            const useSaveId = isParty || (!['manual', 'default'].includes(row.kind) && row.name.toLowerCase() !== 'default');
            title.textContent = useSaveId
                ? `Save #${row.id}` : row.name;
            save.append(title);
            const kind = document.createElement('small');
            kind.textContent = kinds[row.kind] || 'Saved copy'; save.append(kind);
            const identity = cell(isParty ? 'Party' : 'Character');
            if (isParty) {
                const members = row.player_faction_members || [];
                const summary = members.slice(0, 5).join(', ') + (members.length > 5 ? ` +${members.length - 5} more` : '');
                if (members.length > 5) {
                    const details = document.createElement('details');
                    const heading = document.createElement('summary'); heading.textContent = summary;
                    const full = document.createElement('p'); full.textContent = members.join(', ');
                    details.append(heading, full); identity.append(details);
                } else identity.textContent = summary || 'Party not recorded';
            } else {
                identity.textContent = row.player_name || 'Character not recorded';
                if (row.player_level) {
                    const level = document.createElement('small'); level.textContent = `Level ${row.player_level}`; identity.append(level);
                }
            }
            cell('Game date', row.game_date || 'Not recorded');
            cell('Created', row.created_at ? row.created_at.slice(0, 16) : 'Not recorded');
            const bytes = Number(row.size_bytes);
            let size = bytes, unit = 0;
            while (size >= 1024 && unit < 3) { size /= 1024; unit++; }
            cell('Size', Number.isFinite(bytes) && bytes >= 0 ? `${size.toFixed(unit ? 1 : 0)} ${['B', 'KB', 'MB', 'GB'][unit]}` : 'Not recorded');
            const actionCell = cell('Action');
            if (row.active) {
                const active = document.createElement('span'); active.className = 'pth-active'; active.textContent = 'Active'; actionCell.append(active);
            } else {
                const button = document.createElement('button'); button.type = 'button'; button.textContent = 'Switch';
                button.dataset.saveId = String(row.id); button.dataset.action = 'switch'; button.setAttribute('aria-label', `Switch to ${row.label || row.name}`);
                button.addEventListener('click', () => { picker.close(); open('switch', row); }); actionCell.append(button);
                if (row.can_delete) {
                    const remove = document.createElement('button'); remove.type = 'button'; remove.textContent = 'Delete'; remove.className = 'pth-delete';
                    remove.dataset.saveId = String(row.id); remove.dataset.action = 'delete';
                    remove.setAttribute('aria-label', `Delete Save #${row.id} — ${row.label || row.name}`);
                    remove.addEventListener('click', () => { picker.close(); open('delete',row); }); actionCell.append(remove);
                }
            }
            const download=document.createElement('button');download.type='button';download.className='ptx-download';
            download.dataset.profileId=String(row.id);download.textContent='Download';
            download.setAttribute('aria-label',`Download Save #${row.id} — ${row.label || row.name}`);actionCell.append(download);
            rows.append(tr);
        }
    }

    // Bound reads and offer retry inside the picker; never retry a write automatically.
    async function loadState() {
        if (loading || busy || reloadRequired) return;
        loading = true; newButton.disabled = true; retry.hidden = true; tableWrap.hidden = true;
        pickerStatus.textContent = 'Loading saves…';
        const controller = new AbortController();
        const timeout = setTimeout(() => controller.abort(), 10000);
        try {
            const response = await fetch(panel.dataset.endpoint, {credentials: 'same-origin', cache: 'no-store', signal: controller.signal});
            const result = await response.json();
            if (!response.ok || !result.ok) throw new Error(result.message || 'Saves unavailable');
            state = result.state; csrf = result.csrf_token;
            const active = state.playthroughs.find(row => row.active);
            current.textContent = active ? (active.label || active.name) : 'Current progress (not yet saved)';
            newButton.disabled = !state.available;
            status.textContent = result.notice || '';
            renderSaves();
            tableWrap.hidden = !state.available || state.playthroughs.length === 0;
            pickerStatus.textContent = '';
            if (!state.available) pickerStatus.textContent = 'Open Manage saves to set up Playthrough Saves.';
            else if (state.playthroughs.length === 0) pickerStatus.textContent = 'No saved playthroughs yet. Open Manage saves to save your current progress.';
        } catch (failure) {
            state = null; rows.replaceChildren(); retry.hidden = false;
            current.textContent = 'Saves unavailable';
            pickerStatus.textContent = failure.name === 'AbortError' ? 'Loading saves took too long. Try again or open Manage saves.'
                : 'Could not load Playthrough Saves. Try again or open Manage saves.';
            status.textContent = 'Open Switch playthrough to try again, or open Manage saves.';
        } finally { clearTimeout(timeout); loading = false; }
    }
    loadState();

})();
