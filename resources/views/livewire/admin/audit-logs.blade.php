<div>
    <x-ui.page-header title="Audit log"
        description="Who changed what, and when. Times are UTC. Entries older than {{ config('audit.retention_days') }} days are removed automatically." />

    <div class="mb-4 grid gap-3 md:grid-cols-[1fr_12rem_12rem_9.5rem_9.5rem] md:items-end">
        <div>
            <label for="audit-search" class="sh-label">Search</label>
            <input id="audit-search" type="search" wire:model.live.debounce.350ms="search" class="sh-input" placeholder="Person, email, call ID, business…">
        </div>
        <div>
            <label for="audit-action" class="sh-label">Action</label>
            <select id="audit-action" wire:model.live="action" class="sh-input">
                <option value="">All actions</option>
                @foreach ($actions as $option)
                    <option value="{{ $option }}">{{ $option }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="audit-org" class="sh-label">Business</label>
            <select id="audit-org" wire:model.live="organization" class="sh-input">
                <option value="">All businesses</option>
                @foreach ($organizations as $org)
                    <option value="{{ $org->id }}">{{ $org->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="audit-from" class="sh-label">From</label>
            <input id="audit-from" type="date" wire:model.live="from" class="sh-input">
        </div>
        <div>
            <label for="audit-to" class="sh-label">To</label>
            <input id="audit-to" type="date" wire:model.live="to" class="sh-input">
        </div>
    </div>

    <x-ui.card :padding="false">
        @if ($logs->isEmpty())
            <x-ui.empty-state icon="shield" title="No matching entries" description="Sign-ins, user changes, call changes and assignment changes appear here." />
        @else
            <ul class="divide-y divide-line">
                @foreach ($logs as $log)
                    <li x-data="{ open: false }" wire:key="audit-{{ $log->id }}" class="px-5 py-3">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="text-sm text-ink">
                                    <x-ui.badge :tone="str_contains($log->action, 'failed') || str_contains($log->action, 'blocked') ? 'danger' : 'brand'">{{ $log->action }}</x-ui.badge>
                                    <span class="ml-1 font-medium">{{ $log->subject_label ?? '—' }}</span>
                                    @if ($log->organization)
                                        <span class="text-muted">· {{ $log->organization->name }}</span>
                                    @endif
                                </p>
                                <p class="mt-1 text-xs text-subtle">
                                    {{ $log->actor?->name ?? ucfirst($log->actor_type) }}
                                    @if ($log->actor) ({{ $log->actor->email }}) @endif
                                    @if ($log->impersonator)<span class="block text-xs text-amber-300">by {{ $log->impersonator->name }}, viewing as them</span>@endif
                                    · {{ $log->created_at->format('M j, Y H:i:s') }}
                                    @if ($log->ip_address) · {{ $log->ip_address }} @endif
                                </p>
                            </div>
                            @if ($log->old_values || $log->new_values)
                                <button type="button" x-on:click="open = ! open" :aria-expanded="open.toString()" class="text-xs font-medium text-brand-300 hover:text-brand-400">
                                    <span x-text="open ? 'Hide details' : 'Show details'">Show details</span>
                                </button>
                            @endif
                        </div>
                        @if ($log->old_values || $log->new_values)
                            <div x-show="open" x-cloak class="mt-3 overflow-x-auto rounded-lg bg-surface-2 p-3">
                                <table class="text-xs">
                                    <thead><tr><th class="pr-6 text-left font-semibold text-subtle">Field</th><th class="pr-6 text-left font-semibold text-subtle">Before</th><th class="text-left font-semibold text-subtle">After</th></tr></thead>
                                    <tbody>
                                        @foreach (array_unique(array_merge(array_keys($log->old_values ?? []), array_keys($log->new_values ?? []))) as $field)
                                            <tr>
                                                <td class="pr-6 align-top text-muted">{{ $field }}</td>
                                                <td class="pr-6 align-top text-red-300">{{ is_scalar($log->old_values[$field] ?? null) ? var_export($log->old_values[$field], true) : json_encode($log->old_values[$field] ?? null) }}</td>
                                                <td class="align-top text-emerald-300">{{ is_scalar($log->new_values[$field] ?? null) ? var_export($log->new_values[$field], true) : json_encode($log->new_values[$field] ?? null) }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif
                    </li>
                @endforeach
            </ul>
            <x-ui.pagination :paginator="$logs" />
        @endif
    </x-ui.card>
</div>
