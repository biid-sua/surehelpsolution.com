<?php

namespace App\Actions\Escalations;

use App\Models\Escalation;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\EscalationActivity;
use App\Support\Audit\Audit;
use Illuminate\Validation\ValidationException;

/**
 * Hands an escalation to one person on the team (spec §25: assigned person).
 */
class AssignEscalation
{
    public function __construct(private readonly Audit $audit) {}

    public function handle(Escalation $escalation, mixed $userId, User $actor): Escalation
    {
        $assignee = self::member($escalation->organization, $userId);
        $escalation->assigned_to_user_id = $assignee?->id;

        if (! $escalation->isDirty('assigned_to_user_id')) {
            return $escalation;
        }

        $escalation->save();
        $this->audit->changes('escalation.assigned', $escalation, ['assigned_to_user_id']);

        if ($assignee && $assignee->id !== $actor->id) {
            $assignee->notify(new EscalationActivity($escalation, EscalationActivity::ASSIGNED));
        }

        return $escalation;
    }

    /**
     * An active member of the business who can see escalations, or null for "nobody".
     *
     * @throws ValidationException
     */
    public static function member(Organization $organization, mixed $id): ?User
    {
        if ($id === null || $id === '') {
            return null;
        }

        $user = $organization->members()->wherePivot('status', 'active')->where('users.is_active', true)->whereKey($id)->first();

        if (! $user || ! $user->hasPermissionIn('escalations.view', $organization)) {
            throw ValidationException::withMessages(['assigned_to_user_id' => ['Choose someone on your team.']]);
        }

        return $user;
    }
}
