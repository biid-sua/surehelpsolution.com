# Project status

_As of 2026-10-04. Detailed tracker: [implementation-plan.md](implementation-plan.md). Reasons behind decisions: [decisions.md](decisions.md)._

**Summary:** Phase 0 (audit), Phase 1 (foundation) and Phase 2 (core business operations) are built, tested and merged into `develop` (196 automated tests passing). **Nothing from Phase 1 or 2 is live in production yet.** The next big step is a production deploy, then Phase 3 (Calendar).

---

## 1. Done

### Phase 0: Audit and plan
- Architecture audit, current-state report, implementation plan, decision log (D1–D15).

### Phase 1: Foundation
| Step | What it delivered |
|---|---|
| P1-0 Hotfixes | 12 security and bug fixes: `.env` access blocked, password logging removed, login throttling, deactivated users' API tokens revoked, duty-schedule API fixed, no raw errors in API responses, fake dashboard data removed |
| P1-1 Engineering baseline | Dependencies updated (46 security advisories → 0), CI (code style, static analysis, tests, MySQL migrations), Git repository |
| P1-2 Organizations (multi-tenancy) | Every business's data is isolated; agents only see businesses they're assigned to; existing data backfilled |
| P1-3 Roles and permissions | Platform roles (Super Admin, Operations Manager, Support, Agent Supervisor, Agent) and business roles (Owner, Manager, Staff) |
| P1-4 New portals | Business portal `/app` and admin console `/admin` on a shared design system |
| P1-5 Audit log and notifications | Who-did-what log for sensitive actions; in-app and email notifications with per-user preferences |
| P1-6 API conventions | Consistent API errors, security headers, rate limits, device management for the mobile app, contract tests protecting the existing mobile API |

### Phase 2: Core business operations
| Step | What it delivered |
|---|---|
| P2-1 Business profile and hours | Profile, locations, opening hours (split shifts, holidays, temporary closures), timezone-aware "open now" |
| P2-2 Services | Services with duration, buffer and prices (stored in cents) |
| P2-3 Customers (CRM) | Customers deduplicated by phone, tags, consent, full timeline; calls linked automatically |
| P2-4a Call outcomes | Each business can rename, switch off and add outcomes; each outcome has a fixed meaning that drives reports |
| P2-4b Tasks and follow-ups | Call-back tasks created automatically from calls, due within business hours; overdue reminders |
| P2-4c Escalations | Urgent issues tracked to resolution; urgent alerts always delivered, with a reminder if not acknowledged |
| P2-5 Appointments | Booking with database-enforced protection against double-booking; free-time suggestions |
| P2-6 Knowledge base and rules | Business knowledge for agents; enforced rules (booking windows, service areas, required details) |
| P2-7 Agent workspace | New `/agent` workspace: business briefing next to a guided call form that saves call, booking and follow-up together |

### Merge of the cloud session (2026-10-04)
- Branch `claude/phase-2-development-1yiwc2` merged into `develop` and pushed.
- Two deploy-blocking bugs found by rehearsing the production upgrade on MariaDB and fixed: the appointments table couldn't be created on MySQL/MariaDB, and an older data backfill read a table that a later migration creates. Full upgrade and rollback now verified on a copy of real data.

---

## 2. Pending

### A. Can be done now (no outside dependency)
| Item | Notes |
|---|---|
| Move the classic admin screens (Users, Duty schedules, Contact forms) to the new admin console | Required to finish Phase 1's exit criteria |
| Replace the remaining inline role checks in the legacy controllers with permissions | Done together with the item above |
| Two-factor authentication for platform staff and agents, plus idle session timeouts | Decision D8; not built yet |
| Customer duplicate merge (CRM-04) | Merge two customer records with a person confirming |
| Content-Security-Policy header | Only possible after the classic screens stop loading scripts from CDNs |
| Phase 3 (internal part): calendar views, availability refinements | The internal calendar and availability engine already exist from P2-5 |

### B. Needs action from you (operations)
| Item | Notes |
|---|---|
| **Deploy Phase 1 and 2 to production** | Follow [deployment.md](deployment.md). Run the deploy rehearsal in [testing.md](testing.md) first |
| Production clean-up from P1-0 | Confirm `/.env` returns 403, rotate secrets if it was ever reachable, delete old `storage/logs/*.log` (they contain passwords), move `.zip`/old backups off the server, run `php artisan audit:call-ownership --details` |
| Staging environment | Not set up yet; recommended before larger releases |
| Delete branch `feature/p2-4b-tasks` | A superseded local draft; the cloud version is merged |

### C. Blocked on outside accounts or approvals
| Item | Waiting for | Lead time |
|---|---|---|
| Phase 3: Google and Microsoft calendar sync | Google Cloud and Azure app registrations, OAuth verification | Weeks (Google verification) |
| Phase 3b: Telephony (live calls) | Twilio account | Days |
| SMS (reminders, urgent escalation SMS, Phase 4 messaging) | Twilio **A2P 10DLC** brand and campaign registration | **Several weeks, so start now** |
| Mobile push notifications | Firebase service-account key (FCM v1) | Days |
| Real-time updates and a background worker that keeps running (Reverb, Horizon) | Hosting move off cPanel (D6: Laravel Cloud / Forge) | Days |
| Phase 5: Billing | Stripe account, US legal entity, tax advice | Weeks |
| Phase 6: AI foundation | Choice of LLM vendor, data-processing agreements (DPA/BAA) | Weeks |
| Phase 8–9: Growth and social | Google Business Profile API access, Meta app review | Weeks |
| Phase 10: Mobile apps | Apple and Google developer accounts | Days to weeks |

---

## 3. Dependencies

```
Production deploy ─────────────► everything reaches real users
  └─ needs: P1-0 server clean-up, deploy rehearsal

Classic screens → new console ─► inline role checks removed ─► Phase 1 exit criteria met
                              └─► CSP header

Phase 3 Calendar
  ├─ internal calendar ............ ready (built on P2-5)
  └─ Google / Microsoft sync ...... needs OAuth app registrations

Phase 3b Telephony ............... needs Twilio account
  └─ call recordings / transcripts feed later AI features (Phase 7)

Phase 4 Communication
  ├─ email templates, reminders ... ready to build
  └─ SMS .......................... needs A2P 10DLC approval
        └─ urgent-escalation SMS (finishes P2-4c)

Phase 5 Billing .................. needs Stripe + US entity + tax advice
  └─ plan limits and add-ons gate Phases 6–9 features

Phase 6 AI foundation ............ needs LLM vendor + DPA/BAA
  └─ Phase 7 AI products (chatbot, copilot, call summaries)
       └─ uses Phase 2 knowledge base and rules, Phase 3b transcripts

Phase 8–9 Growth / social ........ needs Google Business Profile + Meta approval

Phase 10 Mobile .................. needs Apple/Google developer accounts + Firebase key
  └─ generated OpenAPI spec (deferred from P1-6)

Hosting move (D6) ................ unlocks real-time updates (Reverb) and a long-running worker (Horizon)
```

**Suggested order:** deploy Phases 1–2 → start the long-lead registrations today (A2P 10DLC, Google OAuth verification, Stripe) → build what isn't blocked (classic screens, 2FA, duplicate merge, Phase 3 internal calendar, Phase 4 email) while the approvals come through.
