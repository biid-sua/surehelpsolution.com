<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A visitor's chat with SureHelp's sales assistant on the website (D78). Not tied to any business:
 * the visitor is a prospect. The visitor's browser holds a token; only its hash is stored.
 *
 * @property array{name?: string, email?: string, phone?: string, company?: string, industry?: string, team_size?: string, monthly_calls?: string, current_solution?: string, needs?: string, timeline?: string, plan?: string}|null $lead
 * @property array<string, string>|null $utm
 * @property Carbon|null $last_message_at
 */
class SalesChat extends Model
{
    use HasUlids;

    protected $guarded = [];

    protected $casts = [
        'lead' => 'array',
        'utm' => 'array',
        'needs_human' => 'boolean',
        'wants_demo' => 'boolean',
        'visitor_seen_at' => 'datetime',
        'last_message_at' => 'datetime',
    ];

    public function uniqueIds(): array
    {
        return ['ulid'];
    }

    public static function forToken(?string $token): ?self
    {
        return is_string($token) && preg_match('/^[A-Za-z0-9]{48}$/', $token)
            ? self::query()->where('token_hash', hash('sha256', $token))->first()
            : null;
    }

    /** @return HasMany<SalesChatMessage, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(SalesChatMessage::class);
    }

    /** @return BelongsTo<ContactSubmission, $this> */
    public function enquiry(): BelongsTo
    {
        return $this->belongsTo(ContactSubmission::class, 'contact_submission_id');
    }

    /** @return BelongsTo<User, $this> */
    public function staff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'staff_user_id');
    }

    /** @param array<string, string> $fields */
    public function remember(array $fields): void
    {
        $this->lead = array_filter(array_merge($this->lead ?? [], array_filter($fields, fn ($v) => is_string($v) && trim($v) !== '')));
    }

    public function hasContact(): bool
    {
        return filled($this->lead['name'] ?? null) && filled($this->lead['email'] ?? null);
    }
}
