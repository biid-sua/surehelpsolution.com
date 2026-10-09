<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * An agent's own Google or Microsoft calendar (D54). Tokens are encrypted and never reach the
 * browser or the API. Only busy times are read from it, never titles or attendees.
 *
 * @property string $access_token
 * @property string|null $refresh_token
 * @property Carbon|null $token_expires_at
 * @property Carbon|null $last_synced_at
 * @property list<array{id: string, name: string, primary: bool, can_write: bool}>|null $calendars
 * @property list<string>|null $busy_calendar_ids
 */
class AgentCalendarConnection extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_NEEDS_REAUTH = 'needs_reauth';

    protected $fillable = [
        'user_id', 'provider', 'account_email', 'access_token', 'refresh_token', 'token_expires_at', 'scopes', 'calendars',
        'busy_calendar_ids', 'write_calendar_id', 'push_shifts', 'status', 'last_synced_at', 'last_error',
    ];

    protected $hidden = ['access_token', 'refresh_token'];

    protected $attributes = ['status' => self::STATUS_ACTIVE, 'push_shifts' => true];

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'token_expires_at' => 'datetime',
            'last_synced_at' => 'datetime',
            'calendars' => 'array',
            'busy_calendar_ids' => 'array',
            'push_shifts' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (AgentCalendarConnection $connection) {
            $connection->ulid ??= (string) Str::ulid();
        });
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<AgentCalendarEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(AgentCalendarEvent::class, 'connection_id');
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function calendarName(string $id): ?string
    {
        return collect($this->calendars ?? [])->firstWhere('id', $id)['name'] ?? null;
    }
}
