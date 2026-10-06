<?php

namespace App\Actions\Assignments;

use App\Models\AgentAssignment;
use App\Models\User;
use App\Notifications\AssignmentActivity;
use App\Support\Audit\Audit;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * Ends, revokes, suspends or resumes an assignment (D40). Nothing is deleted: the row keeps its
 * history with who changed it, when and why.
 */
class ChangeAssignment
{
    public function __construct(private readonly Audit $audit) {}

    /**
     * Normal end of an assignment (account reassigned, contract over).
     *
     * @throws AuthorizationException
     */
    public function end(AgentAssignment $assignment, User $by, string $reason): AgentAssignment
    {
        return $this->finish($assignment, $by, $reason, AgentAssignment::ENDED, 'agent.unassigned', 'agent_assignments.update');
    }

    /**
     * Immediate removal for cause (e.g. a security concern). Same effect, recorded differently.
     *
     * @throws AuthorizationException
     */
    public function revoke(AgentAssignment $assignment, User $by, string $reason): AgentAssignment
    {
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => ['Say why the assignment is revoked.']]);
        }

        return $this->finish($assignment, $by, $reason, AgentAssignment::REVOKED, 'agent.assignment_revoked', 'agent_assignments.revoke');
    }

    /**
     * @throws AuthorizationException
     */
    public function suspend(AgentAssignment $assignment, User $by, string $reason): AgentAssignment
    {
        $this->authorize($assignment, $by, 'agent_assignments.update');
        if (! $assignment->isCurrent()) {
            throw ValidationException::withMessages(['assignment' => ['Only an active assignment can be suspended.']]);
        }

        $old = $assignment->status;
        $assignment->forceFill(['status' => AgentAssignment::SUSPENDED, 'end_reason' => mb_substr(trim($reason), 0, 500) ?: null, 'status_changed_at' => now()])->save();
        $this->record('agent.assignment_suspended', $assignment, $by, $old, $reason);
        $assignment->agent?->notify(new AssignmentActivity($assignment, AssignmentActivity::SUSPENDED));

        return $assignment;
    }

    /**
     * @throws AuthorizationException
     */
    public function resume(AgentAssignment $assignment, User $by): AgentAssignment
    {
        $this->authorize($assignment, $by, 'agent_assignments.update');
        if ($assignment->status !== AgentAssignment::SUSPENDED) {
            throw ValidationException::withMessages(['assignment' => ['Only a suspended assignment can be resumed.']]);
        }
        if ($assignment->ends_at && $assignment->ends_at->isPast()) {
            throw ValidationException::withMessages(['assignment' => ['This assignment\'s end date has passed. Create a new assignment instead.']]);
        }

        $assignment->forceFill(['status' => AgentAssignment::ACTIVE, 'end_reason' => null, 'status_changed_at' => now()])->save();
        $this->record('agent.assignment_resumed', $assignment, $by, AgentAssignment::SUSPENDED, null);
        $assignment->agent?->notify(new AssignmentActivity($assignment, AssignmentActivity::STARTED));

        return $assignment;
    }

    private function finish(AgentAssignment $assignment, User $by, string $reason, string $status, string $action, string $permission): AgentAssignment
    {
        $this->authorize($assignment, $by, $permission);
        if (in_array($assignment->status, [AgentAssignment::ENDED, AgentAssignment::REVOKED], true)) {
            throw ValidationException::withMessages(['assignment' => ['This assignment has already ended.']]);
        }

        $old = $assignment->effectiveStatus();
        $wasCurrent = $assignment->isCurrent() || $assignment->status === AgentAssignment::SUSPENDED;
        $assignment->forceFill([
            'status' => $status,
            'ends_at' => $assignment->ends_at && $assignment->ends_at->isPast() ? $assignment->ends_at : now(),
            'ended_by_user_id' => $by->id,
            'end_reason' => mb_substr(trim($reason), 0, 500) ?: null,
            'status_changed_at' => now(),
        ])->save();

        $this->record($action, $assignment, $by, $old, $reason);
        if ($wasCurrent || $old === AgentAssignment::SCHEDULED) {
            $assignment->agent?->notify(new AssignmentActivity($assignment, AssignmentActivity::ENDED));
        }

        return $assignment;
    }

    /**
     * @throws AuthorizationException
     */
    private function authorize(AgentAssignment $assignment, User $by, string $permission): void
    {
        if (! $assignment->organization || ! $by->hasPermissionIn($permission, $assignment->organization)) {
            throw new AuthorizationException('You can\'t change assignments for this company.');
        }
    }

    private function record(string $action, AgentAssignment $assignment, User $by, string $old, ?string $reason): void
    {
        $this->audit->record($action, $assignment, old: ['status' => $old], new: array_filter([
            'status' => $assignment->status,
            'agent' => $assignment->agent?->name,
            'company' => $assignment->organization?->name,
            'reason' => $reason ?: null,
        ]), organization: $assignment->organization, actor: $by, label: ($assignment->agent->name ?? 'Agent').' → '.($assignment->organization->name ?? 'company'));
    }
}
