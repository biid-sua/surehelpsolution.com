<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * One agent's place in one course (brief §1.8, D42): required or optional, who assigned it, due
 * date, and the current cycle's progress. Stored statuses are assigned, in_progress, completed
 * and revoked; overdue, expired and outdated follow from the dates and versions, so they are
 * always right without waiting for a scheduled job.
 *
 * @property Carbon|null $assigned_at
 * @property Carbon|null $due_at
 * @property Carbon|null $cycle_started_at
 * @property Carbon|null $started_at
 * @property Carbon|null $last_accessed_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $expires_at
 * @property Carbon|null $revoked_at
 */
class TrainingAssignment extends Model
{
    public const ASSIGNED = 'assigned';

    public const IN_PROGRESS = 'in_progress';

    public const COMPLETED = 'completed';

    public const REVOKED = 'revoked';

    /** Shown to people: stored status plus what the dates say. */
    public const LABELS = [
        'assigned' => 'Not started',
        'started' => 'Started',
        'in_progress' => 'In progress',
        'completed' => 'Completed',
        'overdue' => 'Overdue',
        'expired' => 'Expired',
        'outdated' => 'Update required',
        'revoked' => 'Removed',
    ];

    protected $fillable = [
        'course_id', 'agent_user_id', 'organization_id', 'rule_id', 'assigned_by_user_id', 'is_required', 'priority', 'status', 'assigned_at',
        'due_at', 'version', 'required_version', 'cycle_started_at', 'started_at', 'last_accessed_at', 'completed_at', 'completed_version', 'expires_at',
        'progress_percent', 'best_score', 'seconds_spent', 'extra_attempts', 'revoked_at', 'revoked_by_user_id', 'revoke_reason',
    ];

    protected $attributes = ['status' => self::ASSIGNED, 'priority' => 'normal', 'is_required' => false, 'required_version' => 1, 'progress_percent' => 0, 'seconds_spent' => 0, 'extra_attempts' => 0];

    protected function casts(): array
    {
        return [
            'is_required' => 'boolean',
            'assigned_at' => 'datetime',
            'due_at' => 'datetime',
            'cycle_started_at' => 'datetime',
            'started_at' => 'datetime',
            'last_accessed_at' => 'datetime',
            'completed_at' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (TrainingAssignment $assignment) {
            $assignment->ulid ??= (string) Str::ulid();
            $assignment->assigned_at ??= now();
        });
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

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<User, $this> */
    public function assigner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by_user_id');
    }

    /** @return BelongsTo<TrainingRule, $this> */
    public function rule(): BelongsTo
    {
        return $this->belongsTo(TrainingRule::class, 'rule_id');
    }

    /** @return HasMany<TrainingLessonProgress, $this> */
    public function lessonProgress(): HasMany
    {
        return $this->hasMany(TrainingLessonProgress::class, 'assignment_id');
    }

    /** @return HasMany<TrainingAttempt, $this> */
    public function attempts(): HasMany
    {
        return $this->hasMany(TrainingAttempt::class, 'assignment_id');
    }

    /** @return HasMany<TrainingCompletion, $this> */
    public function completions(): HasMany
    {
        return $this->hasMany(TrainingCompletion::class, 'assignment_id');
    }

    /** @param Builder<TrainingAssignment> $query */
    public function scopeOpen(Builder $query): void
    {
        $query->where('training_assignments.status', '!=', self::REVOKED);
    }

    /**
     * Only rows whose course the learner may open now: platform-wide courses, or company courses
     * for companies they currently serve (D40). Ending an assignment hides that company's training.
     *
     * @param  Builder<TrainingAssignment>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        $query->whereHas('course', fn (Builder $q) => $q->availableTo($user));
    }

    /**
     * Filters by the status people see (LABELS), in the database, matching effectiveStatus().
     *
     * @param  Builder<TrainingAssignment>  $query
     */
    public function scopeWhereEffectiveStatus(Builder $query, string $status): void
    {
        $now = now();
        $completed = fn (Builder $q) => $q->where('training_assignments.status', self::COMPLETED);
        $notDue = fn (Builder $q) => $q->where(fn (Builder $q) => $q->whereNull('training_assignments.due_at')->orWhere('training_assignments.due_at', '>=', $now));

        match ($status) {
            'revoked' => $query->where('training_assignments.status', self::REVOKED),
            'expired' => $completed($query)->where('training_assignments.expires_at', '<=', $now),
            'outdated' => $completed($query)->where(fn (Builder $q) => $q->whereNull('training_assignments.expires_at')->orWhere('training_assignments.expires_at', '>', $now))
                ->whereColumn('training_assignments.completed_version', '<', 'training_assignments.required_version'),
            'completed' => $completed($query)->where(fn (Builder $q) => $q->whereNull('training_assignments.expires_at')->orWhere('training_assignments.expires_at', '>', $now))
                ->whereColumn('training_assignments.completed_version', '>=', 'training_assignments.required_version'),
            'overdue' => $query->whereIn('training_assignments.status', [self::ASSIGNED, self::IN_PROGRESS])->where('training_assignments.due_at', '<', $now),
            'in_progress' => $notDue($query->where('training_assignments.status', self::IN_PROGRESS)),
            'assigned' => $notDue($query->where('training_assignments.status', self::ASSIGNED)),
            // Not done in any way that counts: everything still to do.
            'open' => $query->where(fn (Builder $q) => $q->whereIn('training_assignments.status', [self::ASSIGNED, self::IN_PROGRESS])
                ->orWhere(fn (Builder $q) => $completed($q)->where(fn (Builder $q) => $q->where('training_assignments.expires_at', '<=', $now)
                    ->orWhereColumn('training_assignments.completed_version', '<', 'training_assignments.required_version')))),
            default => $query,
        };
    }

    /** Completed, on a version that still counts, and not expired. */
    public function isDone(): bool
    {
        return $this->status === self::COMPLETED
            && (int) $this->completed_version >= (int) $this->required_version
            && ! ($this->expires_at && $this->expires_at->isPast());
    }

    public function effectiveStatus(): string
    {
        if ($this->status === self::REVOKED) {
            return 'revoked';
        }
        if ($this->status === self::COMPLETED) {
            if ($this->expires_at && $this->expires_at->isPast()) {
                return 'expired';
            }

            return (int) $this->completed_version < (int) $this->required_version ? 'outdated' : 'completed';
        }
        if ($this->due_at && $this->due_at->isPast()) {
            return 'overdue';
        }
        if ($this->status === self::IN_PROGRESS) {
            return $this->progress_percent > 0 ? 'in_progress' : 'started';
        }

        return 'assigned';
    }

    public function label(): string
    {
        return self::LABELS[$this->effectiveStatus()];
    }

    public function tone(): string
    {
        return match ($this->effectiveStatus()) {
            'completed' => 'success',
            'overdue', 'expired' => 'danger',
            'outdated', 'in_progress', 'started' => 'warning',
            'revoked' => 'neutral',
            default => 'brand',
        };
    }

    /** Attempts allowed on a quiz for this agent (null = unlimited). */
    public function attemptLimit(?int $max): ?int
    {
        return $max ? $max + (int) $this->extra_attempts : null;
    }
}
