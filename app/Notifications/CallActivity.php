<?php

namespace App\Notifications;

use App\Enums\NotificationEvent;
use App\Models\CallLog;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * Tells a business's members about a call our team handled (call.logged,
 * call.missed, followup.created). Queued, and only sent after the database
 * transaction commits, so it never refers to a call that was rolled back.
 */
class CallActivity extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public readonly CallLog $call,
        public readonly NotificationEvent $event,
    ) {
        $this->afterCommit();
    }

    /**
     * @return list<string>
     */
    public function via(User $notifiable): array
    {
        return $notifiable->notificationChannelsFor($this->event);
    }

    /**
     * In-app (database) payload. Kept small and free of sensitive detail.
     *
     * @return array<string, mixed>
     */
    public function toArray(User $notifiable): array
    {
        return [
            'event' => $this->event->value,
            'title' => $this->title(),
            'body' => $this->summary(),
            'call_id' => $this->call->call_id,
            'organization_id' => $this->call->organization_id,
            'url' => route('app.calls.show', $this->call->call_id, absolute: false),
        ];
    }

    public function toMail(User $notifiable): MailMessage
    {
        $business = $this->call->organization->name ?? config('app.name');

        $mail = (new MailMessage)
            ->subject($this->title().' · '.$business)
            ->greeting('Hi '.Str::before(trim($notifiable->name).' ', ' ').',')
            ->line($this->summary());

        if (filled($this->call->notes)) {
            $mail->line('Agent notes: '.Str::limit((string) $this->call->notes, 300));
        }

        return $mail
            ->action('View call', route('app.calls.show', $this->call->call_id))
            ->line('You can choose which notifications you get in your notification settings.');
    }

    public function title(): string
    {
        $caller = CallLog::display($this->call->caller_name);

        return match ($this->event) {
            NotificationEvent::CallMissed => "Missed call from {$caller}",
            NotificationEvent::FollowUpCreated => "{$caller} asked for a call back",
            default => "New call from {$caller}",
        };
    }

    private function summary(): string
    {
        $reason = Str::headline((string) $this->call->reason_for_call);
        $phone = $this->call->caller_phone ? ' ('.$this->call->caller_phone.')' : '';

        return CallLog::display($this->call->caller_name).$phone.' — '.$reason.'. Outcome: '.$this->call->statusLabel().'.';
    }

    /**
     * Event name for the database row, so the bell can filter and group.
     */
    public function databaseType(object $notifiable): string
    {
        return $this->event->value;
    }
}
