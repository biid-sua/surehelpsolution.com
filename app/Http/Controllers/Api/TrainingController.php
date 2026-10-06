<?php

namespace App\Http\Controllers\Api;

use App\Enums\LessonType;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\TrainingAssignment;
use App\Models\TrainingAttempt;
use App\Models\TrainingCertificate;
use App\Models\TrainingCourse;
use App\Models\TrainingCourseVersion;
use App\Services\Training\LearningProgress;
use App\Services\Training\TrainingAccess;
use App\Services\Training\TrainingRecommendations;
use App\Support\Training\VideoEmbed;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Agent University for the mobile app (brief §8): the same services and the same rules as the
 * web portal. A course the agent may not take (another company's, unpublished, switched off)
 * is 404, like one that doesn't exist. Correct answers are never sent.
 */
class TrainingController extends Controller
{
    public function __construct(private readonly TrainingAccess $access, private readonly LearningProgress $learning) {}

    public function index(Request $request, TrainingRecommendations $recommendations): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->hasPermissionIn('agent_university.view'), 403);
        $mine = TrainingAssignment::query()->where('agent_user_id', $user->id)->open()->visibleTo($user)
            ->with('course:id,ulid,title,summary,organization_id,estimated_minutes', 'course.organization:id,ulid,name')->get();
        $required = $mine->where('is_required', true);

        return ApiResponse::success([
            'summary' => [
                'progress_percent' => $mine->isEmpty() ? null : (int) round($mine->avg(fn (TrainingAssignment $a) => $a->isDone() ? 100 : $a->progress_percent)),
                'required' => $required->count(),
                'required_completed' => $required->filter->isDone()->count(),
                'overdue' => $mine->filter(fn (TrainingAssignment $a) => $a->effectiveStatus() === 'overdue')->count(),
            ],
            'assignments' => $mine->sortBy(fn (TrainingAssignment $a) => [$a->isDone() ? 1 : 0, $a->is_required ? 0 : 1, $a->due_at->timestamp ?? PHP_INT_MAX])
                ->map(fn (TrainingAssignment $a) => $this->assignment($a))->values(),
            'recommended' => $recommendations->for($user)->map(fn (array $p) => $this->course($p['course']) + ['reason' => $p['reason']])->values(),
        ]);
    }

    public function show(Request $request, string $course): JsonResponse
    {
        $model = $this->learnable($request, $course);
        $assignment = TrainingAssignment::query()->where('course_id', $model->id)->where('agent_user_id', $request->user()->id)->open()->first();
        $number = match (true) {
            $assignment?->isDone() => (int) $assignment->completed_version,
            $assignment && $assignment->version && $assignment->version >= $assignment->required_version => (int) $assignment->version,
            default => $model->current_version,
        };
        $version = $model->version($number) ?? $model->version($model->current_version) ?? throw new NotFoundHttpException;
        $done = match (true) {
            $assignment === null => [],
            $assignment->isDone() => $version->lessonUlids(),
            $assignment->version === $version->version => $this->learning->completedLessons($assignment, $version->version),
            default => [],
        };

        return ApiResponse::success(['course' => $this->course($model) + [
            'description' => $model->description,
            'version' => $version->version,
            'valid_for_months' => $model->valid_for_months,
            'certificate_name' => $model->issues_certificate ? $model->certificate_name : null,
            'assignment' => $assignment ? $this->assignment($assignment) : null,
            'modules' => array_map(fn (array $m) => [
                'title' => $m['title'],
                'description' => $m['description'],
                'lessons' => array_map(fn (array $l) => [
                    'id' => $l['ulid'], 'title' => $l['title'], 'type' => $l['type'], 'duration_minutes' => $l['duration_minutes'],
                    'completed' => in_array($l['ulid'], $done, true),
                ], $m['lessons']),
            ], $version->content['modules']),
        ]]);
    }

    /** Starts or continues: the version being taken and the next lesson to open. */
    public function start(Request $request, string $course): JsonResponse
    {
        [$model, $assignment, $version] = $this->begin($request, $course);
        $done = $this->learning->completedLessons($assignment, $version->version);
        $next = collect($version->lessonUlids())->first(fn (string $u) => ! in_array($u, $done, true)) ?? $version->lessonUlids()[0];

        return ApiResponse::success(['version' => $version->version, 'next_lesson' => $next, 'assignment' => $this->assignment($assignment->fresh())]);
    }

    public function lesson(Request $request, string $course, string $lesson): JsonResponse
    {
        [$model, $assignment, $version] = $this->begin($request, $course);
        $data = $version->lesson($lesson) ?? throw new NotFoundHttpException;
        $this->learning->openLesson($assignment, $version, $lesson);
        $type = LessonType::from($data['type']);
        $quiz = null;
        if ($type === LessonType::Quiz) {
            $open = $this->learning->attempts($assignment, $version, $lesson)->whereNull('submitted_at')->latest('id')->first();
            $quiz = [
                'pass_percent' => $data['quiz']['pass_percent'],
                'questions' => $data['quiz']['question_count'] ?: count($data['quiz']['questions']),
                'attempts_left' => $this->learning->attemptsLeft($assignment, $version, $lesson),
                'open_attempt' => $open ? $this->attempt($open, $version) : null,
            ];
        }

        return ApiResponse::success(['lesson' => [
            'id' => $data['ulid'],
            'title' => $data['title'],
            'module' => $data['module'],
            'type' => $data['type'],
            'body_markdown' => $data['body'],
            'body_html' => filled($data['body']) ? (string) Str::markdown($data['body'], ['html_input' => 'strip', 'allow_unsafe_links' => false]) : null,
            'url' => $data['url'],
            'embed_url' => $type === LessonType::Video ? VideoEmbed::url($data['url']) : null,
            'file' => $data['file'] ? [
                'name' => $data['file']['name'], 'mime' => $data['file']['mime'], 'size' => $data['file']['size'],
                'url' => url("/api/v1/agent/training/courses/{$model->ulid}/v{$version->version}/files/{$data['ulid']}"),
            ] : null,
            'duration_minutes' => $data['duration_minutes'],
            'completed' => in_array($lesson, $this->learning->completedLessons($assignment, $version->version), true),
            'quiz' => $quiz,
        ]]);
    }

    public function complete(Request $request, string $course, string $lesson): JsonResponse
    {
        $request->validate(['seconds' => ['nullable', 'integer', 'min:0', 'max:86400']]);
        [, $assignment, $version] = $this->begin($request, $course);
        $progress = $this->learning->openLesson($assignment, $version, $lesson);
        // Never more than the time since the lesson was first opened.
        $seconds = min((int) $request->input('seconds', 0), (int) $progress->started_at->diffInSeconds(now(), true));
        $this->learning->completeLesson($assignment, $version, $lesson, $seconds);

        return ApiResponse::success(['assignment' => $this->assignment($assignment->fresh())]);
    }

    public function startAttempt(Request $request, string $course, string $lesson): JsonResponse
    {
        [, $assignment, $version] = $this->begin($request, $course);
        $attempt = $this->learning->startAttempt($assignment, $version, $lesson);

        return ApiResponse::success(['attempt' => $this->attempt($attempt, $version)]);
    }

    public function submitAttempt(Request $request, string $attempt): JsonResponse
    {
        $request->validate(['answers' => ['required', 'array']]);
        $user = $request->user();
        $model = TrainingAttempt::query()->where('ulid', $attempt)
            ->whereHas('assignment', fn ($q) => $q->where('agent_user_id', $user->id)->where('status', '!=', TrainingAssignment::REVOKED))
            ->with('assignment.course')->first() ?? throw new NotFoundHttpException;
        $course = $model->assignment->course;
        abort_unless($this->access->canLearn($user, $course), 404);
        $version = $course->version($model->course_version) ?? throw new NotFoundHttpException;

        $result = $this->learning->submitAttempt($model, $version, (array) $request->input('answers'));
        $assignment = $model->assignment->fresh();

        return ApiResponse::success([
            'attempt' => $this->attempt($result, $version),
            'attempts_left' => $this->learning->attemptsLeft($assignment, $version, $model->lesson_ulid),
            'assignment' => $this->assignment($assignment),
        ]);
    }

    public function certificates(Request $request): JsonResponse
    {
        $user = $request->user();

        return ApiResponse::success(['certificates' => TrainingCertificate::query()->where('agent_user_id', $user->id)->with('course:id,ulid,title')
            ->latest('issued_at')->get()->map(fn (TrainingCertificate $c) => [
                'id' => $c->ulid,
                'number' => $c->number,
                'name' => $c->name,
                'course' => ['id' => $c->course->ulid, 'title' => $c->course->title],
                'version' => $c->course_version,
                'status' => $c->effectiveStatus(),
                'issued_at' => $c->issued_at->toIso8601String(),
                'expires_at' => $c->expires_at?->toIso8601String(),
                'url' => route('agent.university.certificate', $c->ulid),
            ])->values()]);
    }

    private function learnable(Request $request, string $ulid): TrainingCourse
    {
        $course = TrainingCourse::query()->where('ulid', $ulid)->with('organization:id,ulid,name')->first();
        if (! $course || ! $this->access->canLearn($request->user(), $course)) {
            throw new NotFoundHttpException;
        }

        return $course;
    }

    /** @return array{0: TrainingCourse, 1: TrainingAssignment, 2: TrainingCourseVersion} */
    private function begin(Request $request, string $ulid): array
    {
        $course = $this->learnable($request, $ulid);
        try {
            $assignment = $this->learning->enrol($request->user(), $course);
            $version = $this->learning->begin($assignment);
        } catch (AuthorizationException) {
            throw new NotFoundHttpException;
        }

        return [$course, $assignment, $version];
    }

    /** @return array<string, mixed> */
    private function course(TrainingCourse $course): array
    {
        return [
            'id' => $course->ulid,
            'title' => $course->title,
            'summary' => $course->summary,
            'company' => $course->organization ? ['id' => $course->organization->ulid, 'name' => $course->organization->name] : null,
            'estimated_minutes' => $course->estimated_minutes,
        ];
    }

    /** @return array<string, mixed> */
    private function assignment(TrainingAssignment $a): array
    {
        return [
            'course' => $a->relationLoaded('course') ? $this->course($a->course) : null,
            'required' => $a->is_required,
            'priority' => $a->priority,
            'status' => $a->effectiveStatus(),
            'status_label' => $a->label(),
            'progress_percent' => $a->isDone() ? 100 : $a->progress_percent,
            'best_score' => $a->best_score,
            'due_at' => $a->due_at?->toIso8601String(),
            'completed_at' => $a->completed_at?->toIso8601String(),
            'expires_at' => $a->expires_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> questions without the correct answers; results once submitted */
    private function attempt(TrainingAttempt $attempt, TrainingCourseVersion $version): array
    {
        $questions = collect($version->lesson($attempt->lesson_ulid)['quiz']['questions'] ?? [])->keyBy('ulid');
        $submitted = $attempt->submitted_at !== null;

        return [
            'id' => $attempt->ulid,
            'lesson' => $attempt->lesson_ulid,
            'submitted' => $submitted,
            'score_percent' => $attempt->score_percent,
            'passed' => $attempt->passed,
            'questions' => collect($attempt->question_ulids)->map(fn (string $u) => $questions->get($u))->filter()->map(fn (array $q) => [
                'id' => $q['ulid'],
                'type' => $q['type'],
                'scenario' => $q['scenario'],
                'prompt' => $q['prompt'],
                'options' => array_map(fn (array $o) => ['id' => $o['id'], 'label' => $o['label']], $q['options']),
                'correct' => $submitted ? (bool) ($attempt->results[$q['ulid']] ?? false) : null,
                'explanation' => $submitted && ! ($attempt->results[$q['ulid']] ?? false) ? $q['explanation'] : null,
            ])->values()->all(),
        ];
    }
}
