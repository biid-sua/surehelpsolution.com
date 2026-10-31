# SureHelp for Zapier (D113)

The official SureHelp app for Zapier: SureHelp events start Zaps, and Zaps can add leads, customers
and tasks in SureHelp. It uses the business API (`/api/v1/client`) with a business's own API key.

## What's in it

- **14 triggers**, one per SureHelp event: appointment booked, moved, completed, no-show and
  cancelled; call answered and call missed; new conversation; new customer or lead; urgent matter
  raised; task overdue; new Google review; survey answered; customer payment received. Each is a
  REST hook: turning a Zap on adds a webhook address in SureHelp, turning it off removes it.
- **Actions**: Add Lead (gets the instant reply, D109), Add Customer (an existing customer with the
  same phone is handed back instead of failing) and Add Task.
- **Search**: Find Customer by phone or email.

## Develop and test

```
cd integrations/zapier
npm install
npm test            # Zapier's schema check plus the app's own tests
```

## Publish (once, by SureHelp staff)

1. `npm install -g zapier-platform-cli`, then `zapier login` with the SureHelp Zapier account.
2. `zapier register "SureHelp"` the first time (it writes `.zapierapprc`, which stays out of git).
3. `zapier push` uploads this version. Invite testers with `zapier users:add`.
4. To try it against staging: `zapier env:set 1.0.0 SUREHELP_BASE_URL=https://staging.example.com`.
5. When it works end to end, submit it for review in the Zapier developer platform to make it public.

The server side is `App\Http\Controllers\Api\ZapierController` (`/api/v1/client/zapier/…`), tested by
`tests/Feature/ZapierTest.php`. See [docs/zapier.md](../../docs/zapier.md).
