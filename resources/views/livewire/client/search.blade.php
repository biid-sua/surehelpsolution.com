@php
    $labels = ['customers' => 'Customers', 'calls' => 'Calls', 'appointments' => 'Appointments', 'tasks' => 'Tasks'];
@endphp
<div>
    <x-ui.page-header title="Search" description="Customers, calls, appointments and tasks in your business." />

    <div class="relative mb-6 max-w-2xl">
        <x-ui.icon name="search" class="pointer-events-none absolute left-3 top-1/2 size-5 -translate-y-1/2 text-subtle" />
        <label for="app-q" class="sr-only">Search</label>
        <input id="app-q" type="search" wire:model.live.debounce.300ms="q" class="sh-input py-3 pl-11 text-base" placeholder="Name, phone, email, call ID (CL-…)…" autocomplete="off" autofocus>
    </div>

    @if (mb_strlen($term) < 2)
        <x-ui.empty-state icon="search" title="Type at least two characters" description="Try a caller's name, part of a phone number, an email or a call ID." />
    @elseif ($total === 0)
        <x-ui.empty-state icon="search" title="Nothing found for “{{ $term }}”" description="Check the spelling, or search for part of a phone number or email." />
    @else
        <div class="grid gap-6 lg:grid-cols-2">
            @foreach ($results as $group => $items)
                @continue($items->isEmpty())
                <x-ui.card :title="$labels[$group]" :padding="false">
                    <ul class="divide-y divide-line" role="list">
                        @foreach ($items as $item)
                            <li class="px-5 py-3 text-sm">
                                @switch($group)
                                    @case('customers')
                                        <a href="{{ route('app.customers.show', $item->ulid) }}" class="font-medium text-ink hover:text-brand-300">{{ $item->fullName() }}</a>
                                        <p class="text-xs text-subtle">{{ $item->displayPhone() ?? 'No phone' }}@if ($item->email) · {{ $item->email }}@endif</p>
                                        @break
                                    @case('calls')
                                        <a href="{{ route('app.calls.show', $item->call_id) }}" class="font-medium text-ink hover:text-brand-300">{{ \App\Models\CallLog::display($item->caller_name) }}</a>
                                        <p class="text-xs text-subtle">{{ $item->call_id }} · {{ $item->created_at->setTimezone($timezone)->format('M j, g:i A') }} · {{ $item->statusLabel() }}</p>
                                        @break
                                    @case('appointments')
                                        <a href="{{ route('app.appointments.index', ['appointment' => $item->ulid]) }}" class="font-medium text-ink hover:text-brand-300">{{ $item->title }}</a>
                                        <p class="text-xs text-subtle">{{ $item->starts_at->setTimezone($timezone)->format('D M j, g:i A') }} · {{ $item->status->label() }}</p>
                                        @break
                                    @case('tasks')
                                        <a href="{{ route('app.tasks.index', ['task' => $item->ulid]) }}" class="font-medium text-ink hover:text-brand-300">{{ $item->title }}</a>
                                        <p class="text-xs text-subtle">{{ $item->type->label() }} · {{ $item->status->label() }}@if ($item->customer) · {{ $item->customer->fullName() }}@endif</p>
                                        @break
                                @endswitch
                            </li>
                        @endforeach
                    </ul>
                </x-ui.card>
            @endforeach
        </div>
    @endif
</div>
