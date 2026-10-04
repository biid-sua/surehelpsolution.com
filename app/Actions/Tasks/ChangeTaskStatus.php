<?php

namespace App\Actions\Tasks;

use App\Actions\Customers\RecordTimelineEvent;
use App\Enums\TaskStatus;
use App\Enums\TimelineEventType;
use App\Models\Task;
use App\Models\User;
use App\Support\Audit\Audit;

/**
 * Moves a task through its lifecycle: start, complete, cancel, reopen.
 */
class ChangeTaskStatus
{
    public function __construct(
        private readonly Audit $audit,
        private readonly RecordTimelineEvent $timeline,
    ) {}

    public function handle(Task $task, TaskStatus $status, User $actor): Task
    {
        if ($task->status === $status) {
            return $task;
        }

        $old = $task->status;
        $task->status = $status;

        if ($status === TaskStatus::Completed) {
            $task->completed_at = now();
            $task->completed_by_user_id = $actor->id;
        } else {
            $task->completed_at = null;
            $task->completed_by_user_id = null;
        }

        $task->save();

        $this->audit->record('task.status_changed', $task, old: ['status' => $old->value], new: ['status' => $status->value], actor: $actor);

        if ($status === TaskStatus::Completed && $task->customer) {
            $this->timeline->handle($task->customer, TimelineEventType::TaskCompleted, 'Done: '.$task->title, subject: $task, meta: ['task' => $task->ulid], actorId: $actor->id);
        }

        return $task;
    }
}
