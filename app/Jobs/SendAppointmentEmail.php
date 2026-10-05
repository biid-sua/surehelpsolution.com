<?php

namespace App\Jobs;

use App\Models\Appointment;
use App\Services\Messages\CustomerMessages;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Sends one customer email about an appointment once the booking change has committed.
 */
class SendAppointmentEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public readonly int $appointmentId, public readonly string $key) {}

    public function handle(CustomerMessages $messages): void
    {
        $appointment = Appointment::withoutGlobalScopes()->find($this->appointmentId);
        if ($appointment) {
            $messages->send($appointment, $this->key);
        }
    }
}
