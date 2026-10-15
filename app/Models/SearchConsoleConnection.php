<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A business's Google Search Console access (read-only), for real search statistics (D57).
 *
 * @property list<array{url: string, permission: string}>|null $sites
 * @property list<array{query: string, clicks: int, impressions: int, position: float}>|null $top_queries
 * @property Carbon|null $token_expires_at
 * @property Carbon|null $last_synced_at
 */
class SearchConsoleConnection extends Model
{
    use BelongsToOrganization;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_NEEDS_REAUTH = 'needs_reauth';

    public const STATUS_ERROR = 'error';

    protected $fillable = ['organization_id', 'connected_by_user_id', 'account_email', 'access_token', 'refresh_token', 'token_expires_at',
        'sites', 'site_url', 'top_queries', 'status', 'last_synced_at', 'last_error'];

    protected $hidden = ['access_token', 'refresh_token'];

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'token_expires_at' => 'datetime',
            'sites' => 'array',
            'top_queries' => 'array',
            'last_synced_at' => 'datetime',
        ];
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}
