<?php

namespace App\Livewire\Agent;

use App\Jobs\SyncAgentCalendar;
use App\Livewire\Concerns\AgentWorkspaceOnly;
use App\Models\AgentCalendarConnection;
use App\Services\Calendar\AgentCalendarSync;
use App\Services\Calendar\CalendarManager;
use App\Support\Audit\Audit;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * My calendar (D54): the agent's shifts, time off and bookings in one calendar, next to busy
 * times from their own Google or Microsoft calendar, which can also receive their shifts.
 * Everything here is the signed-in person's own; nothing is visible to anyone else.
 */
#[Layout('layouts.portal', ['portal' => 'agent'])]
#[Title('My calendar')]
class Calendar extends Component
{
    use AgentWorkspaceOnly;

    /** @var array<string, array{busy: list<string>, write: string, push: bool}> provider => settings */
    public array $settings = [];

    public function mount(): void
    {
        foreach ($this->connections() as $connection) {
            $this->loadSettings($connection);
        }
    }

    private function loadSettings(AgentCalendarConnection $connection): void
    {
        $this->settings[$connection->provider] = [
            'busy' => $connection->busy_calendar_ids ?? [],
            'write' => (string) $connection->write_calendar_id,
            'push' => (bool) $connection->push_shifts,
        ];
    }

    /** @return Collection<int, AgentCalendarConnection> */
    private function connections()
    {
        return AgentCalendarConnection::query()->where('user_id', auth()->id())->orderBy('provider')->get();
    }

    /** Only the signed-in agent's own connection, by provider. */
    private function connection(string $provider): AgentCalendarConnection
    {
        return AgentCalendarConnection::query()->where('user_id', auth()->id())->where('provider', $provider)->firstOrFail();
    }

    public function save(string $provider, AgentCalendarSync $sync): void
    {
        $this->resetErrorBag();
        $connection = $this->connection($provider);
        $ids = collect($connection->calendars ?? [])->pluck('id')->all();
        $writable = collect($connection->calendars ?? [])->where('can_write', true)->pluck('id')->all();
        $settings = $this->settings[$provider] ?? ['busy' => [], 'write' => '', 'push' => false];
        $push = (bool) $settings['push'];
        if ($push && ! in_array($settings['write'], $writable, true)) {
            $this->addError("settings.{$provider}.write", 'Choose a calendar you can add events to.');

            return;
        }

        $before = [$connection->push_shifts, $connection->write_calendar_id];
        $connection->update([
            'busy_calendar_ids' => array_values(array_intersect((array) $settings['busy'], $ids)),
            'write_calendar_id' => $push ? $settings['write'] : $connection->write_calendar_id,
            'push_shifts' => $push,
        ]);
        if (! $push && $before[0]) {
            $sync->removeShifts($connection); // copying turned off: take our events back out
        } elseif ($push) {
            SyncAgentCalendar::dispatch($connection->id);
        }
        $this->loadSettings($connection->fresh());
        $this->dispatch('toast', type: 'success', message: 'Calendar settings saved.');
    }

    public function syncNow(string $provider): void
    {
        SyncAgentCalendar::dispatch($this->connection($provider)->id);
        $this->dispatch('toast', type: 'success', message: 'Updating your calendar now.');
    }

    public function disconnect(string $provider, AgentCalendarSync $sync, CalendarManager $calendars, Audit $audit): void
    {
        $connection = $this->connection($provider);
        $sync->removeShifts($connection);
        try {
            $calendars->provider($provider)->revoke((string) ($connection->refresh_token ?: $connection->access_token));
        } catch (\Throwable $e) {
            report($e); // the link is removed here either way
        }
        $audit->record('agent_calendar.disconnected', $connection, new: ['provider' => $provider], actor: auth()->user(), label: $calendars->provider($provider)->label());
        $connection->delete();
        unset($this->settings[$provider]);
        $this->dispatch('toast', type: 'success', message: $calendars->provider($provider)->label().' disconnected. Copied shifts were removed from it.');
    }

    public function render(CalendarManager $calendars): View
    {
        $connections = $this->connections()->keyBy('provider');
        $providers = collect($calendars->providers())->map(fn ($p, string $key) => [
            'key' => $key,
            'label' => $p->label(),
            'configured' => $p->isConfigured(),
            'connection' => $connections->get($key),
        ]);

        $sources = [
            ['key' => 'shifts', 'label' => 'My shifts', 'tag' => 'Shift', 'kind' => 'surehelp', 'connected' => true, 'status' => 'active'],
            ['key' => 'leave', 'label' => 'Time off', 'tag' => 'Time off', 'kind' => 'surehelp', 'connected' => true, 'status' => 'active'],
            ['key' => 'bookings', 'label' => 'My bookings', 'tag' => 'Booking', 'kind' => 'surehelp', 'connected' => true, 'status' => 'active'],
        ];
        foreach ($providers as $provider) {
            $connection = $provider['connection'];
            $sources[] = [
                'key' => $provider['key'], 'label' => $provider['label'], 'tag' => $provider['key'] === 'microsoft' ? 'Outlook' : 'Google', 'kind' => 'external',
                'connected' => $connection !== null, 'status' => $connection?->status, 'account' => $connection?->account_email,
                'synced' => $connection?->last_synced_at?->diffForHumans(),
            ];
        }

        return view('livewire.agent.calendar', [
            'providers' => $providers,
            'sources' => $sources,
            'timezone' => auth()->user()->timezoneOrDefault(),
        ]);
    }
}
