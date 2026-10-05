<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * An agent's request to hand a shift to a colleague (swap) or for days off (leave).
 *
 * @property Carbon|null $leave_from
 * @property Carbon|null $leave_until
 * @property Carbon|null $decided_at
 */
class ShiftRequest extends Model
{
    public const SWAP = 'swap';

    public const LEAVE = 'leave';

    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const DECLINED = 'declined';

    public const CANCELLED = 'cancelled';

    public const STATUS_LABELS = [self::PENDING => 'Waiting', self::APPROVED => 'Approved', self::DECLINED => 'Declined', self::CANCELLED => 'Withdrawn'];

    protected $fillable = [
        'agent_id', 'type', 'shift_id', 'swap_with_id', 'leave_from', 'leave_until', 'reason',
        'status', 'decided_by', 'decided_at', 'decision_note',
    ];

    protected $attributes = [
        'status' => self::PENDING,
    ];

    protected function casts(): array
    {
        return [
            'leave_from' => 'date',
            'leave_until' => 'date',
            'decided_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (ShiftRequest $request) {
            $request->ulid ??= (string) Str::ulid();
        });
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_id');
    }

    /**
     * @return BelongsTo<AgentDutySchedule, $this>
     */
    public function shift(): BelongsTo
    {
        return $this->belongsTo(AgentDutySchedule::class, 'shift_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function swapWith(): BelongsTo
    {
        return $this->belongsTo(User::class, 'swap_with_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /**
     * @param  Builder<ShiftRequest>  $query
     */
    public function scopePending(Builder $query): void
    {
        $query->where('status', self::PENDING);
    }

    public function isPending(): bool
    {
        return $this->status === self::PENDING;
    }

    public function statusTone(): string
    {
        return match ($this->status) {
            self::APPROVED => 'success',
            self::DECLINED => 'danger',
            self::PENDING => 'warning',
            default => 'neutral',
        };
    }

    /**
     * One line describing what was asked, for lists and notifications.
     */
    public function summary(): string
    {
        if ($this->type === self::LEAVE) {
            $from = $this->leave_from?->format('D M j');
            $until = $this->leave_until?->format('D M j');

            return 'Time off '.($from === $until ? $from : "{$from} – {$until}");
        }

        $shift = $this->shift;
        $when = $shift ? $shift->start_datetime->format('D M j, g:i A').' – '.$shift->end_datetime->format('g:i A') : 'a removed shift';

        return "Hand over {$when}".($this->swapWith ? ' to '.$this->swapWith->name : '');
    }
}
