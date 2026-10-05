<?php

namespace App\Jobs;

use App\Models\SocialPostTarget;
use App\Services\Social\SocialPublishing;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Publishes one version of a post. Retries are scheduled by SocialPublishing, not by the queue,
 * so a job never runs twice for the same attempt.
 */
class PublishSocialTarget implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(public readonly int $targetId) {}

    public function handle(SocialPublishing $publishing): void
    {
        $target = SocialPostTarget::find($this->targetId);

        if ($target) {
            $publishing->publish($target);
        }
    }
}
