'use strict';
// Ploofy model autocomplete for both connector editors. Models are fetched lazily through the
// server so the stored key never reaches the browser, and are cached per selected API badge.
(() => {
    const script = document.currentScript || document.querySelector('script[data-ploofy-connector]');
    const DEFAULT_MODEL = 'swift';
    const MARGIN = 8, PREFERRED_WIDTH = 420, TIMEOUT_MS = 15000;
    const boot = () => {
        const service = document.getElementById('service_input');
        const model = document.querySelector('input[name="model"]');
        const badge = document.getElementById('api_badge_id');
        const driver = document.querySelector('input[name="driver"]');
        if (!service || !model || !script || !script.dataset.modelsUrl) return;
        const cache = new Map();
        // Last Ploofy model in this editor; switching away and back restores it, otherwise the default.
        let ploofyModel = service.value === 'ploofy' ? model.value : '';
        let current = service.value, items = [], highlighted = -1, filter = '';
        // Each open/close cycle has its own session so late responses never reopen a closed list.
        let session = 0, inflight = null;
        const dropdown = document.createElement('div');
        dropdown.className = 'orm-dropdown';
        const list = document.createElement('div');
        list.id = 'ploofy-model-list'; list.setAttribute('role', 'listbox'); list.setAttribute('aria-label', 'Ploofy models');
        // Keeps focus in the model field so row clicks select instead of blurring.
        dropdown.addEventListener('mousedown', e => e.preventDefault());
        document.body.appendChild(dropdown);

        const active = () => service.value === 'ploofy';
        // Other editor scripts may hide every .orm-dropdown directly, so read state from the element.
        const isOpen = () => dropdown.style.display === 'block';
        // Combobox semantics apply only while Ploofy owns the model field.
        function syncAria() {
            const attrs = { role: 'combobox', 'aria-controls': list.id, 'aria-autocomplete': 'list', 'aria-expanded': 'false' };
            Object.keys(attrs).forEach(k => { if (active()) model.setAttribute(k, attrs[k]); else model.removeAttribute(k); });
            model.removeAttribute('aria-activedescendant');
        }
        function position() {
            const rect = model.getBoundingClientRect();
            const viewport = document.documentElement.clientWidth || window.innerWidth;
            const width = Math.max(0, Math.min(Math.max(rect.width, PREFERRED_WIDTH), viewport - MARGIN * 2));
            const left = Math.min(Math.max(rect.left, MARGIN), Math.max(MARGIN, viewport - MARGIN - width));
            // Inline sizes override the embedded editor's .orm-dropdown min-width.
            Object.assign(dropdown.style, { boxSizing: 'border-box', left: (left + window.scrollX) + 'px', top: (rect.bottom + window.scrollY + 4) + 'px', minWidth: '0', width: width + 'px', maxWidth: (viewport - MARGIN * 2) + 'px', display: 'block' });
            model.setAttribute('aria-expanded', 'true');
        }
        function close() {
            session++;
            dropdown.style.display = 'none'; items = []; highlighted = -1; filter = '';
            if (active()) model.setAttribute('aria-expanded', 'false');
            model.removeAttribute('aria-activedescendant');
        }
        function cancelFetch() { if (inflight) { inflight.cancelled = true; inflight.controller.abort(); inflight = null; } }
        const head = () => { const el = document.createElement('div'); el.className = 'orm-head'; el.textContent = 'Ploofy Models'; return el; };
        function message(cls, text) {
            const body = document.createElement('div'); body.className = cls; body.setAttribute('role', 'status'); body.textContent = text;
            list.replaceChildren();
            dropdown.replaceChildren(head(), body, list); items = []; highlighted = -1;
            model.removeAttribute('aria-activedescendant'); position();
        }
        function choose(id) {
            model.value = id;
            if (active()) ploofyModel = id;
            model.dispatchEvent(new Event('input', { bubbles: true }));
            model.dispatchEvent(new Event('change', { bubbles: true }));
            close();
        }
        function highlight(index) {
            items.forEach((el, i) => { el.style.background = i === index ? '#1a1f29' : ''; el.setAttribute('aria-selected', i === index ? 'true' : 'false'); });
            highlighted = index;
            if (items[index]) { model.setAttribute('aria-activedescendant', items[index].id); items[index].scrollIntoView({ block: 'nearest' }); }
            else model.removeAttribute('aria-activedescendant');
        }
        // Opening lists every model so a prefilled value does not hide the alternatives; typing filters.
        function render(models) {
            const q = filter.trim().toLowerCase();
            const matches = models.filter(m => !q || m.id.toLowerCase().includes(q));
            const note = document.createElement('div'); note.className = 'orm-note'; note.textContent = 'Click or use the arrow keys and Enter to select. You can also type any model ID.';
            const rows = [head(), note];
            const muted = text => { const el = document.createElement('div'); el.className = 'orm-muted'; el.style.padding = '8px 10px'; el.textContent = text; return el; };
            if (models.length === 0) rows.push(muted('Ploofy returned no models for this key.'));
            else if (matches.length === 0) rows.push(muted('No matches'));
            items = matches.map((m, i) => {
                const row = document.createElement('div'); row.className = 'orm-item'; row.id = 'ploofy-model-opt-' + i;
                row.setAttribute('role', 'option'); row.setAttribute('aria-selected', 'false'); row.title = m.id;
                const name = document.createElement('div'); name.textContent = m.id;
                const sub = document.createElement('div'); sub.className = 'orm-muted'; sub.style.cssText = 'font-size:12px;margin-top:2px'; sub.textContent = m.owned_by || 'Ploofy';
                row.append(name, sub); row.addEventListener('click', () => choose(m.id));
                return row;
            });
            list.replaceChildren(...items);
            dropdown.replaceChildren(...rows, list);
            highlighted = -1; model.removeAttribute('aria-activedescendant'); position();
        }
        // One shared request per badge, so focus followed by click does not fetch twice.
        function fetchModels(badgeId) {
            if (cache.has(badgeId)) return Promise.resolve({ models: cache.get(badgeId) });
            if (inflight && inflight.badgeId === badgeId) return inflight.promise;
            cancelFetch();
            const entry = { badgeId, controller: new AbortController(), cancelled: false };
            const timer = setTimeout(() => entry.controller.abort(), TIMEOUT_MS);
            entry.promise = (async () => {
                try {
                    const response = await fetch(script.dataset.modelsUrl, { method: 'POST', credentials: 'same-origin', cache: 'no-store', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ api_badge_id: badgeId }), signal: entry.controller.signal });
                    let json = null;
                    try { json = await response.json(); } catch (_) {}
                    if (entry.cancelled) return {};
                    if (!response.ok || !Array.isArray(json)) return { error: (json && typeof json.error === 'string') ? json.error : 'Failed to load models. Check API key.' };
                    const models = json.filter(m => m && typeof m.id === 'string' && m.id !== '').map(m => ({ id: m.id, owned_by: typeof m.owned_by === 'string' ? m.owned_by : '' }));
                    cache.set(badgeId, models);
                    return { models };
                } catch (_) {
                    return entry.cancelled ? {} : { error: 'Failed to load models. Check your connection and try again.' };
                } finally {
                    clearTimeout(timer);
                    if (inflight === entry) inflight = null;
                }
            })();
            inflight = entry;
            return entry.promise;
        }
        async function show() {
            if (!active() || document.activeElement !== model) return;
            const mine = session;
            const badgeId = badge ? badge.value : '';
            const option = badge ? badge.options[badge.selectedIndex] : null;
            if (!badgeId) { message('orm-err', 'Please select an API Key first.'); return; }
            if (option && option.getAttribute('data-empty') === '1') { message('orm-err', 'The selected API Key is empty. Add your Ploofy key in API Keys.'); return; }
            if (!cache.has(badgeId)) message('orm-note', 'Loading…');
            const result = await fetchModels(badgeId);
            // Discard results for a closed list, another service, or an unfocused field.
            if (mine !== session || !active() || document.activeElement !== model) return;
            // The editors can change the badge without a change event; reload for the current one.
            if (badge && badge.value !== badgeId) { show(); return; }
            if (result.models) render(result.models);
            else if (result.error) message('orm-err', result.error);
        }

        model.addEventListener('focus', show);
        model.addEventListener('click', () => { if (!isOpen()) show(); });
        model.addEventListener('input', () => {
            if (!active()) return;
            ploofyModel = model.value;
            filter = model.value;
            const models = cache.get(badge ? badge.value : '');
            if (isOpen() && models) render(models);
        });
        model.addEventListener('blur', close);
        model.addEventListener('keydown', e => {
            if (!active()) return;
            if (e.key === 'Escape') { if (isOpen()) { e.preventDefault(); close(); } return; }
            if (e.key === 'ArrowDown' && !isOpen()) { e.preventDefault(); show(); return; }
            if (!isOpen() || items.length === 0) return;
            if (e.key === 'ArrowDown') { e.preventDefault(); highlight((highlighted + 1) % items.length); }
            else if (e.key === 'ArrowUp') { e.preventDefault(); highlight(highlighted <= 0 ? items.length - 1 : highlighted - 1); }
            else if (e.key === 'Enter' && highlighted >= 0) { e.preventDefault(); items[highlighted].click(); }
        });
        window.addEventListener('resize', () => { if (isOpen()) position(); });
        window.addEventListener('scroll', () => { if (isOpen()) position(); }, true);
        if (badge) badge.addEventListener('change', () => { cancelFetch(); cache.clear(); close(); });

        // The editors dispatch this before applying URL and driver defaults, and again with the same
        // service on every model edit; only a real switch changes the model or driver.
        document.addEventListener('llm-service-change', e => {
            const next = e.detail;
            if (next === current) return;
            current = next; cancelFetch(); close();
            service.value = next;
            syncAria();
            if (next !== 'ploofy') return;
            if (driver) driver.value = 'openaijson';
            model.value = ploofyModel || DEFAULT_MODEL;
        });
        syncAria();
    };
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();
})();
