# Deployment

Current host: cPanel (PHP 8.3), deployed by uploading files. Target hosting: [decisions.md](decisions.md) D6.

## Every release

1. **Back up the database** (cPanel → phpMyAdmin → Export, or `mysqldump --single-transaction`).
2. Upload the changed files, or the whole project except `.env`, `storage/` and the `old …` folders.
3. Install dependencies on the server, or upload the `vendor/` folder built locally with:
   ```
   composer install --no-dev --optimize-autoloader
   ```
   **Front-end assets:** build locally with `npm ci && npm run build` and upload the `public/build/` folder. It isn't in Git, and the server needs no Node.
4. Run:
   ```
   php artisan migrate --force
   php artisan optimize:clear
   php artisan optimize
   ```
5. Smoke-test: log in as admin, agent and client; save a call log; open the client dashboard.

## Release 2026-10-03 (P1-0 hotfixes + P1-1 to P1-3) — specific notes

- **Composer dependencies changed** (Laravel 12.28 → 12.69, Sanctum 4.3, new: spatie/laravel-permission, livewire/livewire). `vendor/` must be refreshed (step 3).
- Migrations run automatically, in order:
  1. `call_id_sequences`, `completed` call status
  2. `organizations`, `organization_user`, `agent_assignments`, `call_logs.organization_id`
  3. **data backfill**: one organization per client, all active agents assigned, calls attributed (D1–D3)
  4. permission tables + catalogue sync (admins → Super Admin, agents → Agent)

  Preview the backfill on a copy first with `php artisan tenancy:backfill --dry-run` (after the table migrations), or run `php artisan audit:call-ownership --details` beforehand.
- **Mobile users with tokens older than 30 days are signed out once** (token expiry, D8).
- Root `.htaccess` now blocks direct access to source and secrets. After deploying, confirm `https://surehelpsolution.com/.env` returns **403**.
- Server-side clean-up (once): rotate `APP_KEY`/DB/mail secrets if `.env` was ever reachable, delete `storage/logs/*.log`, remove `*.zip` and `old …` folders.

## Release P1-4 (new portals) — specific notes

- **New:** `public/build/` must be uploaded (built with `npm run build`). Without it, the new pages fail with a Vite manifest error.
- No new migrations.
- Clients now land on `/app` after login. Admins land on `/admin`. Agents are unchanged. `/admin/client-dashboard` redirects to `/app`.
- The old client dashboard page has been removed. The mobile API is unchanged.

## Release P1-5 (audit log + notifications) — specific notes

- Migrations: `audit_logs`, `notifications`, `notification_preferences`, permission catalogue re-sync (`audit_logs.view`).
- **Add the cron entry now** (cPanel → Cron Jobs). Without it, notifications and emails wait in the `jobs` table:
  ```
  * * * * * cd /home/<account>/<path> && php artisan schedule:run >> /dev/null 2>&1
  ```
- **Behaviour change:** business members start receiving notifications. Missed calls and callback requests are also **emailed** by default (D13). Check `MAIL_*` in `.env` and send a test before relying on it.
- Rebuild and upload `public/build/` (new UI: notification bell, settings, audit log).

## Release P1-6 (API conventions) — specific notes

- No migrations. Optional `.env`: `CORS_ALLOWED_ORIGINS`, `LOG_SECURITY_DAYS`.
- New log file `storage/logs/security-YYYY-MM-DD.log` (denied requests). Review it weekly.
- Mobile app: may now send `device_name` on login and use `/devices`. Nothing it already uses changed (contract-tested).

## Production `.env` settings to check

| Key | Value |
|---|---|
| `APP_ENV` / `APP_DEBUG` | `production` / `false` |
| `LOG_LEVEL` | `warning` (currently `debug`) |
| `SESSION_SECURE_COOKIE` | `true` |
| `SANCTUM_TOKEN_EXPIRATION` | `43200` (minutes) |
| `TENANCY_AUTO_ASSIGN_AGENTS` | `true` until ops manages agent assignments (D3) |

## Background work (queues and scheduler)

`routes/console.php` defines the schedule. One cron entry runs all of it:
```
* * * * * cd /home/<account>/<path> && php artisan schedule:run >> /dev/null 2>&1
```
| Job | When |
|---|---|
| `queue:work --stop-when-empty` (notifications, emails) | every minute |
| `model:prune` (audit retention) | daily 03:10 |
| `queue:prune-failed --hours=168` | daily 03:20 |

Failed jobs: `php artisan queue:failed`, retry with `php artisan queue:retry all`.

## Rollback

`php artisan migrate:rollback --step=N` reverses the migrations above (tested on MariaDB 10.4 with legacy data). Restoring the step-1 backup is the safest full rollback.
