(() => {
    const dialog=document.getElementById('ptx-dialog');
    if (!dialog || dialog.dataset.bound) return;
    dialog.dataset.bound='true';
    const el=id=>document.getElementById(`ptx-${id}`);
    let job=null,csrf='',token='',preview=null,busy=false,imported=false,opener=null,poll=null;
    const endpoint=dialog.dataset.endpoint;
    async function request(action,values={}) {
        const response=await fetch(endpoint,{method:'POST',credentials:'same-origin',body:new URLSearchParams({action,csrf_token:csrf,...(job?{job}:{}),...values})});
        const data=await response.json();
        if (!response.ok || !data.ok) throw new Error(data.message || 'Transfer failed.');
        return data;
    }
    function setBusy(value) {
        busy=value;el('close').disabled=value;el('check').disabled=value || !el('file').files.length;
        el('file').disabled=value;el('name').disabled=value;
        el('profiles').querySelectorAll('select').forEach(select=>select.disabled=value);
        el('progress').hidden=!value;el('progress').removeAttribute('value');validate();
    }
    function validate() {
        el('import-confirm').disabled=busy || !preview || !el('name').value.trim() || [...el('profiles').querySelectorAll('select')].some(select=>!select.value);
    }
    function startPoll() {
        clearInterval(poll);
        poll=setInterval(async()=>{
            try { const r=await fetch(`${endpoint}?action=status&job=${encodeURIComponent(job)}`,{credentials:'same-origin',cache:'no-store',signal:AbortSignal.timeout(10000)});const result=await r.json();if (busy && result.ok) el('status').textContent=result.message; } catch (_) { /* The main request reports failures. */ }
        },1500);
    }
    function stopPoll() { clearInterval(poll);poll=null; }
    async function open(button) {
        if (busy) return;
        opener=button;job=null;preview=null;imported=false;
        el('error').textContent='';el('status').textContent='Preparing transfer…';el('file').value='';
        el('preview').hidden=true;el('import-confirm').hidden=true;el('download-link').hidden=true;
        el('profiles').replaceChildren();el('close').textContent='Close';
        const downloading=button.classList.contains('ptx-download');
        el('title').textContent=downloading?'Download a Playthrough Save':'Import a Playthrough Save';
        el('upload').hidden=downloading;dialog.showModal();setBusy(true);
        try {
            const response=await fetch(dialog.dataset.stateEndpoint,{credentials:'same-origin',cache:'no-store',signal:AbortSignal.timeout(10000)});
            const state=await response.json();if (!response.ok || !state.ok || !state.state.available) throw new Error(state.message || 'Open Manage saves to set up Playthrough Saves.');
            csrf=state.csrf_token;token=state.state.token;
            job=(await request('allocate',{kind:downloading?'export':'import'})).job;
            if (downloading) {
                startPoll();
                const result=await request('export',{profile_id:button.dataset.profileId,expected_token:token});
                const link=el('download-link');link.href=`${endpoint}?action=download&job=${encodeURIComponent(job)}`;link.download=result.filename;link.hidden=false;
                el('status').textContent='Your file is ready. Choose Download file to save it.';
                if (result.runtime_ready===false) el('error').textContent='The file is ready, but the background worker did not confirm it restarted. Check server status before playing.';
            } else el('status').textContent='Choose a file to check its contents.';
        } catch (error) { el('error').textContent=error.message;el('status').textContent=''; }
        finally { stopPoll();setBusy(false); }
    }
    document.addEventListener('click',event=>{
        const button=event.target.closest('.ptx-import,.ptx-download');
        if (button) { event.preventDefault();open(button); }
    });
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
            imported=true;preview=null;el('import-confirm').hidden=true;el('upload').hidden=true;el('preview').hidden=true;el('close').textContent='Back to saves';el('status').textContent=result.message;
            if (result.runtime_ready===false) el('error').textContent='The save was imported, but the background worker did not confirm it restarted. Check server status before playing.';
        } catch (error) { el('error').textContent=`${error.message} If the connection was interrupted, close and reload the save list to check whether the import completed.`;el('status').textContent=''; }
        finally { stopPoll();setBusy(false); }
    });
    el('close').addEventListener('click',()=>{ if (!busy) dialog.close(); });
    dialog.addEventListener('cancel',event=>{if (busy) event.preventDefault();});
    dialog.addEventListener('close',()=>{
        stopPoll();if (job) request('cancel').catch(()=>{});job=null;
        if (imported) location.reload();else opener?.focus();
    });
})();
