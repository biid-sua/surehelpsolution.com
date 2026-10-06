<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * One try at a quiz (brief §1.10). Every attempt is kept: the questions shown, the answers given,
 * which were right, and the score.
 *
 * @property list<string> $question_ulids
 * @property array<string, list<string>>|null $answers
 * @property array<string, bool>|null $results
 * @property Carbon $started_at
 * @property Carbon|null $submitted_at
 */
class TrainingAttempt extends Model
{
    protected $fillable = ['assignment_id', 'lesson_ulid', 'course_version', 'question_ulids', 'answers', 'results', 'score_percent', 'passed', 'started_at', 'submitted_at'];

    protected function casts(): array
    {
        return [
            'question_ulids' => 'array',
            'answers' => 'array',
            'results' => 'array',
            'passed' => 'boolean',
            'started_at' => 'datetime',
            'submitted_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (TrainingAttempt $attempt) {
            $attempt->ulid ??= (string) Str::ulid();
        });
    }

    /** @return BelongsTo<TrainingAssignment, $this> */
    public function assignment(): BelongsTo
    {
        return $this->belongsTo(TrainingAssignment::class, 'assignment_id');
    }
}
