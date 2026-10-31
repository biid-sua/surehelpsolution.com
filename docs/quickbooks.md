# QuickBooks Online (D110)

Businesses connect their QuickBooks Online company under **Business › Connected apps**. It's the
first connected app; Jobber ([jobber.md](jobber.md)) follows the same pattern, and Housecall Pro and
ServiceTitan can too. Matching, sending once and tokens live in `App\Services\Apps\AppRecords`.

## What it does

| Direction | What | When |
|---|---|---|
| SureHelp → QuickBooks | Deposits and pay-by-text payments (D85), as a *Payment* from the customer, left unapplied | When Stripe confirms the payment |
| SureHelp → QuickBooks | The customer who paid (matched by email, or by name when nothing says it's someone else; otherwise added) | With their first payment |
| SureHelp → QuickBooks | Every new customer (off by default) | When the customer is added |
| QuickBooks → SureHelp | Customers with a phone or email, matched by phone or email, source `quickbooks` | Every hour, and right after connecting |

- SureHelp never changes or deletes anything in QuickBooks, and only fills in blanks in SureHelp.
- Customers brought in from QuickBooks don't start automations and don't count as new leads in
  Results.
- A name already used in QuickBooks by someone else gets the last four digits of the phone, for
  example "Dee Moss (0170)".
- A payment refunded through Stripe is **voided** in QuickBooks (it stays there at $0, marked void, with
  a note), once. Refunds of payments that never went to QuickBooks are left alone.

## Set up (once, by SureHelp staff)

1. At developer.intuit.com, create an app with the **Accounting** scope.
2. Under *Keys & credentials*, add the redirect URI shown under *Admin › Connections › Business apps*
   (`/app/apps/quickbooks/callback`).
3. Paste the Client ID and secret there. Choose "Sample companies" with the Development keys to try
   it with Intuit's sandbox companies, and "Real companies" with the Production keys to go live.
   Intuit reviews the app before production keys work for other companies.

## How it works

- `App\Services\Apps\QuickBooks\QuickBooks`: connecting, matching, sending and bringing in.
- `QuickBooksClient` with two drivers: `IntuitClient` (the real API, minor version 75) and
  `FakeQuickBooksClient` (in memory, used by tests: `QUICKBOOKS_DRIVER=fake`).
- Events reach it through `Webhooks::dispatch` (`payment.paid`, `customer.created`), which queues
  `SyncToQuickBooks`. `apps:quickbooks-pull` runs hourly and queues `PullQuickBooks`.
- Tables: `app_connections` (one per business and app, tokens encrypted), `app_links` (which
  SureHelp record is which QuickBooks record) and `app_sync_entries` (the page's *Recent activity*).
- Access tokens last an hour and are refreshed as needed. If QuickBooks stops accepting us, the
  connection shows **Connect again**; nothing is lost, and new payments wait for the next connection.
- Demo businesses never reach QuickBooks (D94). Permission: `integrations.manage`. Feature: `quickbooks`.

Tests: `tests/Feature/QuickBooksTest.php`.
