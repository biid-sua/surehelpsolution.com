@php($lt = \App\Enums\LessonType::tryFrom($lessonForm['type'] ?? '') ?? $editing->type)
<x-ui.card :title="'Edit lesson: '.$editing->title" class="ring-1 ring-brand-500/30">
    <form wire:submit="saveLesson" class="grid gap-4 md:grid-cols-2">
        <div class="md:col-span-2">
            <label for="le-title" class="sh-label">Title</label>
            <input id="le-title" type="text" wire:model="lessonForm.title" class="sh-input" maxlength="200">
            @error('lessonForm.title')<p class="mt-1 text-sm text-danger">{{ $message }}</p>@enderror
        </div>
        <div>
            <label for="le-type" class="sh-label">Type</label>
            <select id="le-type" wire:model.live="lessonForm.type" class="sh-input">
                @foreach ($lessonTypes as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
            </select>
        </div>
        <div>
            <label for="le-mod" class="sh-label">Module</label>
            <select id="le-mod" wire:model="lessonForm.module" class="sh-input">
                @foreach ($modules as $m)<option value="{{ $m->id }}">{{ $m->title }}</option>@endforeach
            </select>
        </div>

        @if ($lt->acceptsUrl())
            <div class="md:col-span-2">
                <label for="le-url" class="sh-label">{{ $lt === \App\Enums\LessonType::Video ? 'Video link (YouTube or Vimeo)' : 'Link' }} @if ($lt->acceptsFile())<span class="text-subtle">(or upload a file below)</span>@endif</label>
                <input id="le-url" type="url" wire:model="lessonForm.url" class="sh-input" placeholder="https://" maxlength="2048">
                @error('lessonForm.url')<p class="mt-1 text-sm text-danger">{{ $message }}</p>@enderror
            </div>
        @endif

        @if ($lt->acceptsFile())
            <div class="md:col-span-2">
                <label for="le-file" class="sh-label">File <span class="text-subtle">({{ implode(', ', $lt->extensions()) }}; up to {{ $maxUploadMb }} MB)</span></label>
                @if ($editing->file_path && ! ($lessonForm['remove_file'] ?? false))
                    <p class="mb-2 flex flex-wrap items-center gap-3 text-sm text-muted">
                        <a href="{{ route('agent.training.file', [$course->ulid, $editing->ulid]) }}" target="_blank" class="text-brand-300 hover:text-brand-200">{{ $editing->file_name }}</a>
                        <span class="text-xs text-subtle">{{ \Illuminate\Support\Number::fileSize((int) $editing->file_size) }}</span>
                        <button type="button" wire:click="$set('lessonForm.remove_file', true)" class="text-xs text-danger hover:underline">Remove</button>
                    </p>
                @endif
                <input id="le-file" type="file" wire:model="lessonFile" accept="{{ implode(',', array_map(fn ($e) => '.'.$e, $lt->extensions())) }}" class="block w-full text-sm text-muted file:mr-3 file:rounded-lg file:border-0 file:bg-surface-3 file:px-3 file:py-1.5 file:text-ink">
                <p wire:loading wire:target="lessonFile" class="mt-1 text-xs text-subtle">Uploading…</p>
                @error('lessonFile')<p class="mt-1 text-sm text-danger">{{ $message }}</p>@enderror
            </div>
        @endif

        <div class="md:col-span-2">
            <label for="le-body" class="sh-label">{{ match (true) { $lt === \App\Enums\LessonType::Text => 'Lesson text', $lt === \App\Enums\LessonType::Quiz => 'Instructions before the quiz (optional)', default => 'Notes for the learner (optional)' } }} <span class="text-subtle">(Markdown)</span></label>
            <textarea id="le-body" wire:model="lessonForm.body" rows="{{ $lt === \App\Enums\LessonType::Text ? 12 : 4 }}" class="sh-input font-mono text-[13px]"></textarea>
            @error('lessonForm.body')<p class="mt-1 text-sm text-danger">{{ $message }}</p>@enderror
        </div>

        @if ($lt === \App\Enums\LessonType::Quiz)
            <div>
                <label for="le-pass" class="sh-label">Pass mark (%)</label>
                <input id="le-pass" type="number" min="1" max="100" wire:model="lessonForm.pass_percent" class="sh-input">
                @error('lessonForm.pass_percent')<p class="mt-1 text-sm text-danger">{{ $message }}</p>@enderror
            </div>
            <div>
                <label for="le-att" class="sh-label">Attempts allowed</label>
                <input id="le-att" type="number" min="1" max="50" wire:model="lessonForm.max_attempts" class="sh-input" placeholder="Unlimited">
            </div>
            <div>
                <label for="le-qc" class="sh-label">Questions per attempt</label>
                <input id="le-qc" type="number" min="1" wire:model="lessonForm.question_count" class="sh-input" placeholder="All, in order">
                <p class="mt-1 text-xs text-subtle">Fewer than the total picks a random set each attempt.</p>
            </div>
            <label class="flex items-center gap-2 self-end text-sm text-ink"><input type="checkbox" wire:model="lessonForm.shuffle_questions"> Shuffle question order</label>
        @else
            <div>
                <label for="le-dur" class="sh-label">Length (minutes)</label>
                <input id="le-dur" type="number" min="1" wire:model="lessonForm.duration_minutes" class="sh-input">
            </div>
        @endif

        <div class="flex gap-3 md:col-span-2">
            <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="saveLesson,lessonFile">Save lesson</x-ui.button>
            <x-ui.button variant="ghost" wire:click="closeLesson">Close</x-ui.button>
        </div>
    </form>

    @if ($editing->type === \App\Enums\LessonType::Quiz)
        <div class="mt-6 border-t border-line pt-6">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h3 class="text-base font-semibold text-ink">Questions ({{ $editing->questions->count() }})</h3>
                @if ($editingQuestion === null)<x-ui.button size="sm" variant="secondary" wire:click="addQuestion">Add question</x-ui.button>@endif
            </div>

            <ol class="mt-3 space-y-2">
                @foreach ($editing->questions as $qi => $question)
                    <li class="flex items-start gap-3 rounded-xl border border-line px-4 py-3" wire:key="q-{{ $question->id }}">
                        <span class="text-sm font-semibold text-subtle">{{ $qi + 1 }}.</span>
                        <div class="min-w-0 flex-1">
                            <p class="text-sm text-ink">{{ $question->prompt }}</p>
                            <p class="text-xs text-subtle">{{ $question->type->label() }} · {{ count($question->options) }} answers · correct: {{ collect($question->options)->where('correct', true)->pluck('label')->implode(', ') }}</p>
                        </div>
                        <div class="flex items-center gap-1">
                            <button type="button" wire:click="moveQuestion({{ $question->id }}, -1)" class="rounded p-1 text-muted hover:text-ink disabled:opacity-30" @disabled($qi === 0) aria-label="Move question up"><x-ui.icon name="chevron-left" class="size-4 rotate-90" /></button>
                            <button type="button" wire:click="moveQuestion({{ $question->id }}, 1)" class="rounded p-1 text-muted hover:text-ink disabled:opacity-30" @disabled($loop->last) aria-label="Move question down"><x-ui.icon name="chevron-right" class="size-4 rotate-90" /></button>
                            <x-ui.button size="sm" variant="ghost" wire:click="editQuestion({{ $question->id }})">Edit</x-ui.button>
                            <x-ui.button size="sm" variant="ghost" wire:click="deleteQuestion({{ $question->id }})" wire:confirm="Delete this question?">Delete</x-ui.button>
                        </div>
                    </li>
                @endforeach
            </ol>

            @if ($editingQuestion !== null)
                <form wire:submit="saveQuestion" class="mt-4 space-y-4 rounded-xl border border-brand-500/30 bg-brand-500/5 p-4">
                    <p class="text-sm font-semibold text-ink">{{ $editingQuestion ? 'Edit question' : 'New question' }}</p>
                    <div>
                        <label for="qf-type" class="sh-label">Type</label>
                        <select id="qf-type" wire:model.live="questionForm.type" class="sh-input">
                            @foreach ($questionTypes as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                        </select>
                    </div>
                    @if ($questionForm['type'] === 'scenario')
                        <div>
                            <label for="qf-scn" class="sh-label">Scenario</label>
                            <textarea id="qf-scn" wire:model="questionForm.scenario" rows="3" class="sh-input" placeholder="e.g. A caller says their basement is flooding and it's 9 PM on Sunday…"></textarea>
                            @error('questionForm.scenario')<p class="mt-1 text-sm text-danger">{{ $message }}</p>@enderror
                        </div>
                    @endif
                    <div>
                        <label for="qf-prompt" class="sh-label">Question</label>
                        <textarea id="qf-prompt" wire:model="questionForm.prompt" rows="2" class="sh-input"></textarea>
                        @error('questionForm.prompt')<p class="mt-1 text-sm text-danger">{{ $message }}</p>@enderror
                    </div>
                    <fieldset>
                        <legend class="sh-label">Answers <span class="text-subtle">(select the correct {{ $questionForm['type'] === 'multiple' ? 'ones' : 'one' }})</span></legend>
                        <div class="space-y-2">
                            @foreach ($questionForm['options'] as $oi => $option)
                                <div class="flex items-center gap-2" wire:key="opt-{{ $option['id'] }}">
                                    <button type="button" wire:click="markCorrect({{ $oi }})" aria-pressed="{{ $option['correct'] ? 'true' : 'false' }}" aria-label="Mark answer {{ $oi + 1 }} as correct"
                                        @class(['flex size-7 shrink-0 items-center justify-center rounded-full ring-1', 'bg-emerald-500/20 text-emerald-300 ring-emerald-400/40' => $option['correct'], 'text-subtle ring-line-strong hover:text-ink' => ! $option['correct']])>
                                        <x-ui.icon name="check-circle" class="size-4" />
                                    </button>
                                    <label for="opt-{{ $option['id'] }}" class="sr-only">Answer {{ $oi + 1 }}</label>
                                    <input id="opt-{{ $option['id'] }}" type="text" wire:model="questionForm.options.{{ $oi }}.label" class="sh-input" maxlength="500" @readonly($questionForm['type'] === 'true_false')>
                                    @if ($questionForm['type'] !== 'true_false' && count($questionForm['options']) > 2)
                                        <button type="button" wire:click="removeOption({{ $oi }})" class="rounded p-1 text-muted hover:text-danger" aria-label="Remove answer {{ $oi + 1 }}"><x-ui.icon name="x" class="size-4" /></button>
                                    @endif
                                </div>
                                @error('questionForm.options.'.$oi.'.label')<p class="text-sm text-danger">{{ $message }}</p>@enderror
                            @endforeach
                        </div>
                        @error('questionForm.options')<p class="mt-1 text-sm text-danger">{{ $message }}</p>@enderror
                        @if ($questionForm['type'] !== 'true_false' && count($questionForm['options']) < 8)
                            <button type="button" wire:click="addOption" class="mt-2 text-sm text-brand-300 hover:text-brand-200">+ Add an answer</button>
                        @endif
                    </fieldset>
                    <div>
                        <label for="qf-exp" class="sh-label">Explanation <span class="text-subtle">(shown when answered wrongly)</span></label>
                        <textarea id="qf-exp" wire:model="questionForm.explanation" rows="2" class="sh-input" placeholder="Why the right answer is right, without giving it away word for word"></textarea>
                    </div>
                    <div class="flex gap-3">
                        <x-ui.button type="submit">Save question</x-ui.button>
                        <x-ui.button variant="ghost" wire:click="cancel('editingQuestion')">Cancel</x-ui.button>
                    </div>
                </form>
            @endif
        </div>
    @endif
</x-ui.card>
