<?php

namespace App\Actions\Tasks;

use App\Models\Task;
use App\Models\User;
use App\Notifications\TaskActivity;
use App\Support\Audit\Audit;
use Illuminate\Validation\ValidationException;

/**
 * Edits a task's details (not its status, see ChangeTaskStatus).
 */
class UpdateTask
{
    public const FIELDS = ['type', 'title', 'description', 'priority', 'due_at', 'assigned_to_user_id'];

    public function __construct(private readonly Audit $audit) {}

    /**
     * @param  array<string, mixed>  $data  any of FIELDS
     *
     * @throws ValidationException when the assignee isn't on the team
     */
    public function handle(Task $task, array $data, User $actor): Task
    {
        $data = array_intersect_key($data, array_flip(self::FIELDS));

        if (array_key_exists('assigned_to_user_id', $data)) {
            $data['assigned_to_user_id'] = CreateTask::assignee($task->organization, $data['assigned_to_user_id'])?->id;
        }
        if (array_key_exists('title', $data)) {
            $data['title'] = trim((string) $data['title']);
        }
        if (array_key_exists('description', $data)) {
            $data['description'] = filled($data['description']) ? trim((string) $data['description']) : null;
        }

        $task->fill($data);

        // A new due date earns a new overdue reminder.
        if ($task->isDirty('due_at')) {
            $task->overdue_notified_at = null;
        }

        $reassigned = $task->isDirty('assigned_to_user_id') && $task->assigned_to_user_id !== null;
        $task->save();
        $this->audit->changes('task.updated', $task, self::FIELDS);

        if ($reassigned && $task->assigned_to_user_id !== $actor->id) {
            $task->assignee?->notify(new TaskActivity($task, TaskActivity::ASSIGNED));
        }

        return $task;
    }
}
