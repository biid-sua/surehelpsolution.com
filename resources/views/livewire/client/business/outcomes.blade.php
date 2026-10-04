<div>
    <x-ui.page-header title="Business" description="The outcomes our agents choose at the end of each call. They drive your dashboard, follow-ups and alerts." />
    @include('livewire.client.business._tabs')

    <x-ui.card :padding="false">
        <x-ui.table>
            <thead>
                <tr>
                    <th scope="col">Outcome</th>
                    <th scope="col">What it means</th>
                    <th scope="col">Status</th>
                    @if ($canManage)<th scope="col" class="text-right"><span class="sr-only">Actions</span></th>@endif
                </tr>
            </thead>
            <tbody class="divide-y divide-line">
                @foreach ($outcomes as $outcome)
                    <tr wire:key="o-{{ $outcome['key'] }}">
                        <td>
                            @if ($renaming === $outcome['key'])
                                <form wire:submit="saveRename" class="flex items-center gap-2">
                                    <label for="rename-{{ $outcome['key'] }}" class="sr-only">New name</label>
                                    <input id="rename-{{ $outcome['key'] }}" type="text" wire:model="renameLabel" class="sh-input py-1.5" maxlength="100" autofocus>
                                    <x-ui.button type="submit" size="sm">Save</x-ui.button>
                                    <x-ui.button variant="ghost" size="sm" wire:click="$set('renaming', null)">Cancel</x-ui.button>
                                </form>
                                @error('renameLabel') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                            @else
                                <p class="font-medium text-ink">{{ $outcome['label'] }}</p>
                                <p class="text-xs text-subtle">{{ $outcome['custom'] ? 'Your outcome' : 'Standard outcome' }}{{ $outcome['overridden'] ? ' · customized' : '' }}</p>
                            @endif
                        </td>
                        <td class="text-muted">{{ $outcome['category']->label() }}</td>
                        <td>
                            @if ($canManage)
                                <button type="button" wire:click="toggle('{{ $outcome['key'] }}')" aria-label="{{ $outcome['is_active'] ? 'Switch off' : 'Switch on' }} {{ $outcome['label'] }}">
                                    <x-ui.badge :tone="$outcome['is_active'] ? 'success' : 'neutral'">{{ $outcome['is_active'] ? 'On' : 'Off' }}</x-ui.badge>
                                </button>
                            @else
                                <x-ui.badge :tone="$outcome['is_active'] ? 'success' : 'neutral'">{{ $outcome['is_active'] ? 'On' : 'Off' }}</x-ui.badge>
                            @endif
                        </td>
                        @if ($canManage)
                            <td class="whitespace-nowrap text-right">
                                <x-ui.button variant="ghost" size="sm" wire:click="startRename('{{ $outcome['key'] }}')">Rename</x-ui.button>
                                @if ($outcome['custom'])
                                    <x-ui.button variant="ghost" size="sm" wire:click="delete('{{ $outcome['key'] }}')">Delete</x-ui.button>
                                @endif
                            </td>
                        @endif
                    </tr>
                @endforeach
            </tbody>
        </x-ui.table>

        @if ($canManage)
            <form wire:submit="add" class="grid gap-3 border-t border-line px-5 py-4 sm:grid-cols-[1fr_16rem_auto] sm:items-end">
                <div>
                    <label for="new-outcome" class="sh-label">Add your own outcome</label>
                    <input id="new-outcome" type="text" wire:model="newLabel" class="sh-input" placeholder="e.g. Sent price list" maxlength="100">
                    @error('newLabel') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="new-outcome-cat" class="sh-label">What it means</label>
                    <select id="new-outcome-cat" wire:model="newCategory" class="sh-input">
                        @foreach ($categories as $category)
                            <option value="{{ $category->value }}">{{ $category->label() }}</option>
                        @endforeach
                    </select>
                </div>
                <x-ui.button type="submit" variant="secondary">Add outcome</x-ui.button>
            </form>
        @endif
    </x-ui.card>
</div>
