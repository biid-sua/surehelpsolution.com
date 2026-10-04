<?php

namespace App\Services\Scheduling;

use App\Models\Appointment;
use App\Models\CalendarBusyBlock;
use App\Models\Organization;
use App\Services\Business\BusinessHours;
use App\Services\Rules\BusinessRules;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Bookable start times for a business (spec §19). One service for agents, the portal, the API and,
 * later, the chatbot and AI assistant.
 *
 * Considers: business hours (split shifts, holidays, temporary closure), service duration and buffer,
 * existing appointments, busy times in connected Google/Microsoft calendars, minimum notice, and the
 * business's booking rules (cutoff time, booking window).
 * Everything is reasoned in the business's timezone, so DST days have the right number of hours.
 */
class Availability
{
    public const STEP_MINUTES = 30;

    public const MIN_NOTICE_MINUTES = 60;

    public function __construct(
        private readonly BusinessHours $hours,
        private readonly BusinessRules $rules,
    ) {}

    /**
     * Free start times on a local date.
     *
     * @return list<CarbonImmutable> local times, ascending
     */
    public function slots(
        Organization $organization,
        CarbonInterface|string $date,
        int $durationMinutes,
        int $bufferMinutes = 0,
        ?int $locationId = null,
        ?int $ignoreAppointmentId = null,
        ?CarbonInterface $now = null,
        ?int $serviceId = null,
    ): array {
        $timezone = $organization->timezoneOrDefault();
        $now = CarbonImmutable::instance($now ?? now())->setTimezone($timezone);
        $day = CarbonImmutable::parse(is_string($date) ? $date : $date->format('Y-m-d'), $timezone)->startOfDay();

        // Business rules (spec §23): notice, how far ahead, latest start time.
        [$ruleNotice, $maxDays] = $this->rules->window($organization);
        $earliest = $now->addMinutes(max(self::MIN_NOTICE_MINUTES, (int) $ruleNotice));
        $cutoff = $this->rules->cutoffFor($organization, $serviceId);
        if ($maxDays !== null && $day->greaterThan($now->startOfDay()->addDays($maxDays))) {
            return [];
        }

        $intervals = $this->hours->intervalsOn($organization, $day);

        if ($intervals === []) {
            return [];
        }

        $busy = $this->busy($organization, $intervals[0]['start'], end($intervals)['end']->addMinutes($bufferMinutes), $locationId, $ignoreAppointmentId);
        $slots = [];

        foreach ($intervals as $interval) {
            $start = $this->roundUp($interval['start']);

            while ($start->addMinutes($durationMinutes)->lessThanOrEqualTo($interval['end'])) {
                $blockedUntil = $start->addMinutes($durationMinutes + $bufferMinutes);

                $beforeCutoff = $cutoff === null || $start->format('H:i') < $cutoff;

                if ($beforeCutoff && $start->greaterThanOrEqualTo($earliest) && ! $this->clashes($busy, $start, $blockedUntil)) {
                    $slots[] = $start;
                }

                $start = $start->addMinutes(self::STEP_MINUTES);
            }
        }

        return $slots;
    }

    /**
     * The next free start times from a moment on, across days (for "that time is taken, how about…").
     *
     * @return list<CarbonImmutable>
     */
    public function nextSlots(
        Organization $organization,
        CarbonInterface $from,
        int $durationMinutes,
        int $bufferMinutes = 0,
        ?int $locationId = null,
        ?int $ignoreAppointmentId = null,
        int $limit = 3,
        int $days = 14,
        ?int $serviceId = null,
    ): array {
        $from = CarbonImmutable::instance($from)->setTimezone($organization->timezoneOrDefault());
        $found = [];

        for ($i = 0; $i < $days && count($found) < $limit; $i++) {
            foreach ($this->slots($organization, $from->addDays($i), $durationMinutes, $bufferMinutes, $locationId, $ignoreAppointmentId, null, $serviceId) as $slot) {
                if ($slot->greaterThanOrEqualTo($from)) {
                    $found[] = $slot;
                    if (count($found) === $limit) {
                        break;
                    }
                }
            }
        }

        return $found;
    }

    /**
     * Whether [start, start + duration] lies inside one opening interval.
     */
    public function withinHours(Organization $organization, CarbonInterface $start, int $durationMinutes): bool
    {
        $start = CarbonImmutable::instance($start)->setTimezone($organization->timezoneOrDefault());
        $end = $start->addMinutes($durationMinutes);

        foreach ([$start->subDay(), $start] as $day) {
            foreach ($this->hours->intervalsOn($organization, $day) as $interval) {
                if ($start->greaterThanOrEqualTo($interval['start']) && $end->lessThanOrEqualTo($interval['end'])) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return list<array{0: CarbonImmutable, 1: CarbonImmutable}>
     */
    private function busy(Organization $organization, CarbonImmutable $from, CarbonImmutable $to, ?int $locationId, ?int $ignore): array
    {
        return Appointment::query()->forOrganization($organization)
            ->blocking()
            ->overlapping($from->utc(), $to->utc())
            ->competingWith($locationId)
            ->when($ignore, fn ($q) => $q->whereKeyNot($ignore))
            ->get(['starts_at', 'blocked_until'])
            ->map(fn (Appointment $a) => [CarbonImmutable::instance($a->starts_at), CarbonImmutable::instance($a->blocked_until)])
            // Busy times mirrored from connected calendars (spec §19.6–7).
            ->concat(CalendarBusyBlock::query()->forOrganization($organization)->overlapping($from->utc(), $to->utc())->get(['starts_at', 'ends_at'])
                ->map(fn (CalendarBusyBlock $b) => [CarbonImmutable::instance($b->starts_at), CarbonImmutable::instance($b->ends_at)]))
            ->all();
    }

    /**
     * @param  list<array{0: CarbonImmutable, 1: CarbonImmutable}>  $busy
     */
    private function clashes(array $busy, CarbonImmutable $start, CarbonImmutable $blockedUntil): bool
    {
        foreach ($busy as [$busyStart, $busyUntil]) {
            if ($busyStart->lessThan($blockedUntil) && $busyUntil->greaterThan($start)) {
                return true;
            }
        }

        return false;
    }

    private function roundUp(CarbonImmutable $time): CarbonImmutable
    {
        $minutes = (int) $time->format('i');
        $remainder = $minutes % self::STEP_MINUTES;

        return $remainder === 0 ? $time->second(0) : $time->second(0)->addMinutes(self::STEP_MINUTES - $remainder);
    }
}
