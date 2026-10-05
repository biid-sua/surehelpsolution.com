<?php

namespace App\Services\Business;

use App\Models\BusinessHoliday;
use App\Models\BusinessHour;
use App\Models\BusinessProfile;
use App\Models\Organization;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Answers "is this business open?" (spec §10, reused by the availability engine, §19).
 *
 * All reasoning happens in the business's own timezone: never assume UTC == business time (spec §74).
 * - several intervals per weekday = split shift; no interval = closed that day
 * - closes_at earlier than (or equal to) opens_at = open past midnight
 * - a holiday row closes the whole date, or replaces its hours with special hours
 * - closed_from..closed_until on the profile (vacation mode) closes the business on those dates
 */
class BusinessHours
{
    /** Carbon day numbers (0 = Sunday) in display order, Monday first. */
    public const DAYS = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 0 => 'Sunday'];

    /** How far ahead to look for the next opening. */
    private const LOOKAHEAD_DAYS = 14;

    /** @var array<int, array{hours: Collection<int, BusinessHour>, holidays: Collection<string, BusinessHoliday>, profile: ?BusinessProfile}> */
    private array $cache = [];

    /**
     * Opening intervals that START on the given local date.
     *
     * @return list<array{start: CarbonImmutable, end: CarbonImmutable}>
     */
    public function intervalsOn(Organization $organization, CarbonInterface $date): array
    {
        $timezone = $organization->timezoneOrDefault();
        $day = CarbonImmutable::parse($date->format('Y-m-d'), $timezone);
        $data = $this->data($organization);

        if ($data['profile']?->isAwayOn($day->toDateString())) {
            return [];
        }

        $holiday = $data['holidays']->get($day->toDateString());
        if ($holiday) {
            if ($holiday->is_closed || ! $holiday->opens_at || ! $holiday->closes_at) {
                return [];
            }

            return [$this->interval($day, $holiday->opens_at, $holiday->closes_at, $timezone)];
        }

        return $data['hours']
            ->where('day_of_week', $day->dayOfWeek)
            ->sortBy('opens_at')
            ->map(fn (BusinessHour $hour) => $this->interval($day, $hour->opens_at, $hour->closes_at, $timezone))
            ->values()
            ->all();
    }

    public function isOpenAt(Organization $organization, CarbonInterface $moment): bool
    {
        return $this->currentInterval($organization, $moment) !== null;
    }

    /**
     * Everything a screen or an agent needs to say about opening status right now.
     *
     * @return array{open: bool, until: ?CarbonImmutable, next_open: ?CarbonImmutable, reason: ?string, label: string}
     */
    public function status(Organization $organization, ?CarbonInterface $now = null): array
    {
        $timezone = $organization->timezoneOrDefault();
        $now = CarbonImmutable::instance($now ?? now())->setTimezone($timezone);
        $current = $this->currentInterval($organization, $now);

        if ($current) {
            return [
                'open' => true,
                'until' => $current['end'],
                'next_open' => null,
                'reason' => null,
                'label' => 'Open until '.$this->time($current['end']),
            ];
        }

        $next = $this->nextOpening($organization, $now);
        $profile = $this->data($organization)['profile'];
        $holiday = $this->data($organization)['holidays']->get($now->toDateString());

        $reason = match (true) {
            (bool) $profile?->isAwayOn($now->toDateString()) => 'temporarily_closed',
            $holiday !== null => 'holiday',
            default => null,
        };

        $label = match (true) {
            $next === null => 'Closed',
            $next->isSameDay($now) => 'Closed · opens at '.$this->time($next),
            $next->isSameDay($now->addDay()) => 'Closed · opens tomorrow at '.$this->time($next),
            default => 'Closed · opens '.$next->format('l').' at '.$this->time($next),
        };

        if ($reason === 'holiday') {
            $label = $holiday->name.' · '.$label;
        }

        return ['open' => false, 'until' => null, 'next_open' => $next, 'reason' => $reason, 'label' => $label];
    }

    public function nextOpening(Organization $organization, CarbonInterface $after): ?CarbonImmutable
    {
        $timezone = $organization->timezoneOrDefault();
        $after = CarbonImmutable::instance($after)->setTimezone($timezone);

        for ($i = 0; $i <= self::LOOKAHEAD_DAYS; $i++) {
            foreach ($this->intervalsOn($organization, $after->addDays($i)) as $interval) {
                if ($interval['start']->greaterThan($after)) {
                    return $interval['start'];
                }
            }
        }

        return null;
    }

    /**
     * Weekly schedule for display, Monday first: day name => list of "9:00 AM – 5:00 PM".
     *
     * @return array<string, list<string>>
     */
    public function weekly(Organization $organization): array
    {
        $hours = $this->data($organization)['hours'];
        $schedule = [];

        foreach (self::DAYS as $day => $name) {
            $schedule[$name] = $hours
                ->where('day_of_week', $day)
                ->sortBy('opens_at')
                ->map(fn (BusinessHour $h) => $this->clock($h->opens_at).' – '.$this->clock($h->closes_at))
                ->values()
                ->all();
        }

        return $schedule;
    }

    public function forget(Organization $organization): void
    {
        unset($this->cache[$organization->getKey()]);
    }

    /**
     * @return array{start: CarbonImmutable, end: CarbonImmutable}|null
     */
    private function currentInterval(Organization $organization, CarbonInterface $moment): ?array
    {
        $local = CarbonImmutable::instance($moment)->setTimezone($organization->timezoneOrDefault());

        // Yesterday's overnight shift may still be running.
        foreach ([$local->subDay(), $local] as $day) {
            foreach ($this->intervalsOn($organization, $day) as $interval) {
                if ($local->greaterThanOrEqualTo($interval['start']) && $local->lessThan($interval['end'])) {
                    return $interval;
                }
            }
        }

        return null;
    }

    /**
     * @return array{start: CarbonImmutable, end: CarbonImmutable}
     */
    private function interval(CarbonImmutable $day, string $opens, string $closes, string $timezone): array
    {
        $start = CarbonImmutable::parse($day->toDateString().' '.$opens, $timezone);
        $end = CarbonImmutable::parse($day->toDateString().' '.$closes, $timezone);

        if ($end->lessThanOrEqualTo($start)) {
            $end = CarbonImmutable::parse($day->addDay()->toDateString().' '.$closes, $timezone);
        }

        return ['start' => $start, 'end' => $end];
    }

    /**
     * @return array{hours: Collection<int, BusinessHour>, holidays: Collection<string, BusinessHoliday>, profile: ?BusinessProfile}
     */
    private function data(Organization $organization): array
    {
        return $this->cache[$organization->getKey()] ??= [
            'hours' => BusinessHour::query()->forOrganization($organization)->whereNull('location_id')->get(),
            'holidays' => BusinessHoliday::query()->forOrganization($organization)->get()->keyBy(fn (BusinessHoliday $h) => $h->date->toDateString()),
            'profile' => BusinessProfile::query()->forOrganization($organization)->first(),
        ];
    }

    private function time(CarbonInterface $moment): string
    {
        return $moment->format($moment->minute === 0 ? 'g A' : 'g:i A');
    }

    private function clock(string $time): string
    {
        return $this->time(CarbonImmutable::createFromFormat('H:i:s', strlen($time) === 5 ? $time.':00' : $time));
    }
}
