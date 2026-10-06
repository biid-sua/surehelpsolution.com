<?php

namespace App\Notifications;

use App\Enums\NotificationEvent;
use App\Models\AgentAssignment;
use App\Models\User;
use App\Notifications\Concerns\RespectsQuietHours;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * Tells an agent about their company assignments (spec §20A, brief §10):
 * "You have been assigned to ABC Plumbing." / "Your assignment to ABC Plumbing has ended."
 */
class AssignmentActivity extends Notification implements ShouldQueue
{
    use Queueable, RespectsQuietHours;

    public const STARTED = 'started';

    public const SCHEDULED = 'scheduled';

    public const SUSPENDED = 'suspended';

    public const ENDED = 'ended';

    public int $tries = 3;

    public function __construct(public readonly AgentAssignment $assignment, public readonly string $change)
    {
        $this->afterCommit();
    }

    private function event(): NotificationEvent
    {
        return $this->change === self::STARTED ? NotificationEvent::AssignmentStarted : NotificationEvent::AssignmentChanged;
    }

    /**
     * @return list<string>
     */
    public function via(User $notifiable): array
    {
        return $notifiable->notificationChannelsFor($this->event());
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(User $notifiable): array
    {
        return [
            'event' => $this->event()->value,
            'title' => $this->title(),
            'body' => $this->body(),
            'url' => $this->change === self::STARTED ? route('agent.companies', absolute: false) : route('agent.home', absolute: false),
        ];
    }

    public function toMail(User $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->title())
            ->greeting('Hi '.Str::before(trim($notifiable->name).' ', ' ').',')
            ->line($this->body())
            ->action('Open the agent portal', $this->change === self::STARTED ? route('agent.companies') : route('agent.home'));
    }

    private function company(): string
    {
        return $this->assignment->organization->name ?? 'a company';
    }

    private function title(): string
    {
        return match ($this->change) {
            self::STARTED => 'You have been assigned to '.$this->company(),
            self::SCHEDULED => 'You will be assigned to '.$this->company(),
            self::SUSPENDED => 'Your assignment to '.$this->company().' is paused',
            default => 'Your assignment to '.$this->company().' has ended',
        };
    }

    private function body(): string
    {
        $timezone = config('app.timezone');

        return match ($this->change) {
            self::STARTED => $this->company().' now appears under My Companies.'.($this->assignment->ends_at ? ' Until '.$this->assignment->ends_at->setTimezone($timezone)->format('j M Y').'.' : ''),
            self::SCHEDULED => 'From '.$this->assignment->starts_at?->setTimezone($timezone)->format('l j M Y, g:i A').' UTC. We\'ll remind you when it starts.',
            self::SUSPENDED => 'You can\'t work for '.$this->company().' until a supervisor resumes the assignment.',
            default => 'You no longer have access to '.$this->company().'.'.($this->assignment->end_reason ? ' Reason: '.$this->assignment->end_reason : ''),
        };
    }

    public function databaseType(object $notifiable): string
    {
        return $this->event()->value;
    }
}
