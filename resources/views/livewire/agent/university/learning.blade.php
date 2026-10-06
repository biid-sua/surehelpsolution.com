<div>
    <x-ui.page-header title="Agent University" description="Your training: what's required, what you're working on, and your certifications." />

    <div class="mb-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-ui.stat label="Training progress" :value="$stats['progress'] === null ? '–' : $stats['progress'].'%'" icon="trend-up" />
        <x-ui.stat label="Required training" :value="$stats['required_done'].' of '.$stats['required']" hint="completed" icon="check-circle" />
        <x-ui.stat label="Overdue" :value="$stats['overdue']" icon="clock" :invert="true" />
        <x-ui.stat label="Active certifications" :value="$stats['certificates']" icon="shield" />
    </div>

    <nav class="mb-6 flex gap-1 overflow-x-auto border-b border-line" aria-label="Agent University">
        @foreach ($tabs as $key => $label)
            <button type="button" wire:click="$set('tab', '{{ $key }}')" @if ($tab === $key) aria-current="page" @endif
                @class(['-mb-px whitespace-nowrap border-b-2 px-3 py-2 text-sm font-medium', 'border-brand-400 text-ink' => $tab === $key, 'border-transparent text-muted hover:text-ink' => $tab !== $key])>{{ $label }}</button>
        @endforeach
    </nav>

    @if ($tab === 'overview')
        <div class="grid gap-6 xl:grid-cols-3">
            <div class="space-y-6 xl:col-span-2">
                <section aria-labelledby="todo-h">
                    <h2 id="todo-h" class="mb-3 text-base font-semibold text-ink">To do</h2>
                    @if ($todo->isEmpty())
                        <x-ui.card><x-ui.empty-state icon="check-circle" title="You're up to date" description="No training is waiting for you. Browse Recommended to learn something new." /></x-ui.card>
                    @else
                        <div class="grid gap-4 md:grid-cols-2">
                            @foreach ($todo as $a)
                                <x-university.course-card :course="$a->course" :assignment="$a" wire:key="todo-{{ $a->id }}" />
                            @endforeach
                        </div>
                        @if ($todo->count() >= 6)
                            <button type="button" wire:click="$set('tab', 'assigned')" class="mt-3 text-sm text-brand-300 hover:text-brand-200">See all assigned training</button>
                        @endif
                    @endif
                </section>

                @if ($paths->isNotEmpty())
                    <section aria-labelledby="paths-h">
                        <h2 id="paths-h" class="mb-3 text-base font-semibold text-ink">Learning paths</h2>
                        <div class="space-y-3">
                            @foreach ($paths as $path)
                                @php($done = $path->courses->filter(fn ($c) => $byCourse->get($c->id)?->isDone())->count())
                                <x-ui.card wire:key="path-{{ $path->id }}">
                                    <div class="flex flex-wrap items-baseline justify-between gap-2">
                                        <h3 class="font-semibold text-ink">{{ $path->title }}</h3>
                                        <p class="text-sm text-muted">{{ $done }} of {{ $path->courses->count() }} courses done</p>
                                    </div>
                                    @if ($path->description)<p class="mt-1 text-sm text-muted">{{ $path->description }}</p>@endif
                                    <ol class="mt-3 space-y-1">
                                        @foreach ($path->courses as $i => $c)
                                            @php($a = $byCourse->get($c->id))
                                            <li class="flex items-center gap-2 text-sm">
                                                <span @class(['flex size-5 items-center justify-center rounded-full text-[11px] font-semibold', 'bg-emerald-500/20 text-emerald-300' => $a?->isDone(), 'bg-surface-3 text-muted' => ! $a?->isDone()])>{{ $i + 1 }}</span>
                                                <a href="{{ route('agent.university.course', $c->ulid) }}" class="text-ink hover:text-brand-300">{{ $c->title }}</a>
                                                @if ($a)<x-ui.badge :tone="$a->tone()">{{ $a->label() }}</x-ui.badge>@endif
                                            </li>
                                        @endforeach
                                    </ol>
                                </x-ui.card>
                            @endforeach
                        </div>
                    </section>
                @endif
            </div>

            <div class="space-y-6">
                @if ($expiring->isNotEmpty())
                    <x-ui.alert tone="warning" title="Recertification coming up">
                        @foreach ($expiring as $cert)
                            <p><a href="{{ route('agent.university.course', $cert->course->ulid) }}" class="underline">{{ $cert->name }}</a> {{ $cert->expires_at->isPast() ? 'expired' : 'expires' }} {{ $cert->expires_at->format('j M Y') }}.</p>
                        @endforeach
                    </x-ui.alert>
                @endif

                <x-ui.card title="Recently completed">
                    @forelse ($recent as $done)
                        <p class="flex items-center justify-between gap-2 py-1 text-sm">
                            <a href="{{ route('agent.university.course', $done->course->ulid) }}" class="truncate text-ink hover:text-brand-300">{{ $done->course->title }}</a>
                            <span class="shrink-0 text-xs text-subtle">{{ $done->completed_at->format('j M') }}</span>
                        </p>
                    @empty
                        <p class="text-sm text-muted">Nothing yet. Finished courses show here.</p>
                    @endforelse
                </x-ui.card>

                @if ($suggested->isNotEmpty())
                    <section aria-labelledby="sugg-h">
                        <h2 id="sugg-h" class="mb-3 text-base font-semibold text-ink">Recommended</h2>
                        <div class="space-y-3">
                            @foreach ($suggested as $course)
                                <x-university.course-card :course="$course" wire:key="sugg-{{ $course->id }}" />
                            @endforeach
                        </div>
                    </section>
                @endif
            </div>
        </div>
    @elseif ($tab === 'assigned' || $tab === 'completed')
        <x-ui.card :padding="false">
            <x-ui.table>
                <thead><tr><th scope="col">Course</th><th scope="col">For</th><th scope="col">{{ $tab === 'completed' ? 'Completed' : 'Due' }}</th><th scope="col">Progress</th><th scope="col">Status</th></tr></thead>
                <tbody class="divide-y divide-line">
                    @forelse ($rows as $a)
                        <tr wire:key="row-{{ $a->id }}">
                            <td>
                                <a href="{{ route('agent.university.course', $a->course->ulid) }}" class="font-medium text-ink hover:text-brand-300">{{ $a->course->title }}</a>
                                <p class="text-xs text-subtle">{{ $a->is_required ? 'Required' : 'Optional' }}@if ($a->priority === 'high') · high priority @endif</p>
                            </td>
                            <td class="text-sm text-muted">{{ $a->course->organization?->name ?? 'All agents' }}</td>
                            <td class="text-sm text-muted">
                                @if ($tab === 'completed'){{ $a->completed_at?->format('j M Y') }}@if ($a->expires_at)<p class="text-xs text-subtle">valid until {{ $a->expires_at->format('j M Y') }}</p>@endif
                                @else{{ $a->due_at?->format('j M Y') ?? '–' }}@endif
                            </td>
                            <td class="text-sm text-muted">{{ $a->isDone() ? 100 : $a->progress_percent }}%@if ($a->best_score !== null) · score {{ $a->best_score }}%@endif</td>
                            <td><x-ui.badge :tone="$a->tone()">{{ $a->label() }}</x-ui.badge></td>
                        </tr>
                    @empty
                        <tr><td colspan="5"><x-ui.empty-state icon="check-circle" :title="$tab === 'completed' ? 'No completed training yet' : 'Nothing assigned'" :description="$tab === 'completed' ? 'Courses you finish appear here.' : 'Training your supervisor assigns to you appears here.'" /></td></tr>
                    @endforelse
                </tbody>
            </x-ui.table>
        </x-ui.card>
    @elseif ($tab === 'recommended')
        <div class="mb-4 flex flex-wrap gap-3">
            <div class="w-full max-w-sm">
                <label for="u-search" class="sr-only">Search courses</label>
                <input id="u-search" type="search" wire:model.live.debounce.350ms="search" class="sh-input" placeholder="Search courses…">
            </div>
            @if ($categories->isNotEmpty())
                <div>
                    <label for="u-cat" class="sr-only">Category</label>
                    <select id="u-cat" wire:model.live="category" class="sh-input">
                        <option value="">All categories</option>
                        @foreach ($categories as $cat)<option value="{{ $cat->id }}">{{ $cat->name }}</option>@endforeach
                    </select>
                </div>
            @endif
        </div>
        @if ($courses->isEmpty())
            <x-ui.card><x-ui.empty-state icon="sparkles" title="No more courses to suggest" description="You already have every course open to you. New courses appear here when they're published." /></x-ui.card>
        @else
            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                @foreach ($courses as $course)
                    <x-university.course-card :course="$course" wire:key="cat-{{ $course->id }}" />
                @endforeach
            </div>
            <div class="mt-4"><x-ui.pagination :paginator="$courses" /></div>
        @endif
    @elseif ($tab === 'certificates')
        @if ($certificates->isEmpty())
            <x-ui.card><x-ui.empty-state icon="shield" title="No certifications yet" description="Some courses award a certification when you complete them." /></x-ui.card>
        @else
            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                @foreach ($certificates as $cert)
                    <a href="{{ route('agent.university.certificate', $cert->ulid) }}" wire:key="cert-{{ $cert->id }}" class="block rounded-2xl border border-line bg-surface p-5 shadow-[var(--shadow-card)] hover:border-brand-400/60">
                        <div class="flex items-start justify-between gap-3">
                            <p class="font-semibold text-ink">{{ $cert->name }}</p>
                            <x-ui.badge :tone="$cert->tone()">{{ $cert->label() }}</x-ui.badge>
                        </div>
                        <p class="mt-1 text-xs text-subtle">{{ $cert->number }} · version {{ $cert->course_version }}</p>
                        <p class="mt-3 text-sm text-muted">Issued {{ $cert->issued_at->format('j M Y') }}@if ($cert->expires_at) · {{ $cert->expires_at->isPast() ? 'expired' : 'expires' }} {{ $cert->expires_at->format('j M Y') }}@endif</p>
                    </a>
                @endforeach
            </div>
        @endif
    @else
        <x-ui.card :padding="false">
            <x-ui.table>
                <thead><tr><th scope="col">Course</th><th scope="col">Version</th><th scope="col">Completed</th><th scope="col">Score</th><th scope="col">Time</th><th scope="col">Valid until</th></tr></thead>
                <tbody class="divide-y divide-line">
                    @forelse ($history as $h)
                        <tr wire:key="h-{{ $h->id }}">
                            <td><a href="{{ route('agent.university.course', $h->course->ulid) }}" class="text-ink hover:text-brand-300">{{ $h->course->title }}</a><p class="text-xs text-subtle">{{ $h->course->organization?->name ?? 'All agents' }}</p></td>
                            <td class="text-sm text-muted">v{{ $h->course_version }}</td>
                            <td class="text-sm text-muted">{{ $h->completed_at->format('j M Y') }}</td>
                            <td class="text-sm text-muted">{{ $h->score !== null ? $h->score.'%' : '–' }}</td>
                            <td class="text-sm text-muted">{{ $h->seconds_spent >= 60 ? round($h->seconds_spent / 60).' min' : '–' }}</td>
                            <td class="text-sm text-muted">{{ $h->expires_at?->format('j M Y') ?? 'No expiry' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6"><x-ui.empty-state icon="clock" title="No training history yet" description="Every course you complete is recorded here, with its version and score." /></td></tr>
                    @endforelse
                </tbody>
            </x-ui.table>
            <x-ui.pagination :paginator="$history" />
        </x-ui.card>
    @endif
</div>
