<?php

namespace App\Livewire\Client\Business;

use App\Jobs\SyncCalendarConnection;
use App\Livewire\Concerns\ScopedToOrganization;
use App\Models\Appointment;
use App\Models\CalendarConnection;
use App\Services\Calendar\CalendarManager;
use App\Services\Calendar\CalendarSync;
use App\Support\Audit\Audit;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Calendar integrations (spec §18): connect, choose calendars, sync now, disconnect.
 * No token or secret is ever a public property: the browser only sees names and statuses.
 */
#[Layout('layouts.portal', ['portal' => 'client'])]
#[Title('Calendar sync')]
class Calendars extends Component
{
    use ScopedToOrganization;

    /** @var array<string, string> provider => calendar id bookings are written to */
    public array $writeCalendar = [];

    /** @var array<string, list<string>> provider => calendar ids that block time */
    public array $busyCalendars = [];

    public function mount(): void
    {
        $this->authorize('integrations.view', $this->organization());

        foreach ($this->connections() as $connection) {
            $this->writeCalendar[$connection->provider] = (string) $connection->write_calendar_id;
            $this->busyCalendars[$connection->provider] = $connection->busy_calendar_ids ?? [];
        }

        if ($message = session('success')) {
            $this->dispatch('toast', type: 'success', message: $message);
        }
        if ($message = session('error')) {
            $this->dispatch('toast', type: 'error', message: $message);
        }
    }

    public function save(string $provider, Audit $audit, CalendarSync $sync): void
    {
        $this->authorize('integrations.manage', $this->organization());
        $this->resetValidation();
        $connection = $this->connection($provider);
        $ids = collect($connection->calendars ?? []);

        $write = $this->writeCalendar[$provider] ?? '';
        $busy = array_values(array_intersect($this->busyCalendars[$provider] ?? [], $ids->pluck('id')->all()));
        $writable = $ids->where('can_write', true)->pluck('id')->all();

        if ($write !== '' && ! in_array($write, $writable, true)) {
            $this->addError("writeCalendar.{$provider}", 'Choose a calendar you can add events to.');

            return;
        }

        $connection->forceFill(['write_calendar_id' => $write ?: null, 'busy_calendar_ids' => $busy])->save();
        $audit->changes('calendar.updated', $connection, ['write_calendar_id', 'busy_calendar_ids']);

        SyncCalendarConnection::dispatch($connection->id);
        $sync->ensurePush($connection);
        $this->dispatch('toast', type: 'success', message: 'Saved. Busy times are being refreshed.');
    }

    public function syncNow(string $provider, CalendarSync $sync): void
    {
        $this->authorize('integrations.manage', $this->organization());
        $connection = $this->connection($provider);

        $count = $sync->pullBusy($connection);
        $connection->refresh();

        $this->dispatch('toast', type: $connection->last_error ? 'error' : 'success',
            message: $connection->last_error ? 'Sync failed: we\'ll keep retrying automatically.' : "Up to date: {$count} busy ".str('period')->plural($count).' found.');
    }

    public function disconnect(string $provider, CalendarManager $calendars, CalendarSync $sync, Audit $audit): void
    {
        $organization = $this->organization();
        $this->authorize('integrations.manage', $organization);
        $connection = $this->connection($provider);
        $adapter = $calendars->provider($provider);

        $sync->stopPush($connection);
        rescue(fn () => $adapter->revoke($connection->refresh_token ?: $connection->access_token), report: false);

        // Forget our event ids for this connection; the events already in their calendar stay there.
        $key = $connection->refKey();
        Appointment::query()->forOrganization($organization)->whereNotNull('external_refs')->each(function (Appointment $a) use ($key) {
            $refs = $a->external_refs ?? [];
            if (isset($refs[$key])) {
                unset($refs[$key]);
                $a->forceFill(['external_refs' => $refs ?: null])->saveQuietly();
            }
        });

        $connection->delete();   // busy blocks cascade
        $audit->record('calendar.disconnected', $connection, old: ['provider' => $provider, 'account' => $connection->account_email], organization: $organization, label: $adapter->label());

        unset($this->writeCalendar[$provider], $this->busyCalendars[$provider]);
        $this->dispatch('toast', type: 'success', message: $adapter->label().' disconnected.');
    }

    private function connection(string $provider): CalendarConnection
    {
        return CalendarConnection::query()->forOrganization($this->organization())->where('provider', $provider)->firstOrFail();
    }

    /** @return Collection<int, CalendarConnection> */
    private function connections(): Collection
    {
        return CalendarConnection::query()->forOrganization($this->organization())->get();
    }

    public function render(CalendarManager $calendars): View
    {
        $organization = $this->organization();
        $connections = $this->connections()->keyBy('provider');

        return view('livewire.client.business.calendars', [
            'providers' => $calendars->providers(),
            'connections' => $connections,
            'conflicts' => Appointment::query()->forOrganization($organization)->whereNotNull('external_refs')->where('ends_at', '>=', now())->get()
                ->filter(fn (Appointment $a) => collect($a->external_refs)->contains(fn ($r) => ! empty($r['conflict']))),
            'canManage' => auth()->user()->can('integrations.manage', $organization),
        ]);
    }
}
