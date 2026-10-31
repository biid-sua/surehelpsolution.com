<?php

namespace App\Console\Commands;

use App\Models\BusinessService;
use App\Models\Organization;
use App\Services\Billing\FeatureAccess;
use App\Services\Marketing\Outreach;
use Illuminate\Console\Command;

/** Hourly: service reminders that are due, for businesses where it's daytime (D116). */
class SendServiceReminders extends Command
{
    protected $signature = 'marketing:reminders';

    protected $description = 'Send service reminders that are due ("time for your yearly tune-up")';

    public function handle(Outreach $outreach, FeatureAccess $features): int
    {
        $ids = BusinessService::withoutGlobalScopes()->where('reminder_months', '>', 0)->distinct()->pluck('organization_id');
        $sent = 0;
        Organization::query()->whereIn('id', $ids)->whereIn('status', ['active', 'onboarding'])->where('is_demo', false)
            ->each(function (Organization $organization) use ($outreach, $features, &$sent) {
                if ($features->allows($organization, 'campaigns')) {
                    $sent += $outreach->sendReminders($organization);
                }
            });
        $this->info("Sent {$sent} service reminder(s).");

        return self::SUCCESS;
    }
}
