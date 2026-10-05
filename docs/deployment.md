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

## Release P2-1 to P2-3 (business, services, customers) — specific notes

- **Composer dependency added:** `giggsey/libphonenumber-for-php-lite`. Refresh `vendor/`.
- Migrations: business profile tables, services, customers/tags/timeline, `call_logs.customer_id`, plus a **customer backfill** from existing calls. Preview it with `php artisan customers:backfill --dry-run`. Rehearsed on a copy of real data: every call with a phone or email linked, and repeat callers deduplicated.
- Rebuild and upload `public/build/`.
- Ask each client to set their **timezone and hours** under *Business*. Until then, times show in UTC.

## Release "accounts" (D24): specific notes

- **Composer dependencies added:** `pragmarx/google2fa` and `bacon/bacon-qr-code`. Run `composer install --no-dev -o`.
- **Migration:** adds two-step sign-in and session columns on `users`, plus `organization_invitations` and `legal_acceptances`.
- **Email must work.** Password resets, invitations and email confirmation are sent straight away. Check `MAIL_*` and send yourself a reset from `/forgot-password` after deploying.
- **Recommended: `SESSION_DRIVER=database`.** "Where you're signed in" can then list devices. Signing out everywhere works with any driver.
- **After deploying:**
  - Everyone is asked to accept the Terms and Privacy Policy (plus the DPA for businesses) at their next page view.
  - Staff and agents are asked to set up two-step sign-in at their next sign-in. Tell them in advance: they need an authenticator app on their phone.
  - The mobile app needs a code field for two-step sign-in (docs/api.md). Until the app has one, staff and agents can't sign in to the app.

## Release "setup wizard and results" (D25): specific notes

- **Migration:** adds `setup_progress`, `setup_completed_at`, `average_job_value_cents` and `last_report_month` to `organizations`. Businesses that already have hours or services are marked as set up, so they aren't sent into the wizard.
- **Scheduler:** `reports:monthly` runs daily at 14:10 UTC and sends each business last month's report once. The existing cron for `schedule:run` covers it. Try it with `php artisan reports:monthly`.
- **Email:** reports attach a PDF (dompdf, already installed).

## Release "support and communication" (D26): specific notes

- **Migrations:**
  - `audit_logs.impersonator_id`, plus a permission re-sync for `users.impersonate`.
  - On `users`: daily-summary and quiet-hours columns.
  - New `message_templates` table, and `appointments.reminder_sent_at`.
  - `customers.merged_into_id` and `business_profiles.closed_from`.
- **Scheduler:** two new commands run every 15 minutes through the existing `schedule:run` cron: `notifications:daily-summary` and `appointments:send-reminders`.
- **Queue:** quiet hours delay emails, so the queue worker must process delayed jobs. A worker started every minute by cron does that.
- **Email:** customers now receive appointment emails from the `MAIL_FROM_ADDRESS`, showing the business's name, with replies going to the business. Make sure that address's domain has SPF and DKIM set up.

## Release "call quality" (D27) and "shift requests" (D28): specific notes

- **Migrations:** new `qa_reviews` table, plus a permission re-sync for `qa.review`; new `shift_requests` table.
- **Optional `.env`:** `SCHEDULE_MIN_AGENTS` (default 1) sets how many agents an hour needs before the coverage grid stops marking it as a gap.
- **Scheduler:** `quality:sample` runs daily at 05:40 UTC through the existing `schedule:run` cron.
- **Assets:** run `npm ci && npm run build` (new styles on the review form).

## Production `.env` settings to check

| Key | Value |
|---|---|
| `APP_ENV` / `APP_DEBUG` | `production` / `false` |
| `LOG_LEVEL` | `warning` (currently `debug`) |
| `SESSION_SECURE_COOKIE` | `true` |
| `SANCTUM_TOKEN_EXPIRATION` | `43200` (minutes) |
| `TENANCY_AUTO_ASSIGN_AGENTS` | `true` until ops manages agent assignments (D3) |
| `SESSION_DRIVER` | `database` (device list on Account › Security) |
| `IDLE_TIMEOUT_STAFF` / `IDLE_TIMEOUT_AGENTS` | minutes, default `30` (D8) |
| `TWO_FACTOR_ISSUER` | name shown in authenticator apps, default `SureHelp` |

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
| `tasks:notify-overdue` (one reminder per overdue task) | every 5 minutes |
| `escalations:remind` (urgent escalations unacknowledged after 15 min, once) | every minute |

Failed jobs: `php artisan queue:failed`, retry with `php artisan queue:retry all`.

## Rollback

`php artisan migrate:rollback --step=N` reverses the migrations above (tested on MariaDB 10.4 with legacy data). Restoring the step-1 backup is the safest full rollback.
