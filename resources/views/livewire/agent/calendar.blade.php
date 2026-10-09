<div>
    <x-ui.page-header title="My calendar" :description="'Your shifts, time off and bookings, in your time ('.str_replace('_', ' ', $timezone).'). Connect your own calendar to see when you\'re busy and to get your shifts in it.'">
        <x-slot:actions>
            <x-ui.button variant="secondary" size="sm" :href="route('agent.schedule')">Shift swaps and time off</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @if (session('success'))<x-ui.alert tone="success" class="mb-6">{{ session('success') }}</x-ui.alert>@endif
    @if (session('error'))<x-ui.alert tone="danger" class="mb-6">{{ session('error') }}</x-ui.alert>@endif
    @foreach ($providers as $p)
        @if ($p['connection'] && $p['connection']->status === 'needs_reauth')
            <x-ui.alert tone="danger" class="mb-6" title="Reconnect your {{ $p['label'] }}">
                We lost access to {{ $p['connection']->account_email ?: 'it' }}, so busy times aren't shown and shifts aren't copied.
                <a href="{{ route('agent.calendar.connect', $p['key']) }}" class="font-semibold underline underline-offset-2">Reconnect</a>
            </x-ui.alert>
        @endif
    @endforeach

    <x-ui.card class="mb-6">
        <div wire:ignore x-data="calendar(@js(route('agent.calendar.events')), @js($sources), @js('agent-calendar-sources-'.auth()->id()))">
            <div class="mb-4 flex flex-wrap items-center gap-2" role="group" aria-label="Show on the calendar">
                <template x-for="source in sources" :key="source.key">
                    <button type="button" x-on:click="toggle(source)" :disabled="!source.connected" :aria-pressed="isOn(source) ? 'true' : 'false'" :title="hint(source)"
                        class="inline-flex items-center gap-2 rounded-full px-3 py-1.5 text-sm font-medium ring-1 ring-inset transition-colors disabled:cursor-default"
                        :class="isOn(source) ? 'bg-surface-2 text-ink ring-line-strong' : 'text-subtle ring-line hover:text-muted'">
                        <span class="size-2.5 rounded-full" :class="'src-dot-' + source.key" :style="isOn(source) ? '' : 'opacity:.35'"></span>
                        <span x-text="source.label"></span>
                        <template x-if="source.kind === 'external'">
                            <span class="rounded-full px-1.5 py-0.5 text-[11px] font-medium"
                                :class="{ 'bg-emerald-500/15 text-emerald-300': source.status === 'active', 'bg-red-500/15 text-red-300': source.status === 'needs_reauth', 'bg-white/5 text-subtle': !source.connected }"
                                x-text="statusText(source)"></span>
                        </template>
                    </button>
                </template>
            </div>
            <div x-ref="calendar" class="min-h-[28rem]" aria-label="My calendar"></div>
        </div>
    </x-ui.card>

    <h2 class="mb-3 text-base font-semibold text-ink">Your own calendars</h2>
    <div class="grid gap-6 lg:grid-cols-2">
        @foreach ($providers as $p)
            @php($c = $p['connection'])
            <x-ui.card :title="$p['label']" :description="$c ? 'Connected as '.($c->account_email ?: 'your account').($c->last_synced_at ? ' · shifts updated '.$c->last_synced_at->diffForHumans() : '') : 'Not connected'" wire:key="prov-{{ $p['key'] }}">
                @if (! $c)
                    @if ($p['configured'])
                        <p class="text-sm text-muted">See your personal appointments as busy time here, and get your SureHelp shifts added to your calendar automatically.</p>
                        <x-ui.button class="mt-4" :href="route('agent.calendar.connect', $p['key'])" icon="calendar">Connect {{ $p['label'] }}</x-ui.button>
                    @else
                        <p class="text-sm text-muted">Coming soon: we're finishing the {{ $p['label'] }} setup.</p>
                    @endif
                @else
                    <form wire:submit="save('{{ $p['key'] }}')" class="space-y-4">
                        <fieldset>
                            <legend class="sh-label">Show as busy</legend>
                            <div class="space-y-1">
                                @foreach ($c->calendars ?? [] as $cal)
                                    <label class="flex items-center gap-2 text-sm text-ink"><input type="checkbox" value="{{ $cal['id'] }}" wire:model="settings.{{ $p['key'] }}.busy"> {{ $cal['name'] }}@if ($cal['primary']) <span class="text-xs text-subtle">(main)</span>@endif</label>
                                @endforeach
                            </div>
                            <p class="mt-1 text-xs text-subtle">Only the times show, never what the event is.</p>
                        </fieldset>
                        <div class="rounded-xl border border-line p-3">
                            <label class="flex items-center gap-2 text-sm font-medium text-ink"><input type="checkbox" wire:model.live="settings.{{ $p['key'] }}.push"> Add my SureHelp shifts to this calendar</label>
                            @if ($settings[$p['key']]['push'] ?? false)
                                <label for="w-{{ $p['key'] }}" class="sh-label mt-3">Into</label>
                                <select id="w-{{ $p['key'] }}" wire:model="settings.{{ $p['key'] }}.write" class="sh-input">
                                    <option value="">Choose a calendar…</option>
                                    @foreach (collect($c->calendars ?? [])->where('can_write', true) as $cal)<option value="{{ $cal['id'] }}">{{ $cal['name'] }}</option>@endforeach
                                </select>
                                @error('settings.'.$p['key'].'.write')<p class="mt-1 text-sm text-danger">{{ $message }}</p>@enderror
                                <p class="mt-1 text-xs text-subtle">The next {{ \App\Services\Calendar\AgentCalendarSync::DAYS_AHEAD }} days. Changes to your shifts update it; removed shifts disappear.</p>
                            @endif
                        </div>
                        @if ($c->last_error)<p class="text-sm text-amber-300">{{ $c->last_error }}</p>@endif
                        <div class="flex flex-wrap gap-2">
                            <x-ui.button type="submit">Save</x-ui.button>
                            @if ($c->push_shifts)<x-ui.button variant="secondary" wire:click="syncNow('{{ $p['key'] }}')">Update now</x-ui.button>@endif
                            <x-ui.confirm id="disc-{{ $p['key'] }}" title="Disconnect {{ $p['label'] }}?" confirm-label="Disconnect" action="disconnect('{{ $p['key'] }}')">
                                <x-slot:trigger><x-ui.button variant="ghost">Disconnect</x-ui.button></x-slot:trigger>
                                Shifts we added are removed from it, and its busy times stop showing here.
                            </x-ui.confirm>
                        </div>
                    </form>
                @endif
            </x-ui.card>
        @endforeach
    </div>
</div>
