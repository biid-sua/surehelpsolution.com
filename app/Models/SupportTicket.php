<?php

namespace App\Models;

use App\Enums\SupportTicketStatus;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A business's request to SureHelp (spec §78, D49).
 *
 * @property SupportTicketStatus $status
 * @property Carbon|null $last_reply_at
 * @property Carbon|null $resolved_at
 * @property Carbon|null $closed_at
 */
class SupportTicket extends Model
{
    use BelongsToOrganization;

    public const CATEGORIES = [
        'question' => 'Question',
        'script_change' => 'Script or instructions change',
        'calls' => 'Calls and agents',
        'appointments' => 'Appointments and calendar',
        'technical' => 'Something isn\'t working',
        'billing' => 'Billing',
        'other' => 'Something else',
    ];

    public const PRIORITIES = ['low' => 'Low', 'normal' => 'Normal', 'high' => 'High', 'urgent' => 'Urgent'];

    protected $fillable = ['organization_id', 'opened_by_user_id', 'assigned_to_user_id', 'subject', 'category', 'priority', 'status', 'last_reply_at', 'last_reply_by_staff'];

    protected $attributes = ['status' => 'open', 'priority' => 'normal'];

    protected function casts(): array
    {
        return [
            'status' => SupportTicketStatus::class,
            'last_reply_at' => 'datetime',
            'last_reply_by_staff' => 'boolean',
            'resolved_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn (SupportTicket $t) => $t->ulid ??= (string) Str::ulid());
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    /** "SR-" and the id: what people quote on the phone. */
    public function reference(): string
    {
        return 'SR-'.str_pad((string) $this->id, 5, '0', STR_PAD_LEFT);
    }

    public function categoryLabel(): string
    {
        return self::CATEGORIES[$this->category] ?? Str::headline($this->category);
    }

    /** @param  Builder<SupportTicket>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->whereIn('status', SupportTicketStatus::activeValues());
    }

    /** @return BelongsTo<User, $this> */
    public function openedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }

    /** @return HasMany<SupportTicketMessage, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(SupportTicketMessage::class)->orderBy('id');
    }
}
