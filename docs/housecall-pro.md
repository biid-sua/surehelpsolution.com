# Housecall Pro (D112)

Businesses connect Housecall Pro under **Business › Connected apps** by pasting an API key, next to
QuickBooks ([quickbooks.md](quickbooks.md)) and Jobber ([jobber.md](jobber.md)). Nothing needs
setting up by SureHelp staff.

## What it does

| Direction | What | When |
|---|---|---|
| SureHelp → Housecall Pro | Each booking SureHelp makes, as a scheduled job at the customer's address, with the service as a line item and the tag "SureHelp" | When the booking is made |
| SureHelp → Housecall Pro | The customer (found by email, phone or name when nothing says it's someone else; otherwise added, with Housecall Pro's notifications off) | With their first booking |
| SureHelp → Housecall Pro | Every new customer (off by default) | When the customer is added |
| Housecall Pro → SureHelp | Customers with a phone or email, matched by phone or email, source `housecall_pro` | Every hour, and right after connecting |

- The job's address is the booking's address, else the customer's. A booking at a new address adds it
  to the customer in Housecall Pro. A booking with no address is written in *Recent activity* as
  not sent.
- The line item carries the service's price when it's fixed or "starting from", otherwise $0.
- Not sent: bookings copied in from a calendar, imports, and bookings that came from Housecall Pro.
  Moves and cancellations aren't sent yet.

## For the business

1. In Housecall Pro, open **App Store › API** and generate a key (the API is part of the MAX plan).
2. In SureHelp, paste it under **Business › Connected apps › Housecall Pro**.

The key is checked with Housecall Pro before it's saved, kept encrypted, and never shown again.

## How it works

- `App\Services\Apps\HousecallPro\HousecallPro`, with `HousecallProClient`: `RestHousecallProClient`
  (`Authorization: Token <key>`, base URL `HOUSECALL_PRO_BASE_URL`) and `FakeHousecallProClient`
  (`HOUSECALL_PRO_DRIVER=fake` in tests).
- `Webhooks::dispatch` queues `SyncToHousecallPro` for `appointment.booked` and `customer.created`.
  `apps:housecall-pro-pull` runs hourly and reads up to `HOUSECALL_PRO_PULL_LIMIT` customers.
- A 401 or 403 marks the connection as needing a new key; 429 is retried by the queue.
- Permission: `integrations.manage`. Feature: `housecall_pro`.

Tests: `tests/Feature/HousecallProTest.php`.
