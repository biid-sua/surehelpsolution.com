<?php

namespace App\Notifications;

use App\Enums\NotificationEvent;
use App\Models\CalendarConnection;
use App\Models\User;
use App\Services\Calendar\CalendarManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * A connected calendar stopped working and must be reconnected (integration.disconnected, CAL-06).
 */
class CalendarDisconnected extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public readonly CalendarConnection $calendar)
    {
        $this->afterCommit();
    }

    /**
     * @return list<string>
     */
    public function via(User $notifiable): array
    {
        return $notifiable->notificationChannelsFor(NotificationEvent::IntegrationDisconnected);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(User $notifiable): array
    {
        return [
            'event' => NotificationEvent::IntegrationDisconnected->value,
            'title' => $this->title(),
            'body' => 'Bookings are no longer copied to it and its busy times may be out of date. Reconnect it in Business › Calendar sync.',
            'organization_id' => $this->calendar->organization_id,
            'url' => route('app.business.calendars', absolute: false),
        ];
    }

    public function toMail(User $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->title())
            ->greeting('Hi '.Str::before(trim($notifiable->name).' ', ' ').',')
            ->line('We lost access to '.($this->calendar->account_email ?: 'your calendar').'. This happens when access is removed or a password changes.')
            ->line('Until you reconnect, new bookings aren\'t added to that calendar and our agents may not see when you are busy.')
            ->action('Reconnect calendar', route('app.business.calendars'));
    }

    private function title(): string
    {
        return app(CalendarManager::class)->provider($this->calendar->provider)->label().' needs reconnecting';
    }

    public function databaseType(object $notifiable): string
    {
        return NotificationEvent::IntegrationDisconnected->value;
    }
}
