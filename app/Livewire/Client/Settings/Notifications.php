<?php

namespace App\Livewire\Client\Settings;

use App\Enums\NotificationEvent;
use App\Livewire\Concerns\ScopedToOrganization;
use App\Models\NotificationPreference;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Each person chooses how they hear about each event (NTF-02). Personal, not
 * business-wide: one owner may want email, their staff only in-app.
 */
#[Layout('layouts.portal', ['portal' => 'client'])]
#[Title('Notification settings')]
class Notifications extends Component
{
    use ScopedToOrganization;

    /** @var array<string, array<string, bool>> event case name (e.g. CallMissed) => channel => on/off. Case names avoid dots, which Livewire treats as nesting. */
    public array $preferences = [];

    public function mount(): void
    {
        $user = auth()->user()->load('notificationPreferences');

        foreach (NotificationEvent::available() as $event) {
            $chosen = $user->notificationChannelsFor($event);
            foreach ($this->enabledChannels() as $channel) {
                $this->preferences[$event->name][$channel] = in_array($channel, $chosen, true);
            }
        }
    }

    public function save(): void
    {
        $user = auth()->user();

        foreach (NotificationEvent::available() as $event) {
            $channels = array_values(array_filter(
                $this->enabledChannels(),
                fn (string $channel) => (bool) ($this->preferences[$event->name][$channel] ?? false),
            ));

            NotificationPreference::updateOrCreate(
                ['user_id' => $user->id, 'event' => $event->value],
                ['channels' => $channels],
            );
        }

        $this->dispatch('toast', type: 'success', message: 'Notification settings saved.');
    }

    /**
     * @return list<string>
     */
    private function enabledChannels(): array
    {
        return array_keys(array_filter(config('notifications.channels'), fn (array $c) => $c['enabled']));
    }

    public function render(): View
    {
        return view('livewire.client.settings.notifications', [
            'events' => NotificationEvent::available(),
            'channels' => config('notifications.channels'),
        ]);
    }
}
