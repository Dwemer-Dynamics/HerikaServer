'use strict';
// Enhance existing local setup without adding polling, engine controls, or browser-to-model requests.
(() => {
    const script = document.currentScript || document.querySelector('script[data-distro-llm]');
    const boot = () => {
        const lorkhan = !!document.getElementById('qs-local-server');
        const stobe = !!document.getElementById('local_llm_provider');
        const provider = document.getElementById(lorkhan ? 'qs-local-server' : stobe ? 'local_llm_provider' : 'qs_local_llm_server_type');
        if (!provider) return;
        const model = document.getElementById(lorkhan ? 'qs-local-model' : stobe ? 'local_llm_model' : 'qs_local_llm_model');
        const url = document.getElementById(lorkhan ? 'qs-local-endpoint' : stobe ? 'local_llm_base_url' : 'qs_local_llm_url');
        const key = document.getElementById(lorkhan ? 'qs-local-key' : stobe ? 'local_llm_api_key' : 'qs_local_llm_api_key');
        const panel = document.createElement('div'); panel.hidden = true; panel.className = 'qs-field qs-local-llm-field';
        const label = document.createElement('label'); label.htmlFor = 'distro-model'; label.textContent = 'Loaded model';
        const select = document.createElement('select'); select.id = 'distro-model'; select.className = model.className;
        const refresh = document.createElement('button'); refresh.type = 'button'; refresh.textContent = 'Refresh models'; refresh.className = 'btn-primary qs-mini-btn';
        const manager = document.createElement('a'); manager.textContent = 'Open LLM Studio'; manager.target = '_blank'; manager.rel = 'noopener noreferrer';
        const managerUrl = new URL(location.href); managerUrl.port = '8081'; managerUrl.pathname = '/Dwemer-Dashboard/lmstudio.php'; managerUrl.search = ''; managerUrl.hash = ''; manager.href = managerUrl.href;
        const note = document.createElement('p'); note.className = 'qs-hint'; note.textContent = 'Runs inside DwemerDistro. No API key needed. Use Open Manager in the launcher to unlock engine controls.';
        const status = document.createElement('p'); status.id = 'distro-status'; status.setAttribute('role', 'status'); status.setAttribute('aria-live', 'polite');
        const actions = document.createElement('div'); actions.className = 'qs-btn-row'; actions.append(refresh, document.createTextNode(' '), manager);
        panel.append(label, select, actions, note, status); model.parentElement.append(panel);
        let generation = 0;
        select.addEventListener('change', () => { model.value = select.value; model.dispatchEvent(new Event('input', {bubbles: true})); });
        async function discover() {
            if (provider.value !== 'dwemerdistro') return;
            refresh.disabled = true; const current = ++generation;
            status.textContent = 'Checking LLM Studio…';
            try {
                const response = await fetch(script.dataset.statusUrl, {credentials: 'same-origin', cache: 'no-store', signal: AbortSignal.timeout(6000)});
                if (!response.ok) throw new Error('Could not check LLM Studio. Reload Quickstart and try again.');
                const result = await response.json();
                if (provider.value !== 'dwemerdistro' || current !== generation) return;
                const ids = Array.isArray(result.models) ? result.models : [];
                select.replaceChildren(new Option('Choose a loaded model', ''), ...ids.map(id => new Option(id, id)));
                select.value = ids.includes(model.value) ? model.value : ids.length === 1 ? ids[0] : '';
                select.dispatchEvent(new Event('change')); status.textContent = result.message;
            } catch (error) { if (current === generation) status.textContent = error.message; }
            finally { if (current === generation) refresh.disabled = false; }
        }
        function sync() {
            const managed = provider.value === 'dwemerdistro';
            panel.hidden = !managed; model.hidden = managed; url.readOnly = managed;
            const modelLabel = document.querySelector('label[for="' + model.id + '"]');
            if (modelLabel) modelLabel.hidden = managed;
            for (const sibling of model.parentElement.children) {
                if (sibling !== panel && ['P', 'SMALL'].includes(sibling.tagName)) sibling.hidden = managed;
            }
            if (stobe) {
                const help = document.querySelector('#local-llm-form .qs-callout');
                if (help) help.hidden = managed;
                document.getElementById('local_llm_probe_btn').hidden = managed;
            }
            if (key) {
                key.readOnly = managed; if (managed) key.value = '';
                const keyField = key.closest('.qs-field');
                if (keyField) keyField.hidden = managed;
            }
            const addressHelp = document.querySelector('#qs_local_llm_section .qs-local-llm-help');
            if (addressHelp) addressHelp.hidden = managed;
            if (managed) {
                url.value = 'http://127.0.0.1:1234/v1/chat/completions';
                url.dispatchEvent(new Event('input', {bubbles: true}));
            }
            for (const id of ['qs_local_llm_use_host_ip', 'qs_local_llm_use_wsl_ip']) { const button = document.getElementById(id); if (button) button.hidden = managed; }
            document.querySelectorAll('[data-local-ip]').forEach(button => button.hidden = managed);
            for (const id of ['qs_local_llm_loopback_warning','qs-local-loopback']) { const warning = document.getElementById(id); if (warning && managed) warning.hidden = true; }
        }
        provider.addEventListener('change', () => { ++generation; sync(); if (provider.value === 'dwemerdistro') discover(); });
        refresh.addEventListener('click', discover);
        sync(); if (provider.value === 'dwemerdistro') discover();
    };
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();
})();
