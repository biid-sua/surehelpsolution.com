<?php

namespace App\Services\Tasks;

use App\Actions\Notifications\NotifyOrganization;
use App\Models\Task;
use App\Notifications\TaskActivity;
use Illuminate\Support\Facades\DB;

/**
 * Tells people once when a task passes its due time (followup.overdue, spec §24, §27).
 *
 * The assignee hears about it. Unassigned tasks go to everyone in the business who can see tasks.
 * Each task is claimed with a conditional update, so overlapping runs never notify twice.
 */
class OverdueTaskSweep
{
    public function __construct(private readonly NotifyOrganization $notify) {}

    /**
     * @return int tasks notified
     */
    public function run(): int
    {
        $sent = 0;

        Task::withoutGlobalScopes()
            ->overdue()
            ->whereNull('overdue_notified_at')
            ->with(['organization', 'assignee'])
            ->chunkById(200, function ($tasks) use (&$sent) {
                foreach ($tasks as $task) {
                    $claimed = DB::table('tasks')->where('id', $task->id)->whereNull('overdue_notified_at')
                        ->update(['overdue_notified_at' => now()]);

                    if ($claimed === 0 || ! $task->organization) {
                        continue;
                    }

                    try {
                        $notification = new TaskActivity($task, TaskActivity::OVERDUE);
                        $assignee = $task->assignee;

                        if ($assignee && $assignee->is_active && $assignee->hasPermissionIn('tasks.view', $task->organization)) {
                            $assignee->notify($notification);
                        } else {
                            $this->notify->handle($task->organization, $notification, 'tasks.view');
                        }
                        $sent++;
                    } catch (\Throwable $e) {
                        report($e);
                    }
                }
            });

        return $sent;
    }
}
