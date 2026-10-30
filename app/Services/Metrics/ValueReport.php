<?php

namespace App\Services\Metrics;

use App\Enums\InvoiceStatus;
use App\Enums\OutcomeCategory;
use App\Enums\ServicePriceType;
use App\Models\Appointment;
use App\Models\BusinessHour;
use App\Models\CallLog;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Subscription;
use App\Services\Business\BusinessHours;
use App\Services\Calls\CallOutcomes;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * What SureHelp was worth to a business, in dollars (D107): the jobs we booked, what the business
 * paid us for the same month, and the return. Each booked job is valued at the price of the service
 * booked when it has one, otherwise at the owner's average job value. Counted from real calls,
 * bookings and invoices; only the value of a job is an estimate, and the page says so.
 */
class ValueReport
{
    public function __construct(private readonly CallOutcomes $outcomes, private readonly BusinessHours $hours) {}

    /**
     * @return array{
     *     value_cents: int|null, jobs: int, priced_jobs: int, estimated_jobs: int, unvalued_jobs: int,
     *     after_hours_jobs: int|null, after_hours_value_cents: int|null,
     *     cost_cents: int|null, cost_source: string|null, multiple: float|null,
     *     since_value_cents: int|null, since: CarbonImmutable|null
     * }
     */
    public function compute(Organization $organization, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $period = $this->value($organization, $this->bookedCalls($organization, $start, $end), withHours: true);
        $all = $this->value($organization, $this->bookedCalls($organization, null, $end), withHours: false);
        [$cost, $source] = $this->cost($organization, $start, $end);

        return [
            'value_cents' => $period['value_cents'],
            'jobs' => $period['jobs'],
            'priced_jobs' => $period['priced'],
            'estimated_jobs' => $period['estimated'],
            'unvalued_jobs' => $period['jobs'] - $period['priced'] - $period['estimated'],
            'after_hours_jobs' => $period['after_hours_jobs'],
            'after_hours_value_cents' => $period['after_hours_cents'],
            'cost_cents' => $cost,
            'cost_source' => $source,
            // "12× what you paid": only when both sides are known and there was something to pay.
            'multiple' => $cost && $period['value_cents'] !== null ? round($period['value_cents'] / $cost, 1) : null,
            'since_value_cents' => $all['value_cents'],
            'since' => $all['first'],
        ];
    }

    /** @return Collection<int, CallLog> */
    private function bookedCalls(Organization $organization, ?CarbonImmutable $start, CarbonImmutable $end): Collection
    {
        $booked = $this->outcomes->keys($organization, OutcomeCategory::Booked);

        return CallLog::query()->forOrganization($organization)->whereIn('call_outcome', $booked ?: ['-'])
            ->when($start, fn ($q) => $q->where('created_at', '>=', $start->utc()))
            ->where('created_at', '<=', $end->utc())
            ->orderBy('created_at')->get(['id', 'created_at']);
    }

    /**
     * @param  Collection<int, CallLog>  $calls
     * @return array{value_cents: int|null, jobs: int, priced: int, estimated: int, after_hours_jobs: int|null, after_hours_cents: int|null, first: CarbonImmutable|null}
     */
    private function value(Organization $organization, Collection $calls, bool $withHours): array
    {
        $average = $organization->average_job_value_cents ?: null;
        // The price of the service booked on each call, where the service has one.
        $prices = [];
        foreach ($calls->pluck('id')->chunk(500) as $ids) {
            Appointment::query()->forOrganization($organization)->whereIn('call_log_id', $ids->all())->whereNotNull('service_id')
                ->with('service:id,price_type,price_cents')->get(['id', 'call_log_id', 'service_id'])
                ->each(function (Appointment $a) use (&$prices) {
                    $service = $a->service;
                    if ($service && $service->price_cents && $service->price_type !== ServicePriceType::QuoteRequired) {
                        $prices[$a->call_log_id] ??= (int) $service->price_cents;
                    }
                });
        }

        $hours = $withHours && BusinessHour::query()->forOrganization($organization)->exists();
        $timezone = $organization->timezoneOrDefault();
        $total = 0;
        $priced = 0;
        $estimated = 0;
        $afterJobs = $hours ? 0 : null;
        $afterCents = $hours ? 0 : null;
        foreach ($calls as $call) {
            $cents = $prices[$call->id] ?? $average;
            if (isset($prices[$call->id])) {
                $priced++;
            } elseif ($average) {
                $estimated++;
            }
            $total += (int) $cents;
            if ($hours && ! $this->hours->isOpenAt($organization, CarbonImmutable::instance($call->created_at)->setTimezone($timezone))) {
                $afterJobs++;
                $afterCents += (int) $cents;
            }
        }

        return [
            // No value at all when nothing could be priced: "add your average job value" instead of $0.
            'value_cents' => $priced + $estimated > 0 ? $total : null,
            'jobs' => $calls->count(),
            'priced' => $priced,
            'estimated' => $estimated,
            'after_hours_jobs' => $afterJobs,
            'after_hours_cents' => $priced + $estimated > 0 ? $afterCents : null,
            'first' => $calls->first() ? CarbonImmutable::instance($calls->first()->created_at)->setTimezone($timezone) : null,
        ];
    }

    /**
     * What the business paid SureHelp for the period: its invoices for that period, or else its
     * current plan's monthly price.
     *
     * @return array{0: int|null, 1: string|null}
     */
    private function cost(Organization $organization, CarbonImmutable $start, CarbonImmutable $end): array
    {
        // Invoice periods are calendar dates. A monthly invoice counts in the month its period starts;
        // one for several months (a yearly plan) is spread evenly over the months it covers.
        [$first, $last] = [$start->toDateString(), $end->toDateString()];
        $invoiced = 0;
        Invoice::query()->forOrganization($organization)->where('status', '!=', InvoiceStatus::Void->value)
            ->whereDate('period_start', '<=', $last)
            ->where(fn ($q) => $q->whereDate('period_end', '>=', $first)->orWhere(fn ($q) => $q->whereNull('period_end')->whereDate('period_start', '>=', $first)))
            ->get(['id', 'total_cents', 'period_start', 'period_end'])
            ->each(function (Invoice $invoice) use ($first, &$invoiced) {
                $from = CarbonImmutable::parse($invoice->period_start->toDateString());
                $months = $invoice->period_end ? max(1, (int) round($from->diffInDays(CarbonImmutable::parse($invoice->period_end->toDateString())) / 30.44)) : 1;
                if ($months > 1 || $from->toDateString() >= $first) {
                    $invoiced += intdiv((int) $invoice->total_cents, $months);
                }
            });
        if ($invoiced > 0) {
            return [(int) $invoiced, 'invoices'];
        }
        $plan = Subscription::query()->forOrganization($organization)->current()->with('plan')->latest('id')->first()?->plan;

        return $plan && $plan->monthlyCents() > 0 ? [$plan->monthlyCents(), 'plan'] : [null, null];
    }
}
