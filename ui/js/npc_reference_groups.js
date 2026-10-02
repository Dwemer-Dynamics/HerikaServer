// Reference groups: plugin actors that are one character and share a profile automatically.
//
// Built-in groups ship with the server. Saving one stores a custom override with the same key, and
// Reset deletes that override so the built-in group applies again. Custom groups are user rows.
(function () {
    'use strict';

    const API = '../api/chim_npc_manager.php';

    const backdrop = document.getElementById('npc_refgroup_modal');
    if (!backdrop) return;

    const byId = (id) => document.getElementById(id);
    const container = backdrop.querySelector('.modal-container');
    const statusLine = byId('npc_refgroup_status');
    const errorLine = byId('npc_refgroup_error');
    const listPanel = byId('npc_refgroup_list_panel');
    const list = byId('npc_refgroup_list');
    const addButton = byId('npc_refgroup_add');
    const closeButton = byId('npc_refgroup_close');
    const form = byId('npc_refgroup_form');
    const formHeading = byId('npc_refgroup_form_heading');
    const formNote = byId('npc_refgroup_form_note');
    const nameInput = byId('npc_refgroup_name');
    const pluginInput = byId('npc_refgroup_plugin');
    const idsInput = byId('npc_refgroup_ids');
    const enabledInput = byId('npc_refgroup_enabled');
    const saveButton = byId('npc_refgroup_save');
    const cancelButton = byId('npc_refgroup_cancel');

    let groups = [];
    let editing = null;
    let previousFocus = null;
    let previousBodyOverflow = '';
    let requestGeneration = 0;
    let busy = false;

    function element(tag, className, text) {
        const node = document.createElement(tag);
        if (className) node.className = className;
        if (text !== undefined) node.textContent = text;
        return node;
    }

    function setStatus(message) { statusLine.textContent = message || ''; }

    function setError(message) {
        errorLine.textContent = message || '';
        errorLine.hidden = !message;
    }

    function setBusy(value) {
        busy = value;
        container.setAttribute('aria-busy', value ? 'true' : 'false');
        container.querySelectorAll('[data-refgroup-busy]').forEach((node) => { node.disabled = value; });
    }

    function isOpen() { return backdrop.style.display === 'flex'; }

    function focusable() {
        return Array.from(container.querySelectorAll(
            'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])'
        )).filter((node) => !node.disabled && node.offsetParent !== null);
    }

    async function request(init) {
        const query = init ? '' : '?operation=reference_groups';
        const response = await fetch(API + query, Object.assign({ cache: 'no-store', credentials: 'same-origin' }, init || {}));
        let payload = null;
        try { payload = await response.json(); } catch (_error) { payload = null; }
        if (!payload || !payload.success) {
            throw new Error((payload && payload.error) || `Request failed (HTTP ${response.status})`);
        }
        return payload.data || {};
    }

    function post(body) {
        return request({
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body)
        });
    }

    // Show the effective catalog: a custom row with a built-in key replaces that built-in group.
    function applyCatalog(data) {
        const defaults = Array.isArray(data.defaults) ? data.defaults : [];
        const custom = Array.isArray(data.custom) ? data.custom : [];
        const customByKey = new Map(custom.map((group) => [group.group_key, group]));
        const merged = defaults.map((group) => customByKey.has(group.group_key)
            ? Object.assign({}, customByKey.get(group.group_key), { source: 'override', builtin: group })
            : Object.assign({}, group, { source: 'default' }));
        custom.forEach((group) => {
            if (!group.overrides_default) merged.push(Object.assign({}, group, { source: 'custom' }));
        });
        merged.sort((a, b) => a.display_name.localeCompare(b.display_name, undefined, { sensitivity: 'base' }));
        groups = merged;
        renderList();
    }

    function badge(text, modifier) {
        return element('span', 'npc-refgroup-badge' + (modifier ? ' ' + modifier : ''), text);
    }

    function actionButton(label, action, key, className, ariaLabel) {
        const button = element('button', className || '', label);
        button.type = 'button';
        button.dataset.action = action;
        button.dataset.key = key;
        button.setAttribute('data-refgroup-busy', '');
        button.setAttribute('aria-label', ariaLabel);
        button.disabled = busy;
        return button;
    }

    function renderList() {
        if (!groups.length) {
            list.replaceChildren(element('li', 'npc-merge-empty', 'No reference groups yet.'));
            return;
        }
        list.replaceChildren(...groups.map((group) => {
            const item = element('li', 'npc-refgroup-item' + (group.enabled ? '' : ' is-disabled'));
            const copy = element('div', 'npc-refgroup-copy');
            const title = element('div', 'npc-refgroup-name');
            title.append(element('span', '', group.display_name));
            title.append(badge(
                group.source === 'default' ? 'Built-in' : (group.source === 'override' ? 'Built-in, edited' : 'Custom'),
                group.source === 'custom' ? 'is-custom' : ''
            ));
            if (!group.enabled) title.append(badge('Disabled', 'is-off'));
            const meta = element('div', 'npc-merge-option-meta');
            meta.append(element('span', 'npc-merge-origin', group.plugin_name));
            meta.append(element('span', 'npc-merge-refid', group.local_formids.join(', ')));
            copy.append(title, meta);

            const name = group.display_name;
            const actions = element('div', 'npc-merge-actions npc-refgroup-row-actions');
            actions.append(actionButton('Edit', 'edit', group.group_key, '', `Edit ${name}`));
            actions.append(actionButton(group.enabled ? 'Disable' : 'Enable', 'toggle', group.group_key, '',
                `${group.enabled ? 'Disable' : 'Enable'} ${name}`));
            if (group.source === 'override') {
                actions.append(actionButton('Reset', 'reset', group.group_key, '', `Reset ${name} to built-in`));
            } else if (group.source === 'custom') {
                actions.append(actionButton('Delete', 'delete', group.group_key, 'npc-merge-danger', `Delete ${name}`));
            }
            item.append(copy, actions);
            return item;
        }));
    }

    async function load() {
        const generation = ++requestGeneration;
        setBusy(true);
        setError('');
        setStatus('Loading reference groups...');
        list.replaceChildren(element('li', 'npc-merge-empty', 'Loading...'));
        try {
            const data = await request();
            if (generation !== requestGeneration) return;
            setStatus('');
            applyCatalog(data);
        } catch (error) {
            if (generation !== requestGeneration) return;
            setStatus('');
            list.replaceChildren();
            setError(error.message || 'Could not load reference groups.');
        } finally {
            if (generation === requestGeneration) setBusy(false);
        }
    }

    // Run one write, then show the catalog the server returns after it commits.
    async function write(body, doneMessage, focusTarget) {
        const generation = ++requestGeneration;
        setBusy(true);
        setError('');
        setStatus('Saving...');
        try {
            const data = await post(body);
            if (generation !== requestGeneration) return false;
            applyCatalog(data);
            setStatus(doneMessage);
            return true;
        } catch (error) {
            if (generation !== requestGeneration) return false;
            setStatus('');
            setError(error.message || 'Could not save the reference group.');
            return false;
        } finally {
            if (generation === requestGeneration) {
                setBusy(false);
                if (focusTarget && document.contains(focusTarget) && !focusTarget.disabled) focusTarget.focus();
            }
        }
    }

    function parseIds(text) {
        return String(text || '').split(/[\s,;]+/).map((value) => value.trim()).filter(Boolean);
    }

    function showForm(group) {
        editing = group || null;
        formHeading.textContent = group ? `Edit ${group.display_name}` : 'Add custom group';
        formNote.hidden = !(group && group.source === 'default');
        nameInput.value = group ? group.display_name : '';
        pluginInput.value = group ? group.plugin_name : 'Skyrim.esm';
        idsInput.value = group ? group.local_formids.join('\n') : '';
        enabledInput.checked = group ? !!group.enabled : true;
        setError('');
        setStatus('');
        listPanel.hidden = true;
        addButton.hidden = true;
        form.hidden = false;
        nameInput.focus();
    }

    function hideForm(focusKey) {
        const wasEditing = editing;
        editing = null;
        form.hidden = true;
        listPanel.hidden = false;
        addButton.hidden = false;
        const key = focusKey || (wasEditing && wasEditing.group_key);
        const target = key ? list.querySelector(`button[data-action="edit"][data-key="${CSS.escape(key)}"]`) : null;
        (target || addButton).focus();
    }

    async function submitForm(event) {
        event.preventDefault();
        if (busy) return;
        const body = {
            operation: 'reference_group_save',
            display_name: nameInput.value.trim(),
            plugin_name: pluginInput.value.trim(),
            local_formids: parseIds(idsInput.value),
            enabled: enabledInput.checked
        };
        if (editing) body.group_key = editing.group_key;
        const ids = body.local_formids;
        if (!body.display_name) { setError('Enter a character name.'); nameInput.focus(); return; }
        if (!body.plugin_name) { setError('Enter a plugin filename.'); pluginInput.focus(); return; }
        if (ids.length < 2 || ids.length > 32) { setError('Enter between 2 and 32 local FormIDs.'); idsInput.focus(); return; }
        const editedKey = editing ? editing.group_key : '';
        const saved = await write(body, 'Group saved.', saveButton);
        if (!saved) return;
        // New groups receive their key from the server; find the saved row by name.
        const savedGroup = groups.find((group) => group.group_key === editedKey)
            || groups.find((group) => group.display_name === body.display_name);
        hideForm(savedGroup ? savedGroup.group_key : '');
    }

    function onListClick(event) {
        const button = event.target.closest('button[data-action]');
        if (!button || busy) return;
        const group = groups.find((item) => item.group_key === button.dataset.key);
        if (!group) return;
        const action = button.dataset.action;
        if (action === 'edit') { showForm(group); return; }
        if (action === 'toggle') {
            write({
                operation: 'reference_group_save',
                group_key: group.group_key,
                display_name: group.display_name,
                plugin_name: group.plugin_name,
                local_formids: group.local_formids,
                enabled: !group.enabled
            }, `${group.display_name} ${group.enabled ? 'disabled' : 'enabled'}.`, null).then(() => {
                focusRow(group.group_key, 'toggle');
            });
            return;
        }
        if (action === 'reset') {
            if (!window.confirm(`Reset ${group.display_name} to the built-in group?`)) return;
            write({ operation: 'reference_group_delete', group_key: group.group_key },
                `${group.display_name} reset to built-in.`, null).then(() => focusRow(group.group_key, 'edit'));
            return;
        }
        if (action === 'delete') {
            if (!window.confirm(`Delete the custom group ${group.display_name}? Actors it linked will stop sharing a profile.`)) return;
            write({ operation: 'reference_group_delete', group_key: group.group_key },
                `${group.display_name} deleted.`, null).then(() => { if (isOpen()) addButton.focus(); });
        }
    }

    function focusRow(key, action) {
        if (!isOpen()) return;
        const target = list.querySelector(`button[data-action="${action}"][data-key="${CSS.escape(key)}"]`);
        (target || addButton).focus();
    }

    function open(trigger) {
        previousFocus = trigger || document.activeElement;
        previousBodyOverflow = document.body.style.overflow;
        backdrop.style.display = 'flex';
        document.body.style.overflow = 'hidden';
        editing = null;
        form.hidden = true;
        listPanel.hidden = false;
        addButton.hidden = false;
        closeButton.focus();
        load();
    }

    function close() {
        requestGeneration += 1;
        backdrop.style.display = 'none';
        document.body.style.overflow = previousBodyOverflow;
        setBusy(false);
        setStatus('');
        setError('');
        const restore = previousFocus;
        previousFocus = null;
        // AJAX list refreshes replace the toolbar; fall back to its current button.
        const target = (restore && document.contains(restore))
            ? restore
            : document.querySelector('.npc-refgroup-open');
        if (target) { try { target.focus(); } catch (_error) { /* focus is best effort */ } }
    }

    // Delegated: the toolbar is re-rendered after search and paging.
    document.addEventListener('click', function (event) {
        const trigger = event.target.closest('.npc-refgroup-open');
        if (!trigger) return;
        event.preventDefault();
        open(trigger);
    });
    closeButton.addEventListener('click', close);
    addButton.addEventListener('click', function () { showForm(null); });
    cancelButton.addEventListener('click', function () { setError(''); hideForm(''); });
    form.addEventListener('submit', submitForm);
    list.addEventListener('click', onListClick);

    // Capture phase so Escape does not also reach the card editor or the hosting Config Hub.
    document.addEventListener('keydown', function (event) {
        if (!isOpen()) return;
        if (event.key === 'Escape') {
            event.preventDefault();
            event.stopImmediatePropagation();
            if (form.hidden) { close(); } else if (!busy) { setError(''); hideForm(''); }
            return;
        }
        if (event.key !== 'Tab') return;
        const nodes = focusable();
        if (!nodes.length) return;
        const first = nodes[0];
        const last = nodes[nodes.length - 1];
        const active = document.activeElement;
        if (event.shiftKey && (active === first || !container.contains(active))) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && (active === last || !container.contains(active))) {
            event.preventDefault();
            first.focus();
        }
    }, true);
}());
