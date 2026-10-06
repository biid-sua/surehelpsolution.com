<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\StoresUtc;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * One message in a conversation: from the customer, a team member, the AI assistant, or the system.
 *
 * Statuses: received (from the customer), draft (an AI suggestion), pending, sent, failed, discarded.
 *
 * @property list<array{type: string, url: string, name?: ?string}>|null $attachments
 * @property Carbon|null $sent_at
 */
class Message extends Model
{
    use BelongsToOrganization, StoresUtc;

    public const IN = 'in';

    public const OUT = 'out';

    protected $fillable = [
        'organization_id', 'conversation_id', 'direction', 'author_type', 'author_user_id', 'body', 'attachments', 'is_note',
        'status', 'external_id', 'error', 'ai_run_id', 'sent_at',
    ];

    protected $attributes = ['is_note' => false];

    protected function casts(): array
    {
        return [
            'attachments' => 'array',
            'is_note' => 'boolean',
            'sent_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Message $message) {
            $message->ulid ??= (string) Str::ulid();
        });
    }

    /** @return BelongsTo<Conversation, $this> */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }

    /** @return BelongsTo<AiRun, $this> */
    public function aiRun(): BelongsTo
    {
        return $this->belongsTo(AiRun::class);
    }

    /** @return HasMany<AiFeedback, $this> */
    public function feedback(): HasMany
    {
        return $this->hasMany(AiFeedback::class);
    }

    public function isFromAi(): bool
    {
        return $this->author_type === 'ai';
    }

    /** Part of the conversation the customer sees (not a note, draft or discarded suggestion). */
    public function isVisibleToCustomer(): bool
    {
        return ! $this->is_note && in_array($this->status, ['received', 'sent', 'pending'], true);
    }
}
