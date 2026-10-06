<div>
    <x-ui.page-header title="Agent assignments" description="Agents can only see and work for the companies listed here. Every change is recorded.">
        @if ($canCreate)
            <x-slot:actions><x-ui.button icon="users" wire:click="startCreate">Assign an agent</x-ui.button></x-slot:actions>
        @endif
    </x-ui.page-header>

    @if ($reviewCount > 0 && $status !== 'review')
        <x-ui.alert tone="warning" class="mb-6" title="{{ $reviewCount }} {{ \Illuminate\Support\Str::plural('assignment', $reviewCount) }} to review">
            These were created automatically when every agent served every company. Confirm the ones that are right and end the rest.
            <button type="button" wire:click="$set('status', 'review')" class="font-semibold underline underline-offset-2">Review them</button>
        </x-ui.alert>
    @endif

    @if ($creating && $canCreate)
        <x-ui.card title="Assign an agent to a company" class="mb-6">
            <form wire:submit="assign" class="space-y-4">
                <div class="grid gap-4 md:grid-cols-2">
                    <div>
                        <label for="as-agent" class="sh-label">Agent</label>
                        <select id="as-agent" wire:model.live="form.agent" class="sh-input">
                            <option value="">Choose an agent…</option>
                            @foreach ($agents as $a)<option value="{{ $a->id }}">{{ $a->name }} ({{ $a->email }})</option>@endforeach
                        </select>
                        @error('form.agent') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="as-company" class="sh-label">Company</label>
                        <select id="as-company" wire:model.live="form.company" class="sh-input">
                            <option value="">Choose a company…</option>
                            @foreach ($companies as $c)<option value="{{ $c->ulid }}">{{ $c->name }}</option>@endforeach
                        </select>
                        @error('form.company') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                    </div>
                </div>

                @if ($preview)
                    <div class="rounded-xl bg-surface-2 p-4 ring-1 ring-line" aria-live="polite">
                        <p class="text-sm font-semibold text-ink">Before you confirm</p>
                        <dl class="mt-3 grid gap-3 text-sm sm:grid-cols-4">
                            <div><dt class="text-xs text-subtle">Current companies</dt><dd class="text-ink">{{ $preview['companies'] }}</dd></div>
                            <div><dt class="text-xs text-subtle">Open tasks</dt><dd class="text-ink">{{ $preview['open_tasks'] }}</dd></div>
                            <div><dt class="text-xs text-subtle">Availability</dt><dd class="text-ink">{{ $preview['on_shift'] ? 'On shift now' : ($preview['next_shift'] ? 'Next shift '.$preview['next_shift']->format('D j M, g:i A') : 'No shift planned') }}</dd></div>
                            <div><dt class="text-xs text-subtle">Training ready</dt><dd @class(['font-medium', 'text-emerald-300' => $preview['readiness']['ready'], 'text-amber-300' => ! $preview['readiness']['ready']])>{{ $preview['readiness']['ready'] ? 'Yes' : 'No' }}</dd></div>
                        </dl>
                        @foreach ($preview['blocking'] as $problem)<p class="mt-2 text-sm text-danger">{{ $problem }}</p>@endforeach
                        @if ($preview['existing'])
                            <p class="mt-2 text-sm text-danger">{{ $selectedAgent->name }} is already {{ strtolower(\App\Models\AgentAssignment::label($preview['existing']->effectiveStatus())) }} for {{ $selectedCompany->name }}.</p>
                        @endif
                        @if (! $preview['readiness']['ready'])
                            <p class="mt-2 text-sm text-amber-300">{{ $selectedAgent->name }} has {{ count($preview['readiness']['missing']) }} required training {{ \Illuminate\Support\Str::plural('item', count($preview['readiness']['missing'])) }} outstanding for this company: {{ implode(', ', $preview['readiness']['missing']) }}. They'll be assigned automatically.</p>
                        @endif
                    </div>
                @endif

                <div class="grid gap-4 md:grid-cols-4">
                    <div>
                        <label for="as-start" class="sh-label">Start <span class="font-normal text-subtle">(empty = now)</span></label>
                        <input id="as-start" type="date" wire:model="form.starts_on" class="sh-input" min="{{ now()->toDateString() }}">
                        @error('form.starts_at') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="as-start-time" class="sh-label">Start time</label>
                        <input id="as-start-time" type="time" wire:model="form.starts_at" class="sh-input">
                    </div>
                    <div>
                        <label for="as-end" class="sh-label">End <span class="font-normal text-subtle">(optional)</span></label>
                        <input id="as-end" type="date" wire:model="form.ends_on" class="sh-input">
                        @error('form.ends_at') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="as-type" class="sh-label">Type</label>
                        <select id="as-type" wire:model="form.type" class="sh-input">
                            @foreach ($types as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                        </select>
                    </div>
                </div>
                <div>
                    <label for="as-notes" class="sh-label">Notes <span class="font-normal text-subtle">(optional)</span></label>
                    <input id="as-notes" type="text" wire:model="form.notes" class="sh-input" maxlength="2000" placeholder="e.g. Covering for Maria during her leave">
                </div>
                <div class="flex gap-2">
                    <x-ui.button type="submit" :disabled="$preview && ($preview['blocking'] !== [] || $preview['existing'])">Confirm assignment</x-ui.button>
                    <x-ui.button variant="ghost" wire:click="$set('creating', false)">Cancel</x-ui.button>
                </div>
            </form>
        </x-ui.card>
    @endif

    <div class="mb-4 flex flex-wrap items-end gap-3">
        <div class="inline-flex rounded-lg bg-surface-2 p-1 ring-1 ring-line" role="tablist" aria-label="Show">
            @foreach (['current' => 'Current', 'scheduled' => 'Scheduled', 'suspended' => 'Suspended', 'ended' => 'Ended', 'review' => 'Needs review', 'all' => 'All'] as $value => $label)
                <button type="button" role="tab" aria-selected="{{ $status === $value ? 'true' : 'false' }}" wire:click="$set('status', '{{ $value }}')"
                    @class(['rounded-md px-3 py-1.5 text-sm font-medium', 'bg-surface-3 text-ink' => $status === $value, 'text-muted hover:text-ink' => $status !== $value])>{{ $label }}</button>
            @endforeach
        </div>
        <div>
            <label for="f-company" class="sr-only">Company</label>
            <select id="f-company" wire:model.live="company" class="sh-input py-1.5">
                <option value="">All my companies</option>
                @foreach ($companies as $c)<option value="{{ $c->ulid }}">{{ $c->name }}</option>@endforeach
            </select>
        </div>
        <div>
            <label for="f-search" class="sr-only">Agent</label>
            <input id="f-search" type="search" wire:model.live.debounce.350ms="search" class="sh-input py-1.5" placeholder="Agent name…">
        </div>
    </div>

    <x-ui.card :padding="false">
        @if ($assignments->isEmpty())
            <x-ui.empty-state icon="users" title="No assignments here" description="Assign agents to the companies they should serve." />
        @else
            <x-ui.table>
                <thead><tr><th scope="col">Agent</th><th scope="col">Company</th><th scope="col">Status</th><th scope="col">Dates</th><th scope="col">By</th><th scope="col" class="text-right"><span class="sr-only">Actions</span></th></tr></thead>
                <tbody class="divide-y divide-line">
                    @foreach ($assignments as $a)
                        @php($effective = $a->effectiveStatus())
                        @php($tz = $a->organization->timezoneOrDefault())
                        <tr wire:key="as-{{ $a->id }}">
                            <td><a href="{{ route('agent.team.show', $a->agent_user_id) }}" class="font-medium text-ink hover:text-brand-300">{{ $a->agent->name ?? 'Former agent' }}</a>@if (! ($a->agent->is_active ?? true))<p class="text-xs text-danger">Account off</p>@endif</td>
                            <td class="text-ink">{{ $a->organization->name }}</td>
                            <td>
                                <x-ui.badge :tone="\App\Models\AgentAssignment::tone($effective)">{{ \App\Models\AgentAssignment::label($effective) }}</x-ui.badge>
                                @if ($a->needsReview())<p class="mt-1 text-xs text-amber-300">Assigned automatically</p>@endif
                                @if ($a->end_reason && in_array($effective, ['ended', 'revoked', 'suspended'], true))<p class="mt-1 text-xs text-subtle">{{ $a->end_reason }}</p>@endif
                            </td>
                            <td class="whitespace-nowrap text-sm text-muted">
                                {{ $a->starts_at?->setTimezone($tz)->format('j M Y') }} → {{ $a->ends_at ? $a->ends_at->setTimezone($tz)->format('j M Y') : 'open-ended' }}
                                <p class="text-xs text-subtle">{{ \App\Models\AgentAssignment::TYPES[$a->assignment_type] ?? $a->assignment_type }}</p>
                            </td>
                            <td class="text-sm text-muted">{{ $a->assignedBy->name ?? 'System' }}@if ($a->endedBy)<p class="text-xs text-subtle">ended by {{ $a->endedBy->name }}</p>@endif</td>
                            <td class="whitespace-nowrap text-right">
                                @if ($a->needsReview())<x-ui.button size="sm" variant="secondary" wire:click="confirmReview({{ $a->id }})">Confirm</x-ui.button>@endif
                                @if (in_array($effective, ['active', 'scheduled'], true))
                                    @if ($effective === 'active')<x-ui.button size="sm" variant="ghost" wire:click="act({{ $a->id }}, 'reassign')">Reassign</x-ui.button>
                                    <x-ui.button size="sm" variant="ghost" wire:click="act({{ $a->id }}, 'suspend')">Suspend</x-ui.button>@endif
                                    <x-ui.button size="sm" variant="ghost" wire:click="act({{ $a->id }}, 'end')">End</x-ui.button>
                                    <x-ui.button size="sm" variant="ghost" wire:click="act({{ $a->id }}, 'revoke')">Revoke</x-ui.button>
                                @elseif ($effective === 'suspended')
                                    <x-ui.button size="sm" variant="secondary" wire:click="resume({{ $a->id }})">Resume</x-ui.button>
                                    <x-ui.button size="sm" variant="ghost" wire:click="act({{ $a->id }}, 'end')">End</x-ui.button>
                                @endif
                            </td>
                        </tr>
                        @if ($acting === $a->id)
                            <tr wire:key="as-act-{{ $a->id }}">
                                <td colspan="6" class="bg-surface-2/60">
                                    <form wire:submit="confirmAction" class="flex flex-wrap items-end gap-3">
                                        @if ($action === 'reassign')
                                            <div>
                                                <label for="re-to" class="sh-label">Give {{ $a->organization->name }} to</label>
                                                <select id="re-to" wire:model="reassignTo" class="sh-input">
                                                    <option value="">Choose an agent…</option>
                                                    @foreach ($agents->where('id', '!=', $a->agent_user_id) as $other)<option value="{{ $other->id }}">{{ $other->name }}</option>@endforeach
                                                </select>
                                            </div>
                                        @endif
                                        <div class="min-w-64 flex-1">
                                            <label for="re-reason" class="sh-label">Reason {{ $action === 'revoke' ? '(required)' : '(recorded in the history)' }}</label>
                                            <input id="re-reason" type="text" wire:model="reason" class="sh-input" maxlength="500" placeholder="{{ $action === 'reassign' ? 'Account reassigned' : 'e.g. Account reassigned' }}">
                                            @error('reason') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                                        </div>
                                        <x-ui.button type="submit" :variant="$action === 'revoke' ? 'danger' : 'primary'">{{ ['end' => 'End assignment', 'revoke' => 'Revoke now', 'suspend' => 'Suspend', 'reassign' => 'Reassign'][$action] }}</x-ui.button>
                                        <x-ui.button variant="ghost" wire:click="$set('acting', null)">Cancel</x-ui.button>
                                    </form>
                                </td>
                            </tr>
                        @endif
                    @endforeach
                </tbody>
            </x-ui.table>
            <x-ui.pagination :paginator="$assignments" />
        @endif
    </x-ui.card>
</div>
