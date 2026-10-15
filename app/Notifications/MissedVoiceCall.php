<?php

namespace App\Notifications;

use App\Enums\NotificationEvent;
use App\Models\User;
use App\Models\VoiceCall;
use App\Notifications\Concerns\RespectsQuietHours;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * A call to the business's SureHelp line that nobody answered (D60). Follows the member's
 * call.missed preferences; a call-back task was already created.
 */
class MissedVoiceCall extends Notification implements ShouldQueue
{
    use Queueable, RespectsQuietHours;

    public int $tries = 3;

    public function __construct(
        public readonly VoiceCall $call,
        public readonly string $caller,
    ) {
        $this->afterCommit();
    }

    /** @return list<string> */
    public function via(User $notifiable): array
    {
        return $notifiable->notificationChannelsFor(NotificationEvent::CallMissed);
    }

    /** @return array<string, mixed> */
    public function toArray(User $notifiable): array
    {
        return [
            'event' => NotificationEvent::CallMissed->value,
            'title' => $this->title(),
            'body' => $this->body(),
            'organization_id' => $this->call->organization_id,
            'url' => route('app.tasks.index', absolute: false),
        ];
    }

    public function toMail(User $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->title().' · '.($this->call->organization->name ?? config('app.name')))
            ->greeting('Hi '.Str::before(trim($notifiable->name).' ', ' ').',')
            ->line($this->body())
            ->action('Open your tasks', route('app.tasks.index'))
            ->line('You can choose which notifications you get in your notification settings.');
    }

    public function title(): string
    {
        return ($this->call->status === VoiceCall::VOICEMAIL ? 'Voicemail from ' : 'Missed call from ').$this->caller;
    }

    private function body(): string
    {
        return $this->caller.' ('.$this->call->fromDisplay().') called your SureHelp line. We added a call-back task for you.';
    }

    public function databaseType(object $notifiable): string
    {
        return NotificationEvent::CallMissed->value;
    }
}
