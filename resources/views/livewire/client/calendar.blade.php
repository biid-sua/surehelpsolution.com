<div>
    <x-ui.page-header title="Calendar" description="Appointments and service visits for {{ $organization->name }}, in your business's time." />

    @if ($connections->isEmpty())
        <x-ui.alert tone="info" class="mb-6" title="Connect your Google or Microsoft calendar">
            Bookings land in the calendar you already use, and your busy times stop our agents double-booking you.
            @can('integrations.view')<a href="{{ route('app.business.calendars') }}" class="font-semibold underline underline-offset-2">Set up calendar sync</a>@endcan
        </x-ui.alert>
    @elseif ($connections->contains('status', 'needs_reauth'))
        <x-ui.alert tone="danger" class="mb-6" title="A connected calendar needs reconnecting">
            New bookings aren't being added to it and its busy times may be out of date. <a href="{{ route('app.business.calendars') }}" class="font-semibold underline underline-offset-2">Reconnect</a>
        </x-ui.alert>
    @else
        <p class="mb-4 text-sm text-muted">Synced with {{ $connections->map(fn ($c) => $c->account_email ?? ucfirst($c->provider))->join(', ') }}. Shaded areas are busy in your calendar.</p>
    @endif

    <x-ui.card>
        <div wire:ignore x-data="calendar(@js(route('app.calendar.events')))">
            <div x-ref="calendar" class="min-h-[28rem]" aria-label="Appointments calendar"></div>
        </div>
    </x-ui.card>
</div>
