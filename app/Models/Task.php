<?php

namespace App\Models;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\StoresUtc;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A call-back, follow-up or to-do for a business's team (spec §24).
 *
 * @property TaskType $type
 * @property TaskPriority $priority
 * @property TaskStatus $status
 * @property Carbon|null $due_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $overdue_notified_at
 */
class Task extends Model
{
    use BelongsToOrganization, StoresUtc;

    public const SOURCES = ['manual', 'call', 'backfill', 'ai', 'website'];

    protected $fillable = [
        'organization_id', 'customer_id', 'call_log_id', 'type', 'title', 'description', 'priority', 'status',
        'source', 'due_at', 'assigned_to_user_id', 'created_by_user_id', 'completed_at', 'completed_by_user_id',
        'overdue_notified_at',
    ];

    protected $attributes = [
        'type' => 'todo',
        'priority' => 'normal',
        'status' => 'open',
        'source' => 'manual',
    ];

    protected function casts(): array
    {
        return [
            'type' => TaskType::class,
            'priority' => TaskPriority::class,
            'status' => TaskStatus::class,
            'due_at' => 'datetime',
            'completed_at' => 'datetime',
            'overdue_notified_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Task $task) {
            $task->ulid ??= (string) Str::ulid();
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
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function completer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by_user_id');
    }

    /**
     * Still to do: open or in progress.
     *
     * @param  Builder<Task>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->whereIn('status', TaskStatus::openValues());
    }

    /**
     * @param  Builder<Task>  $query
     */
    public function scopeOverdue(Builder $query, ?CarbonInterface $now = null): void
    {
        $query->whereIn('status', TaskStatus::openValues())->whereNotNull('due_at')->where('due_at', '<', $now ?? now());
    }

    /**
     * Call-backs and follow-ups (not plain to-dos).
     *
     * @param  Builder<Task>  $query
     */
    public function scopeFollowUps(Builder $query): void
    {
        $query->whereIn('type', [TaskType::Callback->value, TaskType::FollowUp->value]);
    }

    /**
     * Most pressing first: soonest due (overdue first), then priority, then oldest.
     *
     * @param  Builder<Task>  $query
     */
    public function scopeByUrgency(Builder $query): void
    {
        $query->orderByRaw('due_at IS NULL')
            ->orderBy('due_at')
            ->orderByRaw("CASE priority WHEN 'urgent' THEN 0 WHEN 'high' THEN 1 WHEN 'normal' THEN 2 ELSE 3 END")
            ->orderBy('id');
    }

    public function isOverdue(?CarbonInterface $now = null): bool
    {
        return $this->status->isOpen() && $this->due_at !== null && $this->due_at->lessThan($now ?? now());
    }
}
