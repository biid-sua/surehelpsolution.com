<?php

namespace App\Console\Commands;

use App\Actions\Notifications\NotifyOrganization;
use App\Enums\OrganizationStatus;
use App\Models\Organization;
use App\Notifications\MonthlyResults;
use App\Services\Metrics\ResultsReport;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * On the 1st: last month's results report to every active business (spec RPT-02).
 * Safe to run again: a business gets each month's report once.
 */
class SendMonthlyReports extends Command
{
    protected $signature = 'reports:monthly {--month= : YYYY-MM, defaults to last month}';

    protected $description = 'Email last month\'s results report to each business';

    public function handle(ResultsReport $report, NotifyOrganization $notify): int
    {
        $sent = 0;
        Organization::query()->where('status', OrganizationStatus::Active)->whereNotNull('setup_completed_at')
            ->each(function (Organization $organization) use ($report, $notify, &$sent) {
                $month = $this->option('month') ?: CarbonImmutable::now($organization->timezoneOrDefault())->subMonthNoOverflow()->format('Y-m');
                if ($organization->last_report_month !== null && $organization->last_report_month >= $month) {
                    return;
                }

                $period = $report->month($organization, $month);
                $r = $report->compute($organization, $period['start'], $period['end'], compare: false);
                $summary = array_intersect_key($r, array_flip(['answered', 'booked', 'leads', 'after_hours', 'revenue_cents']));
                $notify->handle($organization, new MonthlyResults($organization, $period['key'], $period['label'], $summary), 'reports.view');
                $organization->forceFill(['last_report_month' => $period['key']])->save();
                $sent++;
            });

        $this->info("Monthly reports sent to {$sent} ".str('business')->plural($sent).'.');

        return self::SUCCESS;
    }
}
