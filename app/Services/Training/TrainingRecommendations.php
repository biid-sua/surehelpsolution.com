<?php

namespace App\Services\Training;

use App\Models\AgentAssignment;
use App\Models\TrainingAssignment;
use App\Models\TrainingAttempt;
use App\Models\TrainingCertificate;
use App\Models\TrainingCompletion;
use App\Models\TrainingCourse;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * What to learn next, and why (brief §1.15), most useful first:
 *  1. certifications about to expire, 2. quizzes not passed yet, 3. courses of companies the
 *  agent recently started serving, 4. courses a supervisor suggested, 5. new courses,
 *  6. courses other agents complete most.
 * Only courses the agent may open (platform-wide, or of companies they serve now).
 */
class TrainingRecommendations
{
    /**
     * @return Collection<int, array{course: TrainingCourse, reason: string, kind: string}>
     */
    public function for(User $user, int $limit = 6): Collection
    {
        $available = fn () => TrainingCourse::query()->availableTo($user)->with('organization:id,name');
        $mine = TrainingAssignment::query()->where('agent_user_id', $user->id)->open()->get()->keyBy('course_id');
        $done = $mine->filter->isDone()->keys();
        $picks = collect();
        $add = function (TrainingCourse $course, string $kind, string $reason) use ($picks) {
            if (! $picks->has($course->id)) {
                $picks->put($course->id, ['course' => $course, 'reason' => $reason, 'kind' => $kind]);
            }
        };

        // 1. Certifications expiring within 30 days (or expired and not yet renewed).
        TrainingCertificate::query()->where('agent_user_id', $user->id)->where('status', TrainingCertificate::ACTIVE)
            ->where('expires_at', '<=', now()->addDays(TrainingCertificate::EXPIRING_DAYS))
            ->whereIn('course_id', $available()->select('training_courses.id'))->with('course.organization')->orderBy('expires_at')->get()
            ->reject(fn (TrainingCertificate $c) => TrainingCertificate::query()->where('agent_user_id', $user->id)->where('course_id', $c->course_id)->where('id', '>', $c->id)->exists())
            ->each(fn (TrainingCertificate $c) => $add($c->course, 'renew', $c->expires_at->isPast()
                ? "Your {$c->name} certification expired on {$c->expires_at->format('j M')}. Renew it."
                : "Your {$c->name} certification expires on {$c->expires_at->format('j M')}."));

        // 2. Quizzes tried and not passed in the current cycle.
        $failed = TrainingAttempt::query()->whereIn('assignment_id', $mine->reject->isDone()->pluck('id'))->where('passed', false)->pluck('assignment_id')->unique();
        $mine->whereIn('id', $failed)->each(function (TrainingAssignment $a) use ($available, $add) {
            if ($course = $available()->find($a->course_id)) {
                $add($course, 'retry', 'You haven\'t passed the quiz yet. Review the lessons and try again.');
            }
        });

        // 3. Companies the agent started serving in the last 30 days.
        $recent = AgentAssignment::query()->where('agent_user_id', $user->id)->current()->where('starts_at', '>=', now()->subDays(30))
            ->with('organization:id,name')->get();
        foreach ($recent as $assignment) {
            $available()->where('organization_id', $assignment->organization_id)->whereNotIn('id', $done)->orderBy('title')->get()
                ->each(fn (TrainingCourse $c) => $add($c, 'company', "You recently started supporting {$assignment->organization->name}."));
        }

        // 4. Suggested by a supervisor (optional training they gave), not started yet.
        $mine->filter(fn (TrainingAssignment $a) => ! $a->is_required && $a->assigned_by_user_id && ! $a->started_at)
            ->each(function (TrainingAssignment $a) use ($available, $add) {
                if ($course = $available()->find($a->course_id)) {
                    $add($course, 'suggested', 'Suggested by '.($a->assigner->name ?? 'your supervisor').'.');
                }
            });

        if ($picks->count() < $limit) {
            // 5. New in the last 30 days. 6. What other agents complete most.
            $available()->whereNotIn('id', $mine->keys())->where('published_at', '>=', now()->subDays(30))->latest('published_at')->limit($limit)->get()
                ->each(fn (TrainingCourse $c) => $add($c, 'new', 'New course.'));
            $popular = TrainingCompletion::query()->where('completed_at', '>=', now()->subDays(90))
                ->selectRaw('course_id, COUNT(*) as n')->groupBy('course_id')->orderByDesc('n')->limit(20)->pluck('n', 'course_id');
            $available()->whereNotIn('id', $mine->keys())->whereIn('id', $popular->keys())->get()
                ->sortByDesc(fn (TrainingCourse $c) => $popular[$c->id] ?? 0)
                ->each(fn (TrainingCourse $c) => $add($c, 'popular', 'Popular with other agents.'));
        }

        return $picks->take($limit)->values();
    }
}
