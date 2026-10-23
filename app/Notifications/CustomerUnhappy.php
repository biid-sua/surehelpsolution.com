<?php

namespace App\Notifications;

use App\Enums\NotificationEvent;
use App\Models\Organization;
use App\Models\Survey;
use App\Models\User;
use App\Notifications\Concerns\RespectsQuietHours;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * A customer gave a low score in a satisfaction survey (D84).
 */
class CustomerUnhappy extends Notification implements ShouldQueue
{
    use Queueable, RespectsQuietHours;

    public int $tries = 3;

    public function __construct(public readonly Organization $organization, public readonly Survey $survey)
    {
        $this->afterCommit();
    }

    /** @return list<string> */
    public function via(User $notifiable): array
    {
        return $notifiable->notificationChannelsFor(NotificationEvent::CustomerUnhappy);
    }

    private function title(): string
    {
        return ($this->survey->customer?->fullName() ?: 'A customer').' rated you '.$this->survey->score.' out of 5';
    }

    /** @return array<string, mixed> */
    public function toArray(User $notifiable): array
    {
        return [
            'event' => NotificationEvent::CustomerUnhappy->value,
            'title' => $this->title(),
            'body' => 'A call-back task was added. Reaching out quickly often turns this around.',
            'organization_id' => $this->organization->id,
            'url' => $this->survey->customer ? route('app.customers.show', $this->survey->customer->ulid, false) : route('app.tasks.index', [], false),
        ];
    }

    public function toMail(User $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->title())
            ->greeting('Hi '.Str::before(trim($notifiable->name).' ', ' ').',')
            ->line($this->title().' in their satisfaction survey.')
            ->line('We added a task to call them. Reaching out quickly often turns this around, before it becomes a public review.')
            ->action('Open the customer', $this->survey->customer ? route('app.customers.show', $this->survey->customer->ulid) : route('app.tasks.index'));
    }

    public function databaseType(object $notifiable): string
    {
        return NotificationEvent::CustomerUnhappy->value;
    }
}
