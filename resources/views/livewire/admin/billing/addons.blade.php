<div class="grid gap-6 lg:grid-cols-5">
    <x-ui.card :padding="false" class="lg:col-span-3" title="Add-ons" description="Extras businesses turn on from their Billing page, billed monthly with their plan.">
        @if ($addons->isEmpty())
            <x-ui.empty-state icon="card" title="No add-ons yet" description="Create one, e.g. Bilingual answering at $49 a month." />
        @else
            <ul class="divide-y divide-line">
                @foreach ($addons as $addon)
                    <li class="flex items-center justify-between gap-3 px-5 py-3" wire:key="addon-{{ $addon->id }}">
                        <span>
                            <span class="font-medium text-ink">{{ $addon->name }}</span> <span class="text-muted">{{ $addon->priceLabel() }}</span>
                            <span class="block text-xs text-subtle"><code>{{ $addon->slug }}</code> · {{ $counts[$addon->id] ?? 0 }} {{ \Illuminate\Support\Str::plural('business', (int) ($counts[$addon->id] ?? 0)) }}{{ $addon->is_active ? '' : ' · retired' }}</span>
                        </span>
                        @if ($canManage)<x-ui.button size="sm" variant="ghost" wire:click="edit({{ $addon->id }})">Edit</x-ui.button>@endif
                    </li>
                @endforeach
            </ul>
        @endif
    </x-ui.card>
    @if ($canManage)
        <x-ui.card class="lg:col-span-2" :title="$editing ? 'Edit add-on' : 'New add-on'">
            <form wire:submit="save" class="grid gap-3 sm:grid-cols-2">
                <div class="sm:col-span-2"><label for="ad-name" class="sh-label">Name</label><input id="ad-name" type="text" wire:model="form.name" class="sh-input">@error('form.name') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror</div>
                <div><label for="ad-price" class="sh-label">Price per month ($)</label><input id="ad-price" type="text" inputmode="decimal" wire:model="form.price" class="sh-input">@error('form.price') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror</div>
                <div>
                    <label for="ad-slug" class="sh-label">Feature key</label>
                    <input id="ad-slug" type="text" wire:model="form.slug" class="sh-input" placeholder="from the name" @disabled($editing)>
                    @error('form.slug') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                </div>
                <div class="sm:col-span-2"><label for="ad-desc" class="sh-label">What it does</label><textarea id="ad-desc" wire:model="form.description" rows="3" class="sh-input"></textarea></div>
                <label class="flex items-center gap-2 text-sm text-ink sm:col-span-2"><input type="checkbox" wire:model="form.is_active" class="size-4 rounded border-line-strong bg-surface-2 text-brand-500"> Offered to businesses</label>
                <div class="flex justify-end gap-2 sm:col-span-2">
                    @if ($editing)<x-ui.button variant="ghost" size="sm" wire:click="cancel">Cancel</x-ui.button>@endif
                    <x-ui.button type="submit" size="sm">Save add-on</x-ui.button>
                </div>
            </form>
        </x-ui.card>
    @endif
</div>
