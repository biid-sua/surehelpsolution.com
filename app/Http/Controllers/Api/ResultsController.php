<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Organization;
use App\Services\Billing\FeatureAccess;
use App\Services\Metrics\ResultsPdf;
use App\Services\Metrics\ResultsReport;
use App\Services\Metrics\SourceReport;
use App\Services\Metrics\ValueReport;
use App\Support\Tenancy\CurrentOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Results in the app (API-10): the same numbers as the web Results page and PDF, for one month
 * (`?month=YYYY-MM`, this month by default), including where customers came from (D68).
 */
class ResultsController extends Controller
{
    public function show(Request $request, CurrentOrganization $current, ResultsReport $report, SourceReport $sources): JsonResponse
    {
        $organization = $current->get();
        abort_if($organization === null, 403);
        $request->validate(['month' => ['nullable', 'date_format:Y-m']]);
        $period = $report->month($organization, $request->query('month') ?: null);
        $r = $report->compute($organization, $period['start'], $period['end']);
        $s = $sources->compute($organization, $period['start'], $period['end']);

        return ApiResponse::success([
            'month' => $period['key'],
            'label' => $period['label'],
            'is_current' => $period['key'] === CarbonImmutable::now($organization->timezoneOrDefault())->format('Y-m'),
            'currency' => $organization->currency,
            'summary' => [
                'calls' => $r['calls'],
                'answered' => $r['answered'],
                'after_hours' => $r['after_hours'],
                'leads' => $r['leads'],
                'booked' => $r['booked'],
                'appointments' => $r['appointments'],
                'job_value_cents' => $r['job_value_cents'],
                'revenue_cents' => $r['revenue_cents'],
                'change' => $r['change'],
            ],
            'call_outcomes' => array_map(fn ($o, $k) => ['key' => $k] + $o, $r['outcomes'], array_keys($r['outcomes'])),
            'appointment_outcomes' => array_map(fn ($o, $k) => ['key' => $k] + $o, $r['appointment_outcomes'], array_keys($r['appointment_outcomes'])),
            'reasons' => collect($r['reasons'])->map(fn ($n, $reason) => ['reason' => $reason, 'count' => $n])->values(),
            'busiest' => $r['busiest'],
            'heatmap' => $r['heatmap'],
            'sources' => [
                'leads' => collect(SourceReport::LEAD_SOURCES)->map(fn ($label, $key) => ['key' => $key, 'label' => $label, 'count' => $s['leads'][$key]])->values(),
                'leads_total' => $s['leads_total'],
                'added_by_team' => $s['added_by_team'],
                'bookings' => collect(SourceReport::BOOKING_SOURCES)->map(fn ($label, $key) => ['key' => $key, 'label' => $label, 'count' => $s['bookings'][$key]])->values(),
                'bookings_total' => $s['bookings_total'],
                'website_views' => $s['website_views'],
                'website_leads' => $s['website_leads'],
                'social_posts' => $s['social_posts'],
                'social_messages' => $s['social_messages'],
                'change' => $s['change'],
            ],
            // The dollars report (D107); null for businesses that don't have it. Added, not changed (D7).
            'value' => $this->value($organization, $period),
            'pdf_url' => route('api.client.results.pdf', ['month' => $period['key']]),
        ]);
    }

    /**
     * @param  array{start: CarbonImmutable, end: CarbonImmutable}  $period
     * @return array<string, mixed>|null
     */
    private function value(Organization $organization, array $period): ?array
    {
        if (! app(FeatureAccess::class)->allows($organization, 'value_report')) {
            return null;
        }
        $v = app(ValueReport::class)->compute($organization, $period['start'], $period['end']);

        return [
            'value_cents' => $v['value_cents'],
            'jobs' => $v['jobs'],
            'priced_jobs' => $v['priced_jobs'],
            'estimated_jobs' => $v['estimated_jobs'],
            'after_hours_jobs' => $v['after_hours_jobs'],
            'after_hours_value_cents' => $v['after_hours_value_cents'],
            'cost_cents' => $v['cost_cents'],
            'cost_source' => $v['cost_source'],
            'multiple' => $v['multiple'],
            'since_value_cents' => $v['since_value_cents'],
            'since' => $v['since']?->format('Y-m'),
        ];
    }

    public function pdf(Request $request, CurrentOrganization $current, ResultsPdf $pdf): Response
    {
        $organization = $current->get();
        abort_if($organization === null, 403);
        $request->validate(['month' => ['nullable', 'date_format:Y-m']]);
        $month = $request->query('month') ?: null;

        return response($pdf->render($organization, $month), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$pdf->filename($organization, $month).'"',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
