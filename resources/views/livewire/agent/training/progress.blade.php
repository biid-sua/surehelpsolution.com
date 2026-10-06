<div>
    <x-ui.page-header title="Training management" description="Who is trained, who is behind, and what's about to expire." />

    <x-university.manage-nav active="progress" />

    <div class="mb-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
        <x-ui.stat label="Agents" :value="$stats['agents']" icon="users" />
        <x-ui.stat label="Required training done" :value="$stats['required'] === null ? '–' : $stats['required'].'%'" icon="check-circle" />
        <x-ui.stat label="Overdue" :value="$stats['overdue']" icon="clock" :invert="true" />
        <x-ui.stat label="Expiring in 30 days" :value="$stats['expiring']" icon="alert" />
        <x-ui.stat label="Agents at risk" :value="$stats['at_risk']" icon="alert" hint="overdue, or due within a week and not started" />
    </div>

    <div class="mb-4 flex flex-wrap items-center gap-3">
        <div class="w-full max-w-xs">
            <label for="p-search" class="sr-only">Search agent or course</label>
            <input id="p-search" type="search" wire:model.live.debounce.350ms="search" class="sh-input" placeholder="Search agent or course…">
        </div>
        @if ($companies->isNotEmpty())
            <div>
                <label for="p-co" class="sr-only">Company</label>
                <select id="p-co" wire:model.live="company" class="sh-input">
                    <option value="">All companies</option>
                    @foreach ($companies as $c)<option value="{{ $c->ulid }}">{{ $c->name }}</option>@endforeach
                </select>
            </div>
        @endif
        <div>
            <label for="p-st" class="sr-only">Status</label>
            <select id="p-st" wire:model.live="status" class="sh-input">
                <option value="">Any status</option>
                @foreach ($statuses as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
            </select>
        </div>
        <label class="flex items-center gap-2 text-sm text-ink"><input type="checkbox" wire:model.live="requiredOnly"> Required only</label>
    </div>

    <x-ui.card :padding="false">
        <x-ui.table>
            <thead><tr><th scope="col">Agent</th><th scope="col">Company</th><th scope="col">Training</th><th scope="col">Progress</th><th scope="col">Score</th><th scope="col">Due</th><th scope="col">Status</th></tr></thead>
            <tbody class="divide-y divide-line">
                @forelse ($rows as $a)
                    <tr wire:key="p-{{ $a->id }}">
                        <td><a href="{{ route('agent.team.show', $a->agent_user_id) }}" class="font-medium text-ink hover:text-brand-300">{{ $a->agent->name }}</a></td>
                        <td class="text-sm text-muted">{{ $a->course->organization?->name ?? $a->organization?->name ?? 'All agents' }}</td>
                        <td>
                            <a href="{{ route('agent.training.course', [$a->course->ulid, 'section' => 'assign']) }}" class="text-sm text-ink hover:text-brand-300">{{ $a->course->title }}</a>
                            <p class="text-xs text-subtle">{{ $a->is_required ? 'Required' : 'Optional' }}</p>
                        </td>
                        <td class="text-sm text-muted">{{ $a->isDone() ? 100 : $a->progress_percent }}%</td>
                        <td class="text-sm text-muted">{{ $a->best_score !== null ? $a->best_score.'%' : '–' }}</td>
                        <td class="text-sm {{ $a->effectiveStatus() === 'overdue' ? 'text-danger' : 'text-muted' }}">{{ $a->due_at?->format('j M Y') ?? '–' }}</td>
                        <td><x-ui.badge :tone="$a->tone()">{{ $a->label() }}</x-ui.badge></td>
                    </tr>
                @empty
                    <tr><td colspan="7"><x-ui.empty-state icon="chart" title="Nothing matches" description="Training given to the agents you look after appears here." /></td></tr>
                @endforelse
            </tbody>
        </x-ui.table>
        <x-ui.pagination :paginator="$rows" />
    </x-ui.card>
</div>
