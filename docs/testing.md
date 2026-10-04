# Testing

## Running locally

The deployed folder has no dev dependencies, so use a development checkout:

```
composer install          # includes PHPUnit, Pint, Larastan
php artisan test          # SQLite in-memory (phpunit.xml)
vendor/bin/pint --test    # code style (Laravel preset)
vendor/bin/phpstan analyse --memory-limit=1G   # Larastan level 5 + baseline
```

CI (`.github/workflows/ci.yml`) runs all of the above, plus `composer audit`, `npm audit`, and a migrate → rollback → migrate cycle on **MySQL 8**. MySQL catches index/foreign-key issues that SQLite does not.

## Suites

| File | Covers |
|---|---|
| `tests/Feature/DashboardFixesTest.php` | KPI/chart agreement, status labels, empty values, call-ID sequencing |
| `tests/Feature/HotfixesTest.php` | route access, login throttling, deactivated tokens, duty-schedule API, CSV export, no fake data |
| `tests/Feature/TenancyTest.php` | **tenant isolation** (web + API), agent assignment enforcement, provisioning, backfill rules, dry run |
| `tests/Feature/ClientPortalTest.php` | business portal: real KPIs, isolation on every page, filters, timezone-aware dates, export, calendar feed |
| `tests/Feature/AdminConsoleTest.php` | admin console access, ULID URLs, agent assignment add/remove, validation, support role read-only, review queue |
| `tests/Feature/AuditAndNotificationsTest.php` | audit entries (logins, calls, users, assignments), redaction, retention, audit page access; notification recipients, events, preferences, bell isolation, scheduler |
| `tests/Feature/ApiContractTest.php` | **frozen response shapes** of every existing `/api/v1` endpoint (mobile compatibility, D7) |
| `tests/Feature/ApiConventionsTest.php` | error envelope per status, no leaked internals, 429, security log, headers, CORS, device tokens |
| `tests/Feature/BusinessHoursTest.php` | opening-hours engine: split shifts, overnight, holidays, special hours, closure, **timezones and DST** |
| `tests/Feature/BusinessProfileTest.php` / `BusinessServicesTest.php` | business pages, owner/manager/staff rights, validation, isolation, money parsing, API |
| `tests/Feature/CustomersTest.php` | E.164 normalization, match/enrich rules, **DB-enforced dedupe**, restore, backfill + dry run, list/search, detail, isolation, export, API |
| `tests/Feature/PermissionsTest.php` | **permission isolation**: org roles, staff vs billing, agents vs unassigned orgs, platform roles, demotion |

## Rules

- Every new endpoint gets a feature test, including a cross-tenant test that expects 403/404 (spec §64).
- New code must pass Larastan with **no new baseline entries**. The baseline (`phpstan-baseline.neon`) only covers legacy controllers and shrinks as they are rewritten.
- Migrations that touch data are rehearsed on a MySQL/MariaDB copy with legacy-shaped data before release.

## Deploy rehearsal on MySQL/MariaDB

Tests run on SQLite, which accepts schema MySQL rejects and never runs migrations against old data. Before every release:

1. Load a snapshot of the database **as it is in production** (the last released state, with real-shaped data) into a throwaway database.
2. Run `DB_DATABASE=<throwaway> php artisan migrate --force` for every pending migration **in one run**, as the deploy will.
3. Check the backfilled rows, not just "DONE" (counts of customers, tasks, links).
4. `migrate:rollback --step=<n>` and `migrate` again: same data, no errors.

Two Phase 2 bugs were only found this way (2026-10-04): NOT NULL `timestamp` columns (MySQL gives them `ON UPDATE CURRENT_TIMESTAMP` or an invalid default, so use `dateTime`), and a backfill reading a table created by a later migration.
