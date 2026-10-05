<div>
    <x-ui.page-header title="My schedule" description="Your shifts for the next four weeks. Times are in {{ str_replace('_', ' ', $timezone) }}.">
        @if ($canAsk)
            <x-slot:actions>
                <x-ui.button variant="secondary" wire:click="ask('leave')">Ask for time off</x-ui.button>
                <x-ui.button wire:click="ask('swap')" :disabled="$swappable->isEmpty()">Hand over a shift</x-ui.button>
            </x-slot:actions>
        @endif
    </x-ui.page-header>

    @if ($asking)
        <x-ui.card class="mb-6" :title="$request['type'] === 'leave' ? 'Ask for time off' : 'Hand over a shift'" description="Whoever plans the duty schedule decides. Until then, nothing changes.">
            <form wire:submit="submit" class="grid gap-4 sm:grid-cols-2">
                @if ($request['type'] === 'swap')
                    <div>
                        <label for="r-shift" class="sh-label">Shift</label>
                        <select id="r-shift" wire:model="request.shift_id" class="sh-input">
                            <option value="">Choose…</option>
                            @foreach ($swappable as $s)
                                <option value="{{ $s->id }}" @disabled(in_array($s->id, $pendingShiftIds, true))>{{ $s->start_datetime->format('D M j, g:i A') }} – {{ $s->end_datetime->format('g:i A') }} · {{ $s->title }}</option>
                            @endforeach
                        </select>
                        @error('request.shift_id') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="r-with" class="sh-label">Colleague who could take it <span class="text-subtle">(optional)</span></label>
                        <select id="r-with" wire:model="request.swap_with_id" class="sh-input">
                            <option value="">Let the scheduler choose</option>
                            @foreach ($colleagues as $colleague)
                                <option value="{{ $colleague->id }}">{{ $colleague->name }}</option>
                            @endforeach
                        </select>
                        @error('request.swap_with_id') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                    </div>
                @else
                    <div>
                        <label for="r-from" class="sh-label">First day off</label>
                        <input id="r-from" type="date" wire:model="request.leave_from" class="sh-input" min="{{ now()->toDateString() }}">
                        @error('request.leave_from') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="r-until" class="sh-label">Last day off</label>
                        <input id="r-until" type="date" wire:model="request.leave_until" class="sh-input" min="{{ now()->toDateString() }}">
                        @error('request.leave_until') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                    </div>
                @endif
                <div class="sm:col-span-2">
                    <label for="r-reason" class="sh-label">Reason <span class="text-subtle">(optional)</span></label>
                    <textarea id="r-reason" wire:model="request.reason" rows="2" class="sh-input" maxlength="1000"></textarea>
                    @error('request.reason') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                </div>
                <div class="flex justify-end gap-2 sm:col-span-2">
                    <x-ui.button type="button" variant="ghost" wire:click="cancelAsking">Cancel</x-ui.button>
                    <x-ui.button type="submit">Send request</x-ui.button>
                </div>
            </form>
        </x-ui.card>
    @endif

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
                                    @if ($canAsk && $swappable->contains('id', $s->id))
                                        @if (in_array($s->id, $pendingShiftIds, true))
                                            <x-ui.badge tone="warning">Hand-over asked</x-ui.badge>
                                        @else
                                            <button type="button" wire:click="ask('swap', {{ $s->id }})" class="text-sm text-brand-300 hover:underline">Hand over</button>
                                        @endif
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-ui.card>

    @if ($requests->isNotEmpty())
        <x-ui.card class="mt-6" :padding="false" title="Your requests" description="Waiting ones, and decisions from the last 30 days.">
            <ul class="divide-y divide-line" role="list">
                @foreach ($requests as $item)
                    <li class="flex flex-wrap items-center gap-3 px-5 py-3" wire:key="req-{{ $item->id }}">
                        <x-ui.badge :tone="$item->statusTone()">{{ \App\Models\ShiftRequest::STATUS_LABELS[$item->status] ?? $item->status }}</x-ui.badge>
                        <div class="min-w-0 flex-1">
                            <p class="text-sm text-ink">{{ $item->summary() }}</p>
                            @if ($item->decision_note)<p class="text-xs text-muted">Note: {{ $item->decision_note }}</p>@endif
                        </div>
                        @if ($item->isPending())
                            <button type="button" wire:click="withdraw('{{ $item->ulid }}')" wire:confirm="Withdraw this request?" class="text-sm text-muted hover:text-ink">Withdraw</button>
                        @endif
                    </li>
                @endforeach
            </ul>
        </x-ui.card>
    @endif
</div>
