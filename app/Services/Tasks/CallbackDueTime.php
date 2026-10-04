<?php

namespace App\Services\Tasks;

use App\Models\Organization;
use App\Services\Business\BusinessHours;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * When a call-back is due (spec §24): within an hour of business time.
 *
 * A caller who asks for a call back at 9 PM is called back an hour after the
 * business opens, not "overdue" overnight. Without configured hours, one hour from now.
 */
class CallbackDueTime
{
    public const RESPONSE_MINUTES = 60;

    public function __construct(private readonly BusinessHours $hours) {}

    public function for(Organization $organization, ?CarbonInterface $requestedAt = null): CarbonImmutable
    {
        $at = CarbonImmutable::instance($requestedAt ?? now())->utc();

        if ($this->hours->isOpenAt($organization, $at)) {
            return $at->addMinutes(self::RESPONSE_MINUTES);
        }

        $opens = $this->hours->nextOpening($organization, $at);

        return ($opens?->utc() ?? $at)->addMinutes(self::RESPONSE_MINUTES);
    }
}
