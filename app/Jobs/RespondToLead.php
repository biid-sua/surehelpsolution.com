<?php

namespace App\Jobs;

use App\Models\Lead;
use App\Services\Leads\LeadResponder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * The instant reply to a new lead (D109). Runs right after the request that brought the lead in;
 * a text held back for the night runs again in the morning.
 */
class RespondToLead implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public function __construct(public readonly int $leadId, public readonly bool $morning = false) {}

    public function handle(LeadResponder $responder): void
    {
        $lead = Lead::withoutGlobalScopes()->find($this->leadId);
        if ($lead) {
            $responder->respond($lead, $this->morning);
        }
    }
}
