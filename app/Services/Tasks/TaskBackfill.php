<?php

namespace App\Services\Tasks;

use App\Actions\Tasks\CreateCallbackTask;
use App\Enums\OutcomeCategory;
use App\Models\CallLog;
use App\Models\Organization;
use App\Models\Task;
use App\Services\Calls\CallOutcomes;
use Illuminate\Support\Facades\DB;

/**
 * Turns call-backs that were waiting before tasks existed into tasks (P2-4b).
 * Idempotent. Tasks already past due are marked as notified, so deploying never sends a burst of overdue alerts.
 */
class TaskBackfill
{
    public function __construct(
        private readonly CreateCallbackTask $callbacks,
        private readonly CallOutcomes $outcomes,
    ) {}

    /**
     * @return array{tasks_created: int, already_overdue: int}
     */
    public function run(bool $dryRun = false): array
    {
        $report = ['tasks_created' => 0, 'already_overdue' => 0];

        DB::beginTransaction();

        try {
            foreach (Organization::query()->orderBy('id')->get() as $organization) {
                $keys = $this->outcomes->keys($organization, OutcomeCategory::Callback);
                if ($keys === []) {
                    continue;
                }

                CallLog::withoutGlobalScopes()
                    ->where('organization_id', $organization->id)
                    ->whereIn('call_outcome', $keys)
                    ->whereNotIn('status', ['completed', 'cancelled', 'spam'])
                    ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('tasks')->whereColumn('tasks.call_log_id', 'call_logs.id'))
                    ->chunkById(200, function ($calls) use ($organization, &$report) {
                        foreach ($calls as $call) {
                            $task = $this->callbacks->handle($call, $organization, null, 'backfill');
                            $report['tasks_created']++;

                            if ($task->isOverdue()) {
                                Task::withoutGlobalScopes()->whereKey($task->id)->update(['overdue_notified_at' => now()]);
                                $report['already_overdue']++;
                            }
                        }
                    });
            }

            $dryRun ? DB::rollBack() : DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();

            throw $e;
        }

        return $report;
    }
}
