<?php

namespace App\Livewire\Agent\Training;

use App\Enums\LessonType;
use App\Enums\QuestionType;
use App\Livewire\Concerns\AgentWorkspaceOnly;
use App\Models\Organization;
use App\Models\TrainingAssignment;
use App\Models\TrainingCategory;
use App\Models\TrainingCourse;
use App\Models\TrainingCourseVersion;
use App\Models\TrainingLesson;
use App\Models\TrainingModule;
use App\Models\TrainingQuestion;
use App\Models\TrainingRule;
use App\Models\User;
use App\Services\Training\CoursePublisher;
use App\Services\Training\TrainingAccess;
use App\Services\Training\TrainingAssigner;
use App\Support\Audit\Audit;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

/**
 * Editing one course (spec §20B, D42): details, the draft content (modules, lessons, quiz
 * questions), publishing a version, giving it to agents, and the version history. Every action
 * re-checks the specific permission for this course's company (or the platform).
 */
#[Layout('layouts.portal', ['portal' => 'agent'])]
class CourseEditor extends Component
{
    use AgentWorkspaceOnly, WithFileUploads, WithPagination;

    public const SECTIONS = ['content' => 'Content', 'details' => 'Details', 'assign' => 'Agents', 'versions' => 'Versions'];

    #[Locked]
    public int $courseId;

    #[Url(except: 'content')]
    public string $section = 'content';

    /** @var array<string, mixed> */
    public array $details = [];

    /** @var TemporaryUploadedFile|null */
    public $cover = null;

    public string $newModule = '';

    #[Locked]
    public ?int $editingModule = null;

    /** @var array{title: string, description: string} */
    public array $moduleForm = ['title' => '', 'description' => ''];

    /** @var array<int, array{title: string, type: string}> new-lesson fields per module */
    public array $newLesson = [];

    #[Locked]
    public ?int $editingLesson = null;

    /** @var array<string, mixed> */
    public array $lessonForm = [];

    /** @var TemporaryUploadedFile|null */
    public $lessonFile = null;

    /** 0 = a new question */
    #[Locked]
    public ?int $editingQuestion = null;

    /** @var array{type: string, scenario: string, prompt: string, explanation: string, options: list<array{id: string, label: string, correct: bool}>} */
    public array $questionForm = ['type' => 'single', 'scenario' => '', 'prompt' => '', 'explanation' => '', 'options' => []];

    public string $publishNote = '';

    public bool $retakeRequired = false;

    /** @var array<string, mixed> */
    public array $ruleForm = [];

    #[Locked]
    public ?int $revoking = null;

    public string $revokeReason = '';

    public function mount(TrainingCourse $course, TrainingAccess $access): void
    {
        abort_unless($this->mayOpen($course), 404);
        $this->courseId = $course->id;
        if (! array_key_exists($this->section, self::SECTIONS)) {
            $this->section = 'content';
        }
        if (! $access->canManage(auth()->user(), $course) && in_array($this->section, ['content', 'details'], true)) {
            $this->section = 'assign';
        }
        $this->fillDetails($course);
        $this->resetRuleForm($course);
    }

    private function course(): TrainingCourse
    {
        $course = TrainingCourse::query()->find($this->courseId);
        abort_unless($course && $this->mayOpen($course), 404);

        return $course;
    }

    /**
     * Editors and assigners of the course; supervisors may also open a published platform-wide
     * course to give it to agents of their own companies (D41).
     */
    private function mayOpen(TrainingCourse $course): bool
    {
        $access = app(TrainingAccess::class);
        $user = auth()->user();

        return $access->canManage($user, $course) || $access->canManage($user, $course, 'training.assign')
            || ($course->isPlatformWide() && $course->isPublished() && $user->hasPermissionIn('training.assign'));
    }

    private function editable(): TrainingCourse
    {
        $course = $this->course();
        abort_unless(app(TrainingAccess::class)->canManage(auth()->user(), $course), 403);

        return $course;
    }

    private function fillDetails(TrainingCourse $course): void
    {
        $this->details = [
            'title' => $course->title,
            'summary' => (string) $course->summary,
            'description' => (string) $course->description,
            'category' => (string) ($course->category_id ?? ''),
            'new_category' => '',
            'difficulty' => $course->difficulty,
            'estimated_minutes' => (string) ($course->estimated_minutes ?? ''),
            'owner' => (string) ($course->owner_user_id ?? ''),
            'valid_for_months' => (string) ($course->valid_for_months ?? ''),
            'issues_certificate' => $course->issues_certificate,
            'certificate_name' => (string) $course->certificate_name,
            'is_active' => $course->is_active,
        ];
    }

    /** Closes an inline form. */
    public function cancel(string $what): void
    {
        if (in_array($what, ['editingModule', 'editingQuestion', 'revoking'], true)) {
            $this->{$what} = null;
        }
    }

    private function toast(string $message): void
    {
        $this->dispatch('toast', type: 'success', message: $message);
    }

    // Details ----------------------------------------------------------------

    public function saveDetails(Audit $audit): void
    {
        $course = $this->editable();
        $this->validate([
            'details.title' => ['required', 'string', 'max:200'],
            'details.summary' => ['nullable', 'string', 'max:500'],
            'details.description' => ['nullable', 'string', 'max:20000'],
            'details.category' => ['nullable', 'integer', 'exists:training_categories,id'],
            'details.new_category' => ['nullable', 'string', 'max:100'],
            'details.difficulty' => ['required', Rule::in(array_keys(TrainingCourse::DIFFICULTIES))],
            'details.estimated_minutes' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'details.owner' => ['nullable', 'integer', Rule::in(array_keys($this->owners($course)))],
            'details.valid_for_months' => ['nullable', 'integer', 'min:1', 'max:120'],
            'details.issues_certificate' => ['boolean'],
            'details.certificate_name' => [$this->details['issues_certificate'] ? 'required' : 'nullable', 'string', 'max:200'],
            'details.is_active' => ['boolean'],
            'cover' => ['nullable', 'image', 'max:4096'],
        ], [], ['details.title' => 'title', 'details.certificate_name' => 'certificate name', 'details.valid_for_months' => 'validity']);

        $category = trim($this->details['new_category']) !== ''
            ? TrainingCategory::query()->firstOrCreate(['name' => trim($this->details['new_category'])])
            : ($this->details['category'] !== '' ? TrainingCategory::find((int) $this->details['category']) : null);
        $old = $course->only(['title', 'is_active', 'valid_for_months', 'issues_certificate']);

        $course->fill([
            'title' => trim($this->details['title']),
            'summary' => trim($this->details['summary']) ?: null,
            'description' => trim($this->details['description']) ?: null,
            'category_id' => $category?->id,
            'difficulty' => $this->details['difficulty'],
            'estimated_minutes' => $this->details['estimated_minutes'] !== '' ? (int) $this->details['estimated_minutes'] : null,
            'owner_user_id' => $this->details['owner'] !== '' ? (int) $this->details['owner'] : null,
            'valid_for_months' => $this->details['valid_for_months'] !== '' ? (int) $this->details['valid_for_months'] : null,
            'issues_certificate' => (bool) $this->details['issues_certificate'],
            'certificate_name' => trim($this->details['certificate_name']) ?: null,
            'is_active' => (bool) $this->details['is_active'],
            'updated_by_user_id' => auth()->id(),
        ]);
        if ($this->cover) {
            $disk = (string) config('training.disk');
            $previous = $course->thumbnail_path;
            $course->thumbnail_path = $this->cover->storeAs('training/covers', $course->ulid.'-'.Str::lower(Str::random(6)).'.'.$this->cover->extension(), $disk);
            if ($previous) {
                Storage::disk($disk)->delete($previous);
            }
            $this->cover = null;
        }
        $course->save();
        $audit->record('training.course_updated', $course, $old, $course->only(array_keys($old)), $course->organization, auth()->user(), $course->title);
        $this->fillDetails($course);
        $this->toast('Course details saved.');
    }

    /** @return array<int, string> people who may own the course: its editors */
    private function owners(TrainingCourse $course): array
    {
        $users = User::query()->where('is_active', true)->whereIn('role', ['admin', 'agent'])->orderBy('name')->get(['id', 'name', 'role']);

        return $users->filter(fn (User $u) => app(TrainingAccess::class)->canManage($u, $course))->pluck('name', 'id')->all();
    }

    // Modules ----------------------------------------------------------------

    public function addModule(): void
    {
        $course = $this->editable();
        $this->validate(['newModule' => ['required', 'string', 'max:200']], [], ['newModule' => 'module title']);
        TrainingModule::create(['course_id' => $course->id, 'title' => trim($this->newModule), 'position' => (int) $course->modules()->max('position') + 1]);
        $course->touchDraft(auth()->user());
        $this->newModule = '';
    }

    public function editModule(int $id): void
    {
        $module = $this->module($id);
        $this->editingModule = $module->id;
        $this->moduleForm = ['title' => $module->title, 'description' => (string) $module->description];
    }

    public function saveModule(): void
    {
        $module = $this->module((int) $this->editingModule);
        $this->validate(['moduleForm.title' => ['required', 'string', 'max:200'], 'moduleForm.description' => ['nullable', 'string', 'max:2000']], [], ['moduleForm.title' => 'module title']);
        $module->update(['title' => trim($this->moduleForm['title']), 'description' => trim($this->moduleForm['description']) ?: null]);
        $module->course->touchDraft(auth()->user());
        $this->editingModule = null;
    }

    public function deleteModule(int $id): void
    {
        $module = $this->module($id);
        foreach ($module->lessons as $lesson) {
            $this->deleteLessonFileIfUnused($lesson);
        }
        $module->delete();
        $this->editable()->touchDraft(auth()->user());
    }

    public function moveModule(int $id, int $direction): void
    {
        $module = $this->module($id);
        $this->reorder($module->course->modules()->get()->all(), $module->id, $direction);
        $module->course->touchDraft(auth()->user());
    }

    private function module(int $id): TrainingModule
    {
        $course = $this->editable();

        return TrainingModule::query()->where('course_id', $course->id)->findOrFail($id);
    }

    /** @param array<int, TrainingModule|TrainingLesson|TrainingQuestion> $items in their current order */
    private function reorder(array $items, int $id, int $direction): void
    {
        $items = collect($items);
        $ids = $items->pluck('id')->values()->all();
        $from = array_search($id, $ids, true);
        $to = $from + ($direction < 0 ? -1 : 1);
        if ($from === false || ! isset($ids[$to])) {
            return;
        }
        [$ids[$from], $ids[$to]] = [$ids[$to], $ids[$from]];
        foreach ($ids as $position => $itemId) {
            $items->firstWhere('id', $itemId)?->forceFill(['position' => $position])->save();
        }
    }

    // Lessons ----------------------------------------------------------------

    public function addLesson(int $moduleId): void
    {
        $module = $this->module($moduleId);
        $input = $this->newLesson[$moduleId] ?? [];
        $this->validate([
            "newLesson.{$moduleId}.title" => ['required', 'string', 'max:200'],
            "newLesson.{$moduleId}.type" => ['required', Rule::enum(LessonType::class)],
        ], [], ["newLesson.{$moduleId}.title" => 'lesson title', "newLesson.{$moduleId}.type" => 'lesson type']);

        $type = LessonType::from($input['type']);
        $lesson = TrainingLesson::create([
            'course_id' => $module->course_id, 'module_id' => $module->id, 'title' => trim($input['title']), 'type' => $type,
            'position' => (int) $module->lessons()->max('position') + 1,
            'pass_percent' => $type === LessonType::Quiz ? 80 : null,
            'max_attempts' => $type === LessonType::Quiz ? 3 : null,
        ]);
        $module->course->touchDraft(auth()->user());
        unset($this->newLesson[$moduleId]);
        $this->editLesson($lesson->id);
    }

    public function editLesson(int $id): void
    {
        $lesson = $this->lesson($id);
        $this->editingLesson = $lesson->id;
        $this->editingQuestion = null;
        $this->lessonFile = null;
        $this->lessonForm = [
            'title' => $lesson->title,
            'type' => $lesson->type->value,
            'module' => (string) $lesson->module_id,
            'body' => (string) $lesson->body,
            'url' => (string) $lesson->url,
            'duration_minutes' => (string) ($lesson->duration_minutes ?? ''),
            'pass_percent' => (string) ($lesson->pass_percent ?? 80),
            'max_attempts' => (string) ($lesson->max_attempts ?? ''),
            'question_count' => (string) ($lesson->question_count ?? ''),
            'shuffle_questions' => $lesson->shuffle_questions,
            'remove_file' => false,
        ];
        $this->resetErrorBag();
    }

    public function saveLesson(): void
    {
        $lesson = $this->lesson((int) $this->editingLesson);
        $type = LessonType::tryFrom((string) ($this->lessonForm['type'] ?? '')) ?? $lesson->type;
        $mb = (int) config('training.max_upload_mb');
        $this->validate([
            'lessonForm.title' => ['required', 'string', 'max:200'],
            'lessonForm.type' => ['required', Rule::enum(LessonType::class)],
            'lessonForm.module' => ['required', 'integer', Rule::exists('training_modules', 'id')->where('course_id', $lesson->course_id)],
            'lessonForm.body' => ['nullable', 'string', 'max:100000'],
            'lessonForm.url' => ['nullable', 'url:http,https', 'max:2048'],
            'lessonForm.duration_minutes' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'lessonForm.pass_percent' => [$type === LessonType::Quiz ? 'required' : 'nullable', 'integer', 'min:1', 'max:100'],
            'lessonForm.max_attempts' => ['nullable', 'integer', 'min:1', 'max:50'],
            'lessonForm.question_count' => ['nullable', 'integer', 'min:1', 'max:200'],
            'lessonForm.shuffle_questions' => ['boolean'],
            'lessonFile' => $type->acceptsFile() ? ['nullable', 'file', 'max:'.($mb * 1024), 'mimes:'.implode(',', $type->extensions())] : ['prohibited'],
        ], [], ['lessonForm.title' => 'title', 'lessonForm.url' => 'link', 'lessonFile' => 'file', 'lessonForm.pass_percent' => 'pass mark']);

        $quiz = $type === LessonType::Quiz;
        $lesson->fill([
            'title' => trim($this->lessonForm['title']),
            'type' => $type,
            'module_id' => (int) $this->lessonForm['module'],
            'body' => trim((string) $this->lessonForm['body']) ?: null,
            'url' => $type->acceptsUrl() ? (trim((string) $this->lessonForm['url']) ?: null) : null,
            'duration_minutes' => $this->lessonForm['duration_minutes'] !== '' ? (int) $this->lessonForm['duration_minutes'] : null,
            'pass_percent' => $quiz ? (int) $this->lessonForm['pass_percent'] : null,
            'max_attempts' => $quiz && $this->lessonForm['max_attempts'] !== '' ? (int) $this->lessonForm['max_attempts'] : null,
            'question_count' => $quiz && $this->lessonForm['question_count'] !== '' ? (int) $this->lessonForm['question_count'] : null,
            'shuffle_questions' => $quiz && $this->lessonForm['shuffle_questions'],
        ]);

        if (($this->lessonForm['remove_file'] || $this->lessonFile || ! $type->acceptsFile()) && $lesson->file_path) {
            $this->deleteLessonFileIfUnused($lesson);
            $lesson->fill(['file_disk' => null, 'file_path' => null, 'file_name' => null, 'file_mime' => null, 'file_size' => null]);
        }
        if ($this->lessonFile) {
            $disk = (string) config('training.disk');
            $course = $lesson->course;
            $lesson->fill([
                'file_disk' => $disk,
                'file_path' => $this->lessonFile->storeAs('training/lessons/'.$course->ulid, Str::ulid().'.'.$this->lessonFile->extension(), $disk),
                'file_name' => Str::limit($this->lessonFile->getClientOriginalName(), 200, ''),
                'file_mime' => $this->lessonFile->getMimeType(),
                'file_size' => $this->lessonFile->getSize(),
            ]);
            $this->lessonFile = null;
        }
        $lesson->save();
        $lesson->course->touchDraft(auth()->user());
        if ($type !== LessonType::Quiz) {
            $this->editingLesson = null;
        }
        $this->toast('Lesson saved.');
    }

    public function closeLesson(): void
    {
        $this->editingLesson = null;
        $this->editingQuestion = null;
        $this->lessonFile = null;
    }

    public function deleteLesson(int $id): void
    {
        $lesson = $this->lesson($id);
        $this->deleteLessonFileIfUnused($lesson);
        $course = $lesson->course;
        $lesson->delete();
        $course->touchDraft(auth()->user());
        if ($this->editingLesson === $id) {
            $this->closeLesson();
        }
    }

    public function moveLesson(int $id, int $direction): void
    {
        $lesson = $this->lesson($id);
        $this->reorder($lesson->module->lessons()->get()->all(), $lesson->id, $direction);
        $lesson->course->touchDraft(auth()->user());
    }

    private function lesson(int $id): TrainingLesson
    {
        $course = $this->editable();

        return TrainingLesson::query()->where('course_id', $course->id)->findOrFail($id);
    }

    /** Published versions keep using their files; only files no version refers to are deleted. */
    private function deleteLessonFileIfUnused(TrainingLesson $lesson): void
    {
        if (! $lesson->file_path) {
            return;
        }
        $used = TrainingCourseVersion::query()->where('course_id', $lesson->course_id)->get()
            ->contains(fn (TrainingCourseVersion $v) => collect($v->lessons())->contains(fn (array $l) => ($l['file']['path'] ?? null) === $lesson->file_path));
        if (! $used) {
            Storage::disk((string) $lesson->file_disk)->delete($lesson->file_path);
        }
    }

    // Quiz questions -----------------------------------------------------------

    public function addQuestion(): void
    {
        $this->quizLesson();
        $this->editingQuestion = 0;
        $this->questionForm = ['type' => 'single', 'scenario' => '', 'prompt' => '', 'explanation' => '', 'options' => [
            ['id' => TrainingQuestion::optionId(), 'label' => '', 'correct' => true],
            ['id' => TrainingQuestion::optionId(), 'label' => '', 'correct' => false],
        ]];
        $this->resetErrorBag();
    }

    public function editQuestion(int $id): void
    {
        $question = $this->question($id);
        $this->editingQuestion = $question->id;
        $this->questionForm = [
            'type' => $question->type->value, 'scenario' => (string) $question->scenario, 'prompt' => $question->prompt,
            'explanation' => (string) $question->explanation,
            'options' => array_map(fn (array $o) => ['id' => $o['id'], 'label' => $o['label'], 'correct' => (bool) $o['correct']], $question->options),
        ];
        $this->resetErrorBag();
    }

    public function updatedQuestionFormType(string $type): void
    {
        if ($type === QuestionType::TrueFalse->value) {
            $this->questionForm['options'] = [
                ['id' => 'true', 'label' => 'True', 'correct' => true],
                ['id' => 'false', 'label' => 'False', 'correct' => false],
            ];
        }
    }

    public function addOption(): void
    {
        if (count($this->questionForm['options']) < 8) {
            $this->questionForm['options'][] = ['id' => TrainingQuestion::optionId(), 'label' => '', 'correct' => false];
        }
    }

    public function removeOption(int $index): void
    {
        unset($this->questionForm['options'][$index]);
        $this->questionForm['options'] = array_values($this->questionForm['options']);
    }

    /** For one-answer questions, choosing a correct answer clears the others. */
    public function markCorrect(int $index): void
    {
        $many = QuestionType::tryFrom($this->questionForm['type'])?->allowsMany() ?? false;
        foreach ($this->questionForm['options'] as $i => $option) {
            $this->questionForm['options'][$i]['correct'] = $i === $index ? ($many ? ! $option['correct'] : true) : ($many && $option['correct']);
        }
    }

    public function saveQuestion(): void
    {
        $lesson = $this->quizLesson();
        $this->validate([
            'questionForm.type' => ['required', Rule::enum(QuestionType::class)],
            'questionForm.scenario' => [$this->questionForm['type'] === QuestionType::Scenario->value ? 'required' : 'nullable', 'string', 'max:5000'],
            'questionForm.prompt' => ['required', 'string', 'max:2000'],
            'questionForm.explanation' => ['nullable', 'string', 'max:2000'],
            'questionForm.options' => ['required', 'array', 'min:2', 'max:8'],
            'questionForm.options.*.label' => ['required', 'string', 'max:500'],
        ], ['questionForm.options.*.label.required' => 'Write every answer, or remove the empty one.'], ['questionForm.prompt' => 'question', 'questionForm.scenario' => 'scenario']);

        $options = array_map(fn (array $o) => ['id' => (string) $o['id'], 'label' => trim($o['label']), 'correct' => (bool) $o['correct']], $this->questionForm['options']);
        $correct = count(array_filter(array_column($options, 'correct')));
        $many = QuestionType::from($this->questionForm['type'])->allowsMany();
        if ($correct === 0 || (! $many && $correct > 1)) {
            $this->addError('questionForm.options', $many ? 'Mark at least one correct answer.' : 'Mark exactly one correct answer.');

            return;
        }

        $data = [
            'type' => $this->questionForm['type'],
            'scenario' => $this->questionForm['type'] === QuestionType::Scenario->value ? trim($this->questionForm['scenario']) : null,
            'prompt' => trim($this->questionForm['prompt']),
            'explanation' => trim($this->questionForm['explanation']) ?: null,
            'options' => $options,
        ];
        if ($this->editingQuestion) {
            $this->question($this->editingQuestion)->update($data);
        } else {
            TrainingQuestion::create($data + ['lesson_id' => $lesson->id, 'position' => (int) $lesson->questions()->max('position') + 1]);
        }
        $lesson->course->touchDraft(auth()->user());
        $this->editingQuestion = null;
    }

    public function deleteQuestion(int $id): void
    {
        $question = $this->question($id);
        $question->delete();
        $this->quizLesson()->course->touchDraft(auth()->user());
        if ($this->editingQuestion === $id) {
            $this->editingQuestion = null;
        }
    }

    public function moveQuestion(int $id, int $direction): void
    {
        $question = $this->question($id);
        $this->reorder($this->quizLesson()->questions()->get()->all(), $question->id, $direction);
        $this->quizLesson()->course->touchDraft(auth()->user());
    }

    private function quizLesson(): TrainingLesson
    {
        $lesson = $this->lesson((int) $this->editingLesson);
        abort_unless($lesson->type === LessonType::Quiz, 404);

        return $lesson;
    }

    private function question(int $id): TrainingQuestion
    {
        return TrainingQuestion::query()->where('lesson_id', $this->quizLesson()->id)->findOrFail($id);
    }

    // Publishing -------------------------------------------------------------

    public function publish(CoursePublisher $publisher): void
    {
        $course = $this->course();
        $this->validate(['publishNote' => ['nullable', 'string', 'max:1000']]);
        $version = $publisher->publish($course, auth()->user(), $this->publishNote, $this->retakeRequired);
        $this->publishNote = '';
        $this->retakeRequired = false;
        $this->toast('Version '.$version->version.' is live.'.($version->retake_required ? ' Agents who completed it will take it again.' : ''));
    }

    // Agents -----------------------------------------------------------------

    private function resetRuleForm(TrainingCourse $course): void
    {
        $this->ruleForm = [
            'scope' => $course->isPlatformWide() ? TrainingRule::EVERYONE : TrainingRule::COMPANY,
            'role' => 'agent', 'company' => $course->organization->ulid ?? '', 'agent' => '',
            'is_required' => true, 'priority' => 'normal', 'due_days' => '14', 'enforcement' => 'warning',
        ];
    }

    public function addRule(TrainingAssigner $assigner): void
    {
        $course = $this->course();
        $this->validate([
            'ruleForm.scope' => ['required', Rule::in([TrainingRule::EVERYONE, TrainingRule::ROLE, TrainingRule::COMPANY, TrainingRule::AGENT])],
            'ruleForm.role' => [Rule::requiredIf($this->ruleForm['scope'] === TrainingRule::ROLE), 'nullable', Rule::in(array_keys(TrainingRule::ROLES))],
            'ruleForm.company' => [Rule::requiredIf($this->ruleForm['scope'] === TrainingRule::COMPANY), 'nullable', 'string'],
            'ruleForm.agent' => [Rule::requiredIf($this->ruleForm['scope'] === TrainingRule::AGENT), 'nullable', 'integer'],
            'ruleForm.is_required' => ['boolean'],
            'ruleForm.priority' => ['required', Rule::in(array_keys(TrainingRule::PRIORITIES))],
            'ruleForm.due_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'ruleForm.enforcement' => ['required', Rule::in(array_keys(TrainingRule::ENFORCEMENT))],
        ], [], ['ruleForm.company' => 'company', 'ruleForm.agent' => 'agent', 'ruleForm.due_days' => 'days to complete']);

        $company = $this->ruleForm['company'] !== '' ? Organization::query()->where('ulid', $this->ruleForm['company'])->value('id') : null;
        $rule = $assigner->createRule($course, auth()->user(), [
            'scope' => $this->ruleForm['scope'],
            'role' => $this->ruleForm['role'] ?: null,
            'organization_id' => $company,
            'agent_user_id' => $this->ruleForm['agent'] !== '' ? (int) $this->ruleForm['agent'] : null,
            'is_required' => (bool) $this->ruleForm['is_required'],
            'priority' => $this->ruleForm['priority'],
            'due_days' => $this->ruleForm['due_days'] !== '' ? (int) $this->ruleForm['due_days'] : null,
            'enforcement' => $this->ruleForm['enforcement'],
        ]);
        $this->resetRuleForm($course);
        $this->toast('Given to '.$rule->audience().'.');
    }

    public function removeRule(int $id, bool $revokeUnfinished, TrainingAssigner $assigner): void
    {
        $course = $this->course();
        $rule = TrainingRule::query()->where('course_id', $course->id)->where('is_active', true)->findOrFail($id);
        $removed = $assigner->removeRule($rule, auth()->user(), $revokeUnfinished);
        $this->toast($revokeUnfinished ? "Rule removed; {$removed} unfinished ".Str::plural('assignment', $removed).' withdrawn.' : 'Rule removed. Agents keep the training they already have.');
    }

    public function startRevoke(int $id): void
    {
        $this->revoking = TrainingAssignment::query()->where('course_id', $this->course()->id)->findOrFail($id)->id;
        $this->revokeReason = '';
    }

    public function revoke(TrainingAssigner $assigner): void
    {
        $assignment = TrainingAssignment::query()->where('course_id', $this->course()->id)->findOrFail((int) $this->revoking);
        $assigner->revoke($assignment, auth()->user(), $this->revokeReason);
        $this->revoking = null;
        $this->toast('Training removed for '.$assignment->agent->name.'.');
    }

    public function allowAttempt(int $id, TrainingAssigner $assigner): void
    {
        $assignment = TrainingAssignment::query()->where('course_id', $this->course()->id)->findOrFail($id);
        $assigner->allowAnotherAttempt($assignment, auth()->user());
        $this->toast($assignment->agent->name.' can try the quiz once more.');
    }

    // Rendering --------------------------------------------------------------

    public function render(TrainingAccess $access, CoursePublisher $publisher): View
    {
        $course = $this->course()->load('organization:id,ulid,name', 'category:id,name');
        $user = auth()->user();
        $canEdit = $access->canManage($user, $course);
        $data = [
            'course' => $course,
            'sections' => self::SECTIONS,
            'canEdit' => $canEdit,
            'canPublish' => $access->canManage($user, $course, 'training.publish'),
            'canAssign' => $access->canManage($user, $course, 'training.assign') || ($course->isPlatformWide() && $user->hasPermissionIn('training.assign')),
        ];

        $data += match ($this->section) {
            'details' => [
                'categories' => TrainingCategory::query()->orderBy('sort_order')->orderBy('name')->get(['id', 'name']),
                'owners' => $this->owners($course),
            ],
            'assign' => $this->assignData($course, $access),
            'versions' => ['versions' => $course->versions()->with('publisher:id,name')->get()],
            default => [
                'modules' => $course->modules()->with(['lessons' => fn ($q) => $q->withCount('questions')])->get(),
                'problems' => $publisher->problems($course),
                'lessonTypes' => LessonType::options(),
                'questionTypes' => QuestionType::options(),
                'editing' => $this->editingLesson ? TrainingLesson::query()->where('course_id', $course->id)->with('questions')->find($this->editingLesson) : null,
                'maxUploadMb' => (int) config('training.max_upload_mb'),
            ],
        };

        return view('livewire.agent.training.course-editor', $data)->title($course->title.' · Training');
    }

    /** @return array<string, mixed> */
    private function assignData(TrainingCourse $course, TrainingAccess $access): array
    {
        $user = auth()->user();
        $visible = $access->agents($user, 'training.view_progress')->select('users.id');
        $platform = $course->isPlatformWide();

        return [
            'rules' => $course->rules()->where('is_active', true)->with('organization:id,name', 'agent:id,name')->latest('id')->get()
                ->filter(fn (TrainingRule $r) => $platform && $user->hasPlatformPermission('training.view_progress')
                    || ($r->organization_id && $user->hasPermissionIn('training.view_progress', $r->organization))
                    || ($r->agent_user_id && $access->canSeeAgent($user, $r->agent)))->values(),
            'learners' => TrainingAssignment::query()->where('course_id', $course->id)->whereIn('agent_user_id', $visible)
                ->with('agent:id,name', 'organization:id,name')->orderByRaw('CASE WHEN status = ? THEN 1 ELSE 0 END', [TrainingAssignment::REVOKED])
                ->orderBy('due_at')->paginate(25),
            'scopes' => array_filter([
                TrainingRule::EVERYONE => $platform && $user->hasPlatformPermission('training.assign') ? 'Every agent' : null,
                TrainingRule::ROLE => $platform && $user->hasPlatformPermission('training.assign') ? 'Everyone with a role' : null,
                TrainingRule::COMPANY => 'Agents serving a company',
                TrainingRule::AGENT => 'One agent',
            ]),
            'ruleCompanies' => $platform
                ? $access->companies($user, 'training.assign')->orderBy('name')->pluck('name', 'ulid')->all()
                : ($user->hasPermissionIn('training.assign', $course->organization) ? [$course->organization->ulid => $course->organization->name] : []),
            'ruleAgents' => ($platform ? $access->agents($user, 'training.assign')
                : User::query()->where('role', 'agent')->whereHas('agentAssignments', fn (Builder $q) => $q->where('organization_id', $course->organization_id)->current()))
                ->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all(),
        ];
    }
}
