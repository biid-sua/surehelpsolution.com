<div>
    <x-ui.card>
        <div class="flex items-center gap-3">
            @if ($logoUrl)<img src="{{ $logoUrl }}" alt="" class="size-10 rounded-lg object-contain">@endif
            <p class="text-sm font-medium text-brand-300">{{ $business }}</p>
        </div>
        <h1 class="mt-1 text-xl font-semibold text-ink">Your appointment</h1>

        <dl class="mt-5 space-y-3 text-sm">
            <div><dt class="text-xs text-subtle">What</dt><dd class="text-ink">{{ $appointment->service?->name ?? $appointment->title }}</dd></div>
            <div><dt class="text-xs text-subtle">When</dt><dd class="text-ink">{{ $appointment->starts_at->setTimezone($timezone)->format('l, F j \a\t g:i A') }}
                <span class="text-subtle">({{ str_replace('_', ' ', $timezone) }})</span></dd></div>
            @if ($appointment->address)<div><dt class="text-xs text-subtle">Where</dt><dd class="text-ink">{{ $appointment->address }}</dd></div>@endif
            <div><dt class="text-xs text-subtle">Status</dt><dd><x-ui.badge :tone="$appointment->status->tone()">{{ $appointment->status->label() }}</x-ui.badge></dd></div>
        </dl>

        @if ($done || $appointment->status === \App\Enums\AppointmentStatus::Cancelled)
            <x-ui.alert tone="info" class="mt-6">This appointment is cancelled{{ $done ? ' and the business has been told' : '' }}. @if ($phone)To book again, call {{ $phone }}.@endif</x-ui.alert>
        @elseif (! $canChange)
            <x-ui.alert tone="info" class="mt-6">It's too close to the appointment to change it online. @if ($phone)Please call {{ $business }} on <a href="tel:{{ $phone }}" class="font-semibold underline">{{ $phone }}</a>.@else Please contact {{ $business }}.@endif</x-ui.alert>
        @elseif ($mode === 'move')
            <form wire:submit="move" class="mt-6 space-y-4 border-t border-line pt-5">
                <div>
                    <label for="m-date" class="sh-label">New date</label>
                    <input id="m-date" type="date" wire:model.live="date" class="sh-input" min="{{ $today }}">
                </div>
                <div>
                    <p class="sh-label">Free times</p>
                    <div role="radiogroup" aria-label="Free times" class="flex flex-wrap gap-2">
                        @forelse ($slots as $slot)
                            <button type="button" role="radio" aria-checked="{{ $time === $slot->format('H:i') ? 'true' : 'false' }}" wire:click="$set('time', '{{ $slot->format('H:i') }}')" @class([
                                'rounded-lg px-3 py-1.5 text-sm font-medium',
                                'bg-brand-600 text-white' => $time === $slot->format('H:i'),
                                'bg-surface-2 text-muted ring-1 ring-line hover:text-ink' => $time !== $slot->format('H:i'),
                            ])>{{ $slot->format('g:i A') }}</button>
                        @empty
                            <p class="text-sm text-muted">No free times that day. Try another date.</p>
                        @endforelse
                    </div>
                    @error('date') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                    @error('time') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                </div>
                <div class="flex flex-wrap gap-2">
                    <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="move">Move my appointment</x-ui.button>
                    <x-ui.button variant="ghost" wire:click="$set('mode', '')">Back</x-ui.button>
                </div>
            </form>
        @elseif ($mode === 'cancel')
            <form wire:submit="cancel" class="mt-6 space-y-4 border-t border-line pt-5">
                <div>
                    <label for="c-reason" class="sh-label">Anything the business should know? (optional)</label>
                    <input id="c-reason" type="text" wire:model="reason" maxlength="200" class="sh-input">
                    @error('reason') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                </div>
                <div class="flex flex-wrap gap-2">
                    <x-ui.button type="submit" variant="danger" wire:loading.attr="disabled" wire:target="cancel">Yes, cancel it</x-ui.button>
                    <x-ui.button variant="ghost" wire:click="$set('mode', '')">Keep my appointment</x-ui.button>
                </div>
            </form>
        @else
            <div class="mt-6 flex flex-wrap gap-2 border-t border-line pt-5">
                <x-ui.button wire:click="startMove">Change the time</x-ui.button>
                <x-ui.button variant="secondary" wire:click="$set('mode', 'cancel')">Cancel</x-ui.button>
            </div>
            @if ($deadline)<p class="mt-3 text-xs text-subtle">You can change or cancel here until {{ $deadline->format('l, F j \a\t g:i A') }}.@if ($phone) After that, call {{ $phone }}.@endif</p>@endif
        @endif
    </x-ui.card>
</div>
