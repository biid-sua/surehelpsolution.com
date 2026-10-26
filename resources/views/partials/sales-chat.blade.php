{{-- SureHelp's own sales assistant on the public site: answers questions, captures the lead and books a demo.
     Vanilla JS (public/assets/js/sales-chat.js); talks only to /api/sales-chat on this site. --}}
@if (config('sales.enabled'))
<link rel="stylesheet" href="{{ asset('assets/css/sales-chat.css') }}?v={{ @filemtime(public_path('assets/css/sales-chat.css')) }}">
<div id="sales-chat" class="sales-chat" data-endpoint="{{ url('/api/sales-chat') }}" data-avatar="{{ asset('assets/img/favicon.png') }}" data-teaser-paths="/,/pricing,/features,/how-it-works">
  <div class="sales-chat__teaser" hidden>
    <button type="button" class="sales-chat__teaser-text" data-sc="open">Questions about SureHelp? I can help or book a demo.</button>
    <button type="button" class="sales-chat__teaser-close" data-sc="dismiss-teaser" aria-label="Dismiss">&times;</button>
  </div>
  <button type="button" class="sales-chat__launcher" data-sc="toggle" aria-label="Chat with SureHelp" aria-expanded="false" aria-controls="sales-chat-panel">
    <svg class="sales-chat__icon-open" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 5.5A2.5 2.5 0 0 1 6.5 3h11A2.5 2.5 0 0 1 20 5.5v8a2.5 2.5 0 0 1-2.5 2.5H10l-4.2 3.6c-.5.4-1.3.1-1.3-.6V16A2.5 2.5 0 0 1 4 13.5v-8Z" fill="currentColor"/></svg>
    <svg class="sales-chat__icon-close" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"/></svg>
    <span class="sales-chat__dot" hidden></span>
  </button>
  <section id="sales-chat-panel" class="sales-chat__panel" role="dialog" aria-modal="false" aria-label="Chat with SureHelp" hidden>
    <header class="sales-chat__header">
      <img class="sales-chat__avatar" src="{{ asset('assets/img/favicon.png') }}" alt="" width="36" height="36">
      <div class="sales-chat__who">
        <p class="sales-chat__title">SureHelp</p>
        <p class="sales-chat__status"><span class="sales-chat__online" aria-hidden="true"></span><span data-sc-status>Typically replies instantly</span></p>
      </div>
      <button type="button" class="sales-chat__close" data-sc="close" aria-label="Close chat">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"/></svg>
      </button>
    </header>
    <p class="sales-chat__disclosure" hidden></p>
    <p class="sales-chat__debug" role="note" hidden></p>
    <div class="sales-chat__log" aria-live="polite" aria-relevant="additions" tabindex="-1"></div>
    <div class="sales-chat__error" role="alert" hidden><span></span> <button type="button" data-sc="retry">Try again</button></div>
    <form class="sales-chat__composer" novalidate>
      <label for="sales-chat-input" class="sc-sr-only">Type your message</label>
      <textarea id="sales-chat-input" rows="1" maxlength="600" placeholder="Type your message…" autocomplete="off"></textarea>
      <button type="submit" class="sales-chat__send" aria-label="Send">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 12 20 4l-6 16-2.5-6.5L4 12Z" fill="currentColor"/></svg>
      </button>
    </form>
    <p class="sales-chat__foot">By chatting you agree to our <a href="{{ route('legal.privacy-policy') }}">privacy policy</a>.</p>
  </section>
</div>
<script src="{{ asset('assets/js/sales-chat.js') }}?v={{ @filemtime(public_path('assets/js/sales-chat.js')) }}" defer></script>
@endif
