<div class="grid gap-6 xl:grid-cols-3">
    <div class="space-y-6 xl:col-span-2">
        <x-ui.card title="Who takes this course" description="Rules keep themselves up to date: agents who join a company or role later get the course too." :padding="false">
            <ul class="divide-y divide-line">
                @forelse ($rules as $rule)
                    <li class="flex flex-wrap items-center gap-3 px-5 py-3" wire:key="rule-{{ $rule->id }}">
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-medium text-ink">{{ $rule->audience() }}</p>
                            <p class="text-xs text-subtle">
                                {{ $rule->is_required ? 'Required' : 'Optional' }} · {{ \App\Models\TrainingRule::PRIORITIES[$rule->priority] }} priority
                                @if ($rule->due_days) · {{ $rule->due_days }} days to complete @endif
                                @if ($rule->scope === 'company' && $rule->is_required) · {{ \Illuminate\Support\Str::before(\App\Models\TrainingRule::ENFORCEMENT[$rule->enforcement], ':') }} @endif
                            </p>
                        </div>
                        @if ($canAssign)
                            <x-ui.confirm id="rule-{{ $rule->id }}" title="Stop this rule?" confirm-label="Stop rule and withdraw unfinished" action="removeRule({{ $rule->id }}, true)">
                                <x-slot:trigger><x-ui.button size="sm" variant="ghost">Remove</x-ui.button></x-slot:trigger>
                                Nobody new gets the course through it. Agents who haven't finished will have it withdrawn; completions are kept.
                                <button type="button" x-on:click="open = false; $wire.removeRule({{ $rule->id }}, false)" class="mt-3 block text-sm text-brand-300 hover:text-brand-200">Stop the rule, but let agents keep the course</button>
                            </x-ui.confirm>
                        @endif
                    </li>
                @empty
                    <li><x-ui.empty-state icon="users" title="Not given to anyone yet" :description="$course->isPublished() ? 'Choose who should take this course.' : 'Publish the course first, then give it to agents.'" /></li>
                @endforelse
            </ul>
        </x-ui.card>

        <x-ui.card title="Agents" :padding="false">
            <x-ui.table>
                <thead><tr><th scope="col">Agent</th><th scope="col">Status</th><th scope="col">Progress</th><th scope="col">Due</th><th scope="col" class="text-right"><span class="sr-only">Actions</span></th></tr></thead>
                <tbody class="divide-y divide-line">
                    @forelse ($learners as $a)
                        <tr wire:key="ta-{{ $a->id }}">
                            <td>
                                <a href="{{ route('agent.team.show', $a->agent_user_id) }}" class="font-medium text-ink hover:text-brand-300">{{ $a->agent->name }}</a>
                                <p class="text-xs text-subtle">{{ $a->is_required ? 'Required' : 'Optional' }}@if ($a->organization) · {{ $a->organization->name }}@endif @if (! $a->assigned_by_user_id && ! $a->rule_id) · self-enrolled @endif</p>
                            </td>
                            <td><x-ui.badge :tone="$a->tone()">{{ $a->label() }}</x-ui.badge>@if ($a->status === 'revoked' && $a->revoke_reason)<p class="mt-1 text-xs text-subtle">{{ $a->revoke_reason }}</p>@endif</td>
                            <td class="text-sm text-muted">{{ $a->isDone() ? 100 : $a->progress_percent }}%@if ($a->best_score !== null) · {{ $a->best_score }}%@endif</td>
                            <td class="text-sm text-muted">{{ $a->due_at?->format('j M Y') ?? '–' }}</td>
                            <td class="text-right">
                                @if ($canAssign && $a->status !== 'revoked')
                                    <div class="flex justify-end gap-1">
                                        <x-ui.button size="sm" variant="ghost" wire:click="allowAttempt({{ $a->id }})" title="Give one more quiz attempt">+1 attempt</x-ui.button>
                                        <x-ui.button size="sm" variant="ghost" wire:click="startRevoke({{ $a->id }})">Remove</x-ui.button>
                                    </div>
                                @endif
                            </td>
                        </tr>
                        @if ($revoking === $a->id)
                            <tr wire:key="rv-{{ $a->id }}">
                                <td colspan="5">
                                    <form wire:submit="revoke" class="flex flex-wrap items-start gap-2">
                                        <div class="min-w-64 flex-1">
                                            <label for="rv-reason" class="sr-only">Reason</label>
                                            <input id="rv-reason" type="text" wire:model="revokeReason" class="sh-input" placeholder="Why remove this training for {{ $a->agent->name }}?" maxlength="500">
                                            @error('reason')<p class="mt-1 text-sm text-danger">{{ $message }}</p>@enderror
                                        </div>
                                        <x-ui.button type="submit" variant="danger">Remove training</x-ui.button>
                                        <x-ui.button variant="ghost" wire:click="cancel('revoking')">Cancel</x-ui.button>
                                    </form>
                                </td>
                            </tr>
                        @endif
                    @empty
                        <tr><td colspan="5"><x-ui.empty-state icon="users" title="No agents have this course yet" description="Agents appear here when a rule gives it to them or they start it themselves." /></td></tr>
                    @endforelse
                </tbody>
            </x-ui.table>
            <x-ui.pagination :paginator="$learners" />
        </x-ui.card>
    </div>

    @if ($canAssign && $course->isPublished())
        <div>
            <x-ui.card title="Give this course to">
                <form wire:submit="addRule" class="space-y-4">
                    <div>
                        <label for="r-scope" class="sh-label">Who</label>
                        <select id="r-scope" wire:model.live="ruleForm.scope" class="sh-input">
                            @foreach ($scopes as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                        </select>
                        @error('ruleForm.scope')<p class="mt-1 text-sm text-danger">{{ $message }}</p>@enderror
                    </div>
                    @if ($ruleForm['scope'] === 'role')
                        <div>
                            <label for="r-role" class="sh-label">Role</label>
                            <select id="r-role" wire:model="ruleForm.role" class="sh-input">
                                @foreach (\App\Models\TrainingRule::ROLES as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                            </select>
                        </div>
                    @elseif ($ruleForm['scope'] === 'company')
                        <div>
                            <label for="r-co" class="sh-label">Company</label>
                            <select id="r-co" wire:model="ruleForm.company" class="sh-input">
                                <option value="">Choose…</option>
                                @foreach ($ruleCompanies as $ulid => $name)<option value="{{ $ulid }}">{{ $name }}</option>@endforeach
                            </select>
                            @error('ruleForm.company')<p class="mt-1 text-sm text-danger">{{ $message }}</p>@enderror
                        </div>
                    @elseif ($ruleForm['scope'] === 'agent')
                        <div>
                            <label for="r-agent" class="sh-label">Agent</label>
                            <select id="r-agent" wire:model="ruleForm.agent" class="sh-input">
                                <option value="">Choose…</option>
                                @foreach ($ruleAgents as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                            </select>
                            @error('ruleForm.agent')<p class="mt-1 text-sm text-danger">{{ $message }}</p>@enderror
                        </div>
                    @endif
                    <label class="flex items-center gap-2 text-sm text-ink"><input type="checkbox" wire:model.live="ruleForm.is_required"> Required</label>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label for="r-due" class="sh-label">Days to complete</label>
                            <input id="r-due" type="number" min="1" max="365" wire:model="ruleForm.due_days" class="sh-input" placeholder="No due date">
                            @error('ruleForm.due_days')<p class="mt-1 text-sm text-danger">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label for="r-pri" class="sh-label">Priority</label>
                            <select id="r-pri" wire:model="ruleForm.priority" class="sh-input">
                                @foreach (\App\Models\TrainingRule::PRIORITIES as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                            </select>
                        </div>
                    </div>
                    @if ($ruleForm['scope'] === 'company' && $ruleForm['is_required'])
                        <div>
                            <label for="r-enf" class="sh-label">Until it's done</label>
                            <select id="r-enf" wire:model="ruleForm.enforcement" class="sh-input">
                                @foreach (\App\Models\TrainingRule::ENFORCEMENT as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                            </select>
                            <p class="mt-1 text-xs text-subtle">How strictly this company's readiness is enforced for agents who haven't finished.</p>
                        </div>
                    @endif
                    @error('rule.scope')<p class="text-sm text-danger">{{ $message }}</p>@enderror
                    <x-ui.button type="submit" class="w-full">Give course</x-ui.button>
                </form>
            </x-ui.card>
        </div>
    @endif
</div>
