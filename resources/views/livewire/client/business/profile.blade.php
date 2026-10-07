<div>
    <x-ui.page-header title="Business" description="What our agents tell your callers. Keep it current, and every call goes better." />
    @include('livewire.client.business._tabs')

    @unless ($canEdit)
        <x-ui.alert class="mb-6" tone="info">Only the business owner can change these details.</x-ui.alert>
    @endunless

    <x-ui.card class="mb-6" title="Logo" description="Shown in your portal and on pages your customers open, such as changing an appointment.">
        <div class="flex flex-wrap items-center gap-4">
            <div class="grid size-20 place-items-center overflow-hidden rounded-xl bg-surface-2 ring-1 ring-line">
                @if ($logoUrl)<img src="{{ $logoUrl }}" alt="Your logo" class="max-h-full max-w-full object-contain">@else<x-ui.icon name="photo" class="size-6 text-subtle" />@endif
            </div>
            @if ($canEdit)
                <div>
                    <input type="file" wire:model="logo" accept="image/png,image/jpeg,image/webp" aria-label="Upload a logo" class="block text-sm text-muted file:mr-3 file:rounded-lg file:border-0 file:bg-surface-2 file:px-3 file:py-1.5 file:text-ink">
                    <p class="mt-1 text-xs text-subtle">PNG, JPG or WebP, up to 2 MB. A square or wide logo on a transparent background works best.</p>
                    <span wire:loading wire:target="logo" class="text-xs text-muted">Uploading…</span>
                    @error('logo') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                </div>
                @if ($logoUrl)<x-ui.button size="sm" variant="ghost" wire:click="removeLogo">Remove</x-ui.button>@endif
            @endif
        </div>
    </x-ui.card>

    <form wire:submit="save" class="space-y-6">
        <fieldset @disabled(! $canEdit) class="space-y-6">
            <x-ui.card title="About the business">
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label for="f-name" class="sh-label">Business name <span class="text-danger">*</span></label>
                        <input id="f-name" type="text" wire:model="form.name" class="sh-input" required maxlength="255">
                        @error('form.name') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="f-legal" class="sh-label">Legal name</label>
                        <input id="f-legal" type="text" wire:model="form.legal_name" class="sh-input" placeholder="e.g. Rapid Plumbing LLC">
                    </div>
                    <div>
                        <label for="f-type" class="sh-label">What kind of business?</label>
                        <input id="f-type" type="text" wire:model="form.business_type" class="sh-input" placeholder="e.g. Plumbing, Dental clinic">
                    </div>
                    <div>
                        <label for="f-industry" class="sh-label">Industry</label>
                        <input id="f-industry" type="text" wire:model="form.industry" class="sh-input" placeholder="e.g. Home services">
                    </div>
                    <div class="sm:col-span-2">
                        <label for="f-desc" class="sh-label">Short description</label>
                        <textarea id="f-desc" wire:model="form.description" rows="3" class="sh-input" maxlength="2000"
                            placeholder="How should our agents describe you in one or two sentences?"></textarea>
                        @error('form.description') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                    </div>
                </div>
            </x-ui.card>

            <x-ui.card title="Contact">
                <div class="grid gap-4 sm:grid-cols-3">
                    <div>
                        <label for="f-phone" class="sh-label">Main phone</label>
                        <input id="f-phone" type="tel" wire:model="form.phone" class="sh-input" autocomplete="tel">
                    </div>
                    <div>
                        <label for="f-email" class="sh-label">Email</label>
                        <input id="f-email" type="email" wire:model="form.email" class="sh-input" autocomplete="email">
                        @error('form.email') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="f-web" class="sh-label">Website</label>
                        <input id="f-web" type="url" wire:model="form.website" class="sh-input" placeholder="https://">
                        @error('form.website') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                    </div>
                </div>
            </x-ui.card>

            <x-ui.card title="Location & service area">
                <div class="grid gap-4 sm:grid-cols-6">
                    <div class="sm:col-span-4">
                        <label for="f-a1" class="sh-label">Street address</label>
                        <input id="f-a1" type="text" wire:model="form.address_line1" class="sh-input" autocomplete="address-line1">
                    </div>
                    <div class="sm:col-span-2">
                        <label for="f-a2" class="sh-label">Suite / unit</label>
                        <input id="f-a2" type="text" wire:model="form.address_line2" class="sh-input" autocomplete="address-line2">
                    </div>
                    <div class="sm:col-span-3">
                        <label for="f-city" class="sh-label">City</label>
                        <input id="f-city" type="text" wire:model="form.city" class="sh-input" autocomplete="address-level2">
                    </div>
                    <div class="sm:col-span-1">
                        <label for="f-state" class="sh-label">State</label>
                        <input id="f-state" type="text" wire:model="form.state" class="sh-input" autocomplete="address-level1">
                    </div>
                    <div class="sm:col-span-2">
                        <label for="f-zip" class="sh-label">ZIP</label>
                        <input id="f-zip" type="text" wire:model="form.postal_code" class="sh-input" autocomplete="postal-code">
                    </div>
                    <div class="sm:col-span-6">
                        <label for="f-area" class="sh-label">Service area</label>
                        <textarea id="f-area" wire:model="form.service_area" rows="2" class="sh-input"
                            placeholder="Cities or ZIP codes you serve, e.g. Austin, Round Rock, Cedar Park (78701–78759)"></textarea>
                    </div>
                    <div class="sm:col-span-3">
                        <label for="f-tz" class="sh-label">Timezone <span class="text-danger">*</span></label>
                        <select id="f-tz" wire:model="form.timezone" class="sh-input" required>
                            <option value="">Choose your timezone…</option>
                            <optgroup label="United States">
                                @foreach ($timezones as $zone => $label)
                                    <option value="{{ $zone }}">{{ $label }}</option>
                                @endforeach
                            </optgroup>
                            <optgroup label="All timezones">
                                @foreach ($otherTimezones as $zone)
                                    <option value="{{ $zone }}">{{ $zone }}</option>
                                @endforeach
                            </optgroup>
                        </select>
                        <p class="mt-1 text-xs text-subtle">Your hours, dashboard and reports use this timezone.</p>
                        @error('form.timezone') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                    </div>
                </div>
            </x-ui.card>
        </fieldset>

        @if ($canEdit)
            <div class="flex justify-end">
                <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="save">
                    <span wire:loading.remove wire:target="save">Save profile</span>
                    <span wire:loading wire:target="save">Saving…</span>
                </x-ui.button>
            </div>
        @endif
    </form>
</div>
