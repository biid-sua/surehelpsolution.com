# UI system

Stack ([decisions.md](decisions.md) D5): Blade + Livewire 3 + Alpine (bundled with Livewire) + Tailwind CSS 4, built with Vite. Fonts, icons, Chart.js and FullCalendar are bundled locally, so there are no runtime CDNs.

## Portals

**One UI (D23):** every screen exists once, in this design system. There are no classic or legacy screens. When a screen is rebuilt, the old one is deleted in the same change.

| Portal | URL | Screens |
|---|---|---|
| Business portal (clients) | `/app` | Dashboard, Calls, Calendar, Appointments, Customers, Tasks, Escalations, Business (profile, hours, services, knowledge, rules, outcomes, calendar sync), Billing, Team, Notifications, Data & privacy |
| Agent workspace | `/agent` | Businesses (per-business workspace with guided call entry), My calls, My schedule (with hand-over and time-off requests), Call quality |
| Admin console | `/admin` | Overview, Organizations, Users, Duty schedule (with requests and coverage), Call review, Escalations, Call quality (opens `/agent/quality`), Billing, Website enquiries, Audit log |
| Account | `/account/password` | First sign-in: replace the temporary password |

All of them use `layouts/portal`, except the account page, which is a standalone page built with the same tokens and components. The public website (`/`, legal pages) is the marketing site with its own layout until its redesign.

**Moving between pages (D106):** menu links use `wire:navigate`, so only the page changes; the
window, the menu and its scroll position stay put, and a thin bar shows while the next page loads.
The agent's phone is kept alive (`@persist('softphone')`), so a live call isn't cut off.

Navigation per portal: `App\Support\Navigation\PortalNavigation`, which is also the complete list of screens (the table above shows the main ones). Items are filtered by permission. Unbuilt items show as **Soon** (spec §95), and only the core set is shown to clients (spec §119).

## Design tokens

Defined once in `resources/css/app.css` (`@theme`), so Tailwind classes use them by name (`bg-surface`,
`text-muted`, `border-line`, `bg-brand-600`…). Never hard-code a colour in a view; add a token instead.
The values below are the default **Midnight** theme.

| Token | Value | Use |
|---|---|---|
| `canvas` | `#0b0c22` | Page background |
| `surface` | `#121331` | Cards, panels |
| `surface-2` | `#191b3f` | Inputs, hovered rows, nested panels |
| `surface-3` | `#22254f` | Raised controls, selected items |
| `line` | white at 8% | Hairline borders and dividers |
| `line-strong` | white at 16% | Input and control borders |
| `ink` | `#eef0fa` | Main text |
| `muted` | `#a9adcf` | Secondary text |
| `subtle` | `#8a8fb8` | Hints, captions, meta |
| `brand-200` to `brand-700` | `#c7d2fe` to `#4338ca` (indigo) | Buttons (`brand-600`), links and focus (`brand-300`/`400`) |
| `accent-400`, `accent-500` | `#c084fc`, `#a855f7` (purple) | Highlights, gradients with brand |
| `success` / `warning` / `danger` / `info` | `#34d399` / `#fbbf24` / `#f87171` / `#60a5fa` | Status colours (badges, alerts, stat changes) |
| `radius-card` | `1rem` | Card and panel corners |
| `shadow-card` | inset hairline + soft drop shadow | Cards |
| `font-sans` | Inter Variable, then system fonts | All text (bundled locally) |

- **Accessibility:** text tokens meet WCAG AA on the surfaces they're used on. A single `:focus-visible`
  outline (`brand-400`) serves keyboard users, and `prefers-reduced-motion` is respected.
- **Portal themes (D59):** `resources/css/themes.css` redefines the same tokens under
  `[data-theme="dark|light|ocean|forest|sunset"]`; `system` follows the device. The same palettes, as plain
  values for the mobile app, are in `config/themes.php` (`colors`); `ThemesTest` checks they match the CSS.
- **Public website themes (D59, D93):** `public/assets/css/site-themes.css`, keyed by `[data-site-theme]`.
  The default and whether visitors may change it are set under *Admin › Appearance*.
- **White-label (D82):** a business's logo, colours and sender name apply to *its own* customer-facing
  pages and emails (`App\Services\Branding`), never to the portals' tokens.
- **Charts and calendars** read the tokens too (`--fc-*` variables for FullCalendar in `app.css`).

## Components (`resources/views/components/ui/`)

| Component | Use |
|---|---|
| `x-ui.page-header` | Title, description, optional `back` link and `actions` slot |
| `x-ui.card` | Section container with optional title/description/actions |
| `x-ui.stat` | KPI tile: value, % change (`invert` when up is bad), hint, link |
| `x-ui.table` + `.sh-table` | Responsive data table (scrolls inside itself, never the page) |
| `x-ui.pagination` | Server-side pagination for Livewire `WithPagination` |
| `x-ui.badge` | Status pill; tones match `CallLog::statusTone()` |
| `x-ui.button` | primary / secondary / ghost / danger, link or button |
| `x-ui.alert` | info / success / warning / danger with correct ARIA role |
| `x-ui.empty-state` | What's missing, why, what to do next (spec §69) |
| `x-ui.confirm` | Accessible confirmation dialog with focus trap |
| `x-ui.toasts` | Global toasts: `$this->dispatch('toast', type: 'success', message: '…')` |
| `x-ui.icon` | Inline Heroicons (no icon font) |

Form fields use the `.sh-input` and `.sh-label` classes.

## Rules for new screens

1. Full-page Livewire component with `#[Layout('layouts.portal', ['portal' => '…'])]`.
2. **Client components use `ScopedToOrganization`**, and **admin components use `PlatformAdminOnly`**. Livewire update requests (`/livewire/update`) skip route middleware, so the component itself must re-establish tenant context and access on every request. Persistent middleware is registered too, as a second layer.
3. Authorize in `mount()` **and** in every action (`$this->authorize('permission', $organization)`).
4. Look up records **inside** the organization (`forOrganization`) and return 404, not 403, for other tenants' IDs.
5. Every list is server-paginated. Every screen has loading, empty and error states.
6. Heavy JS loads lazily (see `resources/js/app.js`: `loadChart`, `loadCalendar`).
7. **No inline `<script>` on portal pages (D106).** After an in-place page change the browser still
   uses the first page's security policy, whose nonce the next page doesn't share, so an inline
   script would be blocked. Put behaviour in an Alpine component (`Alpine.data` in `app.js`), pass
   data in attributes (`data-…` or `x-data="name(@js($data))"`), and clean up in its `destroy()`.
   `PortalNavigationTest` checks this.
