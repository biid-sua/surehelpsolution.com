# Decision Log

Decisions taken on the product owner's behalf (delegated 2026-10-03, with the goal of **a world-class product**). Each entry records what was decided, why, and what would make us revisit it. Any of these can be overridden. Add a new entry rather than editing an old one.

Guiding rule when trade-offs conflict (spec §118): security → data integrity → tenant isolation → correct behaviour → reliability → maintainability → performance → accessibility → UX → polish.

---

## D1 — Existing clients become organizations
**Decision:** Each existing `client` user becomes one **Organization**. The organization's name starts as the user's name, and that user becomes its **Business Owner**. Organizations get a public ULID, a slug and a status (`active` for migrated clients). The timezone is left empty and the owner is asked to set it, because guessing a business's timezone is worse than asking.
**Why:** It's a 1:1 mapping with no ambiguity, and it unlocks staff users, multiple locations and billing per business.
**Revisit if:** one real business turns out to have several client logins. They can be merged into one organization later.

## D2 — Calls that don't map cleanly
**Decision:** The backfill assigns calls in this order:
1. `client_id` points to a client → that client's organization (`ownership_source = client_id`).
2. Otherwise, the caller email matches **exactly one** client → that organization, flagged `ownership_source = email_match` so an admin can review it. This keeps everything a client can see today.
3. Otherwise → no organization (`ownership_source = unassigned`). Platform admins see these in a review queue and can assign them.

No call is deleted or silently hidden from the platform team.
**Why:** It preserves today's visibility for genuine cases, ends the cross-tenant leak (a call with a valid `client_id` is never re-assigned by email), and leaves an audit trail.

## D3 — Agent access to organizations
**Decision:** **Least privilege by design, no disruption at cut-over.** Agents only reach organizations they are assigned to, enforced on server, policy and query. The migration assigns every active agent to every migrated organization, marked `source = migration`, so operations continue unchanged. New organizations get explicit assignments. The admin Assignments screen lets ops narrow access.
**Why:** World-class tenant isolation without risking unanswered client calls on deploy day.

## D4 — Remove caller-email matching from client views
**Decision:** Yes. Once calls carry an `organization_id`, every client query is scoped by organization only. Email matching survives only as the one-time, flagged backfill rule in D2.

## D5 — Frontend stack
**Decision:** **Blade + Livewire 3 + Alpine.js + Tailwind CSS 4, built with Vite.** It comes with an in-house component library (`x-ui.*`) on design tokens that keep the dark navy/purple brand. Chart.js and FullCalendar are bundled through npm with pinned versions; no runtime CDNs. Every web screen is built on this stack; there are no Bootstrap or "classic" screens (D23).
**Why:**
- Authorization stays on the server for every interaction, which is critical for tenancy and permissions.
- One language and one codebase.
- Real-time updates come later through Livewire + Reverb.
- Tailwind 4 and Vite are already in `package.json`.
- The mobile apps use the `/api/v1` API anyway, so a separate SPA would mean duplicate work.

Inertia + React would mean a full rewrite plus a second state layer, without meaningful benefit for this product.
**Revisit if:** we need offline-first or highly interactive canvas-style features. Those can be dropped into individual pages as islands.

## D6 — Hosting
**Decision:**
- **Target production:** Laravel Cloud, or Laravel Forge on DigitalOcean/AWS in a **US region**. Managed MySQL 8, Redis (cache/queue/sessions), Horizon workers, scheduler, Reverb WebSockets, private S3-compatible object storage, separate **staging**, zero-downtime deploys from Git.
- **Until migration:** code stays runnable on the current cPanel host (database queue + cron worker), so nothing blocks shipping.

**Action needed from you:** create the hosting account and a GitHub repository; billing accounts are yours to own. Shared cPanel hosting is the main thing between this codebase and a world-class operation (no Redis, no WebSockets, no long-running workers, no isolated staging).

## D7 — Mobile API compatibility
**Decision:** Treat the existing mobile app as **live**. Every current `/api/v1` path, request field and response field is preserved. Changes are additive only (new fields, new endpoints). Contract tests guard the existing response keys.

## D8 — Sessions, tokens, login security
**Decision:**
- API tokens expire after **30 days** and are named per device; users can list and revoke their devices.
- Web sessions last 120 min, with an **idle timeout of 30 min for agents and platform staff**.
- Login is rate-limited (done, HF-5).
- **2FA (authenticator app) is mandatory for platform staff and agents**, and optional (encouraged) for client users.
- Login history is recorded.
- Secure, HttpOnly, SameSite=Lax cookies in production.

## D9 — Roles at launch
**Decision** (permission names exactly as spec §5):

| Scope | Roles |
|---|---|
| Platform (global) | **Super Admin**, **Operations Manager**, **Support Agent** |
| Service (global, limited to assigned orgs) | **Agent Supervisor**, **Agent** |
| Organization (per tenant) | **Business Owner**, **Business Manager**, **Staff** |

Billing Admin and Platform Admin are added with the billing phase. Existing users map as: `admin` → Super Admin, `agent` → Agent, `client` → Business Owner of their organization. `users.role` stays as the **portal type** (admin/agent/client) for routing and API compatibility. Actions are authorized by **permissions**, never by role names.
Implementation: `spatie/laravel-permission` with teams (team = organization).

## D9a — Amendment: how organization roles are stored (2026-10-03)
**Decision:** spatie/laravel-permission is used **without** its teams mode. Global staff roles (Super Admin, Operations Manager, Support Agent, Agent Supervisor, Agent) are Spatie roles. Organization roles (owner, manager, staff) are stored on the membership (`organization_user.role`) and mapped to permissions in `config/authorization.php`. One method, `User::hasPermissionIn($permission, $organization)`, behind Laravel's Gate, answers every permission question.
**Why:** In teams mode a role exists only inside one team context, which makes platform-wide roles (Super Admin must work in every organization) fragile and easy to get wrong. The chosen model is explicit, testable, and keeps agents limited to assigned organizations. Per-tenant custom roles can be added later as a new organization-role source without changing call sites.

## D13 — Default notifications and audit retention (2026-10-04)
**Decision:**
- Every call: **in-app only** by default.
- Missed or dropped calls, and callers asking for a call back: **in-app + email** by default, because they need action.
- Each person can change this under *Notifications*.
- SMS and mobile push are shown as "coming soon" until Twilio and FCM HTTP v1 are connected.
- Audit entries are kept **730 days** (`AUDIT_RETENTION_DAYS`), then pruned daily.

The audit log is visible to Super Admin and Operations Manager only, never to business owners (spec §62).
**Why:** Email for every call would train owners to ignore our emails. Missed calls and callbacks are where a delayed reaction costs the business money. Two years of audit history covers typical dispute and compliance look-backs without keeping personal data forever (spec §56).
**Revisit if:** a client in a regulated industry needs a longer retention period (a per-organization override can be added).

## D14 — Customer matching and business settings ownership (2026-10-05)
**Decision:**
- A call is matched to a customer by **normalized phone (E.164)** first, which is unique per business in the database, then by **email**, but only if exactly one customer has it. If neither matches and the caller gave a phone or email, a new *lead* is created. Calls with neither are not matched, which avoids nameless duplicates.
- A match only **fills empty fields**. Existing names, emails and addresses are never overwritten by what a caller says.
- Deleted customers are restored when they call again, instead of being duplicated.
- Business profile and hours are owner-only (`organization.update`). Services can also be managed by Business Managers (`settings.manage`). Staff can't open the Business section.
**Why:** Phone is the one identifier a receptionist always has. Fuzzy name matching creates wrong merges, which are worse than duplicates. Duplicate merging (CRM-04) comes later, with a human confirming.

## D15 — Call outcomes have fixed categories (2026-10-06)
**Decision:**
- Every outcome, platform default or business-defined, belongs to one of seven **categories**: booked, information, callback, escalated, missed, spam, other. Dashboards, filters, notifications, and from P2-4b tasks, read the **category**, never an outcome key. A business can add "Quote visit booked" and it counts as a booking everywhere.
- Platform defaults keep their original keys, so every existing call keeps its meaning. A business can **rename** a default or **switch it off**, but can't change what it means. Its own outcomes can be added, renamed, switched off, and deleted only while no call uses them.
- Agents can only save an outcome that is active for the call's business.
- The old admin "success rate" counted outcomes that never existed (`information-provided`, `service-completed`). It now counts the booked and information categories.
**Why:** Businesses describe calls in their own words. Fixed categories keep reporting comparable across businesses and stop custom wording from breaking KPIs.

## D16 — Tasks are the follow-up system of record (2026-10-06)
**Decision:**
- When a call's outcome is in the **callback** category, the business gets a **call-back task** (high priority, linked to the call and the customer). There is one task per call, however often the call is saved or edited.
- **Due time = one hour of business time.** If the business is open, the task is due an hour after the call. If it's closed, it's due an hour after it next opens. Without opening hours, it's due an hour after the call. A 9 PM call is never "overdue" overnight.
- The "pending follow-ups" number and the calls *Follow-ups* view count **open call-back and follow-up tasks**, not call statuses. Closing the task is how a business says "done".
- **Overdue:** one reminder per due date, sent to the assignee, or, when nobody is assigned, to everyone in the business who can see tasks. Changing the due date allows one new reminder. On deploy, existing waiting call-backs become tasks, and any already past due are marked as reminded, so nobody gets a burst of alerts.
- Tasks can only be assigned to active team members who can see tasks. Staff can create and work tasks (they hold `tasks.*`).
**Why:** A call-back that lives only as a call status gets forgotten. A task with an owner and a due time doesn't. Business-hours-aware due times stop false alarms.

## D17 — Escalations reach someone, every time (2026-10-06)
**Decision:**
- When an agent picks an outcome in the **escalated** category, an **escalation** is raised automatically. The agent picks one of the spec §25 types; it defaults to *urgent customer issue*. Priority follows the type (emergency and urgent issue → urgent; complaint, refund, technical → high; others → normal). Each call gets at most one active escalation.
- **Urgent escalations can't be switched off.** They always arrive in-app and by email, whatever the person's notification settings. **SMS** joins once messaging and A2P 10DLC are live (Phase 3), as NTF-03 requires. If nobody presses *I'm on it* within **15 minutes**, the team is alerted once more. Our operations team sees every unresolved escalation across businesses in the admin console, so they can phone the owner.
- Lifecycle: **open → acknowledged → resolved**. Resolving **requires a note** saying what was done; the note appears on the customer's timeline.
- Permissions: everyone in a business can view escalations. Owners, managers **and staff** can acknowledge, assign and resolve (staff are often the ones who deal with it). Agents can raise escalations but not resolve them.
**Why:** An emergency that waits on a notification setting or an unread email is a lost customer. Escalations must be impossible to miss and must leave a record of what was done.

## D18 — Appointments and double-booking protection (2026-10-07)
**Decision:**
- An appointment is an exact time range (UTC) with the business's timezone recorded. **Pending, tentative and confirmed** appointments hold their slot. Completed, no-show and cancelled ones free it. Undoing a completion re-checks that the time is still free.
- **No double booking (§88):** every booking, move and re-confirmation claims time in one place (`BookingGuard`). Inside a transaction it locks the business's row `FOR UPDATE`, checks for overlaps, then writes. Bookings for one business are serialized; different businesses never wait on each other. The overlap test uses `[start, end + service buffer)`, so back-to-back bookings are fine and cleanup time is respected. Verified with 10 simultaneous processes on MariaDB: exactly one succeeded. A clash is answered with the next free times, never a bare error (API: HTTP 409 with `suggestions`).
- **Calendars:** each location has its own calendar. An appointment without a location belongs to the whole business and clashes with everything, which is the right default for solo businesses.
- **Availability (§19, first version):** opening hours (split shifts, holidays, special hours, temporary closure), service length and buffer, existing bookings, a 60-minute minimum notice, and 30-minute start steps, all in the business's timezone (correct on DST days). Agents and automated booking must stay inside opening hours. The business itself may book any time, for example an evening favour. Connected-calendar busy times plug in behind the same service in Phase 3.
- Service visits noted on calls before appointments existed (date plus a vague window like "morning") are **not converted** into appointments, because inventing exact times would create false clashes. They stay visible on the calendar (all-day) and the dashboard schedule next to real appointments.
- A completed appointment turns a *lead* or *prospect* into a *customer*.
**Why:** Double booking is the most visible failure a receptionist service can make. A database lock is simple, correct on MySQL/MariaDB without exclusion constraints, and fast at our volumes.

## D19 — Knowledge base and business rules (2026-10-08)
**Decision:**
- **Knowledge items** have three visibility levels. *Can be shared with callers* (later also the website chatbot). *Agents and your team* (guidance, not read out). *Your team only* (never shown to agents or the AI). Agents see pinned items first, then emergency, agent and escalation guidance.
- **Rules are data, not code (§23).** Each rule has a type plus settings and is shown everywhere as a plain sentence. Five types are **enforced by the system**: latest booking start (optionally per service), booking window (minimum notice, maximum days ahead), service area (5-digit ZIP list; the ZIP is read from the visit address or the customer's address), details to collect before booking (address, phone or email), and automatic escalation for chosen call reasons. *Instruction* rules are free-text guidance shown to agents first, for example "never give final prices for custom jobs".
- Booking rules **shape the free times everyone is offered**. They **bind agents and automated booking**, while the business itself can still book anything from its portal (its own exception, for example an evening favour). An automatic-escalation rule applies whatever outcome the agent chose.
- Owners and managers (`settings.manage`) change rules. Owners, managers and agent supervisors (`knowledge_base.manage`) edit knowledge. Staff can't open the Business section (D14).
**Why:** Rules a person has to remember get forgotten under pressure. Rules the system checks don't. Plain-sentence rendering means the same rule reads identically to the owner, the agent and, later, the AI.

## D20 — The new agent workspace (2026-10-08)
**Decision:**
- Agents land on **/agent** after login. It shows only their assigned businesses (admins see all active and onboarding ones), each with open/closed status and local time, plus what needs attention across them: escalations, call-backs due and the next 24 hours of appointments. The previous agent dashboard has since been removed (D23). The mobile API is unchanged (D7).
- A business's workspace puts the **briefing next to the call** (§21): rules as sentences, emergency handling, knowledge the agent may use (team-only items hidden; caller-safe items marked), services with prices and agent instructions, hours, today's bookings, and the caller's history once identified.
- **Customer matching (§15):** typing a phone number or email shows the existing customer it belongs to. The agent must answer *Yes, it's them* or *Different person* before saving; nothing is merged silently. A different person on a known number gets their own record, and the number stays with its first owner (numbers are unique per business).
- The call, any booking and any follow-up are saved **in one transaction**. If the time was just taken or a rule blocks the booking, nothing is saved and the agent is offered the nearest free times. Agent bookings always follow opening hours and the business's rules.
- Access is re-checked on every action. An agent unassigned mid-call can't save into that business.
- All new tables store times in UTC whatever timezone a value carries (`StoresUtc`). This was found while building the workspace: Eloquent writes a date in its own timezone.
**Why:** On a live call the agent must not hunt through pages, and must not create duplicate customers or bookings the business can't honour. One screen, confirmed matches and atomic saves deliver both.

## D21 — How calendar sync works (2026-10-09)
**Decision:**
- **One OAuth app per provider, registered by SureHelp.** Each business connects its own account. Scopes are the minimum that works: Google `calendar.readonly` + `calendar.events`, Microsoft `Calendars.ReadWrite` (+ sign-in basics). Tokens are encrypted at rest and never reach the browser or the API.
- **Plain HTTPS adapters instead of the Google/Microsoft SDKs.** That means far smaller deploys on cPanel, everything is testable with faked responses, and provider details stay in two classes behind one interface (spec §17).
- **Busy times are mirrored, not looked up live.** During a call, availability and the double-booking guard read the local copy (fast, works if Google is slow). The copy is refreshed by push notifications within seconds and by polling every 10 minutes. Only times are stored, never what the events are. Busy times from a connection that needs reconnecting still count, which is safer than double-booking.
- **Our events in their calendar:** we write and update only events we created, and every update carries the version we last saw. If someone edited the event in Google/Outlook, we stop touching it and flag the booking for a person; we never overwrite their change (spec §16). Cancelling a booking removes our event; completed and no-show bookings stay as a record.
- A busy time in the business's calendar blocks bookings from everyone, including the business's own portal, the same as an existing appointment.
- **Lost access** (revoked, password change, expired) flags the connection once, alerts the owner (`integration.disconnected`), and shows agents "Calendar not synced: confirm before booking" on that business.
**Why:** The calendar the owner already lives in has to be the source of truth for when they're busy, and SureHelp must never be the reason a booking or a personal appointment gets silently changed.

## D22 — Billing through Payoneer, confirmed by a person (2026-10-10)
**Decision:**
- **SureHelp keeps its own billing records** for plans, subscriptions, invoices and payments. The payment provider only moves money. Changing provider later therefore changes one class (`PaymentGateway`), not the data.
- **Payoneer, the account already active, takes the payments.** Clients pay each invoice on a Payoneer payment-request page, by card or from a US bank account (ACH), or by bank transfer to the Payoneer receiving account.
- **Payoneer Checkout (automatic card capture with webhooks) is not used.** It currently requires a Hong Kong entity and roughly $20k a month in volume. So payments are **recorded by a person** in *Admin › Billing*, with the Payoneer transaction ID. Clients can press *I've paid* to tell us. Stripe, the original Phase 5 plan, waits for a US entity.
- **No automatic charging, no automatic suspension.** Invoices go out on the billing day, then up to three reminders a week apart. Whether to pause service stays a human decision.
- Money is stored in cents. Invoice numbers are gap-free per year (`INV-2026-0001`) and reserved under a lock. Each invoice keeps a copy of the client's billing details at the time it was issued. PDFs are made with dompdf (no external service).
- Plan changes apply at renewal (immediately during a trial). Cancellation applies at the end of the paid period. Billing days don't drift: the 31st becomes the last day in shorter months and comes back.
- No separate Billing Admin role yet (D9 planned one): Super Admin manages billing, and Operations Manager and Support Agent can view it. The role can be added as data when someone needs billing access without full admin rights.
**Why:** It lets SureHelp invoice and collect from US clients today with the account the owner already has, and the effort is limited to a minute of record-keeping per payment. Automation can be added later without migrating any data.

## D23 — One product, one UI (2026-10-11)
**Requirement from the product owner:** SureHelp launches as a fresh product, so there is one version of every screen and no "classic" versions.
**Decision:**
- The previous admin console and agent dashboard are deleted, not linked. That covers the Bootstrap pages for the admin dashboard, users, duty schedules, contact forms, the agent dashboard / classic call form, and the old password page, plus their controllers and routes. There are no redirects from the old URLs.
- What they did now lives in the shared design system:
  - *Admin › Users*: add staff, agents and business owners; temporary passwords shown once; reset password; switch off and sign out everywhere; change staff and agent roles.
  - *Admin › Duty schedule*: a week grid with overnight shifts, an overlap check and "copy last week".
  - *Admin › Website enquiries*: open/handled inbox with reply by email.
  - *Agent › My calls*: numbers by period, history and CSV export.
  - *Agent › My schedule*.
  - The first-login password page at `/account/password`.
- Only a Super Admin adds or changes staff accounts. Operations Managers manage agents and business owners. Website enquiries need `marketing.view` (Support Agents don't see sales leads).
- The mobile API (`/api/v1`, D7) is unchanged. The admin and agent endpoints for users, duty schedules and call logs stay for the apps.
- The public website (home and legal pages) is a separate marketing site, not a second version of the product. It keeps its own layout until its redesign, when it moves onto the design system and the CSP header can be switched on.
- From now on, rebuilding a screen means deleting the old one in the same change. Recorded in the master spec, §7 "One UI".
**Why:** Two versions of a screen double the testing, confuse users about which one is real, and keep old security problems alive. A new product has no existing users to migrate gently.

## D24 — Accounts: sign-in, two-step codes, invitations, terms (2026-10-12)
**Decision:**
- **Sign-in page in the product** at `/login`, built with the design system. The website's "Sign in" button links to it; the old pop-up is gone (D23). Guests opening a portal page go there and come back to the page they wanted.
- **Forgot password by email.** The answer is always the same, so nobody can find out whether an email has an account. Links expire after 60 minutes and work once. A reset signs the person out everywhere, mobile app included, and confirms their email address.
- **Two-step sign-in with an authenticator app** (TOTP, RFC 6238), as decided in D8. It is **mandatory for staff and agents**:
  - Without it they're sent to set it up before anything else.
  - The mobile app refuses them until it's set up, then asks for the code (`two_factor_code`).
  - It's optional for business owners and their teams.
  - Each code works once; replaying a code is refused.
  - Eight one-time recovery codes are stored hashed. The secret is encrypted at rest.
  - A Super Admin can reset someone's two-step sign-in when they lose their phone.
- **Sessions:**
  - **Idle timeout:** 30 minutes for staff and agents, none for business owners. Background refreshes (`wire:poll`) don't count as activity.
  - **Sign out everywhere:** every session carries the account's `session_epoch`, and bumping it ends all of that person's sessions at their next request, whatever the session driver. This happens on a password change or reset, on "Sign out other devices", when an account is switched off, and when two-step sign-in is reset. With database sessions the device list is live.
  - **Login history:** each sign-in is recorded with its method (password, app code, recovery code, invitation), and people can see their recent activity.
- **Team invitations (spec AUTH-04):**
  - **Who can invite:** owners invite managers and staff; managers invite staff. Only owners change roles or remove people.
  - **Joining:** invitations are emailed links valid for 7 days, and only a hash of the token is stored. The invited person picks their name and password and accepts the terms on the same form.
  - **One business per email:** each person uses SureHelp with one business (D1), so an email that already has an account can't be invited.
  - **Removing someone** signs them out everywhere. If they have no business left, their account is switched off.
- **Terms acceptance (spec CMP-06):**
  - **What's accepted:** everyone accepts the current Terms of Use and Privacy Policy; business users also accept the Data Processing Addendum.
  - **What's recorded:** each acceptance stores the version, time, IP and browser.
  - **Version changes:** versions are set in `config/account.php`; changing a version asks everyone again, with "we updated our terms".
- **Profile page** at `/account`:
  - Name, phone, your own timezone and password.
  - Changing your password signs you out everywhere else.
  - Confirm your email address.
  - Security: two-step sign-in, recovery codes, devices and mobile-app sign-ins, recent activity.
**Why:** These are what every user meets on day one, and what security reviews of a receptionist service ask about first: who can get in, how, and what's recorded.

## D25 — Setup wizard and Results (2026-10-13)
**Decision:**
- **Setup wizard** at `/app/setup` (spec ONB). There are seven steps:
  1. Your business (name, industry, timezone, contacts, average job value).
  2. Services.
  3. Hours & area (presets, emergencies, ZIP codes).
  4. Call handling (greeting, details to collect, FAQs, do's and don'ts, when to escalate).
  5. Calendar.
  6. Team.
  7. Go live.

  How it works:
  - Steps 1, 3 and 7 are required; the rest can be skipped and done later.
  - **Saves into the real records** (profile, services, hours, rules, knowledge), so the Business pages show the same data and nothing has to be re-entered. Saving a step again replaces what that step created; items added on the Business pages stay.
  - **Industry templates** live in `config/industries.php`: plumbing, HVAC, electrical, cleaning, dental, salon, legal, auto, other. They pre-fill services, emergency guidance, common FAQs and details to collect. The owner unticks and edits; nothing is saved without "Save and continue".
  - **Progress is saved per step.** New owners start in the wizard when they sign in, and the dashboard shows a "Finish setting up" banner until they're done. Admins see each business's progress, can filter "Still setting up", and get an email when one finishes.
  - **Finishing doesn't switch the business live.** The phone line still has to be connected, so SureHelp staff do the go-live check.
  - **Existing businesses** that already had hours or services are marked as set up by the migration.
- **Results page** at `/app/results` (spec RPT-01). For a month in the business's timezone it shows:
  - calls answered (excluding missed and spam);
  - jobs booked (calls with a "booked" outcome);
  - new leads (customers created);
  - after-hours calls caught (answered outside the business's hours; not shown until hours are set);
  - how calls ended, why people called, and a day × hour heatmap;
  - each figure compared with the previous month.

  **Estimated revenue** = jobs booked × the average job value the owner sets, labelled as an estimate. Without a job value there's no figure, only a prompt to set one.
- **Monthly report (spec RPT-02).**
  - **Recipients:** everyone at the business with `reports.view`, unless they switch "Monthly results report" off.
  - **Content:** email plus a PDF.
  - **Timing:** `reports:monthly` runs daily at 14:10 UTC. Each business gets last month's report once (`last_report_month`), on the morning of the 1st in the US. A missed day is caught up the next day.
- **Fix found while building this:** names with "&" or "<" showed as `&amp;` in headings, because the shared components escaped already-escaped text. The components now escape without double-encoding, so they're safe and correct for both raw and pre-escaped values.
**Why:** The first hour decides whether a new business trusts the service, and the monthly "we answered X calls and booked $Y for you" is what keeps them paying.

## D26 — Support tools, daily summary, customer emails, duplicates, vacation mode (2026-10-14)
**Decision:**
- **View as client (ADM-05).**
  - **Who:** staff with `users.impersonate` (Super Admin, Operations Manager, Support Agent) can sign in as an active business user. They start it from Organizations, Users or Search.
  - **While viewing:** an amber banner shows on every page with "Stop viewing", and signing out just stops viewing. The client's account and security pages stay closed. The staff member's own idle timeout and sign-out-everywhere still apply.
  - **Audit:** start and end are recorded, and every audit entry made meanwhile stores `impersonator_id`. The audit log shows "by X, viewing as them".
  - **Why full access rather than read-only:** fixing things for a client is what support needs. The audit trail makes it accountable.
- **Global search (ADM-09):** one box for businesses, people, callers, call IDs and appointments. Each group only appears to staff allowed to see it.
- **Daily summary (NTF-04):**
  - **Content:** yesterday's calls, bookings, missed calls and leads; today's appointments; what's waiting.
  - **When:** on for owners at 7:30 in their own timezone; opt-in for everyone else, at a time they choose. Sent once a day, and not at all on days with nothing to report.
  - **Quiet hours (NTF-07):** emails wait until morning. In-app notifications still arrive, and urgent escalations never wait.
- **Emails to a business's customers (§26–27):** confirmation, reminder, time changed and cancellation.
  - **Sender:** sent in the business's name, with replies going to the business; each one is noted on the customer's timeline.
  - **Editable:** each email has editable wording with placeholders, a live preview and a test send, and can be switched off.
  - **Reminders:** at a lead time the business chooses (2–48 hours, default 24). Last-minute bookings get no separate reminder.
  - **Not sent:** nothing goes to customers without an email address, and pending bookings wait until they're confirmed.
  - **Text messages:** wait for A2P 10DLC.
- **Duplicate customers (CRM-04):**
  - **Suggestions:** records with the same email or the same full name. Phones are already unique per business.
  - **Merging:** a person picks which record stays. Everything moves to it, and its own details win, with empty fields filled from the other.
  - **Afterwards:** the other record is archived with `merged_into_id` and frees its phone number. Owners and managers only.
- **Vacation mode (CLI-06):**
  - **Planning:** "away from / back after" dates can be set ahead. Only those days are closed for bookings.
  - **Who's told:** agents see current and upcoming time away. The owner's dashboard shows it, with "end it now".
**Why:** These take load off SureHelp's support team and the owner's inbox, and keep customers informed without anyone remembering to do it.

## D27 — Call quality reviews (2026-10-15)
**Decision:** Supervisors score agents' calls against a fixed scorecard, and agents read the feedback on their own page (task.md SUP-04, AGT-12).
- **Scorecard (`config/quality.php`):** greeting, accuracy, booking attempt, tone and compliance, each marked Met, Partly or Missed. Booking attempt can also be "Doesn't apply". Accuracy, booking attempt and compliance count double.
  - **Score:** a percentage of the points that apply. A call passes at 80% or more.
  - **Compliance must pass:** a call that misses it fails, whatever the total.
  - **History stays put:** each review keeps a copy of the scorecard it was scored with, so changing the list later doesn't change past scores.
- **Which calls:** each morning one random call per agent from the day before goes into the queue (`quality:sample`, 05:40 UTC). Reviewers can also pick a call by its ID, or ask for a random one from the last week. Each call is reviewed at most once; re-scoring updates it and is audited.
- **Who:** new platform permission `qa.review`.
  - **Who has it:** Super Admin and Operations Manager (all businesses), and Agent Supervisor (only their assigned businesses).
  - **Own calls:** nobody scores their own calls.
  - **Businesses:** business roles never get it, and businesses never see reviews.
- **Feedback:** the reviewer writes what went well and/or what to do differently (at least one). The agent gets an in-app notice and an email (held during quiet hours), opens the review and confirms with "Got it". Reviewers see who has read theirs.
- **One page:** *Call quality* (`/agent/quality`). Reviewers see three tabs: *To review*, *Team results* (per-agent average and pass rate over 30 days, lowest first) and *My feedback*. Agents see only *My feedback*. SureHelp managers reach it from the admin console.
- **Later:** AI scoring of every call (AIX-03) will fill the same scorecard, so supervisors only review flagged calls. Listening to the recording needs telephony (Phase 3b).

**Why:** Consistent call quality is what a receptionist service sells. A shared scorecard makes coaching fair and measurable, and random sampling keeps it honest.

## D28 — Shift requests and coverage (2026-10-15)
**Decision:** Agents ask for changes on *My schedule*; whoever plans the duty schedule decides on *Duty schedule*, which also shows coverage by hour (task.md AGT-11, SUP-03).
- **Hand over a shift:** an agent offers one of their upcoming shifts, optionally naming a colleague. On approval the scheduler picks (or keeps) the colleague and the shift moves to them. It's refused if that colleague already works then.
- **Time off:** up to 31 days at a time. On approval the agent's shifts in those days are taken off the schedule (kept, inactive, for the record) and each day is marked "Leave". Any gaps this leaves show straight away in the coverage grid.
- **Who decides:** staff with `users.update` in the admin console, the same people who edit the schedule. Declining needs a note. Agents can withdraw a request while it's waiting. Supervisors who work in the agent workspace can't decide yet; they can be given admin access if needed.
- **Notices:** schedulers get an in-app notice and email for each new request; the agent gets the decision the same way.
- **Coverage:** a day × hour grid for the week showing how many agents are on shift. Hours below `SCHEDULE_MIN_AGENTS` (default 1) are marked as gaps.

**Why:** Swaps and leave were handled by message and remembered by hand. Now they're recorded, the schedule changes only when someone approves, and gaps are visible before they cost a missed call.

## D29 — Data export, retention, erasure and closing an account (2026-10-16)
**Decision:** Business owners control their data on a *Data & privacy* page (spec §56–57, §91; task.md CMP-05, CMP-07). Managers and staff can't open it (`organization.update`).
- **Full export:** a ZIP with customers, calls, appointments, tasks, escalations and invoices as CSV, plus the business settings as JSON. Built in the background (`BuildDataExport`); the owner gets an in-app notice and email. The file is private, downloads need sign-in and are audited, and it's deleted after 7 days. At most one export in progress and three a day. Cells that start like a formula are neutralised, as in the other CSV exports.
- **Retention:** each business chooses how long history is kept: 1, 2, 3, 5 or 7 years, or everything. **Default 3 years.** The daily `privacy:run` (04:20 UTC) deletes older calls, past appointments, finished tasks, resolved escalations, timeline entries and archived customers. Open work is never deleted. Recordings and transcripts will follow the same setting once telephony exists.
- **Erasing one customer:** when a customer asks, owners and managers (`customers.delete`) choose "Erase personal data". Their name, contact details and notes are removed from the record and from every call, appointment, task and escalation about them; the timeline is deleted; the record is archived and its phone number freed. Counts and outcomes stay, so results don't change. The audit entry holds only the record's ID.
- **Closing the account:** only the owner, after typing the business name and their password (5 tries per 10 minutes). Nothing changes for 30 days: calls are still answered, a banner shows the date, the owner gets an email, and "Keep my account" undoes it. Then `privacy:run` deletes the business's customers, calls, appointments, settings, calendar connections, invitations and agent assignments; ends the subscription; and for people who only belonged to this business, removes their name, email, phone, sign-in, two-step setup, API tokens and notifications. **Invoices and payments are kept** (tax records). The business row stays, marked cancelled, so invoices still have an owner.
- **Later data (2026-10-23):** the same rules cover what arrived after D29:
  - **Export:** inbox conversations and messages, social posts and websites are in the export.
  - **Retention:** inbox messages and AI assistant records follow the retention period.
  - **Erasing a customer:** deletes their conversations and the AI's records of them.
  - **Closing the account:** deletes the inbox, AI settings, social accounts (with their access tokens), posts, uploaded media files and websites.
  - **Not affected:** agent training records stay; they're about SureHelp's agents.
- **Not legal advice:** these are the tools; the privacy policy and DPA describe the promises (spec §57: "Do not present legal compliance as guaranteed by the software").

**Why:** Customers and regulators expect to get their data out and to have it deleted. Doing it in the product, with a grace period and an audit trail, is safer than doing it by hand on request.

## D30 — Usage, add-ons and plan limits (2026-10-17)
**Decision:** Plans can include a number of calls per billing period, with a price per extra call; businesses can turn on paid add-ons; plans can cap team members and connected calendars (task.md BIL-03, BIL-05, BIL-11, ADD-01..04).
- **Usage = calls answered**, spam excluded. Minutes, texts and AI usage join once telephony and AI exist.
- **We never stop answering.** Calls beyond the plan are billed, not refused. At the end of each period the usage is recorded once (`usage_records`) and any extra calls go on the next invoice; a subscription that ends gets a final invoice for them.
- **Alerts:** at 80% and 100% of the included calls, once each per period, to people who see billing (switchable under *Notifications*, "Plan usage").
- **Meter:** the client *Billing* page shows calls used this period against the plan, the estimated extra charge, and past periods.
- **Add-ons:** a catalogue under *Admin › Billing › Add-ons* (name, monthly price, feature key). Owners turn them on and off on *Billing*.
  - **On:** the price is locked. The rest of the current period is invoiced at once, pro rata; during a free trial it's free. Renewal invoices include it after that.
  - **Off:** it stays on until the period ends, and "Keep it" undoes the change.
  - **Features:** an add-on's key unlocks features through `Entitlements::allows`, alongside the plan's feature keys.
- **Hard limits** (only when a plan sets them; blank means unlimited):
  - **Team members:** people who can sign in, plus invitations still open.
  - **Connected calendars:** reconnecting one already counted is always allowed.
  - **Where they're checked:** on the server, with a clear message.
- **Payments stay manual** (D22). Every charge is a normal invoice line, so automatic payments later change nothing here.

**Why:** These are the levers of a usage-priced receptionist service: fair pricing for busy businesses, upsells without custom invoices, and plan tiers that mean something.

## D31 — Finding a calendar from its change notification (2026-10-17)
**Decision:** Push channel ids are indexed in `calendar_push_channels` (unique per provider and channel id). A Google or Microsoft change notification now finds its connection with one indexed lookup. The connection's `push_channels` list stays the source of truth; saving it rewrites the index, the migration backfills existing channels, and deleting a connection removes its rows.

**Why:** The webhook used to load every connected calendar to find the matching channel, which gets slow as more businesses connect calendars (PR #2 review).

## D32 — The mobile API checks permissions, not role names (2026-10-17)
**Decision:** As on the web (D23), a route's `role:` middleware only picks the portal (admin, agent, client). What someone may do comes from permissions on the route (`can:`) or from the data's policy. The checks that repeated the role inside each API method are removed. The admin endpoints now follow the staff member's role: a Support Agent can read users, calls and schedules but not change them; an Operations Manager can't delete users. Seeing another agent's shifts or numbers needs `users.view`, so agents and supervisors see only their own.

**Why:** One rule everywhere. Before, any admin account could change anything through the API, whatever its role in the admin console.

## D33 — Social publishing: our own scheduler, official APIs only (2026-10-06)
**Decision:**
- **Launch channels:** Facebook Pages, Instagram professional accounts, LinkedIn Company Pages, Google Business Profile.
- **Next:** TikTok, YouTube Shorts, Pinterest and Threads.
- **X:** charges per post (about $0.015, or $0.20 with a link, since Sept 2026), so it's only offered as a paid add-on that passes the cost on.
- **Official APIs only:** each network sits behind a `SocialProvider` adapter over plain HTTPS, like calendar sync (P3-1). No browser automation, no unofficial APIs.
- **Our own queue:** SureHelp keeps the schedule and publishes at the due time. We don't use each network's native scheduling (Facebook allows only 10 minutes to 30 days ahead; Instagram has none). Every channel behaves the same, posts can be planned 12 months ahead, and they can be moved or cancelled freely.
- **Each adapter declares its capabilities:** media types, text limits, links, first comment, analytics. A post is validated per channel before it can be scheduled.
- **Human gate:** nothing is published without a person scheduling it, approving it or pressing "publish now".
**Why:** Official APIs are the only route that doesn't get customers' accounts banned. A single queue gives a calendar that behaves the same everywhere, and lets us retry, alert and report consistently.
**Action needed from you (long lead time):**
- **Meta:** App Review for `pages_manage_posts`, `pages_read_engagement`, `instagram_business_content_publish` and related permissions, plus Business Verification. It needs screencasts and takes weeks.
- **LinkedIn:** Community Management API access. It needs a registered company and a verified LinkedIn Page, goes through a two-tier review with a screencast, and takes 1–4 weeks per tier.
- **Google:** a Business Profile API access request.
- **TikTok:** the Content Posting audit. Until it passes, posts stay private.
Start these early. The code can be built and tested with your own accounts before approval.

## D34 — AI content uses Claude through an `AiService` (2026-10-06)
**Decision:**
- **Vendor:** the AI foundation (Phase 6) starts now, because the content studio needs it. Claude (Anthropic) is the first provider, behind an `AiService` interface so the vendor can change.
- **Grounding:** prompts are built from the Business Brain (services, prices, area, hours, offers, knowledge, brand voice).
- **Checks:** output is checked against that data before a person sees it.
- **Limits and labels:** every call is metered per business and capped by plan. Drafts are labelled AI-written until a person edits or approves them.
- **No customer data in prompts:** no customer personal data is sent for marketing content.
**Why:** Content grounded in the business's real facts is the difference between useful posts and generic filler. Metering keeps AI costs inside plan prices.
**Action needed from you:** an Anthropic API key (Console → API keys), and the commercial terms / DPA accepted in the Anthropic Console.

## D35 — Websites: connect first, then SureHelp Sites from templates (2026-10-06)
**Decision:**
1. **Connect an existing website.** The business verifies ownership by meta tag or DNS TXT and gets a one-line snippet: chat, booking, click-to-call and a lead form. We run a monthly site health and SEO check.
2. **SureHelp Sites.** Industry templates are rendered by SureHelp from the Business Brain, so hours, services and holidays are always current. Every site gets structured data, a sitemap, Open Graph tags and cached pages. Sites live on a SureHelp subdomain, or on the business's own domain through Cloudflare for SaaS custom hostnames: automatic HTTPS, 100 hostnames included, then about $0.10 per hostname per month.
**Why:** Most businesses already have a site, and the snippet turns it into a lead source in minutes. Businesses without one, or with a poor one, get a fast, always-accurate local site without a web designer. Rendering it ourselves, rather than exporting to WordPress, keeps it in sync and secure.
**Action needed from you:**
- Choose and buy a short domain for hosted sites (e.g. `surehelp.site`).
- Put it on Cloudflare, needed for custom domains.
- Custom domains also need the hosting move (D6): cPanel can't issue certificates for customers' domains.

## D36 — Growth module order (2026-10-06)
**Decision:**
- **G-1 Social foundation:** accounts, posts, per-channel versions, scheduling queue, approval, calendar, media library. It ships with a Facebook / Instagram / LinkedIn / Google Business Profile adapter set that stays hidden until each app is approved.
- **G-2 AI foundation and content studio.**
- **G-3 Connect your website** (verification, snippet, SEO check).
- **G-4 SureHelp Sites.**
- **G-5 Social analytics** in Results.
- **G-6 Growth ideas backlog** (§41C), in priority order.
**Why:** G-1 and G-3 deliver value without waiting for third-party approvals. G-2 needs only an API key. G-4 needs the domain and hosting decisions.

## D37 — Inbox: our own website chat first, then Meta messaging (2026-10-06)
**Decision:**
- **One model for every channel:** conversations and messages, each conversation tied to a business and (when known) a customer. A `MessageChannel` adapter per channel sends replies.
- **Website chat first.** The first channel is SureHelp's own website chat widget: one snippet, no third-party approval, works today, and it is the "connect your website" snippet from G-3.
- **Meta second.** Facebook Messenger and Instagram direct messages come through the same Meta app as social publishing, with webhooks verified by `X-Hub-Signature-256`.
- **Meta's windows are enforced in code.** Replies are allowed within 24 hours of the customer's last message. A person may reply up to 7 days with the `HUMAN_AGENT` tag; AI never uses that tag.
- **No Google chat:** Google shut down Business Profile chat in July 2024.
- **SMS** follows A2P 10DLC; **email** comes later.
**Why:** The widget gives every business a working inbox and AI assistant immediately, while the Meta review runs. Enforcing the windows in code keeps businesses' pages out of trouble.
**Action needed from you:** add `pages_messaging`, `pages_manage_metadata` and `instagram_manage_messages` to the Meta App Review (same app as D33), and set the webhook URL and verify token (docs/inbox.md).

## D38 — The AI assistant: Claude, tools only, three modes (2026-10-06)
**Decision:**
- **Provider:** an `AiProvider` interface with a Claude implementation through the official Anthropic PHP SDK. Model `claude-opus-5-5`, effort configurable (`AI_EFFORT`, default `medium`).
- **Modes per channel:** off, suggest (a person sends) or auto.
- **Tools only:** the assistant acts only through application tools, the same actions agents use, scoped to the one business. Tools: business info, knowledge search, services, availability, book appointment (with the double-booking guard and business rules), save customer details, create task, escalate / hand over. It never touches the database directly.
- **Limits:** at most 6 tool rounds per reply, 20 AI replies per conversation per hour, and monthly AI replies capped by plan (`ai_replies` limit).
- **Logging:** every call's tokens and every tool call are recorded.
- **Hand-over** when the customer asks for a person, on sensitive topics, low confidence or a team reply. AI messages are labelled and disclosed to customers as an AI assistant.
- **Customer data:** only the current customer's own details reach the model.
**Why:** It gives the "AI that works like an agent" the product owner asked for, with the same guardrails as a human agent, and stays switchable at any moment.
**Action needed from you:** an Anthropic API key (`ANTHROPIC_API_KEY`). Until it's set, the assistant settings show "Coming soon" and the inbox works without AI.

## D39 — Feedback becomes guidelines a person approves (2026-10-06)
**Decision:**
- **Rating:** team members rate each AI reply (helpful / not helpful) and can say what it should have said.
- **Guidelines:** a correction becomes a draft **assistant guideline**. Approved guidelines (owner, or `ai.manage`) are added to the assistant's instructions for that business.
- **No retraining:** nothing is fine-tuned, and feedback never changes behaviour without a person approving it.
**Why:** Businesses can correct the assistant in their own words and see it improve the next day. Approval stops one bad correction from spreading.

## D40 — Assignments have a lifecycle; access follows only current ones (2026-10-06)
**Decision:**
- **One table, extended:** the existing `agent_assignments` table gains status (scheduled, active, suspended, ended, revoked), start and end, who assigned and who ended it, reason, notes and type. Rows are never deleted, so the history stays complete. There is no second assignment table.
- **"Current" is decided in the query:** status active or scheduled, started, and not past its end. Access is correct to the second even if the scheduler runs late; the scheduled sweep only updates labels and sends notices.
- **Every access check uses current assignments.** The single relation `User::assignedOrganizations()` now means "currently assigned", so every existing check became strict at once: permissions, the agent workspace, client lists, call logging, quality reviews and the API.
- **No hints:** an unassigned company and a company that doesn't exist give the same answer (404, or "not found" in forms and the API).
- **Automatic assignment is switched off** (`TENANCY_AUTO_ASSIGN_AGENTS=false`). Assignments that already exist from the migration or auto-assignment stay active, so service doesn't stop overnight. The assignments screen flags them "Assigned automatically, please review" until a supervisor confirms or ends them.
- **The brief's `company.*` permissions are not duplicated.** They are the existing names, checked per company: `organization.view`, `customers.view`, `calls.view`, `appointments.view`, `messages.view`, `calendar.view`.
**Why:** The brief requires strict, auditable assignment. Extending the table we already have keeps one source of truth, and deciding "current" in the query means no security gap if a scheduled job is late.

## D41 — Who manages assignments and training (2026-10-06)
**Decision:**
- **Assignments:**
  - Platform admins and operations managers manage assignments for every company.
  - **Agent supervisors** manage them only for companies they are currently assigned to themselves, through the existing "assigned" role scope.
- **Training:**
  - Supervisors create, edit and assign training for those companies.
  - Platform-wide courses and certificate management stay with platform roles.
- **Business owners, managers and staff never get** assignment or training-management permissions: they are excluded explicitly.
- **Where the screens live:** in the agent portal (*Team*, *Assignments*, *Training management*). The admin console's company page uses the same actions.
**Why:** It follows least privilege. A supervisor runs their own accounts, and a client can't move SureHelp's staff around.

## D42 — Agent University: structure, versions and records (2026-10-06)
**Decision:**
- **Structure:** learning path → course → module → lesson. A lesson has a type (video, PDF, document, presentation, audio, text, external resource, quiz). A quiz lesson holds an assessment with single-answer, multiple-answer, true/false and scenario questions, a passing score, an attempt limit and an optional random subset.
- **Courses:** platform-wide, or owned by one company (company-specific).
- **Versions:**
  - Authors edit a draft; *Publish* creates numbered version N with a change note.
  - If the change is marked **"retake required"**, earlier completions become outdated and those agents' training reopens.
  - Progress, attempts and certificates always record the version they were for; nothing is overwritten.
- **Assignments are stored per agent:** one `training_assignments` row each, created from assignment rules (everyone, a role, a company's agents, or one agent). Status, due dates and reporting are per person. Team assignment waits for a team model, which doesn't exist yet.
- **Progress is server-side:** per lesson and per attempt.
- **Certificates:** a course can issue one, with a unique ID, a validity period and recertification.
- **Files:** stored privately and served only through a route that checks access on every request (no shareable links). Video can be uploaded or linked (YouTube / Vimeo).
- **Audit:** the existing audit log is reused; there is no separate training audit table.
**Why:** It covers every content type in the brief without being built around video, and keeps the history that compliance-style training needs.
**How it was built (A-3):**
- **Publishing freezes the draft.** Modules, lessons and questions are frozen as JSON in `training_course_versions`. Learners only ever read that frozen copy, so draft edits are invisible until published. Correct answers stay on the server.
- **One row per agent and course** holds the current cycle. A retake or a recertification after expiry starts a new cycle: earlier lessons and attempts stay on record but no longer count. Every finished cycle is kept in `training_completions`.
- **Rules only make an assignment stricter:** required beats optional, the earlier due date wins, and the higher priority wins.
- **Supervisors** may give *published platform-wide* courses to the agents of their own companies (a company or one agent). They can't edit those courses, or give them to everyone or a whole role.
- **Company readiness is calculated now.** Enforcing the restricted and blocking levels in the workspace comes with A-4.
- **Starter content:** `php artisan training:starter` creates two draft platform courses (call handling; privacy and security) for an admin to review and publish.

## D43 — Company readiness and how it's enforced (2026-10-06)
**Decision:**
- **Enforcement levels:** each required company course has one:
  - **informational:** shown only;
  - **warning** (default): a banner in the company workspace;
  - **restricted:** the agent can see company information and training but can't log calls or book;
  - **blocking:** only the company's training is available.
- **Where it's enforced:** on the server, in the same place as assignment access. "Ready" means every required course for that company (and every platform or role requirement) is completed, on the current required version, and not expired.
- **On a new assignment:** the agent automatically gets any required company training they're missing, with that requirement's due period. The supervisor sees readiness and a warning before confirming.
**Why:** The brief asks for configurable enforcement rather than one blocking rule. Companies differ, and a hard block everywhere would stop service on day one.

## D10 — Telephony
**Decision:** **Twilio** (Programmable Voice, TaskRouter, Voice JS SDK, Messaging) behind a `TelephonyProvider` interface, so the vendor can be swapped. Calls stay manually logged until then, but the Phase 2 `calls` table is designed for provider data (call SID, direction, timings, recording/transcript references). Telephony becomes **Phase 3b**, right after Calendar, because live call handling is the core of a receptionist product.
**Action needed from you (long lead time):** create a Twilio account and start **A2P 10DLC** brand and campaign registration now. US SMS cannot go live without it, and approval takes weeks.

## D11 — Single source of truth
**Decision:** `SureHelpSolution_Enterprise_Task.md` is the master spec, and `docs/implementation-plan.md` is the tracker. `task.md` is kept for reference only.

## D12 — Engineering conventions (normal engineering decisions)
- **Identifiers:** auto-increment internal ids; **ULIDs** as public identifiers for organizations and future customer-facing records.
- **Money:** integer minor units + ISO currency (spec §75).
- **Time:** stored in UTC; organization timezone (IANA) for display; never assume server timezone.
- **Statuses:** PHP backed enums (spec §76).
- **Audit log:** in-house `audit_logs` table (spec §62 shape), written through one `Audit` service.
- **Quality gates:** PHPUnit feature/unit tests, Laravel Pint (Laravel preset), Larastan (level 5, raised over time), `composer audit` and `npm audit` in CI.
- **Structure:** Controllers orchestrate; logic lives in `app/Actions` and `app/Services`; validation in Form Requests; authorization in Policies.

## D44 — Connecting a business's own website (2026-10-23)
**Decision:** A *Website* page (business portal, `integrations.view` to open, `integrations.manage` to change) connects the business's existing sites (spec §41B, G-3).
- **Ownership:** each site (up to 5) is proven with a meta tag on the home page or a DNS TXT record on the domain (or its parent for `www.` sites). Only verified sites are checked.
- **Health and SEO check:** we read the home page and up to 9 pages it links to, check up to 30 internal links, robots.txt and the sitemap.
  - **What's checked:** HTTPS; titles; descriptions; one main heading per page; the phone (viewport) setting; response time and page size; image descriptions; broken links; "noindex" and a robots.txt that blocks everyone; a sitemap; LocalBusiness structured data; whether the business name, phone and town match the business profile; and whether the SureHelp snippet is on the site.
  - **Report:** each finding is "fix soon", "worth fixing" or "looking good", with a plain-language fix and the pages concerned. The score starts at 100 and loses 15 per problem and 5 per tip.
  - **Schedule:** checked on verification, on demand, and again every 30 days (`websites:check`, daily at 07:20 UTC). There are no ranking promises (spec §39).
- **Fetching safely:** customer-supplied addresses are fetched only over http/https on the standard ports.
  - **Addresses:** the host must resolve to public addresses only, and the connection is pinned to the address that was checked (no DNS rebinding).
  - **Redirects:** every redirect is checked again, with 3 redirects at most.
  - **Limits:** 10 seconds and 2 MB per page; verify and "check now" are limited per site.
- **One snippet, four parts:** the existing chat snippet (D37) now also offers online booking, click-to-call and a contact form, each switched on separately. Chat stays on unless switched off.
  - **Booking:** uses the same rules as agents and the AI assistant (opening hours, notice, buffers, calendar busy times). Bookings arrive as requests by default; the owner can confirm them automatically instead.
  - **Contact form:** becomes a high-priority call-back (or follow-up) task with the message and the page it was sent from.
  - **Into the CRM:** both match or create the customer (source "website").
  - **Abuse protection:** a hidden field catches bots, and each visitor can send 5 accepted bookings or messages an hour, on top of the widget's existing limits and allowed sites.
- **Later:** Google Search Console and Analytics (real search and traffic data), a site-visits count in Results (G-5), and the form inline on a page instead of in the floating panel.

**Why:** Most small businesses already have a site that brings in few calls. Proving ownership first means we only crawl sites the business controls, and plain-language fixes plus booking and calling from every page turn visits into jobs.

## D45 — Who switches a business's service on and off (2026-10-23)
**Decision:** SureHelp staff with `organization.update` (Super Admin, Operations Manager) change a business's service status on its admin page. Support staff can see the status but not change it.
- **Moves:**
  - onboarding → active ("Go live");
  - active ⇄ paused ("Pause" / "Resume");
  - any status → cancelled;
  - cancelled → active ("Reactivate").
- **Reasons:** pausing and cancelling need a reason, which the owner sees in an email, in the app and on their dashboard banner. Every change is audited.
- **While paused or cancelled:** agents no longer see the business, and the website snippet (chat, booking, contact form) stays hidden. The owner can still sign in, see their data and download it.
- **Closed accounts:** an account the owner closed (D29) can't be reactivated, because its data is gone.
- **Not automatic yet:** overdue invoices don't pause a business on their own. Staff decide, with the overdue reminders as the prompt (BIL-07 asks for this after a grace period; it waits for automatic payments).

**Why:** Going live, pausing for non-payment and cancelling are everyday operations for the team. They need one place, a record of who did it and why, and an owner who's told.

## D46 — Paid features: open to everyone, or only to plans that include them (2026-10-23)
**Decision:** Five product features can be sold (FND-18, spec §30–31). Their keys are `calendar_sync`, `ai_assistant`, `social_publishing`, `website_tools` and `customer_emails`.
- **Feature access** (Admin › Billing): each feature is "every business" (the default) or "only plans / add-ons that include it". A plan includes it by listing the key in its feature keys; an add-on does by having the key as its slug.
- **Per business:** on the admin business page, staff with `billing.manage` can set each feature to "follow the plan", "always on" or "always off". The override wins.
- **Enforced on the server, where the work happens:**
  - **Pages:** the pages are behind `feature:` middleware. Owners are sent to Billing with "not in your plan"; others go to the dashboard.
  - **Background work:** the AI assistant doesn't reply, appointment emails aren't sent, calendars don't sync, social posts fail with a clear reason, and website checks don't run.
  - **Website snippet:** it keeps chat but hides booking, click-to-call and the contact form, whose endpoints answer 404.
  - **Sidebar:** locked items stay listed with an "Upgrade" badge.
- **Nothing is taken away by surprise:** every feature starts open to everyone. Changing one to "only plans" takes effect at once for businesses without it, and the admin page shows which plans and add-ons include each feature.
- This corrects D30, which said add-on keys already unlocked features: nothing checked them until now.

**Why:** Plans and add-ons need to mean something, without hard-coding prices into features. Staff need an escape hatch for trials, special deals and support.

## D47 — What agents may change on the web (2026-10-23)
**Decision:** Agents work on records of the companies they currently serve (AGT-14). Each change needs the agent's permission in that company and is looked up inside it, so another company's record is "not found".
- **Appointments:** only upcoming ones (pending, tentative, confirmed, in the future). Moving one uses strict booking: inside opening hours and the business's rules, at a free time. Owners can override those rules; agents can't. Cancelling needs a reason, which the business is told.
- **Customers:** the same edit form and checks as the business portal (one shared action, `UpdateCustomer`), including consent recorded with the agent's name. Notes go on the customer's timeline.
- **Calls:** an agent adds notes only to calls they logged, in a company they still serve, for 24 hours after the call. Notes are appended with name and time and never rewritten, so what the business has read doesn't change. Later information goes on the customer instead.

**Why:** Agents already held these permissions, but the web screens were read-only, so corrections went through the business or by phone. The limits keep agent changes visible and accountable.

## D48 — Customers change their own bookings; owners can approve agents' bookings (2026-10-23)
**Decision (CAL-09):** confirmation, reminder and time-change emails carry a "Change or cancel" button. It opens a page with the business's name. There the customer picks a new free time or cancels, without an account.
- **The link:** it is signed and stops working when the appointment starts. A changed or old link shows "This link no longer works", not an error.
- **Cut-off:** changes are allowed until the business's cut-off, set under *Business › Customer emails*: 2–72 hours before, default 24, or off. After that the page shows the business's phone number.
- **Same rules as staff bookings:** a new time must be free and inside the business's hours and booking rules.
- **Records:** every change notifies the business, goes on the customer's timeline, and is audited with no user (a customer did it). The usual "time changed" or "cancelled" email follows. A customer's cancellation is recorded as "Cancelled by the customer online", with their note.
- **Limits:** at most 10 changes an hour per appointment.
- **The default is on (24 hours) for existing businesses.** Customers could already phone to cancel, and the business hears about every change.

**Decision (CAL-08):** *Business › Rules* has "I approve bookings made by SureHelp agents".
- **When it is on:** anything booked by someone who isn't an active member of the business (our agents and staff) is saved as "Needs confirming" instead of confirmed.
- **The owner's side:** the owner's notification says "Approve: …" and explains what to do.
- **The customer's side:** the customer gets no confirmation until the owner confirms it.
- **Bookings made by the business's own team are unaffected.**

**Why:** customers expect to manage a booking from the email, and some owners want to check what is booked in their name before the customer is told.

## D49 — Support requests (2026-10-23)
**Decision:** businesses ask SureHelp for help under *Support* (spec §78, CLI-09). "Request a script change" opens the form already filled in for that.
- **A request:** subject, category, priority, message and one optional attachment (up to 10 MB: PDF, images, text, Word or Excel). Each request gets an "SR-" number.
- **Statuses:** open, in progress, waiting for customer, resolved, closed.
  - A staff reply sets "waiting for customer" by default; staff can pick another status.
  - A business reply reopens the request: "in progress" if someone has it, otherwise "open".
  - A closed request can't be replied to; the business opens a new one.
- **Who can do what:** new permissions `support.view`, `support.create` (owners, managers and staff in the business) and `support.manage` (SureHelp staff: super admin, operations manager, support agent). Agents get none of them.
- **Notifications:** new requests and business replies go to the assignee, or to all support staff while it's unassigned. Staff replies, and resolving or closing, go to the business's people who can see support. People can turn these off in notification settings under "Support requests".
- **Attachments:** stored privately and only downloaded through an authorized route. They are deleted with the business when its account closes, along with the requests.

**Why:** requests by phone or email get lost. One thread per request, with a status, shows both sides where things stand.

## D50 — Automation foundation (2026-10-23)
**Decision:** each business can set up automations under *Business › Automations* (spec §42–43). An automation is a trigger, optional conditions, a delay (up to 30 days) and one action.
- **Triggers:** an appointment is completed, an appointment is cancelled, or a new customer or lead is added. Records copied in by a backfill or import don't count as new customers.
- **Conditions:** only for certain services (appointment triggers).
- **Actions:**
  - Email the customer, in the business's name with placeholders. Optionally only to customers who agreed to email; this is on by default and on in the recipes.
  - Create a follow-up task.
  - Notify the team ("Automation messages" in notification settings).
- **Recipes:** "Thank you and review request" (2 hours after a completed job), "Rebook cancelled appointments" (a task a day later) and "Tell the team about new leads".
- **How runs work:**
  - A trigger schedules one run per automation and subject, so a repeated trigger never doubles an email.
  - `automations:run` runs due steps every minute, and first checks that each still applies. If the appointment is no longer completed or cancelled, the automation was switched off, or the business is paused, the step is cancelled.
  - A step that can't apply (no email address, no consent, emails not in the plan) is "skipped".
  - Errors retry up to 3 times, then show as "failed". The last 20 runs are listed on the page.
- **Privacy:** erasing a customer deletes their pending and past runs. Closing an account deletes automations and their history.
- **Not yet:** SMS (no provider yet), "request review" as its own action (an email with `{review_link}` covers it), escalations and appointment-created triggers. The engine was built so these can be added as new triggers and actions.

**Why:** follow-ups and review requests are where businesses lose the most after the job. A small engine with history and safe re-checks is enough now and grows later.

## D51 — Welcome emails for people added by staff (2026-10-23)
**Decision:** when SureHelp staff add a person under *Users*, the person gets a "Welcome to SureHelp" email with a link to choose their own password. This is on by default and can be unticked.
- **The link:** it works once and lasts 7 days. Its tokens live in their own table (`user_welcome_tokens`, broker `welcome`), so ordinary password-reset links keep their 60-minute limit.
- **Using it:** the reset page accepts either kind of link. Choosing a password confirms the email address and clears the "must change password" flag.
- **Backup:** staff still see the temporary password once, in case the email doesn't arrive.

**Why:** passing temporary passwords around by chat or phone is error-prone and less safe. A link the person uses themselves is both easier and safer.

## D52 — Idempotency keys on the mobile API (2026-10-23)
**Decision:** the app's create endpoints accept an optional `Idempotency-Key` header: call logs, appointments, tasks, inbox replies and admin user creation.
- **What it does:** the first response is remembered for 24 hours, per signed-in person and key. A retry returns it unchanged, marked `Idempotent-Replayed: true`.
- **Edge cases:** the same key with a different body is refused (422). A concurrent duplicate gets 409. Server errors aren't remembered.
- **Storage:** the application cache, so production's `CACHE_STORE` (database) keeps the keys across workers.

**Why:** phones drop connections after the server has saved a call log or booking. Without a key, the app's retry creates a second one.

## D53 — Importing customers from a spreadsheet (2026-10-23)
**Decision:** *Customers › Import CSV* (needs `customers.create`) takes a CSV of up to 5,000 rows and 5 MB.
- **Columns:** comma, semicolon or tab separated. Columns are matched to fields automatically from their names and can be changed; a "Full name" column is split into first and last name. A preview shows the first rows.
- **Rows:**
  - Each row needs a name, phone or email.
  - Rows with an invalid email or phone are skipped and listed by row number (first 20).
  - Existing customers are matched by phone, or by an email only one customer has. They're skipped, or, if the owner chooses, only their empty fields are filled; nothing is overwritten.
- **Tags:** comma-separated tags are created as needed.
- **Recorded as imported:** imported customers have source `import`, so they don't set off "new customer" automations (D50). The import is audited with its counts.
- **Consent to text or email is never imported.** It must be recorded where the customer gave it (spec §57).

**Why:** businesses arrive with a customer list. Re-typing it isn't realistic, and a careless import would duplicate customers or invent consent.

## D54 — Agents' own calendars on My calendar (2026-10-24)
**Decision:** agents get *My calendar* in the agent portal: their shifts, approved time off and the appointments they booked, shown in their own time zone, next to their own Google or Microsoft calendar.
- **Busy times:** read live for the range on screen (cached for 5 minutes) and never stored. Only times are shown, never titles or attendees.
- **Shifts copied in:** shifts for the next 60 days are copied into the calendar the agent chooses. They update when the plan changes and are deleted when a shift is removed. Shifts belong to SureHelp, so our copy wins over edits made in the external calendar. Turning copying off or disconnecting removes our events.
- **Kept apart from business calendars:** agents' connections are a separate table. A business's calendar feeds its booking availability; an agent's never does. Only the agent sees their own connection.
- **Same safeguards as business connections:** a one-time state bound to the user, tokens encrypted on the server, lost access flagged and the agent told in the app.
- **One-time setup:** the redirect URIs `/agent/calendar/connect/google/callback` and `/agent/calendar/connect/microsoft/callback` are registered next to the business ones.

**Why:** agents asked to see when they're working next to their own commitments, and to have their shifts in the calendar on their phone.

## D55 — The public website: rebuilt, honest, and every link real (2026-10-25)
**Decision:** the public site is rebuilt on the portals' toolkit (Tailwind and Vite, its own small stylesheet, Alpine through Livewire), with no outside CDNs.
- **Pages:** home; five service pages; How it works; Industries and eight industry pages; Pricing; About; Contact (the `?topic=` from each button picks the reason); FAQ; the six legal pages in the same design; sitemap.xml; robots.txt; branded 403, 404, 500 and 503 pages.
- **One source for the words:** `config/marketing.php` holds services, plans, promises and FAQs. Industry details come from `config/industries.php`, the same source as the setup wizard.
- **Only claims we can stand behind:**
  - Removed: "500+ clients", "thousands of businesses", invented testimonials, and SOC 2, ISO 27001, PCI, SOX, cloud-provider and 24/7 security-team claims.
  - The Data Security page now describes the controls the platform really has, with a fake "1-800-SUREHELP" number replaced by the real one.
  - Testimonials appear only when real, permitted quotes are added to the config.
- **Contact form:** a hidden spam trap, rate limiting (6 per 10 minutes), and visitors return to the form they used. The SMS consent wording is kept for carrier review, with its typo fixed.
- **Sub-folder installs (local XAMPP):** Livewire's script and update addresses now include the folder, so the portals work locally too. Production, at a domain root, is unaffected.
- **Left for the owner and counsel:** the wording of the Privacy Policy, Terms, Cookie Notice, DPA and BAA is unchanged. The DPA promises an "ISO 27001 compliant security program", and the Terms and DPA name Delaware law while the company address is in Wyoming.

**Why:** the old page had dead menu links, CDN dependencies and claims a new company can't support. Under FTC rules on endorsements and deceptive claims, invented reviews and certifications are a legal risk, not just a credibility one.

## D56 — Where an agent's booking happens: exact address, saved place or remote (2026-10-26)
**Decision:** when an agent books an appointment in the workspace, a *Location* section asks where it happens. It replaces the single "Address" box in step 1.
- **Specify exact address:** an optional display name ("Lake house"), a location type (residential, commercial, other), country, address lines 1 and 2, city, state or province, and ZIP or postal code.
  - The US and Canada get a state or province list and a checked ZIP or postal code format. Other countries type the region, and the postal code is optional.
  - The default country is the business's primary location's country, or the US.
- **Use saved location:** the confirmed caller's places (the address on their record, then places saved earlier, most recently used first), and the business's own locations.
  - Confirming a caller picks their most recent place, unless the agent has already started typing an address.
  - Picking a business location books on that location's calendar, so the free times follow it.
- **Remote (no address):** for phone or video appointments. "Require the visit address" and service-area rules don't apply.
- **Save this location for future use:** saves the place on the customer's record. The same street and postal code again updates the saved place instead of adding a copy. It needs the caller's phone or email, so there is a customer to save it to.
- **Stored in parts:** appointments store the mode, display name, type and each address part. `address` stays the one-line form that emails, calendars, rules, exports and the API already read. The API also returns `location_mode`, `place_name`, `place_type` and `address_parts`.
- **Customer records:** a new customer, or one without an address, gets the typed address in its separate fields instead of one line.
- **Service area:** checked against the ZIP field rather than a ZIP found in free text.
- **Privacy:** erasing a customer deletes their saved places and clears the address parts on their appointments. The business data export includes `customer_locations.csv`.
- **Calls without a booking:** no longer ask for an address. The address belongs to a visit, and anything else the caller says goes in the notes.

**Why:** one free-text box gave the business addresses it couldn't rely on: missing ZIP codes, guessed states, and no way to say an appointment is by phone. Separate fields make addresses usable for dispatch and service-area checks, and saved places spare a returning caller from repeating their address.

**Amendment (2026-10-26): the business's own booking form.** *Appointments › Book appointment* has the same Location section. One implementation serves both portals: `EditsVisitLocation` for the logic and `partials/visit-location` for the view.
- **The address stays optional for the business:** with nothing typed, the booking has no address, as before. Once any part is typed, the full address is required.
- **The separate "Location" dropdown is gone:** the business's locations are listed under *Use saved location*. A service tied to a location selects it, and the free times follow the chosen location.
- **Customers:** choosing a customer offers their saved places, most recent first. A new customer typed into the form gets the address on their record. *Save this location* needs an existing customer or a new customer's phone number.

## D57 — SEO service: AI does the SEO work, statistics show the difference (2026-10-27)
**Decision:** businesses can take an **SEO service** from us under **SEO** in the business portal. AI does the SEO work within a monthly token allowance, and the page shows the results before and after.
- **A paid add-on, off by default:** the feature key `seo_service` has `default_mode: plan` (a new per-feature setting in `config/features.php`), so it's closed until a plan or add-on includes it. The business sees an offer card and turns it on itself when an active add-on with the slug `seo_service` exists. Turning it on bills the add-on with the subscription and starts the first keyword plan. Staff can also switch it on for one business (D46 overrides) or put `seo_service` in a plan's features.
- **The SEO tasks** (`SeoTaskCatalog`):
  - a keyword plan;
  - page titles, descriptions and main headings for the pages the website check read;
  - a local service page for one service in one town;
  - an FAQ with schema.org `FAQPage` markup (built in code, not by the AI);
  - four Google Business Profile posts.
- **How the AI is used:**
  - Each task is one Claude request with **structured output**: a JSON schema per task, so the answer is always parseable.
  - The `effort` is set from `config/seo.php`.
  - Lengths and counts are enforced in code afterwards.
  - The AI only gets the business's public facts: profile, location, service area, active services, public knowledge items, and the focus areas the business typed. It never gets internal knowledge or agent instructions.
  - It is told never to invent awards, prices, licences or reviews.
- **AI tokens:**
  - Every task records its input (including cached) and output tokens.
  - The allowance is per calendar month in the business's timezone. Staff can set one for a single business; otherwise the plan's `seo_ai_tokens` limit applies, otherwise `SEO_MONTHLY_TOKENS` (250,000).
  - A task can't start with less than 5,000 tokens left, and at most three run at once.
  - Failed answers still count the tokens they used. An unreachable AI uses none.
- **Review, then live:**
  - The business approves or sets aside each result. Each result has copy buttons, and a local page can be copied as HTML.
  - Staff see approved work under *Admin › SEO service*, put it on the site, and mark it live. The business can also mark it live itself.
  - The date it went live becomes a dashed marker on the charts.
- **Autopilot (on by default, `seo:daily`, 08:10):**
  - Statistics are refreshed every day.
  - On the 1st of each month: a keyword plan every three months; new titles and descriptions when the website check finds title, description or heading problems; and Google Business Profile posts.
- **Statistics, before and after:**
  - The tiles compare the 28 days before the first SEO task with the latest 28 days: clicks, times shown and average position (from Google Search Console), website health, and website leads.
  - Average position is weighted by impressions, as Google does, and lower is better.
  - Weekly charts use seven-day blocks ending on the latest day with data, so the last point is never a part-week.
  - A table shows the searches people used to find the business.
  - Website health history comes from a new `website_checks` row on every check. The website check now also keeps each page's title, description and main heading.
- **Google Search Console:** read-only (`webmasters.readonly`), through the same Google app as calendar sync. On first connect it fetches up to 480 days of history, then the last 14 days daily. Lost access marks the connection for reconnecting.
- **Privacy:** closing an account deletes SEO tasks, website check history, Search Console numbers and the Search Console connection.

**Why:** the request was that clients can take SEO from us, that the SEO work is done with AI tokens, and that the section shows statistics to see the difference. Real Search Console numbers with a "went live" marker make the effect visible, instead of claiming one. Review before publishing keeps a person responsible for what goes on the business's site.

## D58 — Search engine optimisation for surehelpsolution.com (2026-10-27)
**Decision:** the public website is built to rank. Only the public pages are indexed, and every claim made to search engines is one the page itself makes.
- **One place for search content:** `config/site.php` holds:
  - the business facts used in structured data;
  - a search title (at most about 60 characters) and description (120–160 characters) for every page, written around what people search for, such as "answering service for small business", "medical answering service" and "answering service for plumbers";
  - the FAQs.
- **Every public page** (`partials/site-head`):
  - a canonical URL without the query string, so `?utm=` copies don't compete;
  - robots `max-image-preview:large`;
  - Open Graph and X cards with a 1200 × 630 share image (`assets/img/share.png`);
  - a theme colour.
- **Structured data** (`App\Support\SiteSchema`, JSON-LD):
  - `Organization` and `WebSite` on the home page;
  - `BreadcrumbList` on inner pages;
  - `Service` on product and industry pages;
  - `FAQPage` on `/faq` and on the main service pages.
  - The FAQs are rendered from the same config as the page, so the two can't drift apart.
  - No ratings or reviews are marked up, because the site has none it can show.
- **Crawling:**
  - `/sitemap.xml` is generated from the routes (new menu pages appear automatically).
  - `/robots.txt` is generated and blocks the portals, the API, self-service links and media. The static `public/robots.txt` is removed so it can't shadow the generated one.
  - Every non-public response carries `X-Robots-Tag: noindex, nofollow` (`SearchIndexing` middleware), so a shared portal or appointment link never appears in Google.
- **Page structure:** section headings on the service, FAQ and legal pages are now H2s, with questions as H3s (one H1 per page).
- **Speed:** the hero image is served as WebP (42 KB instead of 931 KB) with dimensions and alt text. Menu images load lazily, and connections to the CDNs open early.
- **Accuracy fixes found on the way:** the FAQ said Apple Calendar and SMS/phone message delivery. The product syncs with Google and Microsoft calendars and delivers to the portal, the app and email, so those answers now say that. The commented-out "trusted by" logos of companies we don't serve were taken out of the HTML.

**Why:** the site had no sitemap, canonical tags, structured data or share cards; its titles were generic ("Small Business - SureHelp Solution"); and the portals were open to crawlers. Ranking also depends on things outside the code (Search Console, Google Business Profile, links, reviews, regular content), which are listed in [website-seo.md](website-seo.md).

## D59 — Colour themes: personal choice in the portals, browser choice on the website (2026-10-28)
**Decision:** people choose the colours they see.
- **Portal themes:**
  - Six palettes: Midnight (the original), Dark, Light, Ocean, Forest and Sunset.
  - A seventh option, "Match my device", shows Light or Midnight depending on the device's setting.
  - Each palette redefines the same design tokens (`resources/css/themes.css`), so every screen, chart and calendar follows. Light also darkens the status colours written for dark backgrounds, and swaps in a dark-text logo.
- **Where people choose:** the swatch menu in the top bar, or *Your account › Appearance*.
- **How it's stored:** on the account (`users.theme`, `theme_chosen_at`), so the choice follows the person to every device. "Use the default" clears it.
- **Applied before the page draws:** the `<html>` element carries the theme. A small inline script resolves "Match my device" and follows the device when it changes. Charts redraw in the new colours.
- **Staff viewing as a client** don't see the menu. The client's choice is theirs.
- **Admin default:**
  - *Admin › Appearance* (`settings.view` to see it, `settings.manage` to change it) sets the theme for everyone who hasn't chosen one, including the sign-in pages (platform setting `ui.default_theme`, falling back to `config('themes.default')`).
  - Changing the default never changes a personal choice. The page shows how many people chose each theme.
- **Resetting a personal choice:** a separate, deliberate action, for one person (by email) or everyone. It needs a reason and the typed word RESET. It is recorded in the audit log (`ui.themes_reset`), and each person affected gets an in-app notice with the reason.
- **Website themes:**
  - The public site has six palettes: Indigo (the original), Ocean, Emerald, Sunset, Rose and Graphite.
  - Its brand colours became CSS variables (`public/assets/css/site-themes.css`).
  - Visitors pick a palette from a round button in the bottom-left corner. The choice is kept only in their browser (`localStorage` key `surehelp.site-theme`) and applied before the page draws. Nothing is sent to the server.

**Why:** the request was themes anyone can choose and keep, a default administrators control without overriding people's own choices except through a deliberate step, and more palettes for the landing page remembered in the browser.

**Amendment (2026-10-28): the mobile app.**
- **`data.theme` on login and `GET /api/v1/user`:** the key to show, whether it is the person's own choice, and the default.
- **`GET /api/v1/themes`:** every theme with plain colour values for the app.
- **`PUT /api/v1/user/theme`:** saves the choice with the same rules as the web (`default` clears it). A theme picked in the app shows on the web, and the other way round.
- **Where the colours live:** `config/themes.php`. A test checks they match the CSS tokens, so the app and the web can't drift apart.


## D60 — Live calls through Twilio, first part of Phase 3b (2026-10-29)
**Decision:** calls to a business's SureHelp number ring the browsers of the agents who serve it. The call record, transcript, recording and statistics are filled in by the system, not typed. Setup steps: `docs/telephony.md`.
- **Vendor behind one interface (D10):**
  - `TelephonyProvider` has two drivers. `twilio` is for production. `fake` covers local work, demos and tests: lines get 555 numbers and agents start pretend calls from the softphone.
  - `TELEPHONY_DRIVER` picks the driver.
  - **No Twilio SDK on the server.** The three things needed (REST calls, the browser access token, the webhook signature) are small and tested against Twilio's published example. The browser uses Twilio's Voice JS SDK, loaded only when an agent goes ready.
- **Data:**
  - `phone_numbers`: a business's lines, each with an optional owner number and a "record calls" switch.
  - `voice_calls`: one row per call, holding who answered, wait time, talk time, the transfer, the recording, the transcript and the AI summary.
  - Agent readiness lives on `users`: `phone_status`, `phone_status_at` and `phone_seen_at`.
  - The agent's call log stays in `call_logs` and is linked from the voice call (`call_log_id`). `call_logs` keeps its required form fields, and a call exists before anyone has filled in a form.
- **Routing:**
  - A call rings every *available* agent at once, up to 5, longest-waiting first. An available agent is one who:
    - is currently assigned to the business;
    - is allowed to log its calls, with no training restriction;
    - has a softphone that checked in within 75 seconds;
    - is "Ready for calls", or has been finishing the last call for more than 2 minutes;
    - is not already on a call.
  - The first agent to answer gets the call.
  - When nobody is available or nobody answers within 20 seconds, the call goes to the owner's number (if the line has one), then to voicemail.
  - Missed calls and voicemails become a high-priority call-back task, and the business's people are notified through their `call.missed` preferences.
- **Recording and consent:**
  - Lines record by default. Callers hear a short notice first ("This call may be recorded…").
  - Recorded calls are transcribed live (both sides, final sentences only).
  - When the transcription stops, the AI writes a 2–4 sentence summary. If the agent's call log has no notes, the summary fills them ("AI summary: …"). The call screen also offers "Add to the notes".
  - Recordings stay at Twilio and are played through `/recordings/{call}`. Only people with `calls.recording.view` in that business can play them (owners, managers and supervisors; not front-line agents), and every play is audited.
- **The softphone (agent portal):**
  - A status pill in the top bar: Ready / Away / Offline, plus On a call and Finishing up.
  - A ringing card shows the business and the caller, matched by phone to the business's customers.
  - "Answer" opens that business's call screen in its own tab with the number filled in and the customer confirmed. The phone stays in the first tab, so moving around the portal never drops the call. The browser asks before closing a tab with a live call.
  - Only one tab per agent rings (Web Locks). Other tabs show the state.
  - Polling (4 s while ready in demo mode, 20 s otherwise) keeps the agent online. Real calls are pushed by Twilio's SDK, so no WebSocket server is needed on cPanel.
- **Transfer:** a cold transfer to the owner's number. The live call is redirected and the agent leaves the call. A warm (three-way) transfer needs Twilio conferences and is left for later.
- **Admin › Phone lines** (`settings.view` to see, `settings.manage` to change):
  - Buy a number by area code, or connect one already in the Twilio account. Either way, its webhooks are set automatically.
  - Edit the owner number and recording. Release a number (with confirmation).
  - Answer rate, missed calls and voicemails, average wait and average talk time, for 24 hours, 7 days or 30 days, overall and per line. The latest calls are listed.
  - Every change is audited.
- **Security:**
  - Every Twilio webhook must carry a valid `X-Twilio-Signature`, computed over the exact public URL (`TWILIO_WEBHOOK_URL` covers proxies).
  - The demo driver accepts webhooks only outside production.
  - Agents can only answer, transfer or open calls that were offered to them, in businesses they currently serve.
- **Not in this part:** outbound calls from the browser, warm transfer, SMS (needs A2P 10DLC approval), business-hours routing rules, and the business portal's own line page.

**Why:** live call handling is the core of a receptionist product (D10). Until now every call was typed in by hand, and nothing showed whether calls were actually answered.

## D61 — Calling out, after-hours routing, the business's line page, and texting (2026-10-30)
**Decision:** the second part of Phase 3b (TEL-2) and the texting part of Phase 4. Setup steps: `docs/telephony.md`.
- **After hours:** each line chooses what happens while the business is closed: agents answer anyway (the default), the owner's phone then voicemail, or straight to voicemail.
  - "Closed" comes from the business's own hours, holidays and closures (`BusinessHours`). A business with no hours set is never treated as closed.
  - Each call records whether it came in after hours.
- **Calling out:**
  - Agents call customers from the softphone ("Call a customer") or with the **Call** button on the call screen, showing the business's number.
  - **How a call is placed:** the server first creates the outbound call record (`POST agent/phone/dial`: the agent's own business, US/Canadian numbers only, no 900/976, one call at a time). The TwiML App webhook then places only that call, from that agent, once, within 2 minutes. The browser therefore can't call arbitrary numbers, which guards against toll fraud.
  - Calls out are recorded per the line's setting and link to the call log the same way calls in do.
  - They are excluded from the answer rate.
  - Transfer stays inbound-only. Warm (three-way) transfer is still deferred.
- **Business › Phone line** (business portal):
  - **What it shows:** the number, carrier forwarding codes (with a caveat that they vary), 30-day statistics, and recent calls linked to their call logs.
  - **What owners and managers can change** (`settings.manage`, audited): their phone, after-hours handling, recording and missed-call texts.
  - Staff can't open the Business section, as before.
- **Texting:**
  - Texts are a new Inbox channel (`sms`, up to 1,600 characters). Inbound texts arrive by a signed webhook, are matched to customers by phone, and go to the AI assistant or the team like any other message.
  - Replies, by a person or the AI, go out from the line the conversation used.
  - **Delivery reports:** failed and undelivered texts are marked failed, with a plain-language reason.
  - **STOP and START:** the CTIA stop words add the number to `sms_opt_outs` and withdraw the customer's texting consent. Nothing is texted to an opted-out number, and START removes it from the list.
  - **Gated off with Twilio** until `TELEPHONY_SMS=true`, because US texts need an approved A2P 10DLC registration. The demo driver always "texts", but nothing is sent.
- **Automatic texts** (`Texting::sendAutomatic`, author "system", always inside an Inbox conversation):
  - **Missed-call text-back:** per line, off by default, editable message, at most one per caller in 12 hours.
  - **Appointment texts:** short versions of the confirmation, reminder, change and cancellation. They go only to customers with texting consent and a US/Canadian number, and are noted on the customer's timeline. Email works as before. A message counts as sent if either the email or the text went out.
- **Not in this part:** starting a new text conversation from the Inbox, pictures (MMS), a business's own wording for appointment texts, warm transfer, outbound calls outside US/Canada.

**Why:** these were the next items in the plan (TEL-2 and Phase 4). Missed-call texts and appointment texts are what customers of a receptionist service expect, and keeping everything in the Inbox means one place to answer.

## D62 — Warm transfer, starting texts, texted pictures, own wording for texts (2026-10-31)
**Decision:** TEL-3, the remaining items from D61's "not in this part" list.
- **Warm transfer (conference):**
  1. "Talk to owner first" redirects the caller into a private conference room (`call-{ulid}`). The room hasn't started, so the caller hears hold music.
  2. A consult call to the owner is created (`consult_for_id`), and the agent's browser places it through the TwiML App.
  3. **Connect caller** redirects the owner's leg (the SID from the callee webhook) into the room, which starts it. The agent drops off, and the call is marked transferred (audited, `warm: true`).
  4. **Back to caller** ends the consult. The agent rejoins the room through the TwiML App (`rejoin=1`): only the agent who held the caller, only while they're held, and only once.

  While the caller is held:
  - the Dial action keeps them in the room;
  - the agent stays "On a call".

  The demo phone does the same steps at once. Recording covers the agent's part only.
- **Starting a text conversation** (Inbox "New text", customer page "Text", `Texting::start`):
  - only to a customer with texting consent, or a number that texted the business before;
  - never to an opted-out number;
  - US/Canada only;
  - audited.
- **Texted pictures (MMS):**
  - downloaded from Twilio's own media URLs only, with the account credentials;
  - up to 5 images per text, 5 MB each;
  - stored privately under `inbox-media/{business}/` and served through `/inbox-media/{business}/{file}` to people with `messages.view` in that business (no-sniff, no-script headers);
  - Twilio retries are ignored before anything is downloaded;
  - other file types are noted in the message;
  - sending pictures stays out.
- **Own wording for appointment texts:**
  - `message_templates.sms_body`, edited beside the email (320 characters, placeholders checked, live preview);
  - empty uses the default.
- **Account closure** now also:
  - deletes calls, opt-outs and phone lines;
  - releases the numbers at Twilio;
  - removes texted pictures.

  This closes a gap left by D60.

**Why:** these finish the receptionist workflow. Owners can be briefed before taking a call, businesses can follow up by text with people who expect it, and customers can send a photo of the problem.

## D63 — Notification texts and phone push (2026-11-01)
**Decision:** the text and push notification channels, "coming soon" since D13, now work. Setup steps: `docs/notifications.md`.
- **Channels:** `sms` and `push` are Laravel notification channels registered by name. Their short form (title, one line, link) comes from each notification's existing in-app payload, so every notification that respects people's choices gains them without changes.
- **Availability decided at run time** (`NotificationChannels`):
  - texts need SureHelp's own sending number (`TELEPHONY_NOTIFY_FROM`) and texting switched on, with the same A2P gate as D61;
  - push needs a Firebase service account.

  Until then, settings say "Not available yet" and nothing is sent.
- **Texts go only to a verified mobile number** (`users.sms_phone` / `sms_phone_verified_at`):
  - **The code:** 6 digits, stored hashed, valid for 10 minutes, with 5 tries and 3 codes per 10 minutes. US/Canada only.
  - **Audit:** verifying and removing a number are recorded.
  - **STOP** to the notification number un-verifies it.
  - Texts are never sent from a business's line.
- **Push uses FCM HTTP v1:**
  - **Why:** the legacy server-key API used before was shut down by Google in 2024.
  - **How it signs in:** a service account JWT exchanged for a cached OAuth token.
  - **Delivery:** one request per device. Unregistered devices are removed.
  - Logs hold counts only.
- **Defaults:**
  - push is on by default wherever in-app is, so people with the app get it without setting anything up;
  - texts are always opt-in;
  - urgent escalations are forced to push as well as in-app and email.
- **Quiet hours** now hold texts and push as well as email.
- **Removed:** the API used to push a "New call" message directly, ignoring people's choices. Calls now notify through the normal channels (call.logged → in-app and push by default).
- **Mobile API:** `DELETE /api/v1/notifications/device-token` removes the app's device on sign-out.

**Why:** owners and agents asked to hear about missed calls and urgent problems on their phones. Push had silently stopped working when Google retired the old API.

## D64 — Card payments and automatic payments through Stripe, ready to switch on (2026-11-02)
**Decision:** a `StripeGateway` behind the existing `PaymentGateway` interface (D22), off until `BILLING_GATEWAY=stripe` and the Stripe keys are set. Payoneer stays the default. Setup steps: `docs/billing.md`.
- **Paying an invoice:**
  - **Pay by card** creates a Stripe Checkout session for the invoice's balance. It uses the business's Stripe customer (created on first use) and an idempotency key.
  - Card details are entered only on Stripe's page.
  - Only `billing.manage` (owners) can pay.
- **Booking what Stripe confirms:**
  - signed webhooks (`Stripe-Signature`, HMAC-SHA256 with a 5-minute window) for completed checkouts and payment intents;
  - each event id is processed once;
  - payments are booked through `RecordPayment` with method `stripe_card` and the PaymentIntent id as the reference, so no payment is booked twice;
  - no person is the "recorded by";
  - a payment is never booked above the open balance.
- **Automatic payments:**
  - **Saving the card:** "Pay and save card" sets `setup_future_usage=off_session`. The card's id and label are stored on the business, and both actions are audited.
  - **Charging it:** each newly issued invoice is charged to the saved card once (`ChargeInvoice`, idempotency key `autopay-{invoice}`), off session.
  - **A decline or a bank's request to confirm** leaves the invoice open, records the reason on the invoice and in the audit log, and sends "Automatic payment didn't go through" (payment.failed). It isn't retried.
  - **Turning it off** removes the card (detached at Stripe).
- **No SDK:** a small form-encoded REST client, tested with faked responses.
- **Not in this part:** refunds through the app (made in Stripe), retrying declined charges, Stripe Billing subscriptions (SureHelp keeps its own billing, D22), and bank debit (ACH) through Stripe.

**Why:** card payments, and payments that happen without anyone chasing them, were the next step in Phase 5. Building them behind the existing interface means switching on Stripe later is a configuration change, not a migration.

## D65 — Self-serve sign-up from the website (2026-11-03)
**Decision:** a public `/start` page where a business creates its own account: pick a plan, enter the business and owner details, accept the terms, start the plan's free trial. Details: `docs/signup.md`.
- **What a sign-up creates**, in one transaction:
  - the owner (business owner role, own password, no forced change);
  - their business, named as entered, in **onboarding** like a business staff create, and marked `signed_up_at`;
  - the chosen plan's free trial (nothing is invoiced until the trial ends; a plan without trial days is invoiced straight away, as in Admin);
  - the accepted Terms, Privacy Policy and DPA;
  - an audit entry `organization.signed_up`.
- **Email confirmed first:** the owner is signed in but sees "Check your email" until they open the link. This applies to the portal and to the mobile app login. Businesses created by staff aren't affected.
- **Staff are told:** "New sign-up" (in-app and email) to everyone who can manage businesses. Admin › Organizations shows "Signed up online".
- **Going live stays with staff:** connecting the phone line and switching the business to active work as before.
- **Abuse protection:**
  - a hidden spam-trap field (a filled-in form looks accepted but creates nothing);
  - 5 attempts per IP an hour (`SIGNUP_PER_HOUR`);
  - `SIGNUP_ENABLED=false` closes it and points visitors to the contact form.
- **Website:** the pricing cards' "Start Free Trial" buttons open `/start?plan=…`. A plan whose slug matches is preselected, otherwise the first public plan. The sign-in page links here too.
- **Not in this part:** taking a card at sign-up (owners can save one under Billing, D64), a CAPTCHA, and choosing add-ons at sign-up.

**Why:** every new business needed a sales call and staff typing in their details. Letting businesses start a trial themselves removes that step, while staff keep control of going live.

## D66 — AI content studio for social posts (2026-11-04)
**Decision:** the social composer and calendar get AI writing (G-2). It runs on the existing `AiProvider` (Claude, structured JSON output) with the SEO service's public-facts builder (D57). Setup and behaviour: `docs/social.md` › Writing with AI.
- **Three uses:**
  - **write:** a version per network from a short brief and a tone;
  - **rewrite:** shorter, friendlier, more professional, or with a call to action;
  - **plan the month:** 1–5 posts a week as drafts in the calendar, queued (`PlanSocialMonth`).
- **Only public facts:** the AI sees the business's public details and its brand voice. Internal and team-only knowledge never reach it, as for SEO.
- **Fact checks for a person to resolve:**
  - claims the AI itself couldn't back up;
  - phone numbers, prices, discounts and "years in business" not found in the details (`FactCheck`);
  - network-rule errors.

  Nothing is removed automatically; the person reading the post decides.
- **People stay in charge:**
  - AI never publishes; month-plan posts are drafts that the publisher ignores.
  - An AI draft scheduled by anyone but an owner waits for an owner's approval, even with approval switched off. An owner scheduling it counts as approving it. This replaces "AI posts always need approval", which made owners approve their own scheduling.
- **Labels:** *AI draft*, *Written by AI* or *AI-assisted* on posts; `ai_label` in the API.
- **Allowance:**
  - each request is logged in `social_ai_runs` with its tokens;
  - 150,000 tokens a month by default, or the plan's `social_ai_tokens` limit;
  - separate from the SEO allowance;
  - an unavailable AI uses none;
  - the runs are deleted when an account closes.
- **Brand voice:** one free-text field on the business profile, edited under Social › Accounts & settings by people who manage posts.
- **Not in this part:** AI-chosen photos or image generation, alt text suggestions, writing in other languages, and learning from post results (G-5 analytics first).

**Why:** a blank post box is the main reason businesses stop posting. Drafting from the business's own facts, with a person checking and scheduling, keeps posts accurate and on-brand. The AI and approval plumbing already existed.

## D67 — Content-Security-Policy on every page (2026-11-05)
**Decision:** every HTML page gets a Content-Security-Policy, in force by default. Details: `docs/security.md`.
- **Scripts:**
  - run only from this site, the public website's three CDNs, or inline with a fresh per-request nonce;
  - Vite and Livewire add the nonce themselves; our inline scripts use `Vite::cspNonce()`;
  - inline event handlers on the public site became `data-call` attributes handled by one listener.
- **Kept on purpose:**
  - `'unsafe-eval'`, because Alpine inside Livewire evaluates its attributes. Moving to Alpine's CSP build would mean rewriting every inline expression.
  - Inline styles, because the design sets colours and sizes in `style` attributes. Style injection is far less dangerous than script injection.
  - Any HTTPS image or media source: social avatars and media.
- **Blocked:** plugins (`object-src 'none'`), `<base>` changes, framing by other sites, and connections except to this site and Twilio.
- **Reporting and rollback:**
  - browsers report what was blocked to `/api/csp-report` (throttled), written to the security log;
  - `CSP_MODE=report` switches to report-only by changing `.env` (then `php artisan config:cache`), with no code deploy;
  - `CSP_MODE=off` removes it.
- **Checked in a browser:** all 92 pages without parameters, as a visitor, owner, agent and admin, with no violations. The phone, charts, calendar, Alpine panels, Livewire saves and the website calculator all work under the policy.
- **Fixed along the way (existing bugs):**
  - the FAQ page script failed on items without a paragraph;
  - the website chat snippet's Copy button never worked, because `@js()` isn't compiled inside a component attribute.

**Why:** the last open item from the security audit. With a policy, an injected script, the usual result of an XSS bug, doesn't run, even if a template mistake lets one through.

## D68 — Where customers come from, in Results (2026-11-06)
**Decision:** Results, its PDF and its CSV get a "Where customers came from" section (the first part of G-5). It is counted only from SureHelp's own records, so it works today without any network's analytics approval.
- **New customers by how they first got in touch:**
  - phone calls;
  - website form or online booking;
  - website chat;
  - Facebook Messenger, Instagram and text messages, taken from the customer's first conversation.

  Customers the business typed in or imported are shown apart ("not counted"), so the sources add up to real inbound interest.
- **Bookings by who made them:**
  - SureHelp agents by phone;
  - online booking on the website;
  - the AI assistant;
  - the business's own team.

  Events copied in from a connected Google or Microsoft calendar aren't counted as bookings.
- **Website pages viewed:**
  - the snippet asks for its settings once per page, so that request is counted;
  - one number per business per day (`website_daily_views`), in the business's timezone;
  - no visitor, IP address or cookie is stored, and no extra request is made;
  - shown as "—" until the snippet has reported a view.
- **Social media:** posts published (per account) and new Facebook and Instagram conversations.
- **Comparisons:** each total is compared with the previous period of the same length. The page views are deleted when an account closes.
- **Not in this part (rest of G-5):**
  - reach, engagement and clicks from Facebook, Instagram, LinkedIn and Google, which need each network's insights permissions after app review;
  - tracking links in posts.

**Why:** owners asked which of the things they pay for actually bring customers. The data was already in SureHelp, just never added up.

## D69 — Link-in-bio page and last-minute openings (2026-11-07)
**Decision:** the first two growth tools from G-6, built only on what SureHelp already has.

**Link in bio** (Social › Accounts & settings): a public page at `/b/{business-slug}` for Instagram, Facebook and TikTok bios.
- **What it shows:** the logo or initial, name, town and description.
- **Buttons:**
  - **Book online** and **Send us a message**, when those website tools are on: they open the SureHelp snippet on the page;
  - **Call**, from the profile phone;
  - **Visit our website**;
  - up to six links of the business's own.
- **When it's public:** it's off until the business switches it on, and it disappears while the business is paused, cancelled or closed. Changes are audited.
- **The snippet can now be opened from any page:**
  - links to `#surehelp-book`, `#surehelp-chat`, `#surehelp-call` or `#surehelp-lead`, the same in the page address, or `SureHelp.open('book')`;
  - so a business's own website can have a "Book now" button too.
- **Snippet fixes:**
  - SureHelp's own pages are always allowed, even when a business limits the snippet to its own site.
  - When a browser sends no `Origin` (a same-site page load), the `Referer` tells where the page is. Before, such requests were refused.

**Last-minute openings** (Social › Posts):
- **The card:** shows free one-hour times in the next three open days, at most four per day, spread across the day. It uses the same rules as online booking: hours, notice, existing appointments and busy time from connected calendars.
- **"Post about these openings":** opens a new post with the times and the booking link, the link page's `#surehelp-book` when it's live, otherwise the website. A matching brief is filled in for Write with AI.

**Not in this part (rest of G-6):** Google Business Profile sync, turning reviews into posts, job photos from agents, seasonal campaign packs, and several locations.

**Why:** "link in bio" and "we have a slot tomorrow" are the two posts small service businesses make most. Both now take a click and lead straight to a booking.

## D70 — API for the new mobile app: capabilities, notification centre, account closure (2026-11-08)
**Decision:** the first server tasks from `docs/mobile-app-plan.md` (API-1, API-2, API-4). Details: `docs/api.md`.
- **`GET /me/capabilities`:** the permissions a person actually has, plus for business users their role, features, plan limits and plan, and the account's state (email confirmed, two-step sign-in on or required, may close the account). The app hides what someone can't use; the server still checks every call.
- **Notification centre:**
  - the same items as the web bell, paginated, with read state, mark one or all read, and remove;
  - each item gets a `target`: the web route name and its parameters, worked out from the stored link by matching the router. Links to other sites get no URL or target.
  - Pushes now carry `notification_id`, `route` and `route_params`, so a tap opens the right screen and marks the item read.
  - The two staff notifications that lacked an `event` got one.
- **Account closure in the app:** the same rules as the web (owner only, business name and password, 5 tries per 10 minutes, a 30-day grace period, keep the account until then).
  - Everyone else gets `can_request: false`, with whom to ask and the contact email.
  - This meets Apple's rule 5.1.1(v) and Google Play's account-deletion requirement for apps with sign-in: businesses own their data, so closing the business is the deletion.
- **Not in this part:** the other API tasks (API-3 and API-5 to API-19) in the plan's order.

**Why:** the mobile app can't be submitted to the stores without in-app account deletion. A notification centre and screens that open from a push are the base of the app's experience. Telling the app what a person may use stops it showing screens that would only answer 403.

## D71 — API for the mobile app: settings, update check, call details, Results (2026-11-09)
**Decision:** the next server tasks from `docs/mobile-app-plan.md` (API-3, API-5, API-6, API-10). Details: `docs/api.md`.
- **Notification settings:**
  - the same choices as the web pages, per person: events and channels (only those the person can receive), quiet hours, and the daily summary for business users;
  - the texting number is verified with a code, using the web's `NotificationPhone` rules (10 minutes, 5 tries, 3 codes per 10 minutes).
- **Update check:**
  - `GET /app/version` needs no sign-in, so a build too old to sign in can still be told to update;
  - `minimum` blocks the app with "Please update" and `latest` shows a gentle prompt, both per platform in `.env`.
- **Call details:**
  - what the agent logged, plus the phone call itself;
  - the recording, AI summary and transcript need `calls.recording.view`, as on the web;
  - the recording is a signed link valid for 15 minutes that an audio player opens without the token, still checked against the person's permission and audited with `channel: app`.
- **Results:** the same numbers as the web page and PDF, including where customers came from (D68), plus the PDF itself.

**Why:** these are the screens owners use most on the phone: who called and what was said, how the month went, and controlling what buzzes them. The update check lets old builds be retired safely once the new app ships.

## D72 — API for the mobile app: inbox actions, customers, app links (2026-11-10)
**Decision:** the next server tasks from `docs/mobile-app-plan.md` (API-7, API-8, API-16). Details: `docs/api.md`.
- **Inbox:** the web Inbox's actions, all audited as on the web:
  - start a text conversation (the consent rules of D62);
  - assign, close and reopen;
  - turn the AI off or on for a conversation;
  - mark read;
  - send or discard the AI's suggested reply;
  - rate AI answers with a correction.

  Texted pictures sit behind a web address that needs a browser session, so the app gets signed links valid for an hour; each still checks the person's `messages.view`. Sending pictures is still TEL-4.
- **Customers:**
  - adding uses one `CreateCustomer` action, now shared with the web, so duplicate checks are identical;
  - a taken phone number answers `409` with the existing customer, and a lookup warns while typing;
  - editing is partial (fields not sent are kept) through the web's `UpdateCustomer`, which records consent changes;
  - notes and tags as on the web.
- **App links:** `/.well-known/assetlinks.json` and `/.well-known/apple-app-site-association` are built from config. They list no app until the store builds' fingerprints and team id are set.
- **Bug found while testing:** the first version of the signed picture link had a double slash in it, because `url()` drops a trailing slash. A test now opens the link.

**Why:** answering customers and keeping their details right are the things owners do most between jobs. App links make every email and notification link open straight in the app.

## D73 — API for the mobile app: social posts, billing, team (2026-11-11)
**Decision:** API-9, API-11 and API-12 from `docs/mobile-app-plan.md`, each through the same actions as the web, so the rules can't drift. Details: `docs/api.md`.
- **Social posts:** the web composer's rules through `SavePost`:
  - per-network checks;
  - owner approval for managers' posts and AI drafts;
  - the scheduling window.

  Also: a "check without saving" endpoint for live hints, photo upload, Write with AI and rewrites (D66), last-minute openings, the link page (D69), cancel, retry and delete. They're behind the `social_publishing` feature like the web pages.
- **Billing:**
  - the plan, balance, invoices with lines and payments, and the PDF;
  - paying by card returns a Stripe Checkout address for an in-app browser. Stripe sends people back to `/app/billing?paid=…`, which app links open in the app. The payment is booked from Stripe's webhook as before.
  - Also: reporting a payment made another way, and turning automatic payments off.
  - Managers see billing; only owners pay or change it.
- **Team:** members and pending invitations, inviting (managers invite staff; owners invite managers too; seat limits apply), resending and cancelling invitations, changing roles and removing people (owners). Nobody changes the owner's access or their own.

**Why:** with these, a business owner can run their day from the phone: posts, money and people. Each endpoint uses the web's action, so a rule changed for the web changes for the app too.

## D74 — API for the mobile app: agents, search, OpenAPI (2026-11-12)
**Decision:** the last planned server tasks from `docs/mobile-app-plan.md` (API-13, 14, 15, 17, 19). Only API-18 is left, and it waits for a decision on calls in the app. Details: `docs/api.md`.
- **Shift requests:** swap or time off through the web's `ShiftRequests` service. Agents see their requests and withdraw waiting ones; schedulers still decide on the web.
- **Quality reviews:** agents read only their own scored calls, with the scorecard, strengths and improvements, and confirm they've read them.
- **Availability:** calls still ring the softphone on a computer, so the app can only step away or come back while that softphone is online. It changes the status without counting as a softphone check-in, so the router never offers calls to a device that can't answer.
- **Search:** the web search's queries moved into a shared `BusinessSearch` service used by the web page and the app, with the same per-permission groups.
- **OpenAPI:** `GET /openapi.json` is built from the routes, so it can't drift:
  - paths, methods and parameters;
  - sign-in, permission, role and feature checks, idempotency keys and rate limits;
  - a summary from each controller's doc comment.

  Field detail stays in `docs/api.md`.

**Why:** with these, the app covers agents' day too: schedule, feedback, and stepping away. The generated description gives the mobile team a checkable contract.

## D75 — Business users start on their calendar (2026-10-21)
**Decision:** after signing in, business users land on the **Calendar** (website and app), not the dashboard.
- **Website:** `User::homeUrl()` sends them to `app.calendar` when they may see it, otherwise to the dashboard. An owner who hasn't finished setup still starts in the setup wizard, and finishing it lands on the calendar. In the menu, Calendar, Appointments and Inbox come first; Dashboard and Results come after the day-to-day pages.
- **App:** the tabs are Calendar, Inbox, Calls, Overview (the old Home, with the numbers) and More. Business users land on Calendar.

**Why:** businesses open SureHelp to see and run their schedule. Their numbers matter, but they aren't what people need at first glance, and profit and loss isn't something SureHelp works out.

**Update:** on the website this is replaced by D77. The dashboard is home again and opens on the calendar.

## D76 — API follow-ups for the mobile app (2026-10-21)
**Decision:** small, additive API changes that remove workarounds in the new app:
- `POST /client/tasks` takes `call_id`, so a follow-up links to its call;
- `customer.tag_list` adds tag ids next to the existing `tags` names;
- `/agent/clients` and agent call logs name their business;
- `GET /agent/call-logs/{id}` opens one log for editing;
- `GET /agent/companies/{id}` carries the business's hours, briefing, services and knowledge (the web workspace's sidebar);
- `GET /admin/users` takes `search`.

**Why:** the app had to guess or make extra calls for each of these. Nothing existing changes shape, so older apps keep working (the v1 compatibility promise).

## D77 — The dashboard opens on the calendar, and each person picks its sections (2026-10-22)
**Decision:** this replaces the website half of D75.
- **Landing page.** Business users land on the **Dashboard** again, and Dashboard is back at the top of the menu (then Calendar, Appointments, Inbox and the rest).
- **The calendar comes first.** The calendar is the dashboard's first section by default. It's the same calendar as the Calendar page, with its source switches and live refresh.
- **Each person chooses what they see.** "Customise" on the dashboard lets each person show, hide and reorder the sections. The sections are:
  - Calendar
  - Key numbers
  - Call volume
  - Today's schedule
  - Recent calls
  - Customer activity
- **Where the choice is stored.** The choice belongs to the person, not the business. It's kept in `users.dashboard_layout`, and null means the standard layout.
- **What stays fixed.** "Back to the standard layout" resets it. Alerts (escalations, follow-ups, service status, setup) always stay at the top.
- **Permissions still apply.** Sections someone isn't allowed to see never show, whatever is saved: the calendar needs `calls.view` and customer activity needs `customers.view`. Hidden sections aren't computed at all. The period switch shows only while a section that uses it is on screen.
- **The mobile app is unchanged.** It still opens on its Calendar tab, with the numbers under Overview.

**Why:** one fixed first screen didn't suit every business. Most want their schedule first, while some owners want the numbers first. Putting the calendar inside the dashboard gives both: the schedule at first glance, and the dashboard stays home. The code is in `App\Support\Dashboard\DashboardLayout` and `x-client.calendar-board`.

## D78 — An AI sales chat on SureHelp's own website (2026-10-22)
**Decision:** the public website gets its own sales assistant. It answers questions, captures leads and
books demo calls with the SureHelp team. It doesn't reuse a business's Inbox chat, because those leads
belong to SureHelp, not to a tenant.
- **Leads.** Leads become website enquiries (source "Chat") with a rule-based hot / warm / cold score, the
  details the chat learned and the transcript. The team can take over and reply from Admin › Website
  enquiries.
- **Demos.** Demo times come from sales hours in `config/sales.php`. Booking takes a row lock, so two
  visitors can't take the same place. The visitor gets an email invitation with a calendar file.
- **The AI only reads free text.** Buttons, the time picker and the form never call it. Each call uses:
  - a cached prompt (plans, facts, FAQ);
  - the last 12 messages, plus the lead's details as facts;
  - low effort, a 2,000-token cap and 4 tool rounds.
- **Caps.** Each chat, each IP and each day has a limit. Without a key, or past the budget, the chat keeps
  working on buttons, forms and keyword answers. The model is configurable (`SALES_CHAT_MODEL`).
- **Gentle on visitors.**
  - The chat only asks for a name and email when booking or for a follow-up.
  - It never re-asks for something it already knows.
  - Text consent is only the optional checkbox.
- **Fix.** The public chat APIs (`api/sales-chat/*`, and `api/chat/*` used on SureHelp-hosted pages) are
  left out of Sanctum's CSRF check, which counted our own site as a stateful front end.

**Why:** the website's only lead path was a long contact form, and "Book a demo" led to it. A chat that
answers at once and books a time in a few taps turns more visitors into calls with the team. Keeping the
AI to free text and caching keeps the cost per lead small. Details are in `docs/sales-chat.md`.

## D79 — Go-live readiness check (2026-10-23)
**Decision:** `php artisan launch:check` and *Admin › System status* check an installation against what it
needs to go live. They are not a deploy script; they only check.
- **Statuses:**
  - **fail:** must be fixed before launch (production mode, HTTPS, database, migrations, front-end build,
    storage, cron, queue, real email sending).
  - **warn:** strongly recommended (secure cookies, log level, CSP enforced, token expiry, agent assignments).
  - **off:** a feature that stays hidden until its keys are set (AI, calendars, Twilio calls and texts,
    Stripe, invoice details, plans, Meta, LinkedIn, Google Business Profile, push, store links).
- **Addresses to register.** `--urls` (and the page) list every redirect and webhook address to paste
  into each provider, built from the live `APP_URL`.
- **Test email.** `--mail=` (and a button on the page) sends one.
- **Scheduler heartbeat.** The scheduler records a heartbeat every minute, so a missing cron entry shows
  up.
- **Safety.** No secret values are ever printed. A section that can't be read (for example, the database
  is down) shows as one failed line instead of an error.
- **Order.** [go-live.md](go-live.md) gives the order, starting with the approvals that take weeks: A2P
  10DLC, Google OAuth verification and Business Profile access, Meta App Review, and the store accounts.

**Why:** most of the product is built but waiting on settings and third-party approvals. One place that
says exactly what's missing turns going live into a checklist instead of guesswork.
