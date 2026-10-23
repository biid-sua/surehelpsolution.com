<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A business's API key (D87). Only a hash is kept; the key itself is shown once, when it's made.
 *
 * @property Carbon|null $last_used_at
 * @property Carbon|null $revoked_at
 */
class ApiKey extends Model
{
    use BelongsToOrganization;

    public const PREFIX = 'shk_';

    protected $fillable = ['organization_id', 'user_id', 'name', 'prefix', 'hash', 'can_write', 'last_used_at', 'revoked_at'];

    protected $hidden = ['hash'];

    protected function casts(): array
    {
        return ['can_write' => 'boolean', 'last_used_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(fn (ApiKey $key) => $key->ulid ??= (string) Str::ulid());
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * A new key: the model (saved) and the secret to show once.
     *
     * @return array{0: self, 1: string}
     */
    public static function issue(Organization $organization, User $user, string $name, bool $canWrite): array
    {
        $secret = self::PREFIX.Str::random(40);
        $key = self::create(['organization_id' => $organization->id, 'user_id' => $user->id, 'name' => $name,
            'prefix' => substr($secret, 0, 10), 'hash' => hash('sha256', $secret), 'can_write' => $canWrite]);

        return [$key, $secret];
    }

    public static function findActive(string $secret): ?self
    {
        return self::withoutGlobalScopes()->where('hash', hash('sha256', $secret))->whereNull('revoked_at')->first();
    }
}
