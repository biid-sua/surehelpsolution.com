<?php

namespace App\Notifications;

use App\Enums\NotificationEvent;
use App\Models\Appointment;
use App\Models\User;
use App\Notifications\Concerns\RespectsQuietHours;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * An appointment was booked, changed or cancelled (appointment.created / .updated / .cancelled).
 */
class AppointmentActivity extends Notification implements ShouldQueue
{
    use Queueable, RespectsQuietHours;

    public int $tries = 3;

    public function __construct(
        public readonly Appointment $appointment,
        public readonly NotificationEvent $event,
        public readonly ?string $change = null,
    ) {
        $this->afterCommit();
    }

    /**
     * @return list<string>
     */
    public function via(User $notifiable): array
    {
        return $notifiable->notificationChannelsFor($this->event);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(User $notifiable): array
    {
        return [
            'event' => $this->event->value,
            'title' => $this->title(),
            'body' => $this->summary(),
            'appointment_id' => $this->appointment->ulid,
            'organization_id' => $this->appointment->organization_id,
            'url' => route('app.appointments.index', ['appointment' => $this->appointment->ulid], absolute: false),
        ];
    }

    public function toMail(User $notifiable): MailMessage
    {
        $business = $this->appointment->organization->name ?? config('app.name');

        return (new MailMessage)
            ->subject($this->title().' · '.$business)
            ->greeting('Hi '.Str::before(trim($notifiable->name).' ', ' ').',')
            ->line($this->summary())
            ->action('Open appointment', route('app.appointments.index', ['appointment' => $this->appointment->ulid]))
            ->line('You can choose which notifications you get in your notification settings.');
    }

    public function title(): string
    {
        return match ($this->event) {
            NotificationEvent::AppointmentCancelled => 'Cancelled: '.$this->appointment->title,
            NotificationEvent::AppointmentUpdated => 'Changed: '.$this->appointment->title,
            default => 'Booked: '.$this->appointment->title,
        };
    }

    private function summary(): string
    {
        return trim($this->appointment->whenLabel().' ('.$this->appointment->localStart()->format('T').'). '.($this->change ?? ''));
    }

    public function databaseType(object $notifiable): string
    {
        return $this->event->value;
    }
}
