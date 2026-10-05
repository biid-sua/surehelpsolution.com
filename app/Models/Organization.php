<?php

namespace App\Models;

use App\Enums\AgentAssignmentSource;
use App\Enums\OrganizationStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * A client business — the tenant boundary for all business data.
 *
 * @property array<string, string>|null $setup_progress setup wizard step => done | skipped
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
        'average_job_value_cents',
    ];

    protected function casts(): array
    {
        return [
            'status' => OrganizationStatus::class,
            'setup_progress' => 'array',
            'setup_completed_at' => 'datetime',
            'average_job_value_cents' => 'integer',
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

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->withPivot(['role', 'status', 'invited_at', 'joined_at'])
            ->withTimestamps();
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function agents(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'agent_assignments', 'organization_id', 'agent_user_id')
            ->withPivot(['is_primary', 'source'])
            ->withTimestamps();
    }

    /**
     * @return HasMany<CallLog, $this>
     */
    public function callLogs(): HasMany
    {
        return $this->hasMany(CallLog::class);
    }

    /**
     * @return HasOne<BusinessProfile, $this>
     */
    public function profile(): HasOne
    {
        return $this->hasOne(BusinessProfile::class);
    }

    /**
     * @return HasMany<BusinessLocation, $this>
     */
    public function locations(): HasMany
    {
        return $this->hasMany(BusinessLocation::class);
    }

    /**
     * @return HasOne<BusinessLocation, $this>
     */
    public function primaryLocation(): HasOne
    {
        return $this->hasOne(BusinessLocation::class)->ofMany(['is_primary' => 'max', 'id' => 'min']);
    }

    /**
     * @return HasMany<BusinessHour, $this>
     */
    public function hours(): HasMany
    {
        return $this->hasMany(BusinessHour::class);
    }

    /**
     * @return HasMany<BusinessHoliday, $this>
     */
    public function holidays(): HasMany
    {
        return $this->hasMany(BusinessHoliday::class);
    }

    /**
     * @return HasMany<BusinessService, $this>
     */
    public function services(): HasMany
    {
        return $this->hasMany(BusinessService::class);
    }

    /**
     * @return HasMany<CalendarConnection, $this>
     */
    public function calendarConnections(): HasMany
    {
        return $this->hasMany(CalendarConnection::class);
    }

    /**
     * @return HasMany<Subscription, $this>
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /** The setup wizard was finished (or wasn't needed). */
    public function isSetUp(): bool
    {
        return $this->setup_completed_at !== null;
    }

    /**
     * The organization's IANA timezone, falling back to the app timezone until it is set.
     */
    public function timezoneOrDefault(): string
    {
        return $this->timezone ?: (string) config('app.timezone');
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
