# Estimates and invoices (D115)

**Estimates & invoices** (portal menu; feature `estimates`) lets a business quote and bill its own
customers. Card payments use the business's Stripe account from **Payments** (D85).

## The flow

1. **Write an estimate**: choose the customer, a title, lines (description, quantity, price), sales
   tax and "valid until". Save it as a draft.
2. **Send it** by text and/or email. The customer gets a link (`/d/{token}`) showing the lines and
   total in the business's look.
3. **The customer accepts** by typing their name, or **declines** with an optional reason. The team
   gets an "Estimate accepted or declined" alert.
4. **Turn it into an invoice** (same lines, due in 14 days), check it, and send it.
5. **The customer pays by card** from the link (when the business takes card payments), or the team
   **marks it paid** (cash, check, transfer, other).

Drafts and sent documents can still be edited; the link always shows the current version. Voiding
stops the link working for accepting or paying.

## Code

- `App\Models\JobDocument` (`job_documents`): `type` estimate|invoice, `number` EST-1001/INV-1001 per
  business, lines in `items`, totals in cents, tax in basis points.
- `App\Services\Documents\JobDocuments`: create, update, send, viewed, accept, decline, invoiceFrom,
  checkoutUrl (makes a `PaymentRequest` of kind `invoice`), paidOnline (called from
  `CustomerPayments::completed`), markPaid, void. Statuses: docs/state-machines.md.
- Portal: `App\Livewire\Client\Documents\Index` and `Editor` (`app.documents.*`); permissions
  `payments.view` to look, `payments.request` to write and send.
- Customer page: `App\Http\Controllers\DocumentController` (`documents.*`), throttled.
- Alert: `App\Notifications\EstimateAnswered` (`estimate.answered`).

Tests: `tests/Feature/EstimatesTest.php`.
