<?php

namespace App\Notifications;

use App\Enums\NotificationEvent;
use App\Models\Task;
use App\Models\User;
use App\Notifications\Concerns\RespectsQuietHours;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * A task was given to someone (task.assigned) or is past due (followup.overdue).
 * Queued and sent after commit, like CallActivity.
 */
class TaskActivity extends Notification implements ShouldQueue
{
    use Queueable, RespectsQuietHours;

    public const ASSIGNED = NotificationEvent::TaskAssigned;

    public const OVERDUE = NotificationEvent::FollowUpOverdue;

    public int $tries = 3;

    public function __construct(
        public readonly Task $task,
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
            'task_id' => $this->task->ulid,
            'organization_id' => $this->task->organization_id,
            'url' => route('app.tasks.index', ['task' => $this->task->ulid], absolute: false),
        ];
    }

    public function toMail(User $notifiable): MailMessage
    {
        $business = $this->task->organization->name ?? config('app.name');

        return (new MailMessage)
            ->subject($this->title().' · '.$business)
            ->greeting('Hi '.Str::before(trim($notifiable->name).' ', ' ').',')
            ->line($this->summary())
            ->action('Open task', route('app.tasks.index', ['task' => $this->task->ulid]))
            ->line('You can choose which notifications you get in your notification settings.');
    }

    public function title(): string
    {
        return match ($this->event) {
            NotificationEvent::FollowUpOverdue => 'Overdue: '.Str::limit($this->task->title, 80),
            default => 'New task: '.Str::limit($this->task->title, 80),
        };
    }

    private function summary(): string
    {
        $due = $this->task->due_at
            ? ' Due '.$this->task->due_at->setTimezone($this->task->organization->timezoneOrDefault())->format('D j M, g:i A').'.'
            : '';

        return match ($this->event) {
            NotificationEvent::FollowUpOverdue => $this->task->type->label().' "'.$this->task->title.'" is past due.'.$due,
            default => ($this->task->creator->name ?? 'Your team').' gave you a task: "'.$this->task->title.'".'.$due,
        };
    }

    /**
     * Event name for the database row, so the bell can filter and group.
     */
    public function databaseType(object $notifiable): string
    {
        return $this->event->value;
    }
}
