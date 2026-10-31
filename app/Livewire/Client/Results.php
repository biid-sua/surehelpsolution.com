<?php

namespace App\Livewire\Client;

use App\Livewire\Concerns\ScopedToOrganization;
use App\Models\CallLog;
use App\Models\Organization;
use App\Models\Review;
use App\Services\Billing\FeatureAccess;
use App\Services\Metrics\ResultsReport;
use App\Services\Metrics\SourceReport;
use App\Services\Metrics\ValueReport;
use App\Services\Reviews\ReviewDesk;
use App\Services\Surveys\SurveyDesk;
use App\Support\Audit\Audit;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Results (spec RPT-01): what SureHelp did for the business each month, and what it was likely worth.
 */
#[Layout('layouts.portal', ['portal' => 'client'])]
#[Title('Results')]
class Results extends Component
{
    use ScopedToOrganization;

    #[Url(except: '')]
    public string $month = '';

    public string $jobValue = '';

    public function mount(): void
    {
        $organization = $this->organization();
        $this->authorize('reports.view', $organization);
        $this->jobValue = $organization->average_job_value_cents ? Money::toInput($organization->average_job_value_cents) : '';
    }

    public function saveJobValue(Audit $audit): void
    {
        $organization = $this->organization();
        $this->authorize('organization.update', $organization);
        $this->resetValidation();
        try {
            $cents = filled($this->jobValue) ? Money::parse($this->jobValue) : null;
        } catch (\InvalidArgumentException) {
            throw ValidationException::withMessages(['jobValue' => 'Enter an amount like 250 or 250.00.']);
        }
        $organization->forceFill(['average_job_value_cents' => $cents])->save();
        $audit->changes('organization.updated', $organization, ['average_job_value_cents']);
        $this->dispatch('toast', type: 'success', message: 'Average job value saved.');
    }

    public function render(ResultsReport $report, SourceReport $sources): View
    {
        $organization = $this->organization();
        $period = $report->month($organization, $this->month ?: null);
        // Months to choose from: since the business joined, or since its first call if that came earlier.
        $firstCall = CallLog::query()->forOrganization($organization)->min('created_at');
        $first = CarbonImmutable::instance($organization->created_at)->min($firstCall ? CarbonImmutable::parse($firstCall) : now())
            ->setTimezone($organization->timezoneOrDefault())->startOfMonth();
        $months = [];
        for ($m = CarbonImmutable::now($organization->timezoneOrDefault())->startOfMonth(), $i = 0; $m->greaterThanOrEqualTo($first) && $i < 24; $m = $m->subMonthNoOverflow(), $i++) {
            $months[$m->format('Y-m')] = $m->format('F Y');
        }

        return view('livewire.client.results', [
            'organization' => $organization,
            'period' => $period,
            'months' => $months,
            'r' => $report->compute($organization, $period['start'], $period['end']),
            's' => $sources->compute($organization, $period['start'], $period['end']),
            'leadSources' => SourceReport::LEAD_SOURCES,
            'bookingSources' => SourceReport::BOOKING_SOURCES,
            'isCurrent' => $period['key'] === CarbonImmutable::now($organization->timezoneOrDefault())->format('Y-m'),
            'canEditValue' => auth()->user()->can('organization.update', $organization),
            'reviews' => $this->reviews($organization, $period['start'], $period['end']),
            // The dollars report (D107), for businesses that have it.
            'value' => app(FeatureAccess::class)->allows($organization, 'value_report') ? app(ValueReport::class)->compute($organization, $period['start'], $period['end']) : null,
            'surveys' => app(SurveyDesk::class)->summary($organization, CarbonImmutable::instance($period['start'])->utc(), CarbonImmutable::instance($period['end'])->utc()),
        ]);
    }

    /**
     * Google reviews left in the month (D83), or null when none are connected or ever came in.
     *
     * @return array{count: int, average: ?float, replied: int, rating: ?float}|null
     */
    private function reviews(Organization $organization, CarbonInterface $start, CarbonInterface $end): ?array
    {
        if (! auth()->user()->can('reviews.view', $organization) || ! Review::query()->forOrganization($organization)->exists()) {
            return null;
        }
        $month = Review::query()->forOrganization($organization)->whereBetween('reviewed_at', [CarbonImmutable::instance($start)->utc(), CarbonImmutable::instance($end)->utc()]);
        $summary = app(ReviewDesk::class)->summary($organization, 1);

        return [
            'count' => (clone $month)->count(),
            'average' => ($avg = (clone $month)->avg('rating')) !== null ? round((float) $avg, 1) : null,
            'replied' => (clone $month)->whereNotNull('replied_at')->count(),
            'rating' => $summary['google_average'] ?? $summary['average'],
        ];
    }
}
