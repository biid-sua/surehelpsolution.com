<?php

namespace App\Services\Growth;

use App\Actions\Appointments\BookAppointment;
use App\Models\Organization;
use App\Services\Scheduling\Availability;
use Carbon\CarbonImmutable;

/**
 * Last-minute openings (G-6, D69): free appointment times in the next few days, under the same rules
 * as online booking, so a business can post "we have openings Thursday afternoon".
 */
class Openings
{
    public const DAYS = 3;

    public const PER_DAY = 4;

    public function __construct(private readonly Availability $availability) {}

    /**
     * @return list<array{date: CarbonImmutable, times: list<CarbonImmutable>}> days with openings, soonest first
     */
    public function upcoming(Organization $organization): array
    {
        $today = CarbonImmutable::now($organization->timezoneOrDefault())->startOfDay();
        $days = [];
        for ($i = 0; $i <= self::DAYS; $i++) {
            $date = $today->addDays($i);
            $times = $this->availability->slots($organization, $date, BookAppointment::DEFAULT_DURATION);
            if ($times !== []) {
                $days[] = ['date' => $date, 'times' => $this->spread($times)];
            }
            if (count($days) === self::DAYS) {
                break;
            }
        }

        return $days;
    }

    /** Text for a post about the openings, with where to book. */
    public function postText(Organization $organization, array $days, ?string $bookingUrl): string
    {
        $lines = array_map(fn (array $d) => $d['date']->format('l, M j').': '.implode(', ', array_map(fn (CarbonImmutable $t) => $t->format('g:i A'), $d['times'])), $days);

        return "Last-minute openings this week:\n".implode("\n", $lines)."\n\n"
            .($bookingUrl ? "Book yours: {$bookingUrl}" : 'Call or message us to book yours.');
    }

    /**
     * A few times across the day rather than the first four half-hours.
     *
     * @param  list<CarbonImmutable>  $times
     * @return list<CarbonImmutable>
     */
    private function spread(array $times): array
    {
        if (count($times) <= self::PER_DAY) {
            return $times;
        }
        $picked = [];
        $step = (count($times) - 1) / (self::PER_DAY - 1);
        for ($i = 0; $i < self::PER_DAY; $i++) {
            $picked[] = $times[(int) round($i * $step)];
        }

        return $picked;
    }
}
