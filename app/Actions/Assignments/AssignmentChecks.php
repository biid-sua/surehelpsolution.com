<?php

namespace App\Actions\Assignments;

use App\Enums\OrganizationStatus;
use App\Models\AgentAssignment;
use App\Models\AgentDutySchedule;
use App\Models\Organization;
use App\Models\Task;
use App\Models\User;
use App\Services\Training\TrainingReadiness;
use Illuminate\Support\Carbon;

/**
 * What a supervisor needs to know before assigning an agent to a company (spec §20A workflow, §3.2):
 * hard checks that stop the assignment, and information to decide well.
 */
class AssignmentChecks
{
    /**
     * Reasons the assignment can't happen at all.
     *
     * @return list<string>
     */
    public static function blocking(Organization $organization, User $agent): array
    {
        $problems = [];
        if (! $agent->isAgent()) {
            $problems[] = $agent->name.' is not an agent account.';
        }
        if (! $agent->is_active) {
            $problems[] = $agent->name.'\'s account is switched off.';
        }
        if (! in_array($organization->status, [OrganizationStatus::Active, OrganizationStatus::Onboarding], true)) {
            $problems[] = $organization->name.' is not an active company.';
        }

        return $problems;
    }

    /**
     * Everything shown on the confirmation step.
     *
     * @return array{blocking: list<string>, existing: ?AgentAssignment, companies: int, open_tasks: int, on_shift: bool, next_shift: ?Carbon, readiness: array{ready: bool, required: int, missing: list<string>, enforcement: ?string}}
     */
    public static function preview(Organization $organization, User $agent): array
    {
        $current = $agent->assignedOrganizations()->pluck('organizations.id');
        $now = now();

        return [
            'blocking' => self::blocking($organization, $agent),
            'existing' => AgentAssignment::query()->where('organization_id', $organization->id)->where('agent_user_id', $agent->id)->open()->latest('id')->first(),
            'companies' => $current->count(),
            'open_tasks' => Task::withoutGlobalScopes()->whereIn('organization_id', $current)->where('assigned_to_user_id', $agent->id)->open()->count(),
            'on_shift' => AgentDutySchedule::query()->where('agent_id', $agent->id)->where('is_active', true)
                ->where('start_datetime', '<=', $now)->where('end_datetime', '>', $now)->exists(),
            'next_shift' => AgentDutySchedule::query()->where('agent_id', $agent->id)->where('is_active', true)
                ->where('start_datetime', '>', $now)->orderBy('start_datetime')->value('start_datetime'),
            'readiness' => app(TrainingReadiness::class)->for($agent, $organization),
        ];
    }
}
