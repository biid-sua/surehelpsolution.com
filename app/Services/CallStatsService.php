<?php

namespace App\Services;

use App\Models\CallLog;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Single source of truth for agent call statistics.
 *
 * Both the KPI cards and the call volume chart read from here so they
 * always agree (FIX-01). Dates are immutable to avoid the Carbon mutation
 * bug that made every KPI range collapse to a single instant.
 */
class CallStatsService
{
    public const PERIODS = ['today', 'weekly', 'monthly'];

    /**
     * KPI values for a period plus % change against the previous period.
     */
    public function kpis(int|string $userId, string $period = 'today'): array
    {
        [$currentStart, $currentEnd, $previousStart, $previousEnd] = $this->ranges($period);

        $current = $this->counts($userId, $currentStart, $currentEnd);
        $previous = $this->counts($userId, $previousStart, $previousEnd);

        $conversion = $this->rate($current['service_requests'], $current['total_calls']);
        $conversionPrevious = $this->rate($previous['service_requests'], $previous['total_calls']);

        return [
            'total_calls' => [
                'value' => $current['total_calls'],
                'change' => $this->percentageChange($current['total_calls'], $previous['total_calls']),
            ],
            'service_requests' => [
                'value' => $current['service_requests'],
                'change' => $this->percentageChange($current['service_requests'], $previous['service_requests']),
            ],
            'conversion_rate' => [
                'value' => $conversion,
                'change' => $this->percentageChange($conversion, $conversionPrevious),
            ],
            'total_schedules' => [
                'value' => $current['schedules'],
                'change' => $this->percentageChange($current['schedules'], $previous['schedules']),
            ],
            'request_callback' => [
                'value' => $current['callbacks'],
                'change' => $this->percentageChange($current['callbacks'], $previous['callbacks']),
            ],
        ];
    }

    /**
     * Chart and analytics data. Every series is built from real call logs.
     */
    public function performance(int|string $userId): array
    {
        $now = CarbonImmutable::now();
        $today = $now->startOfDay();
        $weekStart = $now->startOfWeek();
        $monthStart = $now->startOfMonth();
        $sevenDaysAgo = $today->subDays(6);

        // One query covering everything we chart, bucketed in PHP so it works on any DB driver.
        $earliest = $monthStart->min($sevenDaysAgo);
        $logs = $this->baseQuery($userId)
            ->where('created_at', '>=', $earliest)
            ->get(['created_at', 'service_request', 'call_outcome', 'reason_for_call']);

        $dailyCalls = [];
        $serviceTrend = [];
        for ($i = 6; $i >= 0; $i--) {
            $day = $today->subDays($i);
            $dayLogs = $logs->filter(fn ($log) => $log->created_at->isSameDay($day));
            $dailyCalls[] = ['date' => $day->format('M d'), 'calls' => $dayLogs->count()];
            $serviceTrend[] = [
                'date' => $day->format('M d'),
                'service_requests' => $dayLogs->where('service_request', true)->count(),
                'total_calls' => $dayLogs->count(),
            ];
        }

        $hourlyCalls = [];
        $todayLogs = $logs->filter(fn ($log) => $log->created_at->isSameDay($today));
        for ($hour = 0; $hour < 24; $hour++) {
            $hourlyCalls[] = [
                'hour' => sprintf('%02d:00', $hour),
                'calls' => $todayLogs->filter(fn ($log) => (int) $log->created_at->format('G') === $hour)->count(),
            ];
        }

        // Week-of-month buckets: days 1-7, 8-14, 15-21, 22-28, 29+.
        $monthLogs = $logs->filter(fn ($log) => $log->created_at->gte($monthStart));
        $weeksInMonth = (int) ceil($now->daysInMonth / 7);
        $weeklyCalls = [];
        for ($week = 1; $week <= $weeksInMonth; $week++) {
            $weeklyCalls[] = [
                'week' => 'Week '.$week,
                'calls' => $monthLogs->filter(fn ($log) => (int) ceil($log->created_at->day / 7) === $week)->count(),
            ];
        }

        $weekLogs = $logs->filter(fn ($log) => $log->created_at->gte($weekStart));
        $totalThisWeek = $weekLogs->count();
        $serviceThisWeek = $weekLogs->where('service_request', true)->count();

        return [
            'daily_calls' => $dailyCalls,
            'hourly_calls' => $hourlyCalls,
            'weekly_calls' => $weeklyCalls,
            'call_outcomes' => $weekLogs->countBy('call_outcome')->all(),
            'call_reasons' => $weekLogs->countBy('reason_for_call')->all(),
            'service_trend' => $serviceTrend,
            'metrics' => [
                'total_calls_this_week' => $totalThisWeek,
                'avg_calls_per_day' => $totalThisWeek > 0 ? round($totalThisWeek / 7, 1) : 0,
                'service_request_rate' => $serviceThisWeek,
                'service_request_percentage' => $totalThisWeek > 0 ? round(($serviceThisWeek / $totalThisWeek) * 100, 1) : 0,
            ],
        ];
    }

    public function percentageChange(int|float $current, int|float $previous): int|float
    {
        if ($previous == 0) {
            return $current > 0 ? 100 : 0;
        }

        return round((($current - $previous) / $previous) * 100);
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable, 2: CarbonImmutable, 3: CarbonImmutable}
     */
    private function ranges(string $period): array
    {
        $now = CarbonImmutable::now();

        return match ($period) {
            'weekly' => [
                $now->startOfWeek(), $now->endOfWeek(),
                $now->subWeek()->startOfWeek(), $now->subWeek()->endOfWeek(),
            ],
            'monthly' => [
                $now->startOfMonth(), $now->endOfMonth(),
                $now->subMonthNoOverflow()->startOfMonth(), $now->subMonthNoOverflow()->endOfMonth(),
            ],
            default => [
                $now->startOfDay(), $now->endOfDay(),
                $now->subDay()->startOfDay(), $now->subDay()->endOfDay(),
            ],
        };
    }

    private function counts(int|string $userId, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $query = fn () => $this->baseQuery($userId)->whereBetween('created_at', [$start, $end]);

        return [
            'total_calls' => $query()->count(),
            'service_requests' => $query()->where('service_request', true)->count(),
            'schedules' => $query()->where('call_outcome', 'scheduled-appointment')->count(),
            'callbacks' => $query()->where('call_outcome', 'callback-requested')->count(),
        ];
    }

    private function rate(int $part, int $total): int|float
    {
        return $total > 0 ? round(($part / $total) * 100) : 0;
    }

    /**
     * @return Builder<CallLog>
     */
    private function baseQuery(int|string $userId): Builder
    {
        return CallLog::query()->where('user_id', $userId);
    }
}
