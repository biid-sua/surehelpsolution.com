{{-- Customer edit dialog, shared by the business portal and the agent workspace. Needs $statuses and $contactMethods; the component has $editing, $form and save(). --}}
<div x-data="{ open: $wire.entangle('editing') }" x-show="open" x-cloak x-on:keydown.escape.window="open = false"
    class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto p-4 sm:items-center" role="dialog" aria-modal="true" aria-labelledby="edit-cust-title">
    <div class="fixed inset-0 bg-black/60" x-on:click="open = false"></div>
    <form wire:submit="save" x-trap.noscroll="open" class="relative w-full max-w-2xl rounded-2xl border border-line-strong bg-surface p-6 shadow-2xl">
        <h2 id="edit-cust-title" class="text-lg font-semibold text-ink">Edit customer</h2>
        <div class="mt-5 grid gap-4 sm:grid-cols-6">
            <div class="sm:col-span-3"><label class="sh-label" for="e-first">First name</label><input id="e-first" type="text" wire:model="form.first_name" class="sh-input"></div>
            <div class="sm:col-span-3"><label class="sh-label" for="e-last">Last name</label><input id="e-last" type="text" wire:model="form.last_name" class="sh-input"></div>
            <div class="sm:col-span-6"><label class="sh-label" for="e-company">Company</label><input id="e-company" type="text" wire:model="form.company" class="sh-input"></div>
            <div class="sm:col-span-3">
                <label class="sh-label" for="e-phone">Phone</label><input id="e-phone" type="tel" wire:model="form.phone" class="sh-input">
                @error('form.phone') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
            </div>
            <div class="sm:col-span-3">
                <label class="sh-label" for="e-email">Email</label><input id="e-email" type="email" wire:model="form.email" class="sh-input">
                @error('form.email') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
            </div>
            <div class="sm:col-span-4"><label class="sh-label" for="e-a1">Street address</label><input id="e-a1" type="text" wire:model="form.address_line1" class="sh-input"></div>
            <div class="sm:col-span-2"><label class="sh-label" for="e-a2">Unit</label><input id="e-a2" type="text" wire:model="form.address_line2" class="sh-input"></div>
            <div class="sm:col-span-3"><label class="sh-label" for="e-city">City</label><input id="e-city" type="text" wire:model="form.city" class="sh-input"></div>
            <div class="sm:col-span-1"><label class="sh-label" for="e-state">State</label><input id="e-state" type="text" wire:model="form.state" class="sh-input"></div>
            <div class="sm:col-span-2"><label class="sh-label" for="e-zip">ZIP</label><input id="e-zip" type="text" wire:model="form.postal_code" class="sh-input"></div>
            <div class="sm:col-span-3">
                <label class="sh-label" for="e-status">Status</label>
                <select id="e-status" wire:model="form.status" class="sh-input">
                    @foreach ($statuses as $option)<option value="{{ $option->value }}">{{ $option->label() }}</option>@endforeach
                </select>
            </div>
            <div class="sm:col-span-3">
                <label class="sh-label" for="e-pref">Preferred contact</label>
                <select id="e-pref" wire:model="form.preferred_contact" class="sh-input">
                    <option value="">No preference</option>
                    @foreach ($contactMethods as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
                </select>
            </div>
            <fieldset class="sm:col-span-6">
                <legend class="sh-label">Consent (only tick if the customer agreed)</legend>
                <div class="flex flex-wrap gap-x-6 gap-y-2 text-sm text-ink">
                    <label class="flex items-center gap-2"><input type="checkbox" wire:model="form.sms_consent" class="rounded border-line-strong bg-surface-2 text-brand-500"> OK to text</label>
                    <label class="flex items-center gap-2"><input type="checkbox" wire:model="form.email_consent" class="rounded border-line-strong bg-surface-2 text-brand-500"> OK to email</label>
                </div>
            </fieldset>
            <div class="sm:col-span-6"><label class="sh-label" for="e-notes">About this customer</label><textarea id="e-notes" wire:model="form.notes" rows="3" class="sh-input"></textarea></div>
        </div>
        <div class="mt-6 flex justify-end gap-2">
            <x-ui.button variant="secondary" x-on:click="open = false">Cancel</x-ui.button>
            <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="save">Save</x-ui.button>
        </div>
    </form>
</div>
