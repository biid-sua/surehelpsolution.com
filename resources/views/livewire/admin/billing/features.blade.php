<x-ui.card :padding="false" title="Feature access" description="Open to everyone, or only to businesses whose plan or add-on includes the feature key. Put the key in a plan's feature keys, or give an add-on the same key. You can also switch a feature on or off for one business on its page.">
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
                            <select id="mode-{{ $key }}" wire:model="modes.{{ $key }}" class="sh-input py-1.5 text-sm" @disabled(! $canManage)>
                                <option value="everyone">Every business</option>
                                <option value="plan">Only plans / add-ons that include it</option>
                            </select>
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
                <p class="text-xs text-subtle">Switching a feature to "only plans" takes it away from businesses whose plan doesn't include it, at once.</p>
                <x-ui.button type="submit" size="sm">Save</x-ui.button>
            </div>
        @endif
    </form>
</x-ui.card>
