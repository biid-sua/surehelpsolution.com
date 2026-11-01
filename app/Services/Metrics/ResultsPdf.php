<?php

namespace App\Services\Metrics;

use App\Models\Organization;
use App\Services\Billing\FeatureAccess;
use Barryvdh\DomPDF\Facade\Pdf;

/**
 * The monthly results report as a PDF (spec RPT-02), for download and the 1st-of-the-month email.
 */
class ResultsPdf
{
    public function __construct(private readonly ResultsReport $report, private readonly SourceReport $sources) {}

    public function render(Organization $organization, ?string $month): string
    {
        $period = $this->report->month($organization, $month);

        return Pdf::loadView('reports.monthly', [
            'organization' => $organization,
            'period' => $period,
            'r' => $this->report->compute($organization, $period['start'], $period['end']),
            's' => $this->sources->compute($organization, $period['start'], $period['end']),
            'value' => app(FeatureAccess::class)->allows($organization, 'value_report') ? app(ValueReport::class)->compute($organization, $period['start'], $period['end']) : null,
        ])->setPaper('letter')->output();
    }

    public function filename(Organization $organization, ?string $month): string
    {
        return str($organization->name)->slug().'-results-'.$this->report->month($organization, $month)['key'].'.pdf';
    }
}
