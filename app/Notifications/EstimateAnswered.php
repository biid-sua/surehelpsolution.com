<?php

namespace App\Notifications;

use App\Enums\NotificationEvent;
use App\Models\JobDocument;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/** A customer accepted or declined an estimate (D115): the team can book the job or follow up. */
class EstimateAnswered extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public readonly JobDocument $estimate)
    {
        $this->afterCommit();
    }

    /** @return list<string> */
    public function via(User $notifiable): array
    {
        return $notifiable->notificationChannelsFor(NotificationEvent::EstimateAnswered);
    }

    private function title(): string
    {
        $who = $this->estimate->customer?->fullName() ?? 'A customer';

        return $this->estimate->status === JobDocument::ACCEPTED
            ? $who.' accepted '.$this->estimate->label().' ('.$this->estimate->total().')'
            : $who.' declined '.$this->estimate->label();
    }

    /** @return array<string, mixed> */
    public function toArray(User $notifiable): array
    {
        return [
            'event' => NotificationEvent::EstimateAnswered->value,
            'title' => $this->title(),
            'body' => $this->estimate->status === JobDocument::ACCEPTED ? 'Book the job, or turn it into an invoice.' : (string) ($this->estimate->declined_reason ?: 'Follow up to see what would change their mind.'),
            'organization_id' => $this->estimate->organization_id,
            'url' => route('app.documents.edit', $this->estimate, false),
        ];
    }

    public function toMail(User $notifiable): MailMessage
    {
        $mail = (new MailMessage)->subject($this->title())->greeting('Hi '.Str::before(trim($notifiable->name).' ', ' ').',')->line($this->title().'.');
        if ($this->estimate->declined_reason) {
            $mail->line('"'.Str::limit($this->estimate->declined_reason, 500).'"');
        }

        return $mail->action('Open the estimate', route('app.documents.edit', $this->estimate));
    }

    public function databaseType(object $notifiable): string
    {
        return NotificationEvent::EstimateAnswered->value;
    }
}
