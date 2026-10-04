<div>
    <x-ui.page-header title="Calendar" description="Everything on {{ $organization->name }}'s schedule, in your business's time. Each entry is tagged with where it comes from." />

    @if ($needsReconnect->isNotEmpty())
        <x-ui.alert tone="danger" class="mb-6" title="{{ $needsReconnect->join(' and ') }} {{ $needsReconnect->count() > 1 ? 'need' : 'needs' }} reconnecting">
            New bookings aren't being added to it and its busy times may be out of date.
            @can('integrations.view')<a href="{{ route('app.business.calendars') }}" class="font-semibold underline underline-offset-2">Reconnect</a>@endcan
        </x-ui.alert>
    @elseif (! $anyConnected)
        <x-ui.alert tone="info" class="mb-6" title="Connect your Google or Microsoft calendar">
            Bookings land in the calendar you already use, and your busy times stop our agents double-booking you.
            @can('integrations.view')<a href="{{ route('app.business.calendars') }}" class="font-semibold underline underline-offset-2">Set up calendar sync</a>@endcan
        </x-ui.alert>
    @endif

    <x-ui.card>
        <div wire:ignore x-data="calendar(@js(route('app.calendar.events')), @js($sources), @js('calendar-sources-'.$organization->ulid))">
            <div class="mb-4 flex flex-wrap items-center gap-2" role="group" aria-label="Show on the calendar">
                <template x-for="source in sources" :key="source.key">
                    <button type="button" x-on:click="toggle(source)" :disabled="!source.connected"
                        :aria-pressed="isOn(source) ? 'true' : 'false'"
                        :title="hint(source)"
                        class="inline-flex items-center gap-2 rounded-full px-3 py-1.5 text-sm font-medium ring-1 ring-inset transition-colors disabled:cursor-default"
                        :class="isOn(source) ? 'bg-surface-2 text-ink ring-line-strong' : 'text-subtle ring-line hover:text-muted'">
                        <span class="size-2.5 rounded-full" :class="'src-dot-' + source.key" :style="isOn(source) ? '' : 'opacity:.35'"></span>
                        <span x-text="source.label"></span>
                        <template x-if="source.kind === 'external'">
                            <span class="rounded-full px-1.5 py-0.5 text-[11px] font-medium"
                                :class="{ 'bg-emerald-500/15 text-emerald-300': source.status === 'active', 'bg-red-500/15 text-red-300': source.status === 'needs_reauth', 'bg-amber-500/15 text-amber-300': source.status === 'error', 'bg-white/5 text-subtle': !source.connected }"
                                x-text="statusText(source)"></span>
                        </template>
                    </button>
                </template>
                @can('integrations.view')
                    <a href="{{ route('app.business.calendars') }}" class="ml-auto text-sm text-brand-300 hover:underline">Manage calendar sync</a>
                @endcan
            </div>
            <div x-ref="calendar" class="min-h-[28rem]" aria-label="Business calendar"></div>
        </div>
    </x-ui.card>

    <p class="mt-3 text-xs text-subtle">Busy times show only that you're busy, never what the event is. Tags on a booking show which of your calendars hold a copy.</p>
</div>
