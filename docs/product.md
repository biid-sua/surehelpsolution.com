# Product

A one-page summary of what Sure Help is and who it's for. The full requirements are in
[SureHelpSolution_Enterprise_Task.md](../SureHelpSolution_Enterprise_Task.md) (the master specification) and
[task.md](../task.md). Every product decision taken since, with its reason, is in [decisions.md](decisions.md).
What's built and what's left: [project-status.md](project-status.md).

## The problem

A solo owner or small service business (plumbing, HVAC, dental, legal, salons, and similar) in the USA
needs someone to answer every call, talk to customers, book appointments, follow up on leads and keep
the business organised, without hiring a full-time employee. Every missed call is a lost job.

## What Sure Help is

**A business operating platform with real people behind it.** Live receptionists answer calls 24/7 in
the business's name. Around that sits the software a small business runs on, in one place.

| Area | What's included |
|---|---|
| **Human services** | Call answering, appointment booking, customer intake, call notes, follow-ups, escalations |
| **Business software** | Dashboard, calendar with Google and Microsoft sync, customers (CRM) with timelines, tasks, results and monthly reports |
| **Messaging** | One inbox for website chat, text messages, Messenger, Instagram and email, with missed-call text-back |
| **Automation and AI** | Automation flows, AI replies and drafts, agent assist, call summaries, a website sales chat |
| **Growth** | Google reviews and review requests, satisfaction surveys, social publishing, website tools, an SEO service, referrals |
| **Money** | Subscriptions and invoices for Sure Help; deposits and pay-by-text for the business's own customers (Stripe Connect) |
| **Business setup** | Team and permissions, white-label branding, several locations, English and Spanish for customers, API keys and webhooks |

## Who uses it

| User | Where | What they do |
|---|---|---|
| **Business owner** | Business portal (`/app`) and the mobile app | Sees calls, bookings and messages; sets up the business, team and billing |
| **Business staff / manager** | Business portal | Work the calendar, customers, tasks and inbox, within their permissions |
| **Sure Help agent** | Agent workspace (`/agent`) | Answers calls for assigned businesses using each business's script and rules; books, notes and escalates |
| **Agent supervisor** | Agent workspace and admin | Assignments, schedules, call quality, training (Agent University) |
| **Platform admin** | Admin console (`/admin`) | Businesses, users, billing, enquiries, appearance, system status, demo data |

## Principles

1. **One platform, not a pile of tools.** Every feature shares the same customers, calendar and timeline.
2. **Each business sees only its own data** (multi-tenancy), and every action is checked against permissions.
3. **Never break what works:** the mobile app's API stays backwards compatible (D7).
4. **Plain language everywhere**, for owners who aren't technical.
5. **Live in 48 hours:** no new phone system for the business.
6. **Safe by default:** audit trail, two-step sign-in for staff, privacy tools, nothing sent without consent.

## Not built yet, and why

These are blocked on outside accounts or approvals, not on code; see [project-status.md §2](project-status.md):
live calls and texts (Twilio and US carrier registration), live calendar sync (Google and Microsoft
verification), AI going live (Anthropic key), Meta, LinkedIn and TikTok publishing (app reviews), automatic
card payments (Stripe or Payoneer), and the store release of the mobile app.
