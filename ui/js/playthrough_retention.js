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
        area.replaceChildren(node('h3','Cleanup preview'),node('p',plan.message));
        const lines = [];
        for (const item of plan.diagnostics) lines.push((item.label || item.table) + ': ' + item.rows + ' entries');
        for (const item of plan.playthroughs) lines.push('Playthrough Save: ' + item.name);
        lines.forEach(text => area.append(node('p',text)));
        if (!lines.length) area.append(node('p','Nothing is eligible.'));
        if (plan.more_possible) area.append(node('p','Another cleanup round may be needed.'));
        area.append(node('p','This preview expires in 5 minutes. Recent logs, undelivered replies and live gameplay history are kept.'));
        const run = button('Delete this batch', async () => {
            if (Date.now() >= Date.parse(plan.expires_at)) throw new Error('Preview expired. Preview again.');
            if (!confirm('Permanently delete this exact batch?\n\n' + lines.join('\n'))) return;
            const result = await request('run',{preview_token:plan.token}); await load(); status.textContent = result.result.message;
        });
        run.disabled = !plan.playthroughs.length && !plan.diagnostics.some(item => item.rows > 0); area.append(run);
    }
    function render(state) {
        host.replaceChildren();
        const backup = node('form'), auto = group(backup,'Automatic Playthrough Saves');
        field(auto,'enabled','Make a Playthrough Save when loading an older game save',state.backup_settings.enabled,'checkbox');
        field(auto,'min_days','Minimum in-game days behind',state.backup_settings.min_days,'number',1,3650);
        auto.append(node('p','This is triggered by loading an older save, not by a timer. These settings apply to this game and stay the same after a restore.'));
        auto.append(node('p',state.last_backup ? state.last_backup.message + ' ' + state.last_backup.at : 'No automatic save attempt recorded yet.'));
        auto.append(button('Save automatic save settings',async () => {
            if (!backup.reportValidity()) return;
            await request('save_backup',values(backup)); await load(); status.textContent = 'Automatic save settings saved.';
        })); host.append(backup);
        const form = node('form'), grid = node('div'); grid.className = 'ps-grid'; form.append(grid);
        const saves = group(grid,'Automatic Playthrough Save limit');
        field(saves,'playthroughs_enabled','Delete extra automatic saves',state.settings.playthroughs_enabled,'checkbox');
        field(saves,'playthrough_keep','Maximum automatic saves (0 = Unlimited)',state.settings.playthrough_keep);
        saves.append(node('p','Oldest eligible automatic saves are removed first. Manual, unclassified, active, default and protected saves are kept.'));
        for (const category of state.capabilities.categories) {
            const part = group(grid,category.label), key = category.key;
            field(part,key+'_enabled','Include in cleanup',state.settings[key+'_enabled'],'checkbox');
            field(part,key+'_days','Older than (real-world days)',state.settings[key+'_days'],'number',1,3650);
            field(part,key+'_max_mb','Also trim above (MB; 0 = off)',state.settings[key+'_max_mb'],'number',0,102400);
            if (key === 'requests') {
                const label = node('label','Request logs to include '), select = node('select'); select.name = 'requests_filter';
                for (const [value,text] of [['all','All request logs'],['relationship','Relationship request logs only']]) { const o=node('option',text); o.value=value; select.append(o); }
                select.value=state.settings.requests_filter; label.append(select); part.append(label);
            }
        }
        field(form,'automatic','Run saved cleanup rules automatically (off by default)',state.settings.automatic,'checkbox');
        form.append(node('p','Runs at most hourly while the background service runs. Each round removes up to 1,000 entries per log type and 3 automatic saves.'));
        form.append(node('p',state.event_status));
        if (state.last_run) form.append(node('p','Last cleanup: '+state.last_run.message+' '+state.last_run.at));
        const area = node('div');
        form.append(button('Save cleanup settings',async()=> {
            if (!form.reportValidity()) return;
            const fields=values(form);
            if (fields.automatic==='1' && !state.settings.automatic && !confirm('Enable automatic cleanup using these rules?')) return;
            await request('save',fields); await load(); status.textContent='Cleanup settings saved.';
        }),button('Preview cleanup',async()=> {
            if (!form.reportValidity()) return;
            preview(area,(await request('preview',values(form))).preview); status.textContent='Preview ready. Settings were not saved.';
        }));
        form.addEventListener('input',()=>area.replaceChildren());
        for (const f of [form,backup]) f.addEventListener('submit',e=>e.preventDefault());
        host.append(form,area,node('h3','Protect or delete Playthrough Saves'));
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
        host.append(list,button('Preview selected deletion',async()=>{
            if (!selected.size) throw new Error('Select inactive, unprotected saves first.');
            preview(area,(await request('preview_delete',{profile_ids:JSON.stringify([...selected])})).preview); area.scrollIntoView({block:'nearest'}); status.textContent='Review the selected saves before deleting.';
        }));
    }
    perform(load);
})();
