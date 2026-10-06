<div>
    <div class="mb-6">
        <a href="{{ route('agent.team') }}" class="inline-flex items-center gap-1 text-sm text-muted hover:text-ink"><x-ui.icon name="arrow-left" class="size-4" /> Team</a>
        <div class="mt-2 flex flex-wrap items-center gap-3">
            <h1 class="text-2xl font-semibold text-ink">{{ $agent->name }}</h1>
            <x-ui.badge :tone="$agent->is_active ? 'success' : 'danger'">{{ $agent->is_active ? 'Active' : 'Account off' }}</x-ui.badge>
            @if ($canAssign && $agent->is_active)<x-ui.button size="sm" class="ml-auto" :href="route('agent.assignments', ['agent' => $agent->id])">Assign to a company</x-ui.button>@endif
        </div>
        <p class="mt-1 text-sm text-muted">{{ $agent->email }} · {{ $agent->getRoleNames()->map(fn ($r) => str_replace('_', ' ', $r))->implode(', ') }}</p>
    </div>

    <div class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_22rem]">
        <div class="space-y-6">
            <x-ui.card title="Companies" description="{{ $otherCompanies > 0 ? 'Also serves '.$otherCompanies.' company(ies) you don\'t manage.' : 'Companies you manage.' }}" :padding="false">
                @if ($current->isEmpty())
                    <p class="px-5 py-4 text-sm text-muted">Not currently assigned to any of your companies.</p>
                @else
                    <ul class="divide-y divide-line">
                        @foreach ($current as $a)
                            @php($r = $readiness[$a->organization_id] ?? null)
                            <li class="flex flex-wrap items-center gap-3 px-5 py-3">
                                <span class="flex-1 font-medium text-ink">{{ $a->organization->name }}</span>
                                <span class="text-sm text-subtle">since {{ $a->starts_at?->setTimezone($a->organization->timezoneOrDefault())->format('j M Y') }}@if ($a->ends_at) · until {{ $a->ends_at->setTimezone($a->organization->timezoneOrDefault())->format('j M Y') }}@endif</span>
                                @if ($r)<x-ui.badge :tone="$r['ready'] ? 'success' : 'warning'">{{ $r['ready'] ? 'Training ready' : count($r['missing']).' training to finish' }}</x-ui.badge>@endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-ui.card>

            @if ($training !== null)
                <x-ui.card title="Training" :description="$training['required'] ? $training['required_done'].' of '.$training['required'].' required courses completed' : 'No required training'" :padding="false">
                    @if ($training['rows']->isEmpty())
                        <x-ui.empty-state icon="sparkles" title="No training yet" description="Training given to this agent appears here." />
                    @else
                        <x-ui.table>
                            <thead><tr><th scope="col">Course</th><th scope="col">Progress</th><th scope="col">Due</th><th scope="col">Status</th></tr></thead>
                            <tbody class="divide-y divide-line">
                                @foreach ($training['rows'] as $t)
                                    <tr wire:key="tr-{{ $t->id }}">
                                        <td><p class="text-sm text-ink">{{ $t->course->title }}</p><p class="text-xs text-subtle">{{ $t->course->organization?->name ?? 'All agents' }} · {{ $t->is_required ? 'required' : 'optional' }}</p></td>
                                        <td class="text-sm text-muted">{{ $t->isDone() ? 100 : $t->progress_percent }}%@if ($t->best_score !== null) · {{ $t->best_score }}%@endif</td>
                                        <td class="text-sm text-muted">{{ $t->due_at?->format('j M Y') ?? '–' }}</td>
                                        <td><x-ui.badge :tone="$t->tone()">{{ $t->label() }}</x-ui.badge></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </x-ui.table>
                    @endif
                    @if ($training['certificates']->isNotEmpty())
                        <div class="border-t border-line px-5 py-3">
                            <p class="text-xs font-semibold uppercase tracking-wider text-subtle">Certifications</p>
                            <ul class="mt-2 flex flex-wrap gap-2">
                                @foreach ($training['certificates'] as $cert)
                                    <li><a href="{{ route('agent.university.certificate', $cert->ulid) }}"><x-ui.badge :tone="$cert->tone()">{{ $cert->name }} · {{ $cert->label() }}</x-ui.badge></a></li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                </x-ui.card>
            @endif

            <x-ui.card title="Assignment history" :padding="false">
                @if ($history->isEmpty())
                    <p class="px-5 py-4 text-sm text-muted">No history with your companies.</p>
                @else
                    <x-ui.table>
                        <thead><tr><th scope="col">Company</th><th scope="col">Period</th><th scope="col">Status</th><th scope="col">Reason</th></tr></thead>
                        <tbody class="divide-y divide-line">
                            @foreach ($history as $a)
                                @php($tz = $a->organization->timezoneOrDefault())
                                <tr>
                                    <td class="text-ink">{{ $a->organization->name }}</td>
                                    <td class="whitespace-nowrap text-sm text-muted">{{ $a->starts_at?->setTimezone($tz)->format('j M Y') }} → {{ $a->ends_at?->setTimezone($tz)->format('j M Y') ?? 'now' }}
                                        <p class="text-xs text-subtle">by {{ $a->assignedBy->name ?? 'system' }}@if ($a->endedBy), ended by {{ $a->endedBy->name }}@endif</p></td>
                                    <td><x-ui.badge :tone="\App\Models\AgentAssignment::tone($a->effectiveStatus())">{{ \App\Models\AgentAssignment::label($a->effectiveStatus()) }}</x-ui.badge></td>
                                    <td class="text-sm text-muted">{{ $a->end_reason ?? '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </x-ui.table>
                @endif
            </x-ui.card>

            <x-ui.card title="Recent activity" :padding="false">
                @forelse ($activity as $log)
                    <p class="border-b border-line px-5 py-2.5 text-sm text-muted last:border-0"><span class="text-ink">{{ str_replace(['.', '_'], ' ', $log->action) }}</span> · {{ $log->subject_label }} · {{ $log->created_at->diffForHumans() }}</p>
                @empty
                    <p class="px-5 py-4 text-sm text-muted">Nothing recent in your companies.</p>
                @endforelse
            </x-ui.card>
        </div>

        <aside class="space-y-4">
            <x-ui.card title="Performance (your companies)">
                <dl class="grid grid-cols-2 gap-3">
                    <div><dt class="text-xs text-subtle">Calls, 30 days</dt><dd class="text-xl font-semibold text-ink">{{ number_format($performance['calls_30']) }}</dd></div>
                    <div><dt class="text-xs text-subtle">Quality score, 90 days</dt><dd class="text-xl font-semibold text-ink">{{ $performance['qa_avg'] !== null ? round($performance['qa_avg']).'%' : '—' }}</dd><dd class="text-xs text-subtle">{{ $performance['qa_count'] }} reviews</dd></div>
                </dl>
            </x-ui.card>
            <x-ui.card title="Next 7 days">
                @forelse ($shifts as $s)
                    <p class="text-sm text-ink">{{ $s->start_datetime->format('D j M, g:i A') }} – {{ $s->end_datetime->format('g:i A') }}</p>
                @empty
                    <p class="text-sm text-muted">No shifts planned.</p>
                @endforelse
            </x-ui.card>
            <x-ui.card title="Open tasks">
                @forelse ($tasks as $t)
                    <p class="text-sm text-ink">{{ $t->title }}</p><p class="mb-2 text-xs text-subtle">{{ $t->organization->name ?? '' }}</p>
                @empty
                    <p class="text-sm text-muted">None.</p>
                @endforelse
            </x-ui.card>
        </aside>
    </div>
</div>
