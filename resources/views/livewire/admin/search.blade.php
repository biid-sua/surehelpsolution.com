@php
    $labels = ['businesses' => 'Businesses', 'people' => 'People', 'callers' => 'Callers', 'calls' => 'Calls', 'appointments' => 'Appointments'];
    $business = fn ($org) => $org ? '<a href="'.e(route('admin.organizations.show', $org)).'" class="text-brand-300 hover:underline">'.e($org->name).'</a>' : '<span class="text-subtle">No business</span>';
@endphp
<div>
    <x-ui.page-header title="Search" description="Businesses, people, callers, call IDs and appointments across the platform." />

    <div class="relative mb-6 max-w-2xl">
        <x-ui.icon name="search" class="pointer-events-none absolute left-3 top-1/2 size-5 -translate-y-1/2 text-subtle" />
        <label for="global-q" class="sr-only">Search</label>
        <input id="global-q" type="search" wire:model.live.debounce.300ms="q" class="sh-input py-3 pl-11 text-base" placeholder="Name, email, phone, call ID (CL-…), business…" autocomplete="off" autofocus>
    </div>

    @if (mb_strlen($term) < 2)
        <x-ui.empty-state icon="search" title="Type at least two characters" description="Try a caller's phone number, a call ID like CL-20261005-0012, an owner's email or a business name." />
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
                                    @case('businesses')
                                        <a href="{{ route('admin.organizations.show', $item) }}" class="font-medium text-ink hover:text-brand-300">{{ $item->name }}</a>
                                        <p class="text-xs text-subtle">{{ $item->status->label() }} · {{ $item->timezone ?? 'timezone not set' }}</p>
                                        @break
                                    @case('people')
                                        <div class="flex items-center justify-between gap-2">
                                            <a href="{{ route('admin.users', ['search' => $item->email]) }}" class="font-medium text-ink hover:text-brand-300">{{ $item->name }}</a>
                                            @if ($canImpersonate && $item->isClient() && $item->is_active)
                                                <form method="POST" action="{{ route('admin.impersonate', $item) }}">@csrf<x-ui.button type="submit" size="sm" variant="ghost">View as</x-ui.button></form>
                                            @endif
                                        </div>
                                        <p class="text-xs text-subtle">{{ $item->email }} · {{ ['admin' => 'Staff', 'agent' => 'Agent', 'client' => 'Business user'][$item->role] ?? $item->role }}@if ($item->organizations->isNotEmpty()) · {{ $item->organizations->pluck('name')->join(', ') }}@endif{{ $item->is_active ? '' : ' · switched off' }}</p>
                                        @break
                                    @case('callers')
                                        <p class="font-medium text-ink">{{ $item->fullName() }}</p>
                                        <p class="text-xs text-subtle">{{ $item->displayPhone() ?? $item->email }} · {!! $business($item->organization) !!}</p>
                                        @break
                                    @case('calls')
                                        <p class="font-medium text-ink">{{ \App\Models\CallLog::display($item->caller_name) }} <span class="font-mono text-xs text-subtle">{{ $item->call_id }}</span></p>
                                        <p class="text-xs text-subtle">{{ $item->created_at->format('M j, Y g:i A') }} UTC · {{ $item->statusLabel() }} · {!! $business($item->organization) !!}</p>
                                        @break
                                    @case('appointments')
                                        <p class="font-medium text-ink">{{ $item->title }}</p>
                                        <p class="text-xs text-subtle">{{ $item->starts_at->setTimezone($item->organization?->timezoneOrDefault() ?? 'UTC')->format('M j, Y g:i A') }} · {{ $item->status->label() }}{{ $item->customer ? ' · '.$item->customer->fullName() : '' }} · {!! $business($item->organization) !!}</p>
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
