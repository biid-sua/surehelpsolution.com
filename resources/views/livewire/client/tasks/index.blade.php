<div>
    <x-ui.page-header title="Tasks" description="Call-backs from your calls, follow-ups and your team's to-dos, most urgent first.">
        @if ($canCreate)
            <x-slot:actions>
                <x-ui.button icon="check-circle" wire:click="create">Add task</x-ui.button>
            </x-slot:actions>
        @endif
    </x-ui.page-header>

    {{-- Views --}}
    <div class="mb-4 flex gap-1 overflow-x-auto border-b border-line" role="tablist" aria-label="Task views">
        @foreach ($views as $key => $label)
            <button type="button" role="tab" wire:click="$set('view', '{{ $key }}')" aria-selected="{{ $view === $key ? 'true' : 'false' }}"
                @class([
                    '-mb-px inline-flex items-center gap-2 whitespace-nowrap border-b-2 px-3 py-2 text-sm font-medium transition-colors',
                    'border-brand-400 text-ink' => $view === $key,
                    'border-transparent text-muted hover:text-ink' => $view !== $key,
                ])>
                {{ $label }}
                @if (isset($counts[$key]) && $counts[$key] > 0)
                    <x-ui.badge :tone="$key === 'overdue' ? 'danger' : 'neutral'">{{ $counts[$key] }}</x-ui.badge>
                @endif
            </button>
        @endforeach
    </div>

    <div class="mb-4 grid gap-3 sm:grid-cols-[minmax(0,28rem)_12rem_12rem] sm:items-end">
        <div>
            <label for="task-search" class="sh-label">Search</label>
            <div class="relative">
                <x-ui.icon name="search" class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-subtle" />
                <input id="task-search" type="search" wire:model.live.debounce.350ms="search" class="sh-input pl-9" placeholder="Title or details…" autocomplete="off">
            </div>
        </div>
        <div>
            <label for="task-type" class="sh-label">Type</label>
            <select id="task-type" wire:model.live="type" class="sh-input">
                <option value="">All types</option>
                @foreach ($types as $t)<option value="{{ $t->value }}">{{ $t->label() }}</option>@endforeach
            </select>
        </div>
        <div>
            <label for="task-assignee" class="sh-label">Assigned to</label>
            <select id="task-assignee" wire:model.live="assignee" class="sh-input">
                <option value="">Anyone</option>
                <option value="none">Nobody yet</option>
                @foreach ($team as $member)<option value="{{ $member->id }}">{{ $member->name }}</option>@endforeach
            </select>
        </div>
    </div>

    <x-ui.card :padding="false">
        <div class="relative">
            <div wire:loading.flex wire:target="search,view,nextPage,previousPage" class="absolute inset-0 z-10 items-start justify-center bg-surface/60 pt-16">
                <span class="rounded-full bg-surface-2 px-3 py-1 text-xs text-muted ring-1 ring-line">Loading…</span>
            </div>

            @if ($tasks->isEmpty())
                @if ($search !== '')
                    <x-ui.empty-state icon="search" title="No tasks match" description="Try different words." />
                @elseif ($view === 'done')
                    <x-ui.empty-state icon="check-circle" title="Nothing finished yet" description="Completed and cancelled tasks are kept here." />
                @else
                    <x-ui.empty-state icon="check-circle" title="You're all caught up"
                        description="When a caller asks for a call back, our team adds it here with a due time, so nobody is forgotten." />
                @endif
            @else
                <ul class="divide-y divide-line" role="list">
                    @foreach ($tasks as $task)
                        @php($overdue = $task->isOverdue($now))
                        <li wire:key="t-{{ $task->ulid }}" class="flex items-start gap-3 px-5 py-4">
                            @if ($canUpdate && $task->status->isOpen())
                                <button type="button" wire:click="setStatus('{{ $task->ulid }}', 'completed')"
                                    class="mt-0.5 size-5 shrink-0 rounded-full ring-1 ring-line-strong hover:bg-emerald-500/20 hover:ring-emerald-400"
                                    aria-label="Mark “{{ $task->title }}” as done"></button>
                            @else
                                <x-ui.icon :name="$task->status === \App\Enums\TaskStatus::Completed ? 'check-circle' : 'x'" class="mt-0.5 size-5 shrink-0 text-subtle" />
                            @endif

                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    @if ($canUpdate)
                                        <button type="button" wire:click="edit('{{ $task->ulid }}')" @class(['text-left font-medium hover:text-brand-300', 'text-ink' => $task->status->isOpen(), 'text-muted line-through' => ! $task->status->isOpen()])>{{ $task->title }}</button>
                                    @else
                                        <span @class(['font-medium', 'text-ink' => $task->status->isOpen(), 'text-muted line-through' => ! $task->status->isOpen()])>{{ $task->title }}</span>
                                    @endif
                                    <x-ui.badge tone="neutral"><x-ui.icon :name="$task->type->icon()" class="size-3" />{{ $task->type->label() }}</x-ui.badge>
                                    @if ($task->priority->rank() <= 1)
                                        <x-ui.badge :tone="$task->priority->tone()">{{ $task->priority->label() }}</x-ui.badge>
                                    @endif
                                    @if ($task->status === \App\Enums\TaskStatus::InProgress)
                                        <x-ui.badge tone="progress">In progress</x-ui.badge>
                                    @endif
                                </div>
                                <p class="mt-1 flex flex-wrap gap-x-3 gap-y-1 text-xs text-subtle">
                                    @if ($task->due_at)
                                        <span @class(['font-semibold text-danger' => $overdue]) title="{{ $task->due_at->setTimezone($timezone)->toDayDateTimeString() }}">
                                            {{ $overdue ? 'Overdue · was due' : 'Due' }} {{ $task->due_at->setTimezone($timezone)->calendar() }}
                                        </span>
                                    @endif
                                    @if ($task->customer)
                                        <a href="{{ route('app.customers.show', $task->customer->ulid) }}" class="hover:text-ink">{{ $task->customer->fullName() }}</a>
                                    @endif
                                    @if ($task->call)
                                        <a href="{{ route('app.calls.show', $task->call->call_id) }}" class="hover:text-ink">Call {{ $task->call->call_id }}</a>
                                    @endif
                                    <span>{{ $task->assignee ? 'For '.$task->assignee->name : 'Unassigned' }}</span>
                                    @if (! $task->status->isOpen() && $task->completed_at)
                                        <span>Done {{ $task->completed_at->diffForHumans() }}</span>
                                    @endif
                                </p>
                            </div>

                            @if ($canUpdate)
                                <div class="flex shrink-0 gap-1">
                                    @if ($task->status === \App\Enums\TaskStatus::Open)
                                        <x-ui.button variant="ghost" size="sm" wire:click="setStatus('{{ $task->ulid }}', 'in_progress')">Start</x-ui.button>
                                    @elseif (! $task->status->isOpen())
                                        <x-ui.button variant="ghost" size="sm" wire:click="setStatus('{{ $task->ulid }}', 'open')">Reopen</x-ui.button>
                                    @endif
                                </div>
                            @endif
                        </li>
                    @endforeach
                </ul>
                <x-ui.pagination :paginator="$tasks" />
            @endif
        </div>
    </x-ui.card>

    @if ($canCreate || $canUpdate)
        <div x-data="{ open: $wire.entangle('editing') }" x-show="open" x-cloak x-on:keydown.escape.window="$wire.closeEditor()"
            class="fixed inset-0 z-50 flex items-center justify-center p-4" role="dialog" aria-modal="true" aria-labelledby="task-editor-title">
            <div class="fixed inset-0 bg-black/60" x-on:click="$wire.closeEditor()"></div>
            <form wire:submit="save" x-trap.noscroll="open" class="relative max-h-[90vh] w-full max-w-lg overflow-y-auto rounded-2xl border border-line-strong bg-surface p-6 shadow-2xl">
                <h2 id="task-editor-title" class="text-lg font-semibold text-ink">{{ $editingId ? 'Edit task' : 'Add a task' }}</h2>
                @if ($editingTask?->customer || $newFor)
                    <p class="mt-1 text-sm text-muted">For {{ ($editingTask?->customer ?? $newFor)->fullName() }}@if ($editingTask?->call) · from call {{ $editingTask->call->call_id }}@endif</p>
                @endif

                <div class="mt-5 grid gap-4 sm:grid-cols-2">
                    <div class="sm:col-span-2">
                        <label for="t-title" class="sh-label">What needs doing?</label>
                        <input id="t-title" type="text" wire:model="form.title" class="sh-input" maxlength="250" autocomplete="off">
                        @error('form.title') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="t-type" class="sh-label">Type</label>
                        <select id="t-type" wire:model="form.type" class="sh-input">
                            @foreach ($types as $option)<option value="{{ $option->value }}">{{ $option->label() }}</option>@endforeach
                        </select>
                    </div>
                    <div>
                        <label for="t-priority" class="sh-label">Priority</label>
                        <select id="t-priority" wire:model="form.priority" class="sh-input">
                            @foreach ($priorities as $option)<option value="{{ $option->value }}">{{ $option->label() }}</option>@endforeach
                        </select>
                    </div>
                    <div>
                        <label for="t-date" class="sh-label">Due date</label>
                        <input id="t-date" type="date" wire:model="form.due_date" class="sh-input">
                        @error('form.due_date') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="t-time" class="sh-label">Time <span class="font-normal text-subtle">({{ $timezone }})</span></label>
                        <input id="t-time" type="time" wire:model="form.due_time" class="sh-input">
                        @error('form.due_time') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                    </div>
                    <div class="sm:col-span-2">
                        <label for="t-assignee" class="sh-label">Assigned to</label>
                        <select id="t-assignee" wire:model="form.assigned_to_user_id" class="sh-input">
                            <option value="">Anyone on the team</option>
                            @foreach ($team as $member)<option value="{{ $member->id }}">{{ $member->name }}{{ $member->id === auth()->id() ? ' (me)' : '' }}</option>@endforeach
                        </select>
                        @error('form.assigned_to_user_id') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                    </div>
                    <div class="sm:col-span-2">
                        <label for="t-desc" class="sh-label">Details</label>
                        <textarea id="t-desc" wire:model="form.description" rows="3" class="sh-input"></textarea>
                        @error('form.description') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                    </div>
                </div>

                <div class="mt-6 flex flex-wrap items-center justify-between gap-2">
                    <div>
                        @if ($editingTask && $editingTask->status->isOpen())
                            <x-ui.button variant="ghost" size="sm" wire:click="setStatus('{{ $editingTask->ulid }}', 'cancelled')" wire:confirm="Cancel this task?">Cancel task</x-ui.button>
                        @endif
                    </div>
                    <div class="flex gap-2">
                        <x-ui.button variant="secondary" x-on:click="$wire.closeEditor()">Close</x-ui.button>
                        <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="save">{{ $editingId ? 'Save' : 'Add task' }}</x-ui.button>
                    </div>
                </div>
            </form>
        </div>
    @endif
</div>
