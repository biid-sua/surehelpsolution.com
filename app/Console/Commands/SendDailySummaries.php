<?php

namespace App\Console\Commands;

use App\Enums\OrganizationStatus;
use App\Models\User;
use App\Notifications\DailySummaryEmail;
use App\Services\Metrics\DailySummary;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Every 15 minutes: send the daily summary to people whose chosen time has just come, in their own
 * timezone (spec NTF-04). Once a day per person; quiet days with nothing to report send nothing.
 */
class SendDailySummaries extends Command
{
    protected $signature = 'notifications:daily-summary';

    protected $description = 'Send the daily summary email to people whose time has come';

    /** A run that was missed can still send within this many minutes of the chosen time. */
    private const WINDOW_MINUTES = 180;

    public function handle(DailySummary $daily): int
    {
        $sent = 0;
        $summaries = [];

        User::query()->where('role', 'client')->where('is_active', true)
            ->whereHas('organizations', fn ($q) => $q->where('organization_user.status', 'active')->where('organizations.status', OrganizationStatus::Active->value))
            ->each(function (User $user) use ($daily, &$sent, &$summaries) {
                $time = $user->dailySummaryTime();
                $organization = $user->primaryOrganization();
                if (! $time || ! $organization || ! $user->hasPermissionIn('dashboard.view', $organization)) {
                    return;
                }

                $now = CarbonImmutable::now($user->timezoneOrDefault());
                $due = $now->setTimeFromTimeString($time);
                if ($now->lessThan($due) || $now->diffInMinutes($due, true) > self::WINDOW_MINUTES || $user->last_summary_on?->toDateString() === $now->toDateString()) {
                    return;
                }

                $user->forceFill(['last_summary_on' => $now->toDateString()])->saveQuietly();
                $summary = $summaries[$organization->id] ??= $daily->for($organization);
                if ($daily->isEmpty($summary)) {
                    return;
                }
                $user->notify(new DailySummaryEmail($organization, $summary));
                $sent++;
            });

        $this->info("Daily summary sent to {$sent} ".str('person')->plural($sent).'.');

        return self::SUCCESS;
    }
}
