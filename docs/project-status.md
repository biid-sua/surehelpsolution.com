# Project status

_As of 2026-10-06. Detailed tracker: [implementation-plan.md](implementation-plan.md). Reasons behind decisions: [decisions.md](decisions.md)._

**Summary:** Phases 0–2 are merged and **deployed to production** (2026-10-04). Since then, merged into `develop` and **not yet deployed**:
- **Google / Microsoft calendar sync (P3-1)** is built. It goes live once the two OAuth apps are registered ([calendar-sync.md](calendar-sync.md)).
- **Billing through Payoneer (P5-1)** is built and usable as soon as it's deployed and the payment settings are filled in ([billing.md](billing.md)).
- **Accounts (D24):**
  - Forgot password and email confirmation.
  - Two-step sign-in, mandatory for staff and agents.
  - Idle timeout and "sign out everywhere".
  - A profile page.
  - Team invitations for business owners.
  - Terms acceptance with version tracking.
- **Setup wizard and Results (D25):**
  - New businesses set themselves up in seven guided steps, with industry templates.
  - A Results page shows calls answered, jobs booked, leads, after-hours calls and estimated revenue.
  - A monthly report email with a PDF goes out on the 1st.
- **Support and communication (D26):**
  - "View as client" and global search for staff.
  - Daily summary email and quiet hours.
  - Appointment confirmation, reminder and cancellation emails to customers, with editable templates.
  - Merging duplicate customers.
  - Vacation mode.
- **Call quality reviews (D27):** supervisors score a random sample of each agent's calls against a five-point scorecard; agents read the feedback and confirm it.
- **Usage, add-ons and plan limits (D30):** plans include a number of calls with a price per extra call, billed on the next invoice; a usage meter and alerts; paid add-ons; limits on team size and calendars.
- **Data & privacy (D29):** owners download all their data, choose how long history is kept (3 years by default), erase a customer's personal data on request, and close their account with a 30-day grace period.
- **Shift requests and coverage (D28):** agents ask to hand over a shift or for time off; schedulers approve or decline, and a grid shows hours with nobody on shift.
- **One UI (D23):** the product now has a single version of every screen. The old admin and agent pages were rebuilt in the new design and deleted.
- **Social publishing (G-1, D33):** Facebook, Instagram, LinkedIn and Google Business Profile posts, scheduled up to 12 months ahead with owner approval ([social.md](social.md)). Requirements for AI content with SEO and websites added to the spec (§41A–41C).

303 automated tests pass.

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

### One UI (decision D23, 2026-10-11)
| Step | What it delivered |
|---|---|
| P1-7 One UI | Every screen now exists once, in the new design. Users, Duty schedule and Website enquiries moved into the admin console. Agents get *My calls* and *My schedule*. The first-login password page was redesigned. The previous admin dashboard, agent dashboard ("classic call form") and their pages were deleted. |

### Accounts (decision D24, 2026-10-12)
| Step | What it delivered |
|---|---|
| P1-8 Accounts | Sign-in page; forgot password by email; two-step sign-in with an authenticator app (required for staff and agents, Super Admin can reset it); 30-minute idle timeout for staff and agents; "sign out other devices"; profile page with devices and sign-in history; owners invite managers and staff by email; everyone accepts the current Terms / Privacy Policy (and DPA for businesses), asked again when they change |

### Onboarding and results (decision D25, 2026-10-13)
| Step | What it delivered |
|---|---|
| Setup wizard | New owners are guided through business details, services (pre-filled for their industry), hours and service area, call handling (greeting, FAQs, rules), calendar and team; progress saved per step; admins see who is stuck and are emailed when someone finishes |
| Results | Monthly calls answered, jobs booked, new leads, after-hours calls caught and estimated revenue (jobs × average job value), with breakdowns and a heatmap; PDF; report emailed on the 1st |

### Support, communication and CRM (decision D26, 2026-10-14)
| Step | What it delivered |
|---|---|
| Support tools | Staff can view the portal as a business user (clearly bannered and fully audited) and search everything from one box |
| Daily summary and quiet hours | A morning email with yesterday's results and today's agenda; non-urgent emails held overnight on request |
| Customer emails | Appointment confirmations, reminders, changes and cancellations to customers, in the business's name and words |
| Duplicate customers | Suggested duplicates, side-by-side compare, merge everything into the record you keep |
| Vacation mode | Plan time away ahead; bookings blocked only on those days; agents and the owner see it |

### Agent quality, scheduling, privacy and billing extras (D27–D30)
| Step | What it delivered |
|---|---|
| Call quality reviews | Each morning one random call per agent goes to the review queue. Supervisors score it (greeting, accuracy, booking attempt, tone, compliance), write feedback, and see each agent's average and pass rate. Agents get a notice, read it and confirm |
| Usage, add-ons and limits | Calls included per plan, extra calls billed on the next invoice, usage meter and history, 80%/100% alerts; add-ons turned on and off by owners and billed with renewals; team and calendar limits |
| Data & privacy | Full data export as a ZIP; retention period per business with a daily cleanup; erase one customer's personal data; close the account after 30 days (invoices kept) |
| Shift requests and coverage | Agents ask to hand over a shift or for time off from *My schedule*. Schedulers approve or decline on *Duty schedule*; approved leave clears those shifts, and a coverage grid shows each hour's agents and the gaps |

### Phase 3: Calendar
| Step | What it delivered |
|---|---|
| P3-1 Calendar sync | Businesses connect Google Calendar or Outlook / Microsoft 365. Bookings appear in their calendar and follow every change. Their busy times block double-booking, by agents and in the portal. An event they edit themselves is never overwritten. Lost access alerts the owner and warns agents. |

### Phase 5: Billing (started early, decision D22)
| Step | What it delivered |
|---|---|
| P5-1 Billing with Payoneer | Plans, subscriptions with free trials, automatic monthly or yearly invoices with PDF, reminders for overdue invoices, and a client *Billing* page with Payoneer card/ACH payment links and bank details. *Admin › Billing* to record payments, void invoices, issue one-off invoices and manage plans, with revenue figures. |

### Phase 8: Growth (started 2026-10-06, decisions D33–D36)
| Step | What it delivered |
|---|---|
| G-1 Social publishing | Businesses connect Facebook Pages, Instagram, LinkedIn and Google Business Profile, then write one post with optional per-account text, photos and Google offer/event details. They publish now or schedule up to 12 months ahead in their own timezone, with per-network checks and previews. Owner approval covers posts by managers and the SureHelp team. Includes a content calendar, media library, retries, alerts for lost access and failed posts, and mobile API approval. Each network goes live after its app review ([social.md](social.md)). |

### Merge of the cloud session (2026-10-04)
- Branch `claude/phase-2-development-1yiwc2` merged into `develop` and pushed.
- Two deploy-blocking bugs found by rehearsing the production upgrade on MariaDB and fixed: the appointments table couldn't be created on MySQL/MariaDB, and an older data backfill read a table that a later migration creates. Full upgrade and rollback now verified on a copy of real data.

---

## 2. Pending

### A. Can be done now (no outside dependency)
| Item | Notes |
|---|---|
| Content-Security-Policy header | Only possible after the public website stops loading scripts from CDNs (its redesign) |
| G-3 Connect a website | Ownership check, embed snippet (booking, click-to-call, lead form), SEO health check |

### B. Needs action from you (operations)
| Item | Notes |
|---|---|
| **Before deploying the accounts release** | Tell staff and agents they'll need an authenticator app on their phone. Check outgoing email works (password resets, invitations). Set `SESSION_DRIVER=database`. See [deployment.md](deployment.md) |
| Mobile app: two-step code field | Staff and agents can't sign in to the app until it sends `two_factor_code` ([api.md](api.md)) |
| **Deploy `develop`** | Everything is merged. On the server: `composer install --no-dev -o`, `php artisan migrate --force`, `npm ci && npm run build`, `php artisan optimize`. Enable the PHP `zip` extension first |
| **Billing setup** | *Admin › Billing › Payment settings*: Payoneer payment link and receiving-account bank details. Then create plans and subscribe businesses ([billing.md](billing.md)) |
| Production clean-up from P1-0 | Confirm `/.env` returns 403, rotate secrets if it was ever reachable, delete old `storage/logs/*.log` (they contain passwords), move `.zip`/old backups off the server, run `php artisan audit:call-ownership --details` |
| Staging environment | Not set up yet; recommended before larger releases |
| Delete branch `feature/p2-4b-tasks` | A superseded local draft; the cloud version is merged |
| Pick a domain for hosted websites | e.g. `surehelp.site`, on Cloudflare (D35) |

### C. Blocked on outside accounts or approvals
| Item | Waiting for | Lead time |
|---|---|---|
| Calendar sync going live (built) | Google Cloud and Microsoft Entra app registrations ([calendar-sync.md](calendar-sync.md)). Google's verification of the calendar scopes takes weeks; until then up to 100 test users can connect | Days, then weeks for verification |
| Phase 3b: Telephony (live calls) | Twilio account | Days |
| SMS (reminders, urgent escalation SMS, Phase 4 messaging) | Twilio **A2P 10DLC** brand and campaign registration | **Several weeks, so start now** |
| Mobile push notifications | Firebase service-account key (FCM v1) | Days |
| Real-time updates and a background worker that keeps running (Reverb, Horizon) | Hosting move off cPanel (D6: Laravel Cloud / Forge) | Days |
| Automatic card payments (billing works today with Payoneer and manual confirmation) | Payoneer Checkout (Hong Kong entity, ~$20k/month volume) or Stripe (US entity); tax advice | Weeks |
| G-2 AI content studio (Phase 6 starts here, D34) | Anthropic API key and commercial terms / DPA in the Anthropic Console | Days |
| Publishing to Facebook and Instagram | Meta App Review (`pages_manage_posts`, Instagram content publishing) and Business Verification (D33) | **Weeks, start now** |
| Publishing to LinkedIn Company Pages | LinkedIn Community Management API access: registered company, verified Page, two-tier review with a screencast | 1–4 weeks per tier |
| Google Business Profile posts | Business Profile API access request | Days to weeks |
| TikTok public posts | TikTok Content Posting audit (posts stay private until then) | Weeks |
| G-4 SureHelp Sites on customers' own domains | Sites domain, Cloudflare for SaaS, hosting move (D6) | Days to weeks |
| Phase 10: Mobile apps | Apple and Google developer accounts | Days to weeks |

---

## 3. Dependencies

```
Production deploy ─────────────► everything reaches real users
  └─ needs: P1-0 server clean-up, deploy rehearsal

One UI (D23) ..................... done: every screen in the new design
  └─ public website redesign ─► CSP header

Phase 3 Calendar
  ├─ internal calendar ............ done (P2-5)
  └─ Google / Microsoft sync ...... built (P3-1); live after OAuth app registrations

Phase 3b Telephony ............... needs Twilio account
  └─ call recordings / transcripts feed later AI features (Phase 7)

Phase 4 Communication
  ├─ email templates, reminders ... ready to build
  └─ SMS .......................... needs A2P 10DLC approval
        └─ urgent-escalation SMS (finishes P2-4c)

Phase 5 Billing .................. P5-1 done with Payoneer (manual confirmation)
  ├─ automatic payments ........... needs Payoneer Checkout or Stripe
  └─ plan limits and add-ons gate Phases 6–9 features (Entitlements service ready)

Phase 6 AI foundation ............ needs LLM vendor + DPA/BAA
  └─ Phase 7 AI products (chatbot, copilot, call summaries)
       └─ uses Phase 2 knowledge base and rules, Phase 3b transcripts

Phase 8 Growth (D33–D36)
  ├─ G-1 social foundation ........ done; each network live after its app review (docs/social.md)
  ├─ G-2 AI content studio ........ needs Anthropic key; starts Phase 6 (AiService)
  │     └─ SEO posts, month plans, blog articles for G-4
  ├─ G-3 connect a website ........ ready to build
  ├─ G-4 SureHelp Sites ........... needs sites domain + Cloudflare; custom domains need hosting move
  └─ G-5 analytics in Results ..... after G-1/G-3 and provider approvals

Phase 10 Mobile .................. needs Apple/Google developer accounts + Firebase key
  └─ generated OpenAPI spec (deferred from P1-6)

Hosting move (D6) ................ unlocks real-time updates (Reverb) and a long-running worker (Horizon)
```

**Suggested order:**
1. Deploy calendar sync and billing.
2. Fill in the Payoneer settings and start invoicing.
3. Register the Google and Microsoft apps, and submit Google verification.
4. Start A2P 10DLC.
5. Submit Meta App Review and LinkedIn API access, and get an Anthropic API key (Growth, D33–D34).
6. While approvals come through, build what isn't blocked: G-1 social foundation, G-3 connect a website, Phase 4 email.
