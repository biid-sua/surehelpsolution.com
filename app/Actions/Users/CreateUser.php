<?php

namespace App\Actions\Users;

use App\Actions\Organizations\ProvisionUserTenancy;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Adds a person to the platform from the admin console: a staff member, an agent or a business owner.
 * Agents and owners sign in with a temporary password and must change it first.
 */
class CreateUser
{
    public function __construct(private readonly ProvisionUserTenancy $tenancy) {}

    /**
     * @param  array{name: string, email: string, phone?: ?string, role: string, platform_role?: ?string, business_name?: ?string}  $data
     */
    public function handle(array $data, string $password): User
    {
        return DB::transaction(function () use ($data, $password) {
            $user = User::create([
                'name' => trim($data['name']),
                'email' => mb_strtolower(trim($data['email'])),
                'phone' => ($data['phone'] ?? null) ?: null,
                'role' => $data['role'],
                'password' => $password,
                'is_active' => true,
                'must_change_password' => in_array($data['role'], ['agent', 'client'], true),
            ]);

            if (($data['platform_role'] ?? null) && $data['platform_role'] !== config("authorization.portal_defaults.{$data['role']}")) {
                $user->syncRoles([$data['platform_role']]);
            }

            // Client → their own business; agent → assignments (decisions D1, D3).
            $organization = $this->tenancy->handle($user);
            if ($organization && ($data['business_name'] ?? null)) {
                $organization->update(['name' => trim($data['business_name'])]);
            }

            return $user;
        });
    }
}
