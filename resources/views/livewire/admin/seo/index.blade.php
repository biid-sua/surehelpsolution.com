<div @if ($current && in_array($current->status, ['queued', 'running'], true)) wire:poll.5s @endif>
    <x-ui.page-header title="SEO service" description="Approved SEO work to put on businesses' sites, and each business's AI allowance." />

    <div class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">
        <div class="space-y-6">
            <x-ui.card :padding="false" title="Approved, waiting to go live" description="Put these on the business's site (or check they did), then mark them live.">
                @if ($toApply->isEmpty())
                    <p class="px-5 py-4 text-sm text-muted">Nothing waiting.</p>
                @else
                    <ul class="divide-y divide-line" role="list">
                        @foreach ($toApply as $t)
                            <li wire:key="ap-{{ $t->id }}">
                                <button type="button" wire:click="$set('selected', '{{ $t->ulid }}')" @class(['block w-full px-5 py-3 text-left hover:bg-surface-2', 'bg-surface-2' => $current?->id === $t->id])>
                                    <span class="block text-sm font-medium text-ink">{{ $t->organization?->name }} · {{ $types[$t->type]['label'] ?? $t->type }}</span>
                                    <span class="block text-xs text-subtle">approved {{ $t->updated_at->diffForHumans() }}</span>
                                </button>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-ui.card>
        </div>

        <div>
            @if ($current)
                <x-ui.card :title="($current->organization?->name ?? '').' · '.($types[$current->type]['label'] ?? $current->type)">
                    @if (in_array($current->status, ['queued', 'running'], true))
                        <p class="text-sm text-muted">The AI is working on this…</p>
                    @elseif ($current->status === 'failed')
                        <x-ui.alert tone="danger">{{ $current->error }}</x-ui.alert>
                    @else
                        @include('partials.seo.result')
                        <div class="mt-6 flex flex-wrap items-center gap-2 border-t border-line pt-4">
                            @if ($current->applied_at)
                                <p class="text-sm text-muted">Live since {{ $current->applied_at->format('M j, Y') }}.</p>
                            @else
                                <x-ui.button size="sm" wire:click="markLive">Mark live on their site</x-ui.button>
                            @endif
                        </div>
                    @endif
                </x-ui.card>
            @else
                <x-ui.card><x-ui.empty-state icon="sparkles" title="Pick a task" /></x-ui.card>
            @endif
        </div>
    </div>

    <x-ui.card :padding="false" title="Businesses" class="mt-6">
        @if ($businesses->isEmpty())
            <p class="px-5 py-4 text-sm text-muted">No business uses the SEO service yet. It's offered as an add-on with the slug <code>seo_service</code> (Billing › Add-ons), or switch it on for one business on its page.</p>
        @else
            <x-ui.table>
                <thead><tr><th scope="col">Business</th><th scope="col">Tokens this month</th><th scope="col">Allowance</th><th scope="col">Run</th></tr></thead>
                <tbody class="divide-y divide-line">
                    @foreach ($businesses as $b)
                        <tr wire:key="b-{{ $b['org']->id }}">
                            <td>
                                <a href="{{ route('admin.organizations.show', $b['org']) }}" class="font-medium text-ink hover:text-brand-300">{{ $b['org']->name }}</a>
                                <p class="text-xs text-subtle">{{ $b['on'] ? 'Service on' : 'Service off' }} · {{ $b['console'] ? 'Search Console' : 'no Search Console' }}{{ $b['last'] ? ' · last task '.$b['last']->created_at->diffForHumans() : '' }}</p>
                            </td>
                            <td class="tabular-nums text-muted">{{ number_format($b['used']) }} / {{ number_format($b['allowance']) }}</td>
                            <td>
                                <div class="flex items-center gap-1">
                                    <label class="sr-only" for="al-{{ $b['org']->id }}">Allowance for {{ $b['org']->name }}</label>
                                    <input id="al-{{ $b['org']->id }}" type="text" inputmode="numeric" wire:model="allowance.{{ $b['org']->id }}" class="sh-input w-28 py-1 text-sm" placeholder="plan">
                                    <x-ui.button size="sm" variant="ghost" wire:click="saveAllowance({{ $b['org']->id }})">Save</x-ui.button>
                                </div>
                                @error('allowance.'.$b['org']->id) <p class="text-xs text-danger">{{ $message }}</p> @enderror
                            </td>
                            <td>
                                @if ($b['on'])
                                    <select class="sh-input w-40 py-1 text-sm" aria-label="Run a task for {{ $b['org']->name }}" x-on:change="if ($event.target.value) { $wire.runFor({{ $b['org']->id }}, $event.target.value); $event.target.value = '' }">
                                        <option value="">Run a task…</option>
                                        @foreach ($types as $key => $def)@continue($key === 'local_page')<option value="{{ $key }}">{{ $def['label'] }}</option>@endforeach
                                    </select>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </x-ui.table>
        @endif
    </x-ui.card>
</div>
