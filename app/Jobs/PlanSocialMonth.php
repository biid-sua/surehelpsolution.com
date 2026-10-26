<?php

namespace App\Jobs;

use App\Models\SocialAiRun;
use App\Services\Social\Ai\ContentStudio;
use App\Support\DemoGuard;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Writes a month of draft social posts with AI (D66). One attempt: a failed plan says why and the
 * business can ask again.
 */
class PlanSocialMonth implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(public readonly int $runId) {}

    public function handle(ContentStudio $studio): void
    {
        $run = SocialAiRun::withoutGlobalScopes()->find($this->runId);
        if ($run && in_array($run->status, ['queued', 'running'], true) && ! DemoGuard::isDemo($run->organization_id)) {
            $studio->runPlan($run);
        }
    }

    public function failed(?\Throwable $e): void
    {
        SocialAiRun::withoutGlobalScopes()->whereKey($this->runId)->whereIn('status', ['queued', 'running'])
            ->update(['status' => 'failed', 'error' => 'Something went wrong. Please try again.']);
    }
}
