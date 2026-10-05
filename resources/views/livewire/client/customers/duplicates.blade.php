@php
    $fields = ['Name' => fn ($c) => $c->fullName(), 'Phone' => fn ($c) => $c->displayPhone(), 'Email' => fn ($c) => $c->email, 'Company' => fn ($c) => $c->company,
        'Address' => fn ($c) => trim(implode(', ', array_filter([$c->address_line1, $c->city, $c->state, $c->postal_code]))), 'Status' => fn ($c) => $c->status->label(), 'Added' => fn ($c) => $c->created_at->format('M j, Y')];
@endphp
<div>
    <x-ui.page-header title="Duplicate customers" :back="route('app.customers.index')"
        description="Two records for the same person? Merge them so their calls, bookings and history are in one place. You choose which record stays." />

    @if ($a)
        <x-ui.card title="Compare and merge">
            @if (! $b)
                <p class="text-sm text-muted">Find the other record for {{ $a['customer']->fullName() }}:</p>
                <input type="search" wire:model.live.debounce.300ms="search" class="sh-input mt-2 max-w-md" placeholder="Name, phone or email…" aria-label="Find the other customer">
                <ul class="mt-3 divide-y divide-line rounded-xl ring-1 ring-line">
                    @forelse ($matches as $m)
                        <li class="flex items-center justify-between gap-3 px-4 py-2.5 text-sm">
                            <span><span class="font-medium text-ink">{{ $m->fullName() }}</span> <span class="text-subtle">{{ $m->displayPhone() ?? $m->email }}</span></span>
                            <x-ui.button size="sm" variant="secondary" wire:click="pick('{{ $m->ulid }}')">Compare</x-ui.button>
                        </li>
                    @empty
                        <li class="px-4 py-3 text-sm text-subtle">{{ mb_strlen(trim($search)) >= 2 ? 'No other customers match.' : 'Type at least two characters.' }}</li>
                    @endforelse
                </ul>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[36rem] text-sm">
                        <thead>
                            <tr class="text-left">
                                <th class="w-28"></th>
                                @foreach (['a' => $a, 'b' => $b] as $side => $d)
                                    <th class="px-3 pb-3 align-bottom">
                                        <label @class(['flex cursor-pointer items-center gap-2 rounded-xl px-3 py-2 ring-1', 'bg-brand-500/15 ring-brand-500/40' => $keep === $side, 'ring-line' => $keep !== $side])>
                                            <input type="radio" wire:model.live="keep" value="{{ $side }}" class="size-4 text-brand-500">
                                            <span class="font-semibold text-ink">Keep this record</span>
                                        </label>
                                    </th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            @foreach ($fields as $label => $get)
                                <tr>
                                    <th scope="row" class="py-2 pr-3 text-left font-normal text-subtle">{{ $label }}</th>
                                    @foreach ([$a, $b] as $d)<td class="px-3 py-2 text-ink">{{ $get($d['customer']) ?: '—' }}</td>@endforeach
                                </tr>
                            @endforeach
                            <tr>
                                <th scope="row" class="py-2 pr-3 text-left font-normal text-subtle">History</th>
                                @foreach ([$a, $b] as $d)<td class="px-3 py-2 text-muted">{{ $d['calls'] }} calls · {{ $d['appointments'] }} appointments · {{ $d['tasks'] }} tasks</td>@endforeach
                            </tr>
                        </tbody>
                    </table>
                </div>
                <x-ui.alert tone="info" class="mt-4">
                    Everything from the other record moves to the one you keep. Its details are kept, and any empty ones are filled in from the other record. The other record is archived.
                </x-ui.alert>
                <div class="mt-4 flex justify-end gap-2">
                    <x-ui.button variant="secondary" wire:click="close">Cancel</x-ui.button>
                    <x-ui.confirm id="merge" title="Merge these customers?" confirm-label="Merge" tone="primary" action="merge">
                        <x-slot:trigger><x-ui.button>Merge</x-ui.button></x-slot:trigger>
                        This can't be undone from here.
                    </x-ui.confirm>
                </div>
            @endif
        </x-ui.card>
    @else
        <x-ui.card title="Possible duplicates" :padding="false">
            @if ($pairs->isEmpty())
                <x-ui.empty-state icon="users" title="No likely duplicates" description="We look for customers with the same email address or the same name. To merge two records anyway, open one and choose “Merge with another customer”." />
            @else
                <ul class="divide-y divide-line" role="list">
                    @foreach ($pairs as $pair)
                        <li class="flex flex-wrap items-center justify-between gap-3 px-5 py-3" wire:key="pair-{{ $pair['a']->id }}-{{ $pair['b']->id }}">
                            <div class="text-sm">
                                <p class="font-medium text-ink">{{ $pair['a']->fullName() }} <span class="text-subtle">and</span> {{ $pair['b']->fullName() }}</p>
                                <p class="text-xs text-subtle">{{ $pair['reason'] }} · {{ $pair['a']->displayPhone() ?? $pair['a']->email }} / {{ $pair['b']->displayPhone() ?? $pair['b']->email }}</p>
                            </div>
                            <x-ui.button size="sm" variant="secondary" wire:click="compare('{{ $pair['a']->ulid }}', '{{ $pair['b']->ulid }}')">Compare</x-ui.button>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-ui.card>
    @endif
</div>
