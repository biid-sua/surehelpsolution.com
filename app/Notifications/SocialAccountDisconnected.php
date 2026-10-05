<?php

namespace App\Notifications;

use App\Enums\NotificationEvent;
use App\Models\SocialAccount;
use App\Models\User;
use App\Notifications\Concerns\RespectsQuietHours;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * A connected social account stopped accepting our posts and must be reconnected.
 */
class SocialAccountDisconnected extends Notification implements ShouldQueue
{
    use Queueable, RespectsQuietHours;

    public int $tries = 3;

    public function __construct(public readonly SocialAccount $account)
    {
        $this->afterCommit();
    }

    /**
     * @return list<string>
     */
    public function via(User $notifiable): array
    {
        return $notifiable->notificationChannelsFor(NotificationEvent::SocialAccountDisconnected);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(User $notifiable): array
    {
        return [
            'event' => NotificationEvent::SocialAccountDisconnected->value,
            'title' => $this->title(),
            'body' => 'Scheduled posts to it will fail until you reconnect it in Social › Accounts.',
            'organization_id' => $this->account->organization_id,
            'url' => route('app.social.accounts', absolute: false),
        ];
    }

    public function toMail(User $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->title())
            ->greeting('Hi '.Str::before(trim($notifiable->name).' ', ' ').',')
            ->line($this->account->network->label().' stopped accepting posts from SureHelp for '.$this->account->displayName().'. This happens when access is removed, a password changes or a permission is turned off.')
            ->line('Posts scheduled for it will fail until you reconnect.')
            ->action('Reconnect account', route('app.social.accounts'));
    }

    private function title(): string
    {
        return $this->account->network->label().' needs reconnecting';
    }

    public function databaseType(object $notifiable): string
    {
        return NotificationEvent::SocialAccountDisconnected->value;
    }
}
