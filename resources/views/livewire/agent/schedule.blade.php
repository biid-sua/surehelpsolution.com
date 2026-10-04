<div>
    <x-ui.page-header title="My schedule" description="Your shifts for the next four weeks. Times are in {{ str_replace('_', ' ', $timezone) }}. Ask your supervisor to change a shift." />

    <div class="mb-6 grid gap-4 sm:grid-cols-2">
        <x-ui.card>
            <p class="text-sm font-medium text-muted">Right now</p>
            @if ($current)
                <p class="mt-2 text-lg font-semibold text-ink">On shift: {{ $current->title }}</p>
                <p class="text-sm text-muted">until {{ $current->end_datetime->format('g:i A') }}</p>
            @else
                <p class="mt-2 text-lg font-semibold text-ink">Off shift</p>
            @endif
        </x-ui.card>
        <x-ui.stat label="Planned this week" icon="clock" :value="$hoursThisWeek.' h'" />
    </div>

    <x-ui.card :padding="false">
        @if ($days->isEmpty())
            <x-ui.empty-state icon="calendar" title="No shifts planned" description="Nothing is scheduled for you in the next four weeks." />
        @else
            <ul class="divide-y divide-line" role="list">
                @foreach ($days as $date => $shifts)
                    @php $day = \Carbon\CarbonImmutable::parse($date); @endphp
                    <li class="flex flex-wrap gap-4 px-5 py-4">
                        <div class="w-28 shrink-0">
                            <p @class(['font-semibold', 'text-brand-300' => $day->isToday(), 'text-ink' => ! $day->isToday()])>{{ $day->isToday() ? 'Today' : ($day->isTomorrow() ? 'Tomorrow' : $day->format('l')) }}</p>
                            <p class="text-sm text-muted">{{ $day->format('M j') }}</p>
                        </div>
                        <div class="flex min-w-0 flex-1 flex-col gap-2">
                            @foreach ($shifts as $s)
                                <div class="flex flex-wrap items-center gap-3">
                                    <x-ui.badge :tone="$s->shift_type === 'off' ? 'neutral' : 'brand'">{{ $s->title }}</x-ui.badge>
                                    @if ($s->shift_type !== 'off')
                                        <span class="text-sm tabular-nums text-ink">{{ $s->start_datetime->format('g:i A') }} – {{ $s->end_datetime->format('g:i A') }}@if (! $s->end_datetime->isSameDay($s->start_datetime)) <span class="text-subtle">(next day)</span>@endif</span>
                                    @endif
                                    @if ($s->description)<span class="text-sm text-muted">{{ $s->description }}</span>@endif
                                </div>
                            @endforeach
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-ui.card>
</div>
