<?php

namespace App\Models;

use App\Enums\EscalationPriority;
use App\Enums\EscalationStatus;
use App\Enums\EscalationType;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\StoresUtc;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Something that needs the business's attention now (spec §25).
 *
 * @property EscalationType $type
 * @property EscalationPriority $priority
 * @property EscalationStatus $status
 * @property Carbon|null $acknowledged_at
 * @property Carbon|null $resolved_at
 * @property Carbon|null $reminded_at
 */
class Escalation extends Model
{
    use BelongsToOrganization, StoresUtc;

    protected $fillable = [
        'organization_id', 'customer_id', 'call_log_id', 'type', 'priority', 'status', 'reason', 'details', 'source',
        'raised_by_user_id', 'assigned_to_user_id', 'acknowledged_at', 'acknowledged_by_user_id',
        'resolved_at', 'resolved_by_user_id', 'resolution_notes', 'reminded_at',
    ];

    protected $attributes = [
        'priority' => 'high',
        'status' => 'open',
        'source' => 'manual',
    ];

    protected function casts(): array
    {
        return [
            'type' => EscalationType::class,
            'priority' => EscalationPriority::class,
            'status' => EscalationStatus::class,
            'acknowledged_at' => 'datetime',
            'resolved_at' => 'datetime',
            'reminded_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Escalation $escalation) {
            $escalation->ulid ??= (string) Str::ulid();
        });
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    /** @return BelongsTo<CallLog, $this> */
    public function call(): BelongsTo
    {
        return $this->belongsTo(CallLog::class, 'call_log_id');
    }

    /** @return BelongsTo<User, $this> */
    public function raisedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'raised_by_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function acknowledgedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_user_id');
    }

    /**
     * Not resolved yet.
     *
     * @param  Builder<Escalation>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereIn('status', EscalationStatus::activeValues());
    }

    /**
     * Urgent first, then oldest first: the one that has waited longest is on top.
     *
     * @param  Builder<Escalation>  $query
     */
    public function scopeByUrgency(Builder $query): void
    {
        $query->orderByRaw("CASE status WHEN 'open' THEN 0 WHEN 'acknowledged' THEN 1 ELSE 2 END")
            ->orderByRaw("CASE priority WHEN 'urgent' THEN 0 WHEN 'high' THEN 1 ELSE 2 END")
            ->orderBy('created_at')
            ->orderBy('id');
    }
}
