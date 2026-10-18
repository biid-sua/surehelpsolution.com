<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Services\Metrics\ResultsReport;
use App\Services\Metrics\SourceReport;
use App\Support\Money;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A month's Results as CSV (spec §91, CLI-07): the same numbers as the page and the PDF, one per row.
 */
class ResultsCsvController extends Controller
{
    public function __invoke(Request $request, CurrentOrganization $current, ResultsReport $report, SourceReport $sources): StreamedResponse
    {
        $organization = $current->get();
        abort_if($organization === null, 403);
        $month = $request->query('month');
        $period = $report->month($organization, is_string($month) ? $month : null);
        $r = $report->compute($organization, $period['start'], $period['end']);
        $money = fn (?int $cents) => $cents === null ? '' : Money::format($cents, $organization->currency);

        $rows = [
            ['Summary', 'Calls', $r['calls'], ''],
            ['Summary', 'Answered for you', $r['answered'], $r['change']['answered'] ?? ''],
            ['Summary', 'Answered after hours', $r['after_hours'] ?? '', $r['change']['after_hours'] ?? ''],
            ['Summary', 'New leads', $r['leads'], $r['change']['leads'] ?? ''],
            ['Summary', 'Calls that booked a job', $r['booked'], $r['change']['booked'] ?? ''],
            ['Summary', 'Appointments booked', $r['appointments'], ''],
            ['Summary', 'Average job value', $money($r['job_value_cents']), ''],
            ['Summary', 'Estimated revenue', $money($r['revenue_cents']), ''],
        ];
        foreach ($r['outcomes'] as $outcome) {
            $rows[] = ['Call outcomes', $outcome['label'], $outcome['count'], ''];
        }
        foreach ($r['appointment_outcomes'] as $outcome) {
            $rows[] = ['Appointment outcomes', $outcome['label'], $outcome['count'], ''];
        }
        foreach ($r['reasons'] as $reason => $count) {
            $rows[] = ['Top reasons for calling', $reason, $count, ''];
        }
        $s = $sources->compute($organization, $period['start'], $period['end']);
        foreach (SourceReport::LEAD_SOURCES as $key => $label) {
            $rows[] = ['New customers by source', $label, $s['leads'][$key], ''];
        }
        $rows[] = ['New customers by source', 'Added or imported by your team', $s['added_by_team'], ''];
        foreach (SourceReport::BOOKING_SOURCES as $key => $label) {
            $rows[] = ['Bookings by source', $label, $s['bookings'][$key], ''];
        }
        $rows[] = ['Website', 'Pages viewed with the SureHelp snippet', $s['website_views'] ?? '', $s['change']['website_views'] ?? ''];
        $rows[] = ['Website', 'New customers from the website', $s['website_leads'], $s['change']['website_leads'] ?? ''];
        $rows[] = ['Social media', 'Posts published', $s['social_posts'], $s['change']['social_posts'] ?? ''];
        $rows[] = ['Social media', 'Facebook and Instagram conversations', $s['social_messages'], $s['change']['social_messages'] ?? ''];
        if ($r['busiest']) {
            $rows[] = ['Busiest time', $r['busiest'], '', ''];
        }

        return response()->streamDownload(function () use ($rows, $period, $organization) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Results for '.$organization->name, $period['label'], '', '']);
            fputcsv($out, ['Section', 'Item', 'Value', 'Change vs previous month (%)']);
            foreach ($rows as $row) {
                fputcsv($out, array_map([CallExportController::class, 'safe'], $row));
            }
            fclose($out);
        }, str($organization->name)->slug().'-results-'.$period['key'].'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
