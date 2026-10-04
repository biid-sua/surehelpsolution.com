# UI system

Stack ([decisions.md](decisions.md) D5): Blade + Livewire 3 + Alpine (bundled with Livewire) + Tailwind CSS 4, built with Vite. Fonts, icons, Chart.js and FullCalendar are bundled locally, so there are no runtime CDNs.

## Portals

**One UI (D23):** every screen exists once, in this design system. There are no classic or legacy screens. When a screen is rebuilt, the old one is deleted in the same change.

| Portal | URL | Screens |
|---|---|---|
| Business portal (clients) | `/app` | Dashboard, Calls, Calendar, Appointments, Customers, Tasks, Escalations, Business (profile, hours, services, knowledge, rules, outcomes, calendar sync), Billing, Notifications |
| Agent workspace | `/agent` | Businesses (per-business workspace with guided call entry), My calls, My schedule |
| Admin console | `/admin` | Overview, Organizations, Users, Duty schedule, Call review, Escalations, Billing, Website enquiries, Audit log |
| Account | `/account/password` | First sign-in: replace the temporary password |

All of them use `layouts/portal`, except the account page, which is a standalone page built with the same tokens and components. The public website (`/`, legal pages) is the marketing site with its own layout until its redesign.

Navigation per portal: `App\Support\Navigation\PortalNavigation`. Items are filtered by permission. Unbuilt items show as **Soon** (spec §95), and only the core set is shown to clients (spec §119).

## Design tokens (`resources/css/app.css`)

`canvas` / `surface` / `surface-2` / `surface-3` backgrounds, `line` / `line-strong` borders, `ink` / `muted` / `subtle` text, `brand-*` (indigo) and `accent-*` (purple). Text colours meet WCAG AA on the surfaces they're used on. A single `:focus-visible` outline serves keyboard users, and `prefers-reduced-motion` is respected.

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
