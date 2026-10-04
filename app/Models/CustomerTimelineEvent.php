<?php

namespace App\Models;

use App\Enums\TimelineEventType;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One entry on a customer's timeline (spec §13). Append-only.
 *
 * @property TimelineEventType $type
 * @property Carbon $occurred_at
 * @property array<string, mixed>|null $meta
 */
class CustomerTimelineEvent extends Model
{
    use BelongsToOrganization;

    public const UPDATED_AT = null;

    protected $fillable = [
        'organization_id', 'customer_id', 'type', 'title', 'body', 'subject_type', 'subject_id', 'meta', 'actor_id', 'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => TimelineEventType::class,
            'meta' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
