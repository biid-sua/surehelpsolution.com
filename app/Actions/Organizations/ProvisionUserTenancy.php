<?php

namespace App\Actions\Organizations;

use App\Enums\AgentAssignmentSource;
use App\Enums\OrganizationStatus;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Gives a newly created (or migrated) user their place in the tenancy model:
 * - client → owns exactly one organization (docs/decisions.md D1)
 * - agent  → assigned to organizations per config('tenancy.auto_assign_agents') (D3)
 */
class ProvisionUserTenancy
{
    public function handle(User $user, AgentAssignmentSource $source = AgentAssignmentSource::Automatic): ?Organization
    {
        return match ($user->role) {
            'client' => $this->provisionClient($user, $source),
            'agent' => $this->provisionAgent($user, $source),
            default => null,
        };
    }

    private function provisionClient(User $user, AgentAssignmentSource $source): Organization
    {
        return DB::transaction(function () use ($user, $source) {
            $existing = $user->organizations()->orderBy('organizations.id')->first();
            if ($existing) {
                return $existing;
            }

            $organization = Organization::create([
                'name' => $user->name,
                'status' => $user->is_active === false ? OrganizationStatus::Paused : OrganizationStatus::Active,
                'owner_user_id' => $user->getKey(),
            ]);

            $organization->members()->attach($user->getKey(), [
                'role' => 'owner',
                'status' => 'active',
                'joined_at' => now(),
            ]);

            if (config('tenancy.auto_assign_agents')) {
                User::where('role', 'agent')->where('is_active', true)->each(
                    fn (User $agent) => $organization->assignAgent($agent, $source)
                );
            }

            return $organization;
        });
    }

    private function provisionAgent(User $agent, AgentAssignmentSource $source): null
    {
        if (config('tenancy.auto_assign_agents')) {
            Organization::whereIn('status', [OrganizationStatus::Active, OrganizationStatus::Onboarding])
                ->each(fn (Organization $organization) => $organization->assignAgent($agent, $source));
        }

        return null;
    }
}
