@php
    $money = fn (?int $cents) => $cents === null ? '—' : \App\Support\Money::format($cents);
@endphp
<div>
    <x-ui.page-header title="Call tracking" description="A separate SureHelp number for each ad or marketing channel. Calls are answered exactly like your main number, and you see which channels bring calls and booked jobs." />

    <div class="mb-4 flex flex-wrap items-center gap-3">
        <label for="ct-month" class="text-sm text-muted">Month</label>
        <select id="ct-month" wire:model.live="month" class="sh-input w-auto">
            @foreach ($months as $m)
                <option value="{{ $m->format('Y-m') }}">{{ $m->format('F Y') }}</option>
            @endforeach
        </select>
    </div>

    <x-ui.card title="By channel, {{ $current->format('F Y') }}" :padding="false">
        <div class="overflow-x-auto">
            <table class="w-full min-w-[44rem] text-sm">
                <thead class="text-left text-xs uppercase tracking-wide text-subtle">
                    <tr class="border-b border-line">
                        <th class="px-5 py-3 font-medium">Channel</th>
                        <th class="px-3 py-3 text-right font-medium">Calls</th>
                        <th class="px-3 py-3 text-right font-medium">Answered</th>
                        <th class="px-3 py-3 text-right font-medium">Booked jobs</th>
                        <th class="px-3 py-3 text-right font-medium">Worth</th>
                        <th class="px-3 py-3 font-medium">Spend this month</th>
                        <th class="px-5 py-3 text-right font-medium">Cost per job</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    @forelse ($rows as $row)
                        @php
                            $line = $row['line'];
                        @endphp
                        <tr wire:key="ct-{{ $line->ulid }}">
                            <td class="px-5 py-3">
                                <p class="font-medium text-ink">{{ $row['source'] }} @if ($line->status !== 'active')<span class="text-xs font-normal text-subtle">(removed)</span>@endif</p>
                                <p class="text-xs text-subtle">{{ $line->display() }}</p>
                            </td>
                            <td class="px-3 py-3 text-right text-ink">{{ number_format($row['calls']) }}</td>
                            <td class="px-3 py-3 text-right text-muted">{{ number_format($row['answered']) }}</td>
                            <td class="px-3 py-3 text-right font-medium text-ink">{{ number_format($row['booked']) }}</td>
                            <td class="px-3 py-3 text-right text-muted">{{ $money($row['value_cents']) }}</td>
                            <td class="px-3 py-3">
                                @if ($canManage && $line->status === 'active')
                                    <form wire:submit="saveSpend('{{ $line->ulid }}')" class="flex items-center gap-1">
                                        <input type="text" inputmode="decimal" wire:model="spend.{{ $line->ulid }}" aria-label="Monthly spend for {{ $row['source'] }}" placeholder="$ a month" class="sh-input w-24 py-1 text-sm">
                                        <x-ui.button type="submit" size="sm" variant="ghost">Save</x-ui.button>
                                    </form>
                                    @error('spend.'.$line->ulid)<p class="mt-1 text-xs text-danger">{{ $message }}</p>@enderror
                                @else
                                    <span class="text-muted">{{ $money($row['spend_cents']) }}</span>
                                @endif
                            </td>
                            <td class="px-5 py-3 text-right font-medium text-ink">{{ $money($row['cost_per_job_cents']) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="px-5 py-6"><x-ui.empty-state icon="phone" title="No phone line yet" description="Once your SureHelp line is set up, you can add a tracking number for each channel." /></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <p class="border-t border-line px-5 py-3 text-xs text-subtle">Booked jobs are calls our receptionists logged as booked. "Worth" uses the booked service's price, or your average job value on Results. Enter what each channel costs you a month to see the cost per booked job.</p>
    </x-ui.card>

    @if ($canManage)
        <x-ui.card title="Add a tracking number" description="Each number costs SureHelp a small monthly fee from the phone company; your plan covers up to {{ \App\Livewire\Client\CallTracking::MAX_NUMBERS }}." class="mt-6">
            @if (! $hasMainLine)
                <p class="text-sm text-muted">Your SureHelp phone line needs to be set up first.</p>
            @elseif ($trackingCount >= \App\Livewire\Client\CallTracking::MAX_NUMBERS)
                <p class="text-sm text-muted">You have the most tracking numbers allowed. Remove one you no longer use to add another.</p>
            @else
                <form wire:submit="search" class="grid gap-3 sm:grid-cols-[1fr_9rem_auto] sm:items-end">
                    <div>
                        <label for="ct-source" class="sh-label">Channel</label>
                        <input id="ct-source" type="text" list="ct-suggested" wire:model="source" maxlength="60" class="sh-input" placeholder="Google Ads">
                        <datalist id="ct-suggested">@foreach ($suggested as $s)<option value="{{ $s }}"></option>@endforeach</datalist>
                        @error('source')<p class="mt-1 text-sm text-danger">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label for="ct-area" class="sh-label">Area code</label>
                        <input id="ct-area" type="text" inputmode="numeric" maxlength="3" wire:model="areaCode" class="sh-input" placeholder="512">
                        @error('areaCode')<p class="mt-1 text-sm text-danger">{{ $message }}</p>@enderror
                    </div>
                    <x-ui.button type="submit" icon="search">Find numbers</x-ui.button>
                </form>
                @if ($found)
                    <ul class="mt-4 grid gap-2 sm:grid-cols-2" role="list">
                        @foreach ($found as $option)
                            <li wire:key="found-{{ $option['number'] }}" class="flex items-center justify-between gap-3 rounded-xl border border-line px-4 py-2 text-sm">
                                <span class="text-ink">{{ \App\Models\PhoneNumber::pretty($option['number']) }} <span class="text-xs text-subtle">{{ collect([$option['locality'] ?? null, $option['region'] ?? null])->filter()->implode(', ') }}</span></span>
                                <x-ui.button type="button" size="sm" wire:click="buy('{{ $option['number'] }}')" wire:confirm="Add {{ \App\Models\PhoneNumber::pretty($option['number']) }} for {{ $source }}?">Add</x-ui.button>
                            </li>
                        @endforeach
                    </ul>
                @endif
            @endif

            @if ($trackingCount > 0)
                <div class="mt-5 border-t border-line pt-4">
                    <p class="mb-2 text-sm font-medium text-ink">Your tracking numbers</p>
                    <ul class="space-y-2 text-sm" role="list">
                        @foreach ($rows as $row)
                            @continue(! $row['line']->isTracking() || $row['line']->status !== 'active')
                            <li class="flex flex-wrap items-center justify-between gap-2">
                                <span class="text-ink">{{ $row['source'] }} · <span class="text-muted">{{ $row['line']->display() }}</span></span>
                                <button type="button" wire:click="remove('{{ $row['line']->ulid }}')" wire:confirm="Remove this number? Calls to it stop reaching SureHelp, and the number can't be got back." class="text-muted hover:text-danger">Remove</button>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </x-ui.card>
    @endif
</div>
