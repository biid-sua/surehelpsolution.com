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

### Safe retries (`Idempotency-Key`, D52)
`POST /agent/call-logs`, `POST /client/appointments`, `POST /client/tasks`, `POST /client/inbox/conversations/{id}/reply` and `POST /admin/users` accept an optional `Idempotency-Key` header (8–100 characters: letters, digits, `-`, `_`, `.`, `:`). Send a new random key, such as a UUID, for each thing the person creates, and the same key when you retry it.
- **A retry with the same key and body within 24 hours:** returns the first response with `Idempotent-Replayed: true`, and nothing new is created.
- **The same key with a different body:** `422`.
- **A retry while the first attempt is still running:** `409`. Wait a moment and retry.
- **Server errors (`5xx`) aren't remembered:** retry with the same key.
- **Keys are per signed-in person.**

### Security
- No browser CORS except from our own site (`CORS_ALLOWED_ORIGINS` adds more).
- API responses are `Cache-Control: no-store`.
- Denied requests are logged to the security log.
- Every sign-in, sign-out, token refresh and device revocation is recorded in the audit log.

### Tenancy
A client user only ever sees their own business. The business is resolved on the server, so no endpoint accepts an organization ID. Agents can only act for businesses they are currently assigned to (D40). A business they aren't assigned to answers exactly like one that doesn't exist: `404`, or `422 "The selected client was not found."` on `client_id`.

---

### Colour themes (D59)
The `theme` object (on `/login`, `/user` and `PUT /user/theme`): `{ "key": "ocean", "own": true, "default": "midnight" }`.
- `key`: the theme to show. It can be `system`: then use `resolves_to.light` or `resolves_to.dark` from `/themes`, following the device's light or dark setting.
- `own`: `true` when the person chose it, `false` when they follow the default an administrator sets.
- `default`: that default.

`colors` (hex; `#RRGGBBAA` where transparent):
- **backgrounds:** `canvas` (page), `surface` (cards), `surface_2`, `surface_3`;
- **lines:** `line`, `line_strong`;
- **text:** `ink`, `muted`, `subtle`;
- **brand:** `brand` (links and highlights), `brand_strong` (filled buttons, with white text), `accent`;
- **status:** `success`, `warning`, `danger`, `info`.

They are the same values the website uses. Fetch `/themes` once per session; the list rarely changes.

## 2. Endpoints

### Account and devices — any signed-in user
| Method | Path | Notes |
|---|---|---|
| POST | `/login` | `email`, `password`, `device_name?`, `two_factor_code?` → `data.user`, `data.token`, `data.token_type`, `data.expires_at`, `data.must_change_password`, `data.theme` |
| POST | `/logout` | Revokes the current token |
| GET | `/user` | `data.user`, plus `data.organization` (`id` = public ULID, `name`, `status`, `timezone`, `currency`) for client users, `null` otherwise, and `data.theme` |
| GET | `/themes` | Colour themes (D59) → `data.themes[]`: `key`, `label`, `description`, `mode` (`dark`, `light` or `auto`), `colors` (see below; absent for `system`), `resolves_to` (`system` only: `{light, dark}` theme keys to use by the device setting); `data.current` = this person's `theme` object |
| PUT | `/user/theme` | `theme` = a key from `/themes`, or `default` to follow the SureHelp default again → `data.theme`. Saved on the account: the web shows the same theme |
| POST | `/refresh-token` | New token for the same device → `data.token`, `data.expires_at` |
| GET | `/devices` | `data.devices[]`: `id`, `name`, `current`, `last_used_at`, `created_at`, `expires_at`. Token values are never returned |
| DELETE | `/devices/{id}` | Sign out one of **your** devices (others' → 404) |
| DELETE | `/devices` | Sign out every device except this one → `data.revoked` |
| POST | `/notifications/device-token` | Register a push token: `token`, `platform?`, `device_name?` |
| DELETE | `/notifications/device-token` | Stop pushes to this device (`token`); call it on sign-out |
| GET | `/me/capabilities` | What this person may use (API-1) → `data.role`, `data.permissions[]` (granted permission keys, in their business for business users), `data.business` (`null` for staff and agents): `id`, `name`, `status`, `serving`, `role` (`owner`, `manager` or `staff`), `is_owner`, `features{key: bool}`, `limits{calls, team_members, calendars, social_accounts, social_ai_tokens, seo_ai_tokens}` (`null` = no limit), `plan{name, status, trial_ends_on, current_period_end}`, `closes_at`; and `data.account`: `email_verified`, `two_factor_enabled`, `two_factor_required`, `can_close_account`. Show only what's granted; the server checks again on every call |
| GET | `/notifications` | The notification centre (API-2), newest first, 20 per page (`per_page` up to 50, `page`, `unread=1`) → `data.notifications[]`: `id`, `type` (e.g. `task_activity`), `event` (see below), `title`, `body`, `url` (web address, `null` if not one of our pages), `target` (`{route, params}`: the web route name and its parameters, for opening the matching app screen; `null` when there's none), `read_at`, `created_at`; `meta.unread`, `meta.pagination` |
| GET | `/notifications/unread-count` | `data.unread`, for the app-icon badge |
| POST | `/notifications/{id}/read` | Mark one read → `data.notification`, `data.unread` |
| POST | `/notifications/read-all` | Mark all read → `data.unread` = 0 |
| DELETE | `/notifications/{id}` | Remove one from the list |
| GET | `/account/closure` | Closing the account (API-4, D29) → `data.can_request` (only the business owner), `business_name`, `grace_days` (30), `scheduled`, `requested_at`, `closes_at`; for everyone else `message` and `contact_email` say whom to ask |
| POST | `/account/closure` | Owner only: `business_name` (typed exactly, case doesn't matter) and `password`. The account closes after the grace period and an email confirms it. `422` wrong name or password, `409` already scheduled, `429` after 5 tries in 10 minutes, `403` not the owner |
| DELETE | `/account/closure` | Owner only: keep the account (cancels a scheduled closure) |

| GET | `/notifications/settings` | Notification settings (API-3), the same choices as the web → `data.channels[]` (`key`, `label`, `enabled`), `data.events[]` (`key`, `label`, `description`, `channels[]` chosen), `data.quiet_hours` (`start`, `end`, `timezone`, or `null` = off), `data.daily_summary` (`time` or `null`, `options[]`; business users only, otherwise `null`), `data.sms_phone` (`number`, `display`, `verified`, `texts_available`) |
| PUT | `/notifications/settings` | Any of: `events{event_key: [channel, …]}` (only the events and channels listed above), `quiet_hours` (`{start, end}` as `HH:MM`, or `null` to turn off), `daily_summary` (`off` or one of the options; business users only) → the same as GET |
| POST | `/account/sms-phone` | `phone`: a US or Canadian mobile number. Texts a 6-digit code (valid 10 minutes, 3 codes per 10 minutes) → `data.sms_phone`-shaped `data`. `422` with a message when texts aren't set up or the number isn't valid |
| POST | `/account/sms-phone/verify` | `code` → `data.verified: true`, or `422` (wrong or expired; 5 tries per code) |
| DELETE | `/account/sms-phone` | Removes the number: no more notification texts |
| GET | `/app/version` | **No sign-in needed.** `platform` (`ios` or `android`), `version?` (e.g. `1.4.2`) → `data.minimum`, `data.latest`, `data.store_url`, `data.update_required` (older than `minimum`: block the app with "Please update"), `data.update_available` (older than `latest`: a gentle prompt). Set in `.env`: `MOBILE_IOS_MINIMUM`, `MOBILE_IOS_LATEST`, `MOBILE_IOS_STORE_URL`, and the same for `ANDROID` (API-5) |

#### Push notifications and opening the right screen (API-2)
Every push carries `notification.title` and `notification.body`, plus `data` (all strings):
- `url`: the web address;
- `event`: the event, below;
- `notification_id`: mark it read with `POST /notifications/{id}/read`;
- `route` and `route_params` (JSON): the same `target` as the list.

Map `route` to app screens and fall back to the notification list for routes the app doesn't have. Events in use:
- **Calls:** `call.logged`, `call.missed`.
- **Follow-ups:** `followup.created`, `followup.overdue`, `task.assigned`.
- **Appointments:** `appointment.created`, `appointment.updated`, `appointment.cancelled`.
- **Messages and escalations:** `message.received`, `escalation.created`, `ai.escalation`.
- **Social:** `social.approval_requested`, `social.post_failed`, `social.account_disconnected`.
- **Billing:** `invoice.issued`, `payment.received`, `payment.failed`, `payment.reported`, `billing.usage`, `subscription.updated`, `plan.change_requested`.
- **Reports and data:** `report.monthly`, `data_export.ready`.
- **Agents:**
  - `assignment.started`, `assignment.changed`;
  - training: `training.assigned`, `training.due`, `training.completed`, `training.certification`;
  - `qa.reviewed`;
  - shift requests: `shift_request.*`;
  - calendar: `agent_calendar.disconnected`.
- **Account and service:** `integration.disconnected`, `service.status`, `support.update`, `automation.notice`, `ui.theme_reset`.
- **Staff:** `organization.signed_up`, `setup.completed`.

Common routes:
- **Business:**
  - `app.calls.show` {callId}, `app.inbox.show` {conversation}, `app.customers.show`, `app.social.posts.edit`;
  - `app.appointments.index`, `app.tasks.index`, `app.escalations.index`;
  - `app.billing`, `app.billing.invoice`, `app.results`, `app.dashboard`.
- **Agents:** `agent.home`, `agent.companies`, `agent.university.course`, `agent.quality`, `agent.schedule`.

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
| GET | `/client/customers/{id}` | `data.customer` + `data.timeline[]` (latest 50). `customer.tags` are names; `customer.tag_list[]` (`id`, `name`) adds the ids for removing one |
| GET | `/client/tasks?status=open\|overdue\|done\|all&mine&customer&per_page` | Permission `tasks.view`. Default `open`, most urgent first. `data.tasks[]`: `id` (ULID), `type` (`callback`, `follow_up`, `todo`), `title`, `description`, `priority` (`urgent`, `high`, `normal`, `low`), `status` (`open`, `in_progress`, `completed`, `cancelled`), `is_overdue`, `due_at`, `source`, `customer {id, name}`, `call_id`, `assigned_to {id, name}`, `completed_at`, `created_at`. Paged like customers |
| POST | `/client/tasks` | Permission `tasks.create`. `title` (required), `type`, `priority`, `description`, `due_at` (ISO 8601), `customer_id` (customer ULID), `call_id?` (the call it follows up, e.g. `CL-…`; its customer comes along; another business's call is `422`), `assigned_to` (user id of an active team member). 201 with `data.task` |
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
| GET | `/client/inbox/conversations?filter=attention\|ai\|open\|closed\|all&per_page` | Permission `messages.view`. Default `attention` (open and needing a person), latest first. `data.conversations[]`: `id` (ULID), `channel` (`web_chat`, `facebook`, `instagram`), `name`, `customer_id`, `status` (`open`, `closed`), `needs_human`, `ai_handling`, `unread`, `last_message_at`, `can_reply_until` (Meta channels: 7 days after the customer's last message). Paged like customers |
| GET | `/client/inbox/conversations/{id}` | `data.conversation` and `data.messages[] {id, direction (in/out), from (customer/user/ai/system), author, text, attachments[], is_note, status (received/draft/pending/sent/failed), error, at}`. Marks it read |
| POST | `/client/inbox/conversations/{id}/reply` | Permission `messages.send`. `text` (required), `note` (team-only note). **422** when the channel's window has closed or the channel refused it (`errors.text`). Replying pauses the AI in that conversation (docs/inbox.md) |
| GET | `/client/social/posts?status=upcoming\|awaiting_approval\|published\|failed\|all&per_page` | Permission `social.view`. Default `upcoming` (waiting, scheduled, publishing), soonest first. `data.posts[]`: `id` (ULID), `status` (`draft`, `in_review`, `scheduled`, `publishing`, `published`, `partly_published`, `failed`, `cancelled`), `status_label`, `body`, `link_url`, `scheduled_at`, `published_at`, `source` (`manual`, `team`, `ai`), `author`, `review_note`, `accounts[] {network, name, status, url, error}`, `media[] {url, alt}` (signed links, valid 2 hours), `web_url`. Paged like customers. Tokens are never included |
| POST | `/client/social/posts/{id}/approve` | Permission `social.manage` and **business owner** (403 otherwise). Posts waiting for approval only (422 otherwise) |
| POST | `/client/social/posts/{id}/request-changes` | Same rules. `note` required: the post returns to draft with the note |


#### Agents, search and the API description (D74)
| Method | Path | Notes |
|---|---|---|
| GET | `/agent/shift-requests` | My requests from the last 30 days and all waiting ones → `data.requests[]` (`id`, `type` `swap`/`leave`, `status` `pending`/`approved`/`declined`/`cancelled`, `status_label`, `shift{id, starts_at, ends_at}`, `swap_with{id, name}`, `leave_from`, `leave_until`, `reason`, `decision_note`, `decided_at`, `created_at`), `data.colleagues[]` (`id`, `name`) |
| POST | `/agent/shift-requests` | `Idempotency-Key`. `type`: `swap` with `shift_id` and `swap_with_id?`; or `leave` with `leave_from` and `leave_until` (dates from today), plus `reason?` → `201`. Schedulers decide on the web; the agent hears back by notification (`shift_request.*`) |
| POST | `/agent/shift-requests/{id}/withdraw` | Withdraw a waiting request |
| GET | `/agent/quality/reviews` | My scored calls (D27) → `data.summary` (`count`, `average`, `pass_rate` over 30 days), `data.pass_score`, `data.reviews[]` (`id`, `score`, `passed`, `business`, `call{id, caller, at}`, `reviewer`, `reviewed_at`, `acknowledged_at`) |
| GET | `/agent/quality/reviews/{id}` | Plus `scores[]` (`key`, `label`, `weight`, `critical`, `mark`, `mark_label`), `strengths`, `improvements` |
| POST | `/agent/quality/reviews/{id}/acknowledge` | "I've read it" |
| GET | `/agent/presence` | → `data.status` (`offline`, `available`, `away`, `busy`, `wrap_up`), `label`, `softphone_online`, `since` |
| PUT | `/agent/presence` | `status`: `away` (calls stop ringing) or `available`. Calls ring the softphone on a computer, so this works only while that softphone is online (`409` otherwise, or while on a call). The app never counts as the softphone checking in |
| GET | `/client/search` | `q` (2+ characters), `limit?` (up to 20 per group) → `data.results`: `customers[]`, `calls[]`, `appointments[]`, `tasks[]`; a group is `null` when the person may not see that kind of record (the same as the web search) |
| GET | `/openapi.json` | **No sign-in.** OpenAPI 3.1 description of every `/api/v1` path, built from the routes: methods, path parameters, sign-in, permissions, idempotency keys, rate limits and a summary each. Field detail stays in this document |

#### Social posts, billing and team in the app (D73)
Social endpoints need the `social_publishing` feature (`403` with `feature` otherwise). Viewing needs `social.view`; everything that changes something needs `social.manage`.

| Method | Path | Notes |
|---|---|---|
| GET | `/client/social/accounts` | Connected accounts → `data.accounts[]`: `id`, `network`, `network_label`, `name`, `avatar_url`, `enabled`, `needs_reconnect`, `limits` (`max_chars`, `requires_media`, `max_images`, `links`, `clickable_links`, `max_hashtags`) |
| GET | `/client/social/posts/{id}` | One post: the list fields plus `ai_note`, `custom{account_id: text}`, `options{account_id: {…}}` (D76), `account_ids[]`, `media_ids[]`, `editable` |
| POST | `/client/social/posts/check` | Check without saving: `body`, `link_url?`, `accounts[]`, `custom?`, `media[]?` → `data.checks[]`: `account`, `errors[]`, `warnings[]` (the web's per-network rules) |
| POST | `/client/social/posts` | `Idempotency-Key`. `intent` (`draft`, `schedule` or `now`), `body`, `link_url?`, `accounts[]` (account ids), `custom?{account_id: text}`, `media[]?` (ids from `/social/media`), `scheduled_at?` (ISO 8601, required to schedule), `options?{account_id: {…}}` (Google Business: `topic` `STANDARD`/`OFFER`/`EVENT`, `button` `BOOK`/`ORDER`/`SHOP`/`LEARN_MORE`/`SIGN_UP`/`CALL`, `button_url`, and for offers and events `title`, `starts_on`, `ends_on` (`YYYY-MM-DD`), plus `coupon` and `terms` for offers), `ai_assisted?` → `201` with `data.post`. A manager's or an AI draft's post goes to `in_review` for the owner, as on the web. `422` lists each network's problems |
| PUT | `/client/social/posts/{id}` | The same fields, for a post that's still a draft, in review or scheduled |
| POST | `/client/social/posts/{id}/cancel` · `/retry` · DELETE `/{id}` | Cancel a planned post, try a failed one again, delete |
| POST | `/client/social/media` | Multipart `photo` (JPG, PNG or WebP) and `alt?` → `201` with `data.media{id, url, alt}` |
| POST | `/client/social/ai/write` | `brief`, `tone?` (`friendly`, `professional`, `playful`, `urgent`), `accounts[]?` → `data.versions{network: text}`, `data.flags[]` ("check before posting"). `422` when AI isn't on or the month's allowance is used (D66) |
| POST | `/client/social/ai/rewrite` | `text`, `how` (`shorter`, `friendlier`, `professional`, `cta`), `accounts[]?` → `data.text`, `data.flags[]` |
| GET | `/client/social/openings` | Last-minute openings (D69) → `data.days[]` (`date`, `times[]`), `data.text` (a ready post, or `null` with no openings) |
| GET / PUT | `/client/social/link-page` | Link in bio: `enabled`, `links[]` (`label`, `url`, up to 6) → `data.enabled`, `live`, `url`, `links`, `max_links` |
| GET | `/client/billing` | `billing.view` → `data.plan` (`name`, `status`, `price_cents`, `currency`, `interval`, `trial_ends_on`, `renews_on`, `cancel_at_period_end`), `balance_cents`, `overdue`, `card_payments` (Stripe on), `autopay` (`card`, `since`, or `null`), `can_pay` |
| GET | `/client/billing/invoices` | `status?` (`open`, `paid`, `all`), paginated → `data.invoices[]`: `id`, `number`, `status`, `currency`, `total_cents`, `paid_cents`, `balance_cents`, `period`, `issued_at`, `due_at`, `paid_at`, `overdue`, `autopay_error`, `pdf_url` |
| GET | `/client/billing/invoices/{id}` | Plus `items[]` (`description`, `quantity`, `unit_cents`, `amount_cents`), `payments[]` (`amount_cents`, `method`, `received_at`, `reference`), `notes`, `reported_paid_at` |
| GET | `/client/billing/invoices/{id}/pdf` | The invoice PDF |
| POST | `/client/billing/invoices/{id}/pay` | `billing.manage` (owners). `save?` (also save the card for automatic payments) → `data.checkout_url`: open it in an in-app browser. Stripe returns to `/app/billing?paid={id}` (an app link); the payment shows when Stripe confirms it, usually within seconds. `404` when card payments are off, `409` already paid |
| POST | `/client/billing/invoices/{id}/report-payment` | `note` ("Bank transfer on Nov 3"): the team confirms it |
| DELETE | `/client/billing/autopay` | Turn automatic payments off and remove the card |
| GET | `/client/billing` (continued) | Also `trial` (D97): `null`, or `status` (`active`: a trial to keep; `ended`: read-only until a plan starts), `ends_on`, `days_left`, `calls_included` (`null` = no limit), `calls_used`, `plan_id`. And `credits` (D100): `balance`, `packs[]` (`pack`, `credits`, `price_cents`), `pending[]` (`credits`, `invoice_id`: bought, waiting for payment) |
| GET | `/client/billing/plans` | Plans the business can choose itself (D98) → `data.plans[]`: `id`, `name`, `tagline`, `price_cents`, `currency`, `interval`, `highlights[]`, `featured`, `current` |
| POST | `/client/billing/trial/keep` | `billing.manage`. `plan_id` → keeps that plan for after the trial; nothing is charged until then. `404` when there's no trial to keep or the plan can't be chosen |
| POST | `/client/billing/trial/start` | `billing.manage`. `plan_id`, after the trial ended → `201`, `data.invoice` (the first invoice); the account opens again. `422` when the business already has a plan |
| POST | `/client/billing/credits` | `billing.manage`. `pack` (from `credits.packs[].pack`) → `201`, `data.invoice` and `data.credits`; the credits are added once the invoice is paid. `422` for an unknown pack, no plan, or 3 unpaid credit invoices |
| GET | `/client/team` | `users.view` → `data.members[]` (`id`, `name`, `email`, `role`, `last_login_at`, `is_you`), `data.invitations[]` (pending: `id`, `email`, `role`, `invited_by`, `expires_at`), `roles`, `can_invite[]` (roles this person may invite), `can_manage` |
| POST | `/client/team/invitations` | `users.create`: `email`, `role` (managers may invite `staff` only) → `201`. Plan seat limits apply (`422`) |
| POST | `/client/team/invitations/{id}/resend` · DELETE `/{id}` | Send it again, or cancel it |
| PATCH | `/client/team/members/{id}` | Owners: `role` (`manager` or `staff`) |
| DELETE | `/client/team/members/{id}` | Owners: remove the person. Without another business they're switched off and signed out everywhere. The owner and yourself can't be changed (`403`) |

#### Inbox and customers in the app (D72)
| Method | Path | Notes |
|---|---|---|
| POST | `/client/inbox/conversations` | Start a text conversation (API-7, D62), `messages.send`, `Idempotency-Key`: `to` (phone), `text` (up to 1,600) → `201` with `data.conversation.id`. `422` when the customer hasn't agreed to texts, texting isn't on, or the number isn't valid |
| GET | `/client/inbox/assignees` | People on the team a conversation can be assigned to → `data.assignees[]` (`id`, `name`) |
| POST | `/client/inbox/conversations/{id}/assign` | `user_id` (or `null` to unassign) → `data.assigned_to` |
| POST | `/client/inbox/conversations/{id}/close` · `/reopen` | → `data.status` |
| POST | `/client/inbox/conversations/{id}/ai` | `on`: `true` lets the AI assistant answer again, `false` keeps it for people → `data.ai_paused`, `data.needs_human` |
| POST | `/client/inbox/conversations/{id}/read` | Mark read (opening it with GET also does) |
| POST | `/client/inbox/conversations/{id}/drafts/{message}/send` · `/discard` | The AI's suggested reply (mode "suggest"): send it as it is, or discard it. Each draft can be used once |
| POST | `/client/inbox/conversations/{id}/messages/{message}/rate` | `rating` (`helpful` or `unhelpful`), `correction?` (what it should have said; becomes a guideline for the owner to approve, D39) |
| | (in `GET …/conversations/{id}`) | Conversations now include `assigned_to` and `ai_paused`. Texted pictures in `messages[].attachments[].url` are signed links valid 60 minutes (`/api/v1/inbox-media/…`) that open without the token |
| GET | `/client/customers/lookup` | `phone?`, `email?` → `data.match` (`id`, `name`, `matched_by`) or `null`: warn about a duplicate while typing (API-8) |
| POST | `/client/customers` | `customers.create`, `Idempotency-Key`: `first_name`, `last_name`, `company` (a name or a company), `phone`, `email` (a phone or an email) → `201` with `data.customer`. **`409`** when another customer has the phone number, with `existing` (`id`, `name`): open that customer instead |
| PATCH | `/client/customers/{id}` | `customers.update`. Any of: `first_name`, `last_name`, `company`, `phone`, `email`, `address_line1`, `address_line2`, `city`, `state`, `postal_code`, `status`, `preferred_contact` (`phone`, `sms`, `email`), `sms_consent`, `email_consent`, `notes`. Fields not sent keep their value; consent changes are recorded with who and when (spec §57). `422` if the phone belongs to another customer |
| POST | `/client/customers/{id}/notes` | `note` → `201` with `data.event` (the timeline entry) |
| POST | `/client/customers/{id}/tags` | `name` → `data.tags[]` |
| DELETE | `/client/customers/{id}/tags/{tag_id}` | → `data.tags[]` |

#### Links that open the app (API-16)
- `/.well-known/assetlinks.json` (Android App Links) and `/.well-known/apple-app-site-association` (iOS Universal Links) are served from `config/mobile.php`.
- Set `MOBILE_ANDROID_SHA256` (signing certificate fingerprints from Play Console › App integrity, comma-separated) and `MOBILE_IOS_APP_IDS` (`TEAMID.bundle.id`).
- Until then the files list no app. The app handles `/app/*`, `/agent/*`, `/invitations/*`, `/account/*` and `/b/*`.
- **Hosting:** the server must serve both addresses directly, over HTTPS, with no redirect.

#### Calls and Results in the app (D71)
| Method | Path | Notes |
|---|---|---|
| GET | `/client/calls/{call_id}` | One call (API-6), `calls.view` → `data.call`: `id`, `created_at`, `caller{name, phone, email}`, `customer{id, name}`, `reason`, `outcome`, `outcome_label`, `status`, `agent_name`, `notes`, `service{date, window, location}`, `tasks[]` (with `tasks.view`), `phone_call` (calls on a SureHelp line): `direction`, `status`, `status_label`, `other_party`, `after_hours`, `started_at`, `answered_at`, `ended_at`, `wait_seconds`, `duration_seconds`, `transferred_to`, `can_hear`; and, with `calls.recording.view` only, `recording{url, seconds, expires_in}`, `summary` and `transcript[]` (`speaker`, `text`, `at`) |
| GET | `/recordings/{id}?…signature` | The `recording.url` above: a signed link valid 15 minutes (`MOBILE_RECORDING_LINK_MINUTES`) that an audio player opens **without** the token. It still checks the person may hear it; each play is audited. Fetch the call again for a fresh link |
| GET | `/client/results` | Results for a month (API-10), `reports.view`. `month?` = `YYYY-MM` (this month by default) → `data.month`, `label`, `is_current`, `currency`, `summary` (`calls`, `answered`, `after_hours`, `leads`, `booked`, `appointments`, `job_value_cents`, `revenue_cents`, `change{…%}`), `call_outcomes[]`, `appointment_outcomes[]`, `reasons[]`, `busiest`, `heatmap` (7 days × 24 hours, Sunday first, business timezone), `sources` (leads and bookings by source, website and social numbers, D68), `value` (the dollars report, D107: `value_cents`, `jobs`, `priced_jobs`, `estimated_jobs`, `after_hours_jobs`, `after_hours_value_cents`, `cost_cents`, `cost_source` `invoices`/`plan`, `multiple`, `since_value_cents`, `since`; `null` when the business doesn't have it), `pdf_url` |
| GET | `/client/results/pdf` | The monthly report PDF (`month?`), with the token like any other call |

### Agent workspace — role `agent` or `admin`
| Method | Path | Notes |
|---|---|---|
| GET | `/agent/dashboard/kpi?period=today\|weekly\|monthly` | `data.kpi_data` |
| GET | `/agent/dashboard/performance` | `data.performance_data` |
| GET | `/agent/call-logs?status&limit&offset` | Own calls, for businesses the agent serves now |
| POST | `/agent/call-logs` | Permission `calls.create` **in the client's business** (else 422 on `client_id`). `call_outcome` must be one of that business's active outcomes (else 422 on `call_outcome`). Optional `escalation_type` / `escalation_priority` for outcomes in the escalated category (default `urgent_issue`, priority from the type). Callback outcomes create a task; escalated outcomes raise an escalation. Notifies the business |
| GET | `/agent/call-logs/{id}` | One of my call logs, for editing → `data.call_log` (the list's fields). Someone else's, or one in a business I no longer serve, is `404` |
| PUT | `/agent/call-logs/{id}` | Own calls, while still assigned to that business. Anything else is `404` |
| GET | `/agent/clients` | Only businesses the agent is assigned to. Each client carries `call_outcomes: [{key, label, category}]`, the pick-list for that business (added P2-4a, additive), and `business {id, name}` (ULID; D76). Call logs in `GET /agent/call-logs` also carry `client_id` and `business {id, name}` |
| PUT | `/agent/call-logs/{id}` with a new `call_outcome` | Must be one of the call's business's active outcomes (else 422 on `call_outcome`) |
| GET | `/duty-schedules`, `/duty-schedules/calendar` | Own shifts; staff with `users.view` see everyone's (and may pass `agent_id`) |
| GET | `/agent/companies` | Businesses the agent is assigned to now: `data.companies[]` with `id` (ULID), `name`, `timezone`, `assignment {status, type, starts_at, ends_at}`. Platform staff see every business |
| GET | `/agent/companies/{id}` | `data.company` with `appointments_today`, and what the agent reads before answering (D76): `status` (open now, as on `/client/business`), `hours` (weekday → ranges), `briefing[]` (the business's instructions and rules as sentences), `emergency {available, instructions}`, `services[]` (`id`, `name`, `price_label`, `duration_minutes`, `bookable`), `knowledge[]` (`id`, `title`, `content`, `category`, `pinned`; up to 30, team-only items left out). Permission `organization.view` there |
| GET | `/agent/companies/{id}/customers?search&per_page` | Paged (`meta.page`, `per_page`, `total`, `last_page`). `customers.view` |
| GET | `/agent/companies/{id}/customers/{customer}` | Looked up inside that business only. `customers.view` |
| GET | `/agent/companies/{id}/appointments` | Next 14 days. `appointments.view` |
| GET | `/agent/companies/{id}/calls?per_page` | Paged. `calls.view` |
| GET | `/agent/companies/{id}/conversations` | Open conversations. `messages.view` |

Every `/agent/companies/{id}/…` endpoint answers `404` when the business doesn't exist, the agent isn't currently assigned (scheduled, suspended, ended or revoked assignments give nothing), or the agent lacks the permission there.

**Required training (D43).** Unfinished required training can limit what an agent does for a company:
- **Blocking:** `/agent/companies/{id}/…` answers `403` with `code: "training_required"` and `missing: [course titles]`. Open the company's training instead.
- **Restricted or blocking:** `POST /agent/call-logs` answers `422` on `client_id`, with the reason in the message.

#### Agent University — any agent (docs/agent-university.md)
A course the agent may not take answers `404`: another company's course, an unpublished one, or one switched off. Correct quiz answers are never sent.

| Method | Path | Notes |
|---|---|---|
| GET | `/agent/training` | `summary` (progress %, required, required completed, overdue). `assignments[]`: course, required, priority, `status` (`assigned`, `started`, `in_progress`, `completed`, `overdue`, `expired`, `outdated`, `revoked`), progress, score and dates. `recommended[]`: course plus `reason` |
| GET | `/agent/training/certificates` | Each with `number`, `status` (`active`, `expiring_soon`, `expired`, `revoked`), `issued_at`, `expires_at` and a printable `url` |
| GET | `/agent/training/courses/{id}` | Details, the `assignment`, and the outline of the version being taken (`modules[].lessons[]` with `completed`) |
| POST | `/agent/training/courses/{id}/start` | Starts or continues. Returns `version` and `next_lesson` |
| GET | `/agent/training/courses/{id}/lessons/{lesson}` | `body_markdown` and `body_html`, `url`, `embed_url` (YouTube/Vimeo), `file.url`, and `quiz` (pass mark, question count, `attempts_left`, `open_attempt`) |
| POST | `/agent/training/courses/{id}/lessons/{lesson}/complete` | Optional `seconds` (time spent; capped at the time since the lesson was opened). Not for quizzes |
| POST | `/agent/training/courses/{id}/lessons/{lesson}/attempts` | Starts or resumes a quiz attempt: `attempt.id`, `questions[]` (id, type, scenario, prompt, options). `422` when no attempts are left |
| POST | `/agent/training/attempts/{attempt}/submit` | `answers: {question id: option id \| [option ids]}`. Returns the score, `passed`, per-question `correct`, and the `explanation` for wrong answers. Every question must be answered (`422`). Rate limited |
| GET | `/agent/training/courses/{id}/v{version}/files/{lesson}` | The lesson file, with range requests for audio and video |

### Admin — role `admin`, then each endpoint's permission (D32)
A staff member without the permission gets `403`. Dashboard: `dashboard.view`. Analytics and agent performance: `reports.view`. Users: `users.view` (GET), `users.create` (POST), `users.update` (PUT), `users.delete` (DELETE). Call logs: `calls.view`. Duty schedules: `users.view` to read, `users.update` to create, change, delete or check conflicts.

`/admin/dashboard/stats`, `/admin/analytics`, `/admin/agent-performance`, `/admin/users` (GET with `role`, `status`, `search` (name, email, phone or staff id; D76), `limit`, `offset`; POST, PUT/DELETE `/{id}`), `/admin/call-logs`, `/admin/duty-schedules` (GET/POST, PUT/DELETE `/{id}`, `/calendar`, `/check-conflicts`). Duty schedules use `schedule_date` + `start_time`/`end_time` (`H:i` or `H:i:s`) + `notes`.

---

## 2b. For businesses: API keys and webhooks (D87)

Businesses connect their own tools (n8n, Zapier, Make, a CRM) under **Business › API & webhooks**.

### API keys
- Create a key there: read-only, or "can add and change things". It's shown **once**; only a hash is
  stored.
- Send `Authorization: Bearer shk_…` to any `/api/v1/client/…` endpoint above, for example
  `GET /api/v1/client/customers`, `POST /api/v1/client/customers` or `GET /api/v1/client/appointments`.
- A key acts as the person who made it, with their permissions, for their business.
- It doesn't expire, but it stops working if it's revoked, or if that person is deactivated or leaves.
- Read-only keys get `403` with `"This API key is read-only."` on anything but `GET`/`HEAD`.
- Other paths (account, devices, admin, agent) answer `403` to API keys.

### Webhooks
- Add an `https://` address and choose events. Private-network addresses are refused, at setup and on
  every delivery.
- Events:
  - `appointment.booked`, `appointment.rescheduled`, `appointment.completed`, `appointment.no_show`,
    `appointment.cancelled`
  - `call.logged`, `call.missed`, `conversation.started`
  - `customer.created`, `escalation.raised`, `task.overdue`
  - `review.received`, `survey.answered`, `payment.paid`
- Each event is a `POST` with JSON:
  ```json
  { "id": "evt_01…", "event": "customer.created", "created_at": "2026-10-12T15:00:00+00:00",
    "organization": { "id": "01…", "name": "Rivera Plumbing" },
    "data": { "type": "customer", "id": "01…", "name": "Ana Lopez", "phone": "+15125550147", "email": null, "status": "lead" } }
  ```
- Headers: `X-SureHelp-Event`, `X-SureHelp-Delivery` (unique, for de-duplicating) and
  `X-SureHelp-Signature: t=<unix seconds>,v1=<hex>`.
  - `v1` is HMAC-SHA256 of `"<t>.<raw body>"` with the endpoint's signing secret.
  - Reject old timestamps (over 5 minutes) to prevent replays.
- Answer any `2xx` within 10 seconds. Otherwise we retry after 1 minute, 5 minutes, 30 minutes,
  2 hours and 6 hours.
- After 20 failures in a row the webhook is switched off, with the reason shown on the page.
- *Send a test* posts a `ping` event straight away.

## 3. Changelog

| Date | Change |
|---|---|
| 2026-10-30 | `value` on `GET /client/results`: the dollars report (D107), `null` when the business doesn't have it. Additive. |
| 2026-10-28 | For the mobile app (D102): `trial` and `credits` on `GET /client/billing` (additive); `GET /client/billing/plans`; `POST /client/billing/trial/keep`, `/trial/start`; `POST /client/billing/credits`. |
| 2026-10-21 | For the mobile app (D76): `call_id` on `POST /client/tasks`; `customer.tag_list` with ids; `business` on `/agent/clients` and agent call logs; `GET /agent/call-logs/{id}`; the business's hours, briefing, services and knowledge on `GET /agent/companies/{id}`; `search` on `GET /admin/users`; `options` on `GET /client/social/posts/{id}`. All additive. |
| 2026-11-12 | For the mobile app (D74): agents' shift requests, quality reviews and availability; `GET /client/search`; `GET /openapi.json`. |
| 2026-11-11 | For the mobile app (D73): writing, checking and scheduling social posts, photos, AI writing, openings, link page; billing summary, invoices, PDF, card payment, payment reports, automatic payments off; the team (members, invitations, roles). |
| 2026-11-10 | For the mobile app (D72): inbox actions (start a text, assign, close and reopen, AI on or off, read, AI suggestions, rating); signed links for texted pictures; adding and editing customers, with a duplicate lookup, notes and tags; the app-link files under `/.well-known/`. Conversations gain `assigned_to` and `ai_paused` (additive). |
| 2026-11-09 | For the mobile app (D71): `GET/PUT /notifications/settings`; `POST/DELETE /account/sms-phone`, `POST /account/sms-phone/verify`; `GET /app/version` (no sign-in); `GET /client/calls/{call_id}` with a signed recording link (`GET /recordings/{id}`); `GET /client/results`, `GET /client/results/pdf`. |
| 2026-11-08 | For the new mobile app (D70): `GET /me/capabilities`; the notification centre (`GET /notifications`, `/unread-count`, `POST /{id}/read`, `/read-all`, `DELETE /{id}`); `GET/POST/DELETE /account/closure`. Push `data` gains `notification_id`, `route` and `route_params` (additive). |
| 2026-10-28 | Colour themes (D59): `data.theme` on `/login` and `/user` (additive); `GET /themes`; `PUT /user/theme`. |
| 2026-10-23 | Optional `Idempotency-Key` header on create endpoints (D52). |
| 2026-10-06 (A-4) | `/agent/training/…` (Agent University). Required training can now refuse company endpoints (`403 training_required`) and call logging (`422`) (D43). |
| 2026-10-06 (A-1/A-2) | `/agent/companies` and its customers, appointments, calls and conversations. Unassigned businesses now answer `404` (was `403`) on `PUT /agent/call-logs/{id}`; `/agent/call-logs` lists only calls for businesses the agent serves now (D40). |
| 2026-10-06 (M-1) | `/client/inbox/conversations` (list, show, reply). Public website chat endpoints `/api/chat/{key}/…` and the Meta webhook `/api/webhooks/meta` are outside v1 (docs/inbox.md). |
| 2026-10-06 (G-1) | `/client/social/posts` (list), `/approve`, `/request-changes` (G-1, D33). |
| 2026-10-08 | `/client/knowledge`, `/client/rules` (read-only). |
| 2026-10-07 | `/client/appointments` (list, book, show, move, status) and `/client/availability`. |
| 2026-10-06 | `/client/escalations` (list, show, acknowledge, assign, resolve). Optional `escalation_type` / `escalation_priority` on `POST /agent/call-logs`. `/client/tasks` (list, create, show, update). `call_outcomes[]` on `/agent/clients`. `call_outcome` validated against the business's active outcomes. |
| 2026-10-05 | `/client/business`, `/client/services`, `/client/customers`. |
| 2026-10-12 | `two_factor_code` on login; `401 two_factor_required`, `403 two_factor_setup_required` (D24). A password reset, switching an account off or "sign out everywhere" revokes app tokens. |
| 2026-10-04 | Error envelope for every error (incl. 401/404/405/429/500). `device_name` on login. `expires_at` on login/refresh. `/devices` endpoints. Rate limit on all routes. Security headers, CORS restricted. |
| 2026-10-03 | `organization` object on `/user` and `/client/profile`. Duty-schedule endpoints fixed. 30-day token expiry. Errors no longer include exception text. |
