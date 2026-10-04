<?php

namespace App\Support\Authorization;

use App\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Reads config/authorization.php — the single permission catalogue.
 */
class RoleCatalog
{
    /**
     * Idempotently write permissions and global roles to the database and give
     * legacy admin/agent accounts their default role.
     */
    public function sync(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach ($this->permissions() as $name) {
            Permission::findOrCreate($name, 'web');
        }

        foreach (config('authorization.roles') as $name => $definition) {
            Role::findOrCreate($name, 'web')->syncPermissions($definition['permissions']);
        }

        foreach (config('authorization.portal_defaults') as $portal => $role) {
            User::where('role', $portal)
                ->whereDoesntHave('roles')
                ->each(fn (User $user) => $user->assignRole($role));
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * @return list<string>
     */
    public function permissions(): array
    {
        return config('authorization.permissions');
    }

    public function isPermission(string $ability): bool
    {
        return in_array($ability, $this->permissions(), true);
    }

    /**
     * "platform" (any organization) or "assigned" (assigned organizations only).
     */
    public function roleScope(string $role): ?string
    {
        return config("authorization.roles.{$role}.scope");
    }

    /**
     * @return list<string>
     */
    public function organizationRolePermissions(?string $role): array
    {
        return $role ? config("authorization.organization_roles.{$role}.permissions", []) : [];
    }

    /**
     * @return list<string>
     */
    public function organizationRoles(): array
    {
        return array_keys(config('authorization.organization_roles'));
    }
}
