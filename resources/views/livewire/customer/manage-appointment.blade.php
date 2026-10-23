<div>
    <x-ui.card>
        <div class="flex items-center gap-3">
            @if ($logoUrl)<img src="{{ $logoUrl }}" alt="" class="size-10 rounded-lg object-contain">@endif
            <p class="text-sm font-medium text-brand-300">{{ $business }}</p>
        </div>
        <h1 class="mt-1 text-xl font-semibold text-ink">{{ __('Your appointment') }}</h1>

        <dl class="mt-5 space-y-3 text-sm">
            <div><dt class="text-xs text-subtle">{{ __('What') }}</dt><dd class="text-ink">{{ $appointment->service?->name ?? $appointment->title }}</dd></div>
            <div><dt class="text-xs text-subtle">{{ __('When') }}</dt><dd class="text-ink">{{ $appointment->starts_at->setTimezone($timezone)->locale(app()->getLocale())->translatedFormat(__('l, F j \a\t g:i A')) }}
                <span class="text-subtle">({{ str_replace('_', ' ', $timezone) }})</span></dd></div>
            @if ($appointment->address)<div><dt class="text-xs text-subtle">{{ __('Where') }}</dt><dd class="text-ink">{{ $appointment->address }}</dd></div>@endif
            <div><dt class="text-xs text-subtle">{{ __('Status') }}</dt><dd><x-ui.badge :tone="$appointment->status->tone()">{{ __($appointment->status->label()) }}</x-ui.badge></dd></div>
        </dl>

        @if ($done || $appointment->status === \App\Enums\AppointmentStatus::Cancelled)
            <x-ui.alert tone="info" class="mt-6">{{ $done ? __('This appointment is cancelled and the business has been told.') : __('This appointment is cancelled.') }} @if ($phone){{ __('To book again, call :phone.', ['phone' => $phone]) }}@endif</x-ui.alert>
        @elseif (! $canChange)
            <x-ui.alert tone="info" class="mt-6">{{ __('It\'s too close to the appointment to change it online.') }} @if ($phone){{ __('Please call :business on', ['business' => $business]) }} <a href="tel:{{ $phone }}" class="font-semibold underline">{{ $phone }}</a>.@else {{ __('Please contact :business.', ['business' => $business]) }}@endif</x-ui.alert>
        @elseif ($mode === 'move')
            <form wire:submit="move" class="mt-6 space-y-4 border-t border-line pt-5">
                <div>
                    <label for="m-date" class="sh-label">{{ __('New date') }}</label>
                    <input id="m-date" type="date" wire:model.live="date" class="sh-input" min="{{ $today }}">
                </div>
                <div>
                    <p class="sh-label">{{ __('Free times') }}</p>
                    <div role="radiogroup" aria-label="{{ __('Free times') }}" class="flex flex-wrap gap-2">
                        @forelse ($slots as $slot)
                            <button type="button" role="radio" aria-checked="{{ $time === $slot->format('H:i') ? 'true' : 'false' }}" wire:click="$set('time', '{{ $slot->format('H:i') }}')" @class([
                                'rounded-lg px-3 py-1.5 text-sm font-medium',
                                'bg-brand-600 text-white' => $time === $slot->format('H:i'),
                                'bg-surface-2 text-muted ring-1 ring-line hover:text-ink' => $time !== $slot->format('H:i'),
                            ])>{{ $slot->format('g:i A') }}</button>
                        @empty
                            <p class="text-sm text-muted">{{ __('No free times that day. Try another date.') }}</p>
                        @endforelse
                    </div>
                    @error('date') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                    @error('time') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                </div>
                <div class="flex flex-wrap gap-2">
                    <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="move">{{ __('Move my appointment') }}</x-ui.button>
                    <x-ui.button variant="ghost" wire:click="$set('mode', '')">{{ __('Back') }}</x-ui.button>
                </div>
            </form>
        @elseif ($mode === 'cancel')
            <form wire:submit="cancel" class="mt-6 space-y-4 border-t border-line pt-5">
                <div>
                    <label for="c-reason" class="sh-label">{{ __('Anything the business should know? (optional)') }}</label>
                    <input id="c-reason" type="text" wire:model="reason" maxlength="200" class="sh-input">
                    @error('reason') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                </div>
                <div class="flex flex-wrap gap-2">
                    <x-ui.button type="submit" variant="danger" wire:loading.attr="disabled" wire:target="cancel">{{ __('Yes, cancel it') }}</x-ui.button>
                    <x-ui.button variant="ghost" wire:click="$set('mode', '')">{{ __('Keep my appointment') }}</x-ui.button>
                </div>
            </form>
        @else
            <div class="mt-6 flex flex-wrap gap-2 border-t border-line pt-5">
                <x-ui.button wire:click="startMove">{{ __('Change the time') }}</x-ui.button>
                <x-ui.button variant="secondary" wire:click="$set('mode', 'cancel')">{{ __('Cancel') }}</x-ui.button>
            </div>
            @if ($deadline)<p class="mt-3 text-xs text-subtle">{{ __('You can change or cancel here until :when.', ['when' => $deadline->locale(app()->getLocale())->translatedFormat(__('l, F j \a\t g:i A'))]) }}@if ($phone) {{ __('After that, call :phone.', ['phone' => $phone]) }}@endif</p>@endif
        @endif
    </x-ui.card>
</div>
