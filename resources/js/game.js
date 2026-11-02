// "Hands Full", the missed-call game (D122). The visitor fixes leaks under a sink while the phone rings.
// On their own, answering a call stops the work and ignoring it loses the customer. After a while they're
// offered Sure Help: the receptionist answers, books and transfers urgent calls while they keep working.
// Game logic runs on game time (paused while the offer is open); effects run on a canvas layer.
// No libraries: the sounds and the music are synthesized with the Web Audio API.

const root = document.getElementById('game');
if (root) start(JSON.parse(root.dataset.settings || '{}'));

function start(S) {
    const $ = (sel) => root.querySelector(sel);
    const R = S.receptionist || 'Ava';
    const TOTAL = S.total || 60;
    const OFFER = S.offerAfter || 20;
    const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const money = (n) => '$' + Math.round(n).toLocaleString('en-US');
    const rand = (a, b) => a + Math.random() * (b - a);

    // ---- content: the plumbing trade ----------------------------------------------------------------------
    const JOBS = [
        { name: 'Kitchen sink leak', value: 220, leaks: 3 },
        { name: 'Water heater hookup', value: 260, leaks: 3 },
        { name: 'Leaky shower valve', value: 240, leaks: 4 },
        { name: 'Garbage disposal swap', value: 300, leaks: 4 },
        { name: 'Burst pipe repair', value: 420, leaks: 5 },
    ];
    // joints on the drawing (viewBox 800 × 500)
    const SPOTS = [[390, 176], [390, 230], [494, 234], [442, 318], [710, 212], [300, 120], [500, 120], [210, 404], [590, 404], [260, 270], [548, 270]];
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
    const AVATARS = ['linear-gradient(135deg,#ff9a62,#ff4d6d)', 'linear-gradient(135deg,#7c6cff,#4fc3ff)', 'linear-gradient(135deg,#ffd166,#ff8f3f)', 'linear-gradient(135deg,#57ff8d,#1fb5a8)', 'linear-gradient(135deg,#8b93a7,#4b5368)'];
    const CHOICES = [
        { label: 'Come right now', fits: ['emergency'] },
        { label: 'Book a visit', fits: ['booking', 'quote'] },
        { label: 'Not interested', fits: ['spam'] },
    ];
    const AVA_SAYS = {
        emergency: 'I’m so sorry! Stay with me, I’m getting the plumber on the line right now.',
        booking: 'Of course. I have Thursday at 9:00 AM. I’ll book that for you.',
        quote: 'Happy to help. I’ll book a free estimate visit for Tuesday.',
        spam: 'Thanks, but we’re not interested.',
    };
    const SLOTS = ['Thu 9:00 AM', 'Tue 2:00 PM', 'Mon 10:30 AM', 'Wed 8:00 AM', 'Fri 1:00 PM'];

    // ---- sound: effects and a little music loop ----------------------------------------------------------------
    let audio = null; let master = null; let muted = false;
    try { muted = localStorage.getItem('hg.muted') === '1'; } catch (e) { /* storage blocked */ }
    const muteBtn = document.querySelector('[data-mute]');
    const showMute = () => { muteBtn?.setAttribute('aria-pressed', String(muted)); muteBtn?.setAttribute('aria-label', muted ? 'Sound off' : 'Sound on'); if (master) master.gain.value = muted ? 0 : 1; };
    muteBtn?.addEventListener('click', () => { muted = !muted; showMute(); try { localStorage.setItem('hg.muted', muted ? '1' : '0'); } catch (e) { /* ignore */ } });
    showMute();
    function initAudio() {
        if (audio) return;
        try { audio = new (window.AudioContext || window.webkitAudioContext)(); master = audio.createGain(); master.connect(audio.destination); showMute(); } catch (e) { audio = null; }
    }
    function tone(freq, dur, type = 'sine', vol = 0.06, delay = 0, slide = 0) {
        if (!audio) return;
        const t0 = audio.currentTime + delay;
        const o = audio.createOscillator(); const g = audio.createGain();
        o.type = type; o.frequency.setValueAtTime(freq, t0);
        if (slide) o.frequency.exponentialRampToValueAtTime(Math.max(40, freq * slide), t0 + dur);
        g.gain.setValueAtTime(0, t0); g.gain.linearRampToValueAtTime(vol, t0 + 0.01); g.gain.exponentialRampToValueAtTime(0.0001, t0 + dur);
        o.connect(g).connect(master); o.start(t0); o.stop(t0 + dur + 0.02);
    }
    const sfx = {
        ring: () => { [0, 0.13, 0.26, 0.39].forEach((d) => { tone(440, 0.11, 'sine', 0.05, d); tone(480, 0.11, 'sine', 0.05, d); }); },
        coin: () => { tone(988, 0.07, 'square', 0.035); tone(1319, 0.2, 'square', 0.035, 0.07); },
        bad: () => { tone(220, 0.35, 'sawtooth', 0.045, 0, 0.5); },
        fix: (n = 1) => { tone(520 + n * 90, 0.08, 'triangle', 0.07); tone(780 + n * 120, 0.12, 'triangle', 0.05, 0.05); },
        pop: () => { tone(300, 0.12, 'sine', 0.08, 0, 2.2); },
        beep: (hi) => { tone(hi ? 1046 : 523, hi ? 0.4 : 0.15, 'square', 0.05); },
        tick: () => { tone(1500, 0.03, 'square', 0.025); },
        whoosh: () => { tone(200, 0.4, 'sawtooth', 0.025, 0, 4); },
        fanfare: () => { [523, 659, 784, 1046].forEach((f, i) => tone(f, 0.25, 'square', 0.04, i * 0.12)); },
        splash: () => { tone(900, 0.15, 'sine', 0.02, 0, 0.3); },
    };
    let musicTimer = null; let beat = 0; let nextNote = 0;
    const BASS = [110, 110, 146.8, 130.8]; const ARP = [0, 4, 7, 12, 7, 4];
    function music(on) {
        clearInterval(musicTimer); musicTimer = null;
        if (!on || !audio) return;
        beat = 0; nextNote = audio.currentTime + 0.05;
        musicTimer = setInterval(() => {
            while (nextNote < audio.currentTime + 0.2) {
                const bar = Math.floor(beat / 8) % 4; const step = 60 / 128 / 2;
                const t = nextNote - audio.currentTime;
                if (beat % 2 === 0) tone(BASS[bar], 0.22, 'triangle', 0.05, t);
                const helping = G?.mode === 'help';
                const n = BASS[bar] * 4 * Math.pow(2, ARP[beat % ARP.length] / 12);
                if (beat % 2 === 1 || helping) tone(n, 0.09, 'square', helping ? 0.012 : 0.009, t);
                if (beat % 4 === 0) tone(60, 0.08, 'sine', 0.08, t, 0.5);
                nextNote += step; beat++;
            }
        }, 50);
    }

    // ---- effects canvas ------------------------------------------------------------------------------------
    const fx = document.querySelector('[data-fx]'); const ctx = fx?.getContext('2d');
    const parts = []; let fxRun = false;
    function sizeFx() { if (!fx) return; const d = Math.min(2, window.devicePixelRatio || 1); fx.width = innerWidth * d; fx.height = innerHeight * d; ctx.setTransform(d, 0, 0, d, 0, 0); }
    sizeFx(); addEventListener('resize', sizeFx);
    function emit(p) { if (!ctx || reduced && p.kind !== 'coin') return; parts.push(p); if (!fxRun) { fxRun = true; requestAnimationFrame(drawFx); } }
    function drawFx() {
        ctx.clearRect(0, 0, innerWidth, innerHeight);
        for (let i = parts.length - 1; i >= 0; i--) {
            const p = parts[i]; p.life -= 1 / 60;
            if (p.life <= 0) { parts.splice(i, 1); if (p.done) p.done(); continue; }
            if (p.kind === 'coin') {
                const k = 1 - p.life / p.max; const e = k * k * (3 - 2 * k);
                const x = (1 - e) * (1 - e) * p.x + 2 * (1 - e) * e * p.cx + e * e * p.tx;
                const y = (1 - e) * (1 - e) * p.y + 2 * (1 - e) * e * p.cy + e * e * p.ty;
                ctx.save(); ctx.translate(x, y); ctx.scale(Math.abs(Math.cos(k * 12)) * 0.8 + 0.2, 1);
                ctx.beginPath(); ctx.arc(0, 0, p.r, 0, Math.PI * 2); ctx.fillStyle = '#ffd166'; ctx.fill(); ctx.lineWidth = 2.5; ctx.strokeStyle = '#d99a00'; ctx.stroke();
                ctx.restore(); continue;
            }
            p.vx *= p.drag ?? 0.99; p.vy += p.g ?? 0; p.vy *= p.drag ?? 0.99; p.x += p.vx; p.y += p.vy; if (p.rot !== undefined) p.rot += p.vr;
            ctx.globalAlpha = Math.max(0, Math.min(1, p.life / p.max * 1.5));
            ctx.fillStyle = p.c;
            if (p.kind === 'drop') { ctx.beginPath(); ctx.ellipse(p.x, p.y, p.r * 0.7, p.r * 1.3, Math.atan2(p.vy, p.vx) + Math.PI / 2, 0, Math.PI * 2); ctx.fill(); }
            else if (p.kind === 'spark') { ctx.strokeStyle = p.c; ctx.lineWidth = p.r; ctx.lineCap = 'round'; ctx.beginPath(); ctx.moveTo(p.x, p.y); ctx.lineTo(p.x - p.vx * 3, p.y - p.vy * 3); ctx.stroke(); }
            else if (p.kind === 'conf') { ctx.save(); ctx.translate(p.x, p.y); ctx.rotate(p.rot); ctx.fillRect(-p.r, -p.r / 2, p.r * 2, p.r); ctx.restore(); }
            else { ctx.beginPath(); ctx.arc(p.x, p.y, p.r, 0, Math.PI * 2); ctx.fill(); }
        }
        ctx.globalAlpha = 1;
        if (parts.length) requestAnimationFrame(drawFx); else { fxRun = false; ctx.clearRect(0, 0, innerWidth, innerHeight); }
    }
    const COLORS = ['#57ff8d', '#ffd166', '#6d5efc', '#4fc3ff', '#ff4d6d', '#ffffff'];
    function sparks(x, y, n = 18, colors = ['#57ff8d', '#ffd166', '#ffffff']) {
        for (let i = 0; i < n; i++) { const a = rand(0, Math.PI * 2); const s = rand(2, 7); emit({ kind: 'spark', x, y, vx: Math.cos(a) * s, vy: Math.sin(a) * s, g: 0.12, r: rand(2, 3.5), c: colors[i % colors.length], life: rand(0.35, 0.7), max: 0.7, drag: 0.94 }); }
    }
    function coins(fromX, fromY, toEl, n = 6, done) {
        const r = toEl.getBoundingClientRect(); const tx = r.left + 22; const ty = r.top + r.height / 2;
        for (let i = 0; i < n; i++) {
            const life = rand(0.6, 0.85);
            emit({ kind: 'coin', x: fromX + rand(-20, 20), y: fromY + rand(-14, 14), cx: (fromX + tx) / 2 + rand(-120, 120), cy: Math.min(fromY, ty) - rand(60, 200), tx, ty, r: rand(7, 10), life, max: life, done: i === 0 ? done : null });
        }
    }
    function confetti() {
        for (let i = 0; i < 140; i++) emit({ kind: 'conf', x: rand(0, innerWidth), y: rand(-200, -10), vx: rand(-1.5, 1.5), vy: rand(2, 5), g: 0.03, drag: 0.995, r: rand(4, 8), rot: rand(0, 6), vr: rand(-0.2, 0.2), c: COLORS[i % COLORS.length], life: rand(2.5, 4), max: 4 });
    }

    // ---- elements ------------------------------------------------------------------------------------------
    const el = {
        screens: root.querySelectorAll('[data-screen]'), earned: $('[data-earned]'), lost: $('[data-lost]'), missed: $('[data-missed]'),
        combo: $('[data-combo]'), comboVal: $('[data-combo-val]'), comboBar: $('[data-combo-bar]'),
        mode: $('[data-mode]'), time: $('[data-time]'), timerRing: $('[data-timer-ring]'), jobName: $('[data-job-name]'), jobValue: $('[data-job-value]'),
        jobBar: $('[data-job-bar]'), svg: $('[data-svg]'), leaks: $('[data-leaks]'), wrenches: $('[data-wrenches]'), water: $('[data-water]'), waterHl: $('[data-water-hl]'),
        flood: $('[data-flood]'), busy: $('[data-busy]'), toasts: $('[data-toasts]'), stage: $('[data-stage]'), pops: $('[data-pops]'), vignette: $('[data-vignette]'),
        countdown: $('[data-countdown]'), count: $('[data-count]'),
        phone: $('[data-phone]'), idle: $('[data-phone-idle]'), call: $('[data-call]'), callLabel: $('[data-call-label]'), ring: $('[data-ring]'), avatar: $('[data-caller-avatar]'),
        initials: $('[data-caller-initials]'), cname: $('[data-caller-name]'), ctag: $('[data-caller-tag]'), cvalue: $('[data-caller-value]'), cline: $('[data-caller-line]'),
        cbtns: $('[data-call-btns]'), choices: $('[data-choices]'),
        ava: $('[data-ava]'), avaStatus: $('[data-ava-status]'), avaCount: $('[data-ava-count]'), typing: $('[data-typing]'), talk: $('[data-ava-talk]'),
        urgent: $('[data-urgent]'), urgentText: $('[data-urgent-text]'), urgentBar: $('[data-urgent-bar]'), feed: $('[data-feed]'),
        offer: $('[data-offer]'), offerStats: $('[data-offer-stats]'),
    };
    const show = (name) => el.screens.forEach((s) => s.classList.toggle('is-active', s.dataset.screen === name));
    const timerLen = 125.7;
    let best = 0;
    try { best = +(localStorage.getItem('hg.best') || 0); } catch (e) { /* ignore */ }
    const showBest = () => { const b = $('[data-best]'); if (b && best > 0) { b.hidden = false; $('[data-best-val]').textContent = money(best); } };
    showBest();

    // ---- state ---------------------------------------------------------------------------------------------
    let G;
    const fresh = () => ({
        t: 0, running: false, mode: 'solo', offers: 0, earned: 0, lost: 0, missed: 0, calls: 0, combo: 1, bestCombo: 1, lastFix: -9, lastSec: TOTAL,
        seg: { solo: { earned: 0, lost: 0, missed: 0, jobs: 0, time: 0, calls: 0 }, help: { earned: 0, lost: 0, missed: 0, jobs: 0, time: 0, calls: 0 } },
        events: [], jobIdx: 0, job: null, water: 0, call: null, queue: [], avaBusy: false, avaCalls: 0, urgent: null, callIdx: 0, slot: 0, nextCallAt: 3.2, spray: 0,
    });
    const at = (delay, fn) => G.events.push({ at: G.t + delay, fn });
    const seg = () => G.seg[G.mode];

    function svgPoint(x, y) { const m = el.svg.getScreenCTM(); if (!m) return { x: 0, y: 0 }; return { x: m.a * x + m.c * y + m.e, y: m.b * x + m.d * y + m.f }; }
    function centerOf(node) { const r = node.getBoundingClientRect(); return { x: r.left + r.width / 2, y: r.top + r.height / 2 }; }
    function pop(text, x, y, kind = 'g', big = false) {
        const host = root.querySelector('.hg-play').getBoundingClientRect();
        const n = document.createElement('div'); n.className = `hg-pop ${kind}${big ? ' big' : ''}`; n.textContent = text;
        n.style.left = `${x - host.left}px`; n.style.top = `${y - host.top}px`;
        el.pops.append(n); setTimeout(() => n.remove(), 1200);
    }
    function bump(node) { const s = node.closest('.hg-stat'); s?.classList.remove('bump'); void s?.offsetWidth; s?.classList.add('bump'); }
    function shake() { if (reduced) return; el.stage.classList.remove('shake'); void el.stage.offsetWidth; el.stage.classList.add('shake'); el.vignette.classList.remove('on'); void el.vignette.offsetWidth; el.vignette.classList.add('on'); }
    function earn(n, why, from) {
        if (n <= 0) return;
        G.earned += n; seg().earned += n;
        const update = () => { el.earned.textContent = money(G.earned); bump(el.earned); sfx.coin(); };
        if (from) { coins(from.x, from.y, el.earned, Math.min(10, 3 + Math.round(n / 80)), update); pop(`+${money(n)}`, from.x, from.y, 'g', n >= 300); } else update();
        if (why) toast(why, 'g');
    }
    function lose(n, why, from) {
        if (n <= 0) return;
        G.lost += n; seg().lost += n; el.lost.textContent = money(G.lost); bump(el.lost); sfx.bad(); shake();
        const p = from || centerOf(el.lost); pop(`−${money(n)}`, p.x, p.y + 30, 'r', n >= 300);
        if (why) toast(why, 'r');
    }
    function missCall(c, why) {
        if (c.type === 'spam') { toast('Missed a sales call. No loss.'); return; }
        G.missed++; seg().missed++; el.missed.textContent = G.missed; bump(el.missed);
        lose(c.value, why || `${c.name} called the next plumber`, centerOf(el.phone));
    }
    function toast(text, kind = '') {
        const n = document.createElement('div'); n.className = `hg-toast ${kind}`; n.textContent = text;
        el.toasts.append(n); setTimeout(() => n.remove(), 2500);
        while (el.toasts.children.length > 3) el.toasts.firstChild.remove();
    }

    // ---- the job: fix the leaks -------------------------------------------------------------------------------
    function newJob() {
        const base = JOBS[G.jobIdx % JOBS.length]; G.jobIdx++;
        G.job = { ...base, current: base.value, spawned: 0, fixed: 0 };
        el.leaks.innerHTML = ''; G.water = Math.max(0, G.water - 35);
        el.jobName.textContent = base.name; el.jobValue.textContent = money(base.value); el.jobBar.style.width = '0%';
        at(0.35, spawnLeak);
    }
    function spawnLeak() {
        const j = G.job; if (!j || j.spawned >= j.leaks) return;
        const used = [...el.leaks.children].map((g) => g.dataset.spot);
        const free = SPOTS.map((_, i) => String(i)).filter((i) => !used.includes(i));
        const i = free[Math.floor(Math.random() * free.length)] ?? '0';
        const [x, y] = SPOTS[+i];
        const g = document.createElementNS('http://www.w3.org/2000/svg', 'g');
        g.setAttribute('class', 'hg-leak new'); g.setAttribute('tabindex', '0'); g.setAttribute('role', 'button'); g.setAttribute('aria-label', 'Fix this leak');
        g.dataset.spot = i;
        g.innerHTML = `<circle class="hit" cx="${x}" cy="${y}" r="40"/><circle class="glow" cx="${x}" cy="${y}" r="26"/><circle class="ring" cx="${x}" cy="${y}" r="18"/><g class="badge"><circle cx="${x + 18}" cy="${y - 26}" r="13"/><text x="${x + 18}" y="${y - 19}" text-anchor="middle">!</text></g>`;
        const fix = (e) => { e.preventDefault(); fixLeak(g); };
        g.addEventListener('pointerdown', fix);
        g.addEventListener('keydown', (e) => { if (e.key === 'Enter' || e.key === ' ') fix(e); });
        el.leaks.append(g); j.spawned++; sfx.pop();
        const p = svgPoint(x, y); for (let k = 0; k < 10; k++) emit({ kind: 'drop', x: p.x, y: p.y, vx: rand(-3, 3), vy: rand(-4, -1), g: 0.25, r: rand(2, 3.5), c: '#5fd0ff', life: rand(0.5, 0.9), max: 0.9, drag: 0.99 });
        if (j.spawned < j.leaks) at(rand(0.85, 1.4), spawnLeak);
    }
    function fixLeak(g) {
        if (!G.running || g.classList.contains('fixed') || (G.call && G.call.state === 'talk')) return;
        g.classList.add('fixed'); G.job.fixed++;
        const [x, y] = SPOTS[+g.dataset.spot]; const p = svgPoint(x, y);
        // combo: quick fixes in a row
        G.combo = G.t - G.lastFix < 1.6 ? Math.min(5, G.combo + 1) : 1; G.lastFix = G.t; G.bestCombo = Math.max(G.bestCombo, G.combo);
        el.comboVal.textContent = `x${G.combo}`; el.combo.classList.remove('hot'); void el.combo.offsetWidth; if (G.combo > 1) el.combo.classList.add('hot');
        sfx.fix(G.combo); sparks(p.x, p.y, 14 + G.combo * 4);
        const w = document.createElementNS('http://www.w3.org/2000/svg', 'g'); w.setAttribute('class', 'hg-wrench');
        w.innerHTML = `<path d="M${x - 8} ${y + 34} l26 -26 a14 14 0 1 1 10 -18 l-8 8 6 6 8 -8 a14 14 0 0 1 -18 10 l-26 26z" fill="#e9eef5" stroke="#5c6475" stroke-width="3" stroke-linejoin="round"/>`;
        el.wrenches.append(w); setTimeout(() => w.remove(), 550);
        if (G.combo > 1) { const bonus = 10 * G.combo; G.earned += bonus; seg().earned += bonus; el.earned.textContent = money(G.earned); pop(`Combo x${G.combo} +$${bonus}`, p.x, p.y - 20, 'y'); }
        el.jobBar.style.width = `${(G.job.fixed / G.job.leaks) * 100}%`;
        if (G.job.fixed >= G.job.leaks) {
            const j = G.job; G.job = null; seg().jobs++;
            const c = centerOf(el.jobValue);
            earn(j.current, `${j.name} done!`, c); sparks(c.x, c.y, 30, ['#ffd166', '#57ff8d']);
            at(0.8, newJob);
        }
    }
    const openLeaks = () => [...el.leaks.querySelectorAll('.hg-leak:not(.fixed)')];

    // ---- the phone, on your own ------------------------------------------------------------------------------
    function nextCaller() { const c = { ...CALLS[G.callIdx % CALLS.length] }; G.callIdx++; return c; }
    function incoming() {
        const c = nextCaller(); G.calls++; seg().calls++;
        if (G.mode === 'help') { G.queue.push(c); avaNext(); return; }
        if (G.call) { missCall(c, `Call waiting: ${c.name} hung up`); return; }
        G.call = { ...c, state: 'ring', until: G.t + 5 };
        el.idle.hidden = true; el.call.hidden = false; el.phone.classList.add('ringing'); el.callLabel.textContent = 'Incoming call';
        el.initials.textContent = c.name.split(/\s+/).map((w) => w[0]).join('').slice(0, 2).toUpperCase();
        el.avatar.style.background = AVATARS[G.callIdx % AVATARS.length];
        el.cname.textContent = c.name; el.ctag.textContent = c.tag; el.ctag.classList.toggle('urgent', c.type === 'emergency');
        el.cvalue.textContent = c.value ? `~${money(c.value)} job` : 'Unknown caller';
        el.cline.hidden = true; el.cbtns.hidden = false; el.choices.hidden = true;
        sfx.ring();
    }
    function endCall() {
        G.call = null; el.call.hidden = true; el.idle.hidden = false; el.phone.classList.remove('ringing'); el.busy.hidden = true;
    }
    function answer() {
        const c = G.call; if (!c || c.state !== 'ring' || !G.running) return;
        c.state = 'talk'; c.until = G.t + 7; el.phone.classList.remove('ringing'); el.callLabel.textContent = 'On the call · pick a reply';
        el.cbtns.hidden = true; el.busy.hidden = false; el.cline.hidden = false; el.cline.textContent = `“${c.line}”`;
        el.choices.innerHTML = '';
        CHOICES.forEach((ch, i) => {
            const b = document.createElement('button'); b.type = 'button'; b.innerHTML = `<kbd>${i + 1}</kbd><span></span>`; b.lastChild.textContent = ch.label;
            b.addEventListener('click', () => choose(ch)); el.choices.append(b);
        });
        el.choices.hidden = false; el.choices.querySelector('button')?.focus({ preventScroll: true });
    }
    function choose(ch) {
        const c = G.call; if (!c || c.state !== 'talk' || !G.running) return;
        const right = ch.fits.includes(c.type); const from = centerOf(el.phone);
        if (c.type === 'spam') { right ? toast('Sales call off the line. Back to work.') : toast('That was a sales call. Time wasted.', 'r'); }
        else if (right) { earn(c.value, `${c.type === 'emergency' ? 'Emergency booked' : 'Booked'}: ${c.name}`, from); }
        else { lose(c.value, `${c.name} wasn’t happy`, from); }
        endCall();
    }
    function decline() {
        const c = G.call; if (!c || c.state !== 'ring') return;
        endCall(); missCall(c);
    }

    // ---- the receptionist, with Sure Help -----------------------------------------------------------------------
    function bubble(text, who) {
        const b = document.createElement('div'); b.className = `hg-bubble ${who}`; b.textContent = text;
        el.talk.append(b); while (el.talk.children.length > 2) el.talk.firstChild.remove();
    }
    function feed(stamp, kind, text, amount) {
        const li = document.createElement('li'); li.innerHTML = `<span class="st ${kind}"></span><span></span><b></b>`;
        li.children[0].textContent = stamp; li.children[1].textContent = text; li.children[2].textContent = amount ? '+' + money(amount) : '';
        el.feed.prepend(li); while (el.feed.children.length > 6) el.feed.lastChild.remove();
        return li;
    }
    function avaNext() {
        if (G.avaBusy || !G.queue.length) return;
        const c = G.queue.shift(); G.avaBusy = true; G.avaCalls++;
        el.avaCount.textContent = `${G.avaCalls} ${G.avaCalls === 1 ? 'call' : 'calls'}`;
        el.ava.classList.add('talking'); el.avaStatus.textContent = `On a call with ${c.name}`; el.typing.hidden = true;
        el.talk.innerHTML = ''; bubble(c.line, 'caller'); sfx.tick();
        at(0.9, () => { bubble(AVA_SAYS[c.type], 'ava'); el.typing.hidden = false; });
        at(2.3, () => {
            el.ava.classList.remove('talking'); el.typing.hidden = true; G.avaBusy = false;
            el.avaStatus.textContent = G.queue.length ? 'Next call…' : 'Ready for your calls';
            const slot = SLOTS[G.slot++ % SLOTS.length];
            if (c.type === 'spam') { feed('Screened', 's', 'Sales call turned away', 0); }
            else if (c.type === 'emergency') urgent(c);
            else { const li = feed(c.type === 'quote' ? 'Estimate' : 'Booked', '', `${c.name}, ${slot}`, c.value); earn(c.value, null, centerOf(li)); }
            avaNext();
        });
    }
    function urgent(c) {
        G.urgent = { ...c, until: G.t + 3.4, len: 3.4 };
        el.urgentText.textContent = `${c.tag}: ${c.name}. Take it?`; el.urgent.hidden = false; sfx.ring();
    }
    function takeUrgent() {
        const c = G.urgent; if (!c || !G.running) return;
        G.urgent = null; el.urgent.hidden = true;
        const v = Math.round(c.value * 1.15);
        const li = feed('Transfer', 'u', `${c.name} put through to you`, v); earn(v, `${R} put ${c.name} through to you`, centerOf(li));
    }
    function urgentTimeout() {
        const c = G.urgent; G.urgent = null; el.urgent.hidden = true;
        const li = feed('Booked', '', `${c.name}: texted you, first slot`, c.value); earn(c.value, null, centerOf(li));
    }
    function switchToHelp() {
        G.mode = 'help';
        el.mode.textContent = `${R} on the phones`; el.mode.classList.add('help');
        const c = G.call; endCall();
        el.phone.hidden = true; el.ava.hidden = false; el.ava.classList.remove('enter'); void el.ava.offsetWidth; el.ava.classList.add('enter');
        el.feed.innerHTML = ''; el.talk.innerHTML = '';
        const p = centerOf(el.ava); sparks(p.x, p.y, 50, ['#57ff8d', '#6d5efc', '#4fc3ff', '#ffffff']); sfx.fanfare();
        if (c) G.queue.push(c);
        toast(`${R} is answering your calls now`, 'g');
        avaNext();
    }

    // ---- the offer -------------------------------------------------------------------------------------------
    function offer() {
        G.running = false; G.offers++; music(false); sfx.whoosh();
        el.offerStats.innerHTML = '';
        [['Calls', G.calls], ['Missed', G.missed], ['Lost', money(G.lost)]].forEach(([k, v]) => {
            const d = document.createElement('div'); d.innerHTML = '<small></small><b></b>'; d.firstChild.textContent = k; d.lastChild.textContent = v; el.offerStats.append(d);
        });
        el.offer.hidden = false; el.offer.querySelector('[data-accept]')?.focus({ preventScroll: true });
    }
    root.querySelector('[data-accept]')?.addEventListener('click', () => { el.offer.hidden = true; switchToHelp(); resume(); });
    root.querySelector('[data-decline-offer]')?.addEventListener('click', () => { el.offer.hidden = true; toast('Back to it. The phone’s still ringing…'); resume(); });

    // ---- the water ---------------------------------------------------------------------------------------------
    function drawWater() {
        const level = 480 - G.water * 1.7; const t = G.t;
        let d = `M80 480 L80 ${level.toFixed(1)}`; let hl = '';
        for (let x = 80; x <= 720; x += 20) {
            const y = level + Math.sin(x / 38 + t * 3) * 3 + Math.sin(x / 17 - t * 2) * 1.5;
            d += ` L${x} ${y.toFixed(1)}`; hl += `${x === 80 ? 'M' : 'L'}${x} ${(y + 1).toFixed(1)} `;
        }
        d += ' L720 480Z';
        el.water.setAttribute('d', G.water > 0.5 ? d : 'M80 480Z'); el.waterHl.setAttribute('d', G.water > 0.5 ? hl : '');
        const high = G.water > 70;
        el.flood.hidden = !high; el.vignette.classList.toggle('water', high);
    }

    // ---- loop --------------------------------------------------------------------------------------------------
    let last = 0;
    function resume() { G.running = true; last = performance.now(); music(true); requestAnimationFrame(frame); }
    function frame(now) {
        if (!G.running) return;
        const dt = Math.min(0.1, (now - last) / 1000); last = now;
        G.t += dt; seg().time += dt;

        const due = G.events.filter((e) => e.at <= G.t); G.events = G.events.filter((e) => e.at > G.t);
        due.forEach((e) => e.fn());

        if (G.t >= G.nextCallAt) { incoming(); G.nextCallAt = G.t + rand(3.2, 5.4) - Math.min(1, G.t / 60); }
        const c = G.call;
        if (c) {
            const left = Math.max(0, c.until - G.t);
            if (c.state === 'ring') {
                el.ring.style.strokeDashoffset = String(timerLen * (1 - left / 5));
                if (Math.floor(left) !== Math.floor(left + dt)) sfx.ring();
                if (left <= 0) { endCall(); missCall(c); }
            } else if (left <= 0) { endCall(); lose(c.value, `${c.name} gave up waiting`, centerOf(el.phone)); }
        }
        if (G.urgent) { el.urgentBar.style.width = `${Math.max(0, (G.urgent.until - G.t) / G.urgent.len) * 100}%`; if (G.t >= G.urgent.until) urgentTimeout(); }

        // spray from open leaks, and the water rising
        const open = openLeaks();
        G.spray += dt;
        if (G.spray > 0.05 && open.length) {
            G.spray = 0;
            open.forEach((g) => { const [x, y] = SPOTS[+g.dataset.spot]; const p = svgPoint(x, y); emit({ kind: 'drop', x: p.x + rand(-4, 4), y: p.y + 8, vx: rand(-1.6, 1.6), vy: rand(-1.5, 0.5), g: 0.32, r: rand(1.8, 3.2), c: Math.random() < 0.3 ? '#bff0ff' : '#4fc3ff', life: 0.7, max: 0.7, drag: 0.995 }); });
        }
        G.water = Math.min(100, G.water + open.length * dt * 6.5);
        if (G.water >= 100 && G.job) {
            const hit = Math.round(G.job.value * 0.2);
            if (G.job.current - hit >= G.job.value * 0.4) { G.job.current -= hit; el.jobValue.textContent = money(G.job.current); el.jobValue.classList.remove('hit'); void el.jobValue.offsetWidth; el.jobValue.classList.add('hit'); lose(hit, 'Water damage!', centerOf(el.jobValue)); sfx.splash(); }
            G.water = 50;
        }
        drawWater();
        el.comboBar.style.width = `${Math.max(0, 1 - (G.t - G.lastFix) / 1.6) * 100}%`;
        if (G.combo > 1 && G.t - G.lastFix > 1.6) { G.combo = 1; el.comboVal.textContent = 'x1'; }

        // clock, warning ticks and the offer
        const left = Math.max(0, TOTAL - G.t);
        el.time.textContent = Math.ceil(left); el.timerRing.style.strokeDashoffset = String(timerLen * (1 - left / TOTAL));
        el.time.parentElement.classList.toggle('warn', left <= 10);
        if (left <= 10 && Math.ceil(left) !== G.lastSec) { G.lastSec = Math.ceil(left); sfx.tick(); }
        if (G.mode === 'solo' && ((G.offers === 0 && G.t >= OFFER) || (G.offers === 1 && G.t >= OFFER * 2))) { offer(); return; }
        if (left <= 0) { finish(); return; }
        requestAnimationFrame(frame);
    }

    // ---- start, countdown, finish -------------------------------------------------------------------------------
    function begin() {
        initAudio(); audio?.resume?.();
        G = fresh();
        el.earned.textContent = '$0'; el.lost.textContent = '$0'; el.missed.textContent = '0'; el.comboVal.textContent = 'x1';
        el.mode.textContent = 'Solo shift'; el.mode.classList.remove('help');
        el.phone.hidden = false; el.ava.hidden = true; el.urgent.hidden = true; el.offer.hidden = true; el.toasts.innerHTML = ''; el.pops.innerHTML = '';
        el.timerRing.style.strokeDashoffset = '0'; el.time.textContent = TOTAL;
        endCall(); G.water = 0; drawWater(); el.leaks.innerHTML = ''; el.jobBar.style.width = '0%';
        show('play'); countdown(['3', '2', '1', 'GO!'], () => { newJob(); resume(); });
    }
    function countdown(steps, done) {
        if (reduced) { done(); return; }
        el.countdown.hidden = false; let i = 0;
        const next = () => {
            if (i >= steps.length) { el.countdown.hidden = true; done(); return; }
            const s = steps[i++]; el.count.textContent = s; el.count.classList.toggle('go', s === 'GO!');
            el.count.style.animation = 'none'; void el.count.offsetWidth; el.count.style.animation = '';
            sfx.beep(s === 'GO!'); setTimeout(next, s === 'GO!' ? 500 : 700);
        };
        next();
    }
    function perMin(v, s) { return s > 1 ? (v / s) * 60 : 0; }
    function countUp(node, to, ms = 1200) {
        const t0 = performance.now();
        const step = (now) => { const k = Math.min(1, (now - t0) / ms); node.textContent = money(to * (1 - Math.pow(1 - k, 3))); if (k < 1) requestAnimationFrame(step); };
        requestAnimationFrame(step);
    }
    function finish() {
        G.running = false; endCall(); el.urgent.hidden = true; music(false); el.flood.hidden = true; el.vignette.classList.remove('water');
        const s = G.seg.solo; const h = G.seg.help; const used = h.time > 1;
        const set = (k, v) => { const n = root.querySelector(`[data-r="${k}"]`); if (n) n.textContent = v; };
        const soloPM = perMin(s.earned, s.time); const helpPM = perMin(h.earned, h.time);
        set('solo-earn', money(soloPM)); set('help-earn', money(helpPM));
        set('solo-lost', money(perMin(s.lost, s.time))); set('help-lost', money(perMin(h.lost, h.time)));
        set('missed', G.missed); set('help-calls', G.avaCalls); set('jobs', s.jobs + h.jobs); set('combo', `x${G.bestCombo}`);
        root.querySelector('[data-res-help]').hidden = !used; root.querySelector('[data-res-help-chip]').hidden = !used;
        const title = root.querySelector('[data-res-title]'); const lead = root.querySelector('[data-res-lead]');
        if (used) {
            const gain = helpPM - soloPM;
            title.textContent = gain > 0 ? `With ${S.brand} you earned ${money(gain)} more a minute` : `${R} took ${G.avaCalls} calls so you could keep working`;
            lead.textContent = `On your own you lost ${money(perMin(s.lost, s.time))} a minute to missed calls and water damage. With ${R} answering, you lost ${money(perMin(h.lost, h.time))}. Every caller got an answer, and you kept your hands on the job.`;
        } else {
            title.textContent = `You lost ${money(G.lost)} of work to the phone`;
            lead.textContent = `You missed ${G.missed} ${G.missed === 1 ? 'call' : 'calls'} in one minute. Play again and bring in ${R} to see the difference.`;
        }
        const stars = G.earned >= 6000 ? 3 : G.earned >= 3000 ? 2 : 1;
        root.querySelectorAll('[data-stars] svg').forEach((st, i) => { st.classList.remove('on'); if (i < stars) setTimeout(() => { st.classList.add('on'); sfx.fix(i + 2); }, 500 + i * 300); });
        const isBest = G.earned > best; if (isBest) { best = G.earned; try { localStorage.setItem('hg.best', String(Math.round(best))); } catch (e) { /* ignore */ } showBest(); }
        root.querySelector('[data-newbest]').hidden = !isBest;
        show('results'); title.focus({ preventScroll: true });
        countUp(root.querySelector('[data-score]'), G.earned);
        const max = Math.max(soloPM, helpPM, 1);
        const bs = root.querySelector('[data-bar-solo]'); const bh = root.querySelector('[data-bar-help]');
        bs.style.width = '0%'; bh.style.width = '0%';
        setTimeout(() => { bs.style.width = `${(soloPM / max) * 100}%`; bh.style.width = `${(helpPM / max) * 100}%`; }, 300);
        sfx.fanfare(); if (stars >= 2 || isBest) setTimeout(confetti, 400);
    }
    root.querySelector('[data-start]')?.addEventListener('click', begin);
    root.querySelector('[data-again]')?.addEventListener('click', begin);
    root.querySelector('[data-answer]')?.addEventListener('click', answer);
    root.querySelector('[data-decline]')?.addEventListener('click', decline);
    root.querySelector('[data-take]')?.addEventListener('click', takeUrgent);
    root.querySelector('[data-share]')?.addEventListener('click', async (e) => {
        const btn = e.currentTarget;
        const data = { title: 'Hands Full: the missed-call game', text: 'How much work would you lose to the phone? Try it.', url: S.share };
        try {
            if (navigator.share) await navigator.share(data);
            else { await navigator.clipboard.writeText(S.share); const old = btn.innerHTML; btn.textContent = 'Link copied'; setTimeout(() => { btn.innerHTML = old; }, 1800); }
        } catch (err) { /* cancelled */ }
    });
    document.addEventListener('keydown', (e) => {
        if (!G?.running || e.target.closest?.('input, textarea')) return;
        const k = e.key.toLowerCase();
        if (k === 'a') answer();
        else if (k === 'd') decline();
        else if (k === 't') takeUrgent();
        else if (['1', '2', '3'].includes(k) && G.call?.state === 'talk') choose(CHOICES[+k - 1]);
        else if (k === 'f') { const l = openLeaks()[0]; if (l) fixLeak(l); }
    });
}
