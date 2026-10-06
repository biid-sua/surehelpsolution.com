<div>
    <x-ui.page-header :title="$course->title" :description="$course->summary" :back="route('agent.university')" />

    <div class="grid gap-6 xl:grid-cols-3">
        <div class="space-y-6 xl:col-span-2">
            @if ($assignment && $assignment->effectiveStatus() === 'outdated')
                <x-ui.alert tone="warning" title="Update required">This course changed in a way you need to know. Take version {{ $course->current_version }} to stay up to date.</x-ui.alert>
            @elseif ($assignment && $assignment->effectiveStatus() === 'expired')
                <x-ui.alert tone="danger" title="Recertification required">Your completion expired on {{ $assignment->expires_at->format('j M Y') }}. Take the course again to renew it.</x-ui.alert>
            @elseif ($assignment && $assignment->effectiveStatus() === 'overdue')
                <x-ui.alert tone="danger" title="Overdue">This training was due on {{ $assignment->due_at->format('j M Y') }}.</x-ui.alert>
            @endif

            @if ($course->description)
                <x-ui.card title="About this course">
                    <div class="sh-prose text-sm text-muted">{!! \Illuminate\Support\Str::markdown($course->description, ['html_input' => 'strip', 'allow_unsafe_links' => false]) !!}</div>
                </x-ui.card>
            @endif

            @if ($version)
                <x-ui.card title="Course outline" :description="count($version->lessons()).' lessons · version '.$version->version" :padding="false">
                    <ol class="divide-y divide-line">
                        @foreach ($version->content['modules'] as $m => $module)
                            <li class="px-5 py-4" wire:key="mod-{{ $m }}">
                                <p class="text-sm font-semibold text-ink">{{ $m + 1 }}. {{ $module['title'] }}</p>
                                @if ($module['description'])<p class="mt-0.5 text-xs text-subtle">{{ $module['description'] }}</p>@endif
                                <ul class="mt-2 space-y-1">
                                    @foreach ($module['lessons'] as $lesson)
                                        @php($type = \App\Enums\LessonType::from($lesson['type']))
                                        @php($isDone = in_array($lesson['ulid'], $done, true))
                                        <li class="flex items-center gap-3 text-sm">
                                            <span @class(['flex size-5 shrink-0 items-center justify-center rounded-full', 'bg-emerald-500/20 text-emerald-300' => $isDone, 'bg-surface-3 text-subtle' => ! $isDone])>
                                                <x-ui.icon :name="$isDone ? 'check-circle' : $type->icon()" class="size-3.5" />
                                            </span>
                                                <a href="{{ route('agent.university.lesson', [$course->ulid, $lesson['ulid']]) }}" class="text-ink hover:text-brand-300">{{ $lesson['title'] }}</a>

                                            <span class="text-xs text-subtle">{{ $type->label() }}@if ($lesson['duration_minutes']) · {{ $lesson['duration_minutes'] }} min @endif</span>
                                            @if ($isDone)<span class="sr-only">(done)</span>@endif
                                        </li>
                                    @endforeach
                                </ul>
                            </li>
                        @endforeach
                    </ol>
                </x-ui.card>
            @endif
        </div>

        <div class="space-y-6">
            <x-ui.card>
                @if ($assignment && ! $assignment->isDone() && $assignment->progress_percent > 0)
                    <p class="text-sm text-muted">{{ $assignment->progress_percent }}% done</p>
                    <div class="mt-2 h-2 overflow-hidden rounded-full bg-surface-3" role="progressbar" aria-valuenow="{{ $assignment->progress_percent }}" aria-valuemin="0" aria-valuemax="100" aria-label="Course progress">
                        <div class="h-full rounded-full bg-brand-500" style="width: {{ $assignment->progress_percent }}%"></div>
                    </div>
                @elseif ($assignment?->isDone())
                    <p class="flex items-center gap-2 text-sm font-medium text-emerald-300"><x-ui.icon name="check-circle" class="size-5" /> Completed {{ $assignment->completed_at->format('j M Y') }}@if ($assignment->best_score !== null) · score {{ $assignment->best_score }}%@endif</p>
                @endif
                <x-ui.button wire:click="start" class="mt-4 w-full">
                    @if ($assignment?->isDone()) Review course
                    @elseif ($assignment && in_array($assignment->effectiveStatus(), ['outdated', 'expired'], true)) Start again
                    @elseif ($assignment && $assignment->started_at) Continue
                    @else Start course @endif
                </x-ui.button>
                @if ($certificate)
                    <a href="{{ route('agent.university.certificate', $certificate->ulid) }}" class="mt-3 flex items-center justify-center gap-2 text-sm text-brand-300 hover:text-brand-200"><x-ui.icon name="shield" class="size-4" /> {{ $certificate->name }} · {{ $certificate->label() }}</a>
                @endif
            </x-ui.card>

            <x-ui.card title="Details">
                <dl class="space-y-2 text-sm">
                    <div class="flex justify-between gap-3"><dt class="text-subtle">For</dt><dd class="text-right text-ink">{{ $course->organization?->name ?? 'All agents' }}</dd></div>
                    @if ($assignment)
                        <div class="flex justify-between gap-3"><dt class="text-subtle">Type</dt><dd class="text-ink">{{ $assignment->is_required ? 'Required' : 'Optional' }}</dd></div>
                        @if ($assignment->due_at)<div class="flex justify-between gap-3"><dt class="text-subtle">Due</dt><dd class="text-ink">{{ $assignment->due_at->format('j M Y') }}</dd></div>@endif
                        <div class="flex justify-between gap-3"><dt class="text-subtle">Status</dt><dd><x-ui.badge :tone="$assignment->tone()">{{ $assignment->label() }}</x-ui.badge></dd></div>
                    @endif
                    @if ($course->category)<div class="flex justify-between gap-3"><dt class="text-subtle">Category</dt><dd class="text-ink">{{ $course->category->name }}</dd></div>@endif
                    <div class="flex justify-between gap-3"><dt class="text-subtle">Level</dt><dd class="text-ink">{{ \App\Models\TrainingCourse::DIFFICULTIES[$course->difficulty] ?? $course->difficulty }}</dd></div>
                    @if ($course->estimated_minutes)<div class="flex justify-between gap-3"><dt class="text-subtle">Takes about</dt><dd class="text-ink">{{ $course->estimated_minutes }} min</dd></div>@endif
                    <div class="flex justify-between gap-3"><dt class="text-subtle">Version</dt><dd class="text-ink">{{ $course->current_version }} · {{ $course->published_at?->format('j M Y') }}</dd></div>
                    @if ($course->valid_for_months)<div class="flex justify-between gap-3"><dt class="text-subtle">Valid for</dt><dd class="text-ink">{{ $course->valid_for_months }} months</dd></div>@endif
                    @if ($course->issues_certificate)<div class="flex justify-between gap-3"><dt class="text-subtle">Certification</dt><dd class="text-right text-ink">{{ $course->certificate_name }}</dd></div>@endif
                    @if ($course->owner)<div class="flex justify-between gap-3"><dt class="text-subtle">Course owner</dt><dd class="text-ink">{{ $course->owner->name }}</dd></div>@endif
                </dl>
            </x-ui.card>

            @if ($newer?->change_note)
                <x-ui.card title="What changed in version {{ $newer->version }}">
                    <p class="text-sm text-muted">{{ $newer->change_note }}</p>
                </x-ui.card>
            @endif
        </div>
    </div>
</div>
