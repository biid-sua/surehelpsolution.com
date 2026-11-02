// "Hands Full", the missed-call game (D122). The visitor fixes leaks under a sink while the phone rings.
// On their own, answering a call stops the work and ignoring it loses the customer. After a while they're
// offered Sure Help: the receptionist answers, books and transfers urgent calls while they keep working.
// Everything runs on game time (paused while the offer is open). No libraries; sounds are synthesized.

const root = document.getElementById('game');
if (root) start(JSON.parse(root.dataset.settings || '{}'));

function start(S) {
    const $ = (sel) => root.querySelector(sel);
    const R = S.receptionist || 'Ava';
    const TOTAL = S.total || 60;
    const OFFER = S.offerAfter || 20;
    const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const money = (n) => '$' + Math.round(n).toLocaleString('en-US');

    // Content: the plumbing trade. Another trade = another set of these three lists.
    const JOBS = [
        { name: 'Kitchen sink leak', value: 220, leaks: 3 },
        { name: 'Water heater hookup', value: 260, leaks: 3 },
        { name: 'Leaky shower valve', value: 240, leaks: 4 },
        { name: 'Garbage disposal swap', value: 300, leaks: 4 },
        { name: 'Burst pipe repair', value: 420, leaks: 5 },
    ];
    const SPOTS = [[300, 196], [346, 296], [392, 214], [470, 194], [180, 250], [420, 150], [300, 132], [120, 330], [490, 300]];
    const CALLS = [
        { type: 'emergency', name: 'Dana R.', tag: 'Burst pipe', value: 450, line: 'Water’s pouring through my ceiling! Can someone come now?' },
        { type: 'booking', name: 'Mr. Patel', tag: 'Water heater', value: 220, line: 'My water heater is making a noise. Can you look at it this week?' },
        { type: 'quote', name: 'Sarah K.', tag: 'New quote', value: 350, line: 'Hi! I’d like a quote for a bathroom remodel.' },
        { type: 'booking', name: 'Tom B.', tag: 'Slow drain', value: 180, line: 'Our kitchen drain is slow. Any time Thursday?' },
        { type: 'spam', name: 'Unknown', tag: 'Sales call', value: 0, line: 'Congratulations! Your business qualifies for lower card fees…' },
        { type: 'emergency', name: 'Lisa M.', tag: 'Leak', value: 380, line: 'No hot water and there’s a leak by the heater!' },
        { type: 'quote', name: 'Greg H.', tag: 'New quote', value: 320, line: 'Could you quote a new tankless water heater?' },
        { type: 'booking', name: 'Ana P.', tag: 'Toilet repair', value: 160, line: 'Our toilet keeps running. Do you have time Monday?' },
    ];
    const CHOICES = [
        { key: 'now', label: 'Come right now', fits: 'emergency' },
        { key: 'book', label: 'Book a visit', fits: ['booking', 'quote'] },
        { key: 'no', label: 'Not interested', fits: 'spam' },
    ];
    const AVA_SAYS = {
        emergency: 'I’m so sorry! Stay on the line, I’m getting the plumber for you right now.',
        booking: 'Of course. I have Thursday at 9:00 AM. I’ll book that for you.',
        quote: 'Happy to help. I’ll book a free estimate visit for Tuesday.',
        spam: 'Thanks, but we’re not interested.',
    };
    const SLOTS = ['Thu 9:00 AM', 'Tue 2:00 PM', 'Mon 10:30 AM', 'Wed 8:00 AM', 'Fri 1:00 PM'];

    // ---- sound -------------------------------------------------------------------------------------------
    let audio = null;
    let muted = false;
    try { muted = localStorage.getItem('hg.muted') === '1'; } catch (e) { /* storage blocked */ }
    const muteBtn = document.querySelector('[data-mute]');
    const showMute = () => { muteBtn?.setAttribute('aria-pressed', String(muted)); muteBtn?.setAttribute('aria-label', muted ? 'Sound off' : 'Sound on'); };
    showMute();
    muteBtn?.addEventListener('click', () => { muted = !muted; showMute(); try { localStorage.setItem('hg.muted', muted ? '1' : '0'); } catch (e) { /* ignore */ } });
    const tone = (freq, dur, type = 'sine', vol = 0.07, delay = 0) => {
        if (muted || !audio) return;
        const t0 = audio.currentTime + delay;
        const o = audio.createOscillator(); const g = audio.createGain();
        o.type = type; o.frequency.value = freq;
        g.gain.setValueAtTime(0, t0); g.gain.linearRampToValueAtTime(vol, t0 + 0.01); g.gain.exponentialRampToValueAtTime(0.0001, t0 + dur);
        o.connect(g).connect(audio.destination); o.start(t0); o.stop(t0 + dur + 0.02);
    };
    const sfx = {
        ring: () => { [0, 0.12, 0.24, 0.36].forEach((d) => { tone(440, 0.1, 'sine', 0.05, d); tone(480, 0.1, 'sine', 0.05, d); }); },
        coin: () => { tone(988, 0.08, 'square', 0.04); tone(1319, 0.18, 'square', 0.04, 0.07); },
        bad: () => { tone(196, 0.25, 'sawtooth', 0.04); },
        fix: () => { tone(660, 0.06, 'triangle', 0.06); },
    };

    // ---- elements ----------------------------------------------------------------------------------------
    const el = {
        screens: root.querySelectorAll('[data-screen]'), earned: $('[data-earned]'), lost: $('[data-lost]'), missed: $('[data-missed]'),
        mode: $('[data-mode]'), time: $('[data-time]'), timebar: $('[data-timebar]'), jobName: $('[data-job-name]'), jobValue: $('[data-job-value]'),
        jobBar: $('[data-job-bar]'), leaks: $('[data-leaks]'), puddle: $('[data-puddle]'), busy: $('[data-busy]'), toasts: $('[data-toasts]'),
        phone: $('[data-phone]'), idle: $('[data-phone-idle]'), call: $('[data-call]'), ring: $('[data-ring]'), initials: $('[data-caller-initials]'),
        cname: $('[data-caller-name]'), ctag: $('[data-caller-tag]'), cline: $('[data-caller-line]'), cbtns: $('[data-call-btns]'), choices: $('[data-choices]'),
        ava: $('[data-ava]'), avaStatus: $('[data-ava-status]'), talk: $('[data-ava-talk]'), urgent: $('[data-urgent]'), urgentText: $('[data-urgent-text]'), feed: $('[data-feed]'),
        offer: $('[data-offer]'), offerText: $('[data-offer-text]'),
    };
    const show = (name) => el.screens.forEach((s) => s.classList.toggle('is-active', s.dataset.screen === name));

    // ---- state -------------------------------------------------------------------------------------------
    let G;
    const fresh = () => ({
        t: 0, running: false, mode: 'solo', offers: 0, earned: 0, lost: 0, missed: 0, calls: 0,
        seg: { solo: { earned: 0, lost: 0, missed: 0, jobs: 0, time: 0, calls: 0 }, help: { earned: 0, lost: 0, missed: 0, jobs: 0, time: 0, calls: 0 } },
        events: [], jobIdx: 0, job: null, water: 0, call: null, queue: [], avaBusy: false, urgent: null, callIdx: 0, slot: 0, nextCallAt: 2.5,
    });
    const at = (delay, fn) => G.events.push({ at: G.t + delay, fn });
    const seg = () => G.seg[G.mode];

    function bump(node) { const s = node.closest('.hg-stat'); s?.classList.remove('bump'); void s?.offsetWidth; s?.classList.add('bump'); }
    function earn(n, why) {
        if (n <= 0) return;
        G.earned += n; seg().earned += n; el.earned.textContent = money(G.earned); bump(el.earned); sfx.coin();
        if (why) toast(`${why} +${money(n)}`, 'g');
    }
    function lose(n, why) {
        if (n <= 0) return;
        G.lost += n; seg().lost += n; el.lost.textContent = money(G.lost); bump(el.lost); sfx.bad();
        if (why) toast(`${why} −${money(n)}`, 'r');
    }
    function missCall(c, why) {
        if (c.type === 'spam') { toast('Missed a sales call. No loss.'); return; }
        G.missed++; seg().missed++; el.missed.textContent = G.missed; bump(el.missed);
        lose(c.value, why || `${c.name} called the next plumber`);
    }
    function toast(text, kind = '') {
        const n = document.createElement('div'); n.className = `hg-toast ${kind}`; n.textContent = text;
        el.toasts.append(n); setTimeout(() => n.remove(), 2500);
        while (el.toasts.children.length > 3) el.toasts.firstChild.remove();
    }

    // ---- the job: fix the leaks ---------------------------------------------------------------------------
    function newJob() {
        const base = JOBS[G.jobIdx % JOBS.length]; G.jobIdx++;
        G.job = { ...base, current: base.value, spawned: 0, fixed: 0 };
        el.leaks.innerHTML = ''; G.water = Math.max(0, G.water - 30);
        el.jobName.textContent = base.name; el.jobValue.textContent = money(base.value); el.jobBar.style.width = '0%';
        at(0.4, spawnLeak);
    }
    function spawnLeak() {
        const j = G.job; if (!j || j.spawned >= j.leaks) return;
        const used = [...el.leaks.children].map((g) => g.dataset.spot);
        const free = SPOTS.map((_, i) => String(i)).filter((i) => !used.includes(i));
        const i = free[Math.floor(Math.random() * free.length)] ?? '0';
        const [x, y] = SPOTS[+i];
        const ns = 'http://www.w3.org/2000/svg';
        const g = document.createElementNS(ns, 'g');
        g.setAttribute('class', 'hg-leak'); g.setAttribute('tabindex', '0'); g.setAttribute('role', 'button'); g.setAttribute('aria-label', 'Fix this leak');
        g.dataset.spot = i;
        g.innerHTML = `<circle class="hit" cx="${x}" cy="${y}" r="36"/><circle class="ring" cx="${x}" cy="${y}" r="17"/><path class="drop" d="M${x} ${y + 14} q-5 8 0 12 q5 -4 0 -12z"/><path class="drop d2" d="M${x + 6} ${y + 12} q-4 7 0 10 q4 -3 0 -10z"/>`;
        const fix = (e) => { e.preventDefault(); fixLeak(g); };
        g.addEventListener('pointerdown', fix);
        g.addEventListener('keydown', (e) => { if (e.key === 'Enter' || e.key === ' ') fix(e); });
        el.leaks.append(g); j.spawned++;
        if (j.spawned < j.leaks) at(1.0 + Math.random() * 0.6, spawnLeak);
    }
    function fixLeak(g) {
        if (!G.running || g.classList.contains('fixed') || (G.call && G.call.state === 'talk')) return;
        g.classList.add('fixed'); sfx.fix(); G.job.fixed++;
        el.jobBar.style.width = `${(G.job.fixed / G.job.leaks) * 100}%`;
        if (G.job.fixed >= G.job.leaks) {
            const j = G.job; G.job = null; seg().jobs++;
            earn(j.current, `${j.name} done`);
            at(0.8, newJob);
        }
    }
    function activeLeaks() { return el.leaks.querySelectorAll('.hg-leak:not(.fixed)').length; }

    // ---- the phone, on your own -------------------------------------------------------------------------
    function nextCaller() { const c = { ...CALLS[G.callIdx % CALLS.length] }; G.callIdx++; return c; }
    function incoming() {
        const c = nextCaller(); G.calls++; seg().calls++;
        if (G.mode === 'help') { G.queue.push(c); avaNext(); return; }
        if (G.call) { missCall(c, `Call waiting: ${c.name} hung up`); return; }
        G.call = { ...c, state: 'ring', until: G.t + 5 };
        el.idle.hidden = true; el.call.hidden = false; el.call.classList.add('ringing');
        el.initials.textContent = c.name.split(/\s+/).map((w) => w[0]).join('').slice(0, 2).toUpperCase();
        el.cname.textContent = c.name; el.ctag.textContent = c.tag; el.ctag.classList.toggle('urgent', c.type === 'emergency');
        el.cline.hidden = true; el.cbtns.hidden = false; el.choices.hidden = true;
        sfx.ring();
    }
    function endCall() {
        G.call = null; el.call.hidden = true; el.idle.hidden = false; el.call.classList.remove('ringing'); el.busy.hidden = true;
    }
    function answer() {
        const c = G.call; if (!c || c.state !== 'ring' || !G.running) return;
        c.state = 'talk'; c.until = G.t + 7; el.call.classList.remove('ringing');
        el.cbtns.hidden = true; el.busy.hidden = false; el.cline.hidden = false; el.cline.textContent = `“${c.line}”`;
        el.choices.innerHTML = '';
        CHOICES.forEach((ch, i) => {
            const b = document.createElement('button'); b.type = 'button'; b.textContent = `${i + 1}. ${ch.label}`;
            b.addEventListener('click', () => choose(ch)); el.choices.append(b);
        });
        el.choices.hidden = false; el.choices.querySelector('button')?.focus();
    }
    function choose(ch) {
        const c = G.call; if (!c || c.state !== 'talk' || !G.running) return;
        const right = [].concat(ch.fits).includes(c.type);
        if (c.type === 'spam') { right ? toast('Sales call off the line. Back to work.') : toast('That was a sales call. Time wasted.', 'r'); }
        else if (right) { earn(c.value, `${c.type === 'emergency' ? 'Emergency booked' : 'Booked'}: ${c.name}`); }
        else { lose(c.value, `${c.name} wasn’t happy`); }
        endCall();
    }
    function decline() {
        const c = G.call; if (!c || c.state !== 'ring') return;
        endCall(); missCall(c);
    }

    // ---- the receptionist, with Sure Help ---------------------------------------------------------------
    function bubble(text, who) {
        const b = document.createElement('div'); b.className = `hg-bubble ${who}`; b.textContent = text;
        el.talk.append(b); while (el.talk.children.length > 2) el.talk.firstChild.remove();
    }
    function feed(text, amount) {
        const li = document.createElement('li'); li.innerHTML = '<span></span><b></b>';
        li.firstChild.textContent = text; li.lastChild.textContent = amount ? '+' + money(amount) : '';
        el.feed.prepend(li); while (el.feed.children.length > 6) el.feed.lastChild.remove();
    }
    function avaNext() {
        if (G.avaBusy || !G.queue.length) return;
        const c = G.queue.shift(); G.avaBusy = true;
        el.ava.classList.add('talking'); el.avaStatus.textContent = `On a call with ${c.name}`;
        el.talk.innerHTML = ''; bubble(c.line, 'caller');
        at(0.9, () => bubble(AVA_SAYS[c.type], 'ava'));
        at(2.4, () => {
            el.ava.classList.remove('talking'); G.avaBusy = false;
            el.avaStatus.textContent = G.queue.length ? 'Next call…' : 'Ready for your calls';
            const slot = SLOTS[G.slot++ % SLOTS.length];
            if (c.type === 'spam') feed('Sales call screened out', 0);
            else if (c.type === 'emergency') urgent(c);
            else { earn(c.value); feed(`${c.type === 'quote' ? 'Estimate' : 'Booked'}: ${c.name}, ${slot}`, c.value); }
            avaNext();
        });
    }
    function urgent(c) {
        G.urgent = { ...c, until: G.t + 3.2 };
        el.urgentText.textContent = `Urgent: ${c.tag.toLowerCase()} (${c.name}). Take it?`; el.urgent.hidden = false; sfx.ring();
    }
    function takeUrgent() {
        const c = G.urgent; if (!c || !G.running) return;
        G.urgent = null; el.urgent.hidden = true;
        const v = Math.round(c.value * 1.15);
        earn(v, `${R} put ${c.name} through to you`); feed(`Transferred to you: ${c.name}`, v);
    }
    function urgentTimeout() {
        const c = G.urgent; G.urgent = null; el.urgent.hidden = true;
        earn(c.value); feed(`Texted you, booked first slot: ${c.name}`, c.value);
    }
    function switchToHelp() {
        G.mode = 'help';
        el.mode.textContent = `With ${R}`; el.mode.classList.add('help');
        el.phone.hidden = true; el.ava.hidden = false; el.feed.innerHTML = ''; el.talk.innerHTML = '';
        const c = G.call; endCall();
        if (c) { G.queue.push(c); }
        toast(`${R} is answering your calls now`, 'g');
        avaNext();
    }

    // ---- the offer ----------------------------------------------------------------------------------------
    function offer() {
        G.running = false; G.offers++;
        el.offerText.textContent = `In ${Math.round(G.t)} seconds your phone rang ${G.calls} times. You missed ${G.missed} ${G.missed === 1 ? 'call' : 'calls'} and lost ${money(G.lost)} of work.`;
        el.offer.hidden = false; el.offer.querySelector('[data-accept]')?.focus();
    }
    root.querySelector('[data-accept]')?.addEventListener('click', () => { el.offer.hidden = true; switchToHelp(); resume(); });
    root.querySelector('[data-decline-offer]')?.addEventListener('click', () => { el.offer.hidden = true; toast('Back to it. The phone’s still ringing…'); resume(); });

    // ---- loop ---------------------------------------------------------------------------------------------
    let last = 0;
    function resume() { G.running = true; last = performance.now(); requestAnimationFrame(frame); }
    function frame(now) {
        if (!G.running) return;
        const dt = Math.min(0.1, (now - last) / 1000); last = now;
        G.t += dt; seg().time += dt;

        // scheduled events
        const due = G.events.filter((e) => e.at <= G.t); G.events = G.events.filter((e) => e.at > G.t);
        due.forEach((e) => e.fn());

        // calls arrive
        if (G.t >= G.nextCallAt) { incoming(); G.nextCallAt = G.t + 3.2 + Math.random() * 2.2 - Math.min(1, G.t / 60); }
        const c = G.call;
        if (c) {
            const left = Math.max(0, c.until - G.t);
            if (c.state === 'ring') {
                el.ring.style.strokeDashoffset = String(125.7 * (1 - left / 5));
                if (Math.floor(left * 1.0) !== Math.floor((left + dt) * 1.0)) sfx.ring();
                if (left <= 0) { endCall(); missCall(c); }
            } else if (left <= 0) { endCall(); lose(c.value, `${c.name} gave up waiting`); }
        }
        if (G.urgent && G.t >= G.urgent.until) urgentTimeout();

        // water from leaks left open
        const open = activeLeaks();
        G.water = Math.min(100, G.water + open * dt * 7);
        if (G.water >= 100 && G.job) {
            const hit = Math.round(G.job.value * 0.2);
            if (G.job.current - hit >= G.job.value * 0.4) { G.job.current -= hit; el.jobValue.textContent = money(G.job.current); lose(hit, 'Water damage'); }
            G.water = 45;
        }
        el.puddle.setAttribute('rx', String(G.water * 2.4)); el.puddle.setAttribute('ry', String(G.water * 0.22));

        // clock and offer
        const left = Math.max(0, TOTAL - G.t);
        el.time.textContent = Math.ceil(left); el.timebar.style.width = `${(left / TOTAL) * 100}%`;
        if (G.mode === 'solo' && ((G.offers === 0 && G.t >= OFFER) || (G.offers === 1 && G.t >= OFFER * 2))) { offer(); return; }
        if (left <= 0) { finish(); return; }
        requestAnimationFrame(frame);
    }

    // ---- start, finish, again -----------------------------------------------------------------------------
    function begin() {
        if (!audio) { try { audio = new (window.AudioContext || window.webkitAudioContext)(); } catch (e) { audio = null; } }
        G = fresh();
        el.earned.textContent = '$0'; el.lost.textContent = '$0'; el.missed.textContent = '0';
        el.mode.textContent = 'On your own'; el.mode.classList.remove('help');
        el.phone.hidden = false; el.ava.hidden = true; el.urgent.hidden = true; el.offer.hidden = true; el.toasts.innerHTML = '';
        endCall(); G.water = 0; newJob(); show('play'); resume();
    }
    function perMin(v, s) { return s > 1 ? (v / s) * 60 : 0; }
    function finish() {
        G.running = false; endCall(); el.urgent.hidden = true;
        const s = G.seg.solo; const h = G.seg.help; const used = h.time > 1;
        const set = (k, v) => { const n = root.querySelector(`[data-r="${k}"]`); if (n) n.textContent = v; };
        set('solo-earn', money(perMin(s.earned, s.time))); set('solo-lost', money(perMin(s.lost, s.time))); set('solo-missed', s.missed); set('solo-jobs', s.jobs);
        set('help-earn', money(perMin(h.earned, h.time))); set('help-lost', money(perMin(h.lost, h.time))); set('help-calls', h.calls); set('help-jobs', h.jobs);
        root.querySelector('[data-res-help]').hidden = !used;
        const title = root.querySelector('[data-res-title]'); const lead = root.querySelector('[data-res-lead]');
        if (used) {
            const gain = perMin(h.earned, h.time) - perMin(s.earned, s.time);
            title.textContent = gain > 0 ? `With ${S.brand} you earned ${money(gain)} more a minute` : `${R} took ${h.calls} calls so you could keep working`;
            lead.textContent = `On your own you lost ${money(perMin(s.lost, s.time))} a minute to missed calls and water damage. With ${R} answering, you lost ${money(perMin(h.lost, h.time))}. Every caller got an answer, and you kept your hands on the job.`;
        } else {
            title.textContent = `You lost ${money(G.lost)} of work to the phone`;
            lead.textContent = `You missed ${G.missed} ${G.missed === 1 ? 'call' : 'calls'} in one minute. Play again and let ${R} answer to see the difference.`;
        }
        show('results'); title.focus?.();
    }
    root.querySelector('[data-start]')?.addEventListener('click', begin);
    root.querySelector('[data-again]')?.addEventListener('click', begin);
    root.querySelector('[data-answer]')?.addEventListener('click', answer);
    root.querySelector('[data-decline]')?.addEventListener('click', decline);
    root.querySelector('[data-take]')?.addEventListener('click', takeUrgent);
    root.querySelector('[data-share]')?.addEventListener('click', async () => {
        const data = { title: 'Hands Full: the missed-call game', text: 'How much work would you lose to the phone? Try it.', url: S.share };
        try {
            if (navigator.share) await navigator.share(data);
            else { await navigator.clipboard.writeText(S.share); toastResult('Link copied'); }
        } catch (e) { /* cancelled */ }
    });
    function toastResult(text) { const b = root.querySelector('[data-share]'); const old = b.textContent; b.textContent = text; setTimeout(() => { b.textContent = old; }, 1800); }

    document.addEventListener('keydown', (e) => {
        if (!G?.running || e.target.closest?.('input, textarea')) return;
        const k = e.key.toLowerCase();
        if (k === 'a') answer();
        else if (k === 'd') decline();
        else if (k === 't') takeUrgent();
        else if (['1', '2', '3'].includes(k) && G.call?.state === 'talk') choose(CHOICES[+k - 1]);
    });
    if (reduced) root.classList.add('reduced');
}
