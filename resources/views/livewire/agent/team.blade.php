<div>
    <x-ui.page-header title="Team" description="Agents, the companies they serve, and who is on shift." />

    <div class="mb-4 max-w-sm">
        <label for="t-search" class="sr-only">Search agents</label>
        <input id="t-search" type="search" wire:model.live.debounce.350ms="search" class="sh-input" placeholder="Search agents…">
    </div>

    <x-ui.card :padding="false">
        <x-ui.table>
            <thead><tr><th scope="col">Agent</th><th scope="col">Companies</th><th scope="col">Availability</th><th scope="col" class="text-right">Calls (30 days)</th><th scope="col" class="text-right"><span class="sr-only">Actions</span></th></tr></thead>
            <tbody class="divide-y divide-line">
                @forelse ($agents as $agent)
                    @php($row = $rows[$agent->id])
                    <tr wire:key="ag-{{ $agent->id }}">
                        <td>
                            <a href="{{ route('agent.team.show', $agent->id) }}" class="font-medium text-ink hover:text-brand-300">{{ $agent->name }}</a>
                            <p class="text-xs text-subtle">{{ $agent->email }}@unless ($agent->is_active) · <span class="text-danger">account off</span>@endunless</p>
                        </td>
                        <td class="text-sm text-muted">
                            {{ $row['total'] }} current
                            @if ($row['visible'] !== [])<p class="text-xs text-subtle">{{ implode(', ', $row['visible']) }}@if ($row['total'] > count($row['visible'])) + {{ $row['total'] - count($row['visible']) }} other @endif</p>@endif
                        </td>
                        <td>@if ($row['on_shift'])<x-ui.badge tone="success">On shift</x-ui.badge>@else<span class="text-sm text-subtle">Off shift</span>@endif</td>
                        <td class="text-right text-muted">{{ number_format($row['calls']) }}</td>
                        <td class="text-right">@if ($canAssign && $agent->is_active)<x-ui.button size="sm" variant="ghost" :href="route('agent.assignments', ['agent' => $agent->id])">Assign</x-ui.button>@endif</td>
                    </tr>
                @empty
                    <tr><td colspan="5"><x-ui.empty-state icon="users" title="No agents found" description="Agent accounts are created in the admin console." /></td></tr>
                @endforelse
            </tbody>
        </x-ui.table>
        <x-ui.pagination :paginator="$agents" />
    </x-ui.card>
</div>
