<div>
    <x-agent.company-bar :company="$company" active="customers" />

    <div class="mb-4 max-w-md">
        <label for="c-search" class="sh-label">Search {{ $company->name }}'s customers</label>
        <input id="c-search" type="search" wire:model.live.debounce.350ms="search" class="sh-input" placeholder="Name, phone, email…" autocomplete="off">
    </div>

    <x-ui.card :padding="false">
        @if ($customers->isEmpty())
            <x-ui.empty-state icon="users" title="No customers found" description="{{ $search !== '' ? 'Try another name or number.' : 'Customers appear here after their first call, booking or message.' }}" />
        @else
            <x-ui.table>
                <thead><tr><th scope="col">Customer</th><th scope="col">Contact</th><th scope="col">Status</th><th scope="col" class="text-right">Last activity</th></tr></thead>
                <tbody class="divide-y divide-line">
                    @foreach ($customers as $customer)
                        <tr wire:key="cu-{{ $customer->ulid }}">
                            <td><a href="{{ route('agent.businesses.customers.show', [$company->ulid, $customer->ulid]) }}" class="font-medium text-ink hover:text-brand-300">{{ $customer->fullName() }}</a></td>
                            <td class="text-muted">{{ $customer->displayPhone() ?? '—' }}@if ($customer->email)<p class="text-xs text-subtle">{{ $customer->email }}</p>@endif</td>
                            <td><x-ui.badge :tone="$customer->status->tone()">{{ $customer->status->label() }}</x-ui.badge></td>
                            <td class="whitespace-nowrap text-right text-muted">{{ $customer->last_activity_at?->setTimezone($timezone)->diffForHumans() ?? '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </x-ui.table>
            <x-ui.pagination :paginator="$customers" />
        @endif
    </x-ui.card>
</div>
