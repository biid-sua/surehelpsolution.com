<?php

namespace App\Notifications;

use App\Enums\NotificationEvent;
use App\Models\TrainingAssignment;
use App\Models\User;
use App\Notifications\Concerns\RespectsQuietHours;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * Training news (brief §1.14, §10): "You have new required training for ABC Plumbing.",
 * "Your ABC Plumbing training is overdue.", and, for the person who gave it, "Maria completed …".
 * Optional training is announced in the app only, so agents aren't flooded with email.
 */
class TrainingActivity extends Notification implements ShouldQueue
{
    use Queueable, RespectsQuietHours;

    public const ASSIGNED = 'assigned';

    public const DUE_SOON = 'due_soon';

    public const OVERDUE = 'overdue';

    /** To the person who gave the training (or supervisors), not the agent. */
    public const COMPLETED = 'completed';

    /** The agent's own overdue training, told to the person who gave it. */
    public const AGENT_OVERDUE = 'agent_overdue';

    public int $tries = 3;

    public function __construct(public readonly TrainingAssignment $assignment, public readonly string $change)
    {
        $this->afterCommit();
    }

    private function event(): NotificationEvent
    {
        return match ($this->change) {
            self::ASSIGNED => NotificationEvent::TrainingAssigned,
            self::COMPLETED, self::AGENT_OVERDUE => NotificationEvent::TrainingCompleted,
            default => NotificationEvent::TrainingDue,
        };
    }

    /**
     * @return list<string>
     */
    public function via(User $notifiable): array
    {
        $channels = $notifiable->notificationChannelsFor($this->event());

        return $this->change === self::ASSIGNED && ! $this->assignment->is_required
            ? array_values(array_intersect($channels, ['database']))
            : $channels;
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
            'url' => $this->url(false),
        ];
    }

    public function toMail(User $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->title())
            ->greeting('Hi '.Str::before(trim($notifiable->name).' ', ' ').',')
            ->line($this->body())
            ->action(in_array($this->change, [self::COMPLETED, self::AGENT_OVERDUE], true) ? 'See training progress' : 'Open the course', $this->url(true));
    }

    private function url(bool $absolute): string
    {
        return in_array($this->change, [self::COMPLETED, self::AGENT_OVERDUE], true)
            ? route('agent.training.progress', absolute: $absolute)
            : route('agent.university.course', $this->assignment->course->ulid, $absolute);
    }

    /** "ABC Plumbing training" for company training, else the course title. */
    private function subject(): string
    {
        $course = $this->assignment->course;
        $company = $course->organization->name ?? $this->assignment->organization?->name;

        return $company && $course->organization_id ? $company.' training' : '"'.$course->title.'"';
    }

    private function title(): string
    {
        $agent = $this->assignment->agent->name ?? 'An agent';

        return match ($this->change) {
            self::ASSIGNED => $this->assignment->is_required
                ? 'You have new required training'.(($company = $this->assignment->organization?->name) ? ' for '.$company : '')
                : 'New recommended training: '.$this->assignment->course->title,
            self::DUE_SOON => 'Your '.$this->subject().' is due soon',
            self::OVERDUE => 'Your '.$this->subject().' is overdue',
            self::AGENT_OVERDUE => $agent.'\'s training is overdue',
            default => $agent.' completed "'.$this->assignment->course->title.'"',
        };
    }

    private function body(): string
    {
        $course = $this->assignment->course->title;
        $due = $this->assignment->due_at?->setTimezone(config('app.timezone'))->format('l j M Y');

        return match ($this->change) {
            self::ASSIGNED => '"'.$course.'"'.($due ? ' is due by '.$due.'.' : ' is waiting for you in Agent University.')
                .($this->assignment->is_required && $this->assignment->organization ? ' Complete it before your next shift for '.$this->assignment->organization->name.'.' : ''),
            self::DUE_SOON => '"'.$course.'" is due by '.$due.'. You\'re '.$this->assignment->progress_percent.'% of the way there.',
            self::OVERDUE => '"'.$course.'" was due on '.$due.'. Please complete it as soon as you can.',
            self::AGENT_OVERDUE => '"'.$course.'" was due on '.$due.' and isn\'t finished ('.$this->assignment->progress_percent.'%).',
            default => 'Version '.$this->assignment->completed_version.($this->assignment->best_score !== null ? ', score '.$this->assignment->best_score.'%' : '').'.',
        };
    }

    public function databaseType(object $notifiable): string
    {
        return $this->event()->value;
    }
}
