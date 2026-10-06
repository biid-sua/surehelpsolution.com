<?php

namespace App\Livewire\Account;

use App\Enums\NotificationEvent;
use App\Models\NotificationPreference;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Notification choices for SureHelp's own people (NTF-02, brief §1.14): company assignments,
 * training and certifications, plus quiet hours. Businesses choose theirs in their own settings.
 */
#[Layout('layouts.portal')]
#[Title('Notifications')]
class Notifications extends Component
{
    /** Workforce events, in the order shown. */
    private const EVENTS = [
        NotificationEvent::AssignmentStarted, NotificationEvent::AssignmentChanged, NotificationEvent::TrainingAssigned,
        NotificationEvent::TrainingDue, NotificationEvent::CertificationExpiring, NotificationEvent::TrainingCompleted,
    ];

    /** @var array<string, array<string, bool>> event case name => channel => on/off */
    public array $preferences = [];

    public bool $quietOn = false;

    public string $quietStart = '21:00';

    public string $quietEnd = '07:00';

    public function mount(): void
    {
        $user = auth()->user()->load('notificationPreferences');
        abort_if($user->isClient(), 404); // businesses use Settings › Notifications
        foreach ($this->events() as $event) {
            $chosen = $user->notificationChannelsFor($event);
            foreach ($this->enabledChannels() as $channel) {
                $this->preferences[$event->name][$channel] = in_array($channel, $chosen, true);
            }
        }
        $this->quietOn = $user->quiet_hours_start !== null;
        $this->quietStart = $user->quiet_hours_start ?? '21:00';
        $this->quietEnd = $user->quiet_hours_end ?? '07:00';
    }

    public function save(): void
    {
        $user = auth()->user();
        abort_if($user->isClient(), 404);
        $this->validate([
            'quietStart' => ['required_if:quietOn,true', 'date_format:H:i'],
            'quietEnd' => ['required_if:quietOn,true', 'date_format:H:i', 'different:quietStart'],
        ], ['quietEnd.different' => 'Quiet hours need to end at a different time than they start.']);

        $user->forceFill([
            'quiet_hours_start' => $this->quietOn ? $this->quietStart : null,
            'quiet_hours_end' => $this->quietOn ? $this->quietEnd : null,
        ])->save();
        foreach ($this->events() as $event) {
            NotificationPreference::updateOrCreate(['user_id' => $user->id, 'event' => $event->value], ['channels' => array_values(array_filter(
                $this->enabledChannels(), fn (string $channel) => (bool) ($this->preferences[$event->name][$channel] ?? false),
            ))]);
        }

        $this->dispatch('toast', type: 'success', message: 'Notification settings saved.');
    }

    /** @return list<NotificationEvent> the workforce events this person can receive */
    private function events(): array
    {
        $user = auth()->user();

        return array_values(array_filter(self::EVENTS, fn (NotificationEvent $e) => $user->hasPermissionIn((string) $e->requiredPermission())));
    }

    /** @return list<string> */
    private function enabledChannels(): array
    {
        return array_keys(array_filter(config('notifications.channels'), fn (array $c) => $c['enabled']));
    }

    public function render(): View
    {
        return view('livewire.account.notifications', [
            'events' => $this->events(),
            'channels' => config('notifications.channels'),
        ]);
    }
}
