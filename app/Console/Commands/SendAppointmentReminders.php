<?php

namespace App\Console\Commands;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Services\Messages\CustomerMessages;
use Illuminate\Console\Command;

/**
 * Every 15 minutes: reminder emails for confirmed appointments coming up within each business's
 * chosen lead time (default 24 hours). One reminder per appointment; none for last-minute bookings.
 */
class SendAppointmentReminders extends Command
{
    protected $signature = 'appointments:send-reminders';

    protected $description = 'Email customers a reminder before their appointment';

    public function handle(CustomerMessages $messages): int
    {
        $sent = 0;
        $leads = [];

        Appointment::withoutGlobalScopes()->with(['organization', 'customer', 'service'])
            ->where('status', AppointmentStatus::Confirmed->value)->whereNull('reminder_sent_at')
            ->whereBetween('starts_at', [now()->addHour(), now()->addHours(72)])
            ->orderBy('starts_at')
            ->each(function (Appointment $appointment) use ($messages, &$sent, &$leads) {
                $organization = $appointment->organization;
                $lead = $leads[$organization->id] ??= (int) ($messages->template($organization, 'appointment_reminder')['lead_hours'] ?? 24);
                if ($appointment->starts_at->greaterThan(now()->addHours($lead))) {
                    return;
                }
                // Booked inside the reminder window: the confirmation was the reminder.
                if ($appointment->created_at->greaterThan($appointment->starts_at->copy()->subHours($lead))) {
                    $appointment->forceFill(['reminder_sent_at' => now()])->saveQuietly();

                    return;
                }

                $appointment->forceFill(['reminder_sent_at' => now()])->saveQuietly();
                if ($messages->send($appointment, 'appointment_reminder')) {
                    $sent++;
                }
            });

        $this->info("Sent {$sent} ".str('reminder')->plural($sent).'.');

        return self::SUCCESS;
    }
}
