# Self-serve sign-up (D65)

Businesses can create their own SureHelp account at **`/start`**. The website's "Start Free Trial" buttons and the sign-in page link there.

## What the business owner does

1. Picks a plan. Only plans that are active and public (Admin › Plans) are listed. `/start?plan=<slug>` preselects one.
2. Enters the business name, their name, email, an optional phone number and a password (at least 8 characters).
3. Accepts the Terms of Use, Privacy Policy and Data Processing Addendum.
4. Is signed in and asked to confirm their email. The portal and the mobile app open once they click the emailed link. "Send a new link" sends another.
5. After confirming, the setup wizard helps them fill in their business details.

## What SureHelp staff do

- Everyone who can manage businesses gets **New sign-up** (in-app and email) with a link to the business.
- The business is **onboarding**, and Admin › Organizations shows "Signed up online".
- Staff check the details, assign agents, connect the phone line and switch the business to active, exactly as for businesses they create.

## The free trial (D97)

- The plan's free trial starts at once and nothing is charged during it.
- **Sample data.** The portal starts with a few example customers, calls, bookings and follow-ups, so the owner sees what SureHelp does. Nobody is contacted. *Remove sample data* on the dashboard deletes them, and only them.
- **Calls.** Our receptionists answer up to 25 calls during the trial (once staff have connected the phone line). After that, calls go to the business's own number or voicemail until the owner keeps a plan.
- **Keeping the plan.** Under *Billing*, the owner picks *Keep this plan*, or another plan. The first invoice is issued when the trial ends. With card payments switched on (docs/billing.md), they can save a card to pay it automatically.
- **Reminders.** The portal shows how many days are left. Owners get an email 3 days and 1 day before the end.
- **If they don't keep a plan,** the trial ends on its last day and no invoice is issued. The account becomes read-only: they can still sign in, look at everything and download their data, but can't change anything, and our receptionists stop answering. Nothing is deleted. *Start this plan* under *Billing* switches everything back on, with the first invoice that day.
- Trials that staff set up for a business (Admin › Billing) aren't affected: they turn into a paid plan by themselves.

## Settings (.env)

| Setting | Default | Meaning |
|---|---|---|
| `SIGNUP_ENABLED` | `true` | `false` shows "Sign-ups are closed" with a link to the contact form |
| `SIGNUP_PER_HOUR` | `5` | sign-up attempts allowed per IP address per hour |
| `SIGNUP_TRIAL_CALLS` | `25` | calls our receptionists answer before the owner keeps a plan (empty: no limit) |
| `SIGNUP_SAMPLE_DATA` | `true` | `false` starts new trials without sample data |

## Spam protection

- The form has a hidden field that people don't see. Bots that fill it get a normal-looking "check your email" answer, but nothing is created.
- Attempts are limited per IP address. Each email address can have only one account.

## Website plan names

The website's pricing cards link to `/start?plan=essential`, `professional`, `growth` and `enterprise`. To preselect the matching plan, give plans in Admin › Plans those slugs. If no plan matches, the first public plan is selected.
