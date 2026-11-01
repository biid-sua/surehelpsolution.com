# Compliance (D117)

**Compliance** (portal menu, `organization.view`) shows HIPAA mode and the business's consent records.

## HIPAA mode

Turned on by an owner who agrees to the Business Associate Agreement (`/business-associate-agreement`)
for the business: `organizations.hipaa_mode`, `baa_accepted_at`, `baa_accepted_by_user_id`, and a
`legal_acceptances` row with document `baa`. Audited as `compliance.hipaa_enabled` / `_disabled`.

| What | In HIPAA mode | Where |
|---|---|---|
| AI receptionist, AI messaging assistant | Off (`FeatureAccess::allows` says no) | `Hipaa::AI_FEATURES` |
| Agent AI help | Off | `AgentAssist::blocker()` |
| Call transcription | Not started | `CallRouter::incoming()` |
| AI call summaries | Not made | `VoiceCalls::transcriptFinished()` |
| Alerts with patient details by email, text, push | Replaced by `HipaaActivityNotice` ("new activity"), at most every 10 minutes per channel; the in-app bell keeps the full alert | `App\Listeners\KeepPatientDetailsInApp`, `Hipaa::SENSITIVE_EVENTS` |
| Opening a customer's record | Audited as `customers.viewed` | `Client\Customers\Show::mount()` |

Billing, training and system alerts carry no patient details and go as usual.

## Consent

- Counts: agreed to texts, texted STOP (`sms_opt_outs`), unsubscribed from reminders and offers
  (`marketing_opt_out_at`), and whether business texting is on.
- **Download consent records**: `/app/compliance/consent.csv` (`customers.view`, audited as
  `compliance.consent_exported`): name, phone, email, texts allowed and since when, how they
  agreed, when they texted STOP, email allowed, when they unsubscribed.

Tests: `tests/Feature/ComplianceTest.php`.
