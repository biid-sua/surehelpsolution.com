<?php

namespace App\Services\Calendar\Data;

use Carbon\CarbonImmutable;

/**
 * A busy period in an external calendar. Only times are kept: never titles or attendees (privacy).
 */
final class BusyInterval
{
    public function __construct(
        public readonly string $eventId,
        public readonly CarbonImmutable $start,
        public readonly CarbonImmutable $end,
        public readonly bool $allDay = false,
    ) {}
}
