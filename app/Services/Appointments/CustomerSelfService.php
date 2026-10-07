<?php

namespace App\Services\Appointments;

use App\Models\Appointment;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\URL;

/**
 * Customers change or cancel their own appointment from a link in their emails, without an account
 * (CAL-09, D48). The link is signed and expires when the appointment starts; changes are allowed
 * until the business's cut-off (organizations.customer_change_hours) and follow strict booking rules.
 */
class CustomerSelfService
{
    public const DEFAULT_HOURS = 24;

    /** Choices offered in settings, in hours before the appointment. */
    public const HOUR_OPTIONS = [2, 4, 12, 24, 48, 72];

    public function offered(Appointment $appointment): bool
    {
        return $appointment->organization->customer_change_hours !== null && $appointment->status->blocksTime();
    }

    /** Still early enough to change or cancel online. */
    public function canChange(Appointment $appointment): bool
    {
        return $this->offered($appointment)
            && $appointment->starts_at->greaterThan(now()->addHours((int) $appointment->organization->customer_change_hours));
    }

    public function deadline(Appointment $appointment): ?CarbonInterface
    {
        $hours = $appointment->organization->customer_change_hours;

        return $hours === null ? null : $appointment->starts_at->copy()->subHours($hours);
    }

    /** The link for emails, or null when it isn't offered. */
    public function link(Appointment $appointment): ?string
    {
        if (! $this->canChange($appointment)) {
            return null;
        }

        return URL::temporarySignedRoute('appointments.manage', $appointment->starts_at, ['appointment' => $appointment->ulid]);
    }
}
