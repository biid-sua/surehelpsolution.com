<?php

namespace App\Notifications;

use App\Enums\EscalationPriority;
use App\Enums\NotificationEvent;
use App\Models\Escalation;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * An escalation was raised, is still unanswered, or was handed to someone (escalation.created).
 *
 * Urgent escalations can't be switched off (NTF-03): they always arrive in-app and by email,
 * whatever the person chose. SMS joins once messaging exists (docs/decisions.md D17).
 */
class EscalationActivity extends Notification implements ShouldQueue
{
    use Queueable;

    public const RAISED = 'raised';

    public const REMINDER = 'reminder';

    public const ASSIGNED = 'assigned';

    /** Channels urgent escalations always use. */
    public const FORCED_CHANNELS = ['database', 'mail'];

    public int $tries = 3;

    public function __construct(
        public readonly Escalation $escalation,
        public readonly string $kind = self::RAISED,
    ) {
        $this->afterCommit();
    }

    /** Urgent escalations ignore quiet hours: they're the reason someone wants to be woken. */
    public function withDelay(object $notifiable): array
    {
        if ($this->escalation->priority === EscalationPriority::Urgent) {
            return [];
        }
        $until = $notifiable instanceof User ? $notifiable->quietUntil() : null;

        return $until ? ['mail' => $until] : [];
    }

    /**
     * @return list<string>
     */
    public function via(User $notifiable): array
    {
        $channels = $notifiable->notificationChannelsFor(NotificationEvent::EscalationCreated);

        if ($this->escalation->priority === EscalationPriority::Urgent) {
            $forced = array_filter(self::FORCED_CHANNELS, fn (string $c) => (bool) config("notifications.channels.{$c}.enabled"));
            $channels = array_values(array_unique([...$channels, ...$forced]));
        }

        return $channels;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(User $notifiable): array
    {
        return [
            'event' => NotificationEvent::EscalationCreated->value,
            'kind' => $this->kind,
            'priority' => $this->escalation->priority->value,
            'title' => $this->title(),
            'body' => $this->summary(),
            'escalation_id' => $this->escalation->ulid,
            'organization_id' => $this->escalation->organization_id,
            'url' => route('app.escalations.index', ['escalation' => $this->escalation->ulid], absolute: false),
        ];
    }

    public function toMail(User $notifiable): MailMessage
    {
        $business = $this->escalation->organization->name ?? config('app.name');
        $mail = (new MailMessage)
            ->subject($this->title().' · '.$business)
            ->greeting('Hi '.Str::before(trim($notifiable->name).' ', ' ').',')
            ->line($this->summary());

        if ($this->escalation->priority === EscalationPriority::Urgent) {
            $mail->level('error');
        }
        if (filled($this->escalation->details)) {
            $mail->line('Details: '.Str::limit((string) $this->escalation->details, 400));
        }

        return $mail
            ->action('Open escalation', route('app.escalations.index', ['escalation' => $this->escalation->ulid]))
            ->line($this->escalation->priority === EscalationPriority::Urgent
                ? 'Urgent escalations are always sent, whatever your notification settings.'
                : 'You can choose which notifications you get in your notification settings.');
    }

    public function title(): string
    {
        $label = $this->escalation->type->label();

        return match ($this->kind) {
            self::REMINDER => 'Still waiting: '.$label,
            self::ASSIGNED => 'Escalation for you: '.$label,
            default => ($this->escalation->priority === EscalationPriority::Urgent ? 'URGENT: ' : 'Escalation: ').$label,
        };
    }

    private function summary(): string
    {
        $who = $this->escalation->customer?->fullName();

        return trim(($who ? $who.' — ' : '').$this->escalation->reason);
    }

    public function databaseType(object $notifiable): string
    {
        return NotificationEvent::EscalationCreated->value;
    }
}
