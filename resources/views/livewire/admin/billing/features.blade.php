<x-ui.card :padding="false" title="Feature access" description="Open to everyone, only to businesses whose plan or add-on includes the feature key, or only to businesses you pick (the rest don't see it at all). Put the key in a plan's feature keys, or give an add-on the same key. You can also switch a feature on or off for one business on its page.">
    <form wire:submit="save">
        <x-ui.table>
            <thead>
                <tr><th scope="col">Feature</th><th scope="col">Key</th><th scope="col">Who gets it</th><th scope="col">Included in</th></tr>
            </thead>
            <tbody class="divide-y divide-line">
                @foreach ($catalog as $key => $feature)
                    <tr wire:key="feature-{{ $key }}">
                        <td>
                            <p class="font-medium text-ink">{{ $feature['label'] }}</p>
                            <p class="text-xs text-subtle">{{ $feature['description'] }}</p>
                        </td>
                        <td><code class="text-xs text-muted">{{ $key }}</code></td>
                        <td>
                            <label for="mode-{{ $key }}" class="sr-only">Who gets {{ $feature['label'] }}</label>
                            <select id="mode-{{ $key }}" wire:model.live="modes.{{ $key }}" class="sh-input py-1.5 text-sm" @disabled(! $canManage)>
                                <option value="everyone">Every business</option>
                                <option value="plan">Only plans / add-ons that include it</option>
                                <option value="selected">Only businesses I pick (hidden from the rest)</option>
                            </select>
                            @if (($modes[$key] ?? 'everyone') === 'selected' || $picked[$key] !== [])
                                <div class="mt-2 flex flex-wrap items-center gap-2 text-xs text-muted">
                                    <span>{{ count($picked[$key]) === 0 ? 'No businesses picked yet' : count($picked[$key]).' picked: '.\Illuminate\Support\Str::limit(implode(', ', $picked[$key]), 60) }}</span>
                                    <x-ui.button type="button" size="sm" variant="secondary" wire:click="pickFor('{{ $key }}')">Choose businesses</x-ui.button>
                                </div>
                            @endif
                        </td>
                        <td class="text-sm text-muted">
                            @if ($includedBy[$key])
                                {{ implode(', ', $includedBy[$key]) }}
                            @else
                                <span @class(['text-amber-300' => ($modes[$key] ?? 'everyone') === 'plan'])>No plan or add-on yet</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </x-ui.table>
        @if ($canManage)
            <div class="flex items-center justify-between gap-3 border-t border-line px-5 py-4">
                <p class="text-xs text-subtle">Switching a feature to "only plans" or "only businesses I pick" takes it away from the others at once.</p>
                <x-ui.button type="submit" size="sm">Save</x-ui.button>
            </div>
        @endif
    </form>

    @if ($picking)
        <div class="border-t border-line p-5" wire:key="picker-{{ $picking }}">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h3 class="text-sm font-semibold text-ink">Businesses with {{ $catalog[$picking]['label'] }}</h3>
                    <p class="text-xs text-subtle">Ticked businesses have it whatever their plan. Save "Only businesses I pick" above to hide it from everyone else.</p>
                </div>
                <x-ui.button type="button" size="sm" variant="ghost" wire:click="pickFor(null)">Done</x-ui.button>
            </div>
            <label for="pick-search" class="sr-only">Find a business</label>
            <input id="pick-search" type="search" wire:model.live.debounce.300ms="search" class="sh-input mt-3 max-w-sm" placeholder="Find a business…" autocomplete="off">
            <ul class="mt-3 divide-y divide-line rounded-lg border border-line" role="list">
                @forelse ($candidates as $business)
                    <li wire:key="pick-{{ $picking }}-{{ $business->id }}" class="flex items-center gap-3 px-3 py-2">
                        <input id="pick-{{ $business->id }}" type="checkbox" class="size-4 rounded border-line-strong bg-surface-2 text-brand-500"
                            @checked(in_array($business->id, $pickedIds, true)) @disabled(! $canManage) wire:click="togglePick({{ $business->id }})">
                        <label for="pick-{{ $business->id }}" class="flex-1 truncate text-sm text-ink">{{ $business->name }}</label>
                        <span class="text-xs text-subtle">{{ $business->status->label() }}</span>
                    </li>
                @empty
                    <li class="px-3 py-3 text-sm text-muted">No businesses match.</li>
                @endforelse
            </ul>
        </div>
    @endif
</x-ui.card>
