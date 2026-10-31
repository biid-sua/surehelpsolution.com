<?php

namespace App\Console\Commands;

use App\Actions\Notifications\NotifyOrganization;
use App\Enums\OrganizationStatus;
use App\Models\Organization;
use App\Notifications\MonthlyResults;
use App\Services\Billing\FeatureAccess;
use App\Services\Metrics\ResultsReport;
use App\Services\Metrics\ValueReport;
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
        Organization::query()->external()->where('status', OrganizationStatus::Active)->whereNotNull('setup_completed_at')
            ->each(function (Organization $organization) use ($report, $notify, &$sent) {
                $month = $this->option('month') ?: CarbonImmutable::now($organization->timezoneOrDefault())->subMonthNoOverflow()->format('Y-m');
                if ($organization->last_report_month !== null && $organization->last_report_month >= $month) {
                    return;
                }

                $period = $report->month($organization, $month);
                $r = $report->compute($organization, $period['start'], $period['end'], compare: false);
                $summary = array_intersect_key($r, array_flip(['answered', 'booked', 'leads', 'after_hours', 'revenue_cents']));
                // The dollars report (D107): what we were worth and the return, for businesses that have it.
                if (app(FeatureAccess::class)->allows($organization, 'value_report')) {
                    $value = app(ValueReport::class)->compute($organization, $period['start'], $period['end']);
                    $summary += ['value_cents' => $value['value_cents'], 'cost_cents' => $value['cost_cents'], 'multiple' => $value['multiple']];
                }
                $notify->handle($organization, new MonthlyResults($organization, $period['key'], $period['label'], $summary), 'reports.view');
                $organization->forceFill(['last_report_month' => $period['key']])->save();
                $sent++;
            });

        $this->info("Monthly reports sent to {$sent} ".str('business')->plural($sent).'.');

        return self::SUCCESS;
    }
}
