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
            @if ($period === 'custom')
                <div class="flex flex-wrap items-center gap-2">
                    <label for="d-from" class="sr-only">From</label>
                    <input id="d-from" type="date" wire:model.live.debounce.500ms="from" class="sh-input w-40">
                    <span class="text-sm text-muted">to</span>
                    <label for="d-to" class="sr-only">To</label>
                    <input id="d-to" type="date" wire:model.live.debounce.500ms="to" class="sh-input w-40">
                </div>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    @if (! $customValid)
        <x-ui.alert tone="warning" class="mb-6">Pick a start date on or before the end date, up to {{ $maxDays }} days apart. Showing today until then.</x-ui.alert>
    @endif

    @if (session('status'))
        <x-ui.alert tone="success" class="mb-6">{{ session('status') }}</x-ui.alert>
    @endif
    @if (in_array($organization->status->value, ['paused', 'cancelled'], true) && ! $organization->closed_at)
        <x-ui.alert tone="warning" class="mb-6" :title="$organization->status->value === 'paused' ? 'Your SureHelp service is paused' : 'Your SureHelp service is cancelled'">
            Our receptionists aren't answering for you and your website tools are hidden.
            @if ($organization->status_reason) Reason: {{ $organization->status_reason }}. @endif
            Contact us to {{ $organization->status->value === 'paused' ? 'resume' : 'restart' }} your service.
        </x-ui.alert>
    @endif
    @if ($organization->isClosing())
        <x-ui.alert tone="warning" class="mb-6" :title="'Your account closes on '.$organization->closes_at->setTimezone($organization->timezoneOrDefault())->format('l, F j')">
            After that day your data is deleted and calls are no longer answered.
            @can('organization.update', $organization)
                <a href="{{ route('app.settings.privacy') }}" class="mt-2 block font-semibold underline underline-offset-2">Keep my account or download my data</a>
            @endcan
        </x-ui.alert>
    @endif
    @if ($away)
        <x-ui.alert :tone="$away['now'] ? 'warning' : 'info'" class="mb-6"
            :title="$away['now'] ? 'Vacation mode is on until '.$away['profile']->closed_until->format('l, M j') : 'Vacation planned: '.$away['profile']->closed_from->format('M j').' – '.$away['profile']->closed_until->format('M j')">
            {{ $away['profile']->closure_message ?: 'Our receptionists take messages and don\'t book anything on those days.' }}
            @if ($canManageHours)
                <span class="mt-2 flex flex-wrap gap-3">
                    <a href="{{ route('app.business.hours') }}" class="font-semibold underline underline-offset-2">Change dates</a>
                    <button type="button" wire:click="endVacation" class="font-semibold underline underline-offset-2">{{ $away['now'] ? 'I\'m back: end it now' : 'Cancel it' }}</button>
                </span>
            @endif
        </x-ui.alert>
    @endif
    @if ($setup)
        <div class="mb-6 flex flex-wrap items-center gap-4 rounded-[var(--radius-card)] border border-brand-500/40 bg-brand-500/10 p-5">
            <div class="min-w-0 flex-1">
                <p class="font-semibold text-ink">Finish setting up SureHelp</p>
                <p class="mt-0.5 text-sm text-muted">{{ $setup['done'] }} of {{ $setup['total'] }} steps done. A few minutes more and our receptionists can answer for you like your own team.</p>
                <div class="mt-3 h-1.5 max-w-sm overflow-hidden rounded-full bg-surface-2"><div class="h-full rounded-full bg-brand-500" style="width: {{ (int) round($setup['done'] / max(1, $setup['total']) * 100) }}%"></div></div>
            </div>
            <x-ui.button :href="route('app.setup')">Continue setup</x-ui.button>
        </div>
    @endif

    {{-- Escalations come first: someone is waiting on the business (spec §25). --}}
    @if ($activeEscalations > 0)
        <div class="mb-4">
            <x-ui.alert tone="danger" title="{{ $activeEscalations }} {{ \Illuminate\Support\Str::plural('escalation', $activeEscalations) }} need{{ $activeEscalations === 1 ? 's' : '' }} your attention">
                Our team flagged {{ $activeEscalations === 1 ? 'a call' : 'calls' }} only you can deal with. <a href="{{ route('app.escalations.index') }}" class="font-semibold underline underline-offset-2">Open escalations</a>
            </x-ui.alert>
        </div>
    @endif

    {{-- Alerts: only real, actionable conditions (spec §8.1), for people who can act on them. --}}
    @if ($alerts || $kpis['follow_ups']['value'] > 0 || ($period === 'today' && $kpis['missed']['value'] > 0))
        <div class="mb-6 space-y-3">
            @foreach ($alerts as $alert)
                <x-ui.alert :tone="$alert['tone']" :title="$alert['title']" wire:key="alert-{{ $alert['key'] }}">
                    {{ $alert['body'] }} <a href="{{ $alert['url'] }}" class="font-semibold underline underline-offset-2">{{ $alert['action'] }}</a>
                </x-ui.alert>
            @endforeach
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
        <div wire:loading.flex wire:target="period,from,to" class="absolute inset-0 z-10 items-start justify-center rounded-2xl bg-canvas/40 pt-24 backdrop-blur-[1px]">
            <span class="rounded-full bg-surface-2 px-3 py-1 text-xs text-muted ring-1 ring-line">Updating…</span>
        </div>

        {{-- KPI cards --}}
        <div class="grid grid-cols-2 gap-4 md:grid-cols-4">
            <x-ui.stat label="Calls" icon="phone" :value="number_format($kpis['calls']['value'])" :change="$kpis['calls']['change']"
                hint="vs previous" :href="route('app.calls.index')" />
            <x-ui.stat label="Appointments" icon="calendar" :value="number_format($kpis['appointments']['value'])" :change="$kpis['appointments']['change']"
                hint="scheduled in this period" :href="route('app.appointments.index')" />
            <x-ui.stat label="New leads" icon="users" :value="number_format($kpis['leads']['value'])" :change="$kpis['leads']['change']"
                hint="new customer records" :href="route('app.customers.index')" />
            <x-ui.stat label="Service requests" icon="wrench" :value="number_format($kpis['service_requests']['value'])" :change="$kpis['service_requests']['change']"
                hint="vs previous" :href="route('app.calls.index', ['view' => 'service'])" />
            <x-ui.stat label="Calls that booked" icon="check-circle" :value="number_format($kpis['scheduled']['value'])" :change="$kpis['scheduled']['change']"
                hint="vs previous" :href="route('app.calls.index', ['view' => 'scheduled'])" />
            <x-ui.stat label="Missed / dropped" icon="phone-x" :value="number_format($kpis['missed']['value'])" :change="$kpis['missed']['change']"
                :invert="true" hint="vs previous" :href="route('app.calls.index', ['view' => 'missed'])" />
            <x-ui.stat label="Pending follow-ups" icon="callback" :value="number_format($kpis['follow_ups']['value'])"
                hint="open right now" :href="route('app.tasks.index')" class="col-span-2" />
        </div>

        <div class="mt-6 grid gap-6 lg:grid-cols-3">
            {{-- Call volume --}}
            <x-ui.card class="lg:col-span-2" title="Call volume" :description="$hourly ? 'Calls per hour' : 'Calls per day'">
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
            <x-ui.card title="Today's schedule" description="Appointments and visits booked for today" :padding="false">
                @forelse ($schedule as $visit)
                    <a href="{{ $visit['url'] }}" class="flex items-start gap-3 border-b border-line px-5 py-3 last:border-0 hover:bg-surface-2">
                        <span class="mt-0.5 rounded-md bg-brand-500/15 px-2 py-1 text-xs font-semibold text-brand-300 tabular-nums">{{ $visit['time'] }}</span>
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-medium text-ink">{{ $visit['title'] }}</p>
                            <p class="truncate text-xs text-muted">{{ $visit['subtitle'] }}</p>
                        </div>
                        <x-ui.badge :tone="$visit['tone']">{{ $visit['status'] }}</x-ui.badge>
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

        @if ($activity)
            {{-- Customer activity (spec §8.1) --}}
            <x-ui.card class="mt-6" title="Customer activity" :padding="false">
                <x-slot:actions>
                    <x-ui.button variant="ghost" size="sm" :href="route('app.customers.index')">Customers <x-ui.icon name="chevron-right" class="size-4" /></x-ui.button>
                </x-slot:actions>
                <div class="grid gap-6 p-5 md:grid-cols-[12rem_minmax(0,1fr)]">
                    <dl class="grid grid-cols-2 gap-4 md:grid-cols-1">
                        <div><dt class="text-xs text-subtle">New customers</dt><dd class="text-2xl font-semibold text-ink">{{ number_format($activity['new']) }}</dd></div>
                        <div><dt class="text-xs text-subtle">Returning customers</dt><dd class="text-2xl font-semibold text-ink">{{ number_format($activity['returning']) }}</dd>
                            <p class="text-xs text-subtle">called or booked again</p></div>
                    </dl>
                    <div class="min-w-0">
                        <h3 class="text-sm font-semibold text-ink">Recent interactions</h3>
                        @forelse ($activity['recent'] as $event)
                            <div class="flex items-start gap-3 border-b border-line py-2 last:border-0" wire:key="act-{{ $event->id }}">
                                <x-ui.icon :name="$event->type->icon()" class="mt-0.5 size-4 shrink-0 text-subtle" />
                                <p class="min-w-0 flex-1 truncate text-sm text-ink">
                                    @if ($event->customer)<a href="{{ route('app.customers.show', $event->customer->ulid) }}" class="font-medium hover:text-brand-300">{{ $event->customer->fullName() }}</a> · @endif{{ $event->title }}
                                </p>
                                <span class="whitespace-nowrap text-xs text-subtle">{{ $event->occurred_at?->diffForHumans() }}</span>
                            </div>
                        @empty
                            <p class="mt-2 text-sm text-muted">Calls, bookings and messages with your customers will show here.</p>
                        @endforelse
                    </div>
                </div>
            </x-ui.card>
        @endif
    </div>
</div>
