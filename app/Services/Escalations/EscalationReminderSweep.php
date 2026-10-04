<?php

namespace App\Services\Escalations;

use App\Actions\Notifications\NotifyOrganization;
use App\Enums\EscalationPriority;
use App\Enums\EscalationStatus;
use App\Models\Escalation;
use App\Notifications\EscalationActivity;
use Illuminate\Support\Facades\DB;

/**
 * An urgent escalation nobody has acknowledged gets one more push after a few minutes (spec §25, NTF-03).
 * Claimed with a conditional update, so overlapping runs never send twice.
 */
class EscalationReminderSweep
{
    public const AFTER_MINUTES = 15;

    public function __construct(private readonly NotifyOrganization $notify) {}

    public function run(): int
    {
        $sent = 0;

        Escalation::withoutGlobalScopes()
            ->where('status', EscalationStatus::Open->value)
            ->where('priority', EscalationPriority::Urgent->value)
            ->whereNull('reminded_at')
            ->where('created_at', '<=', now()->subMinutes(self::AFTER_MINUTES))
            ->with(['organization', 'customer'])
            ->chunkById(200, function ($escalations) use (&$sent) {
                foreach ($escalations as $escalation) {
                    $claimed = DB::table('escalations')->where('id', $escalation->id)->whereNull('reminded_at')
                        ->where('status', EscalationStatus::Open->value)->update(['reminded_at' => now()]);

                    if ($claimed === 0 || ! $escalation->organization) {
                        continue;
                    }

                    try {
                        $this->notify->handle($escalation->organization, new EscalationActivity($escalation, EscalationActivity::REMINDER), 'escalations.view');
                        $sent++;
                    } catch (\Throwable $e) {
                        report($e);
                    }
                }
            });

        return $sent;
    }
}
