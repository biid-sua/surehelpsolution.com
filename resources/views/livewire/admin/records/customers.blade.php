<div>
    <x-ui.page-header title="Customers" description="Every business's customers, most recently active first. Dates filter by when the customer was added." />

    <x-admin.record-filters :businesses="$businesses" placeholder="Name, phone, email or company…" />

    <x-ui.card :padding="false">
        @if ($customers->isEmpty())
            <x-ui.empty-state icon="users" title="No customers found" description="Try other filters." />
        @else
            <x-ui.table>
                <thead>
                    <tr>
                        <th scope="col">Customer</th>
                        <th scope="col">Business</th>
                        <th scope="col">Status</th>
                        <th scope="col" class="hidden md:table-cell">Source</th>
                        <th scope="col" class="text-right">Last activity</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    @foreach ($customers as $c)
                        <tr wire:key="cu-{{ $c->id }}">
                            <td>
                                <p class="font-medium text-ink">{{ $c->fullName() }}</p>
                                <p class="text-xs text-subtle">{{ $c->displayPhone() ?? 'No phone' }}@if ($c->email) · {{ $c->email }}@endif</p>
                            </td>
                            <td class="text-muted">@if ($c->organization)<a href="{{ route('admin.organizations.show', $c->organization) }}" class="hover:text-ink">{{ $c->organization->name }}</a>@endif</td>
                            <td><x-ui.badge :tone="$c->status->tone()">{{ $c->status->label() }}</x-ui.badge></td>
                            <td class="hidden text-muted md:table-cell">{{ str($c->source)->headline() }}</td>
                            <td class="whitespace-nowrap text-right text-muted">{{ $c->last_activity_at?->diffForHumans() ?? '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </x-ui.table>
            <x-ui.pagination :paginator="$customers" />
        @endif
    </x-ui.card>
</div>
