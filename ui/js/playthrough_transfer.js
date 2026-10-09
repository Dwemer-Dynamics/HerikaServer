(() => {
    const dialog=document.getElementById('ptx-dialog');
    if (!dialog || dialog.dataset.bound) return;
    dialog.dataset.bound='true';
    const el=id=>document.getElementById(`ptx-${id}`);
    let job=null,csrf='',token='',preview=null,busy=false,imported=false,opener=null,poll=null;
    let exporting=false,cancelling=false,downloaded=false,closeFailed=false,closeLabel='Close';
    const endpoint=dialog.dataset.endpoint;
    async function request(action,values={}) {
        const response=await fetch(endpoint,{method:'POST',credentials:'same-origin',...(action==='cancel'?{signal:AbortSignal.timeout(15000)}:{}),body:new URLSearchParams({action,csrf_token:csrf,...(job?{job}:{}),...values})});
        const data=await response.json();
        if (!response.ok || !data.ok) throw new Error(data.message || 'Transfer failed.');
        return data;
    }
    function setBusy(value) {
        // A running download can be cancelled; imports keep their existing non-cancellable flow.
        busy=value;el('close').disabled=value && (!exporting || cancelling);
        el('close').textContent=value && exporting?'Cancel download':closeLabel;
        el('check').disabled=value || !job || !el('file').files.length;
        el('file').disabled=value || !job;el('name').disabled=value;
        el('profiles').querySelectorAll('select').forEach(select=>select.disabled=value);
        el('progress').hidden=!value;el('progress').removeAttribute('value');validate();
    }
    function validate() {
        el('import-confirm').disabled=busy || !preview || !el('name').value.trim() || [...el('profiles').querySelectorAll('select')].some(select=>!select.value);
    }
    function startPoll() {
        clearInterval(poll);
        poll=setInterval(async()=>{
            const polledJob=job;
            try {
                const r=await fetch(`${endpoint}?action=status&job=${encodeURIComponent(polledJob)}`,{credentials:'same-origin',cache:'no-store',signal:AbortSignal.timeout(10000)});const result=await r.json();
                if (job===polledJob && busy && result.ok && !cancelling) {
                    el('status').textContent=result.message;
                    if (result.total>0) { el('progress').max=result.total;el('progress').value=result.step; }
                }
            } catch (_) { /* The main request reports failures. */ }
        },1500);
    }
    function stopPoll() { clearInterval(poll);poll=null; }
    // Ask the server to stop this export; the export request itself confirms when it has stopped.
    async function requestCancel() {
        if (!busy || !exporting || cancelling) return;
        const cancelJob=job;
        cancelling=true;setBusy(true);el('error').textContent='';el('status').textContent='Stopping the download…';
        try { await request('cancel',{job:cancelJob}); }
        catch (error) {
            // A late cancel response must not put a completed or newer dialog back into its busy state.
            if (job!==cancelJob || !busy || !exporting) return;
            cancelling=false;setBusy(true);el('error').textContent=`Could not stop the download: ${error.message}`;
        }
    }
    // Remove the stopped job, which also covers an export that finished while cancellation was pending.
    async function finishCancel() {
        try {
            const result=await request('cancel');
            el('error').textContent='';
            if (!result.removed) { el('status').textContent='Cancellation requested. The server is still stopping. Choose Close to check again.';return; }
            job=null;el('status').textContent='Download cancelled. Temporary files were removed.';
        }
        catch (error) { el('status').textContent='';el('error').textContent=`Could not confirm cancellation or cleanup: ${error.message} Choose Close to try again.`; }
    }
    async function closeDialog() {
        if (closeFailed) { dialog.close();return; }
        if (job) {
            el('close').disabled=true;
            try {
                const result=await request('cancel',downloaded?{after_download:'1'}:{});
                if (result.pending && !downloaded) {
                    el('close').disabled=false;el('status').textContent='Cancellation requested. The server is still stopping. Choose Close to check again.';return;
                }
                job=null;
            }
            catch (error) {
                closeFailed=true;el('close').disabled=false;
                el('error').textContent=`Could not confirm cleanup: ${error.message} Choose Close again to leave.`;return;
            }
        }
        dialog.close();
    }
    async function open(button) {
        if (busy) return;
        opener=button;job=null;preview=null;imported=false;exporting=false;cancelling=false;downloaded=false;closeFailed=false;closeLabel='Close';
        el('error').textContent='';el('status').textContent='Preparing transfer…';el('file').value='';
        el('preview').hidden=true;el('import-confirm').hidden=true;el('download-link').hidden=true;
        el('profiles').replaceChildren();
        const downloading=button.classList.contains('ptx-download');
        el('title').textContent=downloading?'Download a Playthrough Save':'Import a Playthrough Save';
        el('upload').hidden=downloading;dialog.showModal();setBusy(true);
        try {
            const response=await fetch(dialog.dataset.stateEndpoint,{credentials:'same-origin',cache:'no-store',signal:AbortSignal.timeout(10000)});
            const state=await response.json();if (!response.ok || !state.ok || !state.state.available) throw new Error(state.message || 'Open Manage saves to set up Playthrough Saves.');
            csrf=state.csrf_token;token=state.state.token;
            job=(await request('allocate',{kind:downloading?'export':'import'})).job;
            if (downloading) {
                exporting=true;setBusy(true);startPoll();
                let result=null;
                try { result=await request('export',{profile_id:button.dataset.profileId,expected_token:token}); }
                catch (error) { if (!cancelling) throw error; }
                if (cancelling) { await finishCancel();return; }
                const link=el('download-link');link.href=`${endpoint}?action=download&job=${encodeURIComponent(job)}`;link.download=result.filename;link.hidden=false;
                el('status').textContent='Your file is ready. Choose Download file to save it. The server deletes its copy after the download finishes.';
                if (result.runtime_ready===false) el('error').textContent='The file is ready, but the background worker did not confirm it restarted. Check server status before playing.';
            } else el('status').textContent='Choose a file to check its contents.';
        } catch (error) { el('error').textContent=job?error.message:`Could not prepare the transfer. ${error.message} Close this dialog and try again.`;el('status').textContent=''; }
        finally { stopPoll();exporting=false;cancelling=false;setBusy(false); }
    }
    document.addEventListener('click',event=>{
        const button=event.target.closest('.ptx-import,.ptx-download');
        if (button) { event.preventDefault();open(button); }
    });
    el('download-link').addEventListener('click',()=>{ downloaded=true;el('status').textContent='Download started. The server deletes its copy when the download finishes.'; });
    el('file').addEventListener('change',()=>{ preview=null;el('preview').hidden=true;el('import-confirm').hidden=true;validate();el('check').disabled=!el('file').files.length || !job; });
    el('name').addEventListener('input',validate);
    el('profiles').addEventListener('change',validate);
    el('check').addEventListener('click',async()=>{
        if (busy || !job || !el('file').files.length) return;
        preview=null;el('preview').hidden=true;el('error').textContent='';setBusy(true);el('status').textContent='Uploading file…';
        try {
            const data=await new Promise((resolve,reject)=>{
                const xhr=new XMLHttpRequest();xhr.open('POST',endpoint);xhr.responseType='json';
                xhr.upload.onprogress=event=>{if (event.lengthComputable) { el('progress').max=event.total;el('progress').value=event.loaded;el('status').textContent=`Uploading file: ${Math.round(event.loaded/event.total*100)}%`; }};
                xhr.upload.onload=()=>{el('progress').removeAttribute('value');el('status').textContent='Checking file…';startPoll();};
                xhr.onerror=()=>reject(new Error('The upload was interrupted. Choose Check file to try again.'));
                xhr.onload=()=>xhr.status===200 && xhr.response?.ok?resolve(xhr.response):reject(new Error(xhr.response?.message || 'The server could not accept this upload.'));
                const body=new FormData();body.set('action','inspect');body.set('csrf_token',csrf);body.set('job',job);body.set('save',el('file').files[0]);xhr.send(body);
            });
            preview=data;el('name').value=data.save.name;
            const details=el('details');details.replaceChildren();
            const add=(name,value)=>{const dt=document.createElement('dt'),dd=document.createElement('dd');dt.textContent=name;dd.textContent=value;details.append(dt,dd);};
            add('Mod',data.product);add('Game date',data.game_date || 'Not recorded');add('Created',data.save.created_at || 'Not recorded');
            const identity=data.identity || {};
            if (data.product==='STOBE') {
                const members=Array.isArray(identity.player_faction_members)?identity.player_faction_members:[];
                add('Party',members.length?`${members.slice(0,5).join(', ')}${members.length>5?` +${members.length-5} more`:''}`:'Not recorded');
            } else add('Character',`${identity.player_name || 'Not recorded'}${identity.player_level?` · Level ${identity.player_level}`:''}`);
            const profiles=el('profiles');profiles.replaceChildren();
            if (data.profiles.length) {const text=document.createElement('p');text.textContent='Use these existing profiles. Exact matches are selected for you; choose any missing matches.';profiles.append(text);}
            for (const profile of data.profiles) {
                const label=document.createElement('label'),select=document.createElement('select');select.id=`ptx-profile-${profile.id}`;select.dataset.sourceId=profile.id;
                label.htmlFor=select.id;label.textContent=profile.label;select.add(new Option('Choose an existing profile',''));
                for (const available of data.available_profiles) select.add(new Option(available.label,String(available.id)));
                select.value=String(data.profile_map[profile.id] || '');profiles.append(label,select);
            }
            el('preview').hidden=false;el('import-confirm').hidden=false;el('status').textContent='File verified. Final database checks run when you import.';
        } catch (error) { el('error').textContent=error.message;el('status').textContent=''; }
        finally { stopPoll();setBusy(false); }
    });
    el('import-confirm').addEventListener('click',async()=>{
        if (busy || !preview) return;
        setBusy(true);el('error').textContent='';el('status').textContent='Importing save…';startPoll();
        const mapping={};el('profiles').querySelectorAll('select').forEach(select=>mapping[select.dataset.sourceId]=Number(select.value));
        try {
            const result=await request('import',{name:el('name').value.trim(),profile_map:JSON.stringify(mapping),profiles_version:preview.profiles_version});
            imported=true;preview=null;el('import-confirm').hidden=true;el('upload').hidden=true;el('preview').hidden=true;closeLabel='Back to saves';el('status').textContent=result.message;
            if (result.runtime_ready===false) el('error').textContent='The save was imported, but the background worker did not confirm it restarted. Check server status before playing.';
        } catch (error) { el('error').textContent=`${error.message} If the connection was interrupted, close and reload the save list to check whether the import completed.`;el('status').textContent=''; }
        finally { stopPoll();setBusy(false); }
    });
    el('close').addEventListener('click',()=>{ if (busy) requestCancel();else closeDialog(); });
    // Escape follows the Close/Cancel button instead of dismissing a running transfer.
    dialog.addEventListener('cancel',event=>{ event.preventDefault();if (busy) requestCancel();else closeDialog(); });
    dialog.addEventListener('close',()=>{
        // Browsers may still force-dismiss a dialog; stop and release its job all the same.
        if (job) {
            if (busy && exporting) cancelling=true;
            request('cancel',downloaded?{after_download:'1'}:{}).catch(error=>console.error('Playthrough transfer cleanup failed:',error));
            if (!busy) job=null;
        }
        if (imported) location.reload();else opener?.focus();
    });
    // Closing the page cannot await a response, so hand the owned job to the server for cleanup.
    window.addEventListener('pagehide',()=>{ if (job && csrf) navigator.sendBeacon(endpoint,new URLSearchParams({action:'cancel',csrf_token:csrf,job,...(downloaded?{after_download:'1'}:{})})); });
})();
