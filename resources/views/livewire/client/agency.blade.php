<div>
    <x-ui.page-header :title="$agency->name" description="Every business you look after, and how this month is going." />

    <div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-3">
        <x-ui.stat label="Businesses" icon="building" :value="number_format(count($board['rows']))" />
        <x-ui.stat label="Paid to SureHelp, {{ $month->format('F') }}" icon="card" :value="\App\Support\Money::format($board['paid_cents'])" />
        <x-ui.stat label="Your share ({{ rtrim(rtrim(number_format((float) $agency->commission_percent, 2, '.', ''), '0'), '.') }}%)" icon="trend-up" :value="\App\Support\Money::format($board['commission_cents'])" />
    </div>

    <x-ui.card title="Your businesses, {{ $month->format('F Y') }}" :padding="false">
        <div class="overflow-x-auto">
            <table class="w-full min-w-[40rem] text-sm">
                <thead class="text-left text-xs uppercase tracking-wide text-subtle">
                    <tr class="border-b border-line">
                        <th class="px-5 py-3 font-medium">Business</th>
                        <th class="px-3 py-3 text-right font-medium">Calls answered</th>
                        <th class="px-3 py-3 text-right font-medium">Missed</th>
                        <th class="px-3 py-3 text-right font-medium">Booked</th>
                        <th class="px-3 py-3 text-right font-medium">Urgent waiting</th>
                        <th class="px-5 py-3 text-right font-medium"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    @forelse ($board['rows'] as $row)
                        @php
                            $org = $row['organization'];
                        @endphp
                        <tr wire:key="b-{{ $org->ulid }}">
                            <td class="px-5 py-3"><p class="font-medium text-ink">{{ $org->name }}</p><p class="text-xs text-subtle">{{ ucfirst($org->status->value) }}</p></td>
                            <td class="px-3 py-3 text-right text-ink">{{ number_format($row['calls']) }}</td>
                            <td @class(['px-3 py-3 text-right', 'text-danger' => $row['missed'] > 0, 'text-muted' => $row['missed'] === 0])>{{ number_format($row['missed']) }}</td>
                            <td class="px-3 py-3 text-right text-ink">{{ number_format($row['booked']) }}</td>
                            <td @class(['px-3 py-3 text-right', 'font-medium text-danger' => $row['urgent'] > 0, 'text-muted' => $row['urgent'] === 0])>{{ number_format($row['urgent']) }}</td>
                            <td class="px-5 py-3 text-right">
                                <form method="POST" action="{{ route('app.switch', $org->ulid) }}">@csrf<x-ui.button type="submit" size="sm" variant="secondary">Open</x-ui.button></form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-5 py-6 text-subtle">No businesses yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-ui.card>

    <x-ui.card title="Your statements" description="Made on the 1st for the month before; SureHelp pays your share from it." :padding="false" class="mt-6">
        <ul class="divide-y divide-line text-sm" role="list">
            @forelse ($statements as $statement)
                <li class="flex flex-wrap items-center justify-between gap-2 px-5 py-3" wire:key="st-{{ $statement->id }}">
                    <span class="text-ink">{{ $statement->month->format('F Y') }}</span>
                    <span class="text-muted">{{ \App\Support\Money::format($statement->share_cents) }} <span class="text-xs text-subtle">of {{ \App\Support\Money::format($statement->clients_paid_cents) }} paid by your clients</span></span>
                    <x-ui.badge :tone="$statement->status === 'paid' ? 'success' : 'warning'">{{ $statement->status === 'paid' ? 'Paid '.$statement->paid_at?->format('M j') : 'Due' }}</x-ui.badge>
                </li>
            @empty
                <li class="px-5 py-6 text-subtle">Your first statement is made on the 1st of next month.</li>
            @endforelse
        </ul>
    </x-ui.card>

    @if ($isOwner)
        <x-ui.card title="Add a client business" description="You'll be its owner to start with: open it to finish the setup, then invite the business owner under Team." class="mt-6">
            <form wire:submit="addBusiness" class="flex flex-wrap items-start gap-2 text-sm">
                <div class="min-w-[14rem] flex-1">
                    <input type="text" wire:model="name" maxlength="120" placeholder="Business name" aria-label="Business name" class="sh-input">
                    @error('name')<p class="mt-1 text-danger">{{ $message }}</p>@enderror
                </div>
                <select wire:model="timezone" aria-label="Time zone" class="sh-input w-auto">
                    @foreach ($zones as $zone => $label)<option value="{{ $zone }}">{{ $label }}</option>@endforeach
                </select>
                <x-ui.button type="submit">Add business</x-ui.button>
            </form>
        </x-ui.card>
    @endif
</div>
