<?php

namespace App\Notifications;

use App\Models\QaReview;
use App\Models\User;
use App\Notifications\Concerns\RespectsQuietHours;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * Tells an agent that one of their calls was scored, so they can read the feedback (SUP-04).
 */
class QaReviewCompleted extends Notification implements ShouldQueue
{
    use Queueable, RespectsQuietHours;

    public int $tries = 3;

    public function __construct(public readonly QaReview $review)
    {
        $this->afterCommit();
    }

    /**
     * @return list<string>
     */
    public function via(User $notifiable): array
    {
        return ['database', 'mail'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(User $notifiable): array
    {
        return [
            'event' => 'qa.reviewed',
            'title' => $this->title(),
            'body' => $this->summary(),
            'url' => route('agent.quality', ['review' => $this->review->ulid], absolute: false),
        ];
    }

    public function toMail(User $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->title())
            ->greeting('Hi '.Str::before(trim($notifiable->name).' ', ' ').',')
            ->line($this->summary())
            ->action('Read the feedback', route('agent.quality', ['review' => $this->review->ulid]));
    }

    public function databaseType(object $notifiable): string
    {
        return 'qa.reviewed';
    }

    private function title(): string
    {
        return 'Your call '.$this->review->call?->call_id.' was reviewed';
    }

    private function summary(): string
    {
        $business = $this->review->organization->name ?? 'a business';

        return "Score {$this->review->score}% (".($this->review->passed ? 'passed' : 'below the bar').") on your call for {$business}.";
    }
}
