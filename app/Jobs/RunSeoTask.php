<?php

namespace App\Jobs;

use App\Models\SeoTask;
use App\Services\Seo\SeoDesk;
use App\Support\DemoGuard;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Does one SEO task with AI (D57). Not retried automatically: a failed task says why, and the person tries again.
 */
class RunSeoTask implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(public readonly int $taskId) {}

    public function handle(SeoDesk $desk): void
    {
        $task = SeoTask::withoutGlobalScopes()->find($this->taskId);
        if ($task && ! DemoGuard::isDemo($task->organization_id)) {
            $desk->run($task);
        }
    }

    public function failed(?\Throwable $e): void
    {
        SeoTask::withoutGlobalScopes()->whereKey($this->taskId)->whereIn('status', ['queued', 'running'])
            ->update(['status' => 'failed', 'error' => 'Something went wrong. Please try again.']);
    }
}
