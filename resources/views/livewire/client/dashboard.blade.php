<div>
    <x-ui.page-header :title="$greeting" description="Here's what's happening at {{ $organization->name }}.">
        <x-slot:actions>
            <div class="inline-flex rounded-lg border border-line bg-surface p-1" role="group" aria-label="Time period">
                @foreach ($periods as $key => $label)
                    <button type="button" wire:click="$set('period', '{{ $key }}')" aria-pressed="{{ $period === $key ? 'true' : 'false' }}"
                        @class([
                            'rounded-md px-3 py-1.5 text-sm font-medium transition-colors',
                            'bg-brand-600 text-white' => $period === $key,
                            'text-muted hover:text-ink' => $period !== $key,
                        ])>{{ $label }}</button>
                @endforeach
            </div>
        </x-slot:actions>
    </x-ui.page-header>

    {{-- Escalations come first: someone is waiting on the business (spec §25). --}}
    @if ($activeEscalations > 0)
        <div class="mb-4">
            <x-ui.alert tone="danger" title="{{ $activeEscalations }} {{ \Illuminate\Support\Str::plural('escalation', $activeEscalations) }} need{{ $activeEscalations === 1 ? 's' : '' }} your attention">
                Our team flagged {{ $activeEscalations === 1 ? 'a call' : 'calls' }} only you can deal with. <a href="{{ route('app.escalations.index') }}" class="font-semibold underline underline-offset-2">Open escalations</a>
            </x-ui.alert>
        </div>
    @endif

    {{-- Alerts: only real, actionable conditions (spec §8.1). --}}
    @if ($kpis['follow_ups']['value'] > 0 || ($period === 'today' && $kpis['missed']['value'] > 0))
        <div class="mb-6 space-y-3">
            @if ($kpis['follow_ups']['value'] > 0)
                <x-ui.alert tone="warning" title="{{ $kpis['follow_ups']['value'] }} {{ \Illuminate\Support\Str::plural('caller', $kpis['follow_ups']['value']) }} waiting for a follow-up">
                    Callers asked to be called back. <a href="{{ route('app.tasks.index') }}" class="font-semibold underline underline-offset-2">Open tasks</a>
                </x-ui.alert>
            @endif
            @if ($period === 'today' && $kpis['missed']['value'] > 0)
                <x-ui.alert tone="danger" title="{{ $kpis['missed']['value'] }} dropped or unanswered {{ \Illuminate\Support\Str::plural('call', $kpis['missed']['value']) }} today">
                    <a href="{{ route('app.calls.index', ['view' => 'missed']) }}" class="font-semibold underline underline-offset-2">See which calls</a>
                </x-ui.alert>
            @endif
        </div>
    @endif

    <div class="relative">
        <div wire:loading.flex wire:target="period" class="absolute inset-0 z-10 items-start justify-center rounded-2xl bg-canvas/40 pt-24 backdrop-blur-[1px]">
            <span class="rounded-full bg-surface-2 px-3 py-1 text-xs text-muted ring-1 ring-line">Updating…</span>
        </div>

        {{-- KPI cards --}}
        <div class="grid grid-cols-2 gap-4 lg:grid-cols-5">
            <x-ui.stat label="Calls" icon="phone" :value="number_format($kpis['calls']['value'])" :change="$kpis['calls']['change']"
                hint="vs previous" :href="route('app.calls.index')" />
            <x-ui.stat label="Service requests" icon="wrench" :value="number_format($kpis['service_requests']['value'])" :change="$kpis['service_requests']['change']"
                hint="vs previous" :href="route('app.calls.index', ['view' => 'service'])" />
            <x-ui.stat label="Scheduled" icon="calendar" :value="number_format($kpis['scheduled']['value'])" :change="$kpis['scheduled']['change']"
                hint="vs previous" :href="route('app.calls.index', ['view' => 'scheduled'])" />
            <x-ui.stat label="Missed / dropped" icon="phone-x" :value="number_format($kpis['missed']['value'])" :change="$kpis['missed']['change']"
                :invert="true" hint="vs previous" :href="route('app.calls.index', ['view' => 'missed'])" />
            <x-ui.stat label="Pending follow-ups" icon="callback" :value="number_format($kpis['follow_ups']['value'])"
                hint="open right now" :href="route('app.tasks.index')" class="col-span-2 lg:col-span-1" />
        </div>

        <div class="mt-6 grid gap-6 lg:grid-cols-3">
            {{-- Call volume --}}
            <x-ui.card class="lg:col-span-2" title="Call volume" :description="$period === 'today' ? 'Calls per hour today' : 'Calls per day'">
                @if (array_sum($series['data']) === 0)
                    <x-ui.empty-state icon="chart" title="No calls in this period yet"
                        description="As soon as our team answers a call for you, it shows up here." />
                @else
                    <div class="h-64" wire:key="chart-{{ $period }}-{{ md5(json_encode($series)) }}"
                        x-data="chart({ type: 'bar', labels: @js($series['labels']), series: [{ label: 'Calls', data: @js($series['data']) }] })">
                        <canvas x-ref="canvas" role="img" aria-label="Bar chart of calls, {{ array_sum($series['data']) }} in total"></canvas>
                    </div>
                @endif
            </x-ui.card>

            {{-- Today's schedule --}}
            <x-ui.card title="Today's schedule" description="Service visits booked for today" :padding="false">
                @forelse ($schedule as $visit)
                    <a href="{{ route('app.calls.show', $visit->call_id) }}" class="flex items-start gap-3 border-b border-line px-5 py-3 last:border-0 hover:bg-surface-2">
                        <span class="mt-0.5 rounded-md bg-brand-500/15 px-2 py-1 text-xs font-semibold text-brand-300 tabular-nums">{{ $visit->service_window ?: 'Any time' }}</span>
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-medium text-ink">{{ \App\Models\CallLog::display($visit->caller_name) }}</p>
                            <p class="truncate text-xs text-muted">{{ \App\Models\CallLog::display($visit->service_location) }}</p>
                        </div>
                        <x-ui.badge :tone="$visit->statusTone()">{{ $visit->statusLabel() }}</x-ui.badge>
                    </a>
                @empty
                    <x-ui.empty-state icon="calendar" title="Nothing scheduled today"
                        description="Visits our agents book for today will be listed here.">
                        <x-ui.button variant="secondary" size="sm" :href="route('app.calendar')" icon="calendar">Open calendar</x-ui.button>
                    </x-ui.empty-state>
                @endforelse
            </x-ui.card>
        </div>

        {{-- Recent calls --}}
        <x-ui.card class="mt-6" title="Recent calls" :padding="false">
            <x-slot:actions>
                <x-ui.button variant="ghost" size="sm" :href="route('app.calls.index')">View all <x-ui.icon name="chevron-right" class="size-4" /></x-ui.button>
            </x-slot:actions>
            @if ($recentCalls->isEmpty())
                <x-ui.empty-state icon="phone" title="No calls yet"
                    description="Once your phone forwarding is live, every call our team handles appears here with notes and outcome." />
            @else
                <x-ui.table>
                    <thead>
                        <tr>
                            <th scope="col">Caller</th>
                            <th scope="col">Reason</th>
                            <th scope="col">Outcome</th>
                            <th scope="col">Agent</th>
                            <th scope="col" class="text-right">When</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        @foreach ($recentCalls as $call)
                            <tr>
                                <td>
                                    <a href="{{ route('app.calls.show', $call->call_id) }}" class="font-medium text-ink hover:text-brand-300">{{ \App\Models\CallLog::display($call->caller_name) }}</a>
                                    <p class="text-xs text-subtle">{{ \App\Models\CallLog::display($call->caller_phone) }}</p>
                                </td>
                                <td class="text-muted">{{ \Illuminate\Support\Str::headline($call->reason_for_call) }}</td>
                                <td><x-ui.badge :tone="$call->statusTone()">{{ $call->statusLabel() }}</x-ui.badge></td>
                                <td class="text-muted">{{ \App\Models\CallLog::display($call->agent_name) }}</td>
                                <td class="whitespace-nowrap text-right text-muted" title="{{ $call->created_at->setTimezone($organization->timezone ?: config('app.timezone'))->toDayDateTimeString() }}">{{ $call->created_at->diffForHumans() }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </x-ui.table>
            @endif
        </x-ui.card>
    </div>
</div>
