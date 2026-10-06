<?php

namespace App\Services\Training;

use App\Models\Organization;
use App\Models\TrainingCourse;
use App\Models\TrainingPath;
use App\Models\User;
use App\Services\Assignments\AssignmentScope;
use Illuminate\Database\Eloquent\Builder;

/**
 * Who may take or manage which training (D40–D42), on top of the one permission check:
 *  - Platform-wide courses: platform roles manage them; every learner may take them.
 *  - Company courses: managed with the permission in that company (supervisors: only companies
 *    they currently serve); taken only by agents currently assigned to that company.
 * Business owners and their people never hold these permissions (D41).
 */
class TrainingAccess
{
    public function __construct(private readonly AssignmentScope $scope) {}

    public function canLearn(User $user, TrainingCourse $course): bool
    {
        if (! $course->is_active || ! $course->isPublished()) {
            return false;
        }

        return $course->organization_id === null
            ? $user->hasPermissionIn('agent_university.view')
            : $user->hasPermissionIn('agent_university.view', $course->organization);
    }

    public function canManage(User $user, TrainingCourse|TrainingPath $item, string $permission = 'training.update'): bool
    {
        return $item->organization_id === null
            ? $user->hasPlatformPermission($permission)
            : $user->hasPermissionIn($permission, $item->organization);
    }

    /** Whether the user may create training for this company (null = platform-wide). */
    public function canCreateFor(User $user, ?Organization $organization, string $permission = 'training.create'): bool
    {
        return $organization === null ? $user->hasPlatformPermission($permission) : $user->hasPermissionIn($permission, $organization);
    }

    /** Whether the user may open the training management screens at all. */
    public function canOpenManagement(User $user): bool
    {
        foreach (['training.create', 'training.update', 'training.assign', 'training.view_progress', 'training.manage_certifications'] as $permission) {
            if ($user->hasPermissionIn($permission)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Companies the user may manage training for with this permission.
     *
     * @return Builder<Organization>
     */
    public function companies(User $user, string $permission = 'training.update'): Builder
    {
        return $this->scope->organizations($user, $permission);
    }

    /**
     * Courses the user may manage: platform-wide ones with a platform role, and those of their companies.
     *
     * @param  Builder<TrainingCourse>  $query
     * @return Builder<TrainingCourse>
     */
    public function manageable(Builder $query, User $user, string $permission = 'training.update'): Builder
    {
        $platform = $user->hasPlatformPermission($permission);
        $companies = $this->companies($user, $permission)->select('organizations.id');

        return $query->where(fn (Builder $q) => $q->whereIn('organization_id', $companies)
            ->when($platform, fn (Builder $q) => $q->orWhereNull('organization_id')));
    }

    /**
     * Agents whose training the user may see: everyone for platform staff, otherwise the agents
     * currently serving one of the user's companies.
     *
     * @return Builder<User>
     */
    public function agents(User $user, string $permission = 'training.view_progress'): Builder
    {
        $query = User::query()->where('role', 'agent');
        if ($user->hasPlatformPermission($permission)) {
            return $query;
        }

        $companies = $this->companies($user, $permission)->pluck('organizations.id');

        return $query->whereHas('agentAssignments', fn (Builder $q) => $q->whereIn('organization_id', $companies)->current());
    }

    public function canSeeAgent(User $user, User $agent, string $permission = 'training.view_progress'): bool
    {
        return $this->agents($user, $permission)->whereKey($agent->id)->exists();
    }
}
