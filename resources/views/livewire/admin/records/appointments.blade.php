<div>
    <x-ui.page-header title="Appointments" description="Every business's appointments. Without dates, upcoming ones first; times are in each business's timezone." />

    <x-admin.record-filters :businesses="$businesses" placeholder="Title or customer…">
        <div>
            <label for="rf-status" class="sr-only">Status</label>
            <select id="rf-status" wire:model.live="status" class="sh-input">
                <option value="">Any status</option>
                @foreach ($statuses as $s)<option value="{{ $s->value }}">{{ $s->label() }}</option>@endforeach
            </select>
        </div>
    </x-admin.record-filters>

    <x-ui.card :padding="false">
        @if ($appointments->isEmpty())
            <x-ui.empty-state icon="calendar" title="{{ $dated ? 'No appointments in these dates' : 'Nothing coming up' }}" description="Try other filters, or pick dates to see past appointments." />
        @else
            <x-ui.table>
                <thead>
                    <tr>
                        <th scope="col">When</th>
                        <th scope="col">Appointment</th>
                        <th scope="col">Business</th>
                        <th scope="col">Status</th>
                        <th scope="col" class="hidden lg:table-cell">Booked by</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    @foreach ($appointments as $a)
                        @php($tz = $a->organization?->timezoneOrDefault() ?? config('app.timezone'))
                        <tr wire:key="a-{{ $a->id }}">
                            <td class="whitespace-nowrap">
                                <p class="text-ink">{{ $a->starts_at->setTimezone($tz)->format('D M j, g:i A') }}</p>
                                <p class="text-xs text-subtle">{{ $tz }}</p>
                            </td>
                            <td>
                                <p class="font-medium text-ink">{{ $a->service->name ?? $a->title }}</p>
                                <p class="text-xs text-subtle">{{ $a->customer?->fullName() ?? 'No customer' }}</p>
                            </td>
                            <td class="text-muted">@if ($a->organization)<a href="{{ route('admin.organizations.show', $a->organization) }}" class="hover:text-ink">{{ $a->organization->name }}</a>@endif</td>
                            <td>
                                <x-ui.badge :tone="$a->status->tone()">{{ $a->status->label() }}</x-ui.badge>
                                @if ($a->cancellation_reason)<p class="mt-1 max-w-48 truncate text-xs text-subtle" title="{{ $a->cancellation_reason }}">{{ $a->cancellation_reason }}</p>@endif
                            </td>
                            <td class="hidden text-muted lg:table-cell">{{ $a->bookedBy?->name ?? str($a->source)->headline() }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </x-ui.table>
            <x-ui.pagination :paginator="$appointments" />
        @endif
    </x-ui.card>
</div>
