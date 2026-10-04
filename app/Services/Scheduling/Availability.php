<?php

namespace App\Services\Scheduling;

use App\Models\Appointment;
use App\Models\Organization;
use App\Services\Business\BusinessHours;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Bookable start times for a business (spec §19). One service for agents, the portal, the API and,
 * later, the chatbot and AI assistant.
 *
 * Considers: business hours (split shifts, holidays, temporary closure), service duration and buffer,
 * existing appointments, minimum notice. Phase 3 adds connected-calendar busy times behind the same API.
 * Everything is reasoned in the business's timezone, so DST days have the right number of hours.
 */
class Availability
{
    public const STEP_MINUTES = 30;

    public const MIN_NOTICE_MINUTES = 60;

    public function __construct(private readonly BusinessHours $hours) {}

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
    ): array {
        $timezone = $organization->timezoneOrDefault();
        $day = CarbonImmutable::parse(is_string($date) ? $date : $date->format('Y-m-d'), $timezone)->startOfDay();
        $earliest = CarbonImmutable::instance($now ?? now())->setTimezone($timezone)->addMinutes(self::MIN_NOTICE_MINUTES);
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

                if ($start->greaterThanOrEqualTo($earliest) && ! $this->clashes($busy, $start, $blockedUntil)) {
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
    ): array {
        $from = CarbonImmutable::instance($from)->setTimezone($organization->timezoneOrDefault());
        $found = [];

        for ($i = 0; $i < $days && count($found) < $limit; $i++) {
            foreach ($this->slots($organization, $from->addDays($i), $durationMinutes, $bufferMinutes, $locationId, $ignoreAppointmentId) as $slot) {
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
