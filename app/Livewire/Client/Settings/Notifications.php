<?php

namespace App\Livewire\Client\Settings;

use App\Enums\NotificationEvent;
use App\Livewire\Concerns\ScopedToOrganization;
use App\Models\NotificationPreference;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
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

    /** "HH:MM" or "off" (NTF-04). */
    public string $summaryAt = 'off';

    public bool $quietOn = false;

    public string $quietStart = '21:00';

    public string $quietEnd = '07:00';

    public function mount(): void
    {
        $user = auth()->user()->load('notificationPreferences');

        foreach ($this->events() as $event) {
            $chosen = $user->notificationChannelsFor($event);
            foreach ($this->enabledChannels() as $channel) {
                $this->preferences[$event->name][$channel] = in_array($channel, $chosen, true);
            }
        }

        $this->summaryAt = $user->dailySummaryTime() ?? 'off';
        $this->quietOn = $user->quiet_hours_start !== null;
        $this->quietStart = $user->quiet_hours_start ?? '21:00';
        $this->quietEnd = $user->quiet_hours_end ?? '07:00';
    }

    public function save(): void
    {
        $user = auth()->user();
        $this->validate([
            'summaryAt' => ['required', Rule::in(['off', ...array_keys(self::summaryTimes())])],
            'quietStart' => ['required_if:quietOn,true', 'date_format:H:i'],
            'quietEnd' => ['required_if:quietOn,true', 'date_format:H:i', 'different:quietStart'],
        ], ['quietEnd.different' => 'Quiet hours need to end at a different time than they start.']);
        $user->forceFill([
            'daily_summary_at' => $this->summaryAt,
            'quiet_hours_start' => $this->quietOn ? $this->quietStart : null,
            'quiet_hours_end' => $this->quietOn ? $this->quietEnd : null,
        ])->save();

        foreach ($this->events() as $event) {
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
     * Events this person can receive in this business (no billing alerts for staff, for example).
     *
     * @return list<NotificationEvent>
     */
    private function events(): array
    {
        $user = auth()->user();

        return array_values(array_filter(NotificationEvent::available(),
            fn (NotificationEvent $e) => $e->requiredPermission() === null || $user->hasPermissionIn($e->requiredPermission(), $this->organization())));
    }

    /**
     * @return list<string>
     */
    private function enabledChannels(): array
    {
        return array_keys(array_filter(config('notifications.channels'), fn (array $c) => $c['enabled']));
    }

    /** @return array<string, string> half-hourly from 5:00 to 11:00 */
    public static function summaryTimes(): array
    {
        $times = [];
        for ($m = 5 * 60; $m <= 11 * 60; $m += 30) {
            $key = sprintf('%02d:%02d', intdiv($m, 60), $m % 60);
            $times[$key] = CarbonImmutable::createFromTimeString($key)->format('g:i A');
        }

        return $times;
    }

    public function render(): View
    {
        return view('livewire.client.settings.notifications', [
            'summaryTimes' => self::summaryTimes(),
            'timezone' => auth()->user()->timezoneOrDefault(),
            'events' => $this->events(),
            'channels' => config('notifications.channels'),
        ]);
    }
}
