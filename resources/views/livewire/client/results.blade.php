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
        @if ($value && $value['value_cents'] !== null)
            {{-- The dollars report (D107) --}}
            <p class="text-sm font-medium text-muted">What SureHelp was worth to you in {{ $period['label'] }}</p>
            <p class="mt-1 text-4xl font-semibold tracking-tight text-ink tabular-nums">{{ $money($value['value_cents']) }}</p>
            <p class="mt-1 text-sm text-muted">
                {{ number_format($value['jobs']) }} {{ \Illuminate\Support\Str::plural('job', $value['jobs']) }} booked{{ $value['priced_jobs'] || $value['estimated_jobs'] ? ':' : '.' }}
                {{ collect([
                    $value['priced_jobs'] ? number_format($value['priced_jobs']).' at your service prices' : null,
                    $value['estimated_jobs'] ? number_format($value['estimated_jobs']).' at your average job value'.($r['job_value_cents'] ? ' of '.$money($r['job_value_cents']) : '') : null,
                ])->filter()->implode(', ') }}{{ $value['priced_jobs'] || $value['estimated_jobs'] ? '.' : '' }}
                @if ($value['unvalued_jobs']) {{ number_format($value['unvalued_jobs']) }} more had no price, so they aren't counted. @endif
            </p>
            <dl class="mt-5 grid grid-cols-2 gap-4 lg:grid-cols-4">
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-subtle">You paid SureHelp</dt>
                    <dd class="mt-1 text-xl font-semibold text-ink tabular-nums">{{ $value['cost_cents'] !== null ? $money($value['cost_cents']) : '—' }}</dd>
                    <dd class="text-xs text-subtle">{{ ['invoices' => 'invoiced for this month', 'plan' => 'your plan\'s monthly price'][$value['cost_source']] ?? 'no plan or invoice yet' }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-subtle">Your return</dt>
                    <dd class="mt-1 text-xl font-semibold text-ink tabular-nums">{{ $value['multiple'] !== null ? ($value['multiple'] >= 10 ? number_format($value['multiple']) : number_format($value['multiple'], 1)).'×' : '—' }}</dd>
                    <dd class="text-xs text-subtle">{{ $value['multiple'] !== null ? 'what you paid us' : 'shown once there is a plan' }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-subtle">Booked while you were closed</dt>
                    <dd class="mt-1 text-xl font-semibold text-ink tabular-nums">{{ $value['after_hours_value_cents'] !== null ? $money($value['after_hours_value_cents']) : '—' }}</dd>
                    <dd class="text-xs text-subtle">{{ $value['after_hours_jobs'] === null ? 'set your hours to see this' : number_format($value['after_hours_jobs']).' '.\Illuminate\Support\Str::plural('job', $value['after_hours_jobs']).' after hours' }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-subtle">Since {{ $value['since']?->format('F Y') ?? 'you joined' }}</dt>
                    <dd class="mt-1 text-xl font-semibold text-ink tabular-nums">{{ $value['since_value_cents'] !== null ? $money($value['since_value_cents']) : '—' }}</dd>
                    <dd class="text-xs text-subtle">booked for you in total</dd>
                </div>
            </dl>
            <p class="mt-4 text-xs text-subtle">Job values are estimates from your service prices and average job value. Calls, bookings and what you paid are counted from your records.</p>
        @elseif ($value)
            <p class="text-sm font-medium text-muted">What SureHelp was worth to you in {{ $period['label'] }}</p>
            <p class="mt-1 text-lg text-ink">{{ number_format($value['jobs']) }} {{ \Illuminate\Support\Str::plural('job', $value['jobs']) }} booked. Add your average job value, or prices to your services, to see what they're worth.</p>
        @elseif ($r['revenue_cents'] !== null)
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
        <x-ui.stat label="Calls answered" icon="phone" :value="number_format($r['answered'])" :change="$r['change']['answered'] ?? null" hint="{{ number_format($r['calls']) }} calls in total{{ $r['ai_calls'] ? ', '.number_format($r['ai_calls']).' by the AI receptionist' : '' }}" />
        <x-ui.stat label="Jobs booked" icon="calendar" :value="number_format($r['booked'])" :change="$r['change']['booked'] ?? null" hint="{{ number_format($r['appointments']) }} appointments in the calendar" />
        <x-ui.stat label="New leads" icon="users" :value="number_format($r['leads'])" :change="$r['change']['leads'] ?? null" hint="new callers added to your customers" />
        <x-ui.stat label="After-hours calls caught" icon="clock" :value="$r['after_hours'] === null ? '—' : number_format($r['after_hours'])" :change="$r['change']['after_hours'] ?? null"
            hint="{{ $r['after_hours'] === null ? 'set your hours to see this' : 'answered while you were closed' }}" />
    </div>

    {{-- Where customers came from (G-5, D68) --}}
    <h2 class="mt-8 mb-3 text-base font-semibold text-ink">Where customers came from</h2>
    <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
        <x-ui.stat label="New customers" icon="users" :value="number_format($s['leads_total'])" :change="$s['change']['leads_total'] ?? null" hint="who got in touch themselves" />
        <x-ui.stat label="Bookings made" icon="calendar" :value="number_format($s['bookings_total'])" :change="$s['change']['bookings_total'] ?? null" hint="by phone, online and by your team" />
        <x-ui.stat label="Website pages viewed" icon="globe" :value="$s['website_views'] === null ? '—' : number_format($s['website_views'])" :change="$s['change']['website_views'] ?? null"
            hint="{{ $s['website_views'] === null ? 'add the SureHelp snippet to your site to see this' : number_format($s['website_leads']).' new customers from your website' }}" />
        <x-ui.stat label="Social posts published" icon="megaphone" :value="number_format($s['social_posts'])" :change="$s['change']['social_posts'] ?? null"
            hint="{{ number_format($s['social_messages']) }} Facebook and Instagram conversations" />
    </div>

    @if ($reviews)
        {{-- Reputation (D83) --}}
        <h2 class="mt-8 mb-3 text-base font-semibold text-ink">Reviews</h2>
        <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
            <x-ui.stat label="New Google reviews" icon="star" :value="number_format($reviews['count'])" hint="{{ $reviews['average'] === null ? 'none this month' : 'averaging '.number_format($reviews['average'], 1).' stars' }}" />
            <x-ui.stat label="Replied to" icon="chat" :value="number_format($reviews['replied'])" hint="of this month's reviews" />
            <x-ui.stat label="Google rating now" icon="trend-up" :value="$reviews['rating'] === null ? '—' : number_format($reviews['rating'], 1)" hint="across all your reviews" />
        </div>
    @endif

    @if ($surveys['sent'] > 0)
        {{-- Satisfaction surveys (D84) --}}
        <h2 class="mt-8 mb-3 text-base font-semibold text-ink">Customer satisfaction</h2>
        <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
            <x-ui.stat label="Average rating" icon="star" :value="$surveys['average'] === null ? '—' : number_format($surveys['average'], 1).' / 5'" hint="{{ number_format($surveys['answered']) }} of {{ number_format($surveys['sent']) }} surveys answered" />
            <x-ui.stat label="Happy customers" icon="check-circle" :value="$surveys['happy'] === null ? '—' : $surveys['happy'].'%'" hint="gave 4 or 5" />
            <x-ui.stat label="Went on to review" icon="trend-up" :value="number_format($surveys['review_clicks'])" hint="opened your Google review link" />
        </div>
    @endif

    <div class="mt-6 grid gap-6 lg:grid-cols-2">
        @foreach ([['New customers by how they first got in touch', $leadSources, $s['leads'], $s['leads_total']], ['Bookings by who made them', $bookingSources, $s['bookings'], $s['bookings_total']]] as [$title, $labels, $counts, $sum])
            <x-ui.card :title="$title" :padding="false">
                <ul class="divide-y divide-line">
                    @foreach ($labels as $key => $label)
                        @continue($counts[$key] === 0)
                        <li class="px-5 py-3">
                            <div class="flex items-center justify-between text-sm"><span class="text-ink">{{ $label }}</span><span class="tabular-nums text-muted">{{ number_format($counts[$key]) }} · {{ round($counts[$key] / max(1, $sum) * 100) }}%</span></div>
                            <div class="mt-1.5 h-1.5 overflow-hidden rounded-full bg-surface-2"><div class="h-full rounded-full bg-brand-500" style="width: {{ round($counts[$key] / max(1, $sum) * 100) }}%"></div></div>
                        </li>
                    @endforeach
                    @if ($sum === 0)<li class="px-5 py-4 text-sm text-muted">None in {{ $period['label'] }}.</li>@endif
                </ul>
                @if ($loop->first && $s['added_by_team'] > 0)
                    <p class="border-t border-line px-5 py-3 text-xs text-subtle">Not counted: {{ number_format($s['added_by_team']) }} {{ \Illuminate\Support\Str::plural('customer', $s['added_by_team']) }} your team added or imported.</p>
                @endif
            </x-ui.card>
        @endforeach
    </div>

    <h2 class="mt-8 mb-3 text-base font-semibold text-ink">Calls</h2>
    <div class="grid gap-6 lg:grid-cols-2">
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

        @php $apptTotal = array_sum(array_column($r['appointment_outcomes'], 'count')); @endphp
        <x-ui.card title="How appointments went" description="Appointments that were scheduled in this month." :padding="false">
            <ul class="divide-y divide-line">
                @foreach ($r['appointment_outcomes'] as $key => $o)
                    @continue($o['count'] === 0)
                    <li class="px-5 py-3">
                        <div class="flex items-center justify-between text-sm"><span class="text-ink">{{ $o['label'] }}</span><span class="tabular-nums text-muted">{{ number_format($o['count']) }}</span></div>
                        <div class="mt-1.5 h-1.5 overflow-hidden rounded-full bg-surface-2"><div @class(['h-full rounded-full', 'bg-emerald-500' => $key === 'completed', 'bg-brand-500' => $key === 'upcoming', 'bg-amber-500' => $key === 'no_show', 'bg-white/30' => $key === 'cancelled']) style="width: {{ round($o['count'] / max(1, $apptTotal) * 100) }}%"></div></div>
                    </li>
                @endforeach
                @if ($apptTotal === 0)<li class="px-5 py-4 text-sm text-muted">No appointments in {{ $period['label'] }}.</li>@endif
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
