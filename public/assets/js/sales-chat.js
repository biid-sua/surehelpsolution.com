/*
 * SureHelp's own sales chat on the public site (partials/sales-chat.blade.php).
 * Vanilla JS, no dependencies. Server text is only ever rendered with textContent.
 * Requests use XMLHttpRequest on purpose: the public layout wraps window.fetch with a
 * session-timeout handler that redirects on errors, which a chat must not trigger.
 */
(() => {
  'use strict';

  const root = document.getElementById('sales-chat');
  if (!root || root.dataset.ready) return;
  root.dataset.ready = '1';
  document.body.classList.add('has-sales-chat');

  const API = root.dataset.endpoint.replace(/\/$/, '');
  const KEY = 'surehelp.sales-chat';
  const TEASER_KEY = 'surehelp.sales-chat.teaser';
  const POLL_MS = 6000;
  const $ = (sel) => root.querySelector(sel);
  const launcher = $('.sales-chat__launcher');
  const panel = $('.sales-chat__panel');
  const log = $('.sales-chat__log');
  const form = $('.sales-chat__composer');
  const input = $('#sales-chat-input');
  const sendBtn = $('.sales-chat__send');
  const errorBox = $('.sales-chat__error');
  const teaser = $('.sales-chat__teaser');
  const dot = $('.sales-chat__dot');
  const statusText = root.querySelector('[data-sc-status]');

  const state = { token: read(KEY), started: false, starting: null, busy: false, staff: false, lastId: null, seen: new Set(), poll: null, retry: null };

  function read(key, store = localStorage) { try { return store.getItem(key); } catch (e) { return null; } }
  function write(key, value, store = localStorage) { try { value === null ? store.removeItem(key) : store.setItem(key, value); } catch (e) { /* private mode */ } }

  /* ---------- HTTP ---------- */
  function request(method, path, body) {
    return new Promise((resolve, reject) => {
      const xhr = new XMLHttpRequest();
      xhr.open(method, API + path);
      xhr.setRequestHeader('Accept', 'application/json');
      if (body !== undefined) xhr.setRequestHeader('Content-Type', 'text/plain;charset=UTF-8');
      xhr.timeout = 60000;
      xhr.onload = () => {
        let data = {};
        try { data = JSON.parse(xhr.responseText || '{}'); } catch (e) { data = {}; }
        if (xhr.status >= 200 && xhr.status < 300) resolve(data);
        else reject({ status: xhr.status, data });
      };
      xhr.onerror = () => reject({ status: 0, data: {} });
      xhr.ontimeout = () => reject({ status: 0, data: {} });
      xhr.send(body === undefined ? null : JSON.stringify(body));
    });
  }

  function utm() {
    const q = new URLSearchParams(location.search);
    const out = {};
    ['source', 'medium', 'campaign', 'term', 'content'].forEach((k) => { const v = q.get('utm_' + k); if (v) out[k] = v.slice(0, 120); });
    return out;
  }

  /* ---------- Opening and closing ---------- */
  /** Open the chat; `quick` (e.g. "demo") sends that quick reply once the chat has started. */
  function open(quick) {
    hideTeaser(true);
    dot.hidden = true;
    root.classList.add('is-open');
    panel.hidden = false;
    launcher.setAttribute('aria-expanded', 'true');
    launcher.setAttribute('aria-label', 'Close chat');
    start().then(() => {
      scrollDown(); input.focus(); syncPolling();
      if (typeof quick === 'string' && quick) send({ quick });
    });
    input.focus();
  }

  function close() {
    root.classList.remove('is-open');
    panel.hidden = true;
    launcher.setAttribute('aria-expanded', 'false');
    launcher.setAttribute('aria-label', 'Chat with SureHelp');
    syncPolling();
    launcher.focus();
  }

  const isOpen = () => !panel.hidden;

  function start() {
    if (state.started) return Promise.resolve();
    if (state.starting) return state.starting;
    showTyping(true);
    state.starting = request('POST', '/start', {
      token: state.token || undefined,
      page: location.pathname,
      referrer: document.referrer || '',
      utm: utm(),
      locale: navigator.language || '',
    }).then((data) => {
      state.started = true;
      if (data.token) { state.token = data.token; write(KEY, data.token); }
      if (data.title) $('.sales-chat__title').textContent = data.title;
      const disclosure = $('.sales-chat__disclosure');
      if (data.disclosure) { disclosure.textContent = data.disclosure; disclosure.hidden = false; }
      apply(data);
    }).catch((err) => {
      showError(errText(err, 'We couldn’t start the chat.'), () => { state.starting = null; start(); });
    }).finally(() => { showTyping(false); state.starting = null; });
    return state.starting;
  }

  /* ---------- Applying a server response ---------- */
  function apply(data) {
    clearTransient();
    (data.messages || []).forEach(addMessage);
    if (typeof data.staff_joined === 'boolean') setStaff(data.staff_joined);
    if (data.booking) addBooked(data.booking);
    if (data.action && data.action.type === 'slots') addSlots(data.action);
    else if (data.action && data.action.type === 'contact') addContact(data.action);
    if (data.quick_replies && data.quick_replies.length) addChips(data.quick_replies);
    scrollDown();
    if (!isOpen() && (data.messages || []).some((m) => m.from !== 'you')) dot.hidden = false;
  }

  function clearTransient() {
    log.querySelectorAll('[data-transient]').forEach((el) => el.remove());
  }

  function addMessage(m) {
    if (!m || state.seen.has(m.id)) return;
    state.seen.add(m.id);
    state.lastId = m.id;
    const from = ['you', 'assistant', 'team'].includes(m.from) ? m.from : 'assistant';
    const wrap = el('div', 'sc-msg sc-msg--' + from);
    if (from === 'team') wrap.append(el('span', 'sc-msg__who', 'SureHelp team'));
    const bubble = el('div', 'sc-msg__bubble');
    format(m.text || '', bubble);
    wrap.append(bubble);
    if (m.at) {
      const t = new Date(m.at);
      if (!isNaN(t)) wrap.append(el('time', 'sc-msg__time', t.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' })));
    }
    insert(wrap);
  }

  /** **bold**, line breaks (pre-wrap) and links, built as DOM nodes; never innerHTML. */
  function format(text, target) {
    // Bold, full links, and links to pages on this site such as /start or /#pricing.
    const parts = String(text).split(/(\*\*[^*]+\*\*|https?:\/\/[^\s<>()]+[^\s<>().,!?;:'"]|(?<![\w/])\/(?:#[a-z0-9-]+|[a-z][a-z0-9-]*(?:#[a-z0-9-]+)?))/g);
    parts.forEach((part) => {
      if (!part) return;
      if (/^\*\*[^*]+\*\*$/.test(part)) {
        const inner = part.slice(2, -2);
        if (/^\/[#a-z]/.test(inner)) { const a = el('a', null, inner); a.href = inner; const b = el('strong'); b.append(a); target.append(b); return; }
        target.append(el('strong', null, inner));
        return;
      }
      if (/^\/[#a-z]/.test(part)) { const a = el('a', null, part); a.href = part; target.append(a); return; }
      if (/^https?:\/\//.test(part)) {
        const a = el('a', null, part);
        a.href = part; a.target = '_blank'; a.rel = 'noopener noreferrer';
        target.append(a);
        return;
      }
      target.append(document.createTextNode(part));
    });
  }

  function addChips(replies) {
    const box = el('div', 'sc-chips');
    box.dataset.transient = '1';
    box.setAttribute('role', 'group');
    box.setAttribute('aria-label', 'Suggested replies');
    replies.forEach((r) => {
      const b = el('button', 'sc-chip', r.label);
      b.type = 'button';
      b.addEventListener('click', () => send({ quick: r.id }));
      box.append(b);
    });
    insert(box);
  }

  function addSlots(action) {
    const card = el('div', 'sc-card');
    card.dataset.transient = '1';
    card.append(el('p', 'sc-card__title', 'Pick a time for your demo'));
    const days = (action.days || []).filter((d) => d.slots && d.slots.length);
    if (!days.length) {
      card.append(el('p', 'sc-card__note', 'No times are open right now. Leave your details and we’ll reach out.'));
      insert(card);
      return;
    }
    const tabs = el('div', 'sc-days');
    tabs.setAttribute('role', 'tablist');
    tabs.setAttribute('aria-label', 'Day');
    const times = el('div', 'sc-times');
    const show = (i) => {
      [...tabs.children].forEach((t, j) => { t.setAttribute('aria-selected', String(i === j)); t.tabIndex = i === j ? 0 : -1; });
      times.replaceChildren(...days[i].slots.map((s) => {
        const b = el('button', 'sc-time', s.label);
        b.type = 'button';
        b.addEventListener('click', () => book(s.starts_at, card));
        return b;
      }));
    };
    days.forEach((d, i) => {
      const t = el('button', 'sc-day', d.label);
      t.type = 'button';
      t.setAttribute('role', 'tab');
      t.addEventListener('click', () => show(i));
      t.addEventListener('keydown', (e) => {
        if (e.key !== 'ArrowRight' && e.key !== 'ArrowLeft') return;
        const next = (i + (e.key === 'ArrowRight' ? 1 : days.length - 1)) % days.length;
        show(next); tabs.children[next].focus();
      });
      tabs.append(t);
    });
    card.append(tabs, times);
    if (action.timezone) card.append(el('p', 'sc-card__note', 'Times shown in ' + action.timezone + '.'));
    show(0);
    insert(card);
  }

  function addContact(action) {
    const card = el('div', 'sc-card');
    card.dataset.transient = '1';
    card.append(el('p', 'sc-card__title', action.reason || 'Where can we reach you?'));
    const f = el('form', 'sc-form');
    f.noValidate = true;
    const labels = { name: 'Name', email: 'Email', phone: 'Phone', company: 'Company' };
    const types = { email: 'email', phone: 'tel' };
    const autocomplete = { name: 'name', email: 'email', phone: 'tel', company: 'organization' };
    const required = action.required || ['name', 'email'];
    (action.fields || ['name', 'email', 'phone', 'company']).forEach((name) => {
      const id = 'sc-' + name + '-' + Math.random().toString(36).slice(2, 7);
      const field = el('div', 'sc-field');
      const label = el('label', null, labels[name] + (required.includes(name) ? '' : ' (optional)'));
      label.htmlFor = id;
      const inp = el('input');
      inp.id = id; inp.name = name; inp.type = types[name] || 'text'; inp.autocomplete = autocomplete[name] || 'off';
      inp.maxLength = name === 'phone' ? 30 : 120;
      if (required.includes(name)) inp.required = true;
      const err = el('span', 'sc-field__error');
      err.id = id + '-err'; err.hidden = true;
      inp.setAttribute('aria-describedby', err.id);
      field.append(label, inp, err);
      f.append(field);
    });
    const hp = el('div', 'sc-hp');
    hp.setAttribute('aria-hidden', 'true');
    const hpInput = el('input');
    hpInput.name = 'website'; hpInput.tabIndex = -1; hpInput.autocomplete = 'off';
    hp.append(hpInput);
    const consent = el('label', 'sc-consent');
    const box = el('input');
    box.type = 'checkbox'; box.name = 'sms_consent';
    consent.append(box, el('span', null, '(Optional) I agree to receive text messages from SureHelp about my enquiry. Message and data rates may apply. Reply STOP to opt out.'));
    const submit = el('button', 'sc-primary', 'Continue');
    submit.type = 'submit';
    f.append(hp, consent, submit);
    f.addEventListener('submit', (e) => {
      e.preventDefault();
      const v = (n) => (f.elements[n] ? f.elements[n].value.trim() : '');
      let bad = false;
      [...f.querySelectorAll('input[required]')].forEach((i) => {
        const missing = !i.value.trim() || (i.type === 'email' && !/^\S+@\S+\.\S+$/.test(i.value.trim()));
        fieldError(f, i.name, missing ? (i.value.trim() ? 'Please check this email address.' : 'Please fill this in.') : null);
        if (missing && !bad) { bad = true; i.focus(); }
      });
      if (bad) return;
      submit.disabled = true;
      busy(true);
      request('POST', '/lead', {
        token: state.token, name: v('name'), email: v('email'), phone: v('phone'), company: v('company'),
        sms_consent: box.checked, website: hpInput.value,
      }).then(apply).catch((err) => {
        submit.disabled = false;
        const errors = err.data && err.data.errors;
        if (err.status === 422 && errors) {
          Object.keys(errors).forEach((k) => fieldError(f, k, [].concat(errors[k])[0]));
          const first = f.querySelector('[aria-invalid="true"]');
          if (first) first.focus();
        } else {
          showError(errText(err, 'We couldn’t save your details.'), () => f.requestSubmit());
        }
      }).finally(() => busy(false));
    });
    card.append(f);
    insert(card);
    setTimeout(() => { const first = f.querySelector('input:not([tabindex="-1"])'); if (first && isOpen()) first.focus(); }, 50);
  }

  function fieldError(f, name, message) {
    const inp = f.elements[name];
    if (!inp || !inp.getAttribute) return;
    const err = document.getElementById(inp.getAttribute('aria-describedby'));
    inp.setAttribute('aria-invalid', message ? 'true' : 'false');
    if (err) { err.textContent = message || ''; err.hidden = !message; }
  }

  function addBooked(b) {
    log.querySelectorAll('.sc-booked').forEach((el) => el.remove());
    const card = el('div', 'sc-booked');
    card.append(el('p', 'sc-booked__title', 'Your demo is booked'));
    card.append(el('p', 'sc-booked__when', b.label || b.starts_at));
    card.append(el('p', 'sc-booked__note', 'We’ve emailed you a calendar invite' + (b.timezone ? ' (' + b.timezone + ')' : '') + '. Add it to your calendar so you don’t miss it.'));
    insert(card);
  }

  /* ---------- Sending ---------- */
  function send(payload) {
    if (state.busy || !state.token) return;
    hideError();
    busy(true);
    showTyping(true);
    request('POST', '/messages', Object.assign({ token: state.token }, payload))
      .then((data) => { apply(data); syncPolling(); })
      .catch((err) => {
        if (payload.text && !input.value) input.value = payload.text;
        showError(errText(err, 'Your message didn’t send.'), () => send(payload));
      })
      .finally(() => { showTyping(false); busy(false); input.focus(); });
  }

  function book(startsAt, card) {
    if (state.busy) return;
    hideError();
    busy(true);
    card.querySelectorAll('button').forEach((b) => { b.disabled = true; });
    request('POST', '/book', { token: state.token, starts_at: startsAt })
      .then(apply)
      .catch((err) => {
        if ((err.status === 409 || err.status === 422) && err.data) {
          if (err.data.error) addMessage({ id: 'local-' + Date.now(), from: 'assistant', text: err.data.error });
          apply({ action: err.data.action || null });
        } else {
          card.querySelectorAll('button').forEach((b) => { b.disabled = false; });
          showError(errText(err, 'We couldn’t book that time.'), () => book(startsAt, card));
        }
      })
      .finally(() => busy(false));
  }

  form.addEventListener('submit', (e) => {
    e.preventDefault();
    const text = input.value.trim();
    if (!text) return;
    input.value = '';
    autosize();
    if (!state.started) { start().then(() => send({ text })); return; }
    send({ text });
  });
  input.addEventListener('keydown', (e) => {
    if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) { e.preventDefault(); form.requestSubmit(); }
  });
  input.addEventListener('input', autosize);
  function autosize() { input.style.height = 'auto'; input.style.height = Math.min(input.scrollHeight, 120) + 'px'; }

  /* ---------- Staff polling ---------- */
  function setStaff(joined) {
    state.staff = joined;
    statusText.textContent = joined ? 'A person from our team has joined' : 'Typically replies instantly';
    syncPolling();
  }

  function syncPolling() {
    const want = state.staff && isOpen() && document.visibilityState === 'visible' && !!state.token;
    if (want && !state.poll) state.poll = setInterval(pollOnce, POLL_MS);
    if (!want && state.poll) { clearInterval(state.poll); state.poll = null; }
  }

  function pollOnce() {
    if (state.busy) return;
    const q = '?token=' + encodeURIComponent(state.token) + (state.lastId ? '&after=' + encodeURIComponent(state.lastId) : '');
    request('GET', '/messages' + q).then((data) => {
      const fresh = (data.messages || []).filter((m) => !state.seen.has(m.id));
      if (fresh.length) { fresh.forEach(addMessage); scrollDown(); }
      if (typeof data.staff_joined === 'boolean') setStaff(data.staff_joined);
    }).catch(() => { /* try again on the next tick */ });
  }
  document.addEventListener('visibilitychange', syncPolling);

  /* ---------- Small helpers ---------- */
  function el(tag, cls, text) {
    const node = document.createElement(tag);
    if (cls) node.className = cls;
    if (text !== undefined && text !== null) node.textContent = text;
    return node;
  }
  function insert(node) {
    const typing = log.querySelector('.sc-typing');
    typing ? log.insertBefore(node, typing) : log.append(node);
  }
  function scrollDown() { log.scrollTop = log.scrollHeight; }
  function busy(on) {
    state.busy = on;
    sendBtn.disabled = on;
    input.disabled = on;
    log.querySelectorAll('.sc-chip').forEach((b) => { b.disabled = on; });
  }
  function showTyping(on) {
    let t = log.querySelector('.sc-typing');
    if (on && !t) {
      t = el('div', 'sc-typing');
      t.setAttribute('aria-label', 'SureHelp is typing');
      t.setAttribute('role', 'status');
      t.append(el('span'), el('span'), el('span'));
      log.append(t);
      scrollDown();
    } else if (!on && t) t.remove();
  }
  function errText(err, fallback) {
    if (err && err.status === 429) return (err.data && err.data.error) || 'You’re sending messages quickly. Please wait a moment.';
    if (err && err.data && err.data.error) return err.data.error;
    if (err && err.status === 0) return fallback + ' Check your connection.';
    return fallback;
  }
  function showError(text, retry) {
    errorBox.querySelector('span').textContent = text;
    errorBox.querySelector('button').hidden = !retry;
    state.retry = retry;
    errorBox.hidden = false;
  }
  function hideError() { errorBox.hidden = true; state.retry = null; }

  /* ---------- Teaser (once per session, never auto-opens) ---------- */
  function hideTeaser(remember) {
    teaser.hidden = true;
    if (remember) write(TEASER_KEY, '1', sessionStorage);
  }
  const teaserPaths = (root.dataset.teaserPaths || '/').split(',');
  if (teaserPaths.includes(location.pathname.replace(/\/$/, '') || '/') && !read(TEASER_KEY, sessionStorage) && !state.token) {
    setTimeout(() => { if (!isOpen() && !read(TEASER_KEY, sessionStorage)) { teaser.hidden = false; dot.hidden = false; write(TEASER_KEY, '1', sessionStorage); } }, 25000);
  }

  /* ---------- Wiring ---------- */
  root.addEventListener('click', (e) => {
    const action = e.target.closest('[data-sc]');
    if (!action) return;
    const what = action.dataset.sc;
    if (what === 'toggle') isOpen() ? close() : open();
    else if (what === 'open') open();
    else if (what === 'close') close();
    else if (what === 'dismiss-teaser') { hideTeaser(true); dot.hidden = true; }
    else if (what === 'retry' && state.retry) { const r = state.retry; hideError(); r(); }
  });
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && isOpen()) close(); });
  document.addEventListener('click', (e) => {
    const trigger = e.target.closest('[data-sales-chat-open]');
    if (trigger && !root.contains(trigger)) { e.preventDefault(); open(trigger.getAttribute('data-sales-chat-open') || ''); }
  });

  window.SureHelpSalesChat = { open };
})();
