<?php

namespace App\Jobs;

use App\Models\Campaign;
use App\Models\Organization;
use App\Services\Marketing\Outreach;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Sends a campaign (D116) in batches, and waits for the morning when it's the quiet hours.
 */
class SendCampaign implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;

    public function __construct(public readonly int $campaignId) {}

    /** Released for each batch and each night, so it's bounded by time rather than by attempts. */
    public function retryUntil(): \DateTimeInterface
    {
        return now()->addDays(7);
    }

    public function handle(Outreach $outreach): void
    {
        $campaign = Campaign::withoutGlobalScopes()->find($this->campaignId);
        if (! $campaign || $campaign->status !== Campaign::SENDING) {
            return;
        }
        $organization = Organization::query()->find($campaign->organization_id);
        if (! $organization) {
            return;
        }
        if (! $outreach->sendBatch($campaign)) {
            $this->release((int) max(60, now()->diffInSeconds($outreach->nextWindow($organization))));

            return;
        }
        if ($campaign->refresh()->status === Campaign::SENDING) {
            $this->release(30);
        }
    }
}
