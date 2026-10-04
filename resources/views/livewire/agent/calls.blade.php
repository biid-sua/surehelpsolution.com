<div>
    <x-ui.page-header title="My calls" description="Your numbers and every call you've logged.">
        <x-slot:actions>
            <x-ui.button variant="secondary" icon="download" :href="route('agent.calls.export')">Download CSV</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="mb-4 flex flex-wrap gap-2" role="group" aria-label="Period">
        @foreach ($periods as $value => $label)
            <button type="button" wire:click="$set('period', '{{ $value }}')" @class([
                'rounded-full px-3 py-1.5 text-sm font-medium transition-colors',
                'bg-brand-600 text-white' => $period === $value,
                'bg-surface-2 text-muted hover:text-ink' => $period !== $value,
            ]) aria-pressed="{{ $period === $value ? 'true' : 'false' }}">{{ $label }}</button>
        @endforeach
    </div>

    <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
        <x-ui.stat label="Calls" icon="phone" :value="number_format($kpis['total_calls']['value'])" :change="$kpis['total_calls']['change']" />
        <x-ui.stat label="Service requests" icon="wrench" :value="number_format($kpis['service_requests']['value'])" :change="$kpis['service_requests']['change']" />
        <x-ui.stat label="Booked" icon="calendar" :value="number_format($kpis['total_schedules']['value'])" :change="$kpis['total_schedules']['change']" />
        <x-ui.stat label="Call-backs requested" icon="callback" :value="number_format($kpis['request_callback']['value'])" :change="$kpis['request_callback']['change']" />
    </div>

    <x-ui.card class="mt-6" :padding="false" title="Call history">
        <x-slot:actions>
            <label for="my-calls-search" class="sr-only">Search calls</label>
            <input id="my-calls-search" type="search" wire:model.live.debounce.350ms="search" class="sh-input w-56" placeholder="Caller, phone, business…" autocomplete="off">
        </x-slot:actions>
        @if ($calls->isEmpty())
            <x-ui.empty-state icon="phone" title="{{ $search !== '' ? 'No calls match' : 'No calls yet' }}" description="Calls you save in the workspace appear here.">
                <x-ui.button :href="route('agent.home')" variant="secondary">Open the workspace</x-ui.button>
            </x-ui.empty-state>
        @else
            <x-ui.table>
                <thead>
                    <tr>
                        <th scope="col">Caller</th>
                        <th scope="col">Business</th>
                        <th scope="col" class="hidden md:table-cell">Reason</th>
                        <th scope="col">Status</th>
                        <th scope="col" class="text-right">When</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    @foreach ($calls as $call)
                        <tr wire:key="call-{{ $call->id }}">
                            <td>
                                <p class="font-medium text-ink">{{ \App\Models\CallLog::display($call->caller_name) }}</p>
                                <p class="text-xs text-subtle">{{ $call->caller_phone }} · {{ $call->call_id }}</p>
                            </td>
                            <td class="text-muted">
                                @if ($call->organization)
                                    <a href="{{ route('agent.businesses.show', $call->organization) }}" class="hover:text-ink">{{ $call->organization->name }}</a>
                                @else
                                    <span class="text-subtle">—</span>
                                @endif
                            </td>
                            <td class="hidden text-muted md:table-cell">{{ str($call->reason_for_call)->headline() }}</td>
                            <td><x-ui.badge :tone="$call->statusTone()">{{ $call->statusLabel() }}</x-ui.badge></td>
                            <td class="whitespace-nowrap text-right text-muted" title="{{ $call->created_at->toDayDateTimeString() }}">{{ $call->created_at->diffForHumans() }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </x-ui.table>
            <x-ui.pagination :paginator="$calls" />
        @endif
    </x-ui.card>
</div>
