<?php

namespace App\Notifications;

use App\Enums\NotificationEvent;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * A new lead (D109): the team hears straight away, whatever the hour. The first business to call
 * back usually wins the job.
 */
class LeadReceived extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public readonly Lead $lead)
    {
        $this->afterCommit();
    }

    /** @return list<string> */
    public function via(User $notifiable): array
    {
        return $notifiable->notificationChannelsFor(NotificationEvent::LeadReceived);
    }

    private function title(): string
    {
        return 'New lead: '.($this->lead->name ?: 'someone').' ('.$this->lead->sourceLabel().')';
    }

    /** @return array<string, mixed> */
    public function toArray(User $notifiable): array
    {
        return [
            'event' => NotificationEvent::LeadReceived->value,
            'title' => $this->title(),
            'body' => Str::limit((string) $this->lead->message, 160) ?: 'Call them back now.',
            'organization_id' => $this->lead->organization_id,
            'url' => route('app.leads', [], false),
        ];
    }

    public function toMail(User $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject($this->title())
            ->greeting('Hi '.Str::before(trim($notifiable->name).' ', ' ').',')
            ->line($this->title().'.');
        if (filled($this->lead->message)) {
            $mail->line('"'.Str::limit((string) $this->lead->message, 500).'"');
        }

        return $mail->line(collect(['Phone' => $this->lead->phone, 'Email' => $this->lead->email])->filter()->map(fn ($v, $k) => "{$k}: {$v}")->implode(' · '))
            ->line('The first business to call back usually wins the job.')
            ->action('See leads', route('app.leads'));
    }

    public function databaseType(object $notifiable): string
    {
        return NotificationEvent::LeadReceived->value;
    }
}
