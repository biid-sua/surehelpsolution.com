# Reminders & win-back (D116)

**Reminders & win-back** (portal menu; feature `campaigns`) brings past customers back with service
reminders and one-off campaigns.

## Service reminders

- Per service: **Remind after** N months (1, 2, 3, 6, 9, 12, 18, 24 or 36) and an optional message.
  Placeholders: `{first_name}`, `{business}`, `{service}`, `{phone}`.
- `marketing:reminders` runs hourly. For each completed job of that service whose start was N
  months ago (with a week's grace), it sends the reminder once (`service_reminders`, one row per
  job), unless the customer has booked again since.

## Campaigns

- Group: everyone, or customers not back in N months (had a completed job, nothing booked since),
  optionally only those who had a service or have a tag. The page counts the group and how many can
  get texts.
- Text and/or email; placeholders as above. Starting a campaign records its recipients
  (`campaign_recipients`) and queues `SendCampaign`, which sends 200 at a time and waits for the
  morning during the quiet hours.
- Results: reached, and booked within 30 days.

## The rules (`App\Services\Marketing\Outreach`)

| | Text | Email |
|---|---|---|
| Needs | `sms_consent`, a US mobile, texting on, not opted out by STOP | a valid address |
| Always | "Reply STOP to opt out" | an unsubscribe link (signed `/unsubscribe/{customer}`) |
| Never | after `marketing_opt_out_at`, to archived customers, from demo businesses, between 8 PM and 9 AM local | same |

Unsubscribing stops reminders and campaigns only; appointment confirmations and reminders still go.

Tests: `tests/Feature/OutreachTest.php`.
