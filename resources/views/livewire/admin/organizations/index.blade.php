<div>
    <x-ui.page-header title="Organizations" description="Client businesses on the platform. A business is created automatically when you add a client user." />

    <div class="mb-4 grid gap-3 sm:grid-cols-[1fr_14rem] sm:items-end">
        <div>
            <label for="org-search" class="sh-label">Search</label>
            <div class="relative">
                <x-ui.icon name="search" class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-subtle" />
                <input id="org-search" type="search" wire:model.live.debounce.350ms="search" class="sh-input pl-9" placeholder="Business name or owner…" autocomplete="off">
            </div>
        </div>
        <div>
            <label for="org-status" class="sh-label">Status</label>
            <select id="org-status" wire:model.live="status" class="sh-input">
                <option value="">All statuses</option>
                <option value="setup">Still setting up</option>
                @foreach ($statuses as $option)
                    <option value="{{ $option->value }}">{{ $option->label() }}</option>
                @endforeach
            </select>
        </div>
    </div>

    <x-ui.card :padding="false">
        @if ($organizations->isEmpty())
            <x-ui.empty-state icon="building" title="No businesses found"
                description="{{ $search !== '' || $status !== '' ? 'Try a different search or status.' : 'Add a business owner under Users to create the first business.' }}" />
        @else
            <x-ui.table>
                <thead>
                    <tr>
                        <th scope="col">Business</th>
                        <th scope="col">Owner</th>
                        <th scope="col">Status</th>
                        <th scope="col">Setup</th>
                        <th scope="col" class="text-right">Agents</th>
                        <th scope="col" class="text-right">Calls (30d)</th>
                        <th scope="col" class="hidden text-right md:table-cell">Since</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    @foreach ($organizations as $organization)
                        <tr wire:key="org-{{ $organization->id }}">
                            <td>
                                <a href="{{ route('admin.organizations.show', $organization) }}" class="font-medium text-ink hover:text-brand-300">{{ $organization->name }}</a>
                                <p class="text-xs text-subtle">{{ $organization->timezone ?? 'Timezone not set' }}</p>
                            </td>
                            <td class="text-muted">
                                {{ $organization->owner?->name ?? '—' }}
                                <p class="text-xs text-subtle">{{ $organization->owner?->email }}</p>
                            </td>
                            <td>
                                <x-ui.badge :tone="match ($organization->status) {
                                    \App\Enums\OrganizationStatus::Active => 'success',
                                    \App\Enums\OrganizationStatus::Onboarding => 'info',
                                    \App\Enums\OrganizationStatus::Paused => 'warning',
                                    \App\Enums\OrganizationStatus::Cancelled => 'danger',
                                }">{{ $organization->status->label() }}</x-ui.badge>
                            </td>
                            <td>
                                @if ($organization->isSetUp())
                                    <span class="text-sm text-muted">Done</span>
                                @else
                                    @php $c = app(\App\Services\Setup\SetupProgress::class)->count($organization); @endphp
                                    <x-ui.badge :tone="$c['done'] === 0 ? 'danger' : 'warning'">{{ $c['done'] }}/{{ $c['total'] }} steps</x-ui.badge>
                                @endif
                            </td>
                            <td class="text-right tabular-nums text-muted">{{ $organization->agents_count }}</td>
                            <td class="text-right tabular-nums text-muted">{{ number_format($organization->calls_30d) }}</td>
                            <td class="hidden whitespace-nowrap text-right text-muted md:table-cell">{{ $organization->created_at->format('M j, Y') }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </x-ui.table>
            <x-ui.pagination :paginator="$organizations" />
        @endif
    </x-ui.card>
</div>
