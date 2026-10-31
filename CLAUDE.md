# Sure Help: guide for Claude

Sure Help is a multi-tenant Laravel 12 + Livewire 3 platform for small US service businesses: live
receptionists answer their calls 24/7, and the software runs their calendar, customers, inbox,
automations, billing and growth tools. This file is loaded in every session; keep it short and point to
the docs rather than repeating them.

## The docs (read the one you need before changing that area)

| Need | File |
|---|---|
| What we're building and for whom | [docs/product.md](docs/product.md), with full detail in [SureHelpSolution_Enterprise_Task.md](SureHelpSolution_Enterprise_Task.md) and [task.md](task.md) |
| Why things are the way they are (D1, D2, …) | [docs/decisions.md](docs/decisions.md) |
| Statuses and allowed changes | [docs/state-machines.md](docs/state-machines.md) |
| Design tokens, components, screen rules | [docs/ui.md](docs/ui.md) |
| Versions and infrastructure | [docs/stack.md](docs/stack.md) |
| Tables, columns, keys | [docs/schema.md](docs/schema.md) (generated, never edit by hand) |
| What's done, what's next | [docs/project-status.md](docs/project-status.md), [docs/implementation-plan.md](docs/implementation-plan.md) |
| Permissions, security, testing, deploy | [docs/permissions.md](docs/permissions.md), [docs/security.md](docs/security.md), [docs/testing.md](docs/testing.md), [docs/deployment.md](docs/deployment.md) |
| A feature (telephony, inbox, billing, SEO, social, hosted sites, leads, QuickBooks, Jobber, Housecall Pro, Zapier…) | Its own page in [docs/](docs/) |

## Before every push

```
vendor/bin/pint --test                          # style (fix with vendor/bin/pint)
vendor/bin/phpstan analyse --memory-limit=1G    # no new baseline entries
php artisan test                                # SQLite in memory, about 2 minutes
```

CI runs the same plus `composer audit` and a MySQL 8 migration cycle. Don't push red.

## Rules that must not be broken

**Tenancy and access**
- Business data carries `organization_id` and uses `BelongsToOrganization`. Look records up inside the
  business (`forOrganization`) and answer 404, not 403, for another business's IDs.
- Business-portal Livewire components use `ScopedToOrganization`; admin ones use `PlatformAdminOnly`.
  Livewire update requests skip route middleware, so authorize in `mount()` **and** in every action.
- Every new endpoint or screen gets a feature test, including a cross-tenant test.

**Data and behaviour**
- Change statuses only through their action or service, and keep [state-machines.md](docs/state-machines.md) in step.
- Never edit an existing migration; add a new one that works on MySQL/MariaDB and SQLite. Then run
  `php artisan docs:schema` (a test fails if [schema.md](docs/schema.md) is stale).
- Money is integer cents (`*_cents`). Phone numbers are stored in E.164 (`App\Support\Phone`). Times
  are stored in UTC and shown in the business's timezone (`StoresUtc`).
- Record important changes in the audit log (`App\Support\Audit\Audit`).

**Outside services**
- Each one (Twilio, Stripe, Google, Microsoft, Meta, AI…) sits behind its own service or provider class
  with a `fake` driver for tests. AI goes through `AiProvider` only.
- Jobs that call an outside service must skip demo businesses (`App\Support\DemoGuard`, D94).
- Never send email or texts without the person's consent rules being checked.

**Mobile app (D7)**
- `/api/v1` is used by the live Flutter app: only additive changes. `ApiContractTest` guards the shapes.

**Front end**
- Portal screens use the `x-ui.*` components and design tokens; no hard-coded colours and no CDNs.
- Content-Security-Policy is enforced (D67): inline scripts need `nonce="{{ Vite::cspNonce() }}"`, and
  buttons use `data-call` instead of `onclick`.

## Writing

- Docs, UI text and commit messages are plain English for non-technical owners: short sentences, no
  jargon, US spelling in the product.
- A significant decision gets the next D-number in [decisions.md](docs/decisions.md), with **Decision**
  and **Why**. Update [project-status.md](docs/project-status.md) when something ships.
- Comments explain why, not what; match the density of the surrounding code.

## Git

- Work on a branch and open a pull request into `develop`; never push to `develop` directly.
- Commit messages: a short summary line, then what changed and why.
