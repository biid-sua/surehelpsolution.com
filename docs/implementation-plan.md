# Implementation Plan

_Created 2026-10-03 after the Phase 0 audit. All decisions are made: see [decisions.md](decisions.md). Status is tracked in each section._

Inputs: [architecture-audit.md](architecture-audit.md), [current-state.md](current-state.md), [decisions.md](decisions.md), `SureHelpSolution_Enterprise_Task.md` (master spec).

---

## 1. Decisions

All decisions D1–D12 were made on 2026-10-03 (delegated by the product owner) and are recorded with reasons in **[decisions.md](decisions.md)**. In short:

| # | Decision |
|---|---|
| D1 | One organization per existing client user; that user becomes Business Owner |
| D2 | Backfill by `client_id`, then by a unique caller-email match (flagged for review), otherwise unassigned + admin review queue |
| D3 | Least-privilege agent assignments; migration assigns all active agents to all orgs (`source=migration`) so service continues |
| D4 | Client views scoped by organization only; no email matching |
| D5 | Blade + Livewire 3 + Alpine + Tailwind 4 + Vite; in-house `x-ui` component library on brand tokens |
| D6 | Target Laravel Cloud / Forge (US region, Redis, Horizon, Reverb, S3, staging); stays cPanel-compatible until moved |
| D7 | Mobile API treated as live; additive changes only, contract-tested |
| D8 | 30-day device tokens, idle timeouts for staff, mandatory 2FA for platform staff and agents |
| D9 | Super Admin, Operations Manager, Support Agent / Agent Supervisor, Agent / Business Owner, Business Manager, Staff via spatie/laravel-permission (teams) |
| D10 | Twilio behind a `TelephonyProvider` interface, as Phase 3b; start A2P 10DLC registration now |
| D11 | Enterprise spec is master; this plan is the tracker |
| D12 | ULIDs, integer money, UTC + org timezone, PHP enums, in-house audit log, Pint + Larastan + tests in CI |

---

## 2. P1-0 — Hotfixes (do now, independent of Phase 1)

Small, low-risk, no schema redesign. Each one can ship on its own.

> **Status 2026-10-03: HF-1 to HF-12 implemented.** Covered by [tests/Feature/HotfixesTest.php](../tests/Feature/HotfixesTest.php) (16 tests). Full suite: 26 tests pass. Migrations verified up and down on MariaDB 10.4.
> Still to do **on the server**: deploy, `php artisan migrate --force`, confirm `/.env` returns 403, rotate secrets if it was ever reachable, delete `storage/logs/*.log` (they contain passwords from earlier user creation), move the `.zip`/`old …` backups off the server, then run `php artisan audit:call-ownership --details` and send the output for decisions D1/D2/D4.
> Extra beyond the list: the fake "Export Data" button now downloads a real CSV of the agent's own calls; dead admin/agent menu links were removed; call push notifications no longer fall back to the caller-email match.

| ID | Fix | Files | Notes |
|---|---|---|---|
| HF-1 | Block direct access to non-public files (audit R1) | root `.htaccess` or cPanel docroot | **Ops action**; rotate secrets if `.env` was exposed |
| HF-2 | Add `role:agent,admin` to `/admin/call-logs`, `/admin/kpi-data`, `/admin/clients/list`; `role:admin` to `check-conflicts`; delete `/admin/users/debug` | `routes/web.php` | No behaviour change for legitimate users |
| HF-3 | Remove the password-logging line; purge logs | `AdminController::storeUser` | |
| HF-4 | Remove FCM fallback tokens and payload logging | `FcmService` | |
| HF-5 | Login throttling (web + API) | `routes/web.php`, `routes/api.php` (`throttle:login`), `AppServiceProvider` | |
| HF-6 | Revoke/deny API tokens of deactivated users | new middleware on `auth:sanctum` group; `toggleStatus` deletes tokens | |
| HF-7 | Fix `/api/v1/*duty-schedules*` to use `start_datetime`/`end_datetime`/`description` | `Api/DutyScheduleController` | Keep response field names (`schedule_date`, `start_time`, `end_time`, `notes`) derived from the real columns so the mobile contract stays the same |
| HF-8 | Allow `completed` call status | migration widening enum + validation in both controllers | Inspect data first; additive |
| HF-9 | Stop returning raw exception messages in JSON | `bootstrap/app.php` exception rendering for `api/*`; remove `'error' => $e->getMessage()` | |
| HF-10 | Replace hard-coded admin "Recent Activity" and agent notification bell with real data or "Coming soon" | `dashboard.blade.php`, `agent-dashboard.blade.php` | Spec §95 |
| HF-11 | Pin Chart.js version | agent view | |
| HF-12 | **Data check for D2** (read-only query, report counts) | artisan command `audit:call-ownership` | Feeds D1/D2/D4 |

Tests: route-authorization tests (client gets 403 on agent routes), throttle test, deactivated-token test, duty-schedule API tests, status `completed` test.

---

## 3. Phase 1 — Foundation

Ordered. Each step is releasable and backward compatible.

### P1-1 Engineering baseline
> **Status 2026-10-03: done, except Git, which isn't installed on this machine.** Dependencies updated within majors (Laravel 12.28 → 12.69; **46 security advisories → 0**, including a high-severity CRLF injection in Laravel's email rule and in symfony/mime). Added: CI workflow (`.github/workflows/ci.yml`: audit, Pint, Larastan, tests, MySQL migrate/rollback/migrate), `phpstan.neon` + baseline, `pint.json`, complete `.env.example`, extended `.gitignore`, API token expiry (30 days, D8).
- **What:** Git repository (main/develop), `.gitignore` covers `.env`, zips and old folders; GitHub Actions running Pint, Larastan (level 5 to start), PHPUnit (Pest later); staging environment; complete `.env.example` (`MAIL_CONTACT_TO`, `FCM_*`, future keys commented).
- **Files:** `.github/workflows/ci.yml`, `.env.example`, `phpstan.neon`, `pint.json`, `docs/deployment.md`, `docs/testing.md`.
- **Risk:** none to production.
- **Maps to:** task.md FND-03/04/05.

### P1-2 Organizations (tenancy) (D1–D4)
> **Status 2026-10-03: done.** `organizations`, `organization_user`, `agent_assignments`; `call_logs.organization_id` + `ownership_source`; `BelongsToOrganization` + `CurrentOrganization` + `tenant` middleware; `LogCall` action shared by web and API; `ProvisionUserTenancy` on every user creation; backfill runs as a migration (`tenancy:backfill --dry-run` to preview). Client email matching removed (audit R3 closed). Tested in `TenancyTest` (13 tests) and rehearsed on MariaDB with legacy data, including full rollback. **Moved to P1-4:** admin screens for the unassigned-call review queue and for managing assignments.
- **New tables**
  - `organizations`: `id`, `uuid` (public identifier), `name`, `slug` unique, `status` (`onboarding|active|paused|cancelled`), `timezone` (IANA, nullable until onboarding), `currency` char(3) default `USD`, `owner_user_id` FK, timestamps, soft deletes.
  - `organization_user`: `organization_id`, `user_id`, `status`, `invited_at`, `joined_at`; unique (organization_id, user_id).
  - `agent_assignments`: `organization_id`, `agent_user_id`, `is_primary`, `starts_at`, `ends_at`; unique active (organization_id, agent_user_id).
- **Changed tables (additive only)**
  - `call_logs`: add `organization_id` nullable FK + index `(organization_id, created_at)`. Keep `client_id` for API compatibility until the API moves to org ids.
  - Indexes: `call_logs (user_id, created_at)`, `call_logs (caller_phone)`.
- **Code**
  - `App\Models\Organization`, `App\Support\Tenancy\CurrentOrganization` (request-scoped, resolved server-side), `ResolveOrganization` middleware. Clients use their membership. Agents pass an organization in the route, verified against `agent_assignments`. Admins are explicit.
  - `BelongsToOrganization` trait: global scope when an organization is resolved, `organization()` relation, auto-fills `organization_id` on create from the context (never from the request).
  - Client dashboard queries switch to `organization_id`. The `caller_email` branch is removed (R3).
  - Agent call-log form: client picker lists **assigned** organizations. Server verifies the assignment on save.
- **Data migration:** a separate, re-runnable artisan command (`tenancy:backfill`), not inside the schema migration. It creates organizations from client users, memberships, agent assignments (per D3), and sets `call_logs.organization_id` from `client_id`. It runs in a transaction per organization, logs counts, and has a `--dry-run`. Rollback = drop the new columns/tables; the old `client_id` is untouched.
- **API:** unchanged paths. Add `organization` objects to `/api/v1/user` and the client endpoints (additive).
- **Risks:** misattributed calls (mitigated by D2 report + dry-run), agents losing access (mitigated by D3 default), N+1 from scopes (eager loading + tests).
- **Tests:** tenant isolation (org A user requests org B call → 404); an agent not assigned to org B can't log or read calls for it; backfill command on fixture data; dashboards render identical counts before and after backfill.
- **Maps to:** task.md FND-10.

### P1-3 Roles, permissions, policies (D9)
> **Status 2026-10-03: done** (with the D9a amendment, organization roles on the membership). Catalogue in `config/authorization.php`, `User::hasPermissionIn()` behind `Gate::before`, `permissions:sync` (also run by a migration), `CallLogPolicy`, `StoreCallLogRequest`. Reference: [permissions.md](permissions.md). Tested in `PermissionsTest` (10 tests). **Remaining:** convert the older inline `role !==` checks in the admin/duty-schedule controllers, alongside P1-4 when those screens move to the new shell.
- **Package:** `spatie/laravel-permission` with the **teams** feature (team = organization). Platform/service roles are global; organization roles are team-scoped. A short spike verifies global and team roles together before full adoption.
- **Permission list:** exactly the spec §5 list, seeded by a versioned seeder (`PermissionSeeder`) that is idempotent.
- **Role mapping for existing users:** `admin` → Super Admin; `agent` → Agent; `client` → Business Owner of their organization. `users.role` stays as the **portal type** (`admin`/`agent`/`client`) for routing and API compatibility. Actions are authorized by permissions.
- **Code:** policies for `CallLog`, `Organization`, `User`, `AgentDutySchedule`; Form Requests for every write endpoint; replace inline `if ($user->role !== …)` with `authorize()`/`can:` middleware.
- **Tests:** permission matrix test (each role × each permission); staff without `billing.view` gets 403 (spec §64); API cannot bypass UI (same policy on web and API routes).
- **Maps to:** task.md FND-11.

### P1-4 Portal shells and shared UI (D5)
> **Status 2026-10-07: done for clients and admins.** Tailwind 4 + Livewire 3 + Vite toolchain; brand tokens; 14 `x-ui` components ([ui.md](ui.md)); permission-aware `layouts/portal` with skip link, mobile drawer and toasts. **Business portal `/app`:** dashboard (real KPIs, timezone-aware periods, chart, today's schedule, alerts), Calls (search, quick views, date range, server pagination, filtered CSV export), call detail, calendar (range-limited feed). **Admin console `/admin`:** overview, organizations (search, details/timezone, agent assignment management), call review queue (D2). Legacy client dashboard removed; login and header links go to the new portals. JS bundle is code-split (base 21 KB gzip). Tested in `ClientPortalTest` (13) and `AdminConsoleTest` (10); full-stack smoke test against MariaDB. **Update 2026-10-11 (D23):** the agent workspace was rebuilt in P2-7, and the remaining previous screens (users, duty schedules, contact forms, agent dashboard, password page) were rebuilt and the old ones deleted in P1-7. The product has one UI.
- **Layouts:** `layouts/portal.blade.php` plus per-portal sidebars matching spec §6. Items that aren't built yet are hidden or shown as **Coming Soon** behind feature flags. Following spec §119, new clients see only Dashboard, Calls, Appointments, Customers, Calendar and Messages.
- **Components:** `x-card`, `x-kpi`, `x-data-table` (server-side paging/sort/search), `x-empty-state`, `x-toast`, `x-modal`, `x-confirm`, `x-badge`, `x-form.*` with inline validation and focus states, plus skeleton loaders.
- **Assets:** Vite build with pinned Bootstrap 5.3, Chart.js and FullCalendar; dark navy/purple tokens extracted into one SCSS/CSS file; no CDN runtime dependencies.
- **Migration of existing pages:** move the three dashboards inside the new layout **without changing behaviour**; extract inline CSS/JS into modules. Old URLs are not kept (D23: one UI, no redirects).
- **Risk:** visual regressions. Mitigate with screenshot comparison per page before and after.
- **Maps to:** task.md FND-12, FIX-06/08 follow-ups.

### P1-5 Audit log and notification foundation
> **Status 2026-10-04: done.** `audit_logs` + `App\Support\Audit\Audit` (redaction, request context, never breaks the audited action). Recorded: web/API logins, failures and blocks, logouts, user create/update/password change (on the model, so every path is covered), call create/update/attribution, organization edits, agent (un)assignment, tenancy backfill. Audit page `/admin/audit` (filters; `audit_logs.view`, platform only); 730-day retention via `model:prune`. Notifications: `NotificationEvent` enum (spec §27 names), `CallActivity` (queued, after commit) to members with `calls.view` in that business; in-app bell (polling) and email; per-user preferences page; scheduler + cron worker for cPanel. Also fixed: **session fixation**, since web login now regenerates the session. Tested in `AuditAndNotificationsTest` (16) and end-to-end on MariaDB (agent logs call → queue worker → client bell). Decision D13 recorded. **Not done yet:** SMS/push channels (need Twilio / FCM v1), real-time push (Reverb, after hosting move), authorization-failure logging (moved to P1-6 with the API exception handler).
- **Audit:** `audit_logs` table (`organization_id`, `actor_id`, `actor_type`, `action`, `subject_type`, `subject_id`, `old_values`, `new_values`, `ip`, `user_agent`, `created_at`). Use `spatie/laravel-activitylog` with an added `organization_id` column, or a lean in-house table; both satisfy spec §62. Recommendation: in-house, because it's small and matches the spec exactly. Covers login, failed authorization, user and role changes, call log create/update, impersonation later.
- **Notifications:** Laravel Notifications, `notifications` table, event names from spec §27 as constants (`call.logged`, `followup.created`, …). Channels now: `database` (in-app bell) and `mail`. Push channel later via FCM HTTP v1 (needs a Firebase service-account JSON). All queued.
- **Queue/scheduler on cPanel:** cron `* * * * * php artisan schedule:run`; the scheduler starts `queue:work --stop-when-empty --max-time=55` each minute. Document in `docs/deployment.md`.
- **First real use:** the web Call Log Entry sends `call.logged` to the organization's owner (fixing today's gap where only the API path notifies). The agent bell shows real notifications.
- **Tests:** notification dispatched and queued on call log; audit row written on user update; no secrets in audit payloads.
- **Maps to:** task.md FND-14, FND-16, NTF-01/02.

### P1-6 API conventions
> **Status 2026-10-04: done.** `ApiResponse` envelope; `ApiExceptionRenderer` gives every /api error the envelope with correct status and no internals (401/403/404/405/422/429/500). Denied requests are logged to the `security` channel (exceptions and role middleware). `throttle:api` (120/min). Restricted CORS. `SecurityHeaders` middleware (nosniff, frame, referrer, permissions, COOP, HSTS on HTTPS, no-store on API). Named, expiring device tokens with `/devices` list/revoke/revoke-others, all audited. Fixed: a token refresh was recorded as a login, and an API guest without an Accept header got a 500 (redirect to a missing `login` route). Contract tests for every existing endpoint (`ApiContractTest`) plus `ApiConventionsTest`. Reference: [api.md](api.md). **Deferred:** generated OpenAPI file (when the mobile team starts, Phase 10); CSP header (after the public website leaves CDNs); refactoring legacy API controllers onto API Resources (Phase 2, as their data moves to the new models).
- `ApiResponse` helper and exception renderer giving the spec §49 envelope, with consistent status codes and no internals.
- API Resources for User, Organization, CallLog, DutySchedule. Existing field names stay, new ones are added.
- Rate limits: login, general API, future public endpoints.
- Sanctum token expiry and device names (D8); `GET/DELETE /api/v1/tokens` to manage devices.
- `docs/api.md` generated with Scribe (or hand-maintained OpenAPI).
- **Tests:** contract tests that assert every existing `/api/v1` response still contains its current keys.
- **Maps to:** task.md FND-13, MOB-01/03.

### P1-7 One UI (D23)
> **Status 2026-10-11: done.** Product-owner requirement: one version of every screen.
> - Rebuilt in the design system:
>   - *Admin › Users*: add, temporary password shown once, reset, switch off, role change; staff accounts are Super Admin only.
>   - *Admin › Duty schedule*: week grid, overnight shifts, overlap check, copy last week.
>   - *Admin › Website enquiries*: open/handled inbox; new `handled_at` / `handled_by` columns.
>   - *Agent › My calls*: KPIs by period, history, CSV.
>   - *Agent › My schedule*.
>   - The first-login page at `/account/password`.
> - Deleted: the previous admin dashboard, users, duty-schedule and contact-form pages; the agent dashboard / classic call form; the old password page; unused `home_new` / `welcome` views; `AdminController` and the `Admin\*` web controllers, with their routes. The mobile API is unchanged.
> - Tests: `SingleConsoleTest` (9). Older tests moved to the new screens or the API.

### P1-8 Accounts (D24)
> **Status 2026-10-12: done.**
> - **Signing in:** a sign-in page in the design system (the website pop-up is removed); forgot/reset password by email (AUTH-01); email confirmation.
> - **Two-step sign-in (AUTH-02):** authenticator app with recovery codes and replay protection; mandatory for staff and agents on web and mobile API; Super Admin reset.
> - **Sessions (AUTH-03):** 30-minute idle timeout for staff and agents; sign out everywhere through the session epoch; device list and mobile-app sign-ins; sign-in history with method.
> - **Profile page (AUTH-06).**
> - **Team invitations for business owners (AUTH-04):** owner invites managers and staff; managers invite staff; roles; removal.
> - **Terms acceptance with version tracking (CMP-06).**
> - **Tests:** `AccountSecurityTest` (11); API and agent tests updated for mandatory two-step sign-in.
> - **Still open:** "Sign in with Google/Microsoft" (AUTH-05, needs the OAuth apps); the mobile app's code field.

### Phase 1 exit criteria
- Every tenant query is scoped by `organization_id`; isolation tests pass for web and API.
- No inline role checks remain; all writes go through Form Requests and Policies.
- All three portals use the shared layout; no hard-coded data on any screen.
- CI is green; staging matches production; `docs/` updated (architecture, database, permissions, api, deployment, testing).

**Rough size:** P1-0 ≈ 3–4 days · P1-1 ≈ 2 days · P1-2 ≈ 5–7 days · P1-3 ≈ 4–5 days · P1-4 ≈ 7–10 days · P1-5 ≈ 3–4 days · P1-6 ≈ 3 days, for **about 6–7 weeks for one developer**.

---

## 4. Phase 2 — Core business operations

Started 2026-10-04 on `develop`, one feature branch per step.

| Step | Scope (spec §) | Status |
|---|---|---|
| P2-1 | Business profile (§9), locations, business hours with split shifts, closed days, holidays/special hours, temporary closure, emergency availability (§10); `BusinessHours` engine (timezone + DST aware); client *Business* pages; `GET /api/v1/client/business` | **done** 2026-10-05 |
| P2-2 | Services (§11): duration, buffer, price in minor units + price type (fixed / from / quote / hidden), location, booking flag, required customer info, agent instructions; client *Services* page; API | **done** 2026-10-05 |
| P2-3 | Customers CRM (§12–13): E.164 phone normalisation, DB-enforced dedupe per business, statuses, tags, consent, timeline events; calls linked to customers (auto-match by phone, then email); backfill from existing calls; client *Customers* list + detail timeline; API | **done** 2026-10-05 (merge of duplicates: later, CRM-04) |
| P2-4a | Configurable call outcomes (§14): platform defaults plus per-business renames, switch-offs and custom outcomes, each with a fixed category (booked, information, callback, escalated, missed, spam, other) that drives KPIs, filters and notifications; *Business › Call outcomes* tab; agents see each business's own list | **done** 2026-10-06 |
| P2-4b | Tasks and follow-ups (§24, CLI-04): call-backs created automatically from callback-category calls, due one business hour after the call (next opening when closed); to-dos and follow-ups with priority, due time in the business's timezone and an assignee; *Tasks* screen (open, mine, overdue, done); tasks on customer and call pages; customer timeline entries; one overdue reminder per due date (`followup.overdue`), `task.assigned` notification; dashboard follow-ups count open tasks; backfill of waiting call-backs; API | **done** 2026-10-06 |
| P2-4c | Escalations (§25, NTF-03): the eight spec types, priority (urgent/high/normal), open → acknowledged → resolved with required resolution notes, assignee; raised automatically from escalated-category calls (agents pick the type); urgent ones always notify in-app and by email whatever the person's settings, plus one reminder after 15 minutes unacknowledged; *Escalations* screen, dashboard alert, call-page card, customer timeline; platform-wide *Escalations* view in the admin console; `escalations.view/create/resolve` permissions; API | **done** 2026-10-06 (SMS for urgent escalations arrives with messaging, P3) |
| P2-5 | Appointments (§16): confirmed / pending / tentative / completed / no-show / cancelled with allowed transitions, service, location, address, notes, source, customer and call links; **double booking refused** under a per-business row lock (§88, proven with 10 parallel processes on MariaDB: 1 booked, 9 refused) with free times suggested; per-location calendars; first `Availability` engine (§19: hours, holidays, closures, duration, buffer, existing bookings, minimum notice, DST-safe); *Appointments* screen (book with live free times, move, confirm, complete, no-show, cancel), calendar and dashboard schedule show appointments, customer page card, timeline entries, `appointment.*` notifications, completed job turns a lead into a customer; API incl. `/client/availability` and 409 on clashes | **done** 2026-10-07 (calendar sync and agent booking: P2-7 / Phase 3) |
| P2-6 | Knowledge base (§22): the eight content types, category, linked service, visibility (callers / agents and team / team only), pinned, active, updated by and when; *Business › Knowledge* tab with search and type filter. Business rules (§23) stored as data: instructions for agents plus enforced rules (latest booking time per service, booking window, service area by ZIP, details to collect before booking, automatic escalation by call reason); *Business › Rules* tab with each rule written as a sentence; rules shape the free times offered and bind agent/automated bookings; read-only API | **done** 2026-10-08 |
| P2-7 | Agent workspace (§20–21) on the new shell at `/agent` (agents land here after login): assigned businesses with open/closed status and local time, escalations, call-backs due and the next 24 hours across them; per-business workspace with the briefing (rules, agent-visible knowledge with search, services and prices with agent instructions, hours, today's appointments, customer history) next to guided call entry (§15): caller details, customer match by phone/email **confirmed by the agent before merging** (or "different person"), reason, the business's own outcomes, escalation type, booking against live free times under the business's hours and rules, follow-up task; call, booking and follow-up saved in one transaction. The previous form was removed in P1-7 (D23) | **done** 2026-10-08 |

## 4b. Phase 3 — Calendar

| Step | Scope (spec §) | Status |
|---|---|---|
| P3-1 | Google and Microsoft calendar sync (§17–18, CAL-01..06): `CalendarProvider` interface with Google (Calendar API v3) and Microsoft (Graph) adapters over plain HTTPS; OAuth connect / reconnect / disconnect with least-privilege scopes, state check, encrypted tokens never sent to the browser; choose the calendar bookings go to and the calendars that count as busy; appointments pushed on every change (create, move, cancel) without overwriting events edited externally (etag / If-Match → flagged); busy times mirrored (times only) and used by availability and the double-booking guard; push notifications (Google watch channels, Graph subscriptions) with a 10-minute polling safety net; lost access flagged once with owner alert and an agent warning; busy times shown on the calendar. **Update 2026-10-12:** the Calendar page is source-aware. Every entry is tagged SureHelp / Google / Outlook / Visit, booking tags show which calendars hold a copy (or where one was edited), and per-source chips with live connection status filter the feed. Setup: [calendar-sync.md](calendar-sync.md) | **done** 2026-10-09 (needs the Google / Microsoft app registrations to go live) |

## 4c. Phase 5 — Billing (started early, D22)

| Step | Scope (spec §) | Status |
|---|---|---|
| P5-1 | Plans as data (monthly/yearly, trial, feature keys, limits), subscriptions (trial → active → past due → cancelled; plan change at renewal; cancel at period end; billing day kept), invoices (gap-free numbers per year, billing-details snapshot, PDF via dompdf, printable page), payments ledger (partial payments, duplicate-reference guard, receipts), daily `billing:run` (trial conversion, renewals with catch-up, overdue reminders ×3 a week apart), Payoneer gateway (per-invoice or default payment link for card/ACH, receiving-account bank details), client *Billing* page ("I've paid", plan-switch request), *Admin › Billing* (needs-attention queue, record payment, void, one-off invoices, subscriptions, plans, payment settings, MRR/outstanding/overdue/collected), billing notifications, `Entitlements` service. Setup: [billing.md](billing.md) | **done** 2026-10-10 (payments confirmed by a person; automatic gateway later) |

## 4d. Onboarding and results (D25)

| Step | Scope (spec) | Status |
|---|---|---|
| ONB-1 | Setup wizard (ONB-01..05, 07, 08): 7 steps with progress bar, industry templates, saved per step into the real records; new owners start there; dashboard banner; admin progress column, filter and checklist; email to staff on finish. Phone setup (ONB-06) and payment (ONB-09) wait for Twilio and automatic payments | **done** 2026-10-13 |
| RPT-1 | Results page (RPT-01): answered calls, jobs booked, leads, after-hours calls caught, estimated revenue from the owner's average job value, outcomes, reasons, day × hour heatmap, month-over-month change; PDF download; monthly report email with PDF on the 1st (RPT-02), switchable per person | **done** 2026-10-13 |

## 4e. Support, communication and CRM (D26)

| Step | Scope (spec) | Status |
|---|---|---|
| SUP-T | View as client (ADM-05) with banner, private account pages and `impersonator_id` on every audit entry; global search (ADM-09) | **done** 2026-10-14 |
| NTF-4/7 | Daily summary email at each person's chosen time (owners on by default); quiet hours hold non-urgent emails | **done** 2026-10-14 |
| MSG-1 | Appointment emails to customers (§26–27): confirmation, reminder (chosen lead time), time changed, cancellation; editable templates with preview and test send; timeline entries | **done** 2026-10-14 (SMS after A2P 10DLC) |
| CRM-04 | Duplicate suggestions (same email or name), side-by-side compare, merge everything into the record a person chooses | **done** 2026-10-14 |
| CLI-06 | Vacation mode with planned dates, dashboard banner with "end it now", agent notices | **done** 2026-10-14 |

## 4f. Agent quality and scheduling (D27, D28)

| Step | Scope (task.md) | Status |
|---|---|---|
| SUP-04 | Call quality reviews: daily random sample per agent, pick by call ID or at random, five-point scorecard with weights and a must-pass compliance point, written feedback, agent notice and "Got it", team results per agent; `qa.review` permission | **done** 2026-10-15 (recordings after telephony; AI scoring AIX-03 later) |
| AGT-11 / SUP-03 | Shift hand-over and time-off requests from *My schedule*; approve/decline (with note) on *Duty schedule*; leave removes shifts and marks the days; coverage grid by hour with a minimum (D28) | **done** 2026-10-15 |

## 5. Later phases (outline, in spec order)

| Phase | Scope (spec §) | First steps | Main external dependency |
|---|---|---|---|
| 2 Core operations | §9–16, 20–25 | `business_profiles`, `business_locations`, `business_hours` (split shifts, holidays), `business_services` (money in minor units, §75), `customers` with phone normalisation (E.164) and dedupe, `customer_timeline_events`, `calls` (successor to `call_logs`, configurable `call_outcomes`), `appointments` with DB-level double-booking protection (§88), `tasks`, `escalations`, agent client workspace, knowledge base | none |
| 3 Calendar | §17–19 | `CalendarProvider` interface + Internal adapter first; `AvailabilityService` (heavily unit-tested: timezones, DST, buffers, holidays); then Google, then Microsoft adapters; watch/subscription renewal jobs | Google Cloud + Azure app registrations, OAuth verification |
| 4 Communication | §26–27, 42–43, 72–73 | conversations/messages abstraction, email + SMS templates, reminders, simple workflow engine | SMS provider + A2P 10DLC registration (long lead time; start early) |
| 5 Billing | §28–32 | P5-1 done with Payoneer (D22). Next: usage records, add-ons, automatic gateway with idempotent webhooks | for automatic payments: Stripe (US entity) or Payoneer Checkout; tax advice |
| 6 AI foundation | §33–34, 38 | `AiService` provider adapter, tool registry with org-scoped authorization, Business Brain read model, usage metering, audit of tool calls | LLM vendor + DPA/BAA decisions |
| 7 AI products | §35–37 | website chatbot, copilot, call summaries | phase 6 |
| 8–9 Growth / social | §39–41 | provider adapters per platform | Google Business Profile, Meta app review |
| 10 Mobile | §66–67 | apps on the same API; FCM v1 push | Apple/Google developer accounts |

Each later phase starts with its own short plan (affected files, tables, API changes, risks, migration, tests) as spec §107 requires.

---

## 6. Mapping to `task.md`

| task.md | Where it lands |
|---|---|
| FIX-01…08, FND-01 | Done (see current-state §9) |
| FND-02 (upgrade, fresh migrate) | Partly done; finish in P1-1 |
| FND-03/04/05 | P1-1 |
| FND-10 | P1-2 |
| FND-11, FND-12 | P1-3, P1-4 |
| FND-13 | P1-6 |
| FND-14, FND-16 | P1-5 |
| FND-15 (timezones), FND-17 (settings), FND-18 (feature flags) | P1-2 (org timezone) + Phase 2; flags in P1-4 |
| AUTH-* | HF-5/6, P1-6, Phase 2 security settings (§71) |
| ADM, ONB, AGT, CLI, CRM | Phase 2 |
| CAL | Phase 3 |
| TEL | after D10 |
| NTF | P1-5 + Phase 4 |
| BIL, ADD | Phase 5 |
| AI, AIX | Phases 6–7 |
| MKT, INT | Phases 8–9 |
| MOB | Phase 10 (API groundwork in P1-6) |
