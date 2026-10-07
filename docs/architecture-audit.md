# Architecture Audit — Phase 0

_Audited 2026-10-03 against `SureHelpSolution_Enterprise_Task.md` §121. Read-only audit; no production behaviour was changed in this phase._

Companion documents:
- [current-state.md](current-state.md) — what exists today (routes, schema, dashboards, APIs), mapped feature by feature.
- [implementation-plan.md](implementation-plan.md) — proposed Phase 1 plan and the decisions needed before starting.

Severity scale: **Critical** (exploitable now / data exposure) · **High** (security or integrity gap that blocks multi-tenancy) · **Medium** (correctness / reliability) · **Low** (debt, polish).

---

## 1. Summary

The app is a small, working **Laravel 12** monolith (~5 models, ~10 controllers, 3 large self-contained Blade dashboards, a `/api/v1` JSON API for a mobile app). It is a **single-tenant** system: a "client" is a `users` row with `role = client`, and the only link between a call and a business is a free-text `call_logs.client_id` string. There is no organization model, no permission system beyond a 3-value role column, no calendar, billing, CRM, messaging, or AI code.

That is a reasonable MVP, but almost every target capability depends on an **organization + permission foundation that does not exist yet**. Phase 1 must build that foundation first and migrate the existing data into it without breaking the agent/client dashboards or the mobile API.

Before any feature work, there are **three issues to fix immediately** (§2).

---

## 2. Immediate risks (fix before Phase 1)

> **Update 2026-10-03:** R1 and R2 have code fixes (`.htaccess` deny rules, verified locally: `/.env` went from 200 to 403), as do R4 and R5 and the related items, in hotfixes HF-1 to HF-12 (see [implementation-plan.md §2](implementation-plan.md#2-p10--hotfixes-do-now-independent-of-phase-1)). R3 (email matching on client dashboards) is **closed** by P1-2: client queries are scoped by organization only. Push notifications no longer use the email match. Secret rotation and log purging still need doing on the server. Dependencies were updated in P1-1, clearing **46 security advisories**, among them high-severity CRLF injection in Laravel's `email` rule and in symfony/mime, both used by the public contact form.

| # | Severity | Finding | Evidence | Recommended action |
|---|---|---|---|---|
| R1 | **Critical** | **Project root is the web root and real files are served directly.** Root `.htaccess` rewrites to `public/` only when the file does *not* exist (`RewriteCond %{REQUEST_FILENAME} !-f`). `/.env`, `/surehelp.zip` (30 MB), `/public.zip`, `/error_log`, `/composer.lock`, and the `old …`/`SHS RAW` folders are therefore downloadable if production has this layout. | [/.htaccess](../.htaccess), [/index.php](../index.php) | Check `https://surehelpsolution.com/.env` in a browser. If it downloads: rotate `APP_KEY`, DB password, mail password. Point the document root at `public/` (cPanel → Domains), or add deny rules (see §2.1). Remove backups/old folders from the server. |
| R2 | **High** | **Missing role checks on web routes.** `POST /admin/call-logs`, `GET /admin/call-logs`, `GET /admin/kpi-data/{period}`, `GET /admin/clients/list` only require login. A client account can create call logs and list **every client's name, email and phone**. | [routes/web.php:43-48](../routes/web.php#L43-L48) | Add `role:agent,admin` to these routes (one-line change, no behaviour change for legitimate users). |
| R3 | **High** | **Cross-tenant data exposure by email match.** Client dashboards select `client_id = user.id OR caller_email = user.email`. If a client's email appears as the *caller* on another business's call, that call is shown to them. | [AdminController::clientDashboard](../app/Http/Controllers/AdminController.php), [Api/ClientDashboardController](../app/Http/Controllers/Api/ClientDashboardController.php) (4 places) | Remove the `caller_email` branch once existing rows have a reliable `client_id` (check data first — see plan §P1-0). Permanent fix is organization scoping (Phase 1). |
| R4 | **High** | **Plain-text password written to logs.** `storeUser` does `\Log::info('User creation request:', $request->all())`, which includes `password` and `password_confirmation`. `LOG_LEVEL=debug` in `.env`. | [AdminController::storeUser](../app/Http/Controllers/AdminController.php) | Remove the log line; purge `storage/logs/*.log` on the server. |
| R5 | **High** | **Push notification data can reach the wrong device.** `FcmService` falls back to `FCM_FALLBACK_TOKENS` when the target user has no token, so a client's call details would be pushed to whatever devices are configured as fallback. Payloads (caller details) are also written to the log. | [FcmService.php](../app/Services/FcmService.php) | Remove the fallback behaviour; stop logging payloads. |

### 2.1 Interim `.htaccess` hardening (if the docroot can't be changed)

Place at the top of the root `.htaccess`, before the existing rewrite:

```apache
RewriteEngine On
RewriteRule ^(\.env.*|\.git.*|composer\.(json|lock)|artisan|error_log|php\.ini|\.user\.ini|.*\.zip)$ - [F,L,NC]
RewriteRule ^(app|bootstrap|config|database|resources|routes|storage|tests|vendor|docs|old[^/]*|Old[^/]*|SHS[^/]*)(/|$) - [F,L,NC]
```

---

## 3. Audit checklist (§121 items 1–25)

### 3.1 Framework and version
Laravel **12.28.1** (`composer.lock`), Sanctum 4.2.0, Tinker. No other first-party packages (no Cashier, Socialite, Horizon, Reverb, Pennant, Scout). `vendor/` in this folder was installed with `--no-dev` (no PHPUnit/Pint/Collision), so tests cannot run in place.

### 3.2 PHP / runtime
- Local: PHP **8.2.12** (XAMPP, Windows). `pdo_sqlite`, `pdo_mysql` present; **`zip`, `intl`, `redis` extensions not enabled**; no `git`/`unzip` on PATH.
- Production: cPanel with **ea-php83** (from `.htaccess` session path). Apache + LiteSpeed directives present.
- `composer.json` requires `php ^8.2`.

### 3.3 Database
- **MySQL/MariaDB.** Local XAMPP client is MariaDB **10.4.32**. Production version not queried (read-only audit; no production DB connection made). `.env` points to `127.0.0.1:3306`, database `techhrsz_surehelp` — this `.env` appears to be a copy of the production file (`APP_ENV=production`, `APP_URL=https://surehelpsolution.com`).
- Sessions, cache, and queue all use the **database** driver.
- Tests run on in-memory SQLite (phpunit.xml).

### 3.4 Authentication
- **Web:** custom `AuthController::login` (AJAX JSON from the marketing site modal) → `Auth::login`, role-based redirect. Session cookie, 120 min lifetime. **No login throttling**, no "remember me", no email verification, no password-reset flow, no 2FA, no login history.
- **Forced first-login password change** (`must_change_password` + `ForcePasswordChange` middleware) for agent/client accounts created by an admin. Works.
- **API:** `POST /api/v1/login` issues a Sanctum personal access token named `auth-token`. **Tokens never expire** (`sanctum.expiration = null`), no device name, no rate limit, `refresh` endpoint rotates the token.
- Deactivation (`is_active = false`) is enforced on web requests (`RedirectGuestsToHome`) and at API login, **but not on existing API tokens** — a deactivated user's token keeps working.

### 3.5 Authorization
- Single `users.role` **enum (`admin`,`agent`,`client`)**, checked by `RoleMiddleware` (`role:agent,admin`) and by repeated inline `if ($user->role !== 'admin')` in API controllers.
- No policies, gates, permissions, or Form Requests anywhere.
- Gaps: R2 (unprotected routes), R3 (email-based scoping), agents can see **all** clients (no assignment model), admin can create other admins via API with no extra check, `/admin/users/debug` debug route is live.

### 3.6 Existing users / roles
`admin`, `agent`, `client`. `unique_id` auto-generated (`ADM`/`AGT`/`CLT` + 6 random digits). Client staff, supervisors, billing admins, etc. do not exist.

### 3.7 Organizations / tenants
**None.** A client business *is* its user account. Business name = user `name`. There is no place to store business profile, hours, services, timezone, or multiple users per business.

### 3.8 Agent Dashboard
See [current-state.md §4.1](current-state.md#41-agent-dashboard). Summary: KPI cards + call-volume chart (now real data, fixed in the previous pass), duty-schedule calendar (FullCalendar), and the **Call Log Entry** form, all in one 2,900-line Blade file. Notification bell shows a **hard-coded "3" and fake items**. "Export Data" has no real export.

### 3.9 Client Dashboard
See [current-state.md §4.2](current-state.md#42-client-dashboard). Summary card + service calendar + call history table, all data pre-loaded into the page (up to 500 calls) and filtered in the browser. No pagination, search, or detail view.

### 3.10 Existing API
`/api/v1` with 34 endpoints for login, client dashboard, agent dashboard, admin, duty schedules, and device tokens ([current-state.md §5](current-state.md#5-api-apiv1)). Responses already mostly follow `{success, data, message}` but inconsistently (some use `data`, some top-level keys, errors include raw exception messages). **All `/api/v1/*duty-schedules*` endpoints are broken**: they query `schedule_date`, `start_time`, `end_time`, `notes` columns that don't exist on `agent_duty_schedules` (which uses `start_datetime`/`end_datetime`/`description`) → SQL error on every call.

### 3.11 Database schema
See [current-state.md §3](current-state.md#3-database-schema). 13 app tables. Key problems:
- `call_logs.client_id` is a **`varchar` holding a user id**, no foreign key, no index.
- `call_logs.agent_name` is free text (duplicates `user_id`'s name).
- `call_logs.status` **enum lacks `completed`** while the agent form offers it → saving "Completed" fails validation.
- `call_date`/`call_time` stored separately with no timezone; `created_at` is the real timestamp.
- `agent_schedules` is an empty placeholder table (id + timestamps only), unused.
- `users.role` is an enum — adding roles requires a migration.
- No indexes on `call_logs (user_id, created_at)`, `(client_id, created_at)`, `caller_phone`.

### 3.12 Notification system
- **Push only** (FCM), and only when a call log is created **through the API** (`AgentDashboardController::createCallLog`). The web Call Log Entry form — the one agents actually use — **sends no notification**.
- No Laravel Notifications, no `notifications` table, no in-app/email/SMS notifications, no preferences.
- Notifications are sent **synchronously** inside the HTTP request.
- Email: only the marketing contact form (`ContactFormMail`), sent synchronously via SMTP.

### 3.13 Firebase / push
- `FcmService` posts to `https://fcm.googleapis.com/fcm/send` with `Authorization: key=<server key>` — Google's **legacy FCM HTTP API, which Google shut down in 2024**. Push should be assumed **non-functional** until migrated to FCM HTTP v1 (OAuth service-account). `FCM_SERVER_KEY` is not set in this `.env` either.
- Device tokens stored in `fcm_tokens` (user_id, token, platform, device_name). `POST /api/v1/notifications/device-token` re-assigns a token to whoever posts it (fine), but stale tokens are never cleaned up.
- See R5 for the fallback-token leak.

### 3.14 Calendar
- **No external calendar integration.** "Service Schedule Calendar" on the client dashboard is FullCalendar rendering call logs that have a `service_date`; `service_window` is free text parsed in JavaScript.
- Agent duty schedule calendar (`agent_duty_schedules`) is an internal shift planner, not an appointment calendar.
- No appointments table; an "appointment" is a call log with `call_outcome = scheduled-appointment`.

### 3.15 Billing
**None.** No Stripe, Cashier, plans, subscriptions, invoices, or usage tracking.

### 3.16 Integrations
Only: SMTP mail, FCM (legacy, broken). AWS S3 env keys exist but `FILESYSTEM_DISK=local`. No Twilio, Google, Microsoft, Stripe, or AI providers. `.env.example` is missing `MAIL_CONTACT_TO` and all `FCM_*` keys.

### 3.17 Routes
Web: 36 routes (landing, 7 legal pages, contact form, login/logout, `/admin/*` dashboards, duty-schedule resource, users, contact submissions, debug route). API: 34 routes under `/api/v1`. Plus framework routes (`/up`, `/sanctum/csrf-cookie`, `/storage/{path}`). Full list in [current-state.md §5–6](current-state.md#5-api-apiv1).

### 3.18 Controllers / services
- `AdminController` mixes **admin, agent and client** web dashboards plus user creation and call log storage (≈550 lines).
- `Api/*` controllers **duplicate** the web logic with small differences (e.g. API maps `new` → "Pending", web → "New").
- Services: `FcmService`; `CallStatsService` (added in the previous pass to unify agent KPIs).
- No Form Requests, Policies, Actions, Jobs, Events, Listeners, Notifications, or API Resources.

### 3.19 Frontend architecture
- Each portal page is a **standalone HTML document** (own `<head>`, inline `<style>` 700–1,100 lines, inline `<script>` 500–1,500 lines). They do not extend `layouts/app.blade.php` (that layout is used by the marketing site).
- Libraries loaded from 4 CDNs: Bootstrap **5.3.0 and 5.3.2** (mixed), Font Awesome 6.0, Chart.js (**unpinned** `npm/chart.js` → always latest major), FullCalendar 6.1.10, AOS. No Subresource Integrity hashes.
- Vite + Tailwind 4 are configured in `package.json` but **not used**; `public/build` does not exist.
- Dark navy/purple visual identity is consistent and worth keeping (spec §7).

### 3.20 Tests
Before this audit: only the two default example tests. Now: [tests/Feature/DashboardFixesTest.php](../tests/Feature/DashboardFixesTest.php) (8 tests, passing in a scratch checkout with dev dependencies). No CI. No factories besides `UserFactory`.

### 3.21 Technical debt
| Area | Debt |
|---|---|
| Tenancy | No organization model; identity = business. Blocks every Phase 2+ module. |
| Controllers | Fat controllers, duplicated web/API logic, inline validation. |
| Views | 3 monolithic dashboard files; no shared layout/components; repeated CSS. |
| Data | String FK `client_id`; free-text agent name, outcome, reason, service window; status/outcome strings scattered across PHP + JS. |
| Statuses | No enums (spec §76). Outcome values exist only as `<option>` tags in one Blade file. |
| Ops | No Git, no CI, deployed by file copy; backups and old copies in web root; `vendor` without dev deps. |
| Timezones | Everything in server UTC; client times displayed raw. No per-business timezone. |
| Dead code | `agent_schedules` table, `home_new.blade.php`, `welcome.blade.php`, `fix-encoding.php` in project root. |

### 3.22 Security risks (beyond §2)
| Severity | Risk |
|---|---|
| High | No login rate limiting (web or API) → credential stuffing. |
| High | Sanctum tokens never expire and survive account deactivation. |
| Medium | Raw exception messages returned in JSON (`'error' => $e->getMessage()`) across all API controllers → information disclosure. |
| Medium | DOM XSS: dashboards build HTML from server data with `innerHTML` template strings (client dashboard fixed in the previous pass; 3 spots remain in `agent-dashboard.blade.php`). |
| Medium | `/admin/users/debug` debug route enabled in production. |
| Medium | CDN scripts without SRI; unpinned Chart.js. |
| Medium | `ContactController` stores `sms_consent = true` for every submission regardless of what the visitor chose; consent is bundled with the privacy checkbox. This is a TCPA/consent-record concern for any future SMS use — legal review recommended. **Fixed 2026-10-23:** text-message consent is now a separate, optional checkbox and stored as given. |
| Medium | Session cookie `secure` flag depends on `SESSION_SECURE_COOKIE` (unset). |
| Low | Admin can create admins via API; no audit trail of any admin action. |
| Low | `unique_id` uses `mt_rand` (not security-relevant, but collides more than necessary). |

### 3.23 Performance risks
- Client dashboard runs ~15 count queries and loads up to **800 rows** (500 + 3×100) into every page render, then ships them as JSON.
- Admin API `getAgentPerformance` / analytics loop per agent / per day with separate count queries (N+1-style).
- No indexes on the columns used for every dashboard query (`call_logs.user_id+created_at`, `client_id+created_at`).
- Everything synchronous: mail and FCM HTTP calls happen inside the request.
- Database-backed cache and sessions on shared hosting.

### 3.24 Recommended refactoring
1. Introduce `organizations` and move every tenant record under `organization_id` (with a backfill from existing client users).
2. Replace the role enum with permission-based authorization (Spatie `laravel-permission`, team-scoped by organization) while keeping `role` readable for backward compatibility during migration.
3. Split `AdminController` into per-portal controllers; move logic into services/actions shared by web and API; add Form Requests and Policies.
4. A shared Blade layout + components (sidebar, topbar, card, table, empty state, toast, modal) per portal; Vite-built assets; pinned library versions.
5. Central enums for call status/outcome; `call_outcomes` table so outcomes are configurable (spec §14).
6. Queue all external I/O (mail, push) — database queue + cron worker works on cPanel.
7. Consistent API envelope + exception handler that never leaks internals.

### 3.25 Recommended implementation order
1. **P1-0 Hotfixes** (R1–R5, broken duty-schedule API, "Completed" status) — small, independent, deploy immediately.
2. **P1-1 Engineering baseline** — Git, CI, staging, test DB, `.env.example` completeness.
3. **P1-2 Organizations + data migration** (tenancy).
4. **P1-3 Permissions & policies** (roles → permissions, agent assignments).
5. **P1-4 Portal shells & shared UI components** (navigation per spec §6, existing pages moved inside).
6. **P1-5 Audit log + notification foundation** (queued).
7. **P1-6 API conventions** (envelope, resources, error handling, rate limits, token expiry).
8. Then Phase 2 (business profile, hours, services, customers, calls, appointments, tasks, escalations).

Details, affected files, migration strategy and tests: [implementation-plan.md](implementation-plan.md).
