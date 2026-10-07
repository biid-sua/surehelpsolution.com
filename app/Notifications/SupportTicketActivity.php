<?php

namespace App\Notifications;

use App\Enums\NotificationEvent;
use App\Models\SupportTicket;
use App\Models\User;
use App\Notifications\Concerns\RespectsQuietHours;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * Something happened on a support request: to the business (a reply, resolved) or to SureHelp staff (new, reply).
 */
class SupportTicketActivity extends Notification implements ShouldQueue
{
    use Queueable, RespectsQuietHours;

    public int $tries = 3;

    public function __construct(
        public readonly SupportTicket $ticket,
        public readonly string $title,
        public readonly bool $staff,
    ) {
        $this->afterCommit();
    }

    /** @return list<string> */
    public function via(User $notifiable): array
    {
        return $notifiable->notificationChannelsFor(NotificationEvent::SupportUpdate);
    }

    private function url(bool $absolute = true): string
    {
        return $this->staff
            ? route('admin.support', ['ticket' => $this->ticket->ulid], $absolute)
            : route('app.support', ['ticket' => $this->ticket->ulid], $absolute);
    }

    /** @return array<string, mixed> */
    public function toArray(User $notifiable): array
    {
        return [
            'event' => NotificationEvent::SupportUpdate->value,
            'title' => $this->title,
            'body' => $this->ticket->categoryLabel().' · '.$this->ticket->status->label(),
            'organization_id' => $this->ticket->organization_id,
            'url' => $this->url(false),
        ];
    }

    public function toMail(User $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->title)
            ->greeting('Hi '.Str::before(trim($notifiable->name).' ', ' ').',')
            ->line($this->title)
            ->action('Open the request', $this->url());
    }

    public function databaseType(object $notifiable): string
    {
        return NotificationEvent::SupportUpdate->value;
    }
}
