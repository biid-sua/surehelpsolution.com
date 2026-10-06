<?php

namespace App\Services\Training;

use App\Models\AgentAssignment;
use App\Models\Organization;
use App\Models\TrainingAssignment;
use App\Models\TrainingCourse;
use App\Models\TrainingRule;
use App\Models\User;
use App\Notifications\TrainingActivity;
use App\Support\Audit\Audit;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Giving training to agents (brief §1.7–1.8, §3, D42). Rules say who should take a course; each
 * matching agent gets one assignment row, created now and again whenever someone new matches
 * (a new company assignment, a new rule). An agent has one row per course: a second rule only
 * makes it stricter (required, earlier due date, higher priority), never weaker.
 */
class TrainingAssigner
{
    private const PRIORITY_RANK = ['low' => 0, 'normal' => 1, 'high' => 2];

    public function __construct(private readonly TrainingAccess $access, private readonly Audit $audit) {}

    /**
     * @param  array{scope: string, role?: ?string, organization_id?: ?int, agent_user_id?: ?int, is_required?: bool, priority?: string, due_days?: ?int, enforcement?: string}  $data
     */
    public function createRule(TrainingCourse $course, User $by, array $data): TrainingRule
    {
        $organization = isset($data['organization_id']) ? Organization::find($data['organization_id']) : null;
        $agent = isset($data['agent_user_id']) ? User::find($data['agent_user_id']) : null;
        $this->authorizeRule($course, $by, $data['scope'], $organization, $agent, $data['role'] ?? null);

        $rule = DB::transaction(function () use ($course, $by, $data, $organization, $agent) {
            $rule = TrainingRule::create([
                'course_id' => $course->id,
                'scope' => $data['scope'],
                'role' => $data['scope'] === TrainingRule::ROLE ? $data['role'] : null,
                'organization_id' => $data['scope'] === TrainingRule::COMPANY ? $organization?->id : null,
                'agent_user_id' => $data['scope'] === TrainingRule::AGENT ? $agent?->id : null,
                'is_required' => (bool) ($data['is_required'] ?? true),
                'priority' => $data['priority'] ?? 'normal',
                'due_days' => $data['due_days'] ?? null,
                'enforcement' => $data['enforcement'] ?? 'warning',
                'created_by_user_id' => $by->id,
            ]);
            $count = $this->applyRule($rule, $by);
            $this->audit->record('training.rule_created', $course, [], ['audience' => $rule->audience(), 'required' => $rule->is_required, 'agents' => $count],
                $rule->organization ?? $course->organization, $by, $course->title);

            return $rule;
        });

        return $rule;
    }

    private function authorizeRule(TrainingCourse $course, User $by, string $scope, ?Organization $organization, ?User $agent, ?string $role): void
    {
        $fail = fn (string $field, string $message) => throw ValidationException::withMessages([$field => $message]);

        if (! $course->isPublished()) {
            $fail('rule.scope', 'Publish the course before giving it to agents.');
        }

        $allowed = match ($scope) {
            TrainingRule::EVERYONE => $course->isPlatformWide() && $by->hasPlatformPermission('training.assign'),
            TrainingRule::ROLE => $course->isPlatformWide() && array_key_exists((string) $role, TrainingRule::ROLES) && $by->hasPlatformPermission('training.assign'),
            TrainingRule::COMPANY => $organization !== null
                && ($course->isPlatformWide() || $course->organization_id === $organization->id)
                && $by->hasPermissionIn('training.assign', $organization),
            TrainingRule::AGENT => $agent !== null && $agent->isAgent() && $this->canGiveToAgent($course, $by, $agent),
            default => false,
        };

        if (! $allowed) {
            throw new AuthorizationException('You can\'t give this course to that group.');
        }
    }

    /** One agent: the course's company must be one they serve; the assigner must manage that agent. */
    private function canGiveToAgent(TrainingCourse $course, User $by, User $agent): bool
    {
        if ($course->organization_id !== null) {
            return $agent->isAssignedTo($course->organization_id) && $by->hasPermissionIn('training.assign', $course->organization);
        }

        return $this->access->canSeeAgent($by, $agent, 'training.assign');
    }

    /** Creates or tightens the assignment for every agent the rule matches. Returns how many. */
    public function applyRule(TrainingRule $rule, ?User $by = null): int
    {
        if (! $rule->is_active) {
            return 0;
        }
        $course = $rule->course;
        $count = 0;
        $this->agentsFor($rule)->select('users.id', 'users.name')->chunkById(200, function ($agents) use ($rule, $course, $by, &$count) {
            foreach ($agents as $agent) {
                $this->give($course, $agent, $by, [
                    'is_required' => $rule->is_required,
                    'priority' => $rule->priority,
                    'due_at' => $rule->due_days ? now()->addDays($rule->due_days) : null,
                    'organization_id' => $rule->organization_id ?? $course->organization_id,
                    'rule_id' => $rule->id,
                ]);
                $count++;
            }
        }, 'users.id', 'id');

        return $count;
    }

    /** @return Builder<User> active agents the rule matches right now */
    public function agentsFor(TrainingRule $rule): Builder
    {
        $query = User::query()->where('role', 'agent')->where('is_active', true);

        return match ($rule->scope) {
            TrainingRule::EVERYONE => $query,
            TrainingRule::ROLE => $query->whereHas('roles', fn (Builder $q) => $q->where('name', $rule->role)),
            TrainingRule::COMPANY => $query->whereHas('agentAssignments', fn (Builder $q) => $q->where('organization_id', $rule->organization_id)->current()),
            default => $query->whereKey($rule->agent_user_id),
        };
    }

    /**
     * An agent started serving a company (spec §3): give them that company's training rules, plus
     * any platform rules they don't have yet. Returns the courses newly required.
     *
     * @return list<string>
     */
    public function onCompanyAssignment(AgentAssignment $assignment): array
    {
        $agent = $assignment->agent;
        if (! $agent || ! $agent->is_active) {
            return [];
        }
        $added = [];
        $rules = TrainingRule::query()->where('is_active', true)
            ->where(fn (Builder $q) => $q->where(fn (Builder $q) => $q->where('scope', TrainingRule::COMPANY)->where('organization_id', $assignment->organization_id))
                ->orWhere('scope', TrainingRule::EVERYONE)
                ->orWhere(fn (Builder $q) => $q->where('scope', TrainingRule::ROLE)->whereIn('role', $agent->getRoleNames()->all())))
            ->whereHas('course', fn (Builder $q) => $q->where('is_active', true)->where('current_version', '>', 0))
            ->with('course')->get();

        foreach ($rules as $rule) {
            $existing = TrainingAssignment::query()->where('course_id', $rule->course_id)->where('agent_user_id', $agent->id)->first();
            $this->give($rule->course, $agent, null, [
                'is_required' => $rule->is_required,
                'priority' => $rule->priority,
                'due_at' => $rule->due_days ? now()->addDays($rule->due_days) : null,
                'organization_id' => $rule->organization_id ?? $rule->course->organization_id,
                'rule_id' => $rule->id,
            ]);
            if ($rule->is_required && (! $existing || $existing->status === TrainingAssignment::REVOKED)) {
                $added[] = $rule->course->title;
            }
        }

        return $added;
    }

    /**
     * Gives one agent a course, or tightens what they already have. Never loosens: optional never
     * replaces required, a later due date never replaces an earlier one.
     *
     * @param  array{is_required?: bool, priority?: string, due_at?: ?CarbonInterface, organization_id?: ?int, rule_id?: ?int}  $options
     */
    public function give(TrainingCourse $course, User $agent, ?User $by, array $options = []): TrainingAssignment
    {
        return DB::transaction(function () use ($course, $agent, $by, $options) {
            $row = TrainingAssignment::query()->where('course_id', $course->id)->where('agent_user_id', $agent->id)->lockForUpdate()->first();
            $required = (bool) ($options['is_required'] ?? false);
            $due = $options['due_at'] ?? null;
            $priority = $options['priority'] ?? 'normal';

            if (! $row) {
                $row = TrainingAssignment::create([
                    'course_id' => $course->id,
                    'agent_user_id' => $agent->id,
                    'organization_id' => $options['organization_id'] ?? $course->organization_id,
                    'rule_id' => $options['rule_id'] ?? null,
                    'assigned_by_user_id' => $by?->id,
                    'is_required' => $required,
                    'priority' => $priority,
                    'due_at' => $due,
                    'required_version' => max(1, $course->current_version),
                ]);
                $this->audit->record('training.assigned', $course, [], ['agent' => $agent->name, 'required' => $required, 'due' => $due?->toDateString()],
                    $row->organization, $by, $course->title.' → '.$agent->name);
                $agent->notify(new TrainingActivity($row, TrainingActivity::ASSIGNED));

                return $row;
            }

            $reopened = $row->status === TrainingAssignment::REVOKED;
            $changes = [
                'is_required' => $row->is_required || $required,
                'priority' => self::PRIORITY_RANK[$priority] > self::PRIORITY_RANK[$row->priority] ? $priority : $row->priority,
                'organization_id' => $row->organization_id ?? ($options['organization_id'] ?? null),
                'rule_id' => $row->rule_id ?? ($options['rule_id'] ?? null),
            ];
            if ($due && ! $row->isDone() && ($reopened || ! $row->due_at || $due->lt($row->due_at))) {
                // A new due date: its reminders are due again.
                $changes += ['due_at' => $due, 'due_soon_notified_at' => null, 'overdue_notified_at' => null];
            }
            $nowRequired = ! $row->is_required && $required && ! $row->isDone();
            if ($reopened) {
                $changes += [
                    'status' => $row->completed_at ? TrainingAssignment::COMPLETED : ($row->started_at ? TrainingAssignment::IN_PROGRESS : TrainingAssignment::ASSIGNED),
                    'revoked_at' => null, 'revoked_by_user_id' => null, 'revoke_reason' => null,
                    'assigned_at' => now(), 'assigned_by_user_id' => $by?->id,
                ];
            }
            $row->fill($changes)->save();
            if ($reopened) {
                $this->audit->record('training.assigned', $course, ['status' => 'revoked'], ['agent' => $agent->name, 'required' => $row->is_required],
                    $row->organization, $by, $course->title.' → '.$agent->name);
            }
            if ($reopened || $nowRequired) {
                $agent->notify(new TrainingActivity($row, TrainingActivity::ASSIGNED));
            }

            return $row;
        });
    }

    /** Whether $by may change this agent's training: they could give them the course, or they manage the course. */
    private function mayChange(TrainingAssignment $assignment, User $by): bool
    {
        return $this->canGiveToAgent($assignment->course, $by, $assignment->agent) || $this->access->canManage($by, $assignment->course, 'training.assign');
    }

    /** Takes a course away from one agent. Their records stay. */
    public function revoke(TrainingAssignment $assignment, User $by, string $reason): void
    {
        $course = $assignment->course;
        if (! $this->mayChange($assignment, $by)) {
            throw new AuthorizationException('You can\'t change this agent\'s training.');
        }
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => 'Say why the training is removed.']);
        }
        $assignment->forceFill(['status' => TrainingAssignment::REVOKED, 'revoked_at' => now(), 'revoked_by_user_id' => $by->id, 'revoke_reason' => trim($reason)])->save();
        $this->audit->record('training.revoked', $course, [], ['agent' => $assignment->agent->name, 'reason' => trim($reason)], $assignment->organization, $by, $course->title);
    }

    /** Stops a rule. Optionally removes the unfinished training it gave. */
    public function removeRule(TrainingRule $rule, User $by, bool $revokeUnfinished): int
    {
        $course = $rule->course;
        $ok = match ($rule->scope) {
            TrainingRule::COMPANY => $rule->organization && $by->hasPermissionIn('training.assign', $rule->organization),
            TrainingRule::AGENT => $rule->agent && $this->canGiveToAgent($course, $by, $rule->agent),
            default => $by->hasPlatformPermission('training.assign'),
        };
        if (! $ok) {
            throw new AuthorizationException('You can\'t change this rule.');
        }

        return DB::transaction(function () use ($rule, $by, $revokeUnfinished, $course) {
            $rule->forceFill(['is_active' => false])->save();
            $removed = 0;
            if ($revokeUnfinished) {
                $removed = TrainingAssignment::query()->where('rule_id', $rule->id)->whereIn('status', [TrainingAssignment::ASSIGNED, TrainingAssignment::IN_PROGRESS])
                    ->update(['status' => TrainingAssignment::REVOKED, 'revoked_at' => now(), 'revoked_by_user_id' => $by->id, 'revoke_reason' => 'Assignment rule removed', 'updated_at' => now()]);
            }
            $this->audit->record('training.rule_removed', $course, [], ['audience' => $rule->audience(), 'removed' => $removed], $rule->organization ?? $course->organization, $by, $course->title);

            return $removed;
        });
    }

    /** Lets an agent who used every attempt try a quiz once more. */
    public function allowAnotherAttempt(TrainingAssignment $assignment, User $by): void
    {
        if (! $this->mayChange($assignment, $by)) {
            throw new AuthorizationException('You can\'t change this agent\'s training.');
        }
        $assignment->increment('extra_attempts');
        $this->audit->record('training.attempt_granted', $assignment->course, [], ['agent' => $assignment->agent->name], $assignment->organization, $by, $assignment->course->title);
    }
}
