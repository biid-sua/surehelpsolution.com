@props(['businesses', 'placeholder' => 'Search…'])
{{-- Filters shared by the admin console's platform-wide lists (FiltersPlatformRecords). --}}
<div class="mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-[minmax(0,1fr)_14rem_9.5rem_9.5rem_auto] lg:items-end">
    <div>
        <label for="rf-search" class="sh-label">Search</label>
        <div class="relative">
            <x-ui.icon name="search" class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-subtle" />
            <input id="rf-search" type="search" wire:model.live.debounce.350ms="search" class="sh-input pl-9" placeholder="{{ $placeholder }}" autocomplete="off">
        </div>
    </div>
    <div>
        <label for="rf-business" class="sh-label">Business</label>
        <select id="rf-business" wire:model.live="business" class="sh-input">
            <option value="">All businesses</option>
            @foreach ($businesses as $b)<option value="{{ $b->ulid }}">{{ $b->name }}</option>@endforeach
        </select>
    </div>
    <div>
        <label for="rf-from" class="sh-label">From</label>
        <input id="rf-from" type="date" wire:model.live="from" class="sh-input">
    </div>
    <div>
        <label for="rf-to" class="sh-label">To</label>
        <input id="rf-to" type="date" wire:model.live="to" class="sh-input">
    </div>
    <div class="flex gap-2">
        {{ $slot }}
        <x-ui.button variant="ghost" wire:click="clearFilters">Clear</x-ui.button>
    </div>
</div>
