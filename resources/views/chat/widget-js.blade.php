/* SureHelp on your website (docs/inbox.md, docs/websites.md): chat, online booking, click-to-call and a contact form.
   Add to any page: <script src=".../api/chat/widget.js" data-surehelp-chat="KEY" async></script> */
(function () {
  var API = {!! json_encode(url('/api/chat')) !!};
@verbatim
  var script = document.currentScript || document.querySelector('script[data-surehelp-chat]');
  if (!script || window.__surehelpChat) return;
  window.__surehelpChat = true;
  var KEY = script.getAttribute('data-surehelp-chat');
  var STORE = 'surehelp-chat-' + KEY;
  var token = null, last = '', timer = null, open = false, cfg = null, tab = null;
  try { token = localStorage.getItem(STORE); } catch (e) {}

  function el(tag, attrs, text) {
    var n = document.createElement(tag);
    for (var k in attrs || {}) n.setAttribute(k, attrs[k]);
    if (text != null) n.textContent = text; // text only: nothing from the server is parsed as HTML
    return n;
  }
  function req(method, path, body) {
    return fetch(API + '/' + encodeURIComponent(KEY) + path, {
      method: method,
      headers: body ? { 'Content-Type': 'text/plain' } : {},
      body: body ? JSON.stringify(body) : undefined
    }).then(function (r) { return r.json().then(function (j) { if (!r.ok) { var e = new Error(j.message || 'Something went wrong. Please try again.'); e.data = j; throw e; } return j; }); });
  }
  function field(label, input) {
    var w = el('label', { class: 'f' });
    w.appendChild(el('span', {}, label));
    w.appendChild(input);
    return w;
  }
  function honeypot() {
    // Hidden from people; bots fill it in and are ignored.
    var h = el('input', { type: 'text', name: 'website', tabindex: '-1', autocomplete: 'off', 'aria-hidden': 'true' });
    h.style.cssText = 'position:absolute;left:-9999px;width:1px;height:1px;opacity:0';
    return h;
  }

  var root = el('div', { id: 'surehelp-chat' });
  var shadow = root.attachShadow ? root.attachShadow({ mode: 'open' }) : root;
  var style = el('style');
  style.textContent = [
    ':host{all:initial}',
    '.b{position:fixed;right:20px;bottom:20px;z-index:2147483000;width:56px;height:56px;border-radius:50%;border:0;color:#fff;cursor:pointer;box-shadow:0 6px 24px rgba(0,0,0,.25);font:600 22px system-ui}',
    '.p{position:fixed;right:20px;bottom:88px;z-index:2147483000;width:min(370px,calc(100vw - 40px));height:min(560px,calc(100vh - 120px));display:none;flex-direction:column;background:#fff;color:#111;border-radius:16px;box-shadow:0 12px 40px rgba(0,0,0,.25);overflow:hidden;font:14px/1.45 system-ui,-apple-system,Segoe UI,Roboto,sans-serif}',
    '.p.o{display:flex}.h{padding:14px 16px;color:#fff}.h b{display:block;font-size:15px}.h small{opacity:.9}',
    '.t{display:flex;gap:4px;padding:6px;background:#f2f2f5;border-bottom:1px solid #e5e5ea}.t button{flex:1;border:0;border-radius:8px;padding:7px 4px;background:transparent;font:600 13px system-ui;color:#444;cursor:pointer}.t button[aria-selected="true"]{background:#fff;color:#111;box-shadow:0 1px 3px rgba(0,0,0,.12)}',
    '.v{flex:1;display:none;flex-direction:column;min-height:0}.v.o{display:flex}.sc{flex:1;overflow-y:auto;padding:12px}',
    '.l{flex:1;overflow-y:auto;padding:12px;background:#f6f6f8}.m{max-width:80%;margin:6px 0;padding:8px 12px;border-radius:14px;white-space:pre-wrap;word-wrap:break-word}',
    '.you{margin-left:auto;color:#fff}.biz{background:#fff;border:1px solid #e5e5ea}.who{display:block;font-size:11px;color:#666;margin-bottom:2px}',
    '.d{font-size:12px;color:#555;padding:8px 12px;background:#fff;border-top:1px solid #eee}',
    'form.c{display:flex;gap:8px;padding:10px;border-top:1px solid #eee;background:#fff}textarea{flex:1;resize:none;border:1px solid #ccc;border-radius:10px;padding:8px;font:inherit;height:40px}',
    'button.s{border:0;border-radius:10px;padding:10px 14px;color:#fff;font:600 14px system-ui;cursor:pointer}button.s:disabled{opacity:.6}.e{color:#b00020;font-size:12px;padding:0 12px 8px}',
    '.f{display:block;margin:0 0 10px}.f span{display:block;font-size:12px;font-weight:600;color:#444;margin-bottom:4px}.f input,.f select,.f textarea{box-sizing:border-box;width:100%;border:1px solid #ccc;border-radius:10px;padding:8px;font:inherit;height:auto}',
    '.g{display:grid;grid-template-columns:repeat(3,1fr);gap:6px;margin:0 0 10px}.g button{border:1px solid #ccc;border-radius:8px;background:#fff;padding:7px 2px;font:13px system-ui;cursor:pointer}.g button[aria-pressed="true"]{color:#fff;border-color:transparent}',
    '.n{font-size:13px;color:#555;margin:0 0 10px}.ok{padding:14px;border-radius:12px;background:#eefbf3;color:#0b5d2a}',
    'a.call{display:block;text-align:center;text-decoration:none;color:#fff;border-radius:12px;padding:14px;font:600 18px system-ui;margin:8px 0}',
    '@media (prefers-reduced-motion:no-preference){.p.o{animation:in .15s ease-out}@keyframes in{from{opacity:0;transform:translateY(8px)}}}'
  ].join('');

  var button = el('button', { class: 'b', type: 'button', 'aria-label': 'Contact us', 'aria-expanded': 'false' }, '💬');
  var panel = el('div', { class: 'p', role: 'dialog', 'aria-label': 'Contact us' });
  var head = el('div', { class: 'h' });
  var title = el('b', {}, 'Chat with us');
  var sub = el('small', {}, '');
  head.appendChild(title); head.appendChild(sub);
  var tabs = el('div', { class: 't', role: 'tablist' });
  panel.appendChild(head); panel.appendChild(tabs);
  shadow.appendChild(style); shadow.appendChild(button); shadow.appendChild(panel);
  var views = {}, tabButtons = {}, color = '#7C3AED';

  function paint(c) {
    color = c;
    button.style.background = c; head.style.background = c;
    style.textContent += '.you{background:' + c + '}button.s{background:' + c + '}a.call{background:' + c + '}.g button[aria-pressed="true"]{background:' + c + '}.t button[aria-selected="true"]{color:' + c + '}';
  }
  function show(name) {
    tab = name;
    for (var k in views) {
      views[k].classList.toggle('o', k === name);
      if (tabButtons[k]) tabButtons[k].setAttribute('aria-selected', k === name ? 'true' : 'false');
    }
    clearInterval(timer);
    if (open && name === 'chat') { poll(); timer = setInterval(poll, 3000); }
    if (name === 'book' && views.book.load) views.book.load();
  }
  function addView(name, label) {
    var v = el('div', { class: 'v', role: 'tabpanel', 'aria-label': label });
    views[name] = v;
    panel.appendChild(v);
    var b = el('button', { type: 'button', role: 'tab', 'aria-selected': 'false' }, label);
    b.addEventListener('click', function () { show(name); });
    tabButtons[name] = b;
    tabs.appendChild(b);
    return v;
  }
  function toggle() {
    open = !open;
    panel.classList.toggle('o', open);
    button.setAttribute('aria-expanded', open ? 'true' : 'false');
    if (open) show(tab); else clearInterval(timer);
  }
  button.addEventListener('click', toggle);

  // Chat --------------------------------------------------------------------------------------
  var list, input;
  function add(m) {
    if (m.id && shadow.querySelector && shadow.querySelector('[data-id="' + m.id + '"]')) return;
    var b = el('div', { class: 'm ' + (m.from === 'you' ? 'you' : 'biz'), 'data-id': m.id || '' });
    if (m.from === 'assistant') b.appendChild(el('span', { class: 'who' }, (cfg && cfg.assistant ? cfg.assistant : 'Assistant') + ' · AI'));
    b.appendChild(document.createTextNode(m.text || ''));
    list.appendChild(b);
    list.scrollTop = list.scrollHeight;
    if (m.id) last = m.id;
  }
  function poll() {
    if (!token || !list) return;
    req('GET', '/messages?token=' + encodeURIComponent(token) + '&after=' + encodeURIComponent(last))
      .then(function (j) { (j.messages || []).forEach(add); }).catch(function () {});
  }
  function buildChat(v) {
    list = el('div', { class: 'l', 'aria-live': 'polite' });
    var disclosure = el('div', { class: 'd' }, cfg.disclosure || '');
    if (!cfg.disclosure) disclosure.style.display = 'none';
    var error = el('div', { class: 'e', role: 'alert' }, '');
    var form = el('form', { class: 'c' });
    input = el('textarea', { 'aria-label': 'Your message', placeholder: 'Type your message…', maxlength: '2000' });
    var send = el('button', { class: 's', type: 'submit' }, 'Send');
    form.appendChild(input); form.appendChild(send);
    v.appendChild(list); v.appendChild(disclosure); v.appendChild(error); v.appendChild(form);
    input.addEventListener('keydown', function (e) { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); form.requestSubmit ? form.requestSubmit() : form.dispatchEvent(new Event('submit')); } });
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var text = input.value.trim();
      if (!text) return;
      send.disabled = true; error.textContent = '';
      req('POST', '/messages', { token: token, body: text }).then(function (j) {
        input.value = '';
        if (j.token && j.token !== token) { token = j.token; try { localStorage.setItem(STORE, token); } catch (e) {} }
        (j.messages || []).forEach(add);
      }).catch(function (err) { error.textContent = err.message || 'Message not sent. Please try again.'; })
        .then(function () { send.disabled = false; });
    });
    if (!token) add({ from: 'business', text: cfg.greeting });
  }

  // Contact details shared by booking and the contact form --------------------------------------
  function contactFields(form) {
    var name = el('input', { type: 'text', autocomplete: 'name', maxlength: '100', required: 'required' });
    var phone = el('input', { type: 'tel', autocomplete: 'tel', maxlength: '40' });
    var email = el('input', { type: 'email', autocomplete: 'email', maxlength: '150' });
    form.appendChild(field('Your name', name));
    form.appendChild(field('Phone', phone));
    form.appendChild(field('Email (if you prefer)', email));
    form.appendChild(honeypot());
    return function () { return { name: name.value, phone: phone.value, email: email.value, website: form.querySelector('[name=website]').value }; };
  }
  function done(v, text) {
    v.textContent = '';
    var box = el('div', { class: 'sc' });
    box.appendChild(el('div', { class: 'ok', role: 'status' }, text));
    v.appendChild(box);
  }

  // Online booking ------------------------------------------------------------------------------
  function buildBooking(v) {
    var box = el('div', { class: 'sc' });
    var form = el('form');
    var service = el('select', {});
    var date = el('input', { type: 'date' });
    var grid = el('div', { class: 'g', role: 'group', 'aria-label': 'Available times' });
    var note = el('p', { class: 'n' }, 'Choose a day to see open times.');
    var address = el('input', { type: 'text', autocomplete: 'street-address', maxlength: '255' });
    var notes = el('textarea', { maxlength: '1000', rows: '2' });
    var error = el('div', { class: 'e', role: 'alert' }, '');
    var submit = el('button', { class: 's', type: 'submit' }, cfg.booking_confirm ? 'Request this time' : 'Book');
    var chosen = null, loaded = false, seq = 0;
    var serviceField = field('Service', service);
    form.appendChild(serviceField);
    form.appendChild(field('Day', date));
    form.appendChild(note); form.appendChild(grid);
    var contact = contactFields(form);
    form.appendChild(field('Address for the visit (if any)', address));
    form.appendChild(field('Anything we should know?', notes));
    form.appendChild(error); form.appendChild(submit);
    box.appendChild(form); v.appendChild(box);

    function times() {
      grid.textContent = ''; chosen = null;
      if (!date.value) return;
      note.textContent = 'Loading…';
      var mine = ++seq;   // only the latest lookup fills the list
      req('GET', '/booking/slots?date=' + encodeURIComponent(date.value) + '&service=' + encodeURIComponent(service.value || ''))
        .then(function (j) {
          if (mine !== seq) return;
          grid.textContent = '';
          note.textContent = j.times.length ? 'Pick a time:' : 'No open times that day. Try another day.';
          j.times.forEach(function (t) {
            var b = el('button', { type: 'button', 'aria-pressed': 'false', 'data-value': t.value }, t.label);
            b.addEventListener('click', function () {
              chosen = t.value;
              Array.prototype.forEach.call(grid.querySelectorAll('button'), function (x) { x.setAttribute('aria-pressed', x === b ? 'true' : 'false'); });
            });
            grid.appendChild(b);
          });
        }).catch(function (e) { note.textContent = e.message; });
    }
    v.load = function () {
      if (loaded) return;
      loaded = true;
      req('GET', '/booking/services').then(function (j) {
        var today = new Date();
        date.min = today.getFullYear() + '-' + String(today.getMonth() + 1).padStart(2, '0') + '-' + String(today.getDate()).padStart(2, '0');
        if (!j.services.length) { serviceField.style.display = 'none'; }
        service.appendChild(el('option', { value: '' }, 'Choose a service'));
        j.services.forEach(function (s) { service.appendChild(el('option', { value: String(s.id) }, s.name + ' (' + s.minutes + ' min)')); });
      }).catch(function (e) { note.textContent = e.message; });
    };
    service.addEventListener('change', times);
    date.addEventListener('change', times);
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      error.textContent = '';
      if (!chosen) { error.textContent = 'Please pick a time.'; return; }
      var body = contact();
      body.service = service.value; body.starts_at = chosen; body.address = address.value; body.notes = notes.value;
      submit.disabled = true;
      req('POST', '/booking', body).then(function (j) { done(v, j.message); })
        .catch(function (err) { error.textContent = err.message; if (err.data && err.data.times) times(); })
        .then(function () { submit.disabled = false; });
    });
  }

  // Click to call ------------------------------------------------------------------------------
  function buildCall(v) {
    var box = el('div', { class: 'sc' });
    box.appendChild(el('p', { class: 'n' }, 'Prefer to talk? Call us and a real person will answer.'));
    if (/^\+\d{8,15}$/.test(cfg.phone.tel)) box.appendChild(el('a', { class: 'call', href: 'tel:' + cfg.phone.tel }, '📞 ' + cfg.phone.label));
    v.appendChild(box);
  }

  // Contact form --------------------------------------------------------------------------------
  function buildLead(v) {
    var box = el('div', { class: 'sc' });
    var form = el('form');
    form.appendChild(el('p', { class: 'n' }, 'Leave your details and we\'ll get back to you.'));
    var contact = contactFields(form);
    var message = el('textarea', { maxlength: '2000', rows: '4', required: 'required' });
    form.appendChild(field('How can we help?', message));
    var error = el('div', { class: 'e', role: 'alert' }, '');
    var submit = el('button', { class: 's', type: 'submit' }, 'Send');
    form.appendChild(error); form.appendChild(submit);
    box.appendChild(form); v.appendChild(box);
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      error.textContent = '';
      var body = contact();
      body.message = message.value; body.page = String(location.href).slice(0, 300);
      submit.disabled = true;
      req('POST', '/lead', body).then(function (j) { done(v, j.message); })
        .catch(function (err) { error.textContent = err.message; })
        .then(function () { submit.disabled = false; });
    });
  }

  req('GET', '/config').then(function (c) {
    cfg = c;
    var f = c.features || { chat: true };
    title.textContent = c.title || 'Chat with us';
    sub.textContent = c.business || '';
    paint(/^#[0-9a-fA-F]{6}$/.test(c.color || '') ? c.color : '#7C3AED');
    if (f.chat) buildChat(addView('chat', 'Chat'));
    if (f.booking) buildBooking(addView('book', 'Book'));
    if (f.call && c.phone) buildCall(addView('call', 'Call'));
    if (f.lead) buildLead(addView('lead', 'Message'));
    var names = Object.keys(views);
    if (!names.length) return;
    if (names.length === 1) tabs.style.display = 'none';
    if (!f.chat) button.textContent = f.booking ? '📅' : (f.call && c.phone ? '📞' : '✉️');
    tab = names[0];
    show(tab);
    document.body.appendChild(root);
  }).catch(function () { /* not allowed on this site, or switched off */ });
})();
@endverbatim
