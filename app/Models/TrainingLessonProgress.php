<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One lesson of one version, for one agent's assignment (brief §1.9).
 *
 * @property Carbon $started_at
 * @property Carbon|null $completed_at
 */
class TrainingLessonProgress extends Model
{
    protected $table = 'training_lesson_progress';

    protected $fillable = ['assignment_id', 'lesson_ulid', 'course_version', 'started_at', 'completed_at', 'seconds_spent'];

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'completed_at' => 'datetime'];
    }

    /** @return BelongsTo<TrainingAssignment, $this> */
    public function assignment(): BelongsTo
    {
        return $this->belongsTo(TrainingAssignment::class, 'assignment_id');
    }
}
