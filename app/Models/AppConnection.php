<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A business's own software connected to SureHelp: QuickBooks Online (D110) and Jobber (D111). One per
 * app. Tokens are encrypted at rest and never leave the server.
 *
 * @property string $ulid
 * @property string $provider
 * @property array<string, bool>|null $settings
 * @property Carbon|null $token_expires_at
 * @property Carbon|null $pulled_through
 * @property Carbon|null $last_synced_at
 */
class AppConnection extends Model
{
    use BelongsToOrganization;

    public const QUICKBOOKS = 'quickbooks';

    public const JOBBER = 'jobber';

    public const ACTIVE = 'active';

    public const NEEDS_REAUTH = 'needs_reauth';

    /** What each app sends and receives, and what it starts with. */
    public const DEFAULTS = [
        self::QUICKBOOKS => ['send_payments' => true, 'send_new_customers' => false, 'receive_customers' => true],
        self::JOBBER => ['send_bookings' => true, 'send_new_customers' => false, 'receive_customers' => true],
    ];

    protected $fillable = [
        'organization_id', 'provider', 'connected_by_user_id', 'account_id', 'account_name', 'access_token', 'refresh_token',
        'token_expires_at', 'settings', 'status', 'pulled_through', 'last_synced_at', 'last_error',
    ];

    protected $hidden = ['access_token', 'refresh_token'];

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'token_expires_at' => 'datetime',
            'settings' => 'array',
            'pulled_through' => 'datetime',
            'last_synced_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (AppConnection $connection) {
            $connection->ulid ??= (string) Str::ulid();
        });
    }

    public function isActive(): bool
    {
        return $this->status === self::ACTIVE;
    }

    public function wants(string $setting): bool
    {
        $settings = ($this->settings ?? []) + (self::DEFAULTS[$this->provider] ?? []);

        return (bool) ($settings[$setting] ?? false);
    }

    /** @return HasMany<AppLink, $this> */
    public function links(): HasMany
    {
        return $this->hasMany(AppLink::class);
    }

    /** @return HasMany<AppSyncEntry, $this> */
    public function entries(): HasMany
    {
        return $this->hasMany(AppSyncEntry::class);
    }

    public function log(string $action, string $label, ?string $error = null): void
    {
        $this->entries()->create([
            'organization_id' => $this->organization_id, 'action' => $action, 'label' => Str::limit($label, 185),
            'error' => $error === null ? null : Str::limit($error, 495), 'created_at' => now(),
        ]);
    }
}
