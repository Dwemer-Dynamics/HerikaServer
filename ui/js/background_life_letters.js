(function () {
    'use strict';

    const root = document.getElementById('bgl-correspondence');
    if (!root) return;

    const form = document.getElementById('bgl-letter-form');
    const body = document.getElementById('bgl-letter-body');
    const label = document.getElementById('bgl-letter-label');
    const terms = document.getElementById('bgl-letter-terms');
    const count = document.getElementById('bgl-letter-count');
    const send = document.getElementById('bgl-letter-send');
    const cancelReply = document.getElementById('bgl-letter-cancel-reply');
    const refresh = document.getElementById('bgl-letter-refresh');
    const status = document.getElementById('bgl-letter-status');
    const thread = document.getElementById('bgl-letter-thread');
    const drafts = new Map();
    const sending = new Set();
    let npc = '';
    let generation = 0;
    let controller = null;
    let loaded = false;
    let ready = false;
    let awaitingCourier = false;
    let maxLength = 0;

    function element(tag, className, text) {
        const node = document.createElement(tag);
        node.className = className;
        node.textContent = text;
        return node;
    }

    function setStatus(message, error = false) {
        status.textContent = message;
        status.classList.toggle('bgl-letter-error', error);
    }

    function updateComposer() {
        const draft = drafts.get(npc);
        const busy = sending.has(npc);
        const length = Array.from(body.value.trim().replace(/\r\n/g, '\n')).length;
        body.disabled = !ready || busy;
        send.disabled = !ready || busy || awaitingCourier || length === 0 || length > maxLength;
        send.textContent = busy ? 'Sending...' : 'Send Letter';
        count.textContent = maxLength ? length + ' / ' + maxLength + ' characters' : '';
        label.textContent = draft && draft.replyTo ? 'Reply to: ' + draft.replyTitle : 'Write to ' + npc;
        cancelReply.hidden = !(draft && draft.replyTo);
        cancelReply.disabled = busy;
        thread.querySelectorAll('button').forEach(button => { button.disabled = !ready || busy; });
    }

    async function request(parameters, signal) {
        const response = await fetch(root.dataset.apiUrl, {
            method: 'POST', credentials: 'same-origin', cache: 'no-store',
            body: new URLSearchParams(parameters), signal
        });
        if (!response.ok) throw new Error('Letter service could not be reached.');
        const payload = await response.json();
        if (!payload.success) throw new Error(payload.error || 'Letter request failed.');
        return payload;
    }

    function renderThread(letters) {
        thread.replaceChildren();
        if (!letters.length) {
            thread.appendChild(element('div', 'bgl-recent-events-empty', 'No correspondence yet. Write the first letter above.'));
            return;
        }
        const statuses = { awaiting_courier: 'Awaiting courier', in_transit: 'In transit', delivered: 'Delivered', sent: 'Sent', read: 'Read' };
        letters.forEach(letter => {
            const incoming = letter.direction === 'to_player';
            const article = element('article', 'bgl-recent-event bgl-letter', '');
            const meta = element('div', 'bgl-recent-event-meta', '');
            meta.appendChild(element('strong', '', incoming ? 'From ' + npc : 'You → ' + npc));
            meta.appendChild(element('span', 'bgl-recent-event-category', statuses[letter.status] || letter.status));
            article.appendChild(meta);
            article.appendChild(element('strong', 'bgl-letter-title', letter.title));
            article.appendChild(element('div', 'bgl-recent-event-text', letter.body));
            const footer = element('div', 'bgl-letter-toolbar bgl-letter-footer', '');
            footer.appendChild(element('span', 'bgl-letter-hint', (letter.tamrielic_time || '') + (letter.answered ? ' · Answered' : '')));
            if (incoming) {
                const reply = element('button', 'bgl-history-button', 'Reply');
                reply.type = 'button';
                reply.addEventListener('click', function () {
                    const draft = drafts.get(npc);
                    draft.replyTo = letter.id;
                    draft.replyTitle = letter.title;
                    updateComposer();
                    body.focus();
                });
                footer.appendChild(reply);
            }
            article.appendChild(footer);
            thread.appendChild(article);
        });
    }

    // Only fetch the selected NPC's thread; ignore responses from closed or switched dialogs.
    async function loadLetters(message = '') {
        if (!npc) return;
        if (controller) controller.abort();
        controller = new AbortController();
        const current = ++generation;
        ready = false;
        refresh.disabled = true;
        setStatus('Loading letters...');
        updateComposer();
        try {
            const payload = await request({ operation: 'list', npc_name: npc }, controller.signal);
            if (current !== generation) return;
            maxLength = Number(payload.max_length);
            awaitingCourier = payload.letters.some(letter => letter.direction === 'to_npc' && letter.status === 'awaiting_courier');
            terms.textContent = payload.fee + ' gold per letter · ' + payload.delay_hours + ' in-game hours after collection. Courier progress waits while the game is inactive.';
            renderThread(payload.letters);
            ready = true;
            loaded = true;
            setStatus(message || (awaitingCourier ? 'A letter is waiting for the courier. You can send another after collection.' : 'Latest correspondence, newest first.'));
        } catch (error) {
            if (current !== generation || error.name === 'AbortError') return;
            loaded = false;
            setStatus((message ? message + ' ' : '') + 'Could not refresh letters. ' + error.message, true);
        } finally {
            if (current === generation) {
                refresh.disabled = false;
                updateComposer();
            }
        }
    }

    document.addEventListener('bgl-history-npc', function (event) {
        if (controller) controller.abort();
        generation++;
        npc = event.detail;
        if (!drafts.has(npc)) drafts.set(npc, { body: '', replyTo: 0, replyTitle: '' });
        body.value = drafts.get(npc).body;
        loaded = ready = false;
        maxLength = 0;
        terms.textContent = '';
        thread.replaceChildren();
        setStatus('');
        updateComposer();
    });
    document.addEventListener('bgl-history-tab', function (event) {
        if (event.detail === 'letters' && !loaded) loadLetters();
    });
    document.addEventListener('bgl-history-close', function () {
        generation++;
        if (controller) controller.abort();
        npc = '';
    });
    body.addEventListener('input', function () {
        drafts.get(npc).body = body.value;
        updateComposer();
    });
    cancelReply.addEventListener('click', function () {
        drafts.get(npc).replyTo = 0;
        updateComposer();
        body.focus();
    });
    refresh.addEventListener('click', () => loadLetters());
    form.addEventListener('submit', async function (event) {
        event.preventDefault();
        if (send.disabled) return;
        const recipient = npc;
        const draft = drafts.get(recipient);
        sending.add(recipient);
        updateComposer();
        setStatus('Sending letter...');
        let message;
        let failed = false;
        try {
            await request({ operation: 'send', npc_name: recipient, body: draft.body, reply_to: String(draft.replyTo) });
            drafts.set(recipient, { body: '', replyTo: 0, replyTitle: '' });
            message = 'Letter queued for the courier.';
        } catch (error) {
            failed = true;
            message = error.message + ' Your draft is kept. Check the refreshed thread before trying again.';
        } finally {
            sending.delete(recipient);
        }
        if (npc !== recipient) return;
        body.value = drafts.get(recipient).body;
        await loadLetters(message);
        if (npc === recipient && failed) setStatus(message, true);
    });
})();
