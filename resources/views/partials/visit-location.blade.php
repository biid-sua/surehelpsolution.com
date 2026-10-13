{{--
    Where an appointment happens (D56): an exact address, a saved place, or remote.
    Data comes from EditsVisitLocation::locationViewData(): $loc, $locPath (the wire:model path), $locOptional,
    $locCustomerChosen, $savedLocations, $countries, $regions, $regionLabel, $postalLabel, $placeTypes.
--}}
@php $id = str_replace('.', '-', $locPath); @endphp
<fieldset class="sm:col-span-2 rounded-xl bg-surface-2/50 p-4 ring-1 ring-line" aria-describedby="{{ $id }}-help">
    <legend class="px-1 text-sm font-semibold text-ink">Location</legend>

    <div class="flex flex-wrap gap-x-5 gap-y-2" role="radiogroup" aria-label="Where it happens">
        @foreach (['address' => 'Specify exact address', 'saved' => 'Use saved location', 'remote' => 'Remote (no address)'] as $value => $label)
            <label class="flex items-center gap-2 text-sm text-ink">
                <input type="radio" name="{{ $id }}-mode" value="{{ $value }}" wire:model.live="{{ $locPath }}.mode" class="size-4 border-line-strong bg-surface-2 text-brand-500"> {{ $label }}
            </label>
        @endforeach
    </div>
    <hr class="my-4 border-line">

    @if ($loc['mode'] === 'remote')
        <p id="{{ $id }}-help" class="text-sm text-muted">By phone or video. No address is recorded, and address rules don't apply.</p>
    @elseif ($loc['mode'] === 'saved')
        @if ($savedLocations === [])
            <p id="{{ $id }}-help" class="text-sm text-muted">
                {{ $locCustomerChosen ? 'This customer has no saved locations yet, and the business has none set up.' : 'Choose the customer to see their saved locations.' }}
                Use <button type="button" class="font-medium text-brand-300 hover:underline" wire:click="$set('{{ $locPath }}.mode', 'address')">Specify exact address</button> instead.
            </p>
        @else
            <p id="{{ $id }}-help" class="sr-only">Choose one of the customer's places or one of the business's locations.</p>
            @unless ($locCustomerChosen)
                <p class="mb-3 text-sm text-muted">Choose the customer to see their saved places too.</p>
            @endunless
            <div class="space-y-4">
                @foreach (collect($savedLocations)->groupBy('group', preserveKeys: true) as $group => $options)
                    <div class="grid gap-2" role="radiogroup" aria-labelledby="{{ $id }}-group-{{ $group }}">
                        <p id="{{ $id }}-group-{{ $group }}" class="text-xs font-medium uppercase tracking-wide text-subtle">{{ $group === 'customer' ? 'Customer\'s places' : 'The business\'s locations' }}</p>
                        @foreach ($options as $key => $option)
                            <label wire:key="{{ $id }}-saved-{{ $key }}" @class(['flex cursor-pointer items-start gap-3 rounded-lg px-3 py-2 ring-1',
                                'bg-brand-500/10 ring-brand-500' => $loc['saved'] === $key, 'bg-surface-2 ring-line hover:ring-brand-400' => $loc['saved'] !== $key])>
                                <input type="radio" name="{{ $id }}-saved" value="{{ $key }}" wire:model.live="{{ $locPath }}.saved" class="mt-0.5 size-4 border-line-strong bg-surface-2 text-brand-500">
                                <span class="min-w-0 text-sm"><span class="font-medium text-ink">{{ $option['label'] }}</span>@if ($option['hint'])<span class="block truncate text-muted">{{ $option['hint'] }}</span>@endif</span>
                            </label>
                        @endforeach
                    </div>
                @endforeach
            </div>
        @endif
        @error($locPath.'.saved') <p class="mt-2 text-sm text-danger" role="alert">{{ $message }}</p> @enderror
    @else
        @if ($locOptional)
            <p class="mb-3 text-sm text-muted">Leave blank if there's no visit address.</p>
        @endif
        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label for="{{ $id }}-name" class="sh-label">Display name <span class="font-normal text-subtle">(optional)</span></label>
                <input id="{{ $id }}-name" type="text" wire:model="{{ $locPath }}.name" class="sh-input" maxlength="100" placeholder="e.g. Lake house, Main office" autocomplete="off">
                <p id="{{ $id }}-help" class="mt-1 text-xs text-subtle">Shown with the appointment.</p>
                @error($locPath.'.name') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="{{ $id }}-type" class="sh-label">Location type</label>
                <select id="{{ $id }}-type" wire:model="{{ $locPath }}.type" class="sh-input">
                    @foreach ($placeTypes as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                </select>
            </div>
            <div>
                <label for="{{ $id }}-country" class="sh-label">Country</label>
                <select id="{{ $id }}-country" wire:model.live="{{ $locPath }}.country" class="sh-input" autocomplete="off">
                    @foreach ($countries as $code => $name)<option value="{{ $code }}">{{ $name }}</option>@endforeach
                </select>
                @error($locPath.'.country') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
            </div>
            <div class="hidden sm:block"></div>
            <div>
                <label for="{{ $id }}-line1" class="sh-label">Address line 1</label>
                <input id="{{ $id }}-line1" type="text" wire:model="{{ $locPath }}.line1" class="sh-input" maxlength="255" placeholder="Street address" autocomplete="off">
                @error($locPath.'.line1') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="{{ $id }}-line2" class="sh-label">Address line 2 <span class="font-normal text-subtle">(optional)</span></label>
                <input id="{{ $id }}-line2" type="text" wire:model="{{ $locPath }}.line2" class="sh-input" maxlength="255" placeholder="Apt, suite, unit, gate code" autocomplete="off">
            </div>
            <div>
                <label for="{{ $id }}-city" class="sh-label">City</label>
                <input id="{{ $id }}-city" type="text" wire:model="{{ $locPath }}.city" class="sh-input" maxlength="100" autocomplete="off">
                @error($locPath.'.city') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
            </div>
            <div class="grid grid-cols-[minmax(0,2fr)_minmax(0,3fr)] gap-4">
                <div>
                    <label for="{{ $id }}-state" class="sh-label">{{ $regionLabel }}</label>
                    @if ($regions !== [])
                        <select id="{{ $id }}-state" wire:model="{{ $locPath }}.state" class="sh-input">
                            <option value="">Select…</option>
                            @foreach ($regions as $code => $name)<option value="{{ $code }}">{{ $name }}</option>@endforeach
                        </select>
                    @else
                        <input id="{{ $id }}-state" type="text" wire:model="{{ $locPath }}.state" class="sh-input" maxlength="100" autocomplete="off">
                    @endif
                    @error($locPath.'.state') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="{{ $id }}-postal" class="sh-label">{{ $postalLabel }}</label>
                    <input id="{{ $id }}-postal" type="text" wire:model="{{ $locPath }}.postal_code" class="sh-input" maxlength="20" autocomplete="off"
                        @if ($loc['country'] === 'US') inputmode="numeric" placeholder="78701" @endif>
                    @error($locPath.'.postal_code') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                </div>
            </div>
        </div>
        <label class="mt-4 flex items-center gap-2 text-sm text-ink">
            <input type="checkbox" wire:model="{{ $locPath }}.save" class="size-4 rounded border-line-strong bg-surface-2 text-brand-500"> Save this location for future use
        </label>
        @error($locPath.'.save') <p class="mt-1 text-sm text-danger" role="alert">{{ $message }}</p> @enderror
    @endif

    @error($locPath) <p class="mt-3 text-sm font-medium text-danger" role="alert">{{ $message }}</p> @enderror
</fieldset>
