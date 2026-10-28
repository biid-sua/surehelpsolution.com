# AI credits (D100)

Every AI feature comes with a monthly allowance. **AI credits** pay for use beyond it. For now that's the
website builder (D99): once a business has used its monthly website allowance, further changes use
credits.

## For businesses

- **Billing › AI credits** shows the balance, any credits waiting for an invoice to be paid, and the
  history (bought, given by SureHelp, used).
- **Buying:** choose a pack. We issue an invoice straight away; the credits are added as soon as it's
  paid (by card, automatic payment, or a payment SureHelp records). A business needs a plan to buy.
- **Using:** a website change uses the monthly allowance first. Anything beyond it comes off the
  balance, rounded up to whole credits. One credit is 1,000 AI tokens, so a change usually costs 2 to 5.
- Credits don't expire and aren't refunded in cash; staff can take back credits bought by mistake.

## For SureHelp staff

**Admin › Billing › AI credits:**
- **Packs on sale:** credits and price for each (up to 6). The defaults are 100 for $10, 500 for $40
  and 2,000 for $120.
- **Give or take back credits:** a gift, a goodwill credit or a refund, with a note the business sees.
  The balance can't go below zero.
- **Waiting for payment** and **Recent credit activity** across all businesses, with this month's
  credits sold, used and still held.

Voiding a credit invoice cancels the purchase. Paying it twice is impossible; the credits are added once.

## How it works

- `credit_purchases`: one row per pack bought; `pending` → `granted` (invoice paid) or `cancelled`
  (invoice voided). See [state-machines.md](state-machines.md).
- `credit_entries`: every change to a balance (`purchase`, `grant`, `usage`). The balance is the sum.
- `App\Services\Billing\AiCredits` does the work; `RecordPayment` and `VoidInvoice` call it.
- `SiteEditor` lets a change start when the allowance left plus credits covers it, and charges credits
  for the tokens beyond what was left of the allowance when the change started.

## Settings (.env)

| Setting | Default | Meaning |
|---|---|---|
| `CREDITS_TOKENS_PER_CREDIT` | `1000` | AI tokens one credit buys |
