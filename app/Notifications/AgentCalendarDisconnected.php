<?php

namespace App\Notifications;

use App\Models\AgentCalendarConnection;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * An agent's own calendar stopped working (D54): access was revoked or expired. In the app only:
 * nothing urgent is lost, shifts just stop being copied until it's reconnected.
 */
class AgentCalendarDisconnected extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly AgentCalendarConnection $calendar)
    {
        $this->afterCommit();
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        $label = $this->calendar->provider === 'microsoft' ? 'Microsoft calendar' : 'Google Calendar';

        return [
            'event' => 'agent_calendar.disconnected',
            'title' => 'Reconnect your '.$label,
            'body' => 'We lost access to '.($this->calendar->account_email ?: 'your calendar').'. Your shifts aren\'t being copied to it until you reconnect.',
            'url' => route('agent.calendar', absolute: false),
        ];
    }

    public function databaseType(object $notifiable): string
    {
        return 'agent_calendar.disconnected';
    }
}
