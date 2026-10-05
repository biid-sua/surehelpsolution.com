<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A quality review of one call (spec SUP-04). Internal to SureHelp: businesses never see these.
 *
 * `scores` keeps the scorecard as it was when the call was scored:
 * criterion key => ['label', 'weight', 'critical', 'mark' (met|partly|missed|na)].
 *
 * @property array<string, array{label: string, weight: int, critical: bool, mark: string}>|null $scores
 * @property Carbon|null $reviewed_at
 * @property Carbon|null $acknowledged_at
 */
class QaReview extends Model
{
    public const SOURCE_SAMPLE = 'sample';

    public const SOURCE_MANUAL = 'manual';

    public const PENDING = 'pending';

    public const COMPLETED = 'completed';

    public const MARKS = ['met' => 'Met', 'partly' => 'Partly', 'missed' => 'Missed', 'na' => "Doesn't apply"];

    protected $fillable = [
        'call_log_id', 'organization_id', 'agent_user_id', 'reviewer_id', 'source', 'status',
        'scores', 'score', 'passed', 'strengths', 'improvements', 'reviewed_at', 'acknowledged_at',
    ];

    protected $attributes = [
        'status' => self::PENDING,
    ];

    protected function casts(): array
    {
        return [
            'scores' => 'array',
            'score' => 'integer',
            'passed' => 'boolean',
            'reviewed_at' => 'datetime',
            'acknowledged_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (QaReview $review) {
            $review->ulid ??= (string) Str::ulid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    /**
     * @return BelongsTo<CallLog, $this>
     */
    public function call(): BelongsTo
    {
        return $this->belongsTo(CallLog::class, 'call_log_id')->withoutGlobalScopes();
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    /**
     * @param  Builder<QaReview>  $query
     */
    public function scopePending(Builder $query): void
    {
        $query->where('status', self::PENDING);
    }

    /**
     * @param  Builder<QaReview>  $query
     */
    public function scopeCompleted(Builder $query): void
    {
        $query->where('status', self::COMPLETED);
    }

    public function isCompleted(): bool
    {
        return $this->status === self::COMPLETED;
    }

    /**
     * A critical point (compliance) was missed: the review fails whatever the total.
     */
    public function missedCritical(): bool
    {
        return collect($this->scores ?? [])->contains(fn (array $c) => $c['critical'] && $c['mark'] === 'missed');
    }

    public function scoreTone(): string
    {
        return match (true) {
            $this->score === null => 'neutral',
            (bool) $this->passed => 'success',
            $this->score >= 60 && ! $this->missedCritical() => 'warning',
            default => 'danger',
        };
    }
}
