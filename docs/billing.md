# Billing with Payoneer

SureHelp sends its own invoices. Clients pay them through **Payoneer**, by card or from a US bank account (ACH), or by bank transfer to your Payoneer receiving account. A person records each payment in the admin console once it lands. Why this design: decision D22 in [decisions.md](decisions.md).

## How money moves

```
billing:run (daily 06:05)        client                          you (Admin › Billing)
───────────────────────          ──────                          ─────────────────────
issues invoice ── email + PDF ──► opens Billing page
                                 pays on Payoneer link ────────► Payoneer emails you "payment received"
                                 (or bank transfer)
                                 clicks "I've paid" (optional) ─► "Reported paid" counter + email
                                                                 Record payment (amount, method, Payoneer ID)
                                 ◄── receipt email ──────────────
```

## One-time setup (about 15 minutes)

1. **Payment settings.** Go to *Admin › Billing › Payment settings* and fill in:
   - **Company name, address, billing email, tax ID**: printed on every invoice.
   - **Payoneer account email**: shown to clients as a fallback ("send to …").
   - **Default Payoneer payment link**: in Payoneer, open *Receive › Request a payment* and create a reusable link with no fixed amount (if your account offers that), then paste it here. Every invoice without its own link uses it.
   - **Bank transfer details**: copy them from Payoneer *Receive › Receiving accounts*. Usually that's the USD account (ACH routing and account number), plus EUR/GBP if you bill in those. They are printed on invoices and the client's billing page.
   - **Days to pay**: 7 by default.
2. **Plans.** On *Admin › Billing › Plans*, create your plans: name, monthly or yearly price, free-trial days, what's included and the features it unlocks. The same plans appear on the website's pricing section, at sign-up and on clients' *Billing* page (D98).
   - **Website extras:** a "Best for" line, a yearly price per month for the Annual toggle, a "Most popular" badge, the order, and *Price on request* for plans like Enterprise (shown as "Custom", not offered at sign-up).
   - **Retiring:** untick *Available* and *Shown to clients*. Existing subscribers keep the plan; nobody new can choose it.
   - **Deleting:** possible only for a plan nobody has ever been on. The *Delete* button appears only then.
3. **Subscribe businesses.** On *Admin › Billing › Subscriptions*, pick a business, a plan and a start date, then choose whether it gets a free trial.
   - Without a trial, the first invoice is issued and emailed right away.
   - After that, `billing:run` invoices each renewal on the same day of the month. The 31st becomes the last day in shorter months.

## Day to day

- **Needs attention** (the default invoice filter) lists overdue invoices, invoices a client marked as paid and invoices with no way to pay.
- **Exact-amount links (optional).** For a fixed-amount Payoneer link on one invoice:
  1. In Payoneer, open *Request a payment* and enter the invoice total with the invoice number as the reference.
  2. Paste the link into that invoice in *Admin › Billing*.

  It replaces the default link for that invoice, both in the email and on the client's page.
- **Recording a payment.** Open the invoice and choose *Record payment*. Enter the amount, the method (card via Payoneer, bank via Payoneer, Payoneer balance or direct bank transfer), the date received and the Payoneer transaction ID.
  - The same transaction ID can't be recorded twice.
  - A payment can't exceed what's owed.
  - Partial payments are allowed; the invoice is marked paid when nothing is left.
  - The client gets a receipt, and a past-due subscription becomes active again.
- **Overdue.** The day after the due date the subscription becomes *past due*. The client then gets up to three reminders, a week apart. Nothing is switched off automatically: you decide.
- **Plan changes** take effect at the next renewal, so nobody is charged twice. During a trial they take effect immediately. When a client asks to switch, you get an email.
- **Cancelling** ends the plan at the end of the paid period; *Resume* undoes that. You can also end it immediately.
- **Voiding** works for unpaid invoices only, and needs a reason (kept in the audit log).
- **One-off invoices** are for setup fees and extra work.

Each invoice has a printable page and a PDF (attached to the email). Numbers run `INV-2026-0001`, `INV-2026-0002`, … and restart each year.

## Who sees what

| Role | Can do |
|---|---|
| Business owner | Billing page, invoices and PDFs, "I've paid", ask to switch plan |
| Business manager | Billing page, invoices and PDFs (view only) |
| Staff, agents | Nothing in billing |
| Super Admin | *Admin › Billing*: record payments, void, plans, subscriptions, settings |
| Operations Manager, Support Agent | *Admin › Billing*, view only |

Billing emails (*New invoice*, *Payment received*, *Payment overdue*) go only to people who can see billing, and each person can switch them off under *Notifications*.

## Deploying this release

```
composer install --no-dev -o      # new: barryvdh/laravel-dompdf (invoice PDFs)
php artisan migrate --force       # plans, subscriptions, invoices, payments, platform settings
npm ci && npm run build
php artisan optimize
```

The cron entry for `schedule:run` already in place runs `billing:run` daily. To test it by hand, run `php artisan billing:run`; it's safe to run more than once a day.

## Card payments and automatic payments (Stripe, D64)

Built and switched off. Payoneer stays the provider (`BILLING_GATEWAY=payoneer`) until you have a Stripe account, which needs a US company or another country Stripe supports.

### What changes when Stripe is on
- **Paying an invoice:** on *Billing*, owners see **Pay by card**. It opens Stripe's own secure checkout page, so card numbers never touch SureHelp.
- **Saving the card:** **Pay and save card for automatic payments** keeps the card at Stripe. Every new invoice is then charged to it when it's issued.
  - The owner sees the card ("Visa •••• 4242 · 08/28") and can **Turn off** automatic payments at any time; the card is removed from Stripe.
- **Recording payments:** payments are booked automatically when Stripe confirms them, with the method "Card (Stripe)" and Stripe's payment ID as the reference.
  - The receipt email and the in-app notice go out as before.
  - Nobody records these payments in *Admin › Billing*.
- **A declined automatic charge:** the invoice stays open with the normal reminders.
  - The owner gets "Automatic payment didn't go through", with the bank's reason.
  - The Billing page shows the reason next to the Pay button.
  - The charge isn't retried automatically.
- **Other ways to pay:** bank transfer and "I've paid" remain for people who don't pay by card. Admins can still record any payment by hand.
- **Managers** can see billing but can't pay. That's the owner's job, as before.

### Turning it on
1. Create a Stripe account and activate it (business details, bank account for payouts).
2. **Developers › API keys:** copy the **secret key** (`sk_live_…`).
3. **Developers › Webhooks › Add endpoint:**
   - URL: `https://YOUR-DOMAIN/api/webhooks/stripe`.
   - Events: `checkout.session.completed`, `checkout.session.async_payment_succeeded`, `payment_intent.succeeded`, `payment_intent.payment_failed`.
   - Copy the endpoint's **signing secret** (`whsec_…`).
4. In `.env`, set the following, then run `php artisan config:cache`:
   ```
   BILLING_GATEWAY=stripe
   STRIPE_SECRET=sk_live_...
   STRIPE_WEBHOOK_SECRET=whsec_...
   ```
5. Run `php artisan migrate` (Stripe customer, saved card and charge-attempt fields).
6. Test with Stripe's test keys first (`sk_test_…`, card 4242 4242 4242 4242), then switch to the live keys.

### Notes
- **Refunds** are made in the Stripe dashboard. Record a credit or adjust the invoice in *Admin › Billing*, because a refund doesn't change SureHelp's records by itself.
- **Duplicate events:** Stripe may send an event more than once. Each event is handled once, and a payment is booked only once (its Stripe ID is the reference).
- **No overpayment:** a payment larger than what's left on the invoice is booked only up to the balance, and the rest is reported to the error log for a refund.

## Usage, add-ons and limits (D30)

- **Calls included and extra calls.** On a plan, set *Calls included* (per billing period) and *Price per extra call*. Spam calls never count. Each period's usage is recorded once when it ends, and extra calls are added to the next invoice (or to a final invoice when a subscription ends). Clients see a meter on *Billing* and get an alert at 80% and 100%. Calls are always answered.
- **AI credits.** Businesses buy packs under *Billing*; each purchase is an invoice, and the credits arrive when it's paid. Packs and hand-given credits are under *Admin › Billing › AI credits* ([ai-credits.md](ai-credits.md), D100).
- **Add-ons.** Create them under *Admin › Billing › Add-ons*: name, monthly price, and a feature key (fixed once created). Owners turn them on from *Billing*. The rest of the current period is invoiced straight away, pro rata (free during a trial). After that, each renewal invoice includes it. Turning one off takes effect at the end of the period. A new price applies only to businesses that turn the add-on on afterwards.
- **Limits.** *Team members* caps the people who can sign in (members plus open invitations); *Connected calendars* caps calendar connections. Leave blank for unlimited.
- **Feature access (D46).** *Admin › Billing › Feature access* sets each paid feature (`calendar_sync`, `ai_assistant`, `social_publishing`, `website_tools`, `customer_emails`) to "every business" (default) or "only plans / add-ons that include it". Staff can override per business on its admin page. In code, use `App\Services\Billing\FeatureAccess::allows()` (it applies the override, the mode, then the plan and add-ons) and the `feature:` route middleware.
