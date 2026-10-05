# Sure Help Solution --- Enterprise SaaS Product Development Blueprint

## Claude Code Master Task Specification --- Web Application v1.0

> **Project:** Sure Help Solution\
> **Product:** Human Virtual Assistant + Business Operations Platform\
> **Primary Users:** Solo business owners and small service businesses
> in the USA\
> **Primary Platform:** Web application first; mobile apps will consume
> the same API later\
> **Development Agent:** Claude Code\
> **Document Type:** Master implementation task / product requirements /
> architecture specification

------------------------------------------------------------------------

# 0. MASTER DIRECTIVE

You are Claude Code working as a senior enterprise software architect,
product engineer, UX engineer, security engineer, QA engineer, and
DevOps-minded implementation agent.

Your mission is to transform the existing Sure Help Solution web
application into a **production-grade, multi-tenant SaaS platform for
solo business owners and small service businesses**.

The existing project already contains functional Agent and Client
dashboard foundations. **Do not destroy working functionality.** First
inspect the existing codebase, database, routes, controllers, models,
views/components, APIs, authentication flow, migrations, assets, and
existing integrations.

This is not a simple UI redesign.

The target is a scalable business platform that starts with:

> **Human Virtual Receptionist + Call Management + Appointment
> Scheduling + Calendar Integration**

and evolves into:

> **CRM + Unified Inbox + AI Business Assistant + Website Chatbot +
> SMS/Email Automation + Reviews + Local SEO + Social Media + Billing +
> Service Marketplace + Analytics**

The architecture must therefore be designed so that future modules can
be added without rewriting the core system.

------------------------------------------------------------------------

# 1. NON-NEGOTIABLE ENGINEERING PRINCIPLES

## 1.1 Protect existing functionality

Before changing anything:

1.  Inspect the repository.
2.  Identify the current framework/version.
3.  Identify the database engine/version.
4.  Identify authentication and authorization.
5.  Identify current API architecture.
6.  Identify existing Agent Dashboard functionality.
7.  Identify existing Client Dashboard functionality.
8.  Identify existing notification implementation.
9.  Identify existing Firebase/push notification work if present.
10. Identify current mobile/API assumptions.
11. Identify existing migrations and database relationships.
12. Identify environment variables and third-party integrations.
13. Identify any technical debt that could affect the new architecture.

Do not blindly overwrite files.

Do not delete working functionality merely because it is implemented
differently from the desired architecture.

When an existing implementation is sound, extend it.

When an existing implementation is fragile, refactor it carefully with
backward compatibility where practical.

------------------------------------------------------------------------

# 2. PRODUCT VISION

Sure Help Solution is a **Business Operating Platform for solo business
owners**.

The core customer problem:

> A solo business owner needs someone to answer calls, communicate with
> customers, schedule appointments, follow up with leads, and keep the
> business organized without hiring a full-time employee.

Sure Help Solution provides:

### Human services

-   Human virtual receptionist
-   Call handling
-   Appointment scheduling
-   Customer intake
-   Call notes
-   Follow-ups
-   Escalations

### Software services

-   Business dashboard
-   CRM
-   Calendar
-   Customer timeline
-   Messaging
-   Notifications
-   Reports
-   Billing
-   Integrations

### Future AI services

-   AI website chatbot
-   AI customer assistant
-   AI receptionist
-   AI agent copilot
-   AI lead qualification
-   AI summaries
-   AI email/SMS assistant

### Future growth services

-   Local SEO
-   Google Business Profile management
-   Review management
-   Social media management
-   Content generation
-   Marketing automation

The product must feel like a **single operating system for a small
business**, not a collection of unrelated tools.

------------------------------------------------------------------------

# 3. TARGET USERS

## 3.1 Business Owner

Primary customer.

Needs:

-   See what happened today
-   See calls
-   See appointments
-   See leads
-   Manage customers
-   Configure services
-   Connect calendars
-   Configure business rules
-   Communicate with agents
-   Manage AI
-   Purchase add-ons
-   Manage billing
-   Review reports

------------------------------------------------------------------------

## 3.2 Business Staff / Manager

Optional organization user.

Needs limited access according to permissions.

------------------------------------------------------------------------

## 3.3 Human Agent

Internal Sure Help Solution employee/contractor.

Needs:

-   Assigned clients
-   Calls
-   Customers
-   Appointments
-   Client knowledge
-   Tasks
-   Follow-ups
-   Escalations
-   Messages
-   Agent performance

Agents should not see client billing or administrative controls unless
explicitly authorized.

------------------------------------------------------------------------

## 3.4 Agent Supervisor / Operations Manager

Needs:

-   Agent assignment
-   Agent availability
-   Performance
-   Escalations
-   Client coverage
-   Operational reports

------------------------------------------------------------------------

## 3.5 Platform Super Admin

Internal platform administrator.

Needs:

-   Clients
-   Agents
-   Organizations
-   Plans
-   Subscriptions
-   Usage
-   Billing
-   System health
-   Integrations
-   Audit logs
-   Support
-   Feature flags
-   Platform analytics

------------------------------------------------------------------------

# 4. MULTI-TENANT ARCHITECTURE

The application must be designed as a proper multi-tenant SaaS.

Conceptually:

``` text
Platform
│
├── Organization A
│   ├── Users
│   ├── Agents
│   ├── Customers
│   ├── Calls
│   ├── Appointments
│   ├── Services
│   ├── Calendar Connections
│   ├── Messages
│   ├── AI Knowledge
│   ├── Integrations
│   └── Subscription
│
├── Organization B
│   └── ...
│
└── Organization C
    └── ...
```

Every tenant-owned resource must be properly isolated.

Never trust an organization ID supplied by the browser.

Resolve the authenticated user's permitted organization context
server-side.

Implement authorization at:

-   route level
-   controller/service level
-   query level where appropriate
-   policy/permission level
-   API level

Cross-tenant data access must be impossible through normal application
paths.

------------------------------------------------------------------------

# 5. ROLES AND PERMISSIONS

Implement permission-based authorization rather than relying exclusively
on hard-coded role checks.

Suggested roles:

## Platform

-   Super Admin
-   Platform Admin
-   Operations Manager
-   Support Agent
-   Billing Admin

## Client organization

-   Business Owner
-   Business Manager
-   Staff

## Service operations

-   Agent
-   Agent Supervisor

Use granular permissions such as:

``` text
dashboard.view

organization.view
organization.update

users.view
users.create
users.update
users.delete

customers.view
customers.create
customers.update
customers.delete

calls.view
calls.create
calls.update
calls.delete
calls.recording.view

appointments.view
appointments.create
appointments.update
appointments.cancel

calendar.view
calendar.manage

messages.view
messages.send

tasks.view
tasks.create
tasks.update

knowledge_base.view
knowledge_base.manage

ai.view
ai.manage

integrations.view
integrations.manage

reports.view

billing.view
billing.manage

subscriptions.view
subscriptions.manage

marketing.view
marketing.manage

seo.view
seo.manage

reviews.view
reviews.manage

social.view
social.manage

settings.view
settings.manage
```

Create policies/guards/middleware appropriate for the current framework.

------------------------------------------------------------------------

# 6. PRODUCT INFORMATION ARCHITECTURE

## Client navigation

``` text
Dashboard

Inbox
  ├── All Messages
  ├── SMS
  ├── Email
  ├── Social
  └── AI Conversations

Calls
  ├── Call History
  ├── Recordings
  ├── Transcripts
  └── Follow-ups

Appointments
  ├── Calendar
  ├── Upcoming
  ├── Today
  └── Availability

Customers
  ├── All Customers
  ├── Leads
  ├── Customers
  └── Customer Timeline

Business
  ├── Business Profile
  ├── Business Hours
  ├── Services
  ├── Locations
  ├── Staff
  └── Business Rules

AI Assistant
  ├── Overview
  ├── Knowledge Base
  ├── Conversations
  ├── AI Rules
  └── Escalations

Growth
  ├── SEO
  ├── Reviews
  ├── Social Media
  └── Marketing

Reports

Integrations

Billing

Settings
```

## Agent navigation

``` text
Dashboard

My Clients

Calls
  ├── Active
  ├── History
  └── Follow-ups

Appointments

Customers

Messages

Tasks

Knowledge Base

Notifications

Performance

Profile
```

## Super Admin navigation

``` text
Dashboard

Organizations

Users

Agents

Assignments

Calls

Appointments

Customers

Services

Subscriptions

Billing

Usage

Analytics

AI

Integrations

Support

Audit Logs

System Settings

Feature Flags
```

------------------------------------------------------------------------

# 7. DESIGN SYSTEM AND UX DIRECTION

The existing UI uses:

-   dark navy/purple background
-   purple/blue gradients
-   rounded cards
-   compact dashboard layouts
-   modern SaaS styling
-   icon-based navigation
-   high-contrast cards
-   subtle borders and shadows

Preserve the visual identity, but elevate it to enterprise SaaS quality.

## One UI (product owner requirement, 2026-10-11)

SureHelp launches as a brand-new product. There is exactly **one version of
every screen**:

-   no "classic", "legacy" or "old" screens kept next to new ones
-   no links, redirects or toggles to a previous version
-   when a screen is rebuilt, the old one is deleted in the same change
-   every web page (business portal, agent workspace, admin console, sign-in
    and first-login pages) uses the shared design system and layout
-   the public website follows the same brand and moves onto the design
    system when it is next redesigned

The mobile API (§111) is not a UI: its endpoints stay stable for the apps.

## Design requirements

-   Responsive desktop/tablet/mobile web
-   WCAG-conscious contrast
-   Keyboard navigation
-   Focus states
-   Accessible forms
-   Clear empty states
-   Loading states
-   Skeleton loaders
-   Error states
-   Success states
-   Confirmation dialogs
-   Toast notifications
-   Inline validation
-   Consistent spacing
-   Consistent typography
-   Consistent buttons
-   Consistent modal behavior
-   Consistent data tables
-   Consistent pagination
-   Consistent filters

Do not use excessive animations.

Animations should support comprehension, not distract users.

------------------------------------------------------------------------

# 8. DASHBOARD REQUIREMENTS

## 8.1 Client dashboard

The dashboard must answer:

> "What is happening in my business right now?"

Include:

### KPI cards

-   Calls today
-   Appointments today
-   New leads
-   Service requests
-   Missed calls
-   Pending follow-ups

### Today's schedule

Show:

-   time
-   customer
-   service
-   status
-   assigned agent
-   location
-   quick actions

### Recent calls

Show:

-   caller
-   reason
-   outcome
-   agent
-   time
-   appointment created
-   escalation status

### Customer activity

Show:

-   new customers
-   returning customers
-   recent interactions

### Alerts

Examples:

-   Calendar disconnected
-   Payment failed
-   AI needs approval
-   Follow-up overdue
-   Missed call
-   Unanswered customer message

### Business performance

Use configurable time periods:

-   Today
-   This week
-   This month
-   Custom range

Do not fabricate data.

Every metric must have a real backend source.

------------------------------------------------------------------------

# 9. BUSINESS PROFILE

Create a complete business profile.

Fields:

-   business name
-   legal/business display name
-   logo
-   description
-   business type
-   industry
-   website
-   primary phone
-   email
-   address
-   city
-   state
-   ZIP
-   country
-   timezone
-   currency
-   service area
-   tax information if needed later

Support multiple locations in the data model even if the initial UI
exposes one location.

------------------------------------------------------------------------

# 10. BUSINESS HOURS

Support:

-   normal hours
-   closed days
-   split shifts
-   holidays
-   special hours
-   emergency availability
-   temporary closure

Example:

``` text
Monday
08:00 AM - 05:00 PM

Tuesday
08:00 AM - 05:00 PM

Saturday
09:00 AM - 01:00 PM

Sunday
Closed
```

Store timezone correctly.

Never assume server timezone equals business timezone.

------------------------------------------------------------------------

# 11. BUSINESS SERVICES

Important distinction:

### Business Services

What the client's company sells.

Example:

``` text
Water Heater Repair
Duration: 90 minutes
Starting price: $150
```

Fields:

-   service name
-   description
-   category
-   duration
-   buffer time
-   price
-   price type
-   location
-   availability
-   booking rules
-   required customer information
-   active/inactive
-   agent instructions

Pricing types:

-   fixed
-   starting from
-   quote required
-   hidden from customer

------------------------------------------------------------------------

# 12. CUSTOMER CRM

Build a real CRM foundation.

Customer fields:

-   first name
-   last name
-   phone
-   email
-   company
-   address
-   city
-   state
-   ZIP
-   source
-   status
-   tags
-   notes
-   preferred contact method
-   consent/communication preferences
-   created date
-   last activity

Statuses:

``` text
Lead
Prospect
Customer
Inactive
Archived
```

------------------------------------------------------------------------

# 13. CUSTOMER TIMELINE

Every important interaction should be represented as a timeline event.

Examples:

``` text
Incoming Call
Outgoing Call
Appointment Created
Appointment Updated
Appointment Cancelled
SMS Sent
Email Sent
Message Received
Note Added
Task Created
Task Completed
Review Request
AI Conversation
Agent Escalation
```

Example:

``` text
Oct 03, 2:15 PM
Incoming call

Sarah Williams
Reason:
Water heater repair

Outcome:
Appointment booked

Oct 03, 2:18 PM
Appointment created

Oct 03, 2:19 PM
Confirmation SMS sent
```

------------------------------------------------------------------------

# 14. CALL MANAGEMENT

Calls are a core product feature.

Call record fields should support:

-   call ID
-   organization
-   customer
-   agent
-   phone number
-   direction
-   start time
-   end time
-   duration
-   reason
-   outcome
-   status
-   notes
-   recording reference
-   transcript reference
-   summary
-   follow-up date
-   escalation
-   appointment reference

Call outcomes should be configurable.

Examples:

``` text
Appointment Booked
Callback Requested
Information Provided
No Response
Call Dropped
Escalated
Follow-up Required
Wrong Number
Other
```

------------------------------------------------------------------------

# 15. CALL LOG ENTRY

The existing Call Log Entry interface should be retained and improved.

Required UX:

1.  Select/create customer
2.  Capture caller details
3.  Capture reason
4.  Capture outcome
5.  Capture appointment if applicable
6.  Add notes
7.  Create follow-up if needed
8.  Save
9.  Trigger notifications
10. Update analytics

Avoid duplicate customer records.

Implement customer matching by:

-   normalized phone
-   email
-   existing customer ID

Allow the agent to confirm a match before merging.

------------------------------------------------------------------------

# 16. APPOINTMENT MANAGEMENT

Appointments must support:

-   create
-   update
-   reschedule
-   cancel
-   confirm
-   complete
-   no-show
-   pending
-   tentative

Fields:

-   customer
-   service
-   location
-   date
-   start time
-   end time
-   timezone
-   agent
-   notes
-   source
-   status
-   calendar event IDs
-   reminders

Never silently overwrite an external calendar event.

Handle conflicts gracefully.

------------------------------------------------------------------------

# 17. UNIFIED CALENDAR

Build an internal calendar abstraction.

Supported providers initially:

### Google Calendar

### Microsoft Outlook / Microsoft 365 Calendar

Architecture:

``` text
Calendar Provider Interface
        │
        ├── Google Calendar Adapter
        ├── Microsoft Calendar Adapter
        └── Internal Calendar Adapter
```

The application should not spread provider-specific logic throughout
controllers.

Use provider services/adapters.

Support:

-   connect
-   disconnect
-   list calendars
-   select working calendar
-   availability lookup
-   create event
-   update event
-   cancel event
-   synchronization
-   webhook/event notification handling where available
-   token refresh
-   error handling
-   reconnect flow

Store external IDs.

Example:

``` text
provider = google
external_calendar_id = ...
external_event_id = ...
```

------------------------------------------------------------------------

# 18. CALENDAR CONNECTION UX

Client should see:

``` text
Calendar Integrations

Google Calendar
Connected
Last synced: 2 minutes ago
[Manage] [Disconnect]

Microsoft Calendar
Not connected
[Connect]
```

Explain permissions before OAuth.

Do not request excessive permissions.

Store OAuth credentials securely.

Never expose access tokens to frontend JavaScript.

------------------------------------------------------------------------

# 19. AVAILABILITY ENGINE

Create a reusable availability service.

It must consider:

1.  Business hours
2.  Holidays
3.  Service duration
4.  Service buffer
5.  Internal appointments
6.  Connected Google Calendar
7.  Connected Microsoft Calendar
8.  Agent availability
9.  Location constraints
10. Booking rules

Return valid time slots.

Example:

``` text
Requested:
Friday

Available:
10:00 AM
11:30 AM
2:00 PM
4:00 PM
```

This same service must later be usable by:

-   Human agents
-   Website chatbot
-   AI assistant
-   API
-   mobile app

------------------------------------------------------------------------

# 20. AGENT WORKSPACE

The Agent Dashboard should be optimized for speed.

Agent needs immediate visibility into:

-   active calls
-   assigned clients
-   today's appointments
-   pending follow-ups
-   urgent escalations
-   unread messages
-   tasks

------------------------------------------------------------------------

# 21. AGENT CLIENT WORKSPACE

When an agent selects a client, show:

``` text
Business Profile
Business Hours
Services
Pricing Rules
Current Calendar
Agent Instructions
FAQs
Policies
Customer History
```

The agent should not have to search through multiple admin pages while
on a call.

------------------------------------------------------------------------

# 22. CLIENT KNOWLEDGE BASE

Create a structured knowledge base.

Content types:

-   FAQ
-   policy
-   service information
-   pricing guidance
-   operating procedure
-   agent instruction
-   emergency instruction
-   escalation rule

Each item should support:

-   title
-   content
-   category
-   active/inactive
-   visibility
-   priority
-   updated by
-   updated at

Future AI will use the same knowledge base.

------------------------------------------------------------------------

# 23. BUSINESS RULES

Create configurable rules.

Examples:

``` text
Do not schedule emergency appointments after 5 PM.

Always ask for property address.

Never provide final pricing for custom jobs.

Escalate refund requests.

Escalate legal complaints.

Do not schedule appointments outside service area.
```

Rules must be stored as data/configuration where practical.

------------------------------------------------------------------------

# 24. TASKS AND FOLLOW-UPS

Implement:

-   create task
-   assign task
-   due date
-   priority
-   status
-   customer
-   appointment
-   call
-   notes

Statuses:

``` text
Open
In Progress
Completed
Cancelled
Overdue
```

Support recurring tasks later.

------------------------------------------------------------------------

# 25. ESCALATIONS

Create a first-class escalation system.

Types:

-   urgent customer issue
-   refund request
-   complaint
-   emergency
-   pricing approval
-   owner decision
-   technical problem
-   AI uncertainty

Escalation record:

-   organization
-   customer
-   call/message
-   reason
-   priority
-   assigned person
-   status
-   created at
-   resolved at
-   resolution notes

------------------------------------------------------------------------

# 26. UNIFIED INBOX

Build an abstraction around conversations.

Channels:

-   internal messages
-   SMS
-   email
-   website chat
-   social channels later

Concept:

``` text
Conversation
  ├── Participants
  ├── Channel
  ├── Messages
  ├── Customer
  ├── Organization
  ├── Assigned agent
  ├── AI state
  └── Status
```

Future channels must be pluggable.

------------------------------------------------------------------------

# 27. NOTIFICATION SYSTEM

Create a centralized notification service.

Channels:

-   in-app
-   web
-   email
-   SMS
-   push

Events:

``` text
appointment.created
appointment.updated
appointment.cancelled
call.logged
call.missed
followup.created
followup.overdue
message.received
escalation.created
payment.failed
subscription.updated
integration.disconnected
ai.escalation
```

Use queued/background jobs for external notifications.

Never block the main HTTP request unnecessarily.

------------------------------------------------------------------------

# 28. BILLING AND SUBSCRIPTIONS

Design billing as a dedicated module.

Stripe should be treated as the initial payment provider.

Core entities:

``` text
plans
plan_features
subscriptions
subscription_items
payments
invoices
usage_records
payment_methods
```

Support:

-   monthly subscriptions
-   annual subscriptions
-   add-ons
-   upgrades
-   downgrades
-   cancellation
-   trial periods
-   coupons later
-   failed payments
-   invoices
-   payment history
-   usage tracking

Never store raw card information.

Use Stripe-hosted/payment-provider mechanisms for sensitive payment
details.

------------------------------------------------------------------------

# 29. BILLING UI

Client should see:

``` text
Current Plan

Virtual Assistant
$299/month

Next billing date
Nov 03, 2026

Add-ons
AI Assistant      $49
SMS Automation    $29

Total
$377/month
```

Sections:

-   Current plan
-   Available plans
-   Add-ons
-   Payment methods
-   Invoices
-   Payment history
-   Usage

Invoice actions:

-   View
-   Download PDF

------------------------------------------------------------------------

# 30. ADD-ON MARKETPLACE

Create an extensible service marketplace.

Initial categories:

### Communication

-   SMS Automation
-   Email Automation

### AI

-   Website AI Chatbot
-   AI Business Assistant
-   AI Agent Copilot
-   AI Receptionist

### Growth

-   Local SEO
-   Review Management
-   Social Media Management
-   Content Marketing

### Operations

-   Additional agent
-   Additional phone number
-   Additional location

Each add-on should have:

-   product ID
-   name
-   description
-   pricing
-   billing model
-   active status
-   feature flags
-   eligibility rules
-   setup workflow

Do not hard-code add-ons into the billing controller.

------------------------------------------------------------------------

# 31. FEATURE ENTITLEMENTS

Create a reusable entitlement/feature-access layer.

Example:

``` text
feature:
ai.website_chatbot

enabled:
true

plan:
Professional
```

The same system should support:

-   plan limits
-   add-ons
-   usage limits
-   trial features
-   beta features
-   feature flags

Frontend visibility must never be the only enforcement mechanism.

Backend must enforce entitlements.

------------------------------------------------------------------------

# 32. USAGE METERING

Support usage records for:

-   call minutes
-   SMS
-   AI conversations
-   AI tokens/cost
-   chatbot conversations
-   agent seats
-   phone numbers
-   locations

Usage should be auditable.

Example:

``` text
AI Conversations
76 / 100

SMS
180 / 500

Call Minutes
420 / 500
```

------------------------------------------------------------------------

# 33. AI ARCHITECTURE

Do not tightly couple AI to a single screen.

Create a provider abstraction.

Conceptually:

``` text
AI Service
   │
   ├── Provider Adapter
   ├── Prompt/Instruction Manager
   ├── Tool Registry
   ├── Knowledge Retrieval
   ├── Conversation Manager
   ├── Usage Meter
   └── Safety/Guardrails
```

AI must be able to call approved application tools.

Potential tools:

``` text
search_customer
get_business_info
search_knowledge
get_business_hours
get_services
check_availability
create_appointment
reschedule_appointment
cancel_appointment
create_customer
create_task
send_message
create_escalation
```

Never allow an AI model to directly manipulate the database.

AI must use controlled application services/tools.

------------------------------------------------------------------------

# 34. BUSINESS BRAIN

Create the conceptual foundation for a reusable Business Brain.

It combines:

``` text
Business Profile
+
Services
+
Business Hours
+
Policies
+
FAQs
+
Knowledge Base
+
Calendar Availability
+
Customer Context
+
Communication Rules
```

Human agents, AI assistants, chatbots, and future voice agents should be
able to consume this information through controlled services.

------------------------------------------------------------------------

# 35. AI WEBSITE CHATBOT

Future module, but architecture must support it.

Client activates:

``` text
Website AI Assistant
```

The platform generates a site integration.

The chatbot should be able to:

-   answer FAQs
-   explain services
-   capture leads
-   qualify leads
-   check availability
-   schedule appointments
-   collect customer details
-   escalate to human
-   hand off conversations

It should not make unsupported claims.

If confidence is insufficient:

``` text
I want to make sure we give you the correct information.
Let me connect you with a member of our team.
```

------------------------------------------------------------------------

# 36. AI AGENT COPILOT

Future module.

During or after a call, AI can suggest:

``` text
Detected intent:
Water Heater Repair

Suggested questions:
1. What type of water heater?
2. Is there active leaking?
3. What is the property address?

Available services:
Water Heater Repair

Available appointments:
Tomorrow 10 AM
Tomorrow 2 PM
```

The human agent remains in control.

------------------------------------------------------------------------

# 37. AI CALL SUMMARY

After a call, automatically create:

-   summary
-   reason
-   customer intent
-   outcome
-   appointment
-   follow-up
-   important details
-   escalation recommendation

Allow agent/client to edit the generated summary.

------------------------------------------------------------------------

# 38. AI SAFETY AND CONTROL

AI must:

-   stay within business knowledge
-   avoid inventing prices/policies
-   identify uncertainty
-   escalate sensitive requests
-   respect customer communication preferences
-   never expose internal instructions
-   never expose other customers' data
-   never bypass authorization
-   never directly execute unrestricted database operations
-   log tool calls
-   log important AI decisions
-   support disabling AI features

------------------------------------------------------------------------

# 39. LOCAL SEO MODULE --- FUTURE

Architecture should support:

-   SEO audit
-   website health
-   local SEO checklist
-   business profile optimization
-   keyword tracking
-   location pages
-   content suggestions
-   technical SEO recommendations
-   reporting

Do not claim search ranking improvements without real measurement.

------------------------------------------------------------------------

# 40. REVIEW MANAGEMENT --- FUTURE

Support:

-   review aggregation where APIs permit
-   review notifications
-   response suggestions
-   review request campaigns
-   rating trends
-   unresolved negative feedback alerts

Do not create fake reviews.

Do not automate deceptive review practices.

------------------------------------------------------------------------

# 41. SOCIAL MEDIA MODULE

_Expanded 2026-10-06 at the product owner's request. Decisions: D33–D36._

Businesses connect their own social accounts and plan, write, approve and
publish posts from SureHelp, immediately or on any future date.

Support provider adapters. Do not assume every social platform provides
identical API capabilities: each adapter declares what it supports
(text-only posts, images, video, carousels, links, first comment,
scheduling window, character limits, analytics).

## Channels

Launch channels (official APIs that allow publishing for customers'
accounts after app review):

-   Facebook Pages
-   Instagram professional accounts (Business / Creator)
-   LinkedIn Company Pages (and the owner's personal profile)
-   Google Business Profile posts (updates, offers, events)

Later channels, each behind its own adapter:

-   TikTok (posts stay private until TikTok's audit is passed)
-   YouTube Shorts
-   Pinterest
-   Threads
-   X (paid per post by X; offered only as a paid add-on)

## Features

-   connect / reconnect / disconnect each account with OAuth; tokens
    encrypted, never sent to the browser; lost access flagged and the
    owner alerted (same pattern as calendar sync)
-   one post, many channels: a shared draft with per-channel versions
    (text, media, link, hashtags, Google Business Profile button and
    offer/event fields), validated against each channel's rules before
    it can be scheduled
-   publish now, schedule for a date and time in the business's
    timezone, or add to a queue with recurring weekly slots
-   advance scheduling up to 12 months ahead; SureHelp runs its own
    publishing queue so every channel behaves the same
-   content calendar (month, week, list) with drag-to-reschedule,
    filters by channel and status, and planned campaigns
-   statuses: draft → in review → approved → scheduled → publishing →
    published / partly published / failed; failed channels retried
    with backoff, then the owner is told what to fix
-   approval workflow: owners can require approval for posts written by
    staff, by SureHelp's content team or by AI; one-tap approve from
    email and the mobile app
-   media library: images and video with alt text, cropping per channel
    aspect ratio, brand kit (logo, colours, fonts) and branded templates
    for offers, reviews, tips and before/after photos
-   evergreen posts that can be re-queued; duplicate-post protection
-   link tracking: UTM tags added automatically; links to the booking
    page and the business website
-   analytics where APIs permit: reach, impressions, engagement, clicks,
    followers; per post, per channel and in the monthly Results report,
    tied to website visits, calls and bookings where attribution exists
-   done-for-you service: SureHelp's content team can plan and write
    posts for a business (paid add-on), always subject to the owner's
    approval settings
-   audit log for every publish, edit, approval and deletion

## Rules

-   Nothing is published without a human decision: a schedule, an
    approval or "publish now". AI never publishes on its own.
-   Respect each platform's terms, rate limits and content policies.
-   No fake reviews, fake engagement, purchased followers or
    impersonation.
-   Reviews are only turned into posts with the business's choice and
    without the reviewer's surname unless public on the source.

------------------------------------------------------------------------

# 41A. AI CONTENT STUDIO

AI writes, the business decides. Uses the AI foundation (§33) and the
Business Brain (§34): services, prices, service area, hours, offers,
knowledge base and brand voice.

-   brand voice settings: tone, words to use and avoid, emoji and
    hashtag style, examples of posts the business likes
-   write a post from a prompt, a photo, a review, a service, an offer,
    a completed job (before/after photos uploaded by the team) or a
    seasonal idea for the industry
-   a month plan: a ready-to-review calendar of posts mixing tips,
    offers, reviews, seasonal reminders and behind-the-scenes
-   per-channel rewriting (length, format, hashtags, Google Business
    Profile call-to-action)
-   SEO built in: local keywords (service + town), Google Business
    Profile posts aligned with the business's categories and services,
    alt text for every image, short readable link text, hashtags that
    are relevant rather than generic
-   blog articles for the business's SureHelp site (§41B) with title,
    meta description, headings, internal links to service pages and
    FAQ structured data, repurposed into social posts
-   quality checks before approval: facts checked against the Business
    Brain (prices, hours, areas), no promises the business doesn't make,
    no medical/legal/financial claims, readability score, duplicate
    check against recent posts
-   AI usage metered per business and limited by plan (§28–32)
-   every AI draft is labelled as AI-written until a person edits or
    approves it

------------------------------------------------------------------------

# 41B. WEBSITES

Two ways in, one result: a website that brings in calls and bookings.

## Connect an existing website

-   verify ownership (meta tag or DNS TXT record)
-   one snippet adds SureHelp to the site: chat widget (§35), booking
    widget, click-to-call, lead form into the CRM
-   website health and SEO check: titles, meta descriptions, headings,
    broken links, mobile friendliness, page speed basics, structured
    data, name/address/phone consistency with the Business Profile;
    plain-language fixes, re-checked monthly
-   later: Google Search Console and Analytics connection for real
    search and traffic data (no ranking claims without measurement, §39)

## SureHelp Sites (hosted website from templates)

-   industry templates (plumbing, HVAC, electrical, cleaning, dental,
    salon, legal, …) filled automatically from the Business Brain:
    home, one page per service, service-area pages, about, reviews,
    FAQ from the knowledge base, contact, booking, blog
-   live preview generated in seconds during sign-up or a sales call
    ("here is your site"), publishable when the owner is happy
-   always in sync: hours, services, prices, holidays and vacation mode
    update the site automatically
-   simple editor: sections, text, photos, colours and fonts from the
    brand kit; no code; versions with preview and roll back
-   address on a free SureHelp subdomain, or the business's own domain
    with automatic HTTPS; step-by-step DNS help
-   SEO by default: LocalBusiness / Service / FAQ structured data,
    sitemap, robots, canonical URLs, Open Graph images, fast cached
    pages, image compression, Core Web Vitals in the green
-   accessibility to WCAG 2.2 AA; cookie consent only where tracking
    needs it; privacy-friendly visit counts
-   every form, chat and booking lands in the CRM and the customer
    timeline; calls from the site are counted in Results

------------------------------------------------------------------------

# 41C. GROWTH IDEAS BACKLOG

Ideas researched for a world-class growth suite, to be prioritised
after the modules above:

-   Google Business Profile management: hours, holidays and services
    synced from SureHelp; photos; Q&A; post performance
-   review-to-post: turn a 5-star review into a branded image post
-   job photos to post: the team snaps before/after photos on the
    mobile app; AI drafts the post for approval
-   industry campaign packs (e.g. spring AC tune-up, winter pipe
    protection) with posts, an offer, a landing page and email
-   link-in-bio page with booking, offers and reviews
-   "last-minute openings" posts suggested when the calendar has gaps
-   unified social inbox for comments and messages (Phase 9)
-   listings consistency across directories (name, address, phone)
-   competitor watch: their review counts and posting frequency
-   multi-location: one post, location-specific versions

------------------------------------------------------------------------

# 42. EMAIL AND SMS AUTOMATION

Create a reusable automation engine.

Example:

``` text
Trigger:
Appointment Created

↓

Wait:
0 minutes

↓

Send:
Confirmation SMS

↓

Wait:
24 hours before appointment

↓

Send:
Reminder

↓

Appointment Completed

↓

Wait:
2 hours

↓

Send:
Follow-up
```

Architecture should eventually support:

-   triggers
-   conditions
-   delays
-   actions
-   templates
-   cancellation rules
-   retry rules

------------------------------------------------------------------------

# 43. AUTOMATION ENGINE

Create the foundation now even if only simple workflows are initially
exposed.

Concept:

``` text
Workflow
 ├── Trigger
 ├── Conditions
 ├── Actions
 ├── Delays
 └── Execution history
```

Actions:

-   send email
-   send SMS
-   create task
-   notify user
-   create appointment
-   request review
-   create escalation

------------------------------------------------------------------------

# 44. REPORTING AND ANALYTICS

Reports should use real persisted data.

Client reports:

### Calls

-   total
-   answered
-   missed
-   duration
-   outcome
-   source

### Appointments

-   booked
-   completed
-   cancelled
-   no-show
-   conversion

### Leads

-   new
-   contacted
-   qualified
-   converted

### Agent activity

-   calls
-   appointments
-   follow-ups
-   escalations

### Communication

-   SMS
-   email
-   conversations

Provide:

-   date filters
-   charts
-   tables
-   export
-   CSV
-   PDF later

------------------------------------------------------------------------

# 45. ADMIN ANALYTICS

Platform metrics:

``` text
MRR
Active Organizations
New Organizations
Churn
Active Agents
Calls
Appointments
Usage
Add-on adoption
AI usage
Support volume
```

Do not expose sensitive tenant data to unauthorized platform users.

------------------------------------------------------------------------

# 46. SEARCH

Implement global search where practical.

Search entities:

-   customers
-   calls
-   appointments
-   messages
-   tasks
-   organizations

Use indexed database queries.

For large datasets, introduce a dedicated search service later.

------------------------------------------------------------------------

# 47. FILTERING AND DATA TABLES

All enterprise tables should support:

-   search
-   sorting
-   filters
-   pagination
-   column visibility where appropriate
-   date ranges
-   export
-   responsive behavior

Do not load thousands of records into the browser unnecessarily.

Use server-side pagination.

------------------------------------------------------------------------

# 48. API-FIRST ARCHITECTURE

The web frontend and future mobile apps must consume the same backend
APIs.

Structure:

``` text
                Backend API
                     │
          ┌──────────┼──────────┐
          │          │          │
        Web         iOS      Android
```

Do not duplicate business logic in web controllers and mobile APIs.

Prefer service/domain/application classes for core business operations.

------------------------------------------------------------------------

# 49. API STANDARDS

Use consistent API responses.

Example:

``` json
{
  "success": true,
  "data": {},
  "message": "Appointment created successfully"
}
```

Validation error:

``` json
{
  "success": false,
  "message": "Validation failed",
  "errors": {
    "start_time": [
      "The selected time is no longer available."
    ]
  }
}
```

Use appropriate HTTP status codes.

Version public APIs.

Initial version:

``` text
/api/v1
```

------------------------------------------------------------------------

# 50. API SECURITY

Implement:

-   authentication
-   authorization
-   rate limiting
-   request validation
-   CORS policy
-   CSRF protection where applicable
-   webhook signature validation
-   API token management
-   secure secret handling
-   audit logging
-   abuse prevention

Never trust client-side permissions.

------------------------------------------------------------------------

# 51. WEBHOOK ARCHITECTURE

External integrations will eventually send webhooks.

Create a consistent webhook handling pattern:

``` text
Webhook received
    ↓
Verify signature
    ↓
Store event
    ↓
Idempotency check
    ↓
Queue processing
    ↓
Process
    ↓
Record result
```

Webhook events must be idempotent.

Never process the same event twice accidentally.

------------------------------------------------------------------------

# 52. BACKGROUND JOBS

Use queues/background jobs for:

-   emails
-   SMS
-   notifications
-   webhook processing
-   calendar synchronization
-   AI processing
-   transcription
-   report generation
-   invoice synchronization
-   automation execution

The exact queue technology must match the existing project/framework.

------------------------------------------------------------------------

# 53. CACHING

Cache appropriate data:

-   business settings
-   permissions
-   service lists
-   feature entitlements
-   dashboard aggregates where useful

Do not cache highly dynamic data without a clear invalidation strategy.

------------------------------------------------------------------------

# 54. DATABASE DESIGN

Create normalized, maintainable relational tables.

At minimum consider:

``` text
users
organizations
organization_users
roles
permissions
role_permissions

agents
agent_assignments
agent_availability

business_profiles
business_locations
business_hours
business_holidays
business_services
service_categories
business_rules

customers
customer_tags
tags
customer_notes
customer_timeline_events

calls
call_outcomes
call_recordings
call_transcripts

appointments
appointment_statuses

calendar_connections
calendar_calendars
calendar_events

conversations
conversation_participants
messages

tasks
task_assignments

knowledge_base_categories
knowledge_base_items

escalations
notifications

plans
plan_features
subscriptions
subscription_items
payments
invoices
usage_records

integrations
integration_accounts

ai_conversations
ai_messages
ai_tool_calls
ai_usage

workflows
workflow_steps
workflow_executions

audit_logs
feature_flags
```

Do not blindly create every table immediately.

Implement according to the current phase, but maintain architectural
consistency.

------------------------------------------------------------------------

# 55. DATABASE RULES

-   Use foreign keys where appropriate.
-   Add indexes for frequently queried columns.
-   Use unique constraints intentionally.
-   Avoid duplicated business state.
-   Use timestamps consistently.
-   Consider soft deletion where appropriate.
-   Never use soft deletion where it creates security ambiguity.
-   Use transactions for multi-step critical operations.
-   Never rely on application-level uniqueness alone.
-   Avoid N+1 queries.
-   Use eager loading intentionally.

------------------------------------------------------------------------

# 56. DATA RETENTION

Plan for configurable retention of:

-   call recordings
-   transcripts
-   messages
-   audit logs
-   customer data

Do not permanently retain everything by default without a business
reason.

Provide deletion/export mechanisms where required.

------------------------------------------------------------------------

# 57. PRIVACY AND COMPLIANCE FOUNDATION

Because the platform may process US customer information, design with
privacy and security in mind.

Support:

-   privacy policy
-   terms of service
-   data processing documentation
-   consent records
-   communication preferences
-   data export
-   account deletion workflow
-   audit logs

For call recording/transcription, the product must support configurable
notices/consent behavior appropriate to applicable laws and customer
configuration. Do not hard-code a single legal assumption.

Do not present legal compliance as guaranteed by the software.

------------------------------------------------------------------------

# 58. SECURITY REQUIREMENTS

Implement or preserve:

-   password hashing using framework-standard secure mechanisms
-   secure sessions
-   CSRF protection
-   XSS prevention
-   SQL injection prevention
-   mass-assignment protection
-   authorization policies
-   secure file uploads
-   MIME/type validation
-   upload size limits
-   rate limiting
-   brute-force protection
-   secure headers
-   secret management
-   encrypted sensitive credentials
-   audit logging
-   2FA architecture
-   login/session history

Never log:

-   passwords
-   API secrets
-   access tokens
-   full payment credentials
-   sensitive authentication data

------------------------------------------------------------------------

# 59. FILE STORAGE

For uploaded:

-   logos
-   customer documents
-   call recordings
-   generated reports

use an abstraction rather than hard-coding local storage.

The system should be able to move from local development storage to
object storage/cloud storage later.

------------------------------------------------------------------------

# 60. ERROR HANDLING

Every important workflow needs:

### Loading state

### Success state

### Empty state

### Validation state

### Error state

### Retry option

Example:

``` text
Google Calendar connection failed.

We could not complete the connection.

[Try Again]
```

Do not show raw stack traces to customers.

Log technical details server-side.

------------------------------------------------------------------------

# 61. OBSERVABILITY

Prepare for enterprise operation.

Log:

-   authentication events
-   authorization failures
-   integration errors
-   webhook processing
-   background jobs
-   API failures
-   billing events
-   AI tool calls
-   important admin actions

Use structured logs where supported.

------------------------------------------------------------------------

# 62. AUDIT LOGS

Audit important actions:

``` text
Who
What
When
Organization
Entity
Entity ID
Old value
New value
IP/device metadata where appropriate
```

Example:

``` text
John changed appointment
Appointment: #APT-10291
Old time: 2:00 PM
New time: 4:00 PM
```

Do not expose internal audit metadata unnecessarily to normal users.

------------------------------------------------------------------------

# 63. TESTING STRATEGY

Do not consider a feature complete when it merely works manually.

For important business logic create:

### Unit tests

For:

-   availability
-   permissions
-   billing calculations
-   usage
-   appointment rules
-   tenant isolation
-   automation conditions

### Feature/integration tests

For:

-   authentication
-   appointment creation
-   calendar integration
-   customer creation
-   billing webhooks
-   notifications

### End-to-end tests

For critical journeys:

``` text
Client signup
→ onboarding
→ connect calendar
→ create service
→ agent logs call
→ appointment created
→ client notified
```

------------------------------------------------------------------------

# 64. CRITICAL SECURITY TESTS

Create tests proving:

### Tenant isolation

Organization A cannot access Organization B data.

### Permission isolation

Staff cannot access billing if they lack permission.

### Agent isolation

Agent cannot access unrelated client organizations.

### API authorization

Direct API requests cannot bypass UI restrictions.

### Webhook verification

Invalid webhook signatures are rejected.

### AI tool authorization

AI cannot execute tools outside the authenticated organization context.

------------------------------------------------------------------------

# 65. PERFORMANCE REQUIREMENTS

Target a fast SaaS experience.

Avoid:

-   huge dashboard queries
-   unbounded database queries
-   N+1 queries
-   loading entire tables
-   synchronous third-party API chains where avoidable

Use:

-   pagination
-   indexes
-   eager loading
-   caching
-   queues
-   background processing
-   optimized aggregate queries

Dashboard should remain usable even as a tenant has tens of thousands of
customer/call records.

------------------------------------------------------------------------

# 66. MOBILE-READY DESIGN

The web system must be mobile-app-ready.

Every business action should eventually be available through APIs.

Do not make mobile-specific database structures unless necessary.

Future apps:

``` text
Sure Help Client App
Sure Help Agent App
```

The mobile apps should use the same:

-   authentication
-   permissions
-   API
-   notifications
-   business logic
-   calendar services
-   billing status

------------------------------------------------------------------------

# 67. NOTIFICATION ARCHITECTURE FOR MOBILE

Design push notification events now.

Examples:

``` text
appointment.created
appointment.reminder
appointment.cancelled

new.call
missed.call

new.lead

new.message

escalation.created

payment.failed

ai.attention_required
```

Later these can map to Firebase/APNs/mobile push.

------------------------------------------------------------------------

# 68. ONBOARDING WIZARD

Create a polished onboarding flow.

## Step 1 --- Welcome

``` text
Welcome to Sure Help Solution
Let's get your business ready.
```

## Step 2 --- Business information

## Step 3 --- Business hours

## Step 4 --- Services

## Step 5 --- Calendar

``` text
Connect Google Calendar
Connect Microsoft Calendar
Skip for now
```

## Step 6 --- Agent preferences

## Step 7 --- Communication preferences

## Step 8 --- Choose services/add-ons

## Step 9 --- Review

## Step 10 --- Finish

Track onboarding completion.

Allow users to resume later.

------------------------------------------------------------------------

# 69. EMPTY STATES

Never show blank pages.

Example:

``` text
No appointments yet.

Once your agent schedules an appointment,
it will appear here.

[Configure Calendar]
```

Every empty state should explain:

1.  What is missing
2.  Why it matters
3.  What the user can do next

------------------------------------------------------------------------

# 70. SETTINGS

Client settings:

``` text
Profile
Security
Notifications
Business
Users
Roles
Calendar
Integrations
Communication
AI
Privacy
Billing
```

Agent settings:

``` text
Profile
Availability
Notifications
Security
```

------------------------------------------------------------------------

# 71. SECURITY SETTINGS

Support:

-   change password
-   2FA
-   active sessions
-   logout other sessions
-   login history
-   recovery methods

------------------------------------------------------------------------

# 72. EMAIL TEMPLATE SYSTEM

Create reusable templates.

Examples:

``` text
Appointment Confirmation
Appointment Reminder
Appointment Cancellation
Welcome Email
Password Reset
Payment Receipt
Payment Failed
Invoice Available
New Lead
Escalation
```

Templates should support variables:

``` text
{{customer_name}}
{{business_name}}
{{appointment_date}}
{{appointment_time}}
{{service_name}}
{{agent_name}}
```

Do not hard-code email HTML inside business logic.

------------------------------------------------------------------------

# 73. SMS TEMPLATE SYSTEM

Same approach.

Templates must support:

-   character awareness
-   opt-out handling
-   consent state
-   delivery status
-   failure state

------------------------------------------------------------------------

# 74. TIMEZONE HANDLING

This is mandatory.

Store timestamps in a consistent canonical format.

Store each organization's timezone.

Display dates/times according to the organization/user context.

Calendar integrations must preserve provider timezone information.

Never assume:

``` text
UTC == business timezone
```

------------------------------------------------------------------------

# 75. MONEY HANDLING

Never use floating-point arithmetic for financial values.

Use integer minor units or the framework's recommended monetary
representation.

Example:

``` text
$299.99
```

should not be stored as an imprecise binary float.

Currency must be explicit.

Initial market:

``` text
USD
```

Design for multiple currencies later.

------------------------------------------------------------------------

# 76. STATUS ENUMS

Avoid random strings scattered across code.

Centralize statuses or use appropriate domain enums/value objects.

Example:

``` text
AppointmentStatus:
pending
confirmed
completed
cancelled
no_show
```

Same for:

-   calls
-   tasks
-   subscriptions
-   messages
-   escalations

------------------------------------------------------------------------

# 77. FEATURE FLAGS

Create a feature flag mechanism.

Examples:

``` text
ai_assistant
website_chatbot
social_media
seo_module
review_management
sms_automation
advanced_reports
```

This lets the platform launch features gradually.

------------------------------------------------------------------------

# 78. SUPPORT SYSTEM

Prepare a basic support module.

Client can submit:

``` text
Subject
Category
Priority
Message
Attachment
```

Statuses:

``` text
Open
In Progress
Waiting for Customer
Resolved
Closed
```

Later this can become a full support center.

------------------------------------------------------------------------

# 79. SERVICE MARKETPLACE UX

Each service should have:

``` text
Service Name
Description
What You Get
Pricing
Setup Requirements
Estimated Setup Time
Activation Status

[Activate]
```

Activation should launch a setup wizard if needed.

Example:

``` text
Activate Website AI Chatbot

Step 1
Business knowledge

Step 2
Chatbot appearance

Step 3
Escalation settings

Step 4
Install code

Step 5
Test

Step 6
Activate
```

------------------------------------------------------------------------

# 80. INTEGRATION HUB

Create provider abstraction.

Possible integrations:

``` text
Google Calendar
Microsoft Calendar
Stripe
Email provider
SMS provider
Google Business Profile
Facebook
Instagram
Website Chat
Analytics
```

Each provider must have:

-   connect
-   status
-   configuration
-   health check
-   disconnect
-   reconnect
-   error handling

------------------------------------------------------------------------

# 81. INTEGRATION HEALTH

Show:

``` text
Google Calendar
Connected ✓

Last sync:
2 minutes ago

Status:
Healthy
```

If broken:

``` text
Google Calendar
Connection expired ⚠

Reconnect required.

[Reconnect]
```

------------------------------------------------------------------------

# 82. ADMIN SUPPORT / IMPERSONATION

If implementing impersonation, make it secure.

Requirements:

-   explicit permission
-   visible impersonation banner
-   audit log
-   session expiry
-   easy exit
-   never expose credentials
-   prevent privilege escalation

------------------------------------------------------------------------

# 83. API DOCUMENTATION

Maintain API documentation.

Document:

-   authentication
-   endpoints
-   request schema
-   response schema
-   validation
-   errors
-   permissions
-   webhooks

Use the project's appropriate API documentation standard.

------------------------------------------------------------------------

# 84. ENVIRONMENT MANAGEMENT

Support:

``` text
local
development
staging
production
```

Never commit secrets.

Use environment variables for:

-   database
-   mail
-   Stripe
-   OAuth
-   AI providers
-   SMS
-   storage
-   Firebase
-   other integrations

Provide `.env.example`.

------------------------------------------------------------------------

# 85. DEPLOYMENT READINESS

The application should eventually support:

``` text
Application
Database
Queue Worker
Scheduler/Cron
Object Storage
Cache
```

Do not assume a single web process is sufficient for production.

------------------------------------------------------------------------

# 86. CRON / SCHEDULER

Plan scheduled tasks for:

-   appointment reminders
-   overdue follow-ups
-   subscription checks
-   usage aggregation
-   calendar sync
-   workflow execution
-   cleanup
-   notifications

------------------------------------------------------------------------

# 87. IDEMPOTENCY

Critical operations must be safe to retry.

Especially:

-   appointment creation
-   payment webhook handling
-   calendar synchronization
-   notification sending
-   workflow execution

Use idempotency keys where appropriate.

------------------------------------------------------------------------

# 88. CONCURRENCY

Appointment booking must prevent double booking.

Example:

Two agents attempt:

``` text
Oct 10
2:00 PM
```

at the same time.

The system must ensure only one succeeds.

Use database transactions/locking or an equivalent safe concurrency
strategy.

------------------------------------------------------------------------

# 89. AUDITABLE EXTERNAL ACTIONS

When the system creates:

-   calendar event
-   payment
-   SMS
-   email
-   AI tool action

store enough metadata to understand what happened.

Do not store secrets.

------------------------------------------------------------------------

# 90. MODERN SEARCH AND FILTER UX

For customer/call/appointment pages provide:

``` text
Search...
Date
Status
Agent
Service
Outcome
Source
```

Filters should persist during navigation when appropriate.

------------------------------------------------------------------------

# 91. EXPORT

Client export:

-   customers CSV
-   calls CSV
-   appointments CSV
-   reports CSV

Future:

-   full organization data export

Exports should run asynchronously for large datasets.

------------------------------------------------------------------------

# 92. DATA IMPORT

Future-ready architecture should support:

-   customer CSV import
-   service import
-   calendar migration

Validate imports and show row-level errors.

------------------------------------------------------------------------

# 93. DATA QUALITY

Prevent:

-   duplicate customers
-   duplicate calendar events
-   duplicate notifications
-   duplicate webhook processing
-   orphaned records

Use constraints and idempotency mechanisms.

------------------------------------------------------------------------

# 94. USER EXPERIENCE RULE

Every screen should answer:

> What can I do here?

and:

> What should I do next?

Avoid overly technical terminology for business owners.

Use plain language.

Instead of:

``` text
OAuth Authorization
```

say:

``` text
Connect your Google Calendar
```

Instead of:

``` text
Webhook failure
```

say:

``` text
We couldn't sync your calendar.
Try reconnecting.
```

------------------------------------------------------------------------

# 95. DO NOT BUILD A "DEMO"

This must be a real application.

Do not:

-   hard-code dashboard numbers
-   fake API responses
-   use static appointment lists
-   use fake billing states
-   pretend an integration is connected
-   create fake AI responses as production behavior
-   hide broken functionality behind polished UI

If a feature is not implemented yet, clearly mark it as:

``` text
Coming Soon
```

or use a feature flag.

------------------------------------------------------------------------

# 96. DEVELOPMENT PHASES

## PHASE 0 --- Discovery and audit

Before writing code:

-   inspect project
-   inspect database
-   inspect routes
-   inspect models
-   inspect authentication
-   inspect APIs
-   inspect dashboard
-   inspect notifications
-   inspect existing integrations
-   document architecture
-   identify risks

Deliver:

``` text
/docs/architecture-audit.md
/docs/current-state.md
```

Do not make destructive changes during this phase.

------------------------------------------------------------------------

# 97. PHASE 1 --- Foundation

Implement:

-   organization/tenant architecture
-   roles
-   permissions
-   policies
-   navigation
-   shared UI components
-   audit logging foundation
-   notifications foundation
-   API structure
-   error handling
-   database conventions

Deliver production-quality foundations before advanced modules.

------------------------------------------------------------------------

# 98. PHASE 2 --- Core Business Operations

Implement:

-   business profile
-   locations
-   business hours
-   services
-   customers
-   customer timeline
-   calls
-   call outcomes
-   appointments
-   tasks
-   escalations
-   agent assignments

------------------------------------------------------------------------

# 99. PHASE 3 --- Calendar

Implement:

-   internal calendar
-   Google Calendar integration
-   Microsoft Calendar integration
-   unified availability
-   conflict prevention
-   synchronization
-   reconnect handling

------------------------------------------------------------------------

# 100. PHASE 4 --- Communication

Implement:

-   unified inbox architecture
-   email
-   SMS
-   templates
-   notifications
-   appointment reminders
-   follow-ups

Use provider abstraction so vendors can be changed later.

------------------------------------------------------------------------

# 101. PHASE 5 --- Billing

Implement:

-   plans
-   subscriptions
-   add-ons
-   Stripe integration
-   invoices
-   payment methods
-   usage
-   entitlement system
-   billing events/webhooks

------------------------------------------------------------------------

# 102. PHASE 6 --- AI FOUNDATION

Implement:

-   AI provider abstraction
-   Business Brain
-   knowledge base
-   AI conversations
-   tool registry
-   tool authorization
-   AI usage tracking
-   AI audit events
-   safety controls

------------------------------------------------------------------------

# 103. PHASE 7 --- AI PRODUCTS

Implement:

-   website chatbot
-   AI customer assistant
-   AI agent copilot
-   AI summaries
-   AI lead qualification
-   AI appointment scheduling
-   escalation

------------------------------------------------------------------------

# 104. PHASE 8 --- Growth Products

Implement:

-   social media publishing and content calendar (§41)
-   AI content studio with SEO (§41A, needs Phase 6)
-   websites: connect an existing site, SureHelp Sites from templates (§41B)
-   review management
-   Google Business Profile integration where available
-   local SEO
-   marketing automation

------------------------------------------------------------------------

# 105. PHASE 9 --- Advanced Communication

Implement:

-   social messaging
-   unified social inbox
-   AI social assistant
-   additional communication providers

------------------------------------------------------------------------

# 106. PHASE 10 --- Mobile

Only after the API/business logic is stable:

-   Client mobile app
-   Agent mobile app
-   push notifications
-   mobile calendar
-   mobile calls/messages
-   mobile dashboard

The mobile app must reuse the backend API rather than introducing a
separate business logic layer.

------------------------------------------------------------------------

# 107. CLAUDE CODE WORKFLOW

Claude Code must follow this development loop:

``` text
1. Inspect
2. Understand
3. Plan
4. Implement
5. Test
6. Review
7. Refactor
8. Document
```

Do not jump directly into large-scale implementation without inspecting
the current repository.

For each major feature:

### Before coding

Explain:

-   affected files
-   affected tables
-   API changes
-   risks
-   migration strategy
-   testing strategy

### During coding

Keep changes modular.

### After coding

Run relevant:

-   tests
-   linting
-   formatting
-   static analysis
-   database migration checks

Fix failures before moving on.

------------------------------------------------------------------------

# 108. FILE ORGANIZATION

Use the conventions of the existing framework.

Do not introduce unnecessary architectural patterns simply for the sake
of abstraction.

However, core business logic should not become a giant controller.

Prefer separation such as:

``` text
Controllers
Requests
Policies
Services
Actions
Repositories where genuinely useful
Models
DTOs/Value Objects where useful
Jobs
Events
Listeners
Notifications
Integrations
Providers
```

Use the project's established conventions when they are good.

------------------------------------------------------------------------

# 109. CONTROLLER RULE

Controllers should orchestrate.

They should not contain hundreds of lines of:

-   business rules
-   calendar logic
-   billing calculations
-   AI logic
-   notification logic

Move domain/application behavior into appropriate services/actions.

------------------------------------------------------------------------

# 110. DATABASE MIGRATION RULE

Every schema change must be represented by a migration.

Never modify production database structure manually as the primary
implementation method.

Migrations must be:

-   reversible where practical
-   ordered
-   documented when complex
-   safe for existing data

Before changing existing columns, inspect current data.

------------------------------------------------------------------------

# 111. BACKWARD COMPATIBILITY

If existing API endpoints are already consumed by the mobile app or other
clients (web screens are not covered: see "One UI" in §7):

-   preserve them where possible
-   introduce versioned replacements when needed
-   do not break existing clients silently

------------------------------------------------------------------------

# 112. DOCUMENTATION REQUIREMENTS

Maintain:

``` text
/docs/
  architecture.md
  current-state.md
  database.md
  api.md
  permissions.md
  integrations.md
  billing.md
  ai.md
  deployment.md
  testing.md
  roadmap.md
```

Documentation must match the actual implementation.

------------------------------------------------------------------------

# 113. DEFINITION OF DONE

A feature is complete only when:

-   backend implemented
-   database migration implemented
-   authorization implemented
-   validation implemented
-   UI implemented
-   loading state implemented
-   empty state implemented
-   error state implemented
-   success feedback implemented
-   audit logging added if appropriate
-   notifications added if appropriate
-   API endpoint implemented if needed
-   tests added
-   existing functionality verified
-   documentation updated

------------------------------------------------------------------------

# 114. MVP DEFINITION

The first production-ready release should include:

## Client

-   login
-   dashboard
-   business profile
-   business hours
-   services
-   customers
-   customer timeline
-   calls
-   appointments
-   calendar
-   Google Calendar
-   Microsoft Calendar
-   notifications
-   basic reports
-   billing
-   integrations
-   settings

## Agent

-   login
-   dashboard
-   assigned clients
-   client workspace
-   calls
-   customers
-   appointments
-   calendar
-   tasks
-   knowledge base
-   escalations
-   notifications
-   profile

## Admin

-   dashboard
-   organizations
-   agents
-   assignments
-   users
-   calls
-   appointments
-   subscriptions
-   billing
-   reports
-   audit logs

------------------------------------------------------------------------

# 115. FUTURE PRODUCT ROADMAP

After MVP:

``` text
AI Knowledge Base
        ↓
Website AI Chatbot
        ↓
AI Agent Copilot
        ↓
SMS Automation
        ↓
Email Automation
        ↓
Review Management
        ↓
Local SEO
        ↓
Social Media
        ↓
AI Receptionist
        ↓
AI Voice
        ↓
Mobile Apps
```

------------------------------------------------------------------------

# 116. PRODUCT QUALITY BAR

The application must feel comparable to a modern SaaS product.

Quality expectations:

-   fast
-   clean
-   responsive
-   reliable
-   accessible
-   secure
-   consistent
-   intuitive
-   scalable
-   auditable
-   maintainable

Do not optimize only for visual appearance.

The backend architecture is equally important.

------------------------------------------------------------------------

# 117. CLAUDE CODE AUTONOMY RULE

You are authorized to make reasonable implementation decisions when the
specification does not dictate a specific technology.

However:

### Do not invent business requirements.

If a decision materially affects:

-   billing
-   security
-   data retention
-   tenant isolation
-   external integrations
-   user permissions
-   destructive database changes

stop and document the decision before proceeding.

For normal engineering decisions, choose the most maintainable option
consistent with the existing project.

------------------------------------------------------------------------

# 118. PRIORITY ORDER

When requirements conflict, prioritize:

``` text
1. Security
2. Data integrity
3. Tenant isolation
4. Correct business behavior
5. Reliability
6. Maintainability
7. Performance
8. Accessibility
9. UX
10. Visual polish
```

------------------------------------------------------------------------

# 119. IMPORTANT UX RULE FOR ADD-ONS

Do not overwhelm a new customer with every future feature.

The default client experience should focus on:

``` text
Dashboard
Calls
Appointments
Customers
Calendar
Messages
```

Growth/AI features can be introduced progressively.

Use contextual recommendations such as:

``` text
You received 27 new leads this month.

Would you like to activate automated follow-up?

[Learn More]
```

Do not use aggressive upselling.

------------------------------------------------------------------------

# 120. FINAL PRODUCT MODEL

The complete future system should conceptually become:

``` text
                         SURE HELP SOLUTION
                                  │
              ┌───────────────────┼───────────────────┐
              │                   │                   │
          HUMAN SERVICE          AI                GROWTH
              │                   │                   │
       Virtual Agent       AI Assistant             SEO
       Call Handling       AI Chatbot               Reviews
       Scheduling          AI Receptionist          Social
       Follow-ups          AI Copilot               Marketing
              │                   │                   │
              └───────────────────┼───────────────────┘
                                  │
                           BUSINESS PLATFORM
                                  │
        ┌──────────────┬──────────┼──────────┬──────────────┐
        │              │          │          │              │
       CRM          Calendar    Inbox      Billing       Analytics
        │              │          │          │              │
        └──────────────┴──────────┼──────────┴──────────────┘
                                  │
                              API LAYER
                                  │
                    ┌─────────────┴─────────────┐
                    │                           │
                  WEB                         MOBILE
```

------------------------------------------------------------------------

# 121. IMMEDIATE COMMAND TO CLAUDE CODE

Start by doing **PHASE 0 only**.

Do not immediately rewrite the application.

Perform a complete repository audit.

Create:

``` text
/docs/architecture-audit.md
/docs/current-state.md
/docs/implementation-plan.md
```

The audit must identify:

1.  Framework and version
2.  PHP/runtime version if applicable
3.  Database engine/version if detectable
4.  Authentication system
5.  Authorization system
6.  Existing users/roles
7.  Existing organizations/tenants
8.  Existing Agent Dashboard
9.  Existing Client Dashboard
10. Existing API
11. Existing database schema
12. Existing notification system
13. Existing Firebase/push implementation
14. Existing calendar implementation
15. Existing billing implementation
16. Existing integrations
17. Existing routes
18. Existing controllers/services
19. Existing frontend architecture
20. Existing tests
21. Existing technical debt
22. Security risks
23. Performance risks
24. Recommended refactoring
25. Recommended implementation order

Then produce a proposed implementation plan.

**Do not make destructive changes during Phase 0.**

After the audit, stop and present the findings and proposed Phase 1
plan.

------------------------------------------------------------------------

# 122. FIRST PHASE SUCCESS CRITERIA

Phase 0 is successful when:

-   the repository is understood
-   existing functionality is mapped
-   database relationships are understood
-   authentication is understood
-   authorization gaps are identified
-   tenant architecture is understood
-   API architecture is understood
-   dashboard architecture is understood
-   existing notification behavior is understood
-   risks are documented
-   implementation plan is created
-   no existing production functionality has been unnecessarily
    destroyed

------------------------------------------------------------------------

# 123. FINAL INSTRUCTION

Build **Sure Help Solution** as a serious, scalable SaaS product.

Do not think of each page as an isolated webpage.

Think in terms of:

> **Organizations → Users → Permissions → Business Data → Workflows →
> Integrations → Automation → AI → Billing → Analytics**

Every major feature must fit into that ecosystem.

The goal is not simply to make the dashboard look impressive.

The goal is to create a reliable platform that can eventually support
**hundreds or thousands of small businesses, human agents, integrations,
subscriptions, AI services, and mobile clients without requiring a
fundamental rewrite.**

Start with the existing working application.

Understand it.

Protect it.

Then evolve it into the platform described above.

**Begin with Phase 0 repository audit.**
