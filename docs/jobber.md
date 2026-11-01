# Jobber (D111)

Businesses connect their Jobber account under **Business › Connected apps**, next to QuickBooks
([quickbooks.md](quickbooks.md)) and Housecall Pro ([housecall-pro.md](housecall-pro.md)). All use the same tables and the shared `App\Services\Apps\AppRecords`.

## What it does

| Direction | What | When |
|---|---|---|
| SureHelp → Jobber | Each booking SureHelp makes, as a *request* from the client, titled "Service · Fri, Oct 16, 2:00 PM (booked by SureHelp)" | When the booking is made |
| SureHelp → Jobber | The client (found by email, phone or name when nothing says it's someone else; otherwise added) | With their first booking |
| SureHelp → Jobber | Every new customer (off by default) | When the customer is added |
| Jobber → SureHelp | Clients with a phone or email, matched by phone or email, source `jobber` | Every hour, and right after connecting |

- Not sent: bookings copied in from a connected calendar (`calendar`), imports, and bookings that
  came from Jobber.
- **Moved or cancelled later?** The team gets a high-priority task ("Ana Lopez's booking moved to
  Sat, Oct 17, 3:00 PM: change it in Jobber too"), once per change, and it shows in *Recent activity*.
- A customer with no name goes to Jobber with their company or phone number as the name.
- Archived Jobber clients and clients with no phone or email stay out of SureHelp.

## Set up (once, by SureHelp staff)

1. In the Jobber Developer Center (developer.getjobber.com), create an app with read and write
   access to clients and requests.
2. Set its OAuth callback URL to the one shown under *Admin › Connections › Business apps*
   (`/app/apps/jobber/callback`).
3. Paste the Client ID and secret there. Until Jobber publishes the app, only the developer's own
   test account can connect.

## How it works

- `App\Services\Apps\Jobber\Jobber`: connecting, matching, sending and bringing in.
- `JobberClient` with two drivers: `GraphqlJobberClient` (Jobber's GraphQL API, with the
  `X-JOBBER-GRAPHQL-VERSION` header from `JOBBER_API_VERSION`) and `FakeJobberClient` (in memory,
  used by tests: `JOBBER_DRIVER=fake`).
- `Webhooks::dispatch` queues `SyncToJobber` for `appointment.booked` and `customer.created`.
  `apps:jobber-pull` runs hourly and queues `PullJobber`.
- Jobber's errors: lost access (401, or a refused refresh) shows **Connect again**; `THROTTLED` is
  retried by the queue; anything else Jobber refuses is written in *Recent activity*.
- Disconnecting calls Jobber's `appDisconnect`. Permission: `integrations.manage`. Feature: `jobber`.

Tests: `tests/Feature/JobberTest.php`.
