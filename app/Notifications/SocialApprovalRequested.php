<?php

namespace App\Notifications;

use App\Enums\NotificationEvent;
use App\Models\SocialPost;
use App\Models\User;
use App\Notifications\Concerns\RespectsQuietHours;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * A post written by a manager, SureHelp's team or AI waits for an owner's approval (spec §41).
 */
class SocialApprovalRequested extends Notification implements ShouldQueue
{
    use Queueable, RespectsQuietHours;

    public int $tries = 3;

    public function __construct(public readonly SocialPost $post, public readonly string $author)
    {
        $this->afterCommit();
    }

    /**
     * @return list<string>
     */
    public function via(User $notifiable): array
    {
        return $notifiable->notificationChannelsFor(NotificationEvent::SocialApprovalRequested);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(User $notifiable): array
    {
        return [
            'event' => NotificationEvent::SocialApprovalRequested->value,
            'title' => 'Post to approve from '.$this->author,
            'body' => '"'.$this->post->excerpt(100).'"'.$this->when(),
            'organization_id' => $this->post->organization_id,
            'url' => route('app.social.posts.edit', $this->post, absolute: false),
        ];
    }

    public function toMail(User $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('A post is waiting for your approval')
            ->greeting('Hi '.Str::before(trim($notifiable->name).' ', ' ').',')
            ->line($this->author.' wrote a post for you:')
            ->line('"'.$this->post->excerpt(280).'"'.$this->when())
            ->action('Review and approve', route('app.social.posts.edit', $this->post))
            ->line('Nothing is published until you approve it.');
    }

    private function when(): string
    {
        if (! $this->post->scheduled_at) {
            return '';
        }

        $timezone = $this->post->organization?->timezoneOrDefault() ?? config('app.timezone');

        return ' Planned for '.$this->post->scheduled_at->setTimezone($timezone)->format('D j M, g:i A').'.';
    }

    public function databaseType(object $notifiable): string
    {
        return NotificationEvent::SocialApprovalRequested->value;
    }
}
