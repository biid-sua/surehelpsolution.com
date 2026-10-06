<?php

namespace App\Livewire\Agent\University;

use App\Enums\LessonType;
use App\Livewire\Concerns\AgentWorkspaceOnly;
use App\Models\TrainingAssignment;
use App\Models\TrainingAttempt;
use App\Models\TrainingCourse;
use App\Models\TrainingCourseVersion;
use App\Services\Training\LearningProgress;
use App\Services\Training\TrainingAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * One lesson of the version the learner is taking (spec §20B, brief §1.3, §1.9–1.10). Access is
 * re-checked on every request; the course, version, lesson and attempt can't be changed from the
 * browser, and correct answers never leave the server.
 */
#[Layout('layouts.portal', ['portal' => 'agent'])]
class Lesson extends Component
{
    use AgentWorkspaceOnly;

    #[Locked]
    public int $courseId;

    #[Locked]
    public int $versionNumber;

    #[Locked]
    public string $lessonUlid;

    #[Locked]
    public int $openedAt;

    #[Locked]
    public ?int $attemptId = null;

    /** @var array<string, string|list<string>> question ulid => chosen option id(s) */
    public array $answers = [];

    public function mount(TrainingCourse $course, string $lesson, TrainingAccess $access, LearningProgress $learning): void
    {
        abort_unless($access->canLearn(auth()->user(), $course), 404);
        try {
            $assignment = $learning->enrol(auth()->user(), $course);
            $version = $learning->begin($assignment);
        } catch (AuthorizationException) {
            abort(404);
        }
        if (! $version->lesson($lesson)) {
            // A lesson of another version: start from the course page instead.
            $this->redirectRoute('agent.university.course', $course->ulid);

            return;
        }

        $this->courseId = $course->id;
        $this->versionNumber = $version->version;
        $this->lessonUlid = $lesson;
        $this->openedAt = now()->timestamp;
        $learning->openLesson($assignment, $version, $lesson);
        $open = $learning->attempts($assignment, $version, $lesson)->whereNull('submitted_at')->latest('id')->first();
        if ($open) {
            $this->attemptId = $open->id;
            $this->answers = $this->blankAnswers($open, $version);
        }
    }

    /** @return array{0: TrainingCourse, 1: TrainingAssignment, 2: TrainingCourseVersion} */
    private function context(): array
    {
        $course = TrainingCourse::query()->find($this->courseId);
        abort_unless($course && app(TrainingAccess::class)->canLearn(auth()->user(), $course), 404);
        $assignment = TrainingAssignment::query()->where('course_id', $course->id)->where('agent_user_id', auth()->id())->open()->first();
        $version = $course->version($this->versionNumber);
        abort_unless($assignment && $version, 404);

        return [$course, $assignment, $version];
    }

    public function complete(LearningProgress $learning): void
    {
        [$course, $assignment, $version] = $this->context();
        $learning->completeLesson($assignment, $version, $this->lessonUlid, now()->timestamp - $this->openedAt);
        $this->goNext($course, $assignment->fresh(), $version);
    }

    public function startQuiz(LearningProgress $learning): void
    {
        [, $assignment, $version] = $this->context();
        $attempt = $learning->startAttempt($assignment, $version, $this->lessonUlid);
        $this->attemptId = $attempt->id;
        $this->answers = $this->blankAnswers($attempt, $version);
        $this->resetErrorBag();
    }

    /** @return array<string, string|list<string>> empty answers; several-answer questions start as lists */
    private function blankAnswers(TrainingAttempt $attempt, TrainingCourseVersion $version): array
    {
        $types = collect($version->lesson($this->lessonUlid)['quiz']['questions'] ?? [])->pluck('type', 'ulid');

        return collect($attempt->question_ulids)->mapWithKeys(fn (string $u) => [$u => $types->get($u) === 'multiple' ? [] : ''])->all();
    }

    public function submitQuiz(LearningProgress $learning): void
    {
        $this->resetErrorBag();
        [$course, $assignment, $version] = $this->context();
        $attempt = $this->attempt($assignment);
        abort_unless($attempt && ! $attempt->submitted_at, 404);
        $learning->submitAttempt($attempt, $version, $this->answers);
        if ($assignment->fresh()->status === TrainingAssignment::COMPLETED) {
            session()->flash('status', 'Course complete. Well done!');
        }
    }

    public function next(): void
    {
        [$course, $assignment, $version] = $this->context();
        $this->goNext($course, $assignment, $version);
    }

    private function attempt(TrainingAssignment $assignment): ?TrainingAttempt
    {
        return $this->attemptId ? TrainingAttempt::query()->where('assignment_id', $assignment->id)->find($this->attemptId) : null;
    }

    /** The next lesson not yet done, or the course page when everything is. */
    private function goNext(TrainingCourse $course, TrainingAssignment $assignment, TrainingCourseVersion $version): void
    {
        if ($assignment->status === TrainingAssignment::COMPLETED && $assignment->completed_version === $version->version) {
            session()->flash('status', 'Course complete. Well done!');
            $this->redirectRoute('agent.university.course', $course->ulid);

            return;
        }
        $done = app(LearningProgress::class)->completedLessons($assignment, $version->version);
        $ulids = $version->lessonUlids();
        $here = (int) array_search($this->lessonUlid, $ulids, true);
        $after = array_merge(array_slice($ulids, $here + 1), array_slice($ulids, 0, $here));
        $next = collect($after)->first(fn (string $u) => ! in_array($u, $done, true));

        $this->redirectRoute(...($next ? ['agent.university.lesson', [$course->ulid, $next]] : ['agent.university.course', $course->ulid]));
    }

    public function render(LearningProgress $learning): View
    {
        [$course, $assignment, $version] = $this->context();
        $lesson = $version->lesson($this->lessonUlid);
        abort_unless($lesson !== null, 404);
        $type = LessonType::from($lesson['type']);
        $done = $learning->completedLessons($assignment, $version->version);
        $ulids = $version->lessonUlids();
        $index = (int) array_search($this->lessonUlid, $ulids, true);

        $quiz = null;
        if ($type === LessonType::Quiz) {
            $attempt = $this->attempt($assignment);
            $questions = collect($lesson['quiz']['questions'])->keyBy('ulid');
            $quiz = [
                'settings' => $lesson['quiz'],
                'attempt' => $attempt,
                // Shown without the correct answers; explanations only after submitting, for questions answered wrongly.
                'questions' => $attempt ? collect($attempt->question_ulids)->map(fn (string $u) => $questions->get($u))->filter()->map(fn (array $q) => [
                    'ulid' => $q['ulid'], 'type' => $q['type'], 'scenario' => $q['scenario'], 'prompt' => $q['prompt'],
                    'options' => array_map(fn (array $o) => ['id' => $o['id'], 'label' => $o['label']], $q['options']),
                    'explanation' => $attempt->submitted_at && ! ($attempt->results[$q['ulid']] ?? false) ? $q['explanation'] : null,
                ])->values()->all() : [],
                'history' => $learning->attempts($assignment, $version, $this->lessonUlid)->whereNotNull('submitted_at')->latest('id')->limit(10)->get(),
                'left' => $learning->attemptsLeft($assignment, $version, $this->lessonUlid),
                'count' => $lesson['quiz']['question_count'] ?: count($lesson['quiz']['questions']),
            ];
        }

        return view('livewire.agent.university.lesson', [
            'course' => $course,
            'assignment' => $assignment,
            'version' => $version,
            'lesson' => $lesson,
            'type' => $type,
            'isDone' => in_array($this->lessonUlid, $done, true),
            'done' => $done,
            'position' => $index + 1,
            'total' => count($ulids),
            'previous' => $ulids[$index - 1] ?? null,
            'following' => $ulids[$index + 1] ?? null,
            'quiz' => $quiz,
            'fileUrl' => $lesson['file'] ? route('agent.university.file', [$course->ulid, $version->version, $lesson['ulid']]) : null,
        ])->title($lesson['title'].' · '.$course->title);
    }
}
