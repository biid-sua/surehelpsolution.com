# Stack

What Sure Help runs on, with the versions in use on 2026-10-27. The exact versions are always the ones in
`composer.lock` and `package-lock.json`; update this page when a major version changes. The reasons behind
these choices are in [decisions.md](decisions.md) (D5 front end, D6 hosting, D7 mobile API).

## Server

| Part | Version | Notes |
|---|---|---|
| PHP | 8.3 (CI and production); `^8.2` required | Needs the `zip` extension (data exports) |
| Laravel | 12.69 | Monolith: web portals, `/api/v1` for the mobile app, webhooks, scheduler |
| Livewire | 3.8 | Every portal screen is a full-page Livewire component |
| Laravel Sanctum | 4.3 | Mobile app and API-key sign-in |
| spatie/laravel-permission | 6.25 | Roles and permissions per business ([permissions.md](permissions.md)) |
| Anthropic PHP SDK | 0.54 | All AI features, behind `App\Services\Ai\Contracts\AiProvider` |
| dompdf | 3.1 | Invoice and report PDFs |
| google2fa, bacon-qr-code | 8, 3 | Two-step sign-in |
| libphonenumber (lite) | 9 | Phone number parsing |

Twilio, Stripe, Google, Microsoft, Meta, LinkedIn, TikTok and Firebase are called over HTTPS with
Laravel's HTTP client, each behind its own service class, with a `fake` driver for tests and local work.

## Front end

| Part | Version | Notes |
|---|---|---|
| Tailwind CSS | 4.3 | Design tokens in `resources/css/app.css` ([ui.md](ui.md)) |
| Vite | 7.3 | `npm run build`; output in `public/build` |
| Alpine.js | bundled with Livewire | No separate install |
| Chart.js, FullCalendar | 4.5, 6.1 | Loaded lazily (`resources/js/app.js`) |
| Inter | Fontsource, bundled | No font or script CDNs in the portals |
| Twilio Voice SDK | 2.18 | Agents' browser phone |

The public website (`/`) is separate: Bootstrap 5.3 and AOS from a CDN, plus plain JS and CSS in `public/assets/`.

## Data and infrastructure

- **Database:** MySQL/MariaDB in production; SQLite in tests and CI. Schema reference: [schema.md](schema.md).
- **Queue, cache, sessions:** the `database` driver today. Queued work runs through `queue:work --stop-when-empty`
  on the scheduler every minute (`routes/console.php`).
- **Scheduler:** one cron line, `php artisan schedule:run`, every minute.
- **Hosting now:** cPanel shared hosting. **Target (D6):** Laravel Cloud or Forge, with Redis, Horizon and
  Reverb. The code works on both.
- **Files:** the `local` disk (private) for uploads and generated files.

## Mobile app

A separate repository, `biid-sua/SHS_Mobile_App`, built with Flutter (Dart ^3.9). It uses only the `/api/v1`
API, which is kept backwards compatible (D7; guarded by `tests/Feature/ApiContractTest.php` and the `MobileApi*Test` files). Plan:
[mobile-app-plan.md](mobile-app-plan.md).

## Tooling and checks

| Tool | Version | Command |
|---|---|---|
| PHPUnit | 11.5 | `php artisan test` |
| Pint | 1.30 | `vendor/bin/pint --test` (fix: `vendor/bin/pint`) |
| PHPStan + Larastan | 2.2 + 3.12 | `vendor/bin/phpstan analyse --memory-limit=1G` |
| Composer audit | n/a | `composer audit` |

CI (`.github/workflows/`) runs all of these on PHP 8.3 and Node 22 for every pull request.
