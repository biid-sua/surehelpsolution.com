<div>
    <x-ui.page-header :title="$course->title" :description="($course->organization?->name ?? 'All agents').' · '.($course->isPublished() ? 'version '.$course->current_version.' live' : 'not published yet')" :back="route('agent.training.courses')">
        <x-slot:actions>
            @if ($course->hasUnpublishedChanges() && $course->isPublished())<x-ui.badge tone="warning">Unpublished changes</x-ui.badge>@endif
            @unless ($course->is_active)<x-ui.badge tone="danger">Switched off</x-ui.badge>@endunless
            @if ($course->isPublished() && $course->is_active)
                <x-ui.button variant="secondary" size="sm" :href="route('agent.university.course', $course->ulid)">View as learner</x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    <nav class="mb-6 flex gap-1 overflow-x-auto border-b border-line" aria-label="Course sections">
        @foreach ($sections as $key => $label)
            @continue(! $canEdit && in_array($key, ['content', 'details'], true))
            <button type="button" wire:click="$set('section', '{{ $key }}')" @if ($section === $key) aria-current="page" @endif
                @class(['-mb-px whitespace-nowrap border-b-2 px-3 py-2 text-sm font-medium', 'border-brand-400 text-ink' => $section === $key, 'border-transparent text-muted hover:text-ink' => $section !== $key])>{{ $label }}</button>
        @endforeach
    </nav>

    @if ($section === 'content' && $canEdit)
        <div class="grid gap-6 xl:grid-cols-3">
            <div class="space-y-6 xl:col-span-2">
                @if ($editing)
                    @include('livewire.agent.training.partials.lesson-editor')
                @endif

                @forelse ($modules as $mi => $module)
                    <x-ui.card :padding="false" wire:key="m-{{ $module->id }}">
                        <header class="flex flex-wrap items-start justify-between gap-3 border-b border-line px-5 py-4">
                            @if ($editingModule === $module->id)
                                <form wire:submit="saveModule" class="flex w-full flex-col gap-2">
                                    <label for="mt-{{ $module->id }}" class="sr-only">Module title</label>
                                    <input id="mt-{{ $module->id }}" type="text" wire:model="moduleForm.title" class="sh-input" maxlength="200">
                                    @error('moduleForm.title')<p class="text-sm text-danger">{{ $message }}</p>@enderror
                                    <label for="md-{{ $module->id }}" class="sr-only">Module description</label>
                                    <textarea id="md-{{ $module->id }}" wire:model="moduleForm.description" rows="2" class="sh-input" placeholder="What this module covers (optional)"></textarea>
                                    <div class="flex gap-2"><x-ui.button type="submit" size="sm">Save</x-ui.button><x-ui.button size="sm" variant="ghost" wire:click="cancel('editingModule')">Cancel</x-ui.button></div>
                                </form>
                            @else
                                <div class="min-w-0">
                                    <h2 class="text-base font-semibold text-ink">Module {{ $mi + 1 }}: {{ $module->title }}</h2>
                                    @if ($module->description)<p class="mt-0.5 text-sm text-muted">{{ $module->description }}</p>@endif
                                </div>
                                <div class="flex items-center gap-1">
                                    <button type="button" wire:click="moveModule({{ $module->id }}, -1)" class="rounded p-1 text-muted hover:text-ink disabled:opacity-30" @disabled($mi === 0) aria-label="Move module up"><x-ui.icon name="chevron-left" class="size-4 rotate-90" /></button>
                                    <button type="button" wire:click="moveModule({{ $module->id }}, 1)" class="rounded p-1 text-muted hover:text-ink disabled:opacity-30" @disabled($loop->last) aria-label="Move module down"><x-ui.icon name="chevron-right" class="size-4 rotate-90" /></button>
                                    <x-ui.button size="sm" variant="ghost" wire:click="editModule({{ $module->id }})">Edit</x-ui.button>
                                    <x-ui.confirm id="del-m-{{ $module->id }}" title="Delete this module?" confirm-label="Delete" action="deleteModule({{ $module->id }})">
                                        <x-slot:trigger><x-ui.button size="sm" variant="ghost">Delete</x-ui.button></x-slot:trigger>
                                        Its {{ $module->lessons->count() }} {{ \Illuminate\Support\Str::plural('lesson', $module->lessons->count()) }} are removed from the draft. Published versions don't change.
                                    </x-ui.confirm>
                                </div>
                            @endif
                        </header>

                        <ul class="divide-y divide-line">
                            @foreach ($module->lessons as $li => $lesson)
                                <li @class(['flex flex-wrap items-center gap-3 px-5 py-3', 'bg-brand-500/5' => $editingLesson === $lesson->id]) wire:key="l-{{ $lesson->id }}">
                                    <x-ui.icon :name="$lesson->type->icon()" class="size-4 shrink-0 text-subtle" />
                                    <div class="min-w-0 flex-1">
                                        <p class="truncate text-sm font-medium text-ink">{{ $lesson->title }}</p>
                                        <p class="text-xs text-subtle">{{ $lesson->type->label() }}
                                            @if ($lesson->type === \App\Enums\LessonType::Quiz) · {{ $lesson->questions_count }} {{ \Illuminate\Support\Str::plural('question', $lesson->questions_count) }} · pass {{ $lesson->pass_percent }}%@endif
                                            @if ($lesson->file_name) · {{ $lesson->file_name }}@endif
                                            @if ($lesson->duration_minutes) · {{ $lesson->duration_minutes }} min @endif
                                        </p>
                                    </div>
                                    <div class="flex items-center gap-1">
                                        <button type="button" wire:click="moveLesson({{ $lesson->id }}, -1)" class="rounded p-1 text-muted hover:text-ink disabled:opacity-30" @disabled($li === 0) aria-label="Move lesson up"><x-ui.icon name="chevron-left" class="size-4 rotate-90" /></button>
                                        <button type="button" wire:click="moveLesson({{ $lesson->id }}, 1)" class="rounded p-1 text-muted hover:text-ink disabled:opacity-30" @disabled($loop->last) aria-label="Move lesson down"><x-ui.icon name="chevron-right" class="size-4 rotate-90" /></button>
                                        <x-ui.button size="sm" variant="ghost" wire:click="editLesson({{ $lesson->id }})">Edit</x-ui.button>
                                        <x-ui.confirm id="del-l-{{ $lesson->id }}" title="Delete this lesson?" confirm-label="Delete" action="deleteLesson({{ $lesson->id }})">
                                            <x-slot:trigger><x-ui.button size="sm" variant="ghost">Delete</x-ui.button></x-slot:trigger>
                                            It's removed from the draft. Agents keep their records, and published versions don't change.
                                        </x-ui.confirm>
                                    </div>
                                </li>
                            @endforeach
                            <li class="px-5 py-3">
                                <form wire:submit="addLesson({{ $module->id }})" class="flex flex-wrap items-start gap-2">
                                    <div class="min-w-48 flex-1">
                                        <label for="nl-{{ $module->id }}" class="sr-only">New lesson title</label>
                                        <input id="nl-{{ $module->id }}" type="text" wire:model="newLesson.{{ $module->id }}.title" class="sh-input" placeholder="New lesson title" maxlength="200">
                                        @error('newLesson.'.$module->id.'.title')<p class="mt-1 text-sm text-danger">{{ $message }}</p>@enderror
                                    </div>
                                    <div>
                                        <label for="nt-{{ $module->id }}" class="sr-only">Lesson type</label>
                                        <select id="nt-{{ $module->id }}" wire:model="newLesson.{{ $module->id }}.type" class="sh-input">
                                            <option value="">Type…</option>
                                            @foreach ($lessonTypes as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                                        </select>
                                        @error('newLesson.'.$module->id.'.type')<p class="mt-1 text-sm text-danger">{{ $message }}</p>@enderror
                                    </div>
                                    <x-ui.button type="submit" variant="secondary">Add lesson</x-ui.button>
                                </form>
                            </li>
                        </ul>
                    </x-ui.card>
                @empty
                    <x-ui.card><x-ui.empty-state icon="list" title="No modules yet" description="A course is made of modules, each with lessons: reading, video, PDF, documents, audio, links and quizzes. Add the first module below." /></x-ui.card>
                @endforelse

                <form wire:submit="addModule" class="flex flex-wrap items-start gap-2">
                    <div class="min-w-48 flex-1">
                        <label for="new-module" class="sr-only">New module title</label>
                        <input id="new-module" type="text" wire:model="newModule" class="sh-input" placeholder="New module, e.g. Call handling" maxlength="200">
                        @error('newModule')<p class="mt-1 text-sm text-danger">{{ $message }}</p>@enderror
                    </div>
                    <x-ui.button type="submit">Add module</x-ui.button>
                </form>
            </div>

            <div class="space-y-6">
                <x-ui.card title="Publish" :description="$course->isPublished() ? 'Agents take version '.$course->current_version.'. Publishing makes your changes version '.($course->current_version + 1).'.' : 'Agents can only take a published course.'">
                    @if ($problems !== [])
                        <p class="text-sm font-medium text-ink">Before publishing:</p>
                        <ul class="mt-2 list-disc space-y-1 pl-5 text-sm text-amber-200">
                            @foreach ($problems as $problem)<li>{{ $problem }}</li>@endforeach
                        </ul>
                    @elseif (! $course->hasUnpublishedChanges())
                        <p class="text-sm text-muted">Everything is published.</p>
                    @elseif (! $canPublish)
                        <p class="text-sm text-muted">Ready. Someone allowed to publish training needs to publish it.</p>
                    @else
                        <form wire:submit="publish" class="space-y-3">
                            <div>
                                <label for="pub-note" class="sh-label">What changed <span class="text-subtle">(shown to agents)</span></label>
                                <textarea id="pub-note" wire:model="publishNote" rows="2" class="sh-input" maxlength="1000" placeholder="{{ $course->isPublished() ? 'e.g. New escalation steps for after-hours calls' : 'First version' }}"></textarea>
                            </div>
                            @if ($course->isPublished())
                                <label class="flex items-start gap-2 text-sm text-ink">
                                    <input type="checkbox" wire:model="retakeRequired" class="mt-0.5">
                                    <span>Agents must take it again<span class="block text-xs text-subtle">Earlier completions stop counting and their training reopens. Use for important changes.</span></span>
                                </label>
                            @endif
                            @error('publish')<p class="text-sm text-danger">{{ $message }}</p>@enderror
                            <x-ui.button type="submit" class="w-full">Publish version {{ $course->current_version + 1 }}</x-ui.button>
                        </form>
                    @endif
                </x-ui.card>

                <x-ui.card title="Writing tips">
                    <ul class="list-disc space-y-1 pl-5 text-sm text-muted">
                        <li>Keep lessons short: one idea each.</li>
                        <li>Reading lessons support Markdown: <code>## headings</code>, <code>- lists</code>, <code>**bold**</code>, links.</li>
                        <li>Link long videos from YouTube or Vimeo (unlisted); upload files up to {{ $maxUploadMb }} MB.</li>
                        <li>End with a quiz using real call scenarios.</li>
                    </ul>
                </x-ui.card>
            </div>
        </div>
    @elseif ($section === 'details' && $canEdit)
        <x-ui.card>
            <form wire:submit="saveDetails" class="grid gap-4 md:grid-cols-2">
                <div class="md:col-span-2">
                    <label for="d-title" class="sh-label">Title</label>
                    <input id="d-title" type="text" wire:model="details.title" class="sh-input" maxlength="200">
                    @error('details.title')<p class="mt-1 text-sm text-danger">{{ $message }}</p>@enderror
                </div>
                <div class="md:col-span-2">
                    <label for="d-sum" class="sh-label">Summary</label>
                    <input id="d-sum" type="text" wire:model="details.summary" class="sh-input" maxlength="500">
                </div>
                <div class="md:col-span-2">
                    <label for="d-desc" class="sh-label">Description <span class="text-subtle">(Markdown)</span></label>
                    <textarea id="d-desc" wire:model="details.description" rows="5" class="sh-input"></textarea>
                    @error('details.description')<p class="mt-1 text-sm text-danger">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="d-cat" class="sh-label">Category</label>
                    <select id="d-cat" wire:model="details.category" class="sh-input">
                        <option value="">None</option>
                        @foreach ($categories as $cat)<option value="{{ $cat->id }}">{{ $cat->name }}</option>@endforeach
                    </select>
                    <input type="text" wire:model="details.new_category" class="sh-input mt-2" placeholder="…or a new category" maxlength="100" aria-label="New category">
                </div>
                <div>
                    <label for="d-diff" class="sh-label">Level</label>
                    <select id="d-diff" wire:model="details.difficulty" class="sh-input">
                        @foreach (\App\Models\TrainingCourse::DIFFICULTIES as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                    </select>
                </div>
                <div>
                    <label for="d-min" class="sh-label">Takes about (minutes)</label>
                    <input id="d-min" type="number" min="1" wire:model="details.estimated_minutes" class="sh-input">
                    @error('details.estimated_minutes')<p class="mt-1 text-sm text-danger">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="d-owner" class="sh-label">Course owner</label>
                    <select id="d-owner" wire:model="details.owner" class="sh-input">
                        <option value="">Nobody</option>
                        @foreach ($owners as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                    </select>
                </div>
                <div>
                    <label for="d-valid" class="sh-label">Valid for (months)</label>
                    <input id="d-valid" type="number" min="1" max="120" wire:model="details.valid_for_months" class="sh-input" placeholder="Never expires">
                    <p class="mt-1 text-xs text-subtle">After this, agents need to recertify, e.g. 12 for security training.</p>
                    @error('details.valid_for_months')<p class="mt-1 text-sm text-danger">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="d-cover" class="sh-label">Cover image</label>
                    <input id="d-cover" type="file" wire:model="cover" accept="image/*" class="block w-full text-sm text-muted file:mr-3 file:rounded-lg file:border-0 file:bg-surface-3 file:px-3 file:py-1.5 file:text-ink">
                    @if ($course->thumbnail_path)<img src="{{ route('agent.university.thumbnail', $course->ulid) }}" alt="" class="mt-2 h-20 rounded-lg object-cover">@endif
                    @error('cover')<p class="mt-1 text-sm text-danger">{{ $message }}</p>@enderror
                </div>
                <div class="md:col-span-2 space-y-3 rounded-xl border border-line p-4">
                    <label class="flex items-center gap-2 text-sm text-ink"><input type="checkbox" wire:model.live="details.issues_certificate"> Award a certification on completion</label>
                    @if ($details['issues_certificate'])
                        <div>
                            <label for="d-cert" class="sh-label">Certification name</label>
                            <input id="d-cert" type="text" wire:model="details.certificate_name" class="sh-input" maxlength="200" placeholder="e.g. Certified Virtual Receptionist">
                            @error('details.certificate_name')<p class="mt-1 text-sm text-danger">{{ $message }}</p>@enderror
                        </div>
                    @endif
                </div>
                <div class="md:col-span-2">
                    <label class="flex items-start gap-2 text-sm text-ink"><input type="checkbox" wire:model="details.is_active" class="mt-0.5"> <span>Course is on<span class="block text-xs text-subtle">Switched off, agents can't open it and it isn't recommended. Records stay.</span></span></label>
                </div>
                <div class="md:col-span-2"><x-ui.button type="submit">Save details</x-ui.button></div>
            </form>
        </x-ui.card>
    @elseif ($section === 'assign')
        @include('livewire.agent.training.partials.course-agents')
    @elseif ($section === 'versions')
        <x-ui.card :padding="false">
            <x-ui.table>
                <thead><tr><th scope="col">Version</th><th scope="col">Published</th><th scope="col">By</th><th scope="col">What changed</th></tr></thead>
                <tbody class="divide-y divide-line">
                    @forelse ($versions as $v)
                        <tr wire:key="v-{{ $v->id }}">
                            <td class="font-medium text-ink">v{{ $v->version }}@if ($v->version === $course->current_version) <x-ui.badge tone="success">Live</x-ui.badge>@endif</td>
                            <td class="text-sm text-muted">{{ $v->published_at->format('j M Y, g:i A') }}</td>
                            <td class="text-sm text-muted">{{ $v->publisher?->name ?? '–' }}</td>
                            <td class="text-sm text-muted">{{ $v->change_note ?? '–' }}@if ($v->retake_required)<p class="text-xs text-amber-300">Everyone had to take it again</p>@endif</td>
                        </tr>
                    @empty
                        <tr><td colspan="4"><x-ui.empty-state icon="clock" title="Not published yet" description="Each time you publish, a numbered version is kept here. Agents' records always say which version they completed." /></td></tr>
                    @endforelse
                </tbody>
            </x-ui.table>
        </x-ui.card>
    @endif
</div>
