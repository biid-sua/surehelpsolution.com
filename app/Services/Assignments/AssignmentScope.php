<?php

namespace App\Services\Assignments;

use App\Enums\OrganizationStatus;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Which companies a person may see or manage assignments for (D41): every company for platform staff
 * holding the permission, only their own current companies for agent supervisors, none otherwise.
 */
class AssignmentScope
{
    /**
     * @return Builder<Organization>
     */
    public function organizations(User $viewer, string $permission = 'agent_assignments.view'): Builder
    {
        $query = Organization::query()->whereIn('status', [OrganizationStatus::Active->value, OrganizationStatus::Onboarding->value, OrganizationStatus::Paused->value]);

        if (! $viewer->hasPermissionIn($permission)) {
            return $query->whereRaw('1 = 0');
        }
        if ($viewer->isAdmin()) {
            return $query;
        }

        // A supervisor's role ("assigned" scope) grants the permission exactly in their current companies;
        // business roles never carry workforce permissions (D41).
        return $query->whereIn('organizations.id', $viewer->assignedOrganizations()->select('organizations.id'));
    }

    /** @return list<int> */
    public function ids(User $viewer, string $permission = 'agent_assignments.view'): array
    {
        return $this->organizations($viewer, $permission)->pluck('organizations.id')->all();
    }

    public function canManage(User $viewer, Organization $organization, string $permission = 'agent_assignments.view'): bool
    {
        return $viewer->hasPermissionIn($permission, $organization);
    }
}
