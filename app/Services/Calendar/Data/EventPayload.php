<?php

namespace App\Services\Calendar\Data;

use Carbon\CarbonImmutable;

/**
 * What we write to an external calendar for an appointment.
 */
final class EventPayload
{
    public function __construct(
        public readonly string $title,
        public readonly CarbonImmutable $start,
        public readonly CarbonImmutable $end,
        public readonly string $timezone,
        public readonly ?string $description = null,
        public readonly ?string $location = null,
        public readonly ?string $appointmentId = null,
    ) {}
}
