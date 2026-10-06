/* SureHelp website chat (docs/inbox.md). Add to any page: <script src=".../api/chat/widget.js" data-surehelp-chat="KEY" async></script> */
(function () {
  var API = {!! json_encode(url('/api/chat')) !!};
@verbatim
  var script = document.currentScript || document.querySelector('script[data-surehelp-chat]');
  if (!script || window.__surehelpChat) return;
  window.__surehelpChat = true;
  var KEY = script.getAttribute('data-surehelp-chat');
  var STORE = 'surehelp-chat-' + KEY;
  var token = null, last = '', timer = null, open = false, cfg = null;
  try { token = localStorage.getItem(STORE); } catch (e) {}

  function el(tag, attrs, text) {
    var n = document.createElement(tag);
    for (var k in attrs || {}) n.setAttribute(k, attrs[k]);
    if (text != null) n.textContent = text; // text only: messages are never parsed as HTML
    return n;
  }
  function req(method, path, body) {
    return fetch(API + '/' + encodeURIComponent(KEY) + path, {
      method: method,
      headers: body ? { 'Content-Type': 'text/plain' } : {},
      body: body ? JSON.stringify(body) : undefined
    }).then(function (r) { return r.json().then(function (j) { if (!r.ok) throw new Error(j.message || 'Error'); return j; }); });
  }

  var root = el('div', { id: 'surehelp-chat' });
  var shadow = root.attachShadow ? root.attachShadow({ mode: 'open' }) : root;
  var style = el('style');
  style.textContent = [
    ':host{all:initial}',
    '.b{position:fixed;right:20px;bottom:20px;z-index:2147483000;width:56px;height:56px;border-radius:50%;border:0;color:#fff;cursor:pointer;box-shadow:0 6px 24px rgba(0,0,0,.25);font:600 22px system-ui}',
    '.p{position:fixed;right:20px;bottom:88px;z-index:2147483000;width:min(370px,calc(100vw - 40px));height:min(540px,calc(100vh - 120px));display:none;flex-direction:column;background:#fff;color:#111;border-radius:16px;box-shadow:0 12px 40px rgba(0,0,0,.25);overflow:hidden;font:14px/1.45 system-ui,-apple-system,Segoe UI,Roboto,sans-serif}',
    '.p.o{display:flex}.h{padding:14px 16px;color:#fff}.h b{display:block;font-size:15px}.h small{opacity:.9}',
    '.l{flex:1;overflow-y:auto;padding:12px;background:#f6f6f8}.m{max-width:80%;margin:6px 0;padding:8px 12px;border-radius:14px;white-space:pre-wrap;word-wrap:break-word}',
    '.you{margin-left:auto;color:#fff}.biz{background:#fff;border:1px solid #e5e5ea}.who{display:block;font-size:11px;color:#666;margin-bottom:2px}',
    '.d{font-size:12px;color:#555;padding:8px 12px;background:#fff;border-top:1px solid #eee}',
    'form{display:flex;gap:8px;padding:10px;border-top:1px solid #eee;background:#fff}textarea{flex:1;resize:none;border:1px solid #ccc;border-radius:10px;padding:8px;font:inherit;height:40px}',
    'button.s{border:0;border-radius:10px;padding:0 14px;color:#fff;font:600 14px system-ui;cursor:pointer}.e{color:#b00020;font-size:12px;padding:0 12px 8px}',
    '@media (prefers-reduced-motion:no-preference){.p.o{animation:in .15s ease-out}@keyframes in{from{opacity:0;transform:translateY(8px)}}}'
  ].join('');
  var button = el('button', { class: 'b', type: 'button', 'aria-label': 'Open chat', 'aria-expanded': 'false' }, '💬');
  var panel = el('div', { class: 'p', role: 'dialog', 'aria-label': 'Chat' });
  var head = el('div', { class: 'h' });
  var title = el('b', {}, 'Chat with us');
  var sub = el('small', {}, '');
  head.appendChild(title); head.appendChild(sub);
  var list = el('div', { class: 'l', 'aria-live': 'polite' });
  var disclosure = el('div', { class: 'd' }, '');
  var error = el('div', { class: 'e', role: 'alert' }, '');
  var form = el('form');
  var input = el('textarea', { 'aria-label': 'Your message', placeholder: 'Type your message…', maxlength: '2000' });
  var send = el('button', { class: 's', type: 'submit' }, 'Send');
  form.appendChild(input); form.appendChild(send);
  panel.appendChild(head); panel.appendChild(list); panel.appendChild(disclosure); panel.appendChild(error); panel.appendChild(form);
  shadow.appendChild(style); shadow.appendChild(button); shadow.appendChild(panel);

  function paint(color) {
    button.style.background = color; head.style.background = color; send.style.background = color;
    style.textContent += '.you{background:' + color + '}';
  }
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
    if (!token) return;
    req('GET', '/messages?token=' + encodeURIComponent(token) + '&after=' + encodeURIComponent(last))
      .then(function (j) { (j.messages || []).forEach(add); }).catch(function () {});
  }
  function toggle() {
    open = !open;
    panel.classList.toggle('o', open);
    button.setAttribute('aria-expanded', open ? 'true' : 'false');
    clearInterval(timer);
    if (open) { poll(); timer = setInterval(poll, 3000); input.focus(); }
  }
  button.addEventListener('click', toggle);
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

  req('GET', '/config').then(function (c) {
    cfg = c;
    title.textContent = c.title || 'Chat with us';
    sub.textContent = c.business || '';
    disclosure.textContent = c.disclosure || '';
    if (!c.disclosure) disclosure.style.display = 'none';
    paint(/^#[0-9a-fA-F]{6}$/.test(c.color || '') ? c.color : '#7C3AED');
    if (!token) add({ from: 'business', text: c.greeting });
    document.body.appendChild(root);
  }).catch(function () { /* widget not allowed on this site, or switched off */ });
})();
@endverbatim
