@php($money = fn (int $c) => \App\Support\Money::format($c))
<div>
    <x-ui.page-header title="All locations" description="Each of your businesses side by side. Pick one in the top bar to work in it.">
        <x-slot:actions>
            <label for="loc-month" class="sr-only">Month</label>
            <select id="loc-month" wire:model.live="month" class="sh-input w-auto">
                @foreach ($months as $k => $label)<option value="{{ $k }}" @selected($k === $current)>{{ $label }}</option>@endforeach
            </select>
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.card :padding="false">
        <x-ui.table>
            <thead>
                <tr>
                    <th scope="col">Location</th>
                    <th scope="col" class="text-right">Calls answered</th>
                    <th scope="col" class="text-right">New leads</th>
                    <th scope="col" class="text-right">Jobs booked</th>
                    <th scope="col" class="text-right">Reviews</th>
                    <th scope="col" class="text-right">Collected</th>
                    <th scope="col" class="text-right">Open tasks</th>
                    <th scope="col" class="text-right">Escalations</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-line">
                @foreach ($rows as $row)
                    <tr wire:key="loc-{{ $row['organization']->id }}">
                        <td>
                            <form method="POST" action="{{ route('app.switch', $row['organization']->ulid) }}">
                                @csrf
                                <button type="submit" class="font-medium text-ink hover:text-brand-300">{{ $row['organization']->name }}</button>
                            </form>
                        </td>
                        <td class="text-right tabular-nums">{{ number_format($row['answered']) }} <span class="text-xs text-subtle">/ {{ number_format($row['calls']) }}</span></td>
                        <td class="text-right tabular-nums">{{ number_format($row['leads']) }}</td>
                        <td class="text-right tabular-nums">{{ number_format($row['booked']) }}</td>
                        <td class="text-right tabular-nums">{{ number_format($row['reviews']) }}@if ($row['rating'] !== null) <span class="text-xs text-subtle">· {{ number_format($row['rating'], 1) }}★</span>@endif</td>
                        <td class="text-right tabular-nums">{{ $money($row['collected']) }}</td>
                        <td class="text-right tabular-nums">{{ number_format($row['open_tasks']) }}</td>
                        <td class="text-right tabular-nums {{ $row['escalations'] ? 'text-danger' : '' }}">{{ number_format($row['escalations']) }}</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr class="border-t-2 border-line font-semibold">
                    <td>All locations</td>
                    <td class="text-right tabular-nums">{{ number_format($totals['answered']) }} <span class="text-xs font-normal text-subtle">/ {{ number_format($totals['calls']) }}</span></td>
                    <td class="text-right tabular-nums">{{ number_format($totals['leads']) }}</td>
                    <td class="text-right tabular-nums">{{ number_format($totals['booked']) }}</td>
                    <td class="text-right tabular-nums">{{ number_format($totals['reviews']) }}</td>
                    <td class="text-right tabular-nums">{{ $money($totals['collected']) }}</td>
                    <td class="text-right tabular-nums">{{ number_format($totals['open_tasks']) }}</td>
                    <td class="text-right tabular-nums">{{ number_format($totals['escalations']) }}</td>
                </tr>
            </tfoot>
        </x-ui.table>
    </x-ui.card>
</div>
