<?php

namespace App\Jobs;

use App\Models\Appointment;
use App\Services\Calendar\CalendarSync;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Copies an appointment change to the business's connected calendars, after the booking commits.
 */
class PushAppointmentToCalendars implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120, 600];

    public function __construct(public readonly int $appointmentId) {}

    public function handle(CalendarSync $sync): void
    {
        $appointment = Appointment::withoutGlobalScopes()->find($this->appointmentId);

        if ($appointment) {
            $sync->pushAppointment($appointment);
        }
    }
}
