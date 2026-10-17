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

## Billing

- The plan's free trial starts at once and nothing is charged during it.
- When the trial ends, the first invoice is issued as usual. With card payments switched on (docs/billing.md), the owner can save a card under Billing to pay it automatically.

## Settings (.env)

| Setting | Default | Meaning |
|---|---|---|
| `SIGNUP_ENABLED` | `true` | `false` shows "Sign-ups are closed" with a link to the contact form |
| `SIGNUP_PER_HOUR` | `5` | sign-up attempts allowed per IP address per hour |

## Spam protection

- The form has a hidden field that people don't see. Bots that fill it get a normal-looking "check your email" answer, but nothing is created.
- Attempts are limited per IP address. Each email address can have only one account.

## Website plan names

The website's pricing cards link to `/start?plan=essential`, `professional`, `growth` and `enterprise`. To preselect the matching plan, give plans in Admin › Plans those slugs. If no plan matches, the first public plan is selected.
