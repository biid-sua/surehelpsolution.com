<?php

namespace App\Livewire\Agent\University;

use App\Livewire\Concerns\AgentWorkspaceOnly;
use App\Models\TrainingAssignment;
use App\Models\TrainingCertificate;
use App\Models\TrainingCourse;
use App\Services\Training\LearningProgress;
use App\Services\Training\TrainingAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * One course for the learner (spec §20B): what it covers, where they stand, and the way in.
 * A company course of a company the learner doesn't serve answers 404, like any unknown course.
 */
#[Layout('layouts.portal', ['portal' => 'agent'])]
class Course extends Component
{
    use AgentWorkspaceOnly;

    #[Locked]
    public int $courseId;

    public function mount(TrainingCourse $course, TrainingAccess $access): void
    {
        abort_unless($access->canLearn(auth()->user(), $course), 404);
        $this->courseId = $course->id;
    }

    private function course(): TrainingCourse
    {
        $course = TrainingCourse::query()->find($this->courseId);
        abort_unless($course && app(TrainingAccess::class)->canLearn(auth()->user(), $course), 404);

        return $course;
    }

    /** Starts or continues: opens the first lesson not done yet. */
    public function start(LearningProgress $learning): void
    {
        $course = $this->course();
        try {
            $assignment = $learning->enrol(auth()->user(), $course);
            $version = $learning->begin($assignment);
        } catch (AuthorizationException) {
            abort(404);
        }
        $done = $learning->completedLessons($assignment, $version->version);
        $next = collect($version->lessonUlids())->first(fn (string $ulid) => ! in_array($ulid, $done, true)) ?? $version->lessonUlids()[0];

        $this->redirectRoute('agent.university.lesson', [$course->ulid, $next], navigate: false);
    }

    public function render(LearningProgress $learning): View
    {
        $course = $this->course()->load('organization:id,name', 'category:id,name', 'owner:id,name');
        $assignment = TrainingAssignment::query()->where('course_id', $course->id)->where('agent_user_id', auth()->id())->open()->first();
        // The version the learner is on: the one they completed, the one they're part-way through, or the latest.
        $number = match (true) {
            $assignment?->isDone() => (int) $assignment->completed_version,
            $assignment && $assignment->version && $assignment->version >= $assignment->required_version => (int) $assignment->version,
            default => $course->current_version,
        };
        $version = $course->version($number) ?? $course->version($course->current_version);
        $done = match (true) {
            $version === null || $assignment === null => [],
            $assignment->isDone() => $version->lessonUlids(),
            $assignment->version === $version->version => $learning->completedLessons($assignment, $version->version),
            default => [],
        };

        return view('livewire.agent.university.course', [
            'course' => $course,
            'version' => $version,
            'assignment' => $assignment,
            'done' => $done,
            'certificate' => TrainingCertificate::query()->where('course_id', $course->id)->where('agent_user_id', auth()->id())->latest('issued_at')->first(),
            'newer' => $version && $version->version < $course->current_version ? $course->version($course->current_version) : null,
        ])->title($course->title);
    }
}
