<?php

namespace App\Services\Metrics;

use App\Enums\AppointmentStatus;
use App\Enums\OutcomeCategory;
use App\Models\Appointment;
use App\Models\BusinessHour;
use App\Models\CallLog;
use App\Models\Customer;
use App\Models\Organization;
use App\Services\Business\BusinessHours;
use App\Services\Calls\CallOutcomes;
use Carbon\CarbonImmutable;

/**
 * What SureHelp did for a business in a period (spec RPT-01/02): calls answered, leads captured,
 * jobs booked, after-hours calls caught and the revenue those bookings are likely worth.
 * Every number is counted from real records; only revenue is an estimate, and it says so.
 */
class ResultsReport
{
    public function __construct(private readonly CallOutcomes $outcomes, private readonly BusinessHours $hours) {}

    /**
     * Calendar month in the business's timezone ("2026-10"), or null for the current month.
     *
     * @return array{start: CarbonImmutable, end: CarbonImmutable, label: string, key: string}
     */
    public function month(Organization $organization, ?string $key = null): array
    {
        $timezone = $organization->timezoneOrDefault();
        $start = $key && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $key)
            ? CarbonImmutable::createFromFormat('!Y-m', $key, $timezone)
            : CarbonImmutable::now($timezone)->startOfMonth();

        return ['start' => $start, 'end' => $start->endOfMonth(), 'label' => $start->format('F Y'), 'key' => $start->format('Y-m')];
    }

    /**
     * @return array{
     *     calls: int, answered: int, after_hours: int|null, leads: int, booked: int, appointments: int,
     *     revenue_cents: int|null, job_value_cents: int|null,
     *     outcomes: array<string, array{label: string, count: int}>,
     *     appointment_outcomes: array<string, array{label: string, count: int}>,
     *     reasons: array<string, int>, heatmap: array<int, array<int, int>>, busiest: string|null,
     *     change: array<string, int|null>
     * }
     */
    public function compute(Organization $organization, CarbonImmutable $start, CarbonImmutable $end, bool $compare = true): array
    {
        $timezone = $organization->timezoneOrDefault();
        $calls = CallLog::query()->forOrganization($organization)
            ->whereBetween('created_at', [$start->utc(), $end->utc()])
            ->get(['id', 'created_at', 'call_outcome', 'reason_for_call', 'customer_id']);

        $category = [];
        foreach (OutcomeCategory::cases() as $case) {
            foreach ($this->outcomes->keys($organization, $case) as $key) {
                $category[$key] = $case;
            }
        }
        $categoryOf = fn (CallLog $c) => $category[$c->call_outcome] ?? OutcomeCategory::Other;

        $outcomes = [];
        foreach (OutcomeCategory::cases() as $case) {
            $outcomes[$case->value] = ['label' => $case->label(), 'count' => 0];
        }
        $heatmap = array_fill(0, 7, array_fill(0, 24, 0));
        // Without opening hours there is no "after hours" to count.
        $afterHours = BusinessHour::query()->forOrganization($organization)->exists() ? 0 : null;
        foreach ($calls as $call) {
            $outcomes[$categoryOf($call)->value]['count']++;
            $local = CarbonImmutable::instance($call->created_at)->setTimezone($timezone);
            $heatmap[(int) $local->format('w')][(int) $local->format('G')]++;
            if ($afterHours !== null && ! in_array($categoryOf($call), [OutcomeCategory::Spam, OutcomeCategory::Missed], true) && ! $this->hours->isOpenAt($organization, $local)) {
                $afterHours++;
            }
        }

        $answered = $calls->count() - $outcomes[OutcomeCategory::Missed->value]['count'] - $outcomes[OutcomeCategory::Spam->value]['count'];
        $booked = $outcomes[OutcomeCategory::Booked->value]['count'];
        $jobValue = $organization->average_job_value_cents;

        $reasons = $calls->filter(fn (CallLog $c) => filled($c->reason_for_call) && $categoryOf($c) !== OutcomeCategory::Spam)
            ->countBy('reason_for_call')->sortDesc()->take(5)
            ->mapWithKeys(fn (int $n, string $reason) => [CallLog::REASONS[$reason] ?? str($reason)->headline()->toString() => $n])->all();

        $busiest = null;
        $max = 0;
        foreach ($heatmap as $day => $hours) {
            foreach ($hours as $hour => $n) {
                if ($n > $max) {
                    $max = $n;
                    $busiest = CarbonImmutable::now()->startOfWeek(CarbonImmutable::SUNDAY)->addDays($day)->format('l').'s around '.CarbonImmutable::createFromTime($hour)->format('ga');
                }
            }
        }

        $result = [
            'calls' => $calls->count(),
            'answered' => max(0, $answered),
            'after_hours' => $afterHours,
            // New callers who became customer records while we answered for you.
            'leads' => Customer::query()->forOrganization($organization)->whereBetween('created_at', [$start->utc(), $end->utc()])->count(),
            'booked' => $booked,
            'appointments' => Appointment::query()->forOrganization($organization)->whereBetween('created_at', [$start->utc(), $end->utc()])
                ->where('status', '!=', 'cancelled')->count(),
            'revenue_cents' => $jobValue ? $booked * $jobValue : null,
            'job_value_cents' => $jobValue,
            'outcomes' => $outcomes,
            'appointment_outcomes' => $this->appointmentOutcomes($organization, $start, $end),
            'reasons' => $reasons,
            'heatmap' => $heatmap,
            'busiest' => $busiest,
            'change' => [],
        ];

        if ($compare) {
            // The period just before, of the same length (the previous month for a month).
            $wholeMonth = $start->equalTo($start->startOfMonth()) && $end->equalTo($start->endOfMonth());
            $previousStart = $wholeMonth ? $start->subMonthNoOverflow() : $start->subSeconds((int) $start->diffInSeconds($end) + 1);
            $previous = $this->compute($organization, $previousStart, $start->subSecond(), false);
            foreach (['answered', 'after_hours', 'leads', 'booked'] as $key) {
                $result['change'][$key] = ($previous[$key] ?? 0) > 0 && $result[$key] !== null ? (int) round(($result[$key] - $previous[$key]) / $previous[$key] * 100) : null;
            }
        }

        return $result;
    }

    /**
     * What happened to the appointments that fell in the period (by their start time).
     *
     * @return array<string, array{label: string, count: int}>
     */
    private function appointmentOutcomes(Organization $organization, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $counts = Appointment::query()->forOrganization($organization)->whereBetween('starts_at', [$start->utc(), $end->utc()])
            ->toBase()->selectRaw('status, COUNT(*) as n')->groupBy('status')->pluck('n', 'status');

        $groups = [
            'completed' => ['Completed', [AppointmentStatus::Completed]],
            'upcoming' => ['Booked, not yet marked done', [AppointmentStatus::Confirmed, AppointmentStatus::Pending, AppointmentStatus::Tentative]],
            'no_show' => ['No-show', [AppointmentStatus::NoShow]],
            'cancelled' => ['Cancelled', [AppointmentStatus::Cancelled]],
        ];
        $result = [];
        foreach ($groups as $key => [$label, $statuses]) {
            $result[$key] = ['label' => $label, 'count' => (int) collect($statuses)->sum(fn (AppointmentStatus $s) => (int) ($counts[$s->value] ?? 0))];
        }

        return $result;
    }
}
