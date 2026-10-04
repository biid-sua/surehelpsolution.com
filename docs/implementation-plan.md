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
> **Status 2026-10-07: done for clients and admins.** Tailwind 4 + Livewire 3 + Vite toolchain; brand tokens; 14 `x-ui` components ([ui.md](ui.md)); permission-aware `layouts/portal` with skip link, mobile drawer and toasts. **Business portal `/app`:** dashboard (real KPIs, timezone-aware periods, chart, today's schedule, alerts), Calls (search, quick views, date range, server pagination, filtered CSV export), call detail, calendar (range-limited feed). **Admin console `/admin`:** overview, organizations (search, details/timezone, agent assignment management), call review queue (D2). Legacy client dashboard removed; login and header links go to the new portals. JS bundle is code-split (base 21 KB gzip). Tested in `ClientPortalTest` (13) and `AdminConsoleTest` (10); full-stack smoke test against MariaDB. **Not done yet:** agent workspace and the classic admin screens (users, duty schedules, contact forms) still use the previous UI. The agent workspace is rebuilt in Phase 2 with customers and appointments; the classic screens move with P1-5/P1-6.
- **Layouts:** `layouts/portal.blade.php` plus per-portal sidebars matching spec §6. Items that aren't built yet are hidden or shown as **Coming Soon** behind feature flags. Following spec §119, new clients see only Dashboard, Calls, Appointments, Customers, Calendar and Messages.
- **Components:** `x-card`, `x-kpi`, `x-data-table` (server-side paging/sort/search), `x-empty-state`, `x-toast`, `x-modal`, `x-confirm`, `x-badge`, `x-form.*` with inline validation and focus states, plus skeleton loaders.
- **Assets:** Vite build with pinned Bootstrap 5.3, Chart.js and FullCalendar; dark navy/purple tokens extracted into one SCSS/CSS file; no CDN runtime dependencies.
- **Migration of existing pages:** move the three dashboards inside the new layout **without changing behaviour**; extract inline CSS/JS into modules. Keep the old URLs (`/admin/agent-dashboard`, `/admin/client-dashboard`) as redirects to the new paths (`/agent`, `/app`, `/admin`).
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
- `ApiResponse` helper and exception renderer giving the spec §49 envelope, with consistent status codes and no internals.
- API Resources for User, Organization, CallLog, DutySchedule. Existing field names stay, new ones are added.
- Rate limits: login, general API, future public endpoints.
- Sanctum token expiry and device names (D8); `GET/DELETE /api/v1/tokens` to manage devices.
- `docs/api.md` generated with Scribe (or hand-maintained OpenAPI).
- **Tests:** contract tests that assert every existing `/api/v1` response still contains its current keys.
- **Maps to:** task.md FND-13, MOB-01/03.

### Phase 1 exit criteria
- Every tenant query is scoped by `organization_id`; isolation tests pass for web and API.
- No inline role checks remain; all writes go through Form Requests and Policies.
- All three portals use the shared layout; no hard-coded data on any screen.
- CI is green; staging matches production; `docs/` updated (architecture, database, permissions, api, deployment, testing).

**Rough size:** P1-0 ≈ 3–4 days · P1-1 ≈ 2 days · P1-2 ≈ 5–7 days · P1-3 ≈ 4–5 days · P1-4 ≈ 7–10 days · P1-5 ≈ 3–4 days · P1-6 ≈ 3 days, for **about 6–7 weeks for one developer**.

---

## 4. Later phases (outline, in spec order)

| Phase | Scope (spec §) | First steps | Main external dependency |
|---|---|---|---|
| 2 Core operations | §9–16, 20–25 | `business_profiles`, `business_locations`, `business_hours` (split shifts, holidays), `business_services` (money in minor units, §75), `customers` with phone normalisation (E.164) and dedupe, `customer_timeline_events`, `calls` (successor to `call_logs`, configurable `call_outcomes`), `appointments` with DB-level double-booking protection (§88), `tasks`, `escalations`, agent client workspace, knowledge base | none |
| 3 Calendar | §17–19 | `CalendarProvider` interface + Internal adapter first; `AvailabilityService` (heavily unit-tested: timezones, DST, buffers, holidays); then Google, then Microsoft adapters; watch/subscription renewal jobs | Google Cloud + Azure app registrations, OAuth verification |
| 4 Communication | §26–27, 42–43, 72–73 | conversations/messages abstraction, email + SMS templates, reminders, simple workflow engine | SMS provider + A2P 10DLC registration (long lead time; start early) |
| 5 Billing | §28–32 | Cashier on `Organization`, plans/add-ons as data, entitlements service, usage records, Stripe webhooks (idempotent) | Stripe account, US entity, tax advice |
| 6 AI foundation | §33–34, 38 | `AiService` provider adapter, tool registry with org-scoped authorization, Business Brain read model, usage metering, audit of tool calls | LLM vendor + DPA/BAA decisions |
| 7 AI products | §35–37 | website chatbot, copilot, call summaries | phase 6 |
| 8–9 Growth / social | §39–41 | provider adapters per platform | Google Business Profile, Meta app review |
| 10 Mobile | §66–67 | apps on the same API; FCM v1 push | Apple/Google developer accounts |

Each later phase starts with its own short plan (affected files, tables, API changes, risks, migration, tests) as spec §107 requires.

---

## 5. Mapping to `task.md`

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
