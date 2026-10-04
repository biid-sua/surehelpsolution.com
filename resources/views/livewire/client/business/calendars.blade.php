<div>
    <x-ui.page-header title="Business" description="Connect the calendar you already use. Bookings our agents make appear in it, and its busy times stop them double-booking you." />
    @include('livewire.client.business._tabs')

    <div class="grid gap-6 lg:grid-cols-2">
        @foreach ($providers as $key => $provider)
            @php($connection = $connections->get($key))
            <x-ui.card wire:key="cal-{{ $key }}">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <h2 class="text-base font-semibold text-ink">{{ $provider->label() }}</h2>
                        @if ($connection)
                            <p class="mt-1 text-sm text-muted">{{ $connection->account_email ?? 'Connected account' }}</p>
                        @endif
                    </div>
                    @if (! $connection)
                        <x-ui.badge>Not connected</x-ui.badge>
                    @elseif ($connection->status === 'needs_reauth')
                        <x-ui.badge tone="danger">Needs reconnecting</x-ui.badge>
                    @elseif ($connection->last_error)
                        <x-ui.badge tone="warning">Sync problem</x-ui.badge>
                    @else
                        <x-ui.badge tone="success">Connected</x-ui.badge>
                    @endif
                </div>

                @if ($connection)
                    <p class="mt-3 text-sm text-muted">
                        Last synced: {{ $connection->last_synced_at ? $connection->last_synced_at->diffForHumans() : 'not yet' }}
                        @if ($connection->status === 'needs_reauth')
                            <span class="mt-1 block text-danger">We lost access to this calendar. New bookings aren't being added to it and its busy times may be out of date.</span>
                        @elseif ($connection->last_error)
                            <span class="mt-1 block text-amber-300">The last sync didn't finish. We retry automatically every 10 minutes.</span>
                        @endif
                    </p>

                    @if ($canManage && $connection->status !== 'needs_reauth')
                        <form wire:submit="save('{{ $key }}')" class="mt-5 space-y-4">
                            <div>
                                <label for="w-{{ $key }}" class="sh-label">Add bookings to</label>
                                <select id="w-{{ $key }}" wire:model="writeCalendar.{{ $key }}" class="sh-input">
                                    <option value="">Don't add bookings (only read busy times)</option>
                                    @foreach (collect($connection->calendars)->where('can_write', true) as $cal)
                                        <option value="{{ $cal['id'] }}">{{ $cal['name'] }}{{ $cal['primary'] ? ' (main)' : '' }}</option>
                                    @endforeach
                                </select>
                                @error('writeCalendar.'.$key) <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                            </div>
                            <fieldset>
                                <legend class="sh-label">Calendars that mark you as busy</legend>
                                <div class="space-y-1">
                                    @foreach ($connection->calendars ?? [] as $cal)
                                        <label class="flex items-center gap-2 text-sm text-ink">
                                            <input type="checkbox" value="{{ $cal['id'] }}" wire:model="busyCalendars.{{ $key }}" class="size-4 rounded border-line-strong bg-surface-2 text-brand-500">
                                            {{ $cal['name'] }}{{ $cal['primary'] ? ' (main)' : '' }}
                                        </label>
                                    @endforeach
                                </div>
                            </fieldset>
                            <div class="flex flex-wrap gap-2">
                                <x-ui.button type="submit" size="sm">Save</x-ui.button>
                                <x-ui.button size="sm" variant="secondary" wire:click="syncNow('{{ $key }}')" wire:loading.attr="disabled" wire:target="syncNow">Sync now</x-ui.button>
                                <x-ui.button size="sm" variant="ghost" wire:click="disconnect('{{ $key }}')" wire:confirm="Disconnect {{ $provider->label() }}? Events already in your calendar stay there.">Disconnect</x-ui.button>
                            </div>
                        </form>
                    @elseif ($canManage)
                        <div class="mt-5 flex flex-wrap gap-2">
                            <x-ui.button size="sm" :href="route('app.integrations.calendar.connect', $key)">Reconnect</x-ui.button>
                            <x-ui.button size="sm" variant="ghost" wire:click="disconnect('{{ $key }}')" wire:confirm="Disconnect {{ $provider->label() }}?">Disconnect</x-ui.button>
                        </div>
                    @endif
                @else
                    {{-- Explain permissions before OAuth (spec §18) --}}
                    <div class="mt-4 text-sm text-muted">
                        <p>When you connect, {{ $provider->label() }} asks you to allow SureHelp to:</p>
                        <ul class="mt-2 list-disc space-y-1 pl-5">
                            <li>see your calendars and when you're busy, so agents only offer free times;</li>
                            <li>add, move and remove the appointments we book for you.</li>
                        </ul>
                        <p class="mt-2">We never read event details or attendees from your other events, and you can disconnect any time.</p>
                    </div>
                    @if (! $provider->isConfigured())
                        <p class="mt-4 rounded-lg bg-surface-2 px-3 py-2 text-sm text-muted ring-1 ring-line">Coming soon: we're finishing the {{ $provider->label() }} setup.</p>
                    @elseif ($canManage)
                        <x-ui.button class="mt-4" :href="route('app.integrations.calendar.connect', $key)" icon="calendar">Connect {{ $provider->label() }}</x-ui.button>
                    @else
                        <p class="mt-4 text-sm text-muted">Ask the business owner to connect it.</p>
                    @endif
                @endif
            </x-ui.card>
        @endforeach
    </div>

    @if ($conflicts->isNotEmpty())
        <x-ui.alert tone="warning" class="mt-6" title="{{ $conflicts->count() }} {{ \Illuminate\Support\Str::plural('booking', $conflicts->count()) }} changed directly in your calendar">
            We don't overwrite changes made in your calendar. Please update these in SureHelp so agents see the right time:
            @foreach ($conflicts as $a)<a class="font-semibold underline" href="{{ route('app.appointments.index', ['appointment' => $a->ulid]) }}">{{ $a->title }}</a>@if (! $loop->last), @endif @endforeach
        </x-ui.alert>
    @endif
</div>
