<div>
    <x-ui.page-header title="Overview" description="Platform activity across all client businesses." />

    @if ($stats['needsReview'] > 0)
        <x-ui.alert tone="warning" class="mb-6" title="{{ $stats['needsReview'] }} {{ \Illuminate\Support\Str::plural('call', $stats['needsReview']) }} need an owner check">
            These calls were matched to a business by caller email, or couldn't be matched at all.
            <a href="{{ route('admin.calls.review') }}" class="font-semibold underline underline-offset-2">Open the review queue</a>
        </x-ui.alert>
    @endif

    <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
        <x-ui.stat label="Active businesses" icon="building" :value="number_format($stats['organizations'])"
            hint="{{ $stats['newOrganizations'] }} new this month" :href="route('admin.organizations.index')" />
        <x-ui.stat label="Active agents" icon="users" :value="number_format($stats['agents'])" />
        <x-ui.stat label="Calls today" icon="phone" :value="number_format($stats['callsToday'])" hint="all businesses, UTC day" />
        <x-ui.stat label="Calls to review" icon="inbox" :value="number_format($stats['needsReview'])" :href="route('admin.calls.review')" />
    </div>

    <div class="mt-6 grid gap-6 lg:grid-cols-3">
        <x-ui.card class="lg:col-span-2" title="Latest calls" :padding="false">
            @if ($recentCalls->isEmpty())
                <x-ui.empty-state icon="phone" title="No calls logged yet" description="Calls appear here as soon as an agent saves a call log." />
            @else
                <x-ui.table>
                    <thead>
                        <tr>
                            <th scope="col">Business</th>
                            <th scope="col">Caller</th>
                            <th scope="col">Status</th>
                            <th scope="col">Agent</th>
                            <th scope="col" class="text-right">When</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        @foreach ($recentCalls as $call)
                            <tr>
                                <td>
                                    @if ($call->organization)
                                        <a href="{{ route('admin.organizations.show', $call->organization) }}" class="font-medium text-ink hover:text-brand-300">{{ $call->organization->name }}</a>
                                    @else
                                        <x-ui.badge tone="warning">Unassigned</x-ui.badge>
                                    @endif
                                </td>
                                <td class="text-muted">{{ \App\Models\CallLog::display($call->caller_name) }}</td>
                                <td><x-ui.badge :tone="$call->statusTone()">{{ $call->statusLabel() }}</x-ui.badge></td>
                                <td class="text-muted">{{ \App\Models\CallLog::display($call->agent_name) }}</td>
                                <td class="whitespace-nowrap text-right text-muted">{{ $call->created_at->diffForHumans() }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </x-ui.table>
            @endif
        </x-ui.card>

        <x-ui.card title="Busiest businesses" description="Calls in the last 30 days" :padding="false">
            @forelse ($busiest as $organization)
                <a href="{{ route('admin.organizations.show', $organization) }}" class="flex items-center justify-between gap-3 border-b border-line px-5 py-3 last:border-0 hover:bg-surface-2">
                    <span class="truncate text-sm font-medium text-ink">{{ $organization->name }}</span>
                    <span class="text-sm tabular-nums text-muted">{{ number_format($organization->calls_30d) }}</span>
                </a>
            @empty
                <x-ui.empty-state icon="building" title="No businesses yet" description="Businesses are created when you add a client user." />
            @endforelse
        </x-ui.card>
    </div>
</div>
