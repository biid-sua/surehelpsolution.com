<?php

namespace App\Services\Sales;

use App\Models\ContactSubmission;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Open times for a demo call with the SureHelp team (D78): the sales hours in config/sales.php,
 * minus the notice period and times already booked up to the team's capacity.
 */
class DemoSlots
{
    public function timezone(): string
    {
        return (string) config('sales.demos.timezone', 'America/New_York');
    }

    /**
     * Days with open times, each with its slots.
     *
     * @return list<array{date: string, label: string, slots: list<array{starts_at: string, label: string}>}>
     */
    public function days(?string $from = null, int $maxDays = 5, int $perDay = 8, ?string $partOfDay = null): array
    {
        $tz = $this->timezone();
        $now = CarbonImmutable::now($tz);
        $earliest = $now->addHours((int) config('sales.demos.notice_hours', 2));
        $start = $from ? CarbonImmutable::parse($from, $tz)->startOfDay() : $now->startOfDay();
        if ($start->lt($now->startOfDay())) {
            $start = $now->startOfDay();
        }
        $last = $now->startOfDay()->addDays((int) config('sales.demos.days_ahead', 14));
        $minutes = max(15, (int) config('sales.demos.minutes', 30));
        $taken = $this->taken($start, $last->addDay());

        $days = [];
        for ($day = $start; $day->lte($last) && count($days) < $maxDays; $day = $day->addDay()) {
            [$open, $close] = config('sales.demos.hours')[$day->isoWeekday()] ?? [null, null];
            if (! $open) {
                continue;
            }
            $slots = [];
            for ($at = $day->setTimeFromTimeString($open); $at->addMinutes($minutes)->lte($day->setTimeFromTimeString($close)); $at = $at->addMinutes($minutes)) {
                if ($at->lt($earliest) || ($taken[$at->utc()->format('Y-m-d H:i')] ?? 0) >= $this->capacity() || ! $this->inPart($at, $partOfDay)) {
                    continue;
                }
                $slots[] = ['starts_at' => $at->toIso8601String(), 'label' => $at->format('g:i A')];
            }
            if ($slots !== []) {
                $days[] = ['date' => $day->toDateString(), 'label' => $this->dayLabel($day, $now), 'slots' => array_slice($slots, 0, $perDay)];
            }
        }

        return $days;
    }

    /** The slot, if it is one we offer and it is still free. */
    public function check(string $startsAt): ?CarbonImmutable
    {
        try {
            $at = CarbonImmutable::parse($startsAt)->setTimezone($this->timezone());
        } catch (\Throwable) {
            return null;
        }
        foreach ($this->days($at->toDateString(), 1, 100) as $day) {
            foreach ($day['slots'] as $slot) {
                if (CarbonImmutable::parse($slot['starts_at'])->equalTo($at)) {
                    return $at;
                }
            }
        }

        return null;
    }

    /**
     * Book the slot for this enquiry, holding a lock so two visitors can't take the last place.
     * Returns false when it was just taken.
     */
    public function book(ContactSubmission $enquiry, CarbonImmutable $at): bool
    {
        return DB::transaction(function () use ($enquiry, $at) {
            $count = ContactSubmission::query()->lockForUpdate()
                ->where('demo_at', $at->utc())->where('demo_status', 'booked')->whereKeyNot($enquiry->id)->count();
            if ($count >= $this->capacity()) {
                return false;
            }
            $enquiry->forceFill(['demo_at' => $at->utc(), 'demo_status' => 'booked', 'inquiry_type' => 'demo'])->save();

            return true;
        });
    }

    public function label(CarbonImmutable $at): string
    {
        $local = $at->setTimezone($this->timezone());

        return $local->format('l, F j \a\t g:i A').' '.$local->format('T');
    }

    private function capacity(): int
    {
        return max(1, (int) config('sales.demos.capacity', 1));
    }

    /** @return array<string, int> UTC "Y-m-d H:i" => demos booked then */
    private function taken(CarbonImmutable $from, CarbonImmutable $to): array
    {
        return ContactSubmission::query()->where('demo_status', 'booked')
            ->whereBetween('demo_at', [$from->utc(), $to->utc()])->pluck('demo_at')
            ->countBy(fn ($at) => $at->utc()->format('Y-m-d H:i'))->all();
    }

    private function inPart(CarbonImmutable $at, ?string $part): bool
    {
        return match ($part) {
            'morning' => $at->hour < 12,
            'afternoon' => $at->hour >= 12 && $at->hour < 17,
            'evening' => $at->hour >= 17,
            default => true,
        };
    }

    private function dayLabel(CarbonImmutable $day, CarbonImmutable $now): string
    {
        return match (true) {
            $day->isSameDay($now) => 'Today',
            $day->isSameDay($now->addDay()) => 'Tomorrow',
            default => $day->format('D, M j'),
        };
    }
}
