<?php

namespace App\Notifications;

use App\Enums\NotificationEvent;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Notifications\Concerns\RespectsQuietHours;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * A customer message that a person should answer (message.received, spec §26).
 */
class InboxMessageReceived extends Notification implements ShouldQueue
{
    use Queueable, RespectsQuietHours;

    public int $tries = 3;

    public function __construct(public readonly Conversation $conversation, public readonly Message $message)
    {
        $this->afterCommit();
    }

    /**
     * @return list<string>
     */
    public function via(User $notifiable): array
    {
        return $notifiable->notificationChannelsFor(NotificationEvent::MessageReceived);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(User $notifiable): array
    {
        return [
            'event' => NotificationEvent::MessageReceived->value,
            'title' => $this->title(),
            'body' => Str::limit((string) ($this->message->body ?? 'Sent an attachment'), 140),
            'organization_id' => $this->conversation->organization_id,
            'url' => route('app.inbox.show', $this->conversation, absolute: false),
        ];
    }

    public function toMail(User $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->title())
            ->greeting('Hi '.Str::before(trim($notifiable->name).' ', ' ').',')
            ->line('"'.Str::limit((string) ($this->message->body ?? 'Sent an attachment'), 400).'"')
            ->action('Reply', route('app.inbox.show', $this->conversation))
            ->line($this->conversation->channel->isMeta() ? 'Meta allows replies within 24 hours of the customer\'s last message.' : 'They are waiting in your website chat.');
    }

    private function title(): string
    {
        return 'New message from '.$this->conversation->displayName().' ('.$this->conversation->channel->label().')';
    }

    public function databaseType(object $notifiable): string
    {
        return NotificationEvent::MessageReceived->value;
    }
}
