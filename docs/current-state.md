# Current State

_Snapshot 2026-10-03. Describes what the application **actually does today**. Risks and recommendations are in [architecture-audit.md](architecture-audit.md)._

---

## 1. What the product is today

A marketing website plus three login-protected portals:

| Portal | URL | Who | What it does |
|---|---|---|---|
| Marketing site | `/`, legal pages | public | Landing page, contact form (stored + emailed), login modal |
| Admin | `/admin/dashboard` | `admin` | Stats cards, duty schedule management, user creation/reset/deactivate, contact submissions |
| Agent | `/admin/agent-dashboard` | `agent` (and `admin`) | KPIs + call-volume chart, own duty-schedule calendar, **Call Log Entry** form |
| Client | `/admin/client-dashboard` | `client` (and `admin`) | Business summary, service calendar, call history |
| Mobile API | `/api/v1/*` | Sanctum token | Same data for a mobile app (client, agent, admin) |

The core operational loop that works today:

```
Agent answers a call (outside the system, by phone)
  → fills the Call Log Entry form (picks client, caller details, reason, outcome, status, optional service date/window/location)
  → call_logs row created with server-generated ID CL-YYYYMMDD-NNNN
  → client sees it on their dashboard (summary counts, call history, calendar if a service date was set)
```

There is no telephony, appointment, calendar sync, CRM, messaging, billing or AI functionality.

---

## 2. Identity and access

| Concept | Implementation |
|---|---|
| User | `users` table; `role` enum `admin`/`agent`/`client`; `unique_id` like `AGT123456`; `is_active`; `must_change_password` |
| Business (tenant) | **No separate entity.** The client *user* is the business. |
| Web login | AJAX `POST /login` → session; redirect by role. First login forces password change for admin-created agent/client accounts. |
| API login | `POST /api/v1/login` → Sanctum token (no expiry). `GET /api/v1/user`, `POST /api/v1/refresh-token`, `POST /api/v1/logout`. |
| Authorization | `role:` middleware + inline role checks. No permissions/policies. |
| Agent ↔ client relationship | **None.** Every agent can pick any active client in the call log form. |
| Account creation | Admin only (dashboard modal or API). No self-signup, no invites, no password reset email. |

---

## 3. Database schema

```
users ─┬─< call_logs (user_id = agent)         call_logs.client_id = users.id as VARCHAR (no FK)
       ├─< agent_duty_schedules (agent_id)
       ├─< fcm_tokens (user_id)
       ├─< personal_access_tokens (morph)
       └─< sessions (user_id)

contact_submissions        (standalone)
call_id_sequences          (date PK → last_sequence)   added 2026-10-03
agent_schedules            (id, timestamps only — unused)
cache, cache_locks, jobs, job_batches, failed_jobs, password_reset_tokens   (framework)
```

### `call_logs` — the only business-data table

| Column | Type | Notes |
|---|---|---|
| `call_id` | varchar unique | `CL-YYYYMMDD-NNNN`, from `call_id_sequences` (race-safe) |
| `client_id` | varchar null | Client **user id as a string**; no FK/index |
| `call_date`, `call_time` | date, time | Entered by agent, no timezone |
| `caller_name`, `caller_phone`, `caller_email` | varchar null | No customer record; repeated per call |
| `reason_for_call` | varchar | Slug from a hard-coded `<select>` (13 values) |
| `call_outcome` | varchar | Slug from a hard-coded `<select>` (10 values) |
| `agent_name` | varchar | Free text (first name of logged-in agent) |
| `status` | enum | `new, service-requested, information-provided, cancelled, spam` (**no `completed`** although the form offers it) |
| `service_request` | bool | |
| `service_date` | date null | |
| `service_window` | varchar null | Free text, parsed in JS |
| `service_location` | text null | |
| `notes` | text null | |
| `user_id` | FK users | Agent who logged it |

### `agent_duty_schedules`
`agent_id` FK, `title`, `start_datetime`, `end_datetime`, `shift_type` (morning/afternoon/evening/night/off), `description`, `is_active`. Indexed. Overlap check on create/update (web).

### `fcm_tokens`
`user_id`, `token` unique, `platform`, `device_name`, `last_used_at`.

### `contact_submissions`
name, email, phone, company, inquiry_type, message, `sms_consent` (always stored as `true`), ip_address.

---

## 4. Portals in detail

### 4.1 Agent Dashboard
File: [resources/views/admin/agent-dashboard.blade.php](../resources/views/admin/agent-dashboard.blade.php) (standalone page, ~2,900 lines). Controller: `AdminController::agentDashboard`.

| Section | Data source | State |
|---|---|---|
| KPI cards (Total Calls, Service Requests, Conversion, Schedules, Callbacks) with Today/Week/Month toggle | `CallStatsService::kpis` via page render and `GET /admin/kpi-data/{period}` | Real data (fixed 2026-10-03) |
| Call volume chart (hourly / 7-day / week-of-month) | `CallStatsService::performance` | Real data (fixed 2026-10-03) |
| My Duty Schedule (FullCalendar week/day/month + hours summary) | `GET /admin/duty-schedules/calendar-data` | Real data |
| Call Log Entry form | `POST /admin/call-logs`; client search via `GET /admin/clients/list` | Works; no customer matching, no appointment creation, **no notification sent** |
| Notification bell | — | Shows "coming soon" (fake items removed, HF-10) |
| Export My Calls (CSV) | `GET /admin/call-logs/export` | Real download of the agent's own calls |
| Sidebar | Dashboard / My Duty Schedule / Call Log Entry (anchors), Manage Schedules (admin) | Dead links removed 2026-10-03 |

### 4.2 Client Dashboard
File: [resources/views/admin/client-dashboard.blade.php](../resources/views/admin/client-dashboard.blade.php) (~1,800 lines). Controller: `AdminController::clientDashboard`.

| Section | Data source | State |
|---|---|---|
| Top nav (Overview / Service Calendar / Call History) | anchors | Added 2026-10-03 |
| Business Summary (Total Calls, Service Requests, Scheduled, In Progress) Daily/Weekly/Monthly | pre-computed in controller | Real data |
| Service Schedule Calendar | call logs with service request / date, rendered client-side | Real data; no external calendar |
| Call History table | up to 500 calls embedded as JSON | Real status labels + `—` for empty fields (fixed 2026-10-03); no paging/search/detail |
| Calls matched by | `client_id = user.id` **OR** `caller_email = user.email` | See audit R3 |

### 4.3 Admin Dashboard
File: [resources/views/admin/dashboard.blade.php](../resources/views/admin/dashboard.blade.php). Controller: `AdminController::dashboard`.

| Section | State |
|---|---|
| Stats: total agents, active clients, calls today, success rate (vs yesterday) | Real data |
| Duty Schedules Management table (AJAX) | Real data |
| Recent Contact Form Submissions | Real data |
| Recent Calls table | Real data: last 8 call logs (HF-10) |
| Quick actions: add schedule, add user, manage users | Work. Dead "View All Calls", Calls, Analytics, Settings, Profile links removed |
| Users page `/admin/users` | List agents/clients, reset password, activate/deactivate |
| Duty schedules `/admin/duty-schedules` | Full CRUD with conflict checking |
| Contact submissions | List + detail |

---

## 5. API (`/api/v1`)

All JSON, Sanctum bearer token except login. Consumers: a mobile app (status of that app in production is **unknown** — must be confirmed before changing contracts).

| Method & path | Role | State |
|---|---|---|
| `POST /login`, `POST /logout`, `GET /user`, `POST /refresh-token` | any | Works |
| `GET /client/dashboard/summary?period=` | client | Works (email-match scoping, see R3) |
| `GET /client/call-history`, `/client/service-requests`, `/client/calendar` | client | Works; status "New" is returned as "Pending" for compatibility |
| `GET/PUT /client/profile` | client | Works (name, phone only) |
| `GET /agent/dashboard/kpi`, `/agent/dashboard/performance` | agent, admin | Works (shared `CallStatsService`) |
| `GET/POST /agent/call-logs`, `PUT /agent/call-logs/{id}` | agent, admin | Works; create sends FCM push (legacy API — see audit §3.13) |
| `GET /agent/clients` | agent, admin | Works (all active clients) |
| `GET /admin/dashboard/stats`, `/admin/analytics`, `/admin/agent-performance`, `/admin/call-logs` | admin | Works |
| `GET/POST/PUT/DELETE /admin/users` | admin | Works |
| `GET/POST/PUT/DELETE /admin/duty-schedules…`, `GET /duty-schedules…` | admin / agent | Works (fixed HF-7): maps `schedule_date`/`start_time`/`end_time`/`notes` to the real columns; also returns `title`, `start_datetime`, `end_datetime` |
| `POST /notifications/device-token` | any | Works |
| `POST /notifications/test` | admin | Calls legacy FCM endpoint |

Response shape is usually `{ success, message?, data }`, but error responses include `error: <exception message>`.

---

## 6. Web routes

Public: `/`, 7 legal pages, `POST /contact`, `POST /login`, `GET|POST /logout`.
Authenticated (`/admin` prefix, `auth.home` + `force.password.change`):

| Route | Guard |
|---|---|
| `GET dashboard` | role:admin |
| `GET agent-dashboard` | role:agent,admin |
| `GET client-dashboard` | role:client,admin |
| `POST/GET call-logs`, `GET call-logs/export` (CSV), `GET kpi-data/{period}`, `GET clients/list`, `GET duty-schedules/calendar-data` | role:agent,admin (HF-2) |
| `POST users` | role:admin |
| `POST duty-schedules/check-conflicts` | role:admin (HF-2) |
| `POST /login` (web and API) | throttled: 5/min per email+IP, 20/min per IP (HF-5) |
| `duty-schedules` resource, `users`, `users/{user}/reset-password`, `users/{user}/toggle-status`, `contact-submissions…` | role:admin |
| `GET/POST change-password` | login only |

---

## 7. Integrations and infrastructure

| Item | State |
|---|---|
| Mail | SMTP; used only for the contact form (sync) |
| Push | FCM legacy HTTP API (`fcm/send`, server key) — shut down by Google; `FCM_*` not in `.env` |
| Storage | local disk; S3 keys present but unused |
| Queue | `database` driver configured; **no jobs dispatched**, no worker/cron documented |
| Cache / sessions | database |
| Scheduler | none (`routes/console.php` only has `inspire`) |
| Hosting | cPanel (PHP 8.3), project root = web root, no Git, file-copy deploys |
| Frontend build | none; CDN libraries per page; Vite/Tailwind configured but unused |

---

## 8. Tests

| Suite | Coverage |
|---|---|
| `tests/Unit/ExampleTest`, `tests/Feature/ExampleTest` | framework defaults |
| [tests/Feature/DashboardFixesTest.php](../tests/Feature/DashboardFixesTest.php) | KPI/chart agreement, agent dashboard render, status labels, client call history render, empty-value display, call-ID sequencing (3 tests) |

10 tests / 35 assertions pass on SQLite in a checkout with dev dependencies. Cannot run in the deployed folder (no dev deps).

---

## 9. Changes already made before this audit (2026-10-03, from `task.md` Phase 0)

| Change | Files |
|---|---|
| Agent KPIs always showed 0 (mutated Carbon dates); "Today"/"Month" charts were hard-coded → one `CallStatsService` for web + API | `app/Services/CallStatsService.php`, `AdminController`, `Api/AgentDashboardController`, agent view |
| Client call history showed "Scheduled" for every call → `CallLog::statusLabel()` | `CallLog`, `AdminController`, `Api/ClientDashboardController`, client view |
| `null` shown for empty fields → `CallLog::display()` renders `—` | same |
| Browser-generated call IDs; race-prone server IDs → locked per-day counter | `CallLog::generateCallId`, migration `2026_10_03_000001_create_call_id_sequences_table` |
| Hard-coded `© 2025` → dynamic year | agent view, `partials/footer` |
| Dead sidebar links removed; low-contrast duty cards fixed; client top navigation added | agent view, client view |
| Duplicate `personal_access_tokens` migration broke fresh installs → guarded | `2025_10_14_054329_…` |
| Client table / event modal now HTML-escape values | client view |

## 10. Foundation added 2026-10-03 (P1-0 to P1-3)

The sections above describe the original behaviour. Since then:

- **Tenancy:** client businesses are `organizations`. Every call has an `organization_id`. Client screens and `/api/v1/client/*` show only their organization's data. Agents can only log calls for organizations they're assigned to (`agent_assignments`). New clients and agents are provisioned automatically.
- **Permissions:** spec §5 catalogue in `config/authorization.php`. Platform/service roles via Spatie, organization roles (owner/manager/staff) on the membership. See [permissions.md](permissions.md).
- **Security:** login throttling, 30-day API tokens, tokens revoked on deactivation, `.htaccess` lockdown, no internals in error responses, dependencies patched.
- **Quality:** 49 feature tests, Pint, Larastan level 5 (new code clean), CI workflow.
- **API additions (non-breaking):** `organization` object on `/api/v1/user` and `/api/v1/client/profile`. Duty-schedule endpoints fixed.

See [deployment.md](deployment.md) for the release steps. Earlier changes listed in §9 are also **not yet deployed** unless you have uploaded them. Deploy needs `php artisan migrate --force` and `php artisan view:clear`.
