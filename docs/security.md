# Browser security headers (D67)

Every response gets the baseline headers from `App\Http\Middleware\SecurityHeaders`:
- `X-Content-Type-Options: nosniff`, `X-Frame-Options: SAMEORIGIN` and `Referrer-Policy`;
- `Permissions-Policy` and `Cross-Origin-Opener-Policy`;
- HSTS when the request is HTTPS.

Pages also get a **Content-Security-Policy**, built by `App\Support\Security\ContentSecurityPolicy`.

## What the policy allows

| What | Allowed from | Why |
|---|---|---|
| Scripts | this site; inline scripts carrying this request's nonce; the website's CDNs (jsDelivr, unpkg, cdnjs) | injected `<script>` tags and inline `onclick` handlers don't run |
| `'unsafe-eval'` | — | Alpine (inside Livewire) evaluates `x-data` / `x-on` attributes |
| Styles | this site, inline styles, Google Fonts, the CDNs | colours and sizes set in `style` attributes |
| Images, audio, video | this site, `data:`, `blob:`, any HTTPS | social avatars, uploaded media, call recordings |
| Connections | this site, `*.twilio.com` (HTTPS and WebSocket) | Livewire, the browser phone |
| Frames | this site, YouTube (no-cookie), Vimeo | Agent University videos and lesson files |
| Plugins (`object-src`) | none | |
| Being framed (`frame-ancestors`) | this site only | clickjacking |

While `npm run dev` runs, the Vite dev server in `public/hot` is added automatically.

## Writing pages that work with it

- **Inline `<script>`:** give it the nonce: `<script nonce="{{ Vite::cspNonce() }}">`. Vite and Livewire add it to their own tags.
- **No `onclick="…"` attributes:**
  - in portal pages, use Alpine (`x-on:click`);
  - on the public website, use `data-call="functionName"` and, if needed, `data-arg="value"`. The site layout calls the global function on click.
- **Blade directives such as `@js()` aren't compiled inside the attributes of `<x-…>` components.** Put the value in a parent `x-data` instead.
- **A new outside service** (a script, a frame or an API the browser calls) must be added to `config/csp.php`.

## Settings (.env)

| Setting | Default | Meaning |
|---|---|---|
| `CSP_MODE` | `enforce` | `report` only reports (a quick rollback if a page breaks); `off` sends no policy |
| `CSP_REPORT` | `true` | browsers report blocked items to `/api/csp-report`; they go to the security log (`storage/logs/security.log`, `csp.violation`) |

After deploying, watch the security log for `csp.violation` entries for a few days. A real page broken by the policy shows the same page and directive again and again. Injection attempts show unfamiliar sources.
