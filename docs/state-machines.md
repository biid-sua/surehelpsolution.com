# State machines

Every status in the app, what it means and which changes are allowed. The code is the source of truth;
each section names the file that enforces the rules. When you change a flow, update this page in the
same commit.

**Notation:** `a → b` means the code allows moving from `a` to `b`. "Terminal" means nothing leaves it.

## Business (organization)

`App\Enums\OrganizationStatus`, rules in `App\Actions\Organizations\ChangeOrganizationStatus::TRANSITIONS`.

| From | Allowed to |
|---|---|
| `onboarding` | `active`, `paused`, `cancelled` |
| `active` | `paused`, `cancelled` |
| `paused` | `active`, `cancelled` |
| `cancelled` | `active` |

- Pausing or cancelling needs a reason, and the owner sees it.
- A business whose owner closed the account (`closed_at` set, data deleted) can never be reactivated.
- Every change is audited (`organization.status_changed`) and the owner is told.
- `is_demo` businesses (D94) follow the same rules but are removed with the demo data.

## Appointment

`App\Enums\AppointmentStatus::transitions()`, applied by `App\Actions\Appointments\ChangeAppointmentStatus`.

| From | Allowed to |
|---|---|
| `pending` (needs confirming) | `confirmed`, `cancelled` |
| `tentative` | `confirmed`, `cancelled` |
| `confirmed` | `completed`, `no_show`, `cancelled` |
| `completed` | `confirmed` (undo) |
| `no_show` | `confirmed` (undo) |
| `cancelled` | terminal |

- `pending`, `tentative` and `confirmed` block the time slot (`blocksTime()`); the others free it.
- Marking `completed` can start automations (for example a satisfaction survey, D84).

## Task

`App\Enums\TaskStatus`, changed by `App\Actions\Tasks\ChangeTaskStatus`.

- `open`, `in_progress`, `completed` (shown as "Done"), `cancelled`.
- Any status can move to any other; setting the same status again does nothing.
- `open` and `in_progress` count as open (`isOpen()`). Completing records who and when, and adds to the
  customer's timeline.

## Escalation

`App\Enums\EscalationStatus`, changed by `App\Actions\Escalations\AcknowledgeEscalation` and `ResolveEscalation`.

| From | Allowed to |
|---|---|
| `open` (shown as "Waiting") | `acknowledged`, `resolved` |
| `acknowledged` | `resolved` |
| `resolved` | terminal |

`open` and `acknowledged` are active; urgent ones are re-sent until acknowledged (`RemindUrgentEscalations`).

## Support request

`App\Enums\SupportTicketStatus`, changed in `App\Services\Support\SupportDesk`.

- `open`, `in_progress`, `waiting_customer` (the business sees "Waiting for you"), `resolved`, `closed`.
- **Staff reply:** moves to `waiting_customer`, or to whatever status the staff member picks.
- **Business reply:** moves to `in_progress` if someone is assigned, otherwise `open`. A business can't
  reply to a `closed` request; they open a new one.
- **Assigning someone** to an `open` request moves it to `in_progress`.
- **Staff can set any status.** `resolved` and `closed` stamp their time.

## Customer

`App\Enums\CustomerStatus`: `lead`, `prospect`, `customer`, `inactive`, `archived`.

- New customers from calls, chats and messages start as `lead` (`MatchOrCreateCustomer`).
- People with access change it freely (`UpdateCustomer`), and automations can set it (`set_status` action).
- There are no enforced transitions: it's a label for the team.

## Subscription

`App\Enums\SubscriptionStatus`, changed by `App\Actions\Billing\ManageSubscription`, `App\Services\Billing\BillingRun`
and `App\Actions\Billing\RecordPayment`.

| Change | When |
|---|---|
| (new) → `trialing` or `active` | On subscribe: `trialing` if the plan has a trial |
| `trialing` → `active` | Daily billing run, on the trial's end date |
| `active` → `past_due` | Daily billing run, when an invoice is overdue |
| `past_due` → `active` | When a payment clears the last overdue invoice |
| `trialing` → `cancelled` | Cancelling a trial ends it immediately |
| `trialing` → `cancelled` | Daily billing run, on the end date of a self-serve trial the owner didn't keep (`ended_reason = trial_ended`, D97) |
| `active` / `past_due` → `cancelled` | At the period end after "cancel at period end", or immediately when an admin chooses |

- "Cancel at period end" is a flag (`cancel_at_period_end`), not a status; `resume()` clears it.
- A self-serve trial (`confirm_by_trial_end`) only becomes `active` once the owner keeps the plan
  (`confirmed_at`, `App\Services\Billing\Trials::keep()`). While its last subscription ended `trial_ended`
  and nothing replaced it, the business is read-only (`Trials::ended()`).
- `cancelled` is terminal: a new subscription is created instead.

## Invoice

`App\Enums\InvoiceStatus`, changed by `IssueInvoice`, `RecordPayment` and `VoidInvoice` (`App\Actions\Billing`).

| From | Allowed to |
|---|---|
| (new) | `open`, or `paid` straight away for a zero total |
| `open` (shown as "Due") | `paid` (payments reach the total), `void` (only with nothing paid) |
| `paid` | terminal |
| `void` | terminal |

- A part-paid invoice stays `open` with `amount_paid_cents` recorded.
- The business "reporting" a payment (`ReportPayment`) only flags it for the team; it doesn't change the status.
- Numbers come from `invoice_sequences`, for example `INV-2026-0042` (`InvoiceNumbers`).

## Social post

`App\Enums\SocialPostStatus`, changed by `App\Actions\Social\SavePost`, `PostWorkflow` and `App\Services\Social\SocialPublishing`.

| Change | By |
|---|---|
| (save) → `draft`, `in_review` or `scheduled` | `SavePost`: a draft if not publishing yet; `in_review` if the owner must approve (D33); otherwise `scheduled` |
| `in_review` → `scheduled` | The owner approves |
| `in_review` → `draft` | The owner sends it back with a note |
| `draft` / `in_review` / `scheduled` → `cancelled` | Cancel |
| `scheduled` → `publishing` | The publishing queue picks it up when due |
| `publishing` → `published`, `partly_published` or `failed` | From the results of each network (targets below) |
| `failed` / `partly_published` → `scheduled` | Retry |

- Only `draft`, `in_review` and `scheduled` posts can be edited.
- Only `draft`, `cancelled` and `failed` posts can be deleted; published posts stay for the record.
- **Each network is a target** (`SocialPostTarget`): `pending` → `publishing` → `published` or `failed`
  (with timed retries back to `pending`), or `cancelled`.

## Phone call

`App\Models\VoiceCall` constants, changed in `App\Services\Telephony\CallRouter`, `VoiceCalls` and the voice webhooks.

- **Incoming:** `ringing` → `in_progress` when an agent answers; the first agent wins, as the update only
  succeeds while it's still `ringing`. Unanswered calls end `missed` or `voicemail`, or go to the
  business's own number as `transferred` (after hours, D61).
- **Answered:** `in_progress` → `completed`, or `transferred` (warm or cold transfer, D62).
- **Outgoing (agent):** `ringing` → `in_progress`, or `no_answer`.
- **Provider error:** `failed`.

## Payment request (pay-by-text, D85)

`App\Models\PaymentRequest`, changed in `App\Services\Payments\CustomerPayments`.

| From | Allowed to |
|---|---|
| `open` | `paid` (Stripe confirms the checkout), `cancelled` |
| `paid` | `refunded` (Stripe reports a refund) |
| `cancelled`, `refunded` | terminal |

## Agent assignment

`App\Models\AgentAssignment` constants, changed by `App\Actions\Assignments\AssignAgent`, `ChangeAssignment`
and `SweepAssignments`.

| From | Allowed to |
|---|---|
| (new) | `scheduled` (starts later) or `active` |
| `scheduled` | `active` (start time reached), `ended`, `revoked` |
| `active` | `suspended`, `ended`, `revoked` |
| `suspended` | `active` (resume, unless the end date has passed), `ended`, `revoked` |
| `ended`, `revoked` | terminal |

The sweep also moves any assignment past its end date to `ended`.

## Demo call with the SureHelp team (D96)

`contact_submissions.demo_status`, changed by `App\Services\Sales\DemoSlots::book()` and `App\Services\Sales\DemoFollowUp`.

| From | Allowed to |
|---|---|
| (none) | `booked` (picked in the website chat) |
| `booked` | `booked` again (moved by the visitor), `cancelled` (visitor or team), `attended`, `no_show` |
| `cancelled`, `no_show` | `booked` (the visitor picks a new time on their page) |
| `attended`, `no_show` | each other (the team corrects a mistake) |

- A `booked` demo whose time has passed shows as **Needs outcome** in *Website enquiries*.
- Marking `attended` or `no_show` sends one follow-up, only the first time.
- **Outcome** (`demo_outcome`) is separate: `trial`, `customer` or `not_interested`. It's set by the team, or
  automatically when a business owner signs up with the same email (`trial`, then `customer` once paying).

## Demo data (D94)

The `demo.state` platform setting, changed by `App\Services\Demo\DemoData`.

| From | Allowed to |
|---|---|
| `none` | `loading` |
| `loading` | `loaded`, `failed` |
| `failed` | `loading` (try again) |
| `loaded` | `removing` |
| `removing` | `none`, `failed_removal` |
| `failed_removal` | `removing` (try again) |

A `loading` or `removing` state older than 30 minutes is "stuck", and an admin can recover it from what's
actually in the database.
