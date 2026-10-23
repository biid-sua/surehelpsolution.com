<?php

namespace App\Livewire\Client;

use App\Enums\EscalationStatus;
use App\Models\Escalation;
use App\Models\Organization;
use App\Models\PaymentRequest;
use App\Models\Review;
use App\Models\Task;
use App\Services\Metrics\ResultsReport;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * All locations (D89): one owner's businesses side by side, for the month, with totals. Each row
 * only counts businesses the person can see results for.
 */
#[Layout('layouts.portal', ['portal' => 'client'])]
#[Title('All locations')]
class Locations extends Component
{
    #[Url]
    public string $month = '';

    public function mount(): void
    {
        abort_unless(auth()->user()->isClient() && auth()->user()->activeOrganizations()->count() > 1, 404);
    }

    public function render(ResultsReport $report): View
    {
        $user = auth()->user();
        $start = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $this->month) ? CarbonImmutable::createFromFormat('!Y-m', $this->month, 'UTC') : CarbonImmutable::now('UTC')->startOfMonth();
        $rows = $user->activeOrganizations()->filter(fn (Organization $o) => $user->can('reports.view', $o))->map(function (Organization $o) use ($report, $start) {
            $tz = $o->timezoneOrDefault();
            $from = CarbonImmutable::createFromFormat('!Y-m', $start->format('Y-m'), $tz);
            $to = $from->endOfMonth();
            $r = $report->compute($o, $from, $to);
            $reviews = Review::withoutGlobalScopes()->where('organization_id', $o->id)->whereBetween('reviewed_at', [$from->utc(), $to->utc()]);

            return [
                'organization' => $o,
                'answered' => $r['answered'], 'calls' => $r['calls'], 'leads' => $r['leads'], 'booked' => $r['booked'],
                'reviews' => (clone $reviews)->count(),
                'rating' => ($avg = (clone $reviews)->avg('rating')) !== null ? round((float) $avg, 1) : null,
                'collected' => (int) PaymentRequest::withoutGlobalScopes()->where('organization_id', $o->id)->where('status', 'paid')->whereBetween('paid_at', [$from->utc(), $to->utc()])->sum('amount_cents'),
                'open_tasks' => Task::withoutGlobalScopes()->where('organization_id', $o->id)->whereIn('status', ['open', 'in_progress'])->count(),
                'escalations' => Escalation::withoutGlobalScopes()->where('organization_id', $o->id)->where('status', '!=', EscalationStatus::Resolved->value)->count(),
            ];
        })->values();

        $months = [];
        for ($m = CarbonImmutable::now('UTC')->startOfMonth(), $i = 0; $i < 12; $m = $m->subMonthNoOverflow(), $i++) {
            $months[$m->format('Y-m')] = $m->format('F Y');
        }

        return view('livewire.client.locations', [
            'rows' => $rows,
            'totals' => collect(['answered', 'calls', 'leads', 'booked', 'reviews', 'collected', 'open_tasks', 'escalations'])->mapWithKeys(fn ($k) => [$k => $rows->sum($k)])->all(),
            'months' => $months,
            'current' => $start->format('Y-m'),
        ]);
    }
}
