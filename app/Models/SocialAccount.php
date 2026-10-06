<?php

namespace App\Models;

use App\Enums\SocialNetwork;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\StoresUtc;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A Facebook Page, Instagram account, LinkedIn page/profile or Google Business Profile location
 * a business connected (spec §41). Tokens are encrypted and hidden: they never reach the browser or the API.
 *
 * @property SocialNetwork $network
 * @property string $access_token
 * @property string|null $refresh_token
 * @property Carbon|null $token_expires_at
 * @property array<string, mixed>|null $meta
 */
class SocialAccount extends Model
{
    use BelongsToOrganization, StoresUtc;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_NEEDS_REAUTH = 'needs_reauth';

    protected $fillable = [
        'organization_id', 'network', 'external_id', 'name', 'handle', 'avatar_url', 'access_token', 'refresh_token',
        'token_expires_at', 'meta', 'is_enabled', 'status', 'last_error', 'connected_by_user_id', 'messaging_enabled',
    ];

    protected $hidden = ['access_token', 'refresh_token'];

    protected function casts(): array
    {
        return [
            'network' => SocialNetwork::class,
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'token_expires_at' => 'datetime',
            'meta' => 'array',
            'is_enabled' => 'boolean',
            'messaging_enabled' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (SocialAccount $account) {
            $account->ulid ??= (string) Str::ulid();
        });
    }

    /**
     * Accounts posts can go to: chosen by the business and still authorised.
     *
     * @param  Builder<SocialAccount>  $query
     */
    public function scopeUsable(Builder $query): void
    {
        $query->where('is_enabled', true)->where('status', self::STATUS_ACTIVE);
    }

    public function needsReconnect(): bool
    {
        return $this->status === self::STATUS_NEEDS_REAUTH;
    }

    public function displayName(): string
    {
        return $this->name.($this->handle ? ' (@'.ltrim($this->handle, '@').')' : '');
    }
}
