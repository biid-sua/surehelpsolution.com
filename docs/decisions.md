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
**Decision:** **Blade + Livewire 3 + Alpine.js + Tailwind CSS 4, built with Vite.** It comes with an in-house component library (`x-ui.*`) on design tokens that keep the dark navy/purple brand. Chart.js and FullCalendar are bundled through npm with pinned versions; no runtime CDNs. Existing Bootstrap pages stay as they are until each one is rebuilt inside the new shell, one page at a time, with no mixing of frameworks on a page.
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
