<?php

namespace App\Models\Concerns;

/**
 * Store every date as UTC, whatever timezone the Carbon instance carries.
 *
 * Eloquent formats a date in its own timezone, so 9:00 Chicago would otherwise be saved as "09:00"
 * and read back as 9:00 UTC. Business-timezone values are common here (spec §74), so make it impossible.
 */
trait StoresUtc
{
    public function fromDateTime($value)
    {
        return empty($value) ? $value : $this->asDateTime($value)->setTimezone(config('app.timezone'))->format($this->getDateFormat());
    }
}
