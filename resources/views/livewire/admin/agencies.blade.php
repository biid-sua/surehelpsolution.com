<div>
    <x-ui.page-header title="Agencies" description="Marketing agencies that resell SureHelp: their brand, their share, their people and the businesses they look after.">
        <x-slot:actions>
            <x-ui.button type="button" wire:click="edit" icon="sparkles">New agency</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="grid gap-6 lg:grid-cols-5">
        <x-ui.card :padding="false" class="lg:col-span-2">
            <ul class="divide-y divide-line text-sm" role="list">
                @forelse ($agencies as $agency)
                    <li wire:key="ag-{{ $agency->ulid }}">
                        <button type="button" wire:click="edit('{{ $agency->ulid }}')" @class(['flex w-full items-center justify-between gap-3 px-5 py-3 text-left hover:bg-surface-2', 'bg-surface-2' => $open === $agency->ulid])>
                            <span class="font-medium text-ink">{{ $agency->name }}</span>
                            <span class="text-xs text-subtle">{{ $agency->organizations_count }} {{ \Illuminate\Support\Str::plural('business', $agency->organizations_count) }} · {{ $agency->members_count }} {{ \Illuminate\Support\Str::plural('person', $agency->members_count) }}</span>
                        </button>
                    </li>
                @empty
                    <li class="px-5 py-6 text-subtle">No agencies yet.</li>
                @endforelse
            </ul>
        </x-ui.card>

        <div class="space-y-6 lg:col-span-3">
            <x-ui.card :title="$current ? $current->name : 'New agency'">
                <form wire:submit="save" class="grid gap-4 text-sm sm:grid-cols-2">
                    <div class="sm:col-span-2">
                        <label for="ag-name" class="sh-label">Name</label>
                        <input id="ag-name" type="text" wire:model="form.name" maxlength="120" class="sh-input">
                        @error('form.name')<p class="mt-1 text-danger">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label for="ag-color" class="sh-label">Brand colour</label>
                        <input id="ag-color" type="text" wire:model="form.brand_color" maxlength="7" class="sh-input font-mono" placeholder="#2563eb">
                        @error('form.brand_color')<p class="mt-1 text-danger">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label for="ag-share" class="sh-label">Their share of what clients pay (%)</label>
                        <input id="ag-share" type="text" inputmode="decimal" wire:model="form.commission_percent" class="sh-input">
                        @error('form.commission_percent')<p class="mt-1 text-danger">{{ $message }}</p>@enderror
                    </div>
                    <div class="sm:col-span-2">
                        <label for="ag-logo" class="sh-label">Logo address (https)</label>
                        <input id="ag-logo" type="url" wire:model="form.logo_url" maxlength="500" class="sh-input">
                        @error('form.logo_url')<p class="mt-1 text-danger">{{ $message }}</p>@enderror
                    </div>
                    <div class="sm:col-span-2"><x-ui.button type="submit">Save agency</x-ui.button></div>
                </form>
            </x-ui.card>

            @if ($current)
                <x-ui.card title="People" description="They become managers of every business the agency looks after, and see the agency dashboard.">
                    <ul class="mb-4 divide-y divide-line text-sm" role="list">
                        @forelse ($current->members as $member)
                            <li class="flex flex-wrap items-center justify-between gap-2 py-2" wire:key="m-{{ $member->id }}">
                                <span class="text-ink">{{ $member->name }} <span class="text-xs text-subtle">{{ $member->email }} · {{ $roles[$member->pivot->role] ?? $member->pivot->role }}</span></span>
                                <button type="button" wire:click="removeMember({{ $member->id }})" wire:confirm="Remove {{ $member->name }} from the agency? They lose access to its businesses." class="text-muted hover:text-danger">Remove</button>
                            </li>
                        @empty
                            <li class="py-2 text-subtle">Nobody yet.</li>
                        @endforelse
                    </ul>
                    <form wire:submit="addMember" class="flex flex-wrap items-start gap-2 text-sm">
                        <div class="min-w-[14rem] flex-1">
                            <input type="email" wire:model="memberEmail" placeholder="Their SureHelp sign-in email" aria-label="Email" class="sh-input">
                            @error('memberEmail')<p class="mt-1 text-danger">{{ $message }}</p>@enderror
                        </div>
                        <select wire:model="memberRole" aria-label="Role" class="sh-input w-auto">
                            @foreach ($roles as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
                        </select>
                        <x-ui.button type="submit" variant="secondary">Add</x-ui.button>
                    </form>
                </x-ui.card>

                <x-ui.card title="Businesses" description="They see the agency's brand instead of SureHelp's.">
                    <ul class="mb-4 divide-y divide-line text-sm" role="list">
                        @forelse ($current->organizations as $organization)
                            <li class="flex flex-wrap items-center justify-between gap-2 py-2" wire:key="o-{{ $organization->ulid }}">
                                <span class="text-ink">{{ $organization->name }}</span>
                                <button type="button" wire:click="removeBusiness('{{ $organization->ulid }}')" wire:confirm="Stop {{ $current->name }} looking after {{ $organization->name }}?" class="text-muted hover:text-danger">Remove</button>
                            </li>
                        @empty
                            <li class="py-2 text-subtle">None yet.</li>
                        @endforelse
                    </ul>
                    <form wire:submit="addBusiness" class="flex flex-wrap items-start gap-2 text-sm">
                        <div class="min-w-[14rem] flex-1">
                            <input type="text" wire:model="business" placeholder="Business name or ID" aria-label="Business" class="sh-input">
                            @error('business')<p class="mt-1 text-danger">{{ $message }}</p>@enderror
                        </div>
                        <x-ui.button type="submit" variant="secondary">Add</x-ui.button>
                    </form>
                </x-ui.card>
            @endif
        </div>
    </div>
</div>
