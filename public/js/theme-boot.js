/*
 * Colour theme (D59): "Match my device" picks Light or Midnight before the page paints, and follows
 * the device when it changes. A plain file rather than an inline script, so the page head is the
 * same on every page and moving between portal pages (D106) doesn't run it again.
 */
(() => {
    const root = document.documentElement;
    const light = window.matchMedia('(prefers-color-scheme: light)');
    const apply = () => {
        if (root.dataset.themeChoice !== 'system') return;
        root.dataset.theme = light.matches ? 'light' : 'midnight';
        root.style.colorScheme = light.matches ? 'light' : 'dark';
    };
    apply();
    light.addEventListener?.('change', () => { apply(); window.dispatchEvent(new CustomEvent('sh-theme')); });
    window.shApplyTheme = apply;
})();
