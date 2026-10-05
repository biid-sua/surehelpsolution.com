# Permissions

How authorization works. Source of truth: [config/authorization.php](../config/authorization.php). Decision record: [decisions.md](decisions.md) D9 / D9a.

## Rules

1. **Check permissions, never role names.** Use `$user->can('calls.view', $organization)`, the `can:calls.view` route middleware, or `@can` in Blade.
2. **Every permission is checked inside an organization.** Pass the `Organization`, or rely on the tenant context set by the `tenant` middleware. Without either, the check only answers whether the user holds the permission somewhere. Data access must still be checked against the specific organization.
3. **The browser never chooses the organization.** Clients get theirs from their membership. Agents can only act in organizations they are assigned to.

## Where permissions come from

| Source | Stored in | Applies to |
|---|---|---|
| Platform roles: `super_admin`, `operations_manager`, `support_agent` | Spatie `roles` | every organization |
| Service roles: `agent_supervisor`, `agent` | Spatie `roles` | only organizations in `agent_assignments` |
| Organization roles: `owner`, `manager`, `staff` | `organization_user.role` | that organization only |

All of it is evaluated in `App\Models\User::hasPermissionIn()`, registered as a `Gate::before` hook in `AppServiceProvider`. Spatie's own global Gate check is disabled (`config/permission.php`), because it would ignore agent assignments.

### Portal type vs. role
`users.role` (`admin` / `agent` / `client`) stays as the **portal type**: which dashboard you land on and which API group you can call. It is kept for mobile-app compatibility. Creating a user, or changing their portal type, automatically sets their default global role (`admin` → `super_admin`, `agent` → `agent`, client → none). Changing the portal type **replaces** the global role, so a demoted admin loses Super Admin immediately.

## Matrix (summary)

| Permission group | Super Admin | Ops Manager | Support | Supervisor* | Agent* | Owner | Manager | Staff |
|---|---|---|---|---|---|---|---|---|
| dashboard, organization.view | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | dashboard only |
| calls view/create/update | ✓ | ✓ | view | ✓ | ✓ | ✓ | ✓ | view |
| calls.recording.view | ✓ | ✓ | – | ✓ | – | ✓ | ✓ | – |
| customers, appointments, tasks, messages | ✓ | ✓ | view (+tasks, messages) | ✓ | ✓ | ✓ | ✓ | ✓ (no delete/cancel) |
| escalations.view | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| escalations.create (raise) | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | – |
| escalations.resolve (acknowledge, assign, resolve) | ✓ | ✓ | – | – | – | ✓ | ✓ | ✓ |
| knowledge_base.manage | ✓ | ✓ | – | ✓ | – | ✓ | ✓ | – |
| billing.view / subscriptions.view | ✓ | ✓ | ✓ | – | – | ✓ | ✓ | – |
| billing.manage / subscriptions.manage | ✓ | – | – | – | – | ✓ | – | – |
| organization.update | ✓ | ✓ | – | – | – | ✓ | – | – |
| users.delete | ✓ | – | – | – | – | ✓ | – | – |
| ai / integrations / marketing / seo / reviews / social manage | ✓ | partly | – | – | – | ✓ | ✓ | – |

\* Only in assigned organizations. The full list per role is in `config/authorization.php`.

### Platform-only permissions
These aren't in the spec §5 list. Organization roles never get them.

| Permission | What it allows | Who has it |
|---|---|---|
| `audit_logs.view` (P1-5) | The internal audit trail (spec §62) | Super Admin, Operations Manager |
| `users.impersonate` (D26) | "View as client" for support | Super Admin, Operations Manager, Support Agent |
| `qa.review` (D27) | Scoring agents' calls | Super Admin, Operations Manager, Agent Supervisor (assigned businesses only) |

## Changing the catalogue

1. Edit `config/authorization.php`.
2. Run `php artisan permissions:sync`. It's idempotent: creates permissions and roles, re-syncs role permissions, and gives role-less admins/agents their default role.
3. Add or adjust tests in `tests/Feature/PermissionsTest.php`.

## Enforcement points today

| Where | Check |
|---|---|
| `POST /admin/call-logs`, `POST /api/v1/agent/call-logs` | `can:calls.create` + `LogCall` requires `calls.create` **in the client's organization** |
| Client dashboard (web), `/api/v1/client/*` call endpoints | `tenant` middleware + `can:calls.view` |
| `PUT /api/v1/agent/call-logs/{id}` | `CallLogPolicy::update`: own call **and** still `calls.update` in its organization |
| Agent client picker (web + API) | `User::clientsVisibleTo()`: assigned organizations only |
| `/api/v1/admin/*` (D32) | `role:admin` for the portal, then per route: dashboard `dashboard.view`; analytics and agent performance `reports.view`; users `users.view` / `users.create` / `users.update` / `users.delete`; call logs `calls.view`; duty schedules `users.view` to read, `users.update` to change |
| `/api/v1/duty-schedules`, `/api/v1/agent/dashboard/*` (D32) | Everyone sees their own; another agent's shifts or numbers need `users.view` |
