// ----- Data retention panel (kept separate from the timeline/dialog script above) -----
(function(){
    'use strict';
    var form = document.getElementById('retention-form');
    if (!form) return;
    var API = form.dataset.api;
    var CSRF = form.dataset.csrf;
    var statusEl = document.getElementById('retention-status');
    var saveBtn = document.getElementById('ret-save');
    var previewBtn = document.getElementById('ret-preview');
    var runBtn = document.getElementById('ret-run');
    var previewOut = document.getElementById('ret-preview-out');
    var snapListEl = document.getElementById('ret-snap-list');
    var lastRunEl = document.getElementById('ret-lastrun');
    var eventStatusEl = document.getElementById('ret-event-status');

    var fields = {
        automatic: document.getElementById('ret-automatic'),
        diagnostics_enabled: document.getElementById('ret-diag-enabled'),
        diagnostic_days: document.getElementById('ret-diag-days'),
        diagnostic_max_mb: document.getElementById('ret-diag-maxmb'),
        snapshots_enabled: document.getElementById('ret-snap-enabled'),
        snapshot_keep: document.getElementById('ret-snap-keep'),
        event_days: document.getElementById('ret-event-days')
    };

    var NUM_RULES = [
        { key: 'diagnostic_days', label: 'Debug log days', min: 1, max: 3650 },
        { key: 'diagnostic_max_mb', label: 'Debug log size target per table (MB)', min: 0, max: 102400 },
        { key: 'snapshot_keep', label: 'Automatic playthroughs to keep', min: 1, max: 100 },
        { key: 'event_days', label: 'Event preview days', min: 0, max: 3650 }
    ];

    var busy = false;
    var loaded = false;
    var preview = null; // { token, expiresMs, diagnostics, snapshots, events, message }
    var previewTimer = null;

    function asBool(v){ return v === true || v === 1 || v === '1'; }
    function numOr(v, d){ var n = parseInt(v, 10); return isFinite(n) ? n : d; }
    function fmtB(b){
        b = Number(b);
        if (!isFinite(b) || b <= 0) return '0 Bytes';
        var k = 1024, sizes = ['Bytes','KB','MB','GB','TB'];
        var i = Math.min(sizes.length - 1, Math.floor(Math.log(b) / Math.log(k)));
        return (Math.round((b / Math.pow(k, i)) * 100) / 100) + ' ' + sizes[i];
    }

    function setStatus(msg, kind){
        if (!statusEl) return;
        statusEl.textContent = msg || '';
        statusEl.className = 'retention-status' + (kind ? ' is-' + kind : '');
    }

    function previewValid(){
        return !!(preview && preview.token && (!preview.expiresMs || Date.now() < preview.expiresMs));
    }

    function syncButtons(){
        var lock = busy || !loaded;
        saveBtn.disabled = lock;
        previewBtn.disabled = lock;
        var hasWork = preview && (preview.snapshots.length || preview.diagnostics.some(function(d){ return Number(d.rows) > 0; }));
        runBtn.disabled = lock || !previewValid() || !hasWork;
        saveBtn.setAttribute('aria-disabled', String(saveBtn.disabled));
        previewBtn.setAttribute('aria-disabled', String(previewBtn.disabled));
        runBtn.setAttribute('aria-disabled', String(runBtn.disabled));
        if (snapListEl) {
            snapListEl.querySelectorAll('button').forEach(function(b){ b.disabled = lock; });
        }
        Object.keys(fields).forEach(function(k){ if (fields[k]) fields[k].disabled = lock; });
    }

    function setBusy(b, msg){
        busy = b;
        if (b && msg) setStatus(msg, 'busy');
        syncButtons();
    }

    function invalidatePreview(reason){
        if (previewTimer) { clearTimeout(previewTimer); previewTimer = null; }
        var had = !!preview;
        preview = null;
        if (had && previewOut) {
            previewOut.textContent = '';
            if (reason) {
                var n = document.createElement('p');
                n.className = 'retention-note';
                n.textContent = 'Preview cancelled (' + reason + '). Run a new preview before "Run cleanup now".';
                previewOut.appendChild(n);
            }
        }
        syncButtons();
    }

    function handleResponse(r){
        return r.json()
            .catch(function(){ throw new Error('The server returned an unreadable response (HTTP ' + r.status + ').'); })
            .then(function(j){
                if (!j || j.ok !== true) {
                    throw new Error((j && typeof j.error === 'string' && j.error) ? j.error : 'Request failed (HTTP ' + r.status + ').');
                }
                return j;
            });
    }

    function post(params){
        var body = new URLSearchParams();
        body.set('csrf_token', CSRF);
        Object.keys(params).forEach(function(k){ body.set(k, params[k]); });
        return fetch(API, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString()
        }).then(handleResponse);
    }

    function getState(){
        return fetch(API, { credentials: 'same-origin' }).then(handleResponse);
    }

    function collectSettings(){
        var out = {
            automatic: fields.automatic.checked ? '1' : '0',
            diagnostics_enabled: fields.diagnostics_enabled.checked ? '1' : '0',
            snapshots_enabled: fields.snapshots_enabled.checked ? '1' : '0'
        };
        for (var i = 0; i < NUM_RULES.length; i++) {
            var rule = NUM_RULES[i];
            var el = fields[rule.key];
            var raw = String(el.value).trim();
            var v = Number(raw);
            if (!raw || !Number.isInteger(v) || v < rule.min || v > rule.max) {
                setStatus(rule.label + ' must be a whole number between ' + rule.min + ' and ' + rule.max + '. Nothing was saved.', 'error');
                el.focus();
                return null;
            }
            out[rule.key] = String(v);
        }
        return out;
    }

    function renderLastRun(lr){
        if (!lastRunEl) return;
        if (!lr || typeof lr !== 'object') { lastRunEl.textContent = 'No cleanup has run yet.'; return; }
        var txt = lr.at ? String(lr.at) + ' — ' : '';
        txt += Number(lr.rows || 0).toLocaleString() + ' log rows and ' + Number(lr.snapshots || 0).toLocaleString() + ' playthroughs deleted';
        if (lr.message) txt += '. ' + String(lr.message);
        lastRunEl.textContent = txt;
    }

    function badge(text, cls){
        var b = document.createElement('span');
        b.className = 'retention-badge' + (cls ? ' ' + cls : '');
        b.textContent = text;
        return b;
    }

    function renderSnapshots(list){
        if (!snapListEl) return;
        snapListEl.textContent = '';
        if (!list.length) {
            var li0 = document.createElement('li');
            var sp0 = document.createElement('span');
            sp0.textContent = 'No playthroughs found.';
            li0.appendChild(sp0);
            snapListEl.appendChild(li0);
            return;
        }
        list.forEach(function(sn){
            var li = document.createElement('li');
            var left = document.createElement('span');
            var nm = document.createElement('strong');
            nm.textContent = String(sn.name || ('#' + sn.id));
            left.appendChild(nm);
            if (asBool(sn.is_active)) left.appendChild(badge('Active', 'b-active'));
            if (asBool(sn.is_default)) left.appendChild(badge('Default', 'b-default'));
            left.appendChild(asBool(sn.automatic)
                ? badge('Automatic (Dragon Break)', 'b-auto')
                : badge('Manual or older', ''));
            if (asBool(sn.pinned)) left.appendChild(badge('Protected', 'b-pinned'));
            li.appendChild(left);
            if (!asBool(sn.is_active) && !asBool(sn.is_default)) {
                var isPinned = asBool(sn.pinned);
                var btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'button btn-pin';
                btn.textContent = isPinned ? 'Unprotect' : 'Protect';
                btn.setAttribute('aria-label', (isPinned ? 'Remove protection from playthrough ' : 'Protect playthrough ') + String(sn.name || sn.id));
                btn.style.backgroundColor = isPinned ? '#333' : 'rgb(1 53 166 / 90%)';
                btn.style.color = '#fff';
                btn.addEventListener('click', function(){
                    pinSnapshot(sn.id, isPinned ? '0' : '1', String(sn.name || sn.id));
                });
                li.appendChild(btn);
            }
            snapListEl.appendChild(li);
        });
        syncButtons();
    }

    function applyState(j){
        var s = (j && j.settings && typeof j.settings === 'object') ? j.settings : {};
        fields.automatic.checked = asBool(s.automatic);
        fields.diagnostics_enabled.checked = asBool(s.diagnostics_enabled);
        fields.snapshots_enabled.checked = asBool(s.snapshots_enabled);
        fields.diagnostic_days.value = numOr(s.diagnostic_days, 7);
        fields.diagnostic_max_mb.value = numOr(s.diagnostic_max_mb, 500);
        fields.snapshot_keep.value = numOr(s.snapshot_keep, 5);
        fields.event_days.value = numOr(s.event_days, 0);
        renderLastRun(j.last_run || null);
        renderSnapshots(Array.isArray(j.snapshots) ? j.snapshots : []);
        if (eventStatusEl) {
            eventStatusEl.textContent = (typeof j.event_status === 'string' && j.event_status) ? j.event_status : '—';
        }
    }

    function refresh(showErrors){
        return getState().then(function(j){
            loaded = true;
            applyState(j);
            syncButtons();
            return j;
        }).catch(function(err){
            loaded = false;
            syncButtons();
            if (showErrors !== false) setStatus('Storage cleanup controls are unavailable: ' + err.message, 'error');
            throw err;
        });
    }

    function renderPreview(p){
        if (!previewOut) return;
        previewOut.textContent = '';
        var box = document.createElement('div');
        box.className = 'retention-preview-box';
        var h = document.createElement('h3');
        h.textContent = 'Cleanup preview (uses your saved settings)';
        box.appendChild(h);
        if (p.message) {
            var pm = document.createElement('p');
            pm.className = 'retention-note';
            pm.textContent = p.message;
            box.appendChild(pm);
        }

        var diagTitle = document.createElement('p');
        diagTitle.style.cssText = 'margin:8px 0 0 0; font-size:13px; color:#e0e0e0;';
        if (p.diagnostics.length) {
            diagTitle.textContent = 'Debug log rows that would be deleted in the next cleanup round:';
            box.appendChild(diagTitle);
            var tbl = document.createElement('table');
            tbl.className = 'retention-preview-table';
            var thead = document.createElement('thead');
            var trh = document.createElement('tr');
            ['Log table', 'Rows this round', 'Estimated size'].forEach(function(t, i){
                var th = document.createElement('th');
                th.scope = 'col';
                if (i > 0) th.className = 'num';
                th.textContent = t;
                trh.appendChild(th);
            });
            thead.appendChild(trh);
            tbl.appendChild(thead);
            var tbody = document.createElement('tbody');
            var totRows = 0, totBytes = 0;
            p.diagnostics.forEach(function(d){
                var tr = document.createElement('tr');
                var td1 = document.createElement('td'); td1.textContent = String(d.table || '');
                var td2 = document.createElement('td'); td2.className = 'num'; td2.textContent = Number(d.rows || 0).toLocaleString();
                var td3 = document.createElement('td'); td3.className = 'num'; td3.textContent = fmtB(d.bytes_estimate);
                totRows += Number(d.rows || 0);
                totBytes += Number(d.bytes_estimate || 0);
                tr.appendChild(td1); tr.appendChild(td2); tr.appendChild(td3);
                tbody.appendChild(tr);
            });
            if (p.diagnostics.length > 1) {
                var trt = document.createElement('tr');
                var tdA = document.createElement('td'); var st1 = document.createElement('strong'); st1.textContent = 'Total'; tdA.appendChild(st1);
                var tdB = document.createElement('td'); tdB.className = 'num'; var st2 = document.createElement('strong'); st2.textContent = totRows.toLocaleString(); tdB.appendChild(st2);
                var tdC = document.createElement('td'); tdC.className = 'num'; var st3 = document.createElement('strong'); st3.textContent = fmtB(totBytes); tdC.appendChild(st3);
                trt.appendChild(tdA); trt.appendChild(tdB); trt.appendChild(tdC);
                tbody.appendChild(trt);
            }
            tbl.appendChild(tbody);
            box.appendChild(tbl);
        } else {
            diagTitle.textContent = 'Debug logs: nothing to delete with your saved settings.';
            box.appendChild(diagTitle);
        }

        var snapTitle = document.createElement('p');
        snapTitle.style.cssText = 'margin:8px 0 0 0; font-size:13px; color:#e0e0e0;';
        if (p.snapshots.length) {
            snapTitle.textContent = 'Automatic playthroughs that would be deleted (' + p.snapshots.length + '):';
            box.appendChild(snapTitle);
            var ul = document.createElement('ul');
            ul.style.cssText = 'margin:4px 0; padding-left:20px; font-size:13px; color:#e0e0e0;';
            p.snapshots.forEach(function(s){
                var li = document.createElement('li');
                li.textContent = String(s.name || ('#' + s.id)) + ' (' + fmtB(s.bytes) + ')';
                ul.appendChild(li);
            });
            box.appendChild(ul);
        } else {
            snapTitle.textContent = 'Playthroughs: none would be deleted with your saved settings.';
            box.appendChild(snapTitle);
        }

        if (p.events) {
            var evWrap = document.createElement('div');
            evWrap.className = 'retention-blocked';
            var older = Number(p.events.older_rows || 0);
            var line = 'Event log: ' + older.toLocaleString() + ' events are older than your cutoff' +
                (p.events.cutoff_gamets ? ' (in-game time ' + String(p.events.cutoff_gamets) + ')' : '') +
                '. None of them will be deleted';
            line += p.events.blocked_reason
                ? ' — ' + String(p.events.blocked_reason)
                : ' — this cleanup never deletes events.';
            evWrap.textContent = p.events.cutoff_gamets === null
                ? 'Event preview is off. This cleanup never deletes events.'
                : line;
            box.appendChild(evWrap);
        }

        var note = document.createElement('p');
        note.className = 'retention-note';
        note.textContent = 'Row counts are exact for the next cleanup round. Sizes are estimates of the data itself — that space becomes reusable inside the database, but the files on disk may not shrink.';
        box.appendChild(note);

        var exp = document.createElement('p');
        exp.className = 'retention-note';
        var when = new Date(p.expiresMs);
        exp.textContent = 'This preview is good for 5 minutes (until ' + when.toLocaleTimeString() + '). Changing any setting or playthrough protection cancels it.';
        box.appendChild(exp);

        previewOut.appendChild(box);
    }

    function pinSnapshot(id, pinned, name){
        if (busy || !loaded) return;
        setBusy(true, (pinned === '1' ? 'Protecting' : 'Unprotecting') + ' playthrough "' + name + '"…');
        post({ action: 'pin', profile_id: String(id), pinned: pinned }).then(function(){
            invalidatePreview('playthrough protection changed');
            setStatus('Playthrough "' + name + '" is ' + (pinned === '1'
                ? 'now protected. Cleanup cannot delete it, and neither can you, until you unprotect it.'
                : 'no longer protected. Playthroughs you made yourself are still never deleted automatically.'), 'success');
            return refresh(false).catch(function(){});
        }).catch(function(err){
            setStatus('Updating protection failed: ' + err.message, 'error');
        }).then(function(){ setBusy(false); });
    }

    form.addEventListener('submit', function(e){
        e.preventDefault();
        if (busy || !loaded) return;
        var settings = collectSettings();
        if (!settings) return;
        settings.action = 'save';
        setBusy(true, 'Saving settings… nothing is being deleted.');
        post(settings).then(function(){
            invalidatePreview('settings saved');
            setStatus('Settings saved. Saving on its own never deletes anything.', 'success');
            return refresh(false).catch(function(){
                setStatus('Settings saved, but refreshing the panel failed. Reload the page to see the current state.', 'error');
            });
        }).catch(function(err){
            setStatus('Save failed: ' + err.message, 'error');
        }).then(function(){ setBusy(false); });
    });

    previewBtn.addEventListener('click', function(){
        if (busy || !loaded) return;
        setBusy(true, 'Building preview from saved settings… nothing is being deleted.');
        invalidatePreview('');
        post({ action: 'preview' }).then(function(j){
            var p = (j && j.preview && typeof j.preview === 'object') ? j.preview : {};
            var expiresMs = Date.parse(String(p.expires_at || ''));
            if (!isFinite(expiresMs)) expiresMs = Date.now() + 5 * 60 * 1000;
            preview = {
                token: String(p.token || ''),
                expiresMs: expiresMs,
                diagnostics: Array.isArray(p.diagnostics) ? p.diagnostics : [],
                snapshots: Array.isArray(p.snapshots) ? p.snapshots : [],
                events: (p.events && typeof p.events === 'object') ? p.events : null,
                message: (typeof p.message === 'string') ? p.message : ''
            };
            renderPreview(preview);
            if (previewTimer) clearTimeout(previewTimer);
            previewTimer = setTimeout(function(){
                invalidatePreview('it expired after 5 minutes');
                setStatus('The preview expired. Run a new preview before cleaning up.', 'error');
            }, Math.max(0, expiresMs - Date.now()));
            var hasWork = preview.snapshots.length || preview.diagnostics.some(function(d){ return Number(d.rows) > 0; });
            setStatus(preview.token
                ? (hasWork ? 'Preview ready. Review it, then use "Run cleanup now" within 5 minutes.' : 'Preview ready. Nothing needs deleting with your saved settings.')
                : 'Preview ready, but the server did not send back a confirmation code, so "Run cleanup now" stays locked.',
                preview.token ? 'success' : 'error');
        }).catch(function(err){
            setStatus('Preview failed: ' + err.message, 'error');
        }).then(function(){ setBusy(false); });
    });

    runBtn.addEventListener('click', async function(){
        if (busy || !previewValid()) return;
        var totRows = 0;
        preview.diagnostics.forEach(function(d){ totRows += Number(d.rows || 0); });
        var snapNames = preview.snapshots.map(function(s){ return String(s.name || ('#' + s.id)); });
        var msg = 'Run cleanup now?\n\n' +
            'This will permanently delete:\n' +
            '- ' + totRows.toLocaleString() + ' debug log rows (this round)\n' +
            '- ' + (snapNames.length
                ? snapNames.length + ' automatic playthrough(s): ' + snapNames.join(', ')
                : 'no playthroughs') + '\n\n' +
            'Selected playthroughs will be deleted with all their contents. Your current playthrough, including events and NPC memories, and your files will stay intact.\n\n' +
            'This cannot be undone. Choose Cancel to keep everything.';
        var token = preview.token;
        var dialog = document.getElementById('ret-confirm-dialog');
        document.getElementById('ret-confirm-body').textContent = msg;
        dialog.returnValue = 'cancel';
        setBusy(true);
        var confirmed = await new Promise(function(resolve){
            dialog.addEventListener('close', function(){ resolve(dialog.returnValue === 'run'); }, { once: true });
            dialog.showModal();
            dialog.querySelector('[value="cancel"]').focus();
        });
        setBusy(false);
        if (!confirmed) {
            setStatus('Cleanup cancelled. Nothing was deleted.', '');
            return;
        }
        if (!previewValid() || token !== preview.token) {
            setStatus('Preview changed or expired. Preview cleanup again.', 'error');
            return;
        }
        setBusy(true, 'Running cleanup…');
        post({ action: 'run', preview_token: token }).then(function(j){
            var r = (j && j.result && typeof j.result === 'object') ? j.result : {};
            invalidatePreview('cleanup ran');
            renderLastRun({ at: r.at, rows: r.rows, snapshots: r.snapshots, message: r.message });
            var txt = 'Cleanup finished: ' + Number(r.rows || 0).toLocaleString() + ' log rows and ' +
                Number(r.snapshots || 0).toLocaleString() + ' playthroughs deleted';
            if (r.message) txt += '. ' + String(r.message);
            setStatus(txt, 'success');
            return refresh(false).catch(function(){});
        }).catch(function(err){
            invalidatePreview('the cleanup attempt failed');
            setStatus('Cleanup failed: ' + err.message, 'error');
        }).then(function(){ setBusy(false); });
    });

    function maybeInvalidate(){
        if (preview) {
            invalidatePreview('settings changed');
            setStatus('Settings changed, so the preview no longer applies. Save, then preview again before running cleanup.', '');
        }
    }
    form.addEventListener('input', maybeInvalidate);
    form.addEventListener('change', maybeInvalidate);

    // Initial load: one read-only GET, no polling afterwards.
    setStatus('Loading cleanup settings…', 'busy');
    refresh(false).then(function(){
        setStatus('', '');
    }).catch(function(err){
        setStatus('Storage cleanup controls are unavailable: ' + err.message, 'error');
    });
})();
