<div>
    <x-ui.page-header title="Business" description="The services you offer, how long they take, and what our agents may say about price.">
        <x-slot:actions>
            @if ($canManage)
                <x-ui.button icon="wrench" wire:click="create">Add service</x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>
    @include('livewire.client.business._tabs')

    <x-ui.card :padding="false">
        @if ($services->isEmpty())
            <x-ui.empty-state icon="wrench" title="No services yet"
                description="Add what you sell, like &quot;Water heater repair · 90 min · from $150&quot;, so our agents can quote and book it correctly.">
                @if ($canManage)
                    <x-ui.button size="sm" wire:click="create">Add your first service</x-ui.button>
                @endif
            </x-ui.empty-state>
        @else
            <x-ui.table>
                <thead>
                    <tr>
                        <th scope="col">Service</th>
                        <th scope="col">Duration</th>
                        <th scope="col">Price</th>
                        <th scope="col">Status</th>
                        @if ($canManage)<th scope="col" class="text-right"><span class="sr-only">Actions</span></th>@endif
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    @foreach ($services as $service)
                        <tr wire:key="service-{{ $service->id }}">
                            <td>
                                <p class="font-medium text-ink">{{ $service->name }}</p>
                                <p class="text-xs text-subtle">
                                    {{ $service->category ?? 'Uncategorized' }}
                                    @if (! $service->is_bookable) · not bookable by agents @endif
                                </p>
                            </td>
                            <td class="whitespace-nowrap text-muted">
                                {{ $service->durationLabel() }}
                                @if ($service->buffer_minutes) <span class="text-subtle">+ {{ $service->buffer_minutes }} min buffer</span> @endif
                            </td>
                            <td class="whitespace-nowrap text-ink">{{ $service->priceLabel() }}</td>
                            <td>
                                @if ($canManage)
                                    <button type="button" wire:click="toggleActive({{ $service->id }})" class="rounded-full"
                                        aria-label="{{ $service->is_active ? 'Deactivate' : 'Activate' }} {{ $service->name }}">
                                        <x-ui.badge :tone="$service->is_active ? 'success' : 'neutral'">{{ $service->is_active ? 'Active' : 'Inactive' }}</x-ui.badge>
                                    </button>
                                @else
                                    <x-ui.badge :tone="$service->is_active ? 'success' : 'neutral'">{{ $service->is_active ? 'Active' : 'Inactive' }}</x-ui.badge>
                                @endif
                            </td>
                            @if ($canManage)
                                <td class="whitespace-nowrap text-right">
                                    <x-ui.button variant="ghost" size="sm" wire:click="edit({{ $service->id }})">Edit</x-ui.button>
                                    <x-ui.confirm id="del-svc-{{ $service->id }}" title="Remove {{ $service->name }}?" confirm-label="Remove" action="delete({{ $service->id }})">
                                        <x-slot:trigger><x-ui.button variant="ghost" size="sm">Remove</x-ui.button></x-slot:trigger>
                                        Agents will stop offering it. Past calls keep their history.
                                    </x-ui.confirm>
                                </td>
                            @endif
                        </tr>
                    @endforeach
                </tbody>
            </x-ui.table>
        @endif
    </x-ui.card>

    {{-- Create / edit dialog --}}
    @if ($canManage)
        <div x-data="{ open: $wire.entangle('editing') }" x-show="open" x-cloak x-on:keydown.escape.window="$wire.cancel()"
            class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto p-4 sm:items-center" role="dialog" aria-modal="true" aria-labelledby="svc-title">
            <div class="fixed inset-0 bg-black/60" wire:click="cancel"></div>
            <form wire:submit="save" x-trap.noscroll="open"
                class="relative w-full max-w-2xl rounded-2xl border border-line-strong bg-surface p-6 shadow-2xl">
                <h2 id="svc-title" class="text-lg font-semibold text-ink">{{ $serviceId ? 'Edit service' : 'Add a service' }}</h2>

                <div class="mt-5 grid gap-4 sm:grid-cols-2">
                    <div class="sm:col-span-2">
                        <label for="s-name" class="sh-label">Service name <span class="text-danger">*</span></label>
                        <input id="s-name" type="text" wire:model="form.name" class="sh-input" placeholder="e.g. Water heater repair" required>
                        @error('form.name') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="s-cat" class="sh-label">Category</label>
                        <input id="s-cat" type="text" wire:model="form.category" class="sh-input" list="svc-categories" placeholder="e.g. Repairs">
                        <datalist id="svc-categories">@foreach ($categories as $category)<option value="{{ $category }}">@endforeach</datalist>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label for="s-dur" class="sh-label">Duration (min) <span class="text-danger">*</span></label>
                            <input id="s-dur" type="number" min="5" max="1440" step="5" wire:model="form.duration_minutes" class="sh-input">
                            @error('form.duration_minutes') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="s-buf" class="sh-label">Buffer (min)</label>
                            <input id="s-buf" type="number" min="0" max="480" step="5" wire:model="form.buffer_minutes" class="sh-input">
                            @error('form.buffer_minutes') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                        </div>
                    </div>
                    <div>
                        <label for="s-ptype" class="sh-label">Price</label>
                        <select id="s-ptype" wire:model.live="form.price_type" class="sh-input">
                            @foreach ($priceTypes as $type)
                                <option value="{{ $type->value }}">{{ $type->label() }}</option>
                            @endforeach
                        </select>
                    </div>
                    @if (\App\Enums\ServicePriceType::tryFrom($form['price_type'] ?? '')?->needsAmount())
                        <div>
                            <label for="s-price" class="sh-label">Amount ({{ $currency }}) <span class="text-danger">*</span></label>
                            <input id="s-price" type="text" inputmode="decimal" wire:model="form.price" class="sh-input" placeholder="150">
                            @error('form.price') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                        </div>
                    @endif
                    <div class="sm:col-span-2">
                        <label for="s-desc" class="sh-label">Description for callers</label>
                        <textarea id="s-desc" wire:model="form.description" rows="2" class="sh-input"></textarea>
                    </div>
                    <div class="sm:col-span-2">
                        <label for="s-instr" class="sh-label">Instructions for our agents</label>
                        <textarea id="s-instr" wire:model="form.agent_instructions" rows="2" class="sh-input"
                            placeholder="e.g. Ask the tank size and whether it's leaking. Never promise same-day."></textarea>
                    </div>
                    <fieldset class="sm:col-span-2">
                        <legend class="sh-label">Information agents must collect</legend>
                        <div class="flex flex-wrap gap-x-5 gap-y-2">
                            @foreach ($requiredFieldOptions as $key => $label)
                                <label class="flex items-center gap-2 text-sm text-ink">
                                    <input type="checkbox" value="{{ $key }}" wire:model="form.required_fields" class="rounded border-line-strong bg-surface-2 text-brand-500"> {{ $label }}
                                </label>
                            @endforeach
                        </div>
                    </fieldset>
                    <div class="sm:col-span-2 flex flex-wrap gap-x-6 gap-y-2">
                        <label class="flex items-center gap-2 text-sm text-ink">
                            <input type="checkbox" wire:model="form.is_active" class="rounded border-line-strong bg-surface-2 text-brand-500"> Active
                        </label>
                        <label class="flex items-center gap-2 text-sm text-ink">
                            <input type="checkbox" wire:model="form.is_bookable" class="rounded border-line-strong bg-surface-2 text-brand-500"> Agents can book it
                        </label>
                    </div>
                </div>

                <div class="mt-6 flex justify-end gap-2">
                    <x-ui.button variant="secondary" wire:click="cancel">Cancel</x-ui.button>
                    <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="save">Save service</x-ui.button>
                </div>
            </form>
        </div>
    @endif
</div>
