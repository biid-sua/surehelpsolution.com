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
