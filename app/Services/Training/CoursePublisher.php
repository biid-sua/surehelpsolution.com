<?php

namespace App\Services\Training;

use App\Enums\LessonType;
use App\Enums\QuestionType;
use App\Models\TrainingAssignment;
use App\Models\TrainingCourse;
use App\Models\TrainingCourseVersion;
use App\Models\TrainingLesson;
use App\Models\TrainingModule;
use App\Models\TrainingQuestion;
use App\Models\User;
use App\Support\Audit\Audit;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Publishing a course (D42): the draft is checked, then frozen as version N. Learners take that
 * version; nothing already recorded changes. When the author marks the change "retake required",
 * completions of earlier versions become outdated and those agents' training reopens.
 */
class CoursePublisher
{
    public function __construct(private readonly TrainingAccess $access, private readonly Audit $audit) {}

    /** @return list<string> what stops the draft from being published, in plain words */
    public function problems(TrainingCourse $course): array
    {
        $problems = [];
        $course->loadMissing('modules.lessons.questions');
        if ($course->modules->flatMap->lessons->isEmpty()) {
            $problems[] = 'Add at least one lesson.';
        }

        foreach ($course->modules as $module) {
            if ($module->lessons->isEmpty()) {
                $problems[] = "Module \"{$module->title}\" has no lessons.";
            }
            foreach ($module->lessons as $lesson) {
                $problems = array_merge($problems, $this->lessonProblems($lesson));
            }
        }
        if ($course->issues_certificate && trim((string) $course->certificate_name) === '') {
            $problems[] = 'Name the certificate this course issues.';
        }

        return $problems;
    }

    /** @return list<string> */
    private function lessonProblems(TrainingLesson $lesson): array
    {
        $name = "\"{$lesson->title}\"";
        $problems = [];
        if ($lesson->type === LessonType::Quiz) {
            if ($lesson->questions->isEmpty()) {
                return ["Quiz {$name} has no questions."];
            }
            foreach ($lesson->questions as $i => $question) {
                $correct = collect($question->options)->where('correct', true)->count();
                $n = $i + 1;
                if (count($question->options) < 2) {
                    $problems[] = "Quiz {$name}, question {$n}: give at least two answers.";
                } elseif ($correct === 0) {
                    $problems[] = "Quiz {$name}, question {$n}: mark the correct answer.";
                } elseif ($correct > 1 && ! $question->type->allowsMany()) {
                    $problems[] = "Quiz {$name}, question {$n}: only one answer can be correct.";
                }
            }
            if ($lesson->question_count && $lesson->question_count > $lesson->questions->count()) {
                $problems[] = "Quiz {$name} asks {$lesson->question_count} questions but only has {$lesson->questions->count()}.";
            }

            return $problems;
        }

        $hasContent = trim((string) $lesson->body) !== '' || $lesson->url || $lesson->file_path;

        return $hasContent ? [] : ["Lesson {$name} has no content yet."];
    }

    public function publish(TrainingCourse $course, User $by, ?string $note = null, bool $retakeRequired = false): TrainingCourseVersion
    {
        if (! $this->access->canManage($by, $course, 'training.publish')) {
            throw new AuthorizationException('You can\'t publish this course.');
        }
        if ($problems = $this->problems($course)) {
            throw ValidationException::withMessages(['publish' => $problems]);
        }

        return DB::transaction(function () use ($course, $by, $note, $retakeRequired) {
            $course = TrainingCourse::query()->lockForUpdate()->findOrFail($course->id);
            $number = $course->current_version + 1;
            $first = $number === 1;

            $version = TrainingCourseVersion::create([
                'course_id' => $course->id,
                'version' => $number,
                'change_note' => $note !== null && trim($note) !== '' ? trim($note) : ($first ? 'First version' : null),
                'retake_required' => $retakeRequired && ! $first,
                'content' => $this->snapshot($course),
                'published_by_user_id' => $by->id,
                'published_at' => now(),
            ]);
            $course->forceFill(['current_version' => $number, 'published_at' => now(), 'draft_updated_at' => null, 'updated_by_user_id' => $by->id])->save();

            $reopened = 0;
            if ($version->retake_required) {
                // Everyone must take the new version: earlier completions no longer count.
                $reopened = TrainingAssignment::query()->where('course_id', $course->id)->open()->count();
                TrainingAssignment::query()->where('course_id', $course->id)->open()->update(['required_version' => $number]);
                TrainingAssignment::query()->where('course_id', $course->id)->where('status', TrainingAssignment::COMPLETED)
                    ->update(['status' => TrainingAssignment::ASSIGNED, 'version' => null, 'progress_percent' => 0, 'updated_at' => now()]);
            }

            $this->audit->record('training.published', $course, [], [
                'version' => $number, 'note' => $version->change_note, 'retake_required' => $version->retake_required, 'reopened' => $reopened,
            ], $course->organization, $by, $course->title.' v'.$number);

            return $version;
        });
    }

    /**
     * The frozen copy learners take. Correct answers are kept for grading and never sent to the browser.
     *
     * @return array<string, mixed>
     */
    public function snapshot(TrainingCourse $course): array
    {
        $course->load('modules.lessons.questions');

        return [
            'title' => $course->title,
            'summary' => $course->summary,
            'description' => $course->description,
            'modules' => $course->modules->map(fn (TrainingModule $module) => [
                'title' => $module->title,
                'description' => $module->description,
                'lessons' => $module->lessons->map(fn (TrainingLesson $lesson) => [
                    'ulid' => $lesson->ulid,
                    'title' => $lesson->title,
                    'type' => $lesson->type->value,
                    'body' => $lesson->body,
                    'url' => $lesson->url,
                    'file' => $lesson->file_path ? [
                        'disk' => $lesson->file_disk, 'path' => $lesson->file_path, 'name' => $lesson->file_name,
                        'mime' => $lesson->file_mime, 'size' => $lesson->file_size,
                    ] : null,
                    'duration_minutes' => $lesson->duration_minutes,
                    'quiz' => $lesson->type === LessonType::Quiz ? [
                        'pass_percent' => $lesson->pass_percent ?? 80,
                        'max_attempts' => $lesson->max_attempts,
                        'question_count' => $lesson->question_count,
                        'shuffle' => $lesson->shuffle_questions,
                        'questions' => $lesson->questions->map(fn (TrainingQuestion $q) => [
                            'ulid' => $q->ulid,
                            'type' => $q->type->value,
                            'scenario' => $q->type === QuestionType::Scenario ? $q->scenario : null,
                            'prompt' => $q->prompt,
                            'explanation' => $q->explanation,
                            'options' => array_map(fn (array $o) => ['id' => $o['id'], 'label' => $o['label'], 'correct' => (bool) $o['correct']], $q->options),
                        ])->values()->all(),
                    ] : null,
                ])->values()->all(),
            ])->values()->all(),
        ];
    }
}
