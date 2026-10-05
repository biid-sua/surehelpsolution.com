# SureHelp API v1

Base URL: `https://surehelpsolution.com/api/v1`. All requests and responses are JSON. Mobile apps and the web portals share this backend ([decisions.md](decisions.md) D7).

**Compatibility promise:** existing paths and response fields are never removed or renamed within `v1`. Changes are additive only (new fields, new endpoints). `tests/Feature/ApiContractTest.php` enforces this. Clients must ignore fields they don't know.

---

## 1. Conventions

### Response envelope
```json
{ "success": true, "message": "Optional human message", "data": { } }
```
```json
{ "success": false, "message": "Validation failed", "errors": { "email": ["The email field is required."] } }
```
`message` is written for people and safe to show to users. Error responses never contain internal details.

### Status codes
| Code | Meaning | Body |
|---|---|---|
| 200 / 201 | OK | envelope with `data` |
| 401 | Not signed in, or token expired/revoked | `{"success":false,"message":"Unauthenticated."}` |
| 403 | Signed in but not allowed (wrong role, missing permission, deactivated account → `account_deactivated: true`) | envelope |
| 404 | Not found, **or belongs to another business** (deliberately indistinguishable) | `"Not found."` |
| 405 | Wrong HTTP method | envelope |
| 409 | Conflict (e.g. overlapping duty schedule) | envelope |
| 422 | Validation failed | envelope + `errors` per field |
| 429 | Rate limited | envelope + `retry_after` (seconds) + `Retry-After` header |
| 500 | Our fault | generic message; details only in server logs |

### Authentication
1. `POST /login` with `email`, `password`, and optionally `device_name` (e.g. `"Pixel 9"`). The name appears in the user's device list.
   - **Two-step sign-in (D24).** When it's on, also send `two_factor_code`: the 6-digit app code or a recovery code (`abcde-12345`).
     - Missing or wrong code: `401` with `two_factor_required: true`. Show a code field and send the login again with the code.
   - **Staff and agents without two-step sign-in** get `403` with `two_factor_setup_required: true`. They must set it up on the website first.
2. Send `Authorization: Bearer <token>` on every request.
3. Tokens expire after **30 days** (`data.expires_at`). Call `POST /refresh-token` before then. The old token is revoked and a new one with the same device name is returned.
4. Deactivated accounts get `403` with `account_deactivated: true`, and their token is revoked.

### Limits
- `POST /login`: 5 per minute per email+IP, 20 per minute per IP.
- Everything else: 120 requests per minute per user (per IP when signed out).

### Security
- No browser CORS except from our own site (`CORS_ALLOWED_ORIGINS` adds more).
- API responses are `Cache-Control: no-store`.
- Denied requests are logged to the security log.
- Every sign-in, sign-out, token refresh and device revocation is recorded in the audit log.

### Tenancy
A client user only ever sees their own business. The business is resolved on the server, so no endpoint accepts an organization ID. Agents can only act for businesses they are assigned to.

---

## 2. Endpoints

### Account and devices — any signed-in user
| Method | Path | Notes |
|---|---|---|
| POST | `/login` | `email`, `password`, `device_name?`, `two_factor_code?` → `data.user`, `data.token`, `data.token_type`, `data.expires_at`, `data.must_change_password` |
| POST | `/logout` | Revokes the current token |
| GET | `/user` | `data.user`, plus `data.organization` (`id` = public ULID, `name`, `status`, `timezone`, `currency`) for client users, `null` otherwise |
| POST | `/refresh-token` | New token for the same device → `data.token`, `data.expires_at` |
| GET | `/devices` | `data.devices[]`: `id`, `name`, `current`, `last_used_at`, `created_at`, `expires_at`. Token values are never returned |
| DELETE | `/devices/{id}` | Sign out one of **your** devices (others' → 404) |
| DELETE | `/devices` | Sign out every device except this one → `data.revoked` |
| POST | `/notifications/device-token` | Register a push token: `token`, `platform?`, `device_name?` |

### Business portal — role `client`, permission `calls.view`
| Method | Path | Notes |
|---|---|---|
| GET | `/client/dashboard/summary?period=daily\|weekly\|monthly` | `data.summary` (`total_calls`, `service_requests`, `total_scheduled`, `in_progress`), `data.period_range` |
| GET | `/client/call-history?period=all\|daily\|weekly\|monthly&limit&offset` | `data.call_logs[]` (snake_case and legacy camelCase keys), `data.pagination` |
| GET | `/client/service-requests?status=all\|pending\|in_progress\|completed\|scheduled` | `data.service_requests[]` |
| GET | `/client/calendar?start&end` | `data.events[]` |
| GET | `/client/business` | Permission `organization.view`. `data.business`, `data.location`, `data.hours` (weekday → list of `"9 AM – 5 PM"`), `data.upcoming_holidays[]`, `data.status` (`open`, `label`, `until`, `next_open`, `reason`) |
| GET | `/client/services?include_inactive=1` | `data.services[]`: `price_cents` + `currency` (integer minor units), `price_label`, `duration_minutes`, `buffer_minutes`, `required_fields` |
| GET | `/client/customers?search&status&per_page` | Permission `customers.view`. `data.customers[]` (`id` = ULID), `meta` (`page`, `per_page`, `total`, `last_page`) |
| GET | `/client/customers/{id}` | `data.customer` + `data.timeline[]` (latest 50) |
| GET | `/client/tasks?status=open\|overdue\|done\|all&mine&customer&per_page` | Permission `tasks.view`. Default `open`, most urgent first. `data.tasks[]`: `id` (ULID), `type` (`callback`, `follow_up`, `todo`), `title`, `description`, `priority` (`urgent`, `high`, `normal`, `low`), `status` (`open`, `in_progress`, `completed`, `cancelled`), `is_overdue`, `due_at`, `source`, `customer {id, name}`, `call_id`, `assigned_to {id, name}`, `completed_at`, `created_at`. Paged like customers |
| POST | `/client/tasks` | Permission `tasks.create`. `title` (required), `type`, `priority`, `description`, `due_at` (ISO 8601), `customer_id` (customer ULID), `assigned_to` (user id of an active team member). 201 with `data.task` |
| GET | `/client/tasks/{id}` | `data.task` |
| GET | `/client/knowledge?type&search` | Permission `knowledge_base.view`. Active items, pinned first: `data.items[] {id, type, title, content, category, visibility, pinned, updated_at}` |
| GET | `/client/rules` | `data.rules[] {id, type, description, enforced, active}`; `description` is the rule as a sentence |
| GET | `/client/appointments?from&to&status&customer&per_page` | Permission `appointments.view`. From today by default, in start order. `data.appointments[]`: `id` (ULID), `title`, `status` (`pending`, `tentative`, `confirmed`, `completed`, `no_show`, `cancelled`), `starts_at` / `ends_at` (UTC ISO 8601), `local_start` (business time), `timezone`, `duration_minutes`, `source`, `notes`, `address`, `customer {id, name, phone}`, `service {id, name}`, `location {id, name}`, `call_id`, `cancellation_reason`, `created_at` |
| GET | `/client/availability?date=YYYY-MM-DD&service_id&duration_minutes&location_id` | Free start times on that local date: `data.slots[] {local, starts_at}`, plus `timezone` and `duration_minutes` |
| POST | `/client/appointments` | Permission `appointments.create`. `starts_at` (ISO 8601; **without an offset it is the business's local time**), `service_id`, `duration_minutes`, `location_id`, `customer_id` (ULID), `status` (`confirmed` default, `pending`, `tentative`), `notes`, `address`. 201 with `data.appointment`. **409** when the time overlaps another booking: `message`, `errors.starts_at`, `suggestions[] {local, starts_at}` |
| GET | `/client/appointments/{id}` | `data.appointment` |
| PATCH | `/client/appointments/{id}` | `starts_at` / `duration_minutes` to move (`appointments.update`, 409 on clash), `status` (`appointments.cancel` for `cancelled`, with optional `cancellation_reason`), `notes` |
| GET | `/client/escalations?status=active\|resolved\|all&per_page` | Permission `escalations.view`. Default `active` (open + acknowledged), waiting longest and most urgent first. `data.escalations[]`: `id` (ULID), `type` (`urgent_issue`, `emergency`, `complaint`, `refund_request`, `pricing_approval`, `owner_decision`, `technical_problem`, `ai_uncertainty`), `type_label`, `priority` (`urgent`, `high`, `normal`), `status` (`open`, `acknowledged`, `resolved`), `reason`, `details`, `source`, `customer {id, name}`, `call_id`, `assigned_to {id, name}`, `acknowledged_at`, `resolved_at`, `resolution_notes`, `created_at` |
| GET | `/client/escalations/{id}` | `data.escalation` |
| POST | `/client/escalations/{id}/acknowledge` | Permission `escalations.resolve`. Stops the urgent reminder |
| POST | `/client/escalations/{id}/assign` | `assigned_to`: user id of an active team member, or `null` |
| POST | `/client/escalations/{id}/resolve` | `resolution_notes` required (422 without) |
| PATCH | `/client/tasks/{id}` | Permission `tasks.update`. Any of the POST fields, plus `status`. Completing records who and when |
| GET / PUT | `/client/profile` | `name`, `phone` editable. GET also returns `data.organization` |

### Agent workspace — role `agent` or `admin`
| Method | Path | Notes |
|---|---|---|
| GET | `/agent/dashboard/kpi?period=today\|weekly\|monthly` | `data.kpi_data` |
| GET | `/agent/dashboard/performance` | `data.performance_data` |
| GET | `/agent/call-logs?status&limit&offset` | Own calls |
| POST | `/agent/call-logs` | Permission `calls.create` **in the client's business** (else 422 on `client_id`). `call_outcome` must be one of that business's active outcomes (else 422 on `call_outcome`). Optional `escalation_type` / `escalation_priority` for outcomes in the escalated category (default `urgent_issue`, priority from the type). Callback outcomes create a task; escalated outcomes raise an escalation. Notifies the business |
| PUT | `/agent/call-logs/{id}` | Own calls, while still assigned to that business |
| GET | `/agent/clients` | Only businesses the agent is assigned to. Each client carries `call_outcomes: [{key, label, category}]`, the pick-list for that business (added P2-4a, additive) |
| PUT | `/agent/call-logs/{id}` with a new `call_outcome` | Must be one of the call's business's active outcomes (else 422 on `call_outcome`) |
| GET | `/duty-schedules`, `/duty-schedules/calendar` | Own shifts (admins: all) |

### Admin — role `admin`
`/admin/dashboard/stats`, `/admin/analytics`, `/admin/agent-performance`, `/admin/users` (GET/POST, PUT/DELETE `/{id}`), `/admin/call-logs`, `/admin/duty-schedules` (GET/POST, PUT/DELETE `/{id}`, `/calendar`, `/check-conflicts`). Duty schedules use `schedule_date` + `start_time`/`end_time` (`H:i` or `H:i:s`) + `notes`.

---

## 3. Changelog

| Date | Change |
|---|---|
| 2026-10-08 | `/client/knowledge`, `/client/rules` (read-only). |
| 2026-10-07 | `/client/appointments` (list, book, show, move, status) and `/client/availability`. |
| 2026-10-06 | `/client/escalations` (list, show, acknowledge, assign, resolve). Optional `escalation_type` / `escalation_priority` on `POST /agent/call-logs`. `/client/tasks` (list, create, show, update). `call_outcomes[]` on `/agent/clients`. `call_outcome` validated against the business's active outcomes. |
| 2026-10-05 | `/client/business`, `/client/services`, `/client/customers`. |
| 2026-10-12 | `two_factor_code` on login; `401 two_factor_required`, `403 two_factor_setup_required` (D24). A password reset, switching an account off or "sign out everywhere" revokes app tokens. |
| 2026-10-04 | Error envelope for every error (incl. 401/404/405/429/500). `device_name` on login. `expires_at` on login/refresh. `/devices` endpoints. Rate limit on all routes. Security headers, CORS restricted. |
| 2026-10-03 | `organization` object on `/user` and `/client/profile`. Duty-schedule endpoints fixed. 30-day token expiry. Errors no longer include exception text. |
