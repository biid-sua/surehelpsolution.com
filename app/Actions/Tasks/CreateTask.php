<?php

namespace App\Actions\Tasks;

use App\Actions\Customers\RecordTimelineEvent;
use App\Enums\TaskType;
use App\Enums\TimelineEventType;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\Task;
use App\Models\User;
use App\Notifications\TaskActivity;
use App\Support\Audit\Audit;
use Illuminate\Validation\ValidationException;

/**
 * Creates a task for a business (spec §24). One path for the portal, the API and calls.
 */
class CreateTask
{
    public function __construct(
        private readonly Audit $audit,
        private readonly RecordTimelineEvent $timeline,
    ) {}

    /**
     * @param  array{type?: TaskType|string, title: string, description?: ?string, priority?: string, due_at?: mixed, customer_id?: mixed, call_log_id?: ?int, assigned_to_user_id?: mixed}  $data
     *
     * @throws ValidationException when the customer or assignee isn't part of this business
     */
    public function handle(Organization $organization, array $data, ?User $actor = null, string $source = 'manual'): Task
    {
        $customer = $this->customer($organization, $data['customer_id'] ?? null);
        $assignee = self::assignee($organization, $data['assigned_to_user_id'] ?? null);

        $task = Task::create([
            'organization_id' => $organization->id,
            'customer_id' => $customer?->id,
            'call_log_id' => $data['call_log_id'] ?? null,
            'type' => $data['type'] ?? TaskType::Todo,
            'title' => trim($data['title']),
            'description' => filled($data['description'] ?? null) ? trim((string) $data['description']) : null,
            'priority' => $data['priority'] ?? 'normal',
            'source' => $source,
            'due_at' => $data['due_at'] ?? null,
            'assigned_to_user_id' => $assignee?->id,
            'created_by_user_id' => $actor?->id,
        ]);

        $this->audit->record('task.created', $task, new: [
            'type' => $task->type->value,
            'title' => $task->title,
            'priority' => $task->priority->value,
            'due_at' => $task->due_at?->toIso8601String(),
            'assigned_to_user_id' => $task->assigned_to_user_id,
            'source' => $source,
        ], organization: $organization, actor: $actor);

        if ($customer) {
            $this->timeline->handle($customer, TimelineEventType::TaskCreated, $task->type->label().': '.$task->title, $task->description, $task, ['task' => $task->ulid], $actor?->id);
        }

        if ($assignee && $assignee->id !== $actor?->id) {
            $assignee->notify(new TaskActivity($task, TaskActivity::ASSIGNED));
        }

        return $task;
    }

    private function customer(Organization $organization, mixed $id): ?Customer
    {
        if ($id === null || $id === '') {
            return null;
        }

        return Customer::query()->forOrganization($organization)->whereKey($id)->first()
            ?? throw ValidationException::withMessages(['customer_id' => ['Choose one of your customers.']]);
    }

    /**
     * Tasks can only be given to active members of the business who can see tasks.
     *
     * @throws ValidationException
     */
    public static function assignee(Organization $organization, mixed $id): ?User
    {
        if ($id === null || $id === '') {
            return null;
        }

        $user = $organization->members()->wherePivot('status', 'active')->where('users.is_active', true)->whereKey($id)->first();

        if (! $user || ! $user->hasPermissionIn('tasks.view', $organization)) {
            throw ValidationException::withMessages(['assigned_to_user_id' => ['Choose someone on your team.']]);
        }

        return $user;
    }
}
