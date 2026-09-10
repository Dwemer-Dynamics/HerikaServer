// Standalone manager: all writes, previews and protections are owned by the server API.
(() => {
    'use strict';
    const root = document.querySelector('#retention-section[data-api]');
    if (!root) return;
    const host = document.getElementById('ps-controls'), status = document.getElementById('ps-status');
    let busy = false;
    const node = (tag, text) => { const n = document.createElement(tag); if (text != null) n.textContent = text; return n; };
    async function request(action, fields = {}) {
        const response = await fetch(root.dataset.api, action ? {method:'POST', credentials:'same-origin', body:new URLSearchParams({action,csrf_token:root.dataset.csrf,...fields})} : {credentials:'same-origin',cache:'no-store'});
        const data = await response.json();
        if (!response.ok || !data.ok) throw new Error(data.error || 'The request failed.');
        return data;
    }
    async function perform(fn) {
        if (busy) return;
        busy = true; root.setAttribute('aria-busy','true'); status.textContent = 'Working...';
        try { await fn(); status.setAttribute('role','status'); }
        catch (e) { status.textContent = e.message; status.setAttribute('role','alert'); }
        finally { busy = false; root.removeAttribute('aria-busy'); }
    }
    function button(text, fn) {
        const b = node('button', text); b.type = 'button'; b.addEventListener('click', () => perform(fn)); return b;
    }
    function field(parent, key, label, value, type = 'number', min = 0, max = 10000) {
        const wrap = node('label'), input = node('input'); input.name = key; input.type = type;
        if (type === 'checkbox') input.checked = value === true; else { input.value = value; input.min = min; input.max = max; input.step = '1'; input.required = true; }
        wrap.append(input,document.createTextNode(label)); parent.append(wrap); return input;
    }
    const values = form => Object.fromEntries([...form.querySelectorAll('input,select')].map(i => [i.name, i.type === 'checkbox' ? (i.checked ? '1':'0') : i.value]));
    function group(parent, title) { const n = node('fieldset'); n.append(node('legend',title)); parent.append(n); return n; }
    async function load() {
        const state = await request(); render(state); status.textContent = 'Settings loaded.';
    }
    function preview(area, plan) {
        area.replaceChildren(node('h3',plan.scope ? plan.scope.label + ': cleanup preview' : 'Cleanup preview'),node('p',plan.message));
        const lines = [];
        for (const item of plan.diagnostics) lines.push((item.label || item.table) + ': ' + item.rows + ' entries');
        if (plan.events?.cutoff_gamets != null) lines.push('Events: ' + plan.events.rows + ' entries');
        for (const item of plan.playthroughs) lines.push('Playthrough Save: ' + item.name);
        lines.forEach(text => area.append(node('p',text)));
        if (!lines.length) area.append(node('p','Nothing to delete.'));
        if (plan.more_possible) area.append(node('p','Another cleanup round may be needed.'));
        area.append(node('p','Preview expires in 5 minutes. Recent entries, unfinished replies and your active Playthrough Save are kept.'));
        if (plan.events?.message) area.append(node('p',plan.events.message));
        const run = button('Delete now', async () => {
            if (Date.now() >= Date.parse(plan.expires_at)) throw new Error('Preview expired. Preview again.');
            if (!confirm('Permanently delete these items? This cannot be undone.\n\n' + lines.join('\n') + (plan.events?.rows > 0 ? '\n\n' + plan.events.message : ''))) return;
            const result = await request('run',{preview_token:plan.token}); await load(); status.textContent = result.result.message;
        });
        run.disabled = !(plan.events?.rows > 0) && !plan.playthroughs.length && !plan.diagnostics.some(item => item.rows > 0); area.append(run);
    }
    function render(state) {
        host.replaceChildren();
        const backup = node('form'), auto = group(backup,'Automatic Playthrough Saves');
        field(auto,'enabled','Save when loading an older game save',state.backup_settings.enabled,'checkbox');
        field(auto,'min_days','Game days behind',state.backup_settings.min_days,'number',1,3650);
        auto.append(node('p','Save mod data first if the game save you load is at least this many game days behind. This does not run on a timer. Settings stay the same after a restore.'));
        auto.append(node('p',state.last_backup ? state.last_backup.message + ' ' + state.last_backup.at : 'No automatic saves recorded yet.'));
        auto.append(button('Save settings',async () => {
            if (!backup.reportValidity()) return;
            await request('save_backup',values(backup)); await load(); status.textContent = 'Automatic save settings saved.';
        })); host.append(backup);
        const form = node('form'), area = node('div'), storage = state.storage;
        const size = bytes => {
            if(bytes == null)return 'Size unavailable';
            const unit=bytes >= 1048576 ? 'MB' : 'KB', divisor=unit==='MB'?1048576:1024;
            return (bytes/divisor).toLocaleString(undefined,{maximumFractionDigits:1})+' '+unit;
        };
        // Reveal invalid settings before moving keyboard focus to their message.
        const valid = container => {
            for(const input of container.querySelectorAll('input,select')) {
                if(!input.checkValidity()){const row=input.closest('details');if(row)row.open=true;input.reportValidity();return false;}
            }
            return true;
        };
        form.noValidate=true;
        const categories = storage?.categories || [];
        const measured = new Map(categories.map(item => [item.key,item]));
        form.append(node('h3','Playthrough Storage'),node('p',size(storage?.database_bytes) + ' total.'));
        form.append(node('p','Turn on cleanup for the categories you want managed automatically, then save your settings. You can also preview and delete items manually.'),node('p','You can preview any category without enabling it. Nothing is deleted until you confirm.'));
        // Each row keeps its size, rules and one-off preview together.
        function cleanupRow(key, label, description) {
            const row = node('details'), summary = node('summary'); row.className = 'ps-cleanup-row';
            summary.append(node('strong',label),node('span',size(measured.get(key)?.bytes)),node('span','Cleanup settings'));
            row.append(summary,node('p',description)); form.append(row);
            return row;
        }
        function categoryPreview(row, key) {
            row.append(button('Preview deletion',async()=> {
                if (!valid(row)) return;
                if (!state.capabilities.category_preview) throw new Error('Update the server to preview one category.');
                preview(area,(await request('preview',{...values(form),preview_category:key})).preview);
                status.textContent='Preview only. Your settings were not saved.';
                area.scrollIntoView({block:'nearest'});
            }));
        }
        for (const category of state.capabilities.categories) {
            const key = category.key, part = cleanupRow(key,category.label,category.description || 'Troubleshooting logs.');
            field(part,key+'_enabled','Clean up automatically',state.settings[key+'_enabled'],'checkbox');
            field(part,key+'_days','Older than (real-world days)',state.settings[key+'_days'],'number',1,3650);
            field(part,key+'_max_mb','Size limit (MB; 0 = Unlimited)',state.settings[key+'_max_mb'],'number',0,102400);
            part.append(node('p','Deletes older logs first when either the age or size limit is exceeded. Size limits apply to log data.'));
            if (key === 'requests') {
                const label = node('label','Request logs to include '), select = node('select'); select.name = 'requests_filter';
                for (const [value,text] of [['all','All request logs'],['relationship','Relationship requests only']]) { const o=node('option',text); o.value=value; select.append(o); }
                select.value=state.settings.requests_filter; label.append(select); part.append(label);
            }
            part.append(node('p','Logs from the last 24 hours are kept.'));
            categoryPreview(part,key);
        }
        if (state.capabilities.event_cleanup) {
            const events = cleanupRow('events','Events',measured.get('events')?.description || 'Raw gameplay and conversation history.');
            field(events,'events_enabled','Clean up automatically (off by default)',state.settings.events_enabled,'checkbox');
            field(events,'events_days','Older than (in-game days)',state.settings.events_days ?? 30,'number',1,3650);
            events.append(node('p','Age is measured from the latest recorded game time. Events recorded in the last 24 real-world hours, unfinished replies and the newest event of each type are kept.'));
            events.append(node('p','Deleting event history can remove details used for NPC recall and future diaries. Existing memories and diaries are kept.'));
            categoryPreview(events,'events');
        }
        const saves = cleanupRow('playthroughs','Playthrough Saves',measured.get('playthroughs')?.description || 'Saved copies of your mod data.');
        field(saves,'playthroughs_enabled','Clean up automatically',state.settings.playthroughs_enabled,'checkbox');
        field(saves,'playthrough_keep','Maximum automatic saves (0 = Unlimited)',state.settings.playthrough_keep);
        saves.append(node('p','Above the limit, the oldest automatic saves are deleted first. Manual, unclassified, active, default and protected saves are kept.'));
        const manage = node('a','Manage saves'); manage.href='#ps-manage-saves'; saves.append(manage);
        categoryPreview(saves,'playthroughs');
        const kept = node('section'); kept.append(node('h3','Data kept by cleanup'));
        for (const category of categories.filter(item=>!item.cleanup)) {
            const row = node('div'); row.className='ps-kept-row';
            row.append(node('strong',category.label),node('span',size(category.bytes)),node('p',category.description)); kept.append(row);
        }
        form.append(kept,node('p','Sizes include indexes and unused space. Size limits apply to log data. Only the preview estimates what can be deleted; cleanup may not reduce files on disk.'));
        form.append(node('p','Automatic cleanup runs at most once an hour while the background service is running. Large cleanups may take several rounds.'));
        if (state.last_run) form.append(node('p','Last cleanup: '+state.last_run.message+' '+state.last_run.at));
        form.append(button('Save settings',async()=> {
            if (!valid(form)) return;
            const fields=values(form);
            if (fields.events_enabled==='1' && (!state.settings.events_enabled || Number(fields.events_days)<state.settings.events_days) && !confirm('Save these Events cleanup rules? Matching older events will be deleted during background cleanup. Your active Playthrough Save is kept.' + '\n\nDeleting event history can remove details used for NPC recall and future diaries. Existing memories and diaries are kept.')) return;
            await request('save',fields); await load(); status.textContent='Cleanup settings saved.';
        }),button('Preview enabled categories',async()=> {
            if (!valid(form)) return;
            preview(area,(await request('preview',values(form))).preview); status.textContent='Preview only. Your saved cleanup rules were not changed.';
            area.scrollIntoView({block:'nearest'});
        }));
        form.addEventListener('input',()=>area.replaceChildren());
        for (const f of [form,backup]) f.addEventListener('submit',e=>e.preventDefault());
        const manageTitle=node('h3','Manage Playthrough Saves'); manageTitle.id='ps-manage-saves';
        host.append(form,area,manageTitle);
        const selected=new Set(), list=node('div');
        const kind={manual:'Manual Save',dragon_break:'Automatic Rollback Save',before_switch:'Before-Switch Save',unclassified:'Unclassified'};
        for (const item of state.playthroughs) {
            const row=node('div');row.className='ps-row';
            const protectedSave=item.is_active||item.is_default||item.pinned;
            const check=field(row,'pick_'+item.id,item.name+' - '+(kind[item.retention_kind]||'Unclassified'),false,'checkbox');check.disabled=protectedSave||item.storage_type!=='schema';
            check.addEventListener('change',()=> { if(check.checked&&selected.size>=50){check.checked=false;status.textContent='Select up to 50 saves.';return;} check.checked?selected.add(item.id):selected.delete(item.id); area.replaceChildren(); });
            if (item.is_active||item.is_default) row.append(node('span',item.is_active?'Active':'Default'));
            else row.append(button(item.pinned?'Remove protection':'Protect',async()=>{await request('pin',{profile_id:item.id,pinned:item.pinned?'0':'1'});await load();}));
            list.append(row);
        }
        host.append(list,button('Preview deletion',async()=>{
            if (!selected.size) throw new Error('Select saves that are not active or protected first.');
            preview(area,(await request('preview_delete',{profile_ids:JSON.stringify([...selected])})).preview); area.scrollIntoView({block:'nearest'}); status.textContent='Review the selected saves before deleting.';
        }));
    }
    perform(load);
})();
