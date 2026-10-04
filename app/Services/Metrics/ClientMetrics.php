<?php

namespace App\Services\Metrics;

use App\Enums\AppointmentStatus;
use App\Enums\OutcomeCategory;
use App\Models\Appointment;
use App\Models\CallLog;
use App\Models\Organization;
use App\Models\Task;
use App\Services\Calls\CallOutcomes;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Database\Eloquent\Collection;

/**
 * Real, organization-scoped numbers for the client dashboard (spec §8.1).
 * Nothing here is estimated or fabricated (spec §95): every value is a query.
 */
class ClientMetrics
{
    public const PERIODS = ['today' => 'Today', 'week' => 'This week', 'month' => 'This month'];

    /**
     * @return array{start: CarbonImmutable, end: CarbonImmutable, previousStart: CarbonImmutable, previousEnd: CarbonImmutable, timezone: string}
     */
    public function range(Organization $organization, string $period): array
    {
        $timezone = $organization->timezone ?: config('app.timezone');
        $now = CarbonImmutable::now($timezone);

        [$start, $end, $previousStart] = match ($period) {
            'week' => [$now->startOfWeek(), $now->endOfWeek(), $now->subWeek()->startOfWeek()],
            'month' => [$now->startOfMonth(), $now->endOfMonth(), $now->subMonthNoOverflow()->startOfMonth()],
            default => [$now->startOfDay(), $now->endOfDay(), $now->subDay()->startOfDay()],
        };

        return [
            'start' => $start,
            'end' => $end,
            'previousStart' => $previousStart,
            'previousEnd' => $start->subSecond(),
            'timezone' => $timezone,
        ];
    }

    /**
     * KPI values for the period with % change against the previous one.
     *
     * @return array<string, array{value: int, change: int|null}>
     */
    public function kpis(Organization $organization, string $period): array
    {
        $range = $this->range($organization, $period);
        $current = $this->counts($organization, $range['start'], $range['end']);
        $previous = $this->counts($organization, $range['previousStart'], $range['previousEnd']);

        $kpis = [];
        foreach ($current as $key => $value) {
            $kpis[$key] = ['value' => $value, 'change' => $this->change($value, $previous[$key])];
        }

        // Follow-ups are a live backlog, not a period figure.
        $kpis['follow_ups'] = [
            'value' => Task::query()->forOrganization($organization)->open()->followUps()->count(),
            'change' => null,
        ];

        return $kpis;
    }

    /**
     * Calls per hour (today) or per day (week/month), in the business's timezone.
     *
     * @return array{labels: list<string>, data: list<int>}
     */
    public function series(Organization $organization, string $period): array
    {
        $range = $this->range($organization, $period);
        $timestamps = CallLog::query()->forOrganization($organization)
            ->whereBetween('created_at', [$range['start']->utc(), $range['end']->utc()])
            ->pluck('created_at');

        if ($period === 'today') {
            $buckets = array_fill(0, 24, 0);
            foreach ($timestamps as $at) {
                $buckets[(int) $at->copy()->setTimezone($range['timezone'])->format('G')]++;
            }

            return [
                'labels' => array_map(fn (int $h) => CarbonImmutable::createFromTime($h)->format('ga'), range(0, 23)),
                'data' => array_values($buckets),
            ];
        }

        $buckets = [];
        foreach (CarbonPeriod::create($range['start'], $range['end']->startOfDay()) as $day) {
            $buckets[$day->format('Y-m-d')] = 0;
        }
        foreach ($timestamps as $at) {
            $key = $at->copy()->setTimezone($range['timezone'])->format('Y-m-d');
            if (isset($buckets[$key])) {
                $buckets[$key]++;
            }
        }

        return [
            'labels' => array_map(fn (string $d) => CarbonImmutable::parse($d)->format($period === 'week' ? 'D' : 'M j'), array_keys($buckets)),
            'data' => array_values($buckets),
        ];
    }

    /**
     * Today's appointments and legacy service visits, as display rows, in the business's timezone.
     *
     * @return \Illuminate\Support\Collection<int, array{time: string, title: string, subtitle: string, url: string, status: string, tone: string}>
     */
    public function todaysSchedule(Organization $organization): \Illuminate\Support\Collection
    {
        $timezone = $organization->timezoneOrDefault();
        $today = CarbonImmutable::now($timezone);

        $appointments = Appointment::query()->forOrganization($organization)
            ->with('customer:id,first_name,last_name,company,phone,phone_e164')
            ->where('status', '!=', AppointmentStatus::Cancelled->value)
            ->whereBetween('starts_at', [$today->startOfDay()->utc(), $today->endOfDay()->utc()])
            ->orderBy('starts_at')
            ->limit(10)
            ->get()
            ->map(fn (Appointment $a) => [
                'time' => $a->starts_at->setTimezone($timezone)->format('g:i A'),
                'title' => $a->title,
                'subtitle' => $a->address ?? $a->customer?->displayPhone() ?? '',
                'url' => route('app.appointments.index', ['appointment' => $a->ulid]),
                'status' => $a->status->label(),
                'tone' => $a->status->tone(),
            ]);

        // Service visits noted on calls before appointments existed (D18).
        $visits = CallLog::query()->forOrganization($organization)
            ->whereDate('service_date', $today->toDateString())
            ->orderBy('service_window')
            ->limit(10)
            ->get()
            ->map(fn (CallLog $call) => [
                'time' => $call->service_window ?: 'Any time',
                'title' => CallLog::display($call->caller_name),
                'subtitle' => CallLog::display($call->service_location),
                'url' => route('app.calls.show', $call->call_id),
                'status' => $call->statusLabel(),
                'tone' => $call->statusTone(),
            ]);

        return $appointments->concat($visits)->take(10)->values();
    }

    /**
     * @return Collection<int, CallLog>
     */
    public function recentCalls(Organization $organization, int $limit = 6): Collection
    {
        return CallLog::query()->forOrganization($organization)
            ->latest('created_at')->latest('id')
            ->limit($limit)
            ->get();
    }

    /**
     * One aggregate query for all period counts (spec §65: no per-metric round trips).
     *
     * @return array{calls: int, service_requests: int, scheduled: int, missed: int}
     */
    private function counts(Organization $organization, CarbonImmutable $start, CarbonImmutable $end): array
    {
        // Booked and missed follow the business's outcome categories, including its own outcomes.
        $outcomes = app(CallOutcomes::class);
        $booked = $outcomes->keys($organization, OutcomeCategory::Booked) ?: ['__none__'];
        $missed = $outcomes->keys($organization, OutcomeCategory::Missed) ?: ['__none__'];
        $in = fn (array $keys) => implode(', ', array_fill(0, count($keys), '?'));

        $row = CallLog::query()->forOrganization($organization)
            ->whereBetween('created_at', [$start->utc(), $end->utc()])
            ->toBase()
            ->selectRaw(
                'COUNT(*) as calls, '
                .'SUM(CASE WHEN service_request = 1 THEN 1 ELSE 0 END) as service_requests, '
                .'SUM(CASE WHEN call_outcome IN ('.$in($booked).') OR service_date IS NOT NULL THEN 1 ELSE 0 END) as scheduled, '
                .'SUM(CASE WHEN call_outcome IN ('.$in($missed).') THEN 1 ELSE 0 END) as missed',
                [...$booked, ...$missed],
            )
            ->first();

        return [
            'calls' => (int) ($row->calls ?? 0),
            'service_requests' => (int) ($row->service_requests ?? 0),
            'scheduled' => (int) ($row->scheduled ?? 0),
            'missed' => (int) ($row->missed ?? 0),
        ];
    }

    private function change(int $current, int $previous): ?int
    {
        if ($previous === 0) {
            return $current === 0 ? 0 : null; // no baseline: don't invent a percentage
        }

        return (int) round((($current - $previous) / $previous) * 100);
    }
}
