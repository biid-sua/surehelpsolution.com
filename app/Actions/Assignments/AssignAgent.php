<?php

namespace App\Actions\Assignments;

use App\Events\AgentAssignmentStarted;
use App\Models\AgentAssignment;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\AssignmentActivity;
use App\Support\Audit\Audit;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Assigns an agent to serve a company (spec §20A workflow, D40–D41): checks who may do it, that the
 * agent and company are active and that there is no open assignment already, then records it in one
 * transaction. Starts now (active) or on a future date (scheduled).
 */
class AssignAgent
{
    public function __construct(private readonly Audit $audit) {}

    /**
     * @param  array{starts_at?: ?CarbonImmutable, ends_at?: ?CarbonImmutable, type?: ?string, notes?: ?string}  $options
     *
     * @throws AuthorizationException when the person may not assign agents to this company
     * @throws ValidationException when a check fails
     */
    public function handle(Organization $organization, User $agent, User $by, array $options = []): AgentAssignment
    {
        if (! $by->hasPermissionIn('agent_assignments.create', $organization)) {
            throw new AuthorizationException('You can\'t assign agents to this company.');
        }

        $problems = AssignmentChecks::blocking($organization, $agent);
        if ($problems !== []) {
            throw ValidationException::withMessages(['agent' => $problems]);
        }

        $starts = $options['starts_at'] ?? null;
        $ends = $options['ends_at'] ?? null;
        $now = CarbonImmutable::now();
        if ($starts && $starts->lessThan($now->subMinute())) {
            throw ValidationException::withMessages(['starts_at' => ['The start can\'t be in the past. Leave it empty to start now.']]);
        }
        if ($ends && $ends->lessThanOrEqualTo($starts ?? $now)) {
            throw ValidationException::withMessages(['ends_at' => ['The end must be after the start.']]);
        }
        $type = array_key_exists((string) ($options['type'] ?? ''), AgentAssignment::TYPES) ? (string) $options['type'] : 'standard';

        $assignment = DB::transaction(function () use ($organization, $agent, $by, $starts, $ends, $type, $options, $now) {
            // Lock this agent's rows for the company so two supervisors can't assign at the same moment.
            $open = AgentAssignment::query()->where('organization_id', $organization->id)->where('agent_user_id', $agent->id)
                ->lockForUpdate()->get()->first(fn (AgentAssignment $a) => in_array($a->status, [AgentAssignment::SCHEDULED, AgentAssignment::ACTIVE, AgentAssignment::SUSPENDED], true)
                    && ($a->ends_at === null || $a->ends_at->greaterThan($now)));
            if ($open) {
                throw ValidationException::withMessages(['agent' => [$agent->name.' is already '.strtolower(AgentAssignment::label($open->effectiveStatus())).' for this company.']]);
            }

            $future = $starts && $starts->greaterThan($now);

            return AgentAssignment::create([
                'organization_id' => $organization->id,
                'agent_user_id' => $agent->id,
                'status' => $future ? AgentAssignment::SCHEDULED : AgentAssignment::ACTIVE,
                'assignment_type' => $type,
                'starts_at' => $starts ?? $now,
                'ends_at' => $ends,
                'source' => 'manual',
                'assigned_by_user_id' => $by->id,
                'notes' => filled($options['notes'] ?? null) ? mb_substr(trim((string) $options['notes']), 0, 2000) : null,
                'status_changed_at' => $now,
            ]);
        });

        $this->audit->record('agent.assigned', $assignment, new: [
            'agent' => $agent->name, 'agent_id' => $agent->id, 'company' => $organization->name,
            'status' => $assignment->status, 'starts_at' => $assignment->starts_at?->toIso8601String(),
            'ends_at' => $assignment->ends_at?->toIso8601String(), 'type' => $type,
        ], organization: $organization, actor: $by, label: $agent->name.' → '.$organization->name);

        if ($assignment->isCurrent()) {
            $agent->notify(new AssignmentActivity($assignment, AssignmentActivity::STARTED));
            AgentAssignmentStarted::dispatch($assignment);
        } else {
            $agent->notify(new AssignmentActivity($assignment, AssignmentActivity::SCHEDULED));
        }

        return $assignment;
    }

    /**
     * Ends the agent's current assignment and gives the company to another agent, in one transaction.
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function reassign(AgentAssignment $from, User $to, User $by, string $reason): AgentAssignment
    {
        return DB::transaction(function () use ($from, $to, $by, $reason) {
            app(ChangeAssignment::class)->end($from, $by, trim($reason) !== '' ? $reason : 'Reassigned to '.$to->name);

            return $this->handle($from->organization, $to, $by, ['type' => $from->assignment_type]);
        });
    }
}
