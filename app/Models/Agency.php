<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A marketing agency that resells SureHelp (D118): its people look after its client businesses from
 * one dashboard, the businesses see the agency's brand, and the agency earns a share of what they pay.
 *
 * @property string $ulid
 * @property string $commission_percent
 */
class Agency extends Model
{
    protected $fillable = ['name', 'brand_color', 'logo_url', 'commission_percent'];

    protected static function booted(): void
    {
        static::creating(function (Agency $agency) {
            $agency->ulid ??= (string) Str::ulid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    /** @return BelongsToMany<User, $this> */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot('role')->withTimestamps();
    }

    /** @return HasMany<Organization, $this> */
    public function organizations(): HasMany
    {
        return $this->hasMany(Organization::class);
    }

    /** @return HasMany<AgencyStatement, $this> */
    public function statements(): HasMany
    {
        return $this->hasMany(AgencyStatement::class);
    }

    /** The agency a person belongs to (people belong to at most one). */
    public static function of(?User $user): ?self
    {
        return $user ? self::query()->whereHas('members', fn ($q) => $q->whereKey($user->id))->first() : null;
    }
}
