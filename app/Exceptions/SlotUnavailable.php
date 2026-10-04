<?php

namespace App\Exceptions;

use App\Models\Appointment;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * The requested time overlaps a booking (spec §88). Carries what it clashed with and nearby free times,
 * so every caller (portal, agent, API) can offer a way forward instead of a bare error.
 */
class SlotUnavailable extends RuntimeException
{
    /**
     * @param  Collection<int, Appointment>  $conflicts
     * @param  list<CarbonImmutable>  $suggestions  local start times
     */
    public function __construct(
        public readonly Collection $conflicts,
        public array $suggestions = [],
    ) {
        parent::__construct('That time is already booked.');
    }

    public function describe(): string
    {
        $first = $this->conflicts->first();

        return $first
            ? 'That time overlaps '.$first->title.' ('.$first->whenLabel().').'
            : 'That time is already booked.';
    }
}
