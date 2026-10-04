<div>
    <x-ui.page-header title="Escalations" description="Calls our team flagged for you: emergencies, complaints and decisions only you can make." />

    <div class="mb-4 flex gap-1 overflow-x-auto border-b border-line" role="tablist" aria-label="Escalation views">
        @foreach ($views as $key => $label)
            <button type="button" role="tab" wire:click="$set('view', '{{ $key }}')" aria-selected="{{ $view === $key ? 'true' : 'false' }}"
                @class([
                    '-mb-px inline-flex items-center gap-2 whitespace-nowrap border-b-2 px-3 py-2 text-sm font-medium transition-colors',
                    'border-brand-400 text-ink' => $view === $key,
                    'border-transparent text-muted hover:text-ink' => $view !== $key,
                ])>
                {{ $label }}
                @if ($key === 'active' && $activeCount > 0)<x-ui.badge tone="danger">{{ $activeCount }}</x-ui.badge>@endif
            </button>
        @endforeach
    </div>

    <div class="relative">
        <div wire:loading.flex wire:target="view,nextPage,previousPage" class="absolute inset-0 z-10 items-start justify-center bg-canvas/40 pt-16">
            <span class="rounded-full bg-surface-2 px-3 py-1 text-xs text-muted ring-1 ring-line">Loading…</span>
        </div>

        @if ($escalations->isEmpty())
            <x-ui.card>
                @if ($view === 'resolved')
                    <x-ui.empty-state icon="shield" title="Nothing resolved yet" description="Resolved escalations and what was done about them are kept here." />
                @else
                    <x-ui.empty-state icon="shield" title="Nothing needs you right now"
                        description="When a caller has an emergency, a complaint or something only you can decide, our team escalates it here and alerts you immediately." />
                @endif
            </x-ui.card>
        @else
            <ul class="space-y-3" role="list">
                @foreach ($escalations as $escalation)
                    @php($urgentOpen = $escalation->priority === \App\Enums\EscalationPriority::Urgent && $escalation->status === \App\Enums\EscalationStatus::Open)
                    <li wire:key="e-{{ $escalation->ulid }}" id="escalation-{{ $escalation->ulid }}"
                        @class([
                            'rounded-2xl border bg-surface p-5',
                            'border-red-500/50' => $urgentOpen,
                            'border-line' => ! $urgentOpen,
                            'ring-2 ring-brand-400' => $focus === $escalation->ulid,
                        ])>
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <x-ui.badge :tone="$escalation->priority->tone()">{{ $escalation->priority->label() }}</x-ui.badge>
                                    <span class="text-sm font-semibold text-ink">{{ $escalation->type->label() }}</span>
                                    <x-ui.badge :tone="$escalation->status->tone()">{{ $escalation->status->label() }}</x-ui.badge>
                                </div>
                                <p class="mt-2 text-ink">{{ $escalation->reason }}</p>
                                @if ($escalation->details)
                                    <p class="mt-1 whitespace-pre-line text-sm text-muted">{{ $escalation->details }}</p>
                                @endif
                                <p class="mt-2 flex flex-wrap gap-x-3 gap-y-1 text-xs text-subtle">
                                    <span title="{{ $escalation->created_at->setTimezone($timezone)->toDayDateTimeString() }}">Raised {{ $escalation->created_at->diffForHumans() }}</span>
                                    @if ($escalation->customer)
                                        <a href="{{ route('app.customers.show', $escalation->customer->ulid) }}" class="hover:text-ink">{{ $escalation->customer->fullName() }}</a>
                                    @endif
                                    @if ($escalation->call)
                                        <a href="{{ route('app.calls.show', $escalation->call->call_id) }}" class="hover:text-ink">Call {{ $escalation->call->call_id }}</a>
                                    @endif
                                    @if ($escalation->acknowledgedBy && $escalation->status === \App\Enums\EscalationStatus::Acknowledged)
                                        <span>Acknowledged by {{ $escalation->acknowledgedBy->name }} {{ $escalation->acknowledged_at?->diffForHumans() }}</span>
                                    @endif
                                </p>
                            </div>

                            @if ($canResolve && $escalation->status->isActive())
                                <div class="flex shrink-0 flex-wrap items-center gap-2">
                                    @if ($escalation->status === \App\Enums\EscalationStatus::Open)
                                        <x-ui.button variant="secondary" size="sm" wire:click="acknowledge('{{ $escalation->ulid }}')">I'm on it</x-ui.button>
                                    @endif
                                    <x-ui.button size="sm" wire:click="startResolve('{{ $escalation->ulid }}')">Resolve</x-ui.button>
                                </div>
                            @endif
                        </div>

                        @if ($escalation->status->isActive())
                            <div class="mt-4 flex flex-wrap items-center gap-2 border-t border-line pt-3 text-sm">
                                <label for="assign-{{ $escalation->ulid }}" class="text-subtle">Handled by</label>
                                @if ($canResolve)
                                    <select id="assign-{{ $escalation->ulid }}" class="sh-input w-auto py-1"
                                        x-on:change="$wire.assign('{{ $escalation->ulid }}', $event.target.value)">
                                        <option value="">Nobody yet</option>
                                        @foreach ($team as $member)
                                            <option value="{{ $member->id }}" @selected($escalation->assigned_to_user_id === $member->id)>{{ $member->name }}{{ $member->id === auth()->id() ? ' (me)' : '' }}</option>
                                        @endforeach
                                    </select>
                                @else
                                    <span class="text-ink">{{ $escalation->assignee->name ?? 'Nobody yet' }}</span>
                                @endif
                            </div>
                        @else
                            <div class="mt-4 rounded-xl bg-surface-2 px-4 py-3 text-sm ring-1 ring-line">
                                <p class="text-xs font-medium uppercase tracking-wide text-subtle">Resolved {{ $escalation->resolved_at?->setTimezone($timezone)->toDayDateTimeString() }}{{ $escalation->resolvedBy ? ' by '.$escalation->resolvedBy->name : '' }}</p>
                                <p class="mt-1 whitespace-pre-line text-ink">{{ $escalation->resolution_notes }}</p>
                            </div>
                        @endif
                    </li>
                @endforeach
            </ul>
            <div class="mt-4"><x-ui.pagination :paginator="$escalations" /></div>
        @endif
    </div>

    @if ($focus !== '')
        <div x-data x-init="$nextTick(() => document.getElementById('escalation-{{ $focus }}')?.scrollIntoView({ block: 'center' }))"></div>
    @endif

    @if ($canResolve)
        <div x-data x-show="$wire.resolving !== null" x-cloak
            x-on:keydown.escape.window="$wire.set('resolving', null)"
            class="fixed inset-0 z-50 flex items-center justify-center p-4" role="dialog" aria-modal="true" aria-labelledby="resolve-title">
            <div class="fixed inset-0 bg-black/60" x-on:click="$wire.set('resolving', null)"></div>
            <form wire:submit="resolve" class="relative w-full max-w-lg rounded-2xl border border-line-strong bg-surface p-6 shadow-2xl">
                <h2 id="resolve-title" class="text-lg font-semibold text-ink">Resolve escalation</h2>
                @if ($resolvingEscalation)
                    <p class="mt-1 text-sm text-muted">{{ $resolvingEscalation->type->label() }} · {{ $resolvingEscalation->reason }}</p>
                @endif
                <div class="mt-5">
                    <label for="resolution" class="sh-label">What was done?</label>
                    <textarea id="resolution" wire:model="resolutionNotes" rows="4" class="sh-input" placeholder="e.g. Called Maria back, sent a plumber at 3 PM."></textarea>
                    @error('resolutionNotes') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                </div>
                <div class="mt-6 flex justify-end gap-2">
                    <x-ui.button variant="secondary" x-on:click="$wire.set('resolving', null)">Cancel</x-ui.button>
                    <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="resolve">Mark resolved</x-ui.button>
                </div>
            </form>
        </div>
    @endif
</div>
