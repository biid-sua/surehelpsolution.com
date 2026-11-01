# Zapier app (D113)

SureHelp has its own Zapier app (code in [integrations/zapier](../integrations/zapier/README.md)). A
business connects it with an API key from **Business › API & webhooks**; nothing else is set up.

## What businesses can do with it

- Start a Zap from any SureHelp event: bookings (made, moved, completed, no-show, cancelled), calls
  (answered, missed), new conversations, new customers or leads, escalations, overdue tasks, Google
  reviews, survey answers and customer payments.
- Add a lead from any form or lead source; it gets the instant text, email and owner call (D109).
- Add a customer or a task, and find a customer by phone or email.

## Server endpoints (`/api/v1/client/zapier`, API key or app token)

| Endpoint | What it does | Permission |
|---|---|---|
| `GET /me` | Checks the key; Zapier labels the connection with the business name | any |
| `POST /hooks` `{target_url, event}` | A Zap was turned on: adds a webhook address marked `source = zapier` (max 50) | `integrations.manage` |
| `DELETE /hooks/{id}` | The Zap was turned off: removes it (only addresses Zapier added) | `integrations.manage` |
| `GET /sample?event=` | The newest real example of the event for the Zap editor, or a made-up one | `integrations.manage` |
| `POST /leads` `{name, phone, email, message, source}` | Adds a lead through `LeadIntake` | `customers.create`, a key that can write |

Zapier's addresses go through the same checks as any webhook (https only, no private networks) and
show under *API & webhooks* as "Added by a Zap". Events reach Zapier exactly as other webhooks do,
signed. In the Zap, each event has the event's own `id` and the record's id as `record_id`.

Tests: `tests/Feature/ZapierTest.php` (server) and `npm test` in `integrations/zapier` (the app).
