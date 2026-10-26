{{-- Public website colour theme (D59): remembered in this browser only (localStorage). Shown unless an
     administrator hid it under Admin › Appearance. --}}
<div class="site-theme-picker" id="site-theme-picker">
  <button type="button" class="site-theme-picker__toggle" aria-expanded="false" aria-controls="site-theme-panel" title="Colour theme">
    <i class="fas fa-palette" aria-hidden="true"></i><span class="visually-hidden">Change colour theme</span>
  </button>
  <div class="site-theme-picker__panel" id="site-theme-panel" role="radiogroup" aria-label="Colour theme" hidden>
    <p class="site-theme-picker__title">Colour theme</p>
    @foreach (config('themes.site') as $key => $theme)
      <button type="button" class="site-theme-picker__option" role="radio" aria-checked="false" data-site-theme-option="{{ $key }}">
        <span class="site-theme-picker__swatch" aria-hidden="true">@foreach ($theme['swatch'] as $color)<span style="background: {{ $color }}"></span>@endforeach</span>
        {{ $theme['label'] }}
      </button>
    @endforeach
    <p class="site-theme-picker__note">Saved in this browser only.</p>
  </div>
</div>
<script nonce="{{ Vite::cspNonce() }}">
  (() => {
    const KEY = 'surehelp.site-theme';
    const BASE = @json(\App\Support\Ui\Themes::SITE_BASE);
    const DEFAULT = document.documentElement.dataset.siteThemeDefault || BASE;
    const picker = document.getElementById('site-theme-picker');
    const toggle = picker.querySelector('.site-theme-picker__toggle');
    const panel = picker.querySelector('.site-theme-picker__panel');
    const options = [...picker.querySelectorAll('[data-site-theme-option]')];
    const current = () => document.documentElement.dataset.siteTheme || BASE;
    const mark = () => options.forEach((o) => o.setAttribute('aria-checked', String(o.dataset.siteThemeOption === current())));
    const open = (yes) => { panel.hidden = !yes; toggle.setAttribute('aria-expanded', String(yes)); if (yes) (options.find((o) => o.getAttribute('aria-checked') === 'true') || options[0]).focus(); };
    toggle.addEventListener('click', () => open(panel.hidden));
    document.addEventListener('click', (e) => { if (!picker.contains(e.target)) open(false); });
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && !panel.hidden) { open(false); toggle.focus(); } });
    options.forEach((option) => option.addEventListener('click', () => {
      const theme = option.dataset.siteThemeOption;
      if (theme === BASE) delete document.documentElement.dataset.siteTheme; else document.documentElement.dataset.siteTheme = theme;
      // Picking the site's default forgets the choice, so the visitor follows the default if it changes.
      try { theme === DEFAULT ? localStorage.removeItem(KEY) : localStorage.setItem(KEY, theme); } catch (e) { /* private mode: applies to this page only */ }
      mark();
    }));
    mark();
  })();
</script>
