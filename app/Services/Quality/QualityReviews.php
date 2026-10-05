<?php

namespace App\Services\Quality;

use App\Models\CallLog;
use App\Models\QaReview;
use App\Models\User;
use App\Notifications\QaReviewCompleted;
use App\Support\Audit\Audit;
use App\Support\Authorization\RoleCatalog;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Call quality reviews (spec SUP-04, docs/decisions.md D27): who may score which calls, the
 * scorecard maths, the daily random sample, and saving a review.
 */
class QualityReviews
{
    public function __construct(
        private readonly RoleCatalog $catalog,
        private readonly Audit $audit,
    ) {}

    /**
     * The scorecard as configured now.
     *
     * @return array<string, array{label: string, description: string, weight: int, optional: bool, critical?: bool}>
     */
    public function scorecard(): array
    {
        return config('quality.criteria');
    }

    /**
     * Organizations whose calls the user may score: null means all of them (platform roles),
     * otherwise the businesses a supervisor is assigned to.
     *
     * @return list<int>|null
     */
    public function organizationScope(User $user): ?array
    {
        $user->loadMissing('roles.permissions');
        $assigned = false;

        foreach ($user->roles as $role) {
            if (! $role->getRelationValue('permissions')->contains('name', 'qa.review')) {
                continue;
            }
            if ($this->catalog->roleScope((string) $role->getAttribute('name')) === 'platform') {
                return null;
            }
            $assigned = true;
        }

        return $assigned ? $user->assignedOrganizations()->pluck('organizations.id')->all() : [];
    }

    public function canReviewAny(User $user): bool
    {
        return $this->organizationScope($user) !== [];
    }

    /**
     * Agent calls this user may score. Nobody scores their own calls.
     *
     * @return Builder<CallLog>
     */
    public function reviewableCalls(User $user): Builder
    {
        $scope = $this->organizationScope($user);

        return CallLog::withoutGlobalScopes()
            ->whereNotNull('call_logs.user_id')
            ->where('call_logs.user_id', '!=', $user->id)
            ->when($scope !== null, fn (Builder $q) => $q->whereIn('call_logs.organization_id', $scope));
    }

    /**
     * Reviews this user may see or complete as a reviewer.
     *
     * @return Builder<QaReview>
     */
    public function reviewable(User $user): Builder
    {
        $scope = $this->organizationScope($user);

        return QaReview::query()
            ->where(fn (Builder $q) => $q->whereNull('agent_user_id')->orWhere('agent_user_id', '!=', $user->id))
            ->when($scope !== null, fn (Builder $q) => $q->whereIn('organization_id', $scope));
    }

    /**
     * Open (or reopen) the review of a call a reviewer picked themselves.
     */
    public function start(CallLog $call, User $reviewer): QaReview
    {
        abort_unless($this->reviewableCalls($reviewer)->whereKey($call->id)->exists(), 403);

        return QaReview::firstOrCreate(['call_log_id' => $call->id], [
            'organization_id' => $call->organization_id,
            'agent_user_id' => $call->user_id,
            'source' => QaReview::SOURCE_MANUAL,
        ]);
    }

    /**
     * Score and percentage for a set of marks against the current scorecard.
     *
     * @param  array<string, string>  $marks  criterion key => met|partly|missed|na
     * @return array{scores: array<string, array{label: string, weight: int, critical: bool, mark: string}>, score: int, passed: bool}
     */
    public function score(array $marks): array
    {
        $errors = [];
        $scores = [];
        $earned = 0;
        $possible = 0;

        foreach ($this->scorecard() as $key => $criterion) {
            $mark = $marks[$key] ?? null;
            $allowed = $criterion['optional'] ? ['met', 'partly', 'missed', 'na'] : ['met', 'partly', 'missed'];

            if (! in_array($mark, $allowed, true)) {
                $errors["marks.$key"] = "Mark {$criterion['label']}.";

                continue;
            }

            $scores[$key] = [
                'label' => $criterion['label'],
                'weight' => (int) $criterion['weight'],
                'critical' => (bool) ($criterion['critical'] ?? false),
                'mark' => $mark,
            ];

            if ($mark !== 'na') {
                $possible += 2 * $criterion['weight'];
                $earned += ['met' => 2, 'partly' => 1, 'missed' => 0][$mark] * $criterion['weight'];
            }
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        $score = $possible > 0 ? (int) round(100 * $earned / $possible) : 100;
        $missedCritical = collect($scores)->contains(fn (array $c) => $c['critical'] && $c['mark'] === 'missed');

        return [
            'scores' => $scores,
            'score' => $score,
            'passed' => ! $missedCritical && $score >= (int) config('quality.pass_score'),
        ];
    }

    /**
     * Save a review and tell the agent. Re-scoring a finished review keeps the agent's history
     * honest: it is recorded in the audit log and the agent is told again.
     *
     * @param  array<string, string>  $marks
     */
    public function complete(QaReview $review, User $reviewer, array $marks, ?string $strengths, ?string $improvements): QaReview
    {
        abort_unless($this->reviewable($reviewer)->whereKey($review->id)->exists(), 403);

        $errors = blank($strengths) && blank($improvements) ? ['improvements' => 'Write at least one line of feedback for the agent.'] : [];
        try {
            $result = $this->score($marks);
        } catch (ValidationException $e) {
            throw ValidationException::withMessages($e->errors() + $errors);
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
        $wasCompleted = $review->isCompleted();

        DB::transaction(function () use ($review, $reviewer, $result, $strengths, $improvements, $wasCompleted) {
            $review->fill([
                'reviewer_id' => $reviewer->id,
                'status' => QaReview::COMPLETED,
                'scores' => $result['scores'],
                'score' => $result['score'],
                'passed' => $result['passed'],
                'strengths' => filled($strengths) ? Str::limit(trim($strengths), 2000, '') : null,
                'improvements' => filled($improvements) ? Str::limit(trim($improvements), 2000, '') : null,
                'reviewed_at' => now(),
                'acknowledged_at' => null,
            ])->save();

            $this->audit->record($wasCompleted ? 'qa_review.rescored' : 'qa_review.completed', $review,
                new: ['call_id' => $review->call?->call_id, 'score' => $result['score'], 'passed' => $result['passed']],
                organization: $review->organization);
        });

        if ($review->agent && $review->agent->is_active) {
            $review->agent->notify(new QaReviewCompleted($review));
        }

        return $review;
    }

    /**
     * Draw the daily random sample: for each agent who logged calls on the given day, pick
     * `quality.sample_per_agent` calls not already reviewed.
     */
    public function drawSample(CarbonInterface $day): int
    {
        $perAgent = max(0, (int) config('quality.sample_per_agent'));
        if ($perAgent === 0) {
            return 0;
        }

        $from = $day->copy()->startOfDay();
        $until = $day->copy()->endOfDay();
        $base = fn () => CallLog::withoutGlobalScopes()
            ->whereNotNull('user_id')->whereNotNull('organization_id')
            ->whereBetween('created_at', [$from, $until])
            ->whereDoesntHave('qaReview');

        $created = 0;
        foreach ($base()->distinct()->pluck('user_id') as $agentId) {
            $picked = $base()->where('user_id', $agentId)->inRandomOrder()->limit($perAgent)->get(['id', 'organization_id', 'user_id']);

            foreach ($picked as $call) {
                QaReview::firstOrCreate(['call_log_id' => $call->id], [
                    'organization_id' => $call->organization_id,
                    'agent_user_id' => $call->user_id,
                    'source' => QaReview::SOURCE_SAMPLE,
                ])->wasRecentlyCreated && $created++;
            }
        }

        return $created;
    }

    /**
     * An agent's average score and pass rate over completed reviews since a date.
     *
     * @return array{count: int, average: int|null, pass_rate: int|null}
     */
    public function summaryFor(User $agent, CarbonInterface $since): array
    {
        $row = QaReview::query()->completed()->where('agent_user_id', $agent->id)->where('reviewed_at', '>=', $since)
            ->selectRaw('COUNT(*) as reviews, AVG(score) as average, SUM(CASE WHEN passed THEN 1 ELSE 0 END) as passes')
            ->first();
        $count = (int) $row?->getAttribute('reviews');

        return [
            'count' => $count,
            'average' => $count ? (int) round((float) $row->getAttribute('average')) : null,
            'pass_rate' => $count ? (int) round(100 * (int) $row->getAttribute('passes') / $count) : null,
        ];
    }
}
