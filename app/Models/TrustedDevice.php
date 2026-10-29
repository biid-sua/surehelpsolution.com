<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A browser that skips the two-step code until it expires (D103). Only the hash of its secret is stored.
 *
 * @property int $id
 * @property int $user_id
 * @property string $token_hash
 * @property string $name
 * @property string|null $ip_address
 * @property Carbon|null $last_used_at
 * @property Carbon $expires_at
 * @property Carbon $created_at
 */
class TrustedDevice extends Model
{
    use MassPrunable;

    protected $fillable = ['user_id', 'token_hash', 'name', 'ip_address', 'last_used_at', 'expires_at'];

    protected function casts(): array
    {
        return ['last_used_at' => 'datetime', 'expires_at' => 'datetime'];
    }

    /**
     * Expired ones are removed by the daily `model:prune`.
     *
     * @return Builder<TrustedDevice>
     */
    public function prunable(): Builder
    {
        return static::where('expires_at', '<', now());
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
