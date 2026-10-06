<?php

namespace App\Services\Training;

use App\Models\Organization;
use App\Models\TrainingAssignment;
use App\Models\TrainingRule;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Whether an agent has completed the training a company requires (spec §20B, D43). Ready means
 * every required course for that company, and every platform or role requirement, is completed,
 * on the version that counts, and not expired. The enforcement level is the strictest among the
 * company's unfinished requirements; platform requirements never block a company on their own.
 */
class TrainingReadiness
{
    public const LEVELS = ['informational' => 0, 'warning' => 1, 'restricted' => 2, 'blocking' => 3];

    /**
     * @return array{ready: bool, required: int, missing: list<string>, enforcement: ?string}
     */
    public function for(User $agent, Organization $organization): array
    {
        $rules = TrainingRule::query()->where('is_active', true)->where('is_required', true)
            ->whereHas('course', fn (Builder $q) => $q->where('is_active', true)->where('current_version', '>', 0)
                ->where(fn (Builder $q) => $q->whereNull('organization_id')->orWhere('organization_id', $organization->id)))
            ->where(fn (Builder $q) => $q->where(fn (Builder $q) => $q->where('scope', TrainingRule::COMPANY)->where('organization_id', $organization->id))
                ->orWhere('scope', TrainingRule::EVERYONE)
                ->orWhere(fn (Builder $q) => $q->where('scope', TrainingRule::ROLE)->whereIn('role', $agent->getRoleNames()->all()))
                ->orWhere(fn (Builder $q) => $q->where('scope', TrainingRule::AGENT)->where('agent_user_id', $agent->id)))
            ->with('course:id,title,organization_id')->get();

        if ($rules->isEmpty()) {
            return ['ready' => true, 'required' => 0, 'missing' => [], 'enforcement' => null];
        }

        $done = TrainingAssignment::query()->where('agent_user_id', $agent->id)->whereIn('course_id', $rules->pluck('course_id'))->open()->get()
            ->filter->isDone()->pluck('course_id')->all();
        $missing = $rules->reject(fn (TrainingRule $r) => in_array($r->course_id, $done, true));

        $level = $missing->map(fn (TrainingRule $r) => $r->scope === TrainingRule::COMPANY ? $r->enforcement : 'warning')
            ->sortByDesc(fn (string $l) => self::LEVELS[$l] ?? 1)->first();

        return [
            'ready' => $missing->isEmpty(),
            'required' => $rules->unique('course_id')->count(),
            'missing' => $missing->pluck('course.title')->unique()->values()->all(),
            'enforcement' => $missing->isEmpty() ? null : $level,
        ];
    }
}
