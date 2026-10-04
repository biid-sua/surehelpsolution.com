<div>
    <x-ui.page-header title="Calls" description="Every call our team handled for {{ $organization->name }}.">
        <x-slot:actions>
            <x-ui.button variant="secondary" icon="download" :href="$exportUrl">Export CSV</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    {{-- Quick views --}}
    <div class="mb-4 flex gap-1 overflow-x-auto border-b border-line" role="tablist" aria-label="Call views">
        @foreach ($views as $key => $label)
            <button type="button" role="tab" wire:click="$set('view', '{{ $key }}')" aria-selected="{{ $view === $key ? 'true' : 'false' }}"
                @class([
                    '-mb-px whitespace-nowrap border-b-2 px-3 py-2 text-sm font-medium transition-colors',
                    'border-brand-400 text-ink' => $view === $key,
                    'border-transparent text-muted hover:text-ink' => $view !== $key,
                ])>{{ $label }}</button>
        @endforeach
    </div>

    {{-- Filters --}}
    <div class="mb-4 grid gap-3 sm:grid-cols-[1fr_auto_auto_auto] sm:items-end">
        <div>
            <label for="call-search" class="sh-label">Search</label>
            <div class="relative">
                <x-ui.icon name="search" class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-subtle" />
                <input id="call-search" type="search" wire:model.live.debounce.350ms="search" class="sh-input pl-9" placeholder="Name, phone, email, call ID, notes…" autocomplete="off">
            </div>
        </div>
        <div>
            <label for="call-from" class="sh-label">From</label>
            <input id="call-from" type="date" wire:model.live="from" class="sh-input">
        </div>
        <div>
            <label for="call-to" class="sh-label">To</label>
            <input id="call-to" type="date" wire:model.live="to" class="sh-input">
        </div>
        @if ($filtered)
            <x-ui.button variant="ghost" wire:click="clearFilters" icon="x">Clear</x-ui.button>
        @endif
    </div>

    <x-ui.card :padding="false">
        <div class="relative">
            <div wire:loading.flex wire:target="search,view,from,to,clearFilters,nextPage,previousPage" class="absolute inset-0 z-10 items-start justify-center bg-surface/60 pt-16">
                <span class="rounded-full bg-surface-2 px-3 py-1 text-xs text-muted ring-1 ring-line">Loading…</span>
            </div>

            @if ($calls->isEmpty())
                @if ($filtered)
                    <x-ui.empty-state icon="search" title="No calls match these filters" description="Try a different search or date range.">
                        <x-ui.button variant="secondary" size="sm" wire:click="clearFilters">Clear filters</x-ui.button>
                    </x-ui.empty-state>
                @else
                    <x-ui.empty-state icon="phone" title="No calls yet"
                        description="Once your phone forwarding is live, every call our team handles appears here with notes and outcome." />
                @endif
            @else
                <x-ui.table>
                    <thead>
                        <tr>
                            <th scope="col">Caller</th>
                            <th scope="col">Reason</th>
                            <th scope="col">Status</th>
                            <th scope="col" class="hidden md:table-cell">Service</th>
                            <th scope="col" class="hidden lg:table-cell">Agent</th>
                            <th scope="col" class="text-right">Logged</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        @foreach ($calls as $call)
                            <tr wire:key="call-{{ $call->id }}">
                                <td>
                                    <a href="{{ route('app.calls.show', $call->call_id) }}" class="font-medium text-ink hover:text-brand-300">{{ \App\Models\CallLog::display($call->caller_name) }}</a>
                                    <p class="text-xs text-subtle">{{ \App\Models\CallLog::display($call->caller_phone) }} · {{ $call->call_id }}</p>
                                </td>
                                <td class="text-muted">{{ \Illuminate\Support\Str::headline($call->reason_for_call) }}</td>
                                <td><x-ui.badge :tone="$call->statusTone()">{{ $call->statusLabel() }}</x-ui.badge></td>
                                <td class="hidden text-muted md:table-cell">
                                    @if ($call->service_date)
                                        {{ $call->service_date->format('M j') }}{{ $call->service_window ? ' · '.$call->service_window : '' }}
                                    @else
                                        —
                                    @endif
                                </td>
                                <td class="hidden text-muted lg:table-cell">{{ \App\Models\CallLog::display($call->agent_name) }}</td>
                                <td class="whitespace-nowrap text-right text-muted" title="{{ $call->created_at->diffForHumans() }}">
                                    {{ $call->created_at->setTimezone($organization->timezone ?: config('app.timezone'))->format('M j, g:i a') }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </x-ui.table>
                <x-ui.pagination :paginator="$calls" />
            @endif
        </div>
    </x-ui.card>
</div>
