@props(['course', 'assignment' => null, 'reason' => null])
{{-- One course in Agent University lists: what it is, who it's for, and where the learner stands. --}}
<a href="{{ route('agent.university.course', $course->ulid) }}" {{ $attributes->merge(['class' => 'group flex flex-col rounded-2xl border border-line bg-surface p-5 shadow-[var(--shadow-card)] transition hover:border-brand-400/60']) }}>
    <div class="flex items-start justify-between gap-3">
        <p class="text-xs font-medium uppercase tracking-wider text-subtle">{{ $course->organization?->name ?? 'All agents' }}</p>
        @if ($assignment)
            <x-ui.badge :tone="$assignment->tone()">{{ $assignment->label() }}</x-ui.badge>
        @endif
    </div>
    <h3 class="mt-2 text-base font-semibold text-ink group-hover:text-brand-200">{{ $course->title }}</h3>
    @if ($reason)
        <p class="mt-1 flex items-start gap-1.5 text-xs font-medium text-brand-300"><x-ui.icon name="sparkles" class="mt-px size-3.5 shrink-0" /> {{ $reason }}</p>
    @endif
    @if ($course->summary)
        <p class="mt-1 line-clamp-2 text-sm text-muted">{{ $course->summary }}</p>
    @endif
    <p class="mt-3 text-xs text-subtle">
        {{ \App\Models\TrainingCourse::DIFFICULTIES[$course->difficulty] ?? '' }}
        @if ($course->estimated_minutes) · about {{ $course->estimated_minutes >= 60 ? round($course->estimated_minutes / 60, 1).' h' : $course->estimated_minutes.' min' }}@endif
        @if ($assignment?->is_required) · <span class="text-amber-300">Required</span>@endif
        @if ($assignment?->due_at && ! $assignment->isDone()) · due {{ $assignment->due_at->format('j M') }}@endif
    </p>
    @if ($assignment && ! $assignment->isDone())
        <div class="mt-auto pt-4">
            <div class="h-1.5 overflow-hidden rounded-full bg-surface-3" role="progressbar" aria-valuenow="{{ $assignment->progress_percent }}" aria-valuemin="0" aria-valuemax="100" aria-label="Progress">
                <div class="h-full rounded-full bg-brand-500" style="width: {{ $assignment->progress_percent }}%"></div>
            </div>
            <p class="mt-1 text-xs text-subtle">{{ $assignment->progress_percent }}% done</p>
        </div>
    @endif
</a>
