<x-ui.card :padding="false" title="Feature access" description="Switch each feature on or off for every business. A feature that's off is hidden everywhere (menu, pages, the app and upgrade offers) until you switch it on, and then businesses that get it see it marked New. While it's on, choose who gets it: every business, only plans and add-ons that include its key, or only businesses you pick (hidden from the rest). You can also switch a feature on or off for one business on its page.">
    <form wire:submit="save">
        <div class="flex flex-wrap items-center gap-3 border-b border-line px-5 py-3">
            <p class="text-sm text-muted"><span class="font-semibold text-ink">{{ $counts['on'] }}</span> on · <span class="font-semibold text-ink">{{ $counts['off'] }}</span> off</p>
            <div class="inline-flex rounded-lg border border-line bg-surface p-1" role="group" aria-label="Show features">
                @foreach (['all' => 'All', 'on' => 'On', 'off' => 'Off'] as $value => $label)
                    <button type="button" wire:click="$set('show', '{{ $value }}')" aria-pressed="{{ $show === $value ? 'true' : 'false' }}"
                        @class(['rounded-md px-3 py-1 text-xs font-medium', 'bg-brand-600 text-white' => $show === $value, 'text-muted hover:text-ink' => $show !== $value])>{{ $label }}</button>
                @endforeach
            </div>
            <label for="feature-find" class="sr-only">Find a feature</label>
            <input id="feature-find" type="search" wire:model.live.debounce.300ms="find" class="sh-input max-w-xs py-1.5 text-sm" placeholder="Find a feature…" autocomplete="off">
            @if ($canManage)
                <div class="ml-auto flex gap-2">
                    <x-ui.button type="button" size="sm" variant="ghost" wire:click="setAll(true)">All on</x-ui.button>
                    <x-ui.button type="button" size="sm" variant="ghost" wire:click="setAll(false)">All off</x-ui.button>
                </div>
            @endif
        </div>

        <x-ui.table>
            <thead>
                <tr><th scope="col">Feature</th><th scope="col">On</th><th scope="col">Who gets it</th><th scope="col">Included in</th></tr>
            </thead>
            @forelse ($groups as $group => $features)
                <tbody class="divide-y divide-line" wire:key="group-{{ \Illuminate\Support\Str::slug($group) }}">
                    <tr><th colspan="4" scope="colgroup" class="bg-surface-2 px-5 py-2 text-left text-xs font-semibold uppercase tracking-wide text-subtle">{{ $group }}</th></tr>
                    @foreach ($features as $key => $feature)
                        @php($on = (bool) ($active[$key] ?? true))
                        <tr wire:key="feature-{{ $key }}" @class(['opacity-60' => ! $on])>
                            <td>
                                <p class="flex flex-wrap items-center gap-2 font-medium text-ink">
                                    {{ $feature['label'] }}
                                    @if ($isNew[$key])
                                        <x-ui.badge tone="brand">New since {{ \Illuminate\Support\Carbon::parse($launches[$key])->format('M j') }}</x-ui.badge>
                                    @endif
                                    @if ($on !== ! $wasOff[$key])
                                        <x-ui.badge tone="warning">Not saved</x-ui.badge>
                                    @endif
                                </p>
                                <p class="text-xs text-subtle">{{ $feature['description'] }}</p>
                                <p class="mt-0.5"><code class="text-[11px] text-subtle">{{ $key }}</code></p>
                            </td>
                            <td>
                                <button type="button" role="switch" wire:click="$toggle('active.{{ $key }}')" @disabled(! $canManage)
                                    aria-checked="{{ $on ? 'true' : 'false' }}" aria-label="{{ $feature['label'] }} on for businesses"
                                    @class(['relative inline-flex h-6 w-11 shrink-0 items-center rounded-full transition-colors disabled:opacity-50', 'bg-brand-600' => $on, 'bg-surface-2 ring-1 ring-inset ring-line-strong' => ! $on])>
                                    <span @class(['inline-block size-4 rounded-full bg-white shadow transition-transform', 'translate-x-6' => $on, 'translate-x-1' => ! $on])></span>
                                </button>
                                <p class="mt-1 text-xs {{ $on ? 'text-emerald-300' : 'text-subtle' }}">{{ $on ? 'On' : 'Off, hidden' }}</p>
                            </td>
                            <td>
                                <label for="audience-{{ $key }}" class="sr-only">Who gets {{ $feature['label'] }}</label>
                                <select id="audience-{{ $key }}" wire:model.live="audiences.{{ $key }}" class="sh-input py-1.5 text-sm" @disabled(! $canManage || ! $on)>
                                    <option value="everyone">Every business</option>
                                    <option value="plan">Plans / add-ons with it</option>
                                    <option value="selected">Businesses I pick</option>
                                </select>
                                @if ($on && $wasOff[$key])
                                    <label class="mt-2 flex items-center gap-2 text-xs text-muted">
                                        <input type="checkbox" wire:model="announce.{{ $key }}" class="size-4 rounded border-line-strong bg-surface-2 text-brand-500">
                                        Show it as New to businesses for {{ \App\Services\Billing\FeatureAccess::NEW_FOR_DAYS }} days
                                    </label>
                                @endif
                                @if ($on && (($audiences[$key] ?? 'everyone') === 'selected' || $picked[$key] !== []))
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
                                    <span @class(['text-amber-300' => $on && ($audiences[$key] ?? 'everyone') === 'plan'])>No plan or add-on yet</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            @empty
                <tbody><tr><td colspan="4" class="px-5 py-6 text-sm text-muted">No features match.</td></tr></tbody>
            @endforelse
        </x-ui.table>
        @if ($canManage)
            <div class="flex flex-wrap items-center justify-between gap-3 border-t border-line px-5 py-4">
                <p class="text-xs text-subtle">Switching a feature off, or to "Plans / add-ons with it" or "Businesses I pick", takes it away from the others at once. Their settings and data are kept for when it's back.</p>
                <div class="flex items-center gap-3">
                    @if ($dirty)<span class="text-xs text-amber-300">You have changes to save</span>@endif
                    <x-ui.button type="submit" size="sm">Save</x-ui.button>
                </div>
            </div>
        @endif
    </form>

    @if ($picking)
        <div class="border-t border-line p-5" wire:key="picker-{{ $picking }}">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h3 class="text-sm font-semibold text-ink">Businesses with {{ $catalog[$picking]['label'] }}</h3>
                    <p class="text-xs text-subtle">Ticked businesses have it whatever their plan, while the feature is on. Save "Businesses I pick" above to hide it from everyone else.</p>
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
