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
2. **Plans.** On *Admin › Billing › Plans*, create your plans: name, monthly or yearly price, free-trial days and, optionally, feature keys. A plan that's switched off can't be chosen for new subscriptions; existing subscribers keep it.
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

## Later: automatic payments

`App\Services\Billing\Gateways\PaymentGateway` is the seam. When an automated checkout becomes available, a new gateway can return a hosted-checkout URL per invoice and confirm payments from a webhook through the same `RecordPayment` action. Candidates are Payoneer Checkout (it currently needs a Hong Kong entity and about $20k a month in volume), Stripe or Paddle. Nothing else changes: invoices, numbers, reminders and receipts stay as they are.

## Usage, add-ons and limits (D30)

- **Calls included and extra calls.** On a plan, set *Calls included* (per billing period) and *Price per extra call*. Spam calls never count. Each period's usage is recorded once when it ends, and extra calls are added to the next invoice (or to a final invoice when a subscription ends). Clients see a meter on *Billing* and get an alert at 80% and 100%. Calls are always answered.
- **Add-ons.** Create them under *Admin › Billing › Add-ons*: name, monthly price, and a feature key (fixed once created). Owners turn them on from *Billing*. The rest of the current period is invoiced straight away, pro rata (free during a trial). After that, each renewal invoice includes it. Turning one off takes effect at the end of the period. A new price applies only to businesses that turn the add-on on afterwards.
- **Limits.** *Team members* caps the people who can sign in (members plus open invitations); *Connected calendars* caps calendar connections. Leave blank for unlimited.
- **Feature access (D46).** *Admin › Billing › Feature access* sets each paid feature (`calendar_sync`, `ai_assistant`, `social_publishing`, `website_tools`, `customer_emails`) to "every business" (default) or "only plans / add-ons that include it". Staff can override per business on its admin page. In code, use `App\Services\Billing\FeatureAccess::allows()` (it applies the override, the mode, then the plan and add-ons) and the `feature:` route middleware.
