@php
    $money = fn (?int $cents) => $cents === null ? null : \App\Support\Money::format($cents, $organization->currency);
    $days = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
    $peak = max(1, max(array_map('max', $r['heatmap'])));
@endphp
<div>
    <x-ui.page-header title="Results" description="What SureHelp did for {{ $organization->name }}: every number is counted from your calls and bookings.">
        <x-slot:actions>
            <label for="r-month" class="sr-only">Month</label>
            <select id="r-month" wire:model.live="month" class="sh-input w-44">
                @foreach ($months as $key => $label)
                    <option value="{{ $key === array_key_first($months) ? '' : $key }}" @selected($key === $period['key'])>{{ $label }}</option>
                @endforeach
            </select>
            <x-ui.button variant="secondary" icon="download" :href="route('app.results.pdf', ['month' => $period['key']])">PDF</x-ui.button>
            <x-ui.button variant="secondary" icon="download" :href="route('app.results.csv', ['month' => $period['key']])">CSV</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @if ($isCurrent)
        <p class="mb-4 text-sm text-muted">{{ $period['label'] }} so far. The full report is emailed on the 1st.</p>
    @endif

    {{-- The headline: what it was worth --}}
    <div class="mb-6 rounded-[var(--radius-card)] border border-brand-500/40 bg-gradient-to-br from-brand-500/15 to-accent-500/10 p-6">
        @if ($r['revenue_cents'] !== null)
            <p class="text-sm font-medium text-muted">Estimated revenue from jobs we booked</p>
            <p class="mt-1 text-4xl font-semibold tracking-tight text-ink tabular-nums">{{ $money($r['revenue_cents']) }}</p>
            <p class="mt-1 text-sm text-muted">{{ number_format($r['booked']) }} {{ \Illuminate\Support\Str::plural('job', $r['booked']) }} booked × {{ $money($r['job_value_cents']) }} average job value</p>
        @else
            <p class="text-sm font-medium text-muted">Estimated revenue from jobs we booked</p>
            <p class="mt-1 text-lg text-ink">Tell us your average job value to see what our bookings are worth to you.</p>
        @endif
        @if ($canEditValue)
            <form wire:submit="saveJobValue" class="mt-4 flex flex-wrap items-end gap-2">
                <div>
                    <label for="r-value" class="sh-label">Average job value ($)</label>
                    <input id="r-value" type="text" inputmode="decimal" wire:model="jobValue" class="sh-input w-36" placeholder="250">
                </div>
                <x-ui.button type="submit" variant="secondary" size="sm">Save</x-ui.button>
                @error('jobValue') <p class="w-full text-sm text-danger">{{ $message }}</p> @enderror
            </form>
        @endif
    </div>

    <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
        <x-ui.stat label="Calls answered" icon="phone" :value="number_format($r['answered'])" :change="$r['change']['answered'] ?? null" hint="{{ number_format($r['calls']) }} calls in total" />
        <x-ui.stat label="Jobs booked" icon="calendar" :value="number_format($r['booked'])" :change="$r['change']['booked'] ?? null" hint="{{ number_format($r['appointments']) }} appointments in the calendar" />
        <x-ui.stat label="New leads" icon="users" :value="number_format($r['leads'])" :change="$r['change']['leads'] ?? null" hint="new callers added to your customers" />
        <x-ui.stat label="After-hours calls caught" icon="clock" :value="$r['after_hours'] === null ? '—' : number_format($r['after_hours'])" :change="$r['change']['after_hours'] ?? null"
            hint="{{ $r['after_hours'] === null ? 'set your hours to see this' : 'answered while you were closed' }}" />
    </div>

    <div class="mt-6 grid gap-6 lg:grid-cols-2">
        <x-ui.card title="How calls ended" :padding="false">
            @php $total = max(1, $r['calls']); @endphp
            <ul class="divide-y divide-line">
                @foreach ($r['outcomes'] as $key => $o)
                    @continue($o['count'] === 0)
                    <li class="px-5 py-3">
                        <div class="flex items-center justify-between text-sm"><span class="text-ink">{{ $o['label'] }}</span><span class="tabular-nums text-muted">{{ number_format($o['count']) }}</span></div>
                        <div class="mt-1.5 h-1.5 overflow-hidden rounded-full bg-surface-2"><div class="h-full rounded-full bg-brand-500" style="width: {{ round($o['count'] / $total * 100) }}%"></div></div>
                    </li>
                @endforeach
                @if ($r['calls'] === 0)<li class="px-5 py-4 text-sm text-muted">No calls in {{ $period['label'] }}.</li>@endif
            </ul>
        </x-ui.card>

        <x-ui.card title="Why people called" :padding="false">
            <ul class="divide-y divide-line">
                @forelse ($r['reasons'] as $reason => $n)
                    <li class="flex items-center justify-between px-5 py-3 text-sm"><span class="text-ink">{{ $reason }}</span><span class="tabular-nums text-muted">{{ number_format($n) }}</span></li>
                @empty
                    <li class="px-5 py-4 text-sm text-muted">No calls in {{ $period['label'] }}.</li>
                @endforelse
            </ul>
        </x-ui.card>

        <x-ui.card class="lg:col-span-2" title="When people call" :description="$r['busiest'] ? 'Busiest: '.$r['busiest'].'. Times in '.str_replace('_', ' ', $organization->timezoneOrDefault()).'.' : 'Times in '.str_replace('_', ' ', $organization->timezoneOrDefault()).'.'">
            <div class="overflow-x-auto">
                <table class="w-full min-w-[40rem] table-fixed border-separate border-spacing-0.5 text-[10px] text-subtle" aria-label="Calls by day and hour">
                    <thead><tr><th class="w-10"></th>@foreach (range(0, 23) as $h)<th scope="col" class="font-normal">{{ $h % 3 === 0 ? \Carbon\CarbonImmutable::createFromTime($h)->format('ga') : '' }}</th>@endforeach</tr></thead>
                    <tbody>
                        @foreach ([1, 2, 3, 4, 5, 6, 0] as $d)
                            <tr>
                                <th scope="row" class="pr-1 text-right font-normal">{{ $days[$d] }}</th>
                                @foreach (range(0, 23) as $h)
                                    @php $n = $r['heatmap'][$d][$h]; @endphp
                                    <td class="h-5 rounded-sm" style="background: {{ $n ? 'rgb(99 102 241 / '.round(0.15 + 0.85 * $n / $peak, 2).')' : 'rgb(255 255 255 / 0.04)' }}" title="{{ $days[$d] }} {{ \Carbon\CarbonImmutable::createFromTime($h)->format('ga') }}: {{ $n }} {{ \Illuminate\Support\Str::plural('call', $n) }}"></td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-ui.card>
    </div>
</div>
