<div wire:poll.60s>
    <x-ui.page-header title="Agent workspace" description="Pick the business you're answering for. Everything you need for the call is on one screen.">
        <x-slot:actions>
            <span class="rounded-lg bg-surface-2 px-3 py-2 text-sm text-muted ring-1 ring-line">{{ $myCallsToday }} {{ \Illuminate\Support\Str::plural('call', $myCallsToday) }} logged today</span>
        </x-slot:actions>
    </x-ui.page-header>

    @if ($escalations->where('status', \App\Enums\EscalationStatus::Open)->where('priority', \App\Enums\EscalationPriority::Urgent)->isNotEmpty())
        <x-ui.alert tone="danger" class="mb-6" title="Urgent escalations are waiting on businesses">
            The businesses have been alerted. If one stays unanswered, tell your supervisor.
        </x-ui.alert>
    @endif

    <div class="grid gap-6 xl:grid-cols-3">
        <div class="xl:col-span-2">
            <x-ui.card :padding="false" title="Your businesses">
                <x-slot:actions>
                    <label for="biz-search" class="sr-only">Find a business</label>
                    <input id="biz-search" type="search" wire:model.live.debounce.250ms="search" class="sh-input w-56 py-1.5" placeholder="Find a business…" autocomplete="off" autofocus>
                </x-slot:actions>
                @if ($businesses->isEmpty())
                    <x-ui.empty-state icon="building" title="{{ $search !== '' ? 'No business matches' : 'No businesses assigned yet' }}"
                        description="{{ $search !== '' ? 'Try another name.' : 'Ask your supervisor to assign you to the businesses you answer for.' }}" />
                @else
                    <ul class="divide-y divide-line" role="list">
                        @foreach ($businesses as $b)
                            <li wire:key="b-{{ $b['organization']->ulid }}">
                                <a href="{{ route('agent.businesses.show', $b['organization']) }}" class="flex items-center gap-4 px-5 py-3 hover:bg-surface-2">
                                    <span @class(['size-2.5 shrink-0 rounded-full', 'bg-emerald-400' => $b['status']['open'], 'bg-subtle' => ! $b['status']['open']]) aria-hidden="true"></span>
                                    <span class="min-w-0 flex-1">
                                        <span class="block truncate font-medium text-ink">{{ $b['organization']->name }}</span>
                                        <span class="block truncate text-xs text-muted">{{ $b['status']['label'] }} · {{ $b['local_time'] }} local</span>
                                    </span>
                                    @if ($b['escalations'] > 0)<x-ui.badge tone="danger">{{ $b['escalations'] }} escalated</x-ui.badge>@endif
                                    @if ($b['follow_ups'] > 0)<x-ui.badge tone="warning">{{ $b['follow_ups'] }} call-back{{ $b['follow_ups'] === 1 ? '' : 's' }}</x-ui.badge>@endif
                                    <x-ui.icon name="chevron-right" class="size-4 text-subtle" />
                                </a>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-ui.card>

            <x-ui.card class="mt-6" :padding="false" title="Your recent calls">
                @forelse ($recentCalls as $call)
                    <div class="flex items-center gap-3 border-b border-line px-5 py-3 last:border-0 text-sm">
                        <span class="w-36 shrink-0 font-mono text-xs text-subtle">{{ $call->call_id }}</span>
                        <span class="min-w-0 flex-1 truncate text-ink">{{ \App\Models\CallLog::display($call->caller_name) }} <span class="text-muted">· {{ $call->organization->name ?? 'Unassigned' }}</span></span>
                        <x-ui.badge :tone="$call->statusTone()">{{ $call->statusLabel() }}</x-ui.badge>
                        <span class="w-24 text-right text-xs text-subtle">{{ $call->created_at->diffForHumans(null, true) }}</span>
                    </div>
                @empty
                    <p class="px-5 py-6 text-sm text-muted">Calls you log appear here.</p>
                @endforelse
            </x-ui.card>
        </div>

        <div class="space-y-6">
            <x-ui.card :padding="false" title="Escalations">
                @forelse ($escalations as $e)
                    <a href="{{ route('agent.businesses.show', $e->organization) }}" class="block border-b border-line px-5 py-3 last:border-0 hover:bg-surface-2">
                        <span class="flex items-center gap-2"><x-ui.badge :tone="$e->priority->tone()">{{ $e->priority->label() }}</x-ui.badge><x-ui.badge :tone="$e->status->tone()">{{ $e->status->label() }}</x-ui.badge></span>
                        <span class="mt-1 block truncate text-sm text-ink">{{ $e->type->label() }} · {{ $e->organization->name }}</span>
                        <span class="block truncate text-xs text-muted">{{ $e->reason }} · {{ $e->created_at->diffForHumans() }}</span>
                    </a>
                @empty
                    <p class="px-5 py-6 text-sm text-muted">No open escalations.</p>
                @endforelse
            </x-ui.card>

            <x-ui.card :padding="false" title="Call-backs due">
                @forelse ($callbacks as $task)
                    <a href="{{ route('agent.businesses.show', $task->organization) }}" class="block border-b border-line px-5 py-3 last:border-0 hover:bg-surface-2">
                        <span class="block truncate text-sm text-ink">{{ $task->title }}</span>
                        <span @class(['block text-xs', 'font-semibold text-danger' => $task->isOverdue(), 'text-muted' => ! $task->isOverdue()])>
                            {{ $task->organization->name }} · {{ $task->due_at ? ($task->isOverdue() ? 'overdue, was due ' : 'due ').$task->due_at->setTimezone($task->organization->timezoneOrDefault())->calendar() : 'no due time' }}
                        </span>
                    </a>
                @empty
                    <p class="px-5 py-6 text-sm text-muted">No call-backs waiting.</p>
                @endforelse
            </x-ui.card>

            <x-ui.card :padding="false" title="Next 24 hours">
                @forelse ($appointments as $a)
                    <a href="{{ route('agent.businesses.show', $a->organization) }}" class="flex items-start gap-3 border-b border-line px-5 py-3 last:border-0 hover:bg-surface-2">
                        <span class="mt-0.5 rounded-md bg-brand-500/15 px-2 py-1 text-xs font-semibold tabular-nums text-brand-300">{{ $a->starts_at->setTimezone($a->organization->timezoneOrDefault())->format('D g:i A') }}</span>
                        <span class="min-w-0 flex-1"><span class="block truncate text-sm text-ink">{{ $a->title }}</span><span class="block truncate text-xs text-muted">{{ $a->organization->name }}</span></span>
                    </a>
                @empty
                    <p class="px-5 py-6 text-sm text-muted">Nothing booked in the next day.</p>
                @endforelse
            </x-ui.card>
        </div>
    </div>
</div>
