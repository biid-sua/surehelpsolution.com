<?php

namespace App\Notifications;

use App\Enums\NotificationEvent;
use App\Models\Review;
use App\Models\User;
use App\Notifications\Concerns\RespectsQuietHours;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * A new Google review for the business (D83).
 */
class ReviewReceived extends Notification implements ShouldQueue
{
    use Queueable, RespectsQuietHours;

    public int $tries = 3;

    public function __construct(public readonly Review $review)
    {
        $this->afterCommit();
    }

    /** @return list<string> */
    public function via(User $notifiable): array
    {
        return $notifiable->notificationChannelsFor(NotificationEvent::ReviewReceived);
    }

    private function title(): string
    {
        return $this->review->rating.'-star review from '.$this->review->authorLabel();
    }

    /** @return array<string, mixed> */
    public function toArray(User $notifiable): array
    {
        return [
            'event' => NotificationEvent::ReviewReceived->value,
            'title' => $this->title(),
            'body' => Str::limit((string) $this->review->comment, 160) ?: 'No comment, just a rating.',
            'organization_id' => $this->review->organization_id,
            'url' => route('app.reviews', ['review' => $this->review->ulid], false),
        ];
    }

    public function toMail(User $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject($this->title())
            ->greeting('Hi '.Str::before(trim($notifiable->name).' ', ' ').',')
            ->line(str_repeat('★', $this->review->rating).str_repeat('☆', 5 - $this->review->rating).' from '.$this->review->authorLabel().' on Google.');
        if ($this->review->comment) {
            $mail->line('"'.Str::limit($this->review->comment, 600).'"');
        }

        return $mail->line($this->review->rating <= (int) config('reviews.low_rating', 2)
            ? 'A quick, calm reply shows future customers you care. We\'ve added a task for it.'
            : 'A short thank-you reply helps your profile.')
            ->action('Reply to the review', route('app.reviews', ['review' => $this->review->ulid]));
    }

    public function databaseType(object $notifiable): string
    {
        return NotificationEvent::ReviewReceived->value;
    }
}
