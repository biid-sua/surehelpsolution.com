<?php

namespace App\Models;

use App\Models\Concerns\StoresUtc;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An agent's assignment to serve one company (spec §20A, D40). Never deleted: ending or revoking
 * keeps the row, so the history of who served which company, when and why stays auditable.
 *
 * "Current" is decided by the query (status, start, end), not by a scheduler, so access is right
 * to the second even if the sweep runs late.
 *
 * @property Carbon|null $starts_at
 * @property Carbon|null $ends_at
 * @property Carbon|null $status_changed_at
 */
class AgentAssignment extends Model
{
    use StoresUtc;

    public const SCHEDULED = 'scheduled';

    public const ACTIVE = 'active';

    public const SUSPENDED = 'suspended';

    public const ENDED = 'ended';

    public const REVOKED = 'revoked';

    public const TYPES = ['standard' => 'Standard', 'backup' => 'Backup', 'temporary' => 'Temporary cover'];

    protected $fillable = [
        'organization_id', 'agent_user_id', 'status', 'assignment_type', 'starts_at', 'ends_at', 'is_primary', 'source',
        'assigned_by_user_id', 'ended_by_user_id', 'end_reason', 'notes', 'status_changed_at',
    ];

    protected $attributes = ['status' => self::ACTIVE, 'assignment_type' => 'standard', 'source' => 'manual', 'is_primary' => false];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'status_changed_at' => 'datetime',
            'is_primary' => 'boolean',
        ];
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
    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function endedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ended_by_user_id');
    }

    /**
     * Gives access right now: active (or scheduled and started) and not past its end.
     *
     * @param  Builder<AgentAssignment>  $query
     */
    public function scopeCurrent(Builder $query, ?CarbonInterface $now = null): void
    {
        self::applyCurrent($query, $now, $query->getModel()->getTable());
    }

    /**
     * Not finished: current, scheduled for later, or suspended. At most one per agent and company.
     *
     * @param  Builder<AgentAssignment>  $query
     */
    public function scopeOpen(Builder $query, ?CarbonInterface $now = null): void
    {
        $query->whereIn('status', [self::SCHEDULED, self::ACTIVE, self::SUSPENDED])
            ->where(fn (Builder $q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', $now ?? now()));
    }

    /**
     * The single definition of "currently assigned", shared with the User and Organization relations.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<*>|\Illuminate\Database\Eloquent\Relations\BelongsToMany<*, *>|\Illuminate\Database\Query\Builder  $query
     */
    public static function applyCurrent($query, ?CarbonInterface $now = null, string $table = 'agent_assignments'): void
    {
        $now ??= now();
        $query->whereIn($table.'.status', [self::ACTIVE, self::SCHEDULED])
            ->where(fn ($q) => $q->whereNull($table.'.starts_at')->orWhere($table.'.starts_at', '<=', $now))
            ->where(fn ($q) => $q->whereNull($table.'.ends_at')->orWhere($table.'.ends_at', '>', $now));
    }

    public function isCurrent(?CarbonInterface $now = null): bool
    {
        $now ??= now();

        return in_array($this->status, [self::ACTIVE, self::SCHEDULED], true)
            && ($this->starts_at === null || $this->starts_at->lessThanOrEqualTo($now))
            && ($this->ends_at === null || $this->ends_at->greaterThan($now));
    }

    /** What the status really is right now (a scheduled one that has started is active; an expired one has ended). */
    public function effectiveStatus(?CarbonInterface $now = null): string
    {
        $now ??= now();
        if (in_array($this->status, [self::ENDED, self::REVOKED], true)) {
            return $this->status;
        }
        if ($this->ends_at && $this->ends_at->lessThanOrEqualTo($now)) {
            return self::ENDED;
        }
        if ($this->status === self::SCHEDULED && $this->starts_at && $this->starts_at->lessThanOrEqualTo($now)) {
            return self::ACTIVE;
        }

        return $this->status;
    }

    public static function label(string $status): string
    {
        return match ($status) {
            self::SCHEDULED => 'Scheduled',
            self::ACTIVE => 'Active',
            self::SUSPENDED => 'Suspended',
            self::ENDED => 'Ended',
            self::REVOKED => 'Revoked',
            default => ucfirst($status),
        };
    }

    public static function tone(string $status): string
    {
        return match ($status) {
            self::ACTIVE => 'success',
            self::SCHEDULED => 'info',
            self::SUSPENDED => 'warning',
            self::REVOKED => 'danger',
            default => 'neutral',
        };
    }

    /** Created by the old "assign everyone" behaviour rather than a person: shown for review (D40). */
    public function needsReview(): bool
    {
        return in_array($this->source, ['migration', 'automatic'], true) && $this->assigned_by_user_id === null && $this->isCurrent();
    }
}
