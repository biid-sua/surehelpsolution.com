@php
    $tone = ['morning' => 'brand', 'afternoon' => 'info', 'evening' => 'warning', 'night' => 'scheduled', 'off' => 'neutral'];
@endphp
<div>
    <x-ui.page-header title="Duty schedule" description="Who answers calls when. Times are in {{ str_replace('_', ' ', $timezone) }}.">
        <x-slot:actions>
            <div class="flex items-center gap-1">
                <x-ui.button variant="secondary" size="sm" wire:click="previousWeek" aria-label="Previous week"><x-ui.icon name="chevron-left" class="size-4" /></x-ui.button>
                <x-ui.button variant="secondary" size="sm" wire:click="thisWeek">This week</x-ui.button>
                <x-ui.button variant="secondary" size="sm" wire:click="nextWeek" aria-label="Next week"><x-ui.icon name="chevron-right" class="size-4" /></x-ui.button>
            </div>
            @if ($canEdit)
                <x-ui.confirm id="copy-week" title="Copy last week's shifts?" confirm-label="Copy shifts" tone="primary" action="copyPreviousWeek">
                    <x-slot:trigger><x-ui.button variant="secondary" icon="refresh">Copy last week</x-ui.button></x-slot:trigger>
                    Every shift from the week before is repeated in this week. Shifts that would overlap one already planned are skipped.
                </x-ui.confirm>
                <x-ui.button icon="calendar" wire:click="add">Add shift</x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    <div class="mb-4 flex flex-wrap items-center gap-x-6 gap-y-2 text-sm text-muted">
        <p class="font-medium text-ink">{{ $days->first()->format('M j') }} – {{ $days->last()->format('M j, Y') }}</p>
        <p>
            <span class="text-subtle">On shift now:</span>
            @forelse ($onShift as $agent)
                <x-ui.badge tone="success">{{ $agent->name }}</x-ui.badge>
            @empty
                <span>nobody</span>
            @endforelse
        </p>
    </div>

    @if ($open)
        <x-ui.card class="mb-6" :title="$editing ? 'Edit shift' : 'Add a shift'" description="An end time earlier than the start means the shift ends the next morning.">
            <form wire:submit="save" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <div class="lg:col-span-2">
                    <label for="s-agent" class="sh-label">Agent</label>
                    <select id="s-agent" wire:model="shift.agent_id" class="sh-input">
                        <option value="">Choose…</option>
                        @foreach ($agents as $agent)
                            <option value="{{ $agent->id }}">{{ $agent->name }}</option>
                        @endforeach
                    </select>
                    @error('shift.agent_id') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="s-type" class="sh-label">Shift</label>
                    <select id="s-type" wire:model="shift.shift_type" class="sh-input">
                        @foreach ($types as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="s-date" class="sh-label">Date</label>
                    <input id="s-date" type="date" wire:model="shift.date" class="sh-input">
                    @error('shift.date') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="s-start" class="sh-label">Starts</label>
                    <input id="s-start" type="time" wire:model="shift.start" class="sh-input">
                    @error('shift.start') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="s-end" class="sh-label">Ends</label>
                    <input id="s-end" type="time" wire:model="shift.end" class="sh-input">
                    @error('shift.end') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                </div>
                <div class="lg:col-span-2">
                    <label for="s-title" class="sh-label">Label (optional)</label>
                    <input id="s-title" type="text" wire:model="shift.title" class="sh-input" placeholder="e.g. Overflow cover">
                </div>
                <div class="sm:col-span-2 lg:col-span-4">
                    <label for="s-notes" class="sh-label">Notes for the agent (optional)</label>
                    <textarea id="s-notes" wire:model="shift.description" rows="2" class="sh-input"></textarea>
                </div>
                <div class="flex flex-wrap justify-between gap-2 sm:col-span-2 lg:col-span-4">
                    <div>
                        @if ($editing)
                            <x-ui.confirm id="delete-shift" title="Remove this shift?" confirm-label="Remove" action="delete">
                                <x-slot:trigger><x-ui.button variant="ghost">Remove shift</x-ui.button></x-slot:trigger>
                                The agent will no longer see it in their schedule.
                            </x-ui.confirm>
                        @endif
                    </div>
                    <div class="flex gap-2">
                        <x-ui.button variant="secondary" wire:click="close">Cancel</x-ui.button>
                        <x-ui.button type="submit">Save shift</x-ui.button>
                    </div>
                </div>
            </form>
        </x-ui.card>
    @endif

    @if ($requests->isNotEmpty())
        <x-ui.card class="mb-6" :padding="false" title="Requests from agents" description="Approving a hand-over moves the shift; approving time off removes their shifts on those days.">
            <ul class="divide-y divide-line" role="list">
                @foreach ($requests as $item)
                    <li class="grid gap-3 px-5 py-4 lg:grid-cols-[1fr_auto]" wire:key="request-{{ $item->id }}">
                        <div class="min-w-0">
                            <p class="font-medium text-ink">{{ $item->agent->name ?? 'Former agent' }} <span class="font-normal text-muted">· {{ $item->summary() }}</span></p>
                            @if ($item->reason)<p class="mt-0.5 text-sm text-muted">“{{ $item->reason }}”</p>@endif
                            <p class="mt-0.5 text-xs text-subtle">Asked {{ $item->created_at->diffForHumans() }}</p>
                        </div>
                        @if ($canEdit)
                            <div class="flex flex-wrap items-start gap-2">
                                @if ($item->type === 'swap')
                                    <div>
                                        <label for="d-{{ $item->ulid }}-c" class="sr-only">Who takes the shift</label>
                                        <select id="d-{{ $item->ulid }}-c" wire:model="decision.{{ $item->ulid }}.colleague" class="sh-input py-1.5 text-sm">
                                            <option value="">{{ $item->swapWith ? $item->swapWith->name.' (asked)' : 'Who takes it?' }}</option>
                                            @foreach ($agents->where('id', '!=', $item->agent_id) as $agent)
                                                <option value="{{ $agent->id }}">{{ $agent->name }}</option>
                                            @endforeach
                                        </select>
                                        @error('decision.'.$item->ulid.'.colleague') <p class="mt-1 max-w-xs text-sm text-danger">{{ $message }}</p> @enderror
                                    </div>
                                @endif
                                <div>
                                    <label for="d-{{ $item->ulid }}-n" class="sr-only">Note to the agent</label>
                                    <input id="d-{{ $item->ulid }}-n" type="text" wire:model="decision.{{ $item->ulid }}.note" class="sh-input py-1.5 text-sm" placeholder="Note (needed to decline)" maxlength="1000">
                                    @error('decision.'.$item->ulid.'.note') <p class="mt-1 max-w-xs text-sm text-danger">{{ $message }}</p> @enderror
                                </div>
                                <x-ui.button size="sm" variant="secondary" wire:click="decline('{{ $item->ulid }}')">Decline</x-ui.button>
                                <x-ui.button size="sm" wire:click="approve('{{ $item->ulid }}')">Approve</x-ui.button>
                            </div>
                        @endif
                    </li>
                @endforeach
            </ul>
        </x-ui.card>
    @endif

    <x-ui.card :padding="false">
        @if ($agents->isEmpty())
            <x-ui.empty-state icon="users" title="No agents yet" description="Add agents under Users, then plan their shifts here.">
                <x-ui.button :href="route('admin.users', ['type' => 'agent'])" variant="secondary">Go to Users</x-ui.button>
            </x-ui.empty-state>
        @else
            <div class="overflow-x-auto">
                <table class="w-full min-w-[60rem] table-fixed text-sm">
                    <thead>
                        <tr class="border-b border-line text-left text-xs uppercase tracking-wide text-subtle">
                            <th scope="col" class="w-44 px-4 py-3 font-medium">Agent</th>
                            @foreach ($days as $day)
                                <th scope="col" @class(['px-2 py-3 font-medium', 'text-brand-300' => $day->isToday()])>
                                    {{ $day->format('D') }} <span class="tabular-nums">{{ $day->format('j') }}</span>
                                </th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        @foreach ($agents as $agent)
                            <tr wire:key="agent-{{ $agent->id }}">
                                <th scope="row" class="px-4 py-3 text-left align-top font-medium text-ink">
                                    {{ $agent->name }}
                                    <p class="text-xs font-normal text-subtle">{{ $hours[$agent->id] ?? 0 }} h this week</p>
                                </th>
                                @foreach ($days as $day)
                                    <td @class(['px-1.5 py-2 align-top', 'bg-brand-500/5' => $day->isToday()])>
                                        <div class="flex min-h-12 flex-col gap-1">
                                            @foreach ($grid[$agent->id.'|'.$day->toDateString()] ?? [] as $s)
                                                <button type="button" @if ($canEdit) wire:click="edit({{ $s->id }})" @else disabled @endif
                                                    class="rounded-lg bg-surface-2 px-2 py-1.5 text-left ring-1 ring-line transition-colors enabled:hover:ring-brand-500/50"
                                                    title="{{ $s->description }}">
                                                    <x-ui.badge :tone="$tone[$s->shift_type] ?? 'neutral'">{{ $s->title }}</x-ui.badge>
                                                    @if ($s->shift_type !== 'off')
                                                        <p class="mt-1 text-xs tabular-nums text-muted">{{ $s->start_datetime->format('g:i A') }} – {{ $s->end_datetime->format('g:i A') }}@if (! $s->end_datetime->isSameDay($s->start_datetime)) <span class="text-subtle">+1</span>@endif</p>
                                                    @endif
                                                </button>
                                            @endforeach
                                            @if ($canEdit)
                                                <button type="button" wire:click="add({{ $agent->id }}, '{{ $day->toDateString() }}')"
                                                    class="rounded-lg px-2 py-1 text-xs text-subtle opacity-60 hover:bg-surface-2 hover:text-ink hover:opacity-100 focus:opacity-100"
                                                    aria-label="Add a shift for {{ $agent->name }} on {{ $day->format('l, M j') }}">+ Add</button>
                                            @endif
                                        </div>
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-ui.card>
    <x-ui.card class="mt-6" :padding="false" title="Coverage" :description="'Agents on shift in each hour. Hours with fewer than '.$minAgents.' '.str('agent')->plural($minAgents).' are gaps.'">
        <div class="overflow-x-auto p-4">
            <table class="w-full min-w-[48rem] table-fixed border-separate border-spacing-0.5 text-xs">
                <thead>
                    <tr class="text-subtle">
                        <th scope="col" class="w-16 text-left font-medium"><span class="sr-only">Day</span></th>
                        @foreach (range(0, 23) as $hour)
                            <th scope="col" class="font-normal tabular-nums">{{ $hour % 3 === 0 ? \Carbon\CarbonImmutable::today()->setTime($hour, 0)->format('ga') : '' }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($days as $day)
                        <tr>
                            <th scope="row" @class(['text-left font-medium', 'text-brand-300' => $day->isToday(), 'text-muted' => ! $day->isToday()])>{{ $day->format('D j') }}</th>
                            @foreach ($coverage[$day->toDateString()] ?? [] as $hour => $count)
                                <td @class([
                                    'h-7 rounded text-center tabular-nums',
                                    'bg-red-500/25 text-red-200' => $count === 0,
                                    'bg-amber-500/25 text-amber-200' => $count > 0 && $count < $minAgents,
                                    'bg-emerald-500/20 text-emerald-200' => $count >= $minAgents && $count < $minAgents + 1,
                                    'bg-emerald-500/40 text-emerald-100' => $count >= $minAgents + 1,
                                ]) title="{{ $day->format('D M j') }}, {{ $day->setTime($hour, 0)->format('g A') }}: {{ $count }} {{ str('agent')->plural($count) }}">{{ $count }}</td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <p class="mt-3 flex flex-wrap gap-4 text-xs text-muted">
                <span class="inline-flex items-center gap-1.5"><span class="size-3 rounded bg-red-500/40"></span> Nobody on shift</span>
                @if ($minAgents > 1)<span class="inline-flex items-center gap-1.5"><span class="size-3 rounded bg-amber-500/40"></span> Too few</span>@endif
                <span class="inline-flex items-center gap-1.5"><span class="size-3 rounded bg-emerald-500/40"></span> Covered</span>
            </p>
        </div>
    </x-ui.card>
</div>
