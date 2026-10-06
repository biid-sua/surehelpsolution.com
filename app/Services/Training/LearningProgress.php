<?php

namespace App\Services\Training;

use App\Enums\LessonType;
use App\Enums\QuestionType;
use App\Models\TrainingAssignment;
use App\Models\TrainingAttempt;
use App\Models\TrainingCertificate;
use App\Models\TrainingCompletion;
use App\Models\TrainingCourse;
use App\Models\TrainingCourseVersion;
use App\Models\TrainingLessonProgress;
use App\Models\User;
use App\Support\Audit\Audit;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Taking a course (brief §1.9–1.12), all recorded on the server: which version, each lesson
 * started and finished, time spent, every quiz attempt, the completion, and the certificate.
 */
class LearningProgress
{
    /** Time counted for one lesson or attempt at most, so a tab left open doesn't inflate it. */
    private const MAX_SECONDS = 7200;

    public function __construct(private readonly TrainingAccess $access, private readonly Audit $audit) {}

    /** The learner's row for the course, created (optional, self-enrolled) on first open. */
    public function enrol(User $user, TrainingCourse $course): TrainingAssignment
    {
        if (! $this->access->canLearn($user, $course)) {
            throw new AuthorizationException('This course isn\'t available to you.');
        }

        $row = TrainingAssignment::query()->firstOrCreate(
            ['course_id' => $course->id, 'agent_user_id' => $user->id],
            ['organization_id' => $course->organization_id, 'required_version' => $course->current_version],
        );
        if ($row->status === TrainingAssignment::REVOKED) {
            // Removed by a supervisor: the learner may still take it, as optional training.
            $row->forceFill(['status' => $row->completed_at ? TrainingAssignment::COMPLETED : TrainingAssignment::ASSIGNED, 'is_required' => false,
                'revoked_at' => null, 'revoked_by_user_id' => null, 'revoke_reason' => null, 'due_at' => null])->save();
        }

        return $row;
    }

    /**
     * Picks the version the learner works on and marks the course started. A finished, valid
     * course stays on the version completed (for review); anything else moves to the latest
     * version unless the learner is part-way through an older one that still counts.
     */
    public function begin(TrainingAssignment $assignment): TrainingCourseVersion
    {
        $course = $assignment->course;
        $latest = $course->current_version;
        $changes = ['last_accessed_at' => now()];

        if ($assignment->isDone()) {
            $number = (int) ($assignment->completed_version ?: $latest);
        } else {
            $number = (int) $assignment->version;
            $stale = $number === 0 || $number < $assignment->required_version
                || ($number < $latest && ! $this->hasProgress($assignment, $number));
            if ($stale || $assignment->status === TrainingAssignment::COMPLETED) {
                // New cycle: a new version, a retake, or recertification after expiry. Lessons and
                // attempts from earlier cycles stay on record but no longer count.
                $number = $latest;
                $changes += ['version' => $latest, 'progress_percent' => 0, 'cycle_started_at' => now()];
                $assignment->lessonProgress()->where('course_version', $latest)->whereNotNull('completed_at')->update(['completed_at' => null]);
                if ($assignment->due_at?->isPast() && $assignment->status === TrainingAssignment::COMPLETED) {
                    $changes['due_at'] = null;
                }
            }
            if ($assignment->status !== TrainingAssignment::IN_PROGRESS) {
                $changes['status'] = TrainingAssignment::IN_PROGRESS;
            }
            $changes['started_at'] = $assignment->started_at ?? now();
        }
        $assignment->forceFill($changes)->save();

        return $course->version($number) ?? throw new AuthorizationException('This version is no longer available.');
    }

    private function hasProgress(TrainingAssignment $assignment, int $version): bool
    {
        return $assignment->lessonProgress()->where('course_version', $version)->exists();
    }

    /** @return list<string> lessons finished in this version */
    public function completedLessons(TrainingAssignment $assignment, int $version): array
    {
        return $assignment->lessonProgress()->where('course_version', $version)->whereNotNull('completed_at')->pluck('lesson_ulid')->all();
    }

    public function openLesson(TrainingAssignment $assignment, TrainingCourseVersion $version, string $lessonUlid): TrainingLessonProgress
    {
        return TrainingLessonProgress::query()->firstOrCreate(
            ['assignment_id' => $assignment->id, 'course_version' => $version->version, 'lesson_ulid' => $lessonUlid],
            ['started_at' => now()],
        );
    }

    /** Marks a reading, video, file or link lesson as done. Quizzes complete by passing. */
    public function completeLesson(TrainingAssignment $assignment, TrainingCourseVersion $version, string $lessonUlid, int $seconds): void
    {
        $lesson = $version->lesson($lessonUlid) ?? throw ValidationException::withMessages(['lesson' => 'This lesson isn\'t part of the course.']);
        if ($lesson['type'] === LessonType::Quiz->value) {
            throw ValidationException::withMessages(['lesson' => 'Pass the quiz to complete this lesson.']);
        }
        $this->finishLesson($assignment, $version, $lessonUlid, $seconds);
        $this->refresh($assignment, $version);
    }

    private function finishLesson(TrainingAssignment $assignment, TrainingCourseVersion $version, string $lessonUlid, int $seconds): void
    {
        $seconds = max(0, min($seconds, self::MAX_SECONDS));
        $progress = $this->openLesson($assignment, $version, $lessonUlid);
        if ($progress->completed_at) {
            return;
        }
        $progress->forceFill(['completed_at' => now(), 'seconds_spent' => $progress->seconds_spent + $seconds])->save();
        $assignment->increment('seconds_spent', $seconds);
    }

    /** @return int|null attempts left on a quiz (null = unlimited) */
    public function attemptsLeft(TrainingAssignment $assignment, TrainingCourseVersion $version, string $lessonUlid): ?int
    {
        $quiz = $version->lesson($lessonUlid)['quiz'] ?? null;
        $limit = $assignment->attemptLimit($quiz['max_attempts'] ?? null);
        if ($limit === null) {
            return null;
        }
        $used = $this->attempts($assignment, $version, $lessonUlid)->whereNotNull('submitted_at')->count();

        return max(0, $limit - $used);
    }

    /**
     * Attempts on a quiz in the current cycle.
     *
     * @return Builder<TrainingAttempt>
     */
    public function attempts(TrainingAssignment $assignment, TrainingCourseVersion $version, string $lessonUlid): Builder
    {
        return TrainingAttempt::query()->where('assignment_id', $assignment->id)->where('lesson_ulid', $lessonUlid)->where('course_version', $version->version)
            ->when($assignment->cycle_started_at, fn ($q, $since) => $q->where('created_at', '>=', $since));
    }

    /** Starts (or resumes) an attempt: the question set is fixed when it starts. */
    public function startAttempt(TrainingAssignment $assignment, TrainingCourseVersion $version, string $lessonUlid): TrainingAttempt
    {
        $lesson = $version->lesson($lessonUlid);
        if (! $lesson || $lesson['type'] !== LessonType::Quiz->value) {
            throw ValidationException::withMessages(['lesson' => 'This lesson has no quiz.']);
        }
        $open = $this->attempts($assignment, $version, $lessonUlid)->whereNull('submitted_at')->latest('id')->first();
        if ($open) {
            return $open;
        }
        if ($this->attemptsLeft($assignment, $version, $lessonUlid) === 0) {
            throw ValidationException::withMessages(['attempt' => 'You have used every attempt. Ask your supervisor for another one.']);
        }

        $quiz = $lesson['quiz'];
        $ulids = array_column($quiz['questions'], 'ulid');
        if ($quiz['question_count'] && $quiz['question_count'] < count($ulids)) {
            $ulids = Arr::random($ulids, (int) $quiz['question_count']);
        }
        if ($quiz['shuffle'] || $quiz['question_count']) {
            shuffle($ulids);
        }
        $this->openLesson($assignment, $version, $lessonUlid);

        return TrainingAttempt::create([
            'assignment_id' => $assignment->id,
            'lesson_ulid' => $lessonUlid,
            'course_version' => $version->version,
            'question_ulids' => array_values($ulids),
            'started_at' => now(),
        ]);
    }

    /**
     * Grades an attempt. A question is right when exactly the correct answers are chosen.
     *
     * @param  array<string, string|list<string>>  $answers  question ulid => chosen option id(s)
     */
    public function submitAttempt(TrainingAttempt $attempt, TrainingCourseVersion $version, array $answers): TrainingAttempt
    {
        if ($attempt->submitted_at) {
            throw ValidationException::withMessages(['attempt' => 'This attempt was already submitted.']);
        }
        $quiz = $version->lesson($attempt->lesson_ulid)['quiz'] ?? throw ValidationException::withMessages(['attempt' => 'This quiz no longer exists.']);
        $questions = collect($quiz['questions'])->keyBy('ulid');

        $given = [];
        $results = [];
        $missing = [];
        foreach ($attempt->question_ulids as $i => $ulid) {
            $question = $questions->get($ulid);
            $chosen = array_values(array_unique(array_map('strval', Arr::wrap($answers[$ulid] ?? []))));
            $valid = array_column($question['options'], 'id');
            $chosen = array_values(array_intersect($chosen, $valid));
            if ($chosen === [] || (! QuestionType::from($question['type'])->allowsMany() && count($chosen) > 1)) {
                $missing["answers.{$ulid}"] = 'Answer question '.($i + 1).'.';

                continue;
            }
            $correct = array_column(array_filter($question['options'], fn (array $o) => $o['correct']), 'id');
            sort($chosen);
            sort($correct);
            $given[$ulid] = $chosen;
            $results[$ulid] = $chosen === $correct;
        }
        if ($missing !== []) {
            throw ValidationException::withMessages($missing);
        }

        $score = (int) round(100 * count(array_filter($results)) / max(1, count($results)));
        $passed = $score >= (int) $quiz['pass_percent'];

        return DB::transaction(function () use ($attempt, $version, $given, $results, $score, $passed) {
            $attempt->forceFill(['answers' => $given, 'results' => $results, 'score_percent' => $score, 'passed' => $passed, 'submitted_at' => now()])->save();
            $assignment = $attempt->assignment;
            $seconds = (int) $attempt->started_at->diffInSeconds(now(), true);
            if ($passed) {
                $this->finishLesson($assignment, $version, $attempt->lesson_ulid, $seconds);
            } else {
                $assignment->increment('seconds_spent', max(0, min($seconds, self::MAX_SECONDS)));
            }
            $this->refresh($assignment->fresh(), $version);

            return $attempt;
        });
    }

    /** Recomputes the percentage and completes the course when every lesson is done. */
    public function refresh(TrainingAssignment $assignment, TrainingCourseVersion $version): void
    {
        $total = count($version->lessonUlids());
        $done = count(array_intersect($version->lessonUlids(), $this->completedLessons($assignment, $version->version)));
        $percent = $total ? (int) floor(100 * $done / $total) : 0;
        $assignment->forceFill(['progress_percent' => $percent])->save();

        if ($total > 0 && $done === $total && ! ($assignment->status === TrainingAssignment::COMPLETED && $assignment->completed_version === $version->version)) {
            $this->complete($assignment, $version);
        }
    }

    private function complete(TrainingAssignment $assignment, TrainingCourseVersion $version): void
    {
        $course = $assignment->course;
        $quizLessons = collect($version->lessons())->where('type', LessonType::Quiz->value)->pluck('ulid');
        $score = $quizLessons->isEmpty() ? null : (int) round($quizLessons->map(fn (string $ulid) => (int) $this->attempts($assignment, $version, $ulid)
            ->where('passed', true)->max('score_percent'))->avg());
        $expires = $course->valid_for_months ? now()->addMonths($course->valid_for_months) : null;

        DB::transaction(function () use ($assignment, $version, $course, $score, $expires) {
            $assignment->forceFill([
                'status' => TrainingAssignment::COMPLETED, 'completed_at' => now(), 'completed_version' => $version->version,
                'expires_at' => $expires, 'best_score' => $score, 'progress_percent' => 100,
            ])->save();
            $completion = TrainingCompletion::create([
                'assignment_id' => $assignment->id, 'agent_user_id' => $assignment->agent_user_id, 'course_id' => $course->id,
                'course_version' => $version->version, 'score' => $score, 'seconds_spent' => $assignment->seconds_spent,
                'completed_at' => now(), 'expires_at' => $expires,
            ]);
            $agent = $assignment->agent;
            $this->audit->record('training.completed', $course, [], ['agent' => $agent->name, 'version' => $version->version, 'score' => $score],
                $assignment->organization, $agent, $course->title);

            if ($course->issues_certificate) {
                $certificate = TrainingCertificate::create([
                    'agent_user_id' => $agent->id, 'course_id' => $course->id, 'completion_id' => $completion->id, 'course_version' => $version->version,
                    'name' => $course->certificate_name ?: $course->title, 'issued_at' => now(), 'expires_at' => $expires,
                ]);
                $this->audit->record('training.certificate_issued', $certificate, [], ['agent' => $agent->name, 'number' => $certificate->number],
                    $assignment->organization, $agent, $certificate->name);
            }
        });
    }

    /** Revokes a certificate (e.g. issued in error or misconduct). The record stays. */
    public function revokeCertificate(TrainingCertificate $certificate, User $by, string $reason): void
    {
        $course = $certificate->course;
        $allowed = $course->isPlatformWide()
            ? $by->hasPlatformPermission('training.manage_certifications')
            : $by->hasPermissionIn('training.manage_certifications', $course->organization);
        if (! $allowed) {
            throw new AuthorizationException('You can\'t revoke this certificate.');
        }
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => 'Say why the certificate is revoked.']);
        }
        $certificate->forceFill(['status' => TrainingCertificate::REVOKED, 'revoked_at' => now(), 'revoked_by_user_id' => $by->id, 'revoke_reason' => trim($reason)])->save();
        $this->audit->record('training.certificate_revoked', $certificate, ['status' => 'active'], ['status' => 'revoked', 'reason' => trim($reason)],
            $course->organization, $by, $certificate->name.' · '.$certificate->agent->name);
    }
}
