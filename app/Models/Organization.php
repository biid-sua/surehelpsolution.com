<?php

namespace App\Models;

use App\Enums\AgentAssignmentSource;
use App\Enums\OrganizationStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * A client business — the tenant boundary for all business data.
 */
class Organization extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'slug',
        'status',
        'timezone',
        'currency',
        'owner_user_id',
    ];

    protected function casts(): array
    {
        return [
            'status' => OrganizationStatus::class,
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Organization $organization) {
            $organization->ulid ??= (string) Str::ulid();
            $organization->slug ??= static::uniqueSlug($organization->name);
            $organization->status ??= OrganizationStatus::Onboarding;
            $organization->currency ??= 'USD';
        });
    }

    /**
     * Public URLs and API payloads use the ULID, never the numeric id.
     */
    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->withPivot(['role', 'status', 'invited_at', 'joined_at'])
            ->withTimestamps();
    }

    public function agents(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'agent_assignments', 'organization_id', 'agent_user_id')
            ->withPivot(['is_primary', 'source'])
            ->withTimestamps();
    }

    public function callLogs(): HasMany
    {
        return $this->hasMany(CallLog::class);
    }

    public function hasAgent(User $agent): bool
    {
        return $this->agents()->whereKey($agent->getKey())->exists();
    }

    public function assignAgent(User $agent, AgentAssignmentSource $source = AgentAssignmentSource::Manual, bool $primary = false): void
    {
        $this->agents()->syncWithoutDetaching([
            $agent->getKey() => ['source' => $source->value, 'is_primary' => $primary],
        ]);
    }

    public static function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'business';
        $slug = $base;
        $suffix = 2;

        while (static::withTrashed()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }
}
