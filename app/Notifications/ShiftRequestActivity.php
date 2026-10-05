<?php

namespace App\Notifications;

use App\Models\ShiftRequest;
use App\Models\User;
use App\Notifications\Concerns\RespectsQuietHours;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * Shift requests (AGT-11): schedulers hear about new ones; the agent hears the decision.
 */
class ShiftRequestActivity extends Notification implements ShouldQueue
{
    use Queueable, RespectsQuietHours;

    public int $tries = 3;

    /**
     * @param  string  $kind  submitted | approved | declined
     */
    public function __construct(public readonly ShiftRequest $request, public readonly string $kind)
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
            'event' => 'shift_request.'.$this->kind,
            'title' => $this->title(),
            'body' => $this->request->summary(),
            'url' => $this->url(absolute: false),
        ];
    }

    public function toMail(User $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject($this->title())
            ->greeting('Hi '.Str::before(trim($notifiable->name).' ', ' ').',')
            ->line($this->request->summary().'.');

        if ($this->kind === 'submitted' && $this->request->reason) {
            $mail->line('Reason: '.$this->request->reason);
        }
        if ($this->kind !== 'submitted' && $this->request->decision_note) {
            $mail->line('Note: '.$this->request->decision_note);
        }

        return $mail->action($this->kind === 'submitted' ? 'Review the request' : 'Open your schedule', $this->url());
    }

    public function databaseType(object $notifiable): string
    {
        return 'shift_request.'.$this->kind;
    }

    private function title(): string
    {
        $what = $this->request->type === ShiftRequest::LEAVE ? 'time off' : 'shift swap';

        return match ($this->kind) {
            'submitted' => ($this->request->agent->name ?? 'An agent')." asked for a {$what}",
            'approved' => "Your {$what} request was approved",
            default => "Your {$what} request was declined",
        };
    }

    private function url(bool $absolute = true): string
    {
        return $this->kind === 'submitted' ? route('admin.schedule', absolute: $absolute) : route('agent.schedule', absolute: $absolute);
    }
}
