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
 * A post didn't go out to one or more accounts (after retries, or because the network refused it).
 */
class SocialPostFailed extends Notification implements ShouldQueue
{
    use Queueable, RespectsQuietHours;

    public int $tries = 3;

    public function __construct(public readonly SocialPost $post)
    {
        $this->afterCommit();
    }

    /**
     * @return list<string>
     */
    public function via(User $notifiable): array
    {
        return $notifiable->notificationChannelsFor(NotificationEvent::SocialPostFailed);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(User $notifiable): array
    {
        return [
            'event' => NotificationEvent::SocialPostFailed->value,
            'title' => $this->title(),
            'body' => '"'.$this->post->excerpt(80).'" '.$this->failures(),
            'organization_id' => $this->post->organization_id,
            'url' => route('app.social.posts.edit', $this->post, absolute: false),
        ];
    }

    public function toMail(User $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->title())
            ->greeting('Hi '.Str::before(trim($notifiable->name).' ', ' ').',')
            ->line('Your post "'.$this->post->excerpt(80).'" '.$this->failures())
            ->action('Open the post', route('app.social.posts.edit', $this->post))
            ->line('You can fix it and retry from the post page.');
    }

    private function title(): string
    {
        return $this->post->status->value === 'partly_published' ? 'A post was only partly published' : 'A post wasn\'t published';
    }

    private function failures(): string
    {
        $failed = $this->post->targets()->with('account')->where('status', 'failed')->get()
            ->map(fn ($t) => ($t->account?->network->label() ?? 'An account').': '.$t->last_error)->all();

        return 'didn\'t go out to '.count($failed).' '.Str::plural('account', count($failed)).'. '.implode(' ', $failed);
    }

    public function databaseType(object $notifiable): string
    {
        return NotificationEvent::SocialPostFailed->value;
    }
}
