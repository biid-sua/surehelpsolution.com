<?php

namespace App\Models;

use App\Enums\InboxChannel;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\StoresUtc;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * One thread with one customer on one channel (spec §26).
 *
 * @property InboxChannel $channel
 * @property Carbon|null $last_message_at
 * @property Carbon|null $last_inbound_at
 * @property Carbon|null $notified_at
 * @property Carbon|null $closed_at
 */
class Conversation extends Model
{
    use BelongsToOrganization, StoresUtc;

    protected $fillable = [
        'organization_id', 'channel', 'channel_key', 'external_thread_id', 'social_account_id', 'customer_id', 'contact_name',
        'contact_handle', 'status', 'needs_human', 'ai_paused', 'assigned_to_user_id', 'unread_count', 'last_message_at',
        'last_inbound_at', 'notified_at', 'closed_at',
    ];

    protected $attributes = ['status' => 'open', 'needs_human' => false, 'ai_paused' => false, 'unread_count' => 0];

    protected function casts(): array
    {
        return [
            'channel' => InboxChannel::class,
            'needs_human' => 'boolean',
            'ai_paused' => 'boolean',
            'unread_count' => 'integer',
            'last_message_at' => 'datetime',
            'last_inbound_at' => 'datetime',
            'notified_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Conversation $conversation) {
            $conversation->ulid ??= (string) Str::ulid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    /** @return HasMany<Message, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class)->orderBy('id');
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    /** @return BelongsTo<SocialAccount, $this> */
    public function socialAccount(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class)->withoutGlobalScopes();
    }

    /** @return BelongsTo<User, $this> */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }

    public function displayName(): string
    {
        return $this->customer?->fullName() ?: ($this->contact_name ?: ($this->contact_handle ?: $this->channel->label().' visitor'));
    }

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }
}
