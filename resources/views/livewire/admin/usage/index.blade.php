<div>
    <x-ui.page-header title="Usage" description="What each business used in a month ({{ config('app.timezone') }}). Calls are answered calls, without spam, as billed.">
        <x-slot:actions>
            <label for="u-month" class="sr-only">Month</label>
            <select id="u-month" wire:model.live="month" class="sh-input w-44">
                @foreach ($months as $key => $label)
                    <option value="{{ $key === array_key_first($months) ? '' : $key }}" @selected($key === $monthKey)>{{ $label }}</option>
                @endforeach
            </select>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="mb-6 grid grid-cols-2 gap-4 lg:grid-cols-4">
        <x-ui.stat label="Calls answered" icon="phone" :value="number_format($totals['calls'])" hint="all businesses" />
        <x-ui.stat label="Appointments booked" icon="calendar" :value="number_format($totals['appointments'])" hint="not cancelled" />
        <x-ui.stat label="New customers" icon="users" :value="number_format($totals['customers'])" />
        <x-ui.stat label="Inbox messages" icon="chat" :value="number_format($totals['messages'])" hint="in and out" />
    </div>

    <div class="mb-4 max-w-sm">
        <label for="u-search" class="sr-only">Search businesses</label>
        <input id="u-search" type="search" wire:model.live.debounce.350ms="search" class="sh-input" placeholder="Business name…" autocomplete="off">
    </div>

    <x-ui.card :padding="false">
        @if ($organizations->isEmpty())
            <x-ui.empty-state icon="chart" title="No businesses found" />
        @else
            <x-ui.table>
                <thead>
                    <tr>
                        <th scope="col">Business</th>
                        <th scope="col">Plan</th>
                        <th scope="col" class="text-right">Calls</th>
                        <th scope="col" class="text-right">Appointments</th>
                        <th scope="col" class="hidden text-right md:table-cell">New customers</th>
                        <th scope="col" class="hidden text-right lg:table-cell">Messages in / out</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    @foreach ($organizations as $org)
                        @php
                            $sub = $plans[$org->id] ?? null;
                            $included = isset($sub?->plan?->limits['calls']) ? (int) $sub->plan->limits['calls'] : null;
                            $percent = $included ? (int) floor(100 * $org->calls / $included) : null;
                        @endphp
                        <tr wire:key="u-{{ $org->id }}">
                            <td><a href="{{ route('admin.organizations.show', $org) }}" class="font-medium text-ink hover:text-brand-300">{{ $org->name }}</a></td>
                            <td class="text-muted">{{ $sub?->plan?->name ?? 'No plan' }}</td>
                            <td class="text-right tabular-nums">
                                <span class="text-ink">{{ number_format($org->calls) }}</span>@if ($included !== null)<span class="text-subtle"> / {{ number_format($included) }}</span>@endif
                                @if ($percent !== null)
                                    <div class="ml-auto mt-1 h-1.5 w-24 overflow-hidden rounded-full bg-surface-2" title="{{ $percent }}% of the plan">
                                        <div @class(['h-full rounded-full', 'bg-brand-500' => $percent < 80, 'bg-amber-500' => $percent >= 80 && $percent < 100, 'bg-red-500' => $percent >= 100]) style="width: {{ min(100, $percent) }}%"></div>
                                    </div>
                                @endif
                            </td>
                            <td class="text-right tabular-nums text-muted">{{ number_format($appointments[$org->id] ?? 0) }}</td>
                            <td class="hidden text-right tabular-nums text-muted md:table-cell">{{ number_format($customers[$org->id] ?? 0) }}</td>
                            <td class="hidden text-right tabular-nums text-muted lg:table-cell">{{ number_format($messagesIn[$org->id] ?? 0) }} / {{ number_format($messagesOut[$org->id] ?? 0) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </x-ui.table>
            <x-ui.pagination :paginator="$organizations" />
        @endif
    </x-ui.card>
</div>
