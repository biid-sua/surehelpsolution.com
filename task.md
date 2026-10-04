# SureHelp Solution: Virtual Receptionist Platform
## Master Task List (Laravel Web Application)

> **Goal:** Turn the existing Laravel dashboards (Agent + Client) into a complete, production-ready platform for a human-powered virtual receptionist service for US solo business owners, with an add-on marketplace, Stripe billing, calendar sync, and an AI layer.
>
> **Scope of this file:** Web application only. All features are built API-first so the mobile app (Phase 5) can reuse the same backend.

---

## 0. How to Use This File

**Status legend**
- `[ ]` Not started
- `[~]` In progress
- `[x]` Done

**Priority**
- **P0**: Required for launch. Nothing ships without it.
- **P1**: Important. Ship soon after launch.
- **P2**: Nice to have / later.

**Size** (rough effort for one developer)
- **S**: under 1 day
- **M**: 1 to 3 days
- **L**: 3 to 7 days
- **XL**: more than 1 week (should be broken down further before starting)

**Task ID format:** `EPIC-NUMBER`, for example `TEL-04`. Reference IDs in commit messages and pull requests (e.g. `feat(TEL-04): add warm transfer`).

---

## 1. Tech Stack and Packages

The existing system is Laravel, so we keep it and extend it. Confirm the frontend approach (Blade + Livewire, or Inertia + Vue/React) during the Phase 0 audit and stay consistent.

| Purpose | Recommended Tool / Package |
|---|---|
| Framework | Laravel (upgrade to the latest stable major version) |
| Roles and permissions | `spatie/laravel-permission` |
| Audit logs | `spatie/laravel-activitylog` |
| API auth (web SPA + mobile app) | Laravel Sanctum |
| OAuth (Google, Microsoft, Meta) | Laravel Socialite (+ community providers for Microsoft) |
| Real-time updates (live call screen, notifications) | Laravel Reverb (WebSockets) + Laravel Echo |
| Queues and background jobs | Redis + Laravel Horizon |
| Scheduled jobs | Laravel Scheduler (cron) |
| Billing | Laravel Cashier (Stripe) + Stripe Tax + Stripe Customer Portal |
| Telephony (calls, numbers, SMS, recording) | Twilio PHP SDK (`twilio/sdk`), Twilio Voice JS SDK, Twilio TaskRouter |
| Google Calendar | `google/apiclient` |
| Microsoft Calendar | Microsoft Graph SDK (`microsoft/microsoft-graph`) |
| Transcription (later) | Deepgram, AssemblyAI, or Twilio real-time transcription |
| LLM (summaries, chatbot, copilot) | Anthropic or OpenAI API via a single internal `AiService` wrapper |
| Search (clients, callers) | Laravel Scout + Meilisearch (or database driver at first) |
| PDF (reports, invoices) | Cashier invoice PDFs; `barryvdh/laravel-dompdf` for custom reports |
| File storage (recordings, uploads) | Amazon S3 (private bucket, signed URLs) |
| Monitoring | Laravel Pulse, Sentry (errors), Laravel Telescope (local/staging only) |
| Testing | Pest |
| Code quality | Laravel Pint, Larastan (PHPStan) |
| Hosting | Laravel Forge + AWS / DigitalOcean, or Laravel Cloud |

---

## 2. Phase 0: Audit, Fixes, and Foundation

> Do this before building new features. Building on a shaky base costs more later.

### 2.1 Audit the existing code

- [x] **FND-01** (P0, M) Review the existing Laravel codebase: version, folder structure, frontend stack, packages, migrations, and how Agent and Client dashboards get their data. Write findings in `docs/audit.md`. _(Superseded by `docs/architecture-audit.md` + `docs/current-state.md`.)_
- [~] **FND-02** (P0, M) Upgrade Laravel and PHP to the latest stable versions. Run all migrations on a fresh database to make sure they still work.
- [ ] **FND-03** (P0, S) Set up environments: local, staging, production. Separate `.env` files, separate databases, separate Stripe/Twilio test keys.
- [ ] **FND-04** (P0, S) Set up Git branching (`main`, `develop`, feature branches) and pull request reviews.
- [ ] **FND-05** (P0, M) Set up CI (GitHub Actions): run Pint, Larastan, and Pest on every pull request. Auto-deploy `develop` to staging.

### 2.2 Fix bugs found in the current dashboards

- [x] **FIX-01** (P0, S) Agent dashboard: stat cards show `0` while the Hourly Call Volume chart shows data. Make both read from the same query/service so they always match.
- [x] **FIX-02** (P0, S) Client Call History: every row shows `SCHEDULED` even for dropped calls. Show the real status from the call record.
- [x] **FIX-03** (P0, S) Client Call History: "Service Location" displays the text `null`. Show `—` or hide when empty. Check other fields for the same problem.
- [x] **FIX-04** (P0, S) Call ID is generated in the browser form. Move ID generation to the server (database sequence per day, e.g. `CL-YYYYMMDD-0001`) inside a transaction to prevent duplicates.
- [x] **FIX-05** (P0, S) Footer shows `© 2025`. Make the year dynamic.
- [x] **FIX-06** (P0, S) Remove or hide the non-functional sidebar menus from the Agent dashboard (User Management, Content Management, SEO Tools). These move to the Admin portal.
- [x] **FIX-07** (P1, S) Duty schedule summary cards have low-contrast text ("This Week", "Today", "Scheduled Days" are hard to read). Fix contrast to meet accessibility standards.
- [x] **FIX-08** (P1, S) Add proper navigation (sidebar or top menu) to the Client dashboard.

### 2.3 Core architecture

- [ ] **FND-10** (P0, L) **Multi-tenancy model.** Each business client is a tenant (`clients` table). Every client-owned record (calls, appointments, contacts, settings) has a `client_id`. Add a global scope / policy layer so a client user can never see another client's data. Write tests that try to access another tenant's data and expect a 403/404.
- [ ] **FND-11** (P0, M) **Roles and permissions** with Spatie: `super_admin`, `ops_admin`, `supervisor`, `agent`, `client_owner`, `client_staff`, `client_viewer`. Define permissions per feature (e.g. `calls.view`, `billing.manage`).
- [ ] **FND-12** (P0, M) **Portal separation.** Separate route groups, layouts, and middleware for `/admin`, `/supervisor`, `/agent`, `/client`. After login, redirect users to the right portal based on role.
- [ ] **FND-13** (P0, M) **API-first structure.** Put business logic in Service/Action classes, not controllers. Expose versioned API routes (`/api/v1/...`) with Sanctum so the mobile app can reuse them later. Use API Resources for all JSON responses.
- [ ] **FND-14** (P0, M) **Queues and real-time.** Set up Redis, Horizon, and Reverb. Create a base pattern for broadcasting events to private channels (e.g. `client.{id}`, `agent.{id}`).
- [ ] **FND-15** (P0, S) **Timezones.** Store all timestamps in UTC. Every client has a timezone (US business timezone). Every agent has a timezone (may be outside the US). Display times in the viewer's timezone and always show the client's local time on agent screens.
- [ ] **FND-16** (P0, S) **Audit logging** with Spatie Activitylog for all sensitive actions: viewing recordings, changing settings, billing changes, impersonation, data exports.
- [ ] **FND-17** (P0, M) **Settings system.** A flexible `client_settings` (key/value JSON) approach for per-client options so new settings don't need new migrations each time.
- [ ] **FND-18** (P1, S) **Feature flags** (Laravel Pennant) so add-ons and new features can be turned on per client or per plan.

---

## 3. Core Database Schema (Overview)

> These are the main tables. Columns are a starting point. Finalize during FND-10. All tables include `id`, `created_at`, `updated_at`; tenant tables include `client_id`; use soft deletes where data should be recoverable.

**Identity and tenancy**
- `users`: name, email, phone, password, timezone, avatar, 2FA fields, last_login_at, status
- `clients`: business_name, owner_user_id, industry, timezone, address, website, status (onboarding / active / paused / cancelled), plan_id, stripe_id (Cashier)
- `client_users`: client_id, user_id, role
- `teams`: name, supervisor_user_id (agent teams)
- `agent_profiles`: user_id, team_id, languages, skills, status (available / on_call / after_call / break / offline), max_concurrent_clients
- `client_agent_assignments`: client_id, agent_user_id or team_id, is_dedicated, priority

**Business profile (what the agent sees)**
- `client_profiles`: greeting_script, description, do_rules, dont_rules, emergency_definition, escalation_phone
- `services`: client_id, name, description, price_text, duration_minutes, buffer_minutes, is_bookable
- `faqs`: client_id, question, answer, sort_order
- `service_areas`: client_id, zip_code (or radius definition)
- `business_hours`: client_id, day_of_week, open_time, close_time
- `holidays`: client_id, date, label
- `intake_forms` / `intake_form_fields`: per-client custom questions for agents
- `call_rules`: client_id, condition (after_hours / emergency / vip / spam), action (transfer / urgent_notify / voicemail / ai_agent)

**Telephony**
- `phone_numbers`: client_id, e164_number, provider_sid, type (provisioned / forwarded), status
- `calls`: client_id, call_code (CL-...), provider_call_sid, direction, from_number, to_number, contact_id, agent_user_id, started_at, answered_at, ended_at, duration_seconds, billable_seconds, outcome, status, is_spam, is_urgent, recording_id, summary, sentiment
- `call_recordings`: call_id, storage_path, duration, consent_played
- `call_transcripts`: call_id, text, segments (JSON), provider
- `call_events`: call_id, type (queued / ringing / answered / hold / transfer / ended), payload, occurred_at
- `call_outcomes`: code, label, is_active (lookup table used by required outcome codes)

**Customers, calendar, and appointments**
- `contacts`: client_id, name, phone, email, address, tags (JSON), source, notes, lifetime_value
- `calendar_connections`: client_id or user_id, provider (google / microsoft), external_calendar_id, access_token (encrypted), refresh_token (encrypted), sync_token, webhook_channel_id, webhook_expires_at, status
- `appointments`: client_id, contact_id, service_id, call_id, starts_at, ends_at, location, status (pending_approval / confirmed / cancelled / completed / no_show), external_event_id, created_by_user_id, estimated_value
- `busy_blocks`: client_id, starts_at, ends_at, source (synced from external calendar)
- `tasks`: client_id, call_id, assigned_to, type (callback / follow_up), due_at, status

**Agent operations**
- `shifts`: agent_user_id, starts_at, ends_at, status (existing "My Duty Schedule")
- `shift_requests`: agent_user_id, type (swap / leave), details, status
- `agent_status_logs`: agent_user_id, status, started_at, ended_at
- `qa_scorecards` / `qa_reviews`: call_id, reviewer_id, scores (JSON), total, comments

**Notifications**
- `notification_preferences`: user_id, event_type, channels (JSON: push / sms / email / whatsapp)
- Laravel default `notifications` table for in-app notifications

**Billing and add-ons**
- `plans`: name, stripe_price_id, monthly_price, included_minutes, overage_price_per_minute, features (JSON)
- `addons`: slug, name, description, stripe_price_id, pricing_type (recurring / one_time / usage), requires_connections (JSON), is_active
- `client_addons`: client_id, addon_id, status (pending_setup / active / paused / cancelled), stripe_subscription_item_id, activated_at, config (JSON)
- `usage_records`: client_id, metric (minutes / sms / ai_messages), quantity, period, reported_to_stripe_at
- Cashier tables: `subscriptions`, `subscription_items`

**Integrations**
- `integrations`: client_id, provider (google_business / meta / search_console / quickbooks / website), credentials (encrypted), scopes, status, last_synced_at
- `webhook_logs`: provider, event, payload, processed_at, error

**Support**
- `support_tickets` / `ticket_messages`: client_id, user_id, subject, status, priority

---
## 4. Phase 1: MVP (Launch-Ready Core)

> **Phase 1 goal:** A paying client can sign up, connect their phone and calendar, and have your agents answer calls, book appointments into their real calendar, and notify them instantly. You can bill them through Stripe.

### 4.1 Authentication and Accounts (AUTH)

- [ ] **AUTH-01** (P0, M) Login, logout, password reset, email verification for all roles. Login link on the marketing website goes to the right portal.
- [ ] **AUTH-02** (P0, M) Two-factor authentication (authenticator app; SMS as backup). Required for admins, supervisors, and agents; optional but encouraged for clients.
- [ ] **AUTH-03** (P0, S) Session security: idle timeout for agents, login throttling, "log out all devices".
- [ ] **AUTH-04** (P0, M) Client team invites: owner invites staff by email with a role (staff / viewer).
- [ ] **AUTH-05** (P1, S) "Sign in with Google" and "Sign in with Microsoft" for clients.
- [ ] **AUTH-06** (P1, S) Profile page: name, photo, phone, timezone, password, 2FA, active sessions.

### 4.2 Admin Portal (ADM)

- [ ] **ADM-01** (P0, M) Admin dashboard: active clients, agents online, calls today, MRR, failed payments, onboarding pipeline.
- [ ] **ADM-02** (P0, L) Client management: list, search, filter by status/plan; view full client profile; edit any client setting; pause/cancel account.
- [ ] **ADM-03** (P0, M) Agent management: create agents, assign teams, languages, skills; deactivate agents.
- [ ] **ADM-04** (P0, M) Assign clients to agent teams (and optional dedicated agent).
- [ ] **ADM-05** (P0, M) Impersonation: admin can "view as client" for support. Must be logged in the audit log and show a visible banner.
- [ ] **ADM-06** (P0, M) Plans and add-ons configuration: create/edit plans and add-ons, linked to Stripe prices. No code changes needed to add a plan.
- [ ] **ADM-07** (P0, S) Call outcome codes management (the list agents choose from).
- [ ] **ADM-08** (P0, M) Phone number management: search and buy numbers through Twilio, assign to a client, release numbers.
- [ ] **ADM-09** (P1, M) Global search: find any client, caller, call ID, or appointment.
- [ ] **ADM-10** (P1, S) Audit log viewer with filters.
- [ ] **ADM-11** (P1, M) Coupon and trial management (synced with Stripe).

### 4.3 Client Onboarding Wizard (ONB)

> Self-serve, step-by-step, with a progress bar. The client can leave and return; progress is saved. Admin can see where each client is stuck.

- [ ] **ONB-01** (P0, M) Step 1: Business details: name, industry, address, timezone, website, logo.
- [ ] **ONB-02** (P0, M) Step 2: Services: name, description, price (text, e.g. "From $150"), duration, buffer. Industry templates pre-fill common services (plumber, cleaner, dentist, salon, etc.).
- [ ] **ONB-03** (P0, S) Step 3: Business hours, holidays, and service area (ZIP codes).
- [ ] **ONB-04** (P0, M) Step 4: Script builder: greeting, questions to ask, FAQs, do's and don'ts, what counts as an emergency, escalation phone number. Show a live preview of what the agent will see.
- [ ] **ONB-05** (P0, M) Step 5: Call handling rules: after hours behavior, emergency transfer, VIP numbers, block list.
- [ ] **ONB-06** (P0, M) Step 6: Phone setup. Option A: get a new local number (select area code). Option B: forward existing number, with carrier-specific forwarding guides (AT&T, Verizon, T-Mobile, Comcast, RingCentral, Google Voice) and a "test my forwarding" button that places a test call.
- [ ] **ONB-07** (P0, M) Step 7: Connect calendar (Google / Microsoft) — see CAL epic. Can be skipped and done later.
- [ ] **ONB-08** (P0, M) Step 8: Notification preferences (see NTF epic).
- [ ] **ONB-09** (P0, M) Step 9: Choose plan and enter payment (Stripe Checkout). Account goes `active` only after successful payment or trial start.
- [ ] **ONB-10** (P0, S) Completion screen + welcome email + internal alert to ops team to review the script before going live.
- [ ] **ONB-11** (P1, S) "Go live" approval: ops admin reviews the client's script and flips the client to live. Calls route to agents only after this.

### 4.4 Telephony (TEL) — Twilio

- [ ] **TEL-01** (P0, M) Twilio account setup: subaccount per environment, API keys in `.env`, webhook URLs configured per number.
- [ ] **TEL-02** (P0, S) Webhook security: validate every Twilio request signature (middleware). Log all webhooks in `webhook_logs`.
- [ ] **TEL-03** (P0, L) Inbound call flow (TwiML):
  1. Identify client from the dialed number (`To`).
  2. Check spam/block list → reject or flag.
  3. Check business rules (hours, VIP, holidays).
  4. Play recording consent message ("This call may be recorded for quality purposes").
  5. Put caller in queue for that client's agent team.
  6. If no agent answers within X seconds → follow fallback rule (voicemail / transfer to owner / AI agent later).
- [ ] **TEL-04** (P0, L) Call routing with Twilio TaskRouter: workers = agents, task queues per team/skill/language, priority for VIP and dedicated-agent clients. Agent status in the app syncs with TaskRouter worker activity.
- [ ] **TEL-05** (P0, L) Browser softphone with Twilio Voice JS SDK: answer, hang up, mute, hold, DTMF keypad, audio device selection, connection quality indicator. Access tokens issued by the backend.
- [ ] **TEL-06** (P0, M) Call recording: dual-channel recording, saved to private S3 via recording status callback, playable only through signed URLs, access logged.
- [ ] **TEL-07** (P0, M) Call lifecycle tracking: store every status event (`call_events`) and compute duration, wait time, billable seconds.
- [ ] **TEL-08** (P0, L) Transfers: cold transfer to owner; warm transfer using Twilio Conference (agent talks to owner first, then connects caller). If owner doesn't answer, return to agent.
- [ ] **TEL-09** (P0, M) Voicemail fallback with transcription; creates a call record and notifies client.
- [ ] **TEL-10** (P0, M) Outbound callback from agent workspace and from client portal (calls show the client's business number as caller ID).
- [ ] **TEL-11** (P0, M) SMS sending/receiving via Twilio Messaging Service. **A2P 10DLC brand and campaign registration is required** before SMS goes live in the US — start this early, approval takes time.
- [ ] **TEL-12** (P1, M) Spam detection: Twilio Lookup (line type / caller name) + internal block list + manual "mark as spam" which updates the list.
- [ ] **TEL-13** (P1, S) Usage counting: billable minutes and SMS per client per billing period → `usage_records`.

### 4.5 Agent Workspace (AGT)

> Replace the current Agent Dashboard with a call-focused workspace. The agent's screen must be fast and clear under pressure.

- [ ] **AGT-01** (P0, M) Agent status bar: Available / On Call / After-Call Work / Break / Offline. Synced with TaskRouter. Status changes logged in `agent_status_logs`.
- [ ] **AGT-02** (P0, M) Incoming call alert: ring sound, client name, caller number, wait time, Accept button. Real-time via Reverb.
- [ ] **AGT-03** (P0, L) **Screen pop** on answer, auto-loaded (no manual "Select Client"):
  - Client business name, local time, and greeting script (big and visible)
  - Services and prices, FAQs, do's and don'ts, emergency rules
  - Service area check (enter ZIP → in area / out of area)
  - Caller recognition: if the number exists in contacts → name, history, past appointments
- [ ] **AGT-04** (P0, L) **Live booking panel**: client's real availability (business hours − existing appointments − synced busy blocks − buffers), select service → shows valid slots in client's timezone → book in one click → writes to the client's connected calendar.
- [ ] **AGT-05** (P0, M) Per-client intake form (custom fields defined by client) shown during the call.
- [ ] **AGT-06** (P0, M) Call log form redesign: pre-filled call ID, client, date/time, caller number, agent (all automatic). Agent fills caller name, email, reason, notes. **Outcome code is required** before the agent can return to Available.
- [ ] **AGT-07** (P0, M) Escalation buttons: Warm transfer, Cold transfer, Mark urgent (instant SMS + push to owner), Create callback task.
- [ ] **AGT-08** (P0, S) After-call work timer (configurable max, e.g. 60 seconds) then auto-return to Available.
- [ ] **AGT-09** (P0, M) Queue view: calls waiting, which client, wait time.
- [ ] **AGT-10** (P0, M) Keep and improve existing "My Duty Schedule": shifts from the `shifts` table, current-time indicator, hours this week/today.
- [ ] **AGT-11** (P1, M) Shift swap and leave requests (approved by supervisor).
- [ ] **AGT-12** (P1, M) My performance page: calls handled, average handle time, booking rate, QA score, trend charts.
- [ ] **AGT-13** (P1, M) Agent knowledge base: per-client notes and training material, global SOPs, searchable.
- [ ] **AGT-14** (P1, S) Recent calls list (agent's own) with ability to add notes to a completed call within a time window.
- [ ] **AGT-15** (P1, S) Keyboard shortcuts for common actions (answer, hold, transfer, save).

### 4.6 Calendar Integration (CAL)

- [ ] **CAL-01** (P0, L) **Google Calendar OAuth** connection: request calendar scopes, store tokens encrypted, auto-refresh tokens, let client choose which calendar to book into and which calendars count as "busy".
- [ ] **CAL-02** (P0, L) **Microsoft Outlook / 365 OAuth** via Microsoft Graph: same features as Google.
- [ ] **CAL-03** (P0, L) **Two-way sync**:
  - Our appointments → created/updated/deleted in their calendar.
  - Their calendar events → stored as `busy_blocks` so agents never double-book.
  - Use incremental sync (Google sync tokens, Graph delta queries).
- [ ] **CAL-04** (P0, M) **Push updates**: Google watch channels and Microsoft Graph subscriptions so changes arrive within seconds. Both expire — a scheduled job must **renew them before expiry**. Fallback polling every 10–15 minutes if push fails.
- [ ] **CAL-05** (P0, M) Availability engine (one service class, heavily tested): business hours, holidays, service duration, buffer time, minimum notice, max bookings per day, busy blocks, timezone + daylight saving time.
- [ ] **CAL-06** (P0, M) Connection health: detect revoked/expired access, show warning banner to client, email them, show warning on agent screen ("Calendar not synced — confirm with owner before booking").
- [ ] **CAL-07** (P0, M) Client calendar view in portal (Day / Week / Month — extend existing Service Schedule Calendar) showing our appointments + synced busy blocks.
- [ ] **CAL-08** (P1, M) Approval mode: appointments booked by agents are `pending_approval` until the owner approves (from notification or portal); auto-confirm mode as alternative.
- [ ] **CAL-09** (P1, M) Customer-facing confirmation SMS/email with reschedule and cancel links (signed URLs, no login needed).
- [ ] **CAL-10** (P2, L) Travel-time aware scheduling for on-site services (use addresses + a maps API to add drive time between jobs).
- [ ] **CAL-11** (P2, L) Additional integrations: Calendly, Jobber, Housecall Pro, Square Appointments, Apple iCloud (CalDAV).

### 4.7 Client Portal: Calls, Inbox, and Dashboard (CLI)

- [ ] **CLI-01** (P0, M) Redesigned client dashboard: summary cards (calls, bookings, urgent items, callbacks pending) with Daily / Weekly / Monthly toggle — fix to use real data.
- [ ] **CLI-02** (P0, L) **Calls inbox**: list with filters (all / needs action / urgent / bookings / callbacks / spam), search, date range. Each call shows caller, outcome, agent, duration, notes.
- [ ] **CLI-03** (P0, M) Call detail page: recording player, notes, outcome, linked appointment, linked contact, action buttons (call back, text back, mark handled).
- [ ] **CLI-04** (P0, M) Callback tasks list: owner marks as done; overdue highlighted.
- [ ] **CLI-05** (P0, M) Settings pages to edit everything from onboarding later: business info, services, hours, script, FAQs, rules, phone, calendar, notifications.
- [ ] **CLI-06** (P0, S) Vacation / "do not disturb" mode with date range and custom handling instructions for agents.
- [ ] **CLI-07** (P0, M) CSV export of calls and appointments.
- [ ] **CLI-08** (P1, M) Two-way SMS conversation with a caller from the portal (threaded view).
- [ ] **CLI-09** (P1, M) Support: create ticket, view ticket status, reply. Plus a "Request script change" shortcut.

### 4.8 Notifications (NTF)

- [ ] **NTF-01** (P0, M) Notification events: new call, new booking, booking needs approval, urgent call, callback requested, voicemail, calendar disconnected, payment failed, usage at 80% / 100%.
- [ ] **NTF-02** (P0, M) Channels: in-app (real-time bell via Reverb), email, SMS. Each user chooses channels per event in preferences.
- [ ] **NTF-03** (P0, S) Urgent calls always notify by SMS regardless of preferences (can't be turned off, only re-routed to another number).
- [ ] **NTF-04** (P0, M) Daily summary email to owner: calls, bookings, pending actions. Sent at their chosen time in their timezone.
- [ ] **NTF-05** (P1, M) Web push notifications (browser) — prepares for mobile push later.
- [ ] **NTF-06** (P2, M) WhatsApp notifications via Twilio WhatsApp.
- [ ] **NTF-07** (P1, S) Quiet hours: non-urgent notifications held until morning.

### 4.9 Billing and Payments (BIL) — Stripe + Laravel Cashier

- [ ] **BIL-01** (P0, M) Stripe account setup (US entity / Stripe Atlas or partner if needed), test + live keys, Cashier installed on the `Client` model (billing belongs to the business, not the user).
- [ ] **BIL-02** (P0, L) Subscriptions: monthly plans with included minutes; plan upgrade/downgrade with proration; cancel at period end; resume.
- [ ] **BIL-03** (P0, L) Usage-based overage billing: report billable minutes (and later SMS / AI usage) to Stripe metered prices via a scheduled job. Idempotent — never double-report.
- [ ] **BIL-04** (P0, M) Stripe webhooks handled: `invoice.paid`, `invoice.payment_failed`, `customer.subscription.updated/deleted`, `checkout.session.completed`. Verify signatures.
- [ ] **BIL-05** (P0, M) Billing page in client portal: current plan, usage meter (e.g. "312 / 400 minutes"), next invoice estimate, payment methods, active add-ons.
- [ ] **BIL-06** (P0, S) Invoice history with **PDF download** for each invoice.
- [ ] **BIL-07** (P0, M) Failed payment handling (dunning): Stripe Smart Retries + emails + in-app banner; after grace period (e.g. 7 days) → account `paused` (calls go to voicemail) with clear warning before.
- [ ] **BIL-08** (P0, S) Stripe Tax enabled for US sales tax where applicable. (Confirm tax obligations with an accountant.)
- [ ] **BIL-09** (P0, S) Stripe Customer Portal link for card updates and receipts.
- [ ] **BIL-10** (P1, M) Free trial support (card required or not — business decision) and coupons.
- [ ] **BIL-11** (P1, M) Usage alerts at 80% and 100% + optional auto-upgrade.
- [ ] **BIL-12** (P1, M) Admin billing overview: MRR, churn, failed payments, revenue by plan and add-on.
- [ ] **BIL-13** (P2, M) Referral program: referral link, credit applied as Stripe customer balance.

### 4.10 Compliance and Security Basics (CMP)

- [ ] **CMP-01** (P0, S) Recording consent message played on every recorded call (needed for all-party consent states like California, Florida, Pennsylvania, Washington and others).
- [ ] **CMP-02** (P0, M) A2P 10DLC registration for SMS (see TEL-11). Store SMS opt-in/opt-out; honor STOP/HELP keywords automatically.
- [ ] **CMP-03** (P0, M) Encryption: OAuth tokens and integration credentials encrypted with Laravel's encrypted casts; HTTPS everywhere; S3 server-side encryption.
- [ ] **CMP-04** (P0, M) Role-based access to recordings and personal data; every recording view logged.
- [ ] **CMP-05** (P0, S) Data retention settings: how long recordings and transcripts are kept (per plan / per client), with a scheduled cleanup job.
- [ ] **CMP-06** (P0, M) Legal pages wired up (Terms, Privacy, DPA, BAA, Cookie Notice) + checkbox acceptance with version tracking at signup.
- [ ] **CMP-07** (P1, M) Client data export and account deletion (CCPA-style requests).
- [ ] **CMP-08** (P1, L) **HIPAA readiness** (only if serving healthcare clients): signed BAAs with Twilio, AWS, and any AI/transcription vendor; minimum-necessary access; extra logging; HIPAA-flag per client that restricts which vendors/features are used.
- [ ] **CMP-09** (P1, M) Agent workstation rules for offshore agents: IP allowlist or VPN for agent portal, no downloads of recordings, screen watermark with agent ID (optional).

---
## 5. Phase 2: Growth (Add-ons, CRM, Supervision, ROI)

### 5.1 Add-on Framework (ADD)

> Build the framework once; each add-on plugs into it.

- [ ] **ADD-01** (P0, L) Add-on marketplace page in client portal: cards with name, description, price, required connections, "Activate" button, active badge.
- [ ] **ADD-02** (P0, M) Activation flow: confirm price → add Stripe subscription item (prorated) → `client_addons` record → run the add-on's setup steps (e.g. "Connect your Facebook page").
- [ ] **ADD-03** (P0, M) `AddonContract` interface in code: `activate()`, `deactivate()`, `setupSteps()`, `healthCheck()`, `usage()`. Each add-on is its own class/module.
- [ ] **ADD-04** (P0, M) Deactivation: remove subscription item at period end, disconnect integrations, keep data per retention rules.
- [ ] **ADD-05** (P1, L) Add-on fulfillment board in admin (for human-delivered add-ons like SEO and social media): tasks per client, assignee, due dates, status, monthly deliverables checklist.
- [ ] **ADD-06** (P1, M) Integrations hub page in client portal: all connected accounts (calendar, Google Business Profile, Meta, website), status, reconnect buttons.

### 5.2 First Add-ons (easiest to sell)

- [ ] **ADN-01** (P0, M) **Missed-Call Text-Back**: if a call isn't answered (or caller hangs up in queue), auto-send an SMS from the client's number with a custom message and booking link.
- [ ] **ADN-02** (P0, L) **Appointment Reminders**: SMS/email reminders (e.g. 24h and 2h before), confirm/cancel by reply, no-show tracking.
- [ ] **ADN-03** (P1, L) **Review Management**:
  - Connect Google Business Profile.
  - After an appointment is marked completed → send review request SMS.
  - Pull in new reviews, alert on 1–3 star reviews, AI-drafted reply for owner approval, post reply via API.
- [ ] **ADN-04** (P1, L) **Deposits and Payments at Booking**: Stripe Connect (Express accounts) for clients; collect deposit or full payment link by SMS when appointment is booked; your platform fee optional.
- [ ] **ADN-05** (P1, M) **Bilingual (Spanish) Agent**: routes calls to Spanish-skilled TaskRouter queue; language selection IVR option ("Para español, oprima 2").
- [ ] **ADN-06** (P1, M) **Dedicated Agent**: always routes to the assigned agent first, team as fallback.
- [ ] **ADN-07** (P2, L) **Outbound Follow-up**: client uploads leads or connects web form; agents call through an outbound queue; results logged. Must follow TCPA rules (consent, calling hours, do-not-call).

### 5.3 Mini CRM (CRM)

- [ ] **CRM-01** (P0, M) Contacts auto-created/updated from every call (matched by phone number).
- [ ] **CRM-02** (P0, M) Contact detail: all calls, appointments, messages, notes, tags, total spend (estimated or from payments).
- [ ] **CRM-03** (P1, S) Tags (new lead, repeat, VIP), search, filters, CSV import/export.
- [ ] **CRM-04** (P1, M) Merge duplicate contacts.

### 5.4 ROI and Reports (RPT)

- [ ] **RPT-01** (P0, M) **ROI dashboard** for clients: calls answered, leads captured, appointments booked, after-hours calls caught, estimated revenue (bookings × average job value, editable by client).
- [ ] **RPT-02** (P0, M) Monthly report email + PDF: "This month we answered X calls and booked $Y in jobs for you."
- [ ] **RPT-03** (P1, M) Call analytics: calls by hour/day (heatmap), outcomes breakdown, average wait time, top call reasons.
- [ ] **RPT-04** (P1, M) Admin analytics: calls per client, cost vs. revenue per client (profitability), agent utilization.

### 5.5 Supervisor Portal (SUP)

- [ ] **SUP-01** (P0, L) Live wallboard: agents and their status, calls in queue per team, longest wait, service level % (answered within 20s), today's totals. Real-time.
- [ ] **SUP-02** (P1, L) Live call monitoring via Twilio Conference: listen, whisper (coach agent; caller can't hear), barge in.
- [ ] **SUP-03** (P0, M) Shift planning: create/edit shifts for agents, approve swap/leave requests, coverage view (shows gaps by hour).
- [ ] **SUP-04** (P1, M) QA reviews: random call sampling, scorecard form (greeting, accuracy, booking attempt, tone, compliance), agent can view feedback.
- [ ] **SUP-05** (P1, M) Agent performance comparison and coaching notes.
- [ ] **SUP-06** (P2, M) Call volume forecast by hour (from historical data) to suggest staffing.

---

## 6. Phase 3: AI Layer and Marketing Add-ons

### 6.1 AI Foundation (AI)

- [ ] **AI-01** (P0, M) `AiService` wrapper: one place for all LLM calls (provider switchable), prompt templates stored in code/version-controlled, token usage logged per client for billing and cost control.
- [ ] **AI-02** (P0, M) Post-call transcription job (queue) for every recorded call → `call_transcripts`.
- [ ] **AI-03** (P0, M) AI call summary + suggested outcome + urgency + sentiment, shown in client inbox and agent recent calls.
- [ ] **AI-04** (P0, S) Respect HIPAA flag: only use vendors with signed BAAs for HIPAA clients, or disable AI features for them.

### 6.2 AI Add-ons and Features

- [ ] **AIX-01** (P1, XL) **AI Chatbot add-on**:
  - Trained on client profile (services, FAQs, hours, area) — no manual training needed.
  - Website widget: one-line embed script + WordPress plugin, customizable colors/greeting.
  - Can check availability and book appointments (uses CAL-05 availability engine).
  - Handoff to human: collects details and creates a callback task / notifies owner.
  - Channels: website first, then Facebook Messenger, Instagram DMs, WhatsApp Business (Meta app review required).
  - Conversation inbox in client portal; usage metering (messages/month).
- [ ] **AIX-02** (P1, L) **AI Agent Copilot** (agent workspace): live transcription during calls, real-time suggested answers from client FAQ, auto-fill of the call log form (name, email, reason) for agent to confirm.
- [ ] **AIX-03** (P1, M) **AI QA**: score every call automatically against the QA scorecard; supervisors review only flagged calls.
- [ ] **AIX-04** (P2, XL) **AI After-Hours Voice Agent add-on**: AI answers when no human is available (night/overflow), uses the same client knowledge, can book appointments, and hands off/escalates urgent calls to owner. Clear disclosure that caller is speaking with an AI.
- [ ] **AIX-05** (P2, M) **Business insights**: weekly AI digest ("30% of calls ask about pricing — add it to your website").
- [ ] **AIX-06** (P2, M) **"Ask your business"** search box: owner asks questions in plain English ("How many bookings this week?") answered from their own data only.

### 6.3 Marketing Add-ons

- [ ] **MKT-01** (P1, L) **Local SEO add-on**:
  - Connect Google Business Profile + Google Search Console + website.
  - Profile completeness score and recommendations.
  - Keyword rank tracking (third-party rank API) for chosen local keywords.
  - Monthly SEO report (auto-generated PDF) + fulfillment tasks for your SEO team (ADD-05).
- [ ] **MKT-02** (P2, L) **Social Media Posting add-on**: connect Facebook, Instagram, Google Business Profile (LinkedIn later); content calendar; AI-generated post drafts; owner approval; scheduled publishing.
- [ ] **MKT-03** (P2, L) **Email/SMS Campaigns**: send to CRM contacts with consent only; templates; unsubscribe handling; TCPA/CAN-SPAM compliance.
- [ ] **MKT-04** (P2, XL) **Simple Website / Landing Page add-on**: template-based booking page on a subdomain or custom domain.

### 6.4 More Integrations (INT)

- [ ] **INT-01** (P2, M) Zapier app / outgoing webhooks (new call, new booking) so clients can connect any tool.
- [ ] **INT-02** (P2, M) QuickBooks Online: sync customers and create invoices.
- [ ] **INT-03** (P2, L) Field service tools: Jobber, Housecall Pro.

---

## 7. Phase 4: Scale and Enterprise Readiness

- [ ] **SCL-01** (P1, M) Load testing: simulate peak call volume and many agents connected to Reverb at once.
- [ ] **SCL-02** (P1, M) Database indexing review (calls by client + date, appointments by client + time, contacts by phone).
- [ ] **SCL-03** (P1, M) Read replica / reporting database for heavy analytics queries.
- [ ] **SCL-04** (P2, XL) SOC 2 Type I preparation (policies, access reviews, vendor management) — use a compliance platform (e.g. Vanta, Drata).
- [ ] **SCL-05** (P2, M) Multi-location support for clients that grow beyond solo (one account, several locations/calendars).
- [ ] **SCL-06** (P2, L) White-label / reseller option (agencies resell your service under their brand).

---

## 8. Phase 5: Mobile App Preparation

> The app itself comes later. These backend tasks make sure it's easy when the time comes.

- [ ] **MOB-01** (P1, M) Complete API coverage for all client portal features (`/api/v1`), documented with OpenAPI (e.g. Scribe package).
- [ ] **MOB-02** (P1, M) Push notifications backend: device token storage, Firebase Cloud Messaging / APNs via Laravel notification channel.
- [ ] **MOB-03** (P1, S) Sanctum token auth for mobile with device names and revoke.
- [ ] **MOB-04** (P2, M) Deep links (notification → specific call or appointment screen).

---

## 9. Cross-Cutting Requirements (apply to every task)

### Testing
- [ ] Feature tests (Pest) for every endpoint, including **tenant isolation** tests.
- [ ] Unit tests for the availability engine (timezones, DST changes, buffers, holidays) — this is the most bug-prone code.
- [ ] Webhook tests with recorded sample payloads (Twilio, Stripe, Google, Microsoft).
- [ ] Use Stripe test clocks to test subscription renewals, failed payments, and trials.

### Security
- [ ] All forms validated with Form Requests; all access checked with Policies.
- [ ] Rate limiting on login, API, public booking links, and chatbot endpoints.
- [ ] Secrets only in `.env` / secret manager, never in Git.
- [ ] Dependency vulnerability checks in CI (`composer audit`, `npm audit`).

### Reliability
- [ ] All webhooks: verify signature → log → queue job → return 200 fast. Jobs are idempotent (safe to run twice).
- [ ] Failed jobs monitored in Horizon with alerts (Slack/email).
- [ ] Daily database backups with tested restore; recordings bucket versioning.
- [ ] Uptime monitoring on app, webhook endpoints, and Reverb.
- [ ] Status page for clients (optional, P2).

### UX
- [ ] Responsive on tablet and mobile browsers (owners will check from their phones before the app exists).
- [ ] Dark theme kept consistent; accessible color contrast.
- [ ] Empty states with helpful next steps (e.g. "No calls yet — test your forwarding").
- [ ] Loading skeletons and clear error messages.

### Documentation
- [ ] `README.md` with local setup steps.
- [ ] `docs/` folder: architecture, telephony flow diagram, calendar sync design, billing rules, runbooks (what to do if Twilio/Stripe webhooks fail).

---

## 10. Definition of Done

A task is **done** only when:
1. Code is merged through a reviewed pull request.
2. Tests are written and passing in CI.
3. It works on staging with test Stripe/Twilio accounts.
4. Tenant isolation and permissions are checked.
5. Sensitive actions are audit-logged.
6. Any new setting or feature is documented in `docs/`.

---

## 11. Open Decisions (decide before or during Phase 0)

- [ ] **DEC-01** Frontend approach: keep current setup, or standardize on Livewire vs. Inertia (Vue/React)?
- [ ] **DEC-02** Pricing model: per minute, per call, or bundles? Overage price? Trial length? Card required for trial?
- [ ] **DEC-03** Business hours of your agent team: 24/7 or US business hours only? (Affects AI after-hours priority.)
- [ ] **DEC-04** Will you serve healthcare clients at launch? (If yes, HIPAA tasks become P0.)
- [ ] **DEC-05** Approval mode default: auto-confirm bookings or owner approval?
- [ ] **DEC-06** Hosting provider and region (US region recommended for US client data).
- [ ] **DEC-07** Transcription and LLM vendors (cost, accuracy, BAA availability).
- [ ] **DEC-08** US legal entity and Stripe account setup for receiving payments.

---

## 12. Suggested Build Order (Phase 1)

```
FND (audit, fixes, tenancy, roles)
   └─► AUTH ──► ADM (basic) ──► ONB (steps 1–5)
                                   │
         ┌─────────────────────────┼─────────────────────────┐
         ▼                         ▼                         ▼
   TEL (inbound, softphone)   CAL (OAuth, sync,        BIL (plans, Cashier,
         │                     availability engine)      webhooks)
         └──────────┬──────────────┘                         │
                    ▼                                        │
             AGT (screen pop, booking, call log)             │
                    │                                        │
                    ▼                                        ▼
             CLI (inbox, dashboard) + NTF ──────► ONB (steps 6–9) ──► CMP ──► LAUNCH
```

Calendar sync and telephony can be built in parallel by two developers since they only meet in the agent workspace.
