<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A finished course, kept forever (brief §1.16–1.17): the version completed, the score, the time
 * spent and when it expires. Retaking a course adds a new row instead of changing this one.
 *
 * @property Carbon $completed_at
 * @property Carbon|null $expires_at
 */
class TrainingCompletion extends Model
{
    protected $fillable = ['assignment_id', 'agent_user_id', 'course_id', 'course_version', 'score', 'seconds_spent', 'completed_at', 'expires_at'];

    protected function casts(): array
    {
        return ['completed_at' => 'datetime', 'expires_at' => 'datetime'];
    }

    /** @return BelongsTo<TrainingCourse, $this> */
    public function course(): BelongsTo
    {
        return $this->belongsTo(TrainingCourse::class, 'course_id');
    }

    /** @return BelongsTo<User, $this> */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_user_id');
    }
}
