'use strict';
// Add bounded, on-demand discovery to the existing connector editor and test flow.
(() => {
    const script = document.currentScript || document.querySelector('script[data-distro-connector]');
    const boot = () => {
        const lorkhan = !!document.getElementById('llm_service');
        const service = document.getElementById(lorkhan ? 'llm_service' : 'service_input');
        const model = document.querySelector(lorkhan ? '#llm_model' : 'input[name="model"]');
        if (!service || !model || document.getElementById('distro-connector-panel')) return;
        const endpoint = document.querySelector(lorkhan ? '#llm_endpoint' : 'input[name="url"]');
        const key = document.getElementById(lorkhan ? 'llm_credential' : 'api_badge_id');
        const panel = document.createElement('div'); panel.id = 'distro-connector-panel'; panel.hidden = true;
        panel.style.cssText = 'padding:10px 0;max-width:100%;overflow-wrap:anywhere';
        const label = document.createElement('label'); label.htmlFor = 'distro-connector-model'; label.textContent = 'Loaded model';
        const select = document.createElement('select'); select.id = 'distro-connector-model'; select.style.cssText = 'display:block;width:100%;max-width:100%;margin:6px 0';
        if (model.getAttribute('form')) select.setAttribute('form', model.getAttribute('form'));
        const actions = document.createElement('div'); actions.style.cssText = 'display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin:8px 0';
        const refresh = document.createElement('button'); refresh.type = 'button'; refresh.className = 'btn-primary'; refresh.textContent = 'Refresh models';
        const test = document.createElement('button'); test.type = 'button'; test.className = 'btn-primary'; test.textContent = 'Test connection';
        const originalTest = document.querySelector(lorkhan ? '[data-llm-test-form] button[type="submit"]' : '#btn_test_connector_main, #btn_test_connector');
        const manager = document.createElement('a'); manager.textContent = 'Open LLM Studio'; manager.target = '_blank'; manager.rel = 'noopener noreferrer';
        const managerUrl = new URL(location.href); managerUrl.port = '8081'; managerUrl.pathname = '/Dwemer-Dashboard/lmstudio.php'; managerUrl.search = ''; managerUrl.hash = ''; manager.href = managerUrl.href;
        const note = document.createElement('p'); note.textContent = 'Runs inside DwemerDistro. No API key needed. Save this connector before testing. Use Open Manager in the launcher to unlock engine controls.';
        const status = document.createElement('p'); status.id = 'distro-connector-status'; status.setAttribute('role', 'status');
        actions.append(refresh, test, manager); panel.append(label, select, actions, note, status); model.parentElement.append(panel);
        let active = false, generation = 0, loaded = [], savedKey = key?.value, required = model.required;
        const modelVisible = model.hidden, endpointReadOnly = endpoint.readOnly;
        const updateTest = () => { test.disabled = !originalTest || !loaded.includes(model.value); test.title = originalTest ? '' : 'Save the connector first.'; };
        select.addEventListener('change', () => { model.value = select.value; model.dispatchEvent(new Event('input', {bubbles:true})); updateTest(); });
        test.addEventListener('click', () => originalTest?.click());
        async function discover() {
            if (!active) return;
            const request = ++generation; refresh.disabled = true; status.textContent = 'Checking LLM Studio…';
            try {
                const response = await fetch(script.dataset.statusUrl, {credentials:'same-origin', cache:'no-store', signal:AbortSignal.timeout(6000)});
                if (!response.ok) throw new Error('Could not check LLM Studio. Reload this editor and try again.');
                const result = await response.json(); if (!active || request !== generation) return;
                loaded = Array.isArray(result.models) ? result.models : [];
                const selected = model.value;
                select.replaceChildren(new Option('Choose a loaded model', ''), ...loaded.map(id => new Option(id, id)));
                if (selected && !loaded.includes(selected)) select.add(new Option(selected + ' (not loaded)', selected));
                select.value = selected || (loaded.length === 1 ? loaded[0] : '');
                select.dispatchEvent(new Event('change')); status.textContent = result.message;
            } catch (error) { if (request === generation) { loaded = []; status.textContent = error.message; } }
            finally { if (request === generation) { refresh.disabled = false; updateTest(); } }
        }
        // Run after each product's existing service handler finishes updating its fields.
        function sync() {
            const next = service.value === 'dwemerdistro'; const changed = next !== active;
            if (changed) { ++generation; refresh.disabled = false; }
            if (next && changed) savedKey = key?.value;
            active = next; endpoint.readOnly = active || endpointReadOnly; panel.hidden = !active; model.hidden = active || modelVisible;
            if (active) model.required = false;
            else if (changed) model.required = service.value === 'player2' ? false : required;
            select.required = active; select.disabled = !active;
            if (active) {
                endpoint.value = 'http://127.0.0.1:1234/v1/chat/completions';
                if (key) key.value = lorkhan ? 'none' : '';
                if (!lorkhan) {
                    const driver = document.getElementById('driver_input'); if (driver) driver.value = 'openaijson';
                    const driverSelect = document.getElementById('driver_select'); if (driverSelect) driverSelect.value = 'openaijson';
                }
                const keyRow = lorkhan ? key?.closest('.llm-connection-field') : document.getElementById('api_key_row');
                if (keyRow) { if (lorkhan) keyRow.hidden = true; else keyRow.style.display = 'none'; }
                updateTest();
                if (changed) {
                    select.replaceChildren(new Option(model.value || 'Choose a loaded model', model.value));
                    discover();
                }
            } else if (changed && key && savedKey !== undefined && (service.value === 'custom' || service.value === 'local')) {
                key.value = savedKey;
            }
        }
        refresh.addEventListener('click', discover);
        document.addEventListener('llm-service-change', () => queueMicrotask(sync));
        sync();
    };
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();
})();
