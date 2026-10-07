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
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A client business — the tenant boundary for all business data.
 *
 * @property OrganizationStatus $status
 * @property string|null $status_reason why staff last paused or cancelled the service
 * @property Carbon|null $status_changed_at
 * @property array<string, string>|null $setup_progress setup wizard step => done | skipped
 * @property Carbon|null $setup_completed_at
 * @property int|null $average_job_value_cents for estimated revenue in Results
 * @property string|null $last_report_month "Y-m" of the last monthly report sent
 * @property int|null $retention_months months of history kept (null = all)
 * @property Carbon|null $closure_requested_at
 * @property Carbon|null $closes_at when a requested closure takes effect
 * @property Carbon|null $closed_at
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
            'retention_months' => 'integer',
            'closure_requested_at' => 'datetime',
            'closes_at' => 'datetime',
            'closed_at' => 'datetime',
            'status_changed_at' => 'datetime',
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

    /** The service is running: agents answer and the website tools show (onboarding counts, D45). */
    public function isServing(): bool
    {
        return in_array($this->status, [OrganizationStatus::Active, OrganizationStatus::Onboarding], true);
    }

    public function isClosing(): bool
    {
        return $this->closes_at !== null && $this->closed_at === null;
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
        // Currently assigned agents only (D40); the full history is agentAssignments().
        $relation = $this->belongsToMany(User::class, 'agent_assignments', 'organization_id', 'agent_user_id')
            ->withPivot(['id', 'status', 'assignment_type', 'starts_at', 'ends_at', 'is_primary', 'source'])
            ->withTimestamps();
        AgentAssignment::applyCurrent($relation);

        return $relation;
    }

    /**
     * @return HasMany<AgentAssignment, $this>
     */
    public function agentAssignments(): HasMany
    {
        return $this->hasMany(AgentAssignment::class)->latest('id');
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

    /**
     * System-level assignment (tenancy backfill, optional auto-assignment). People assign through
     * App\Actions\Assignments\AssignAgent, which checks permissions and records who did it.
     */
    public function assignAgent(User $agent, AgentAssignmentSource $source = AgentAssignmentSource::Manual, bool $primary = false): void
    {
        $open = AgentAssignment::query()->where('organization_id', $this->getKey())->where('agent_user_id', $agent->getKey())->open()->exists();

        if (! $open) {
            AgentAssignment::create([
                'organization_id' => $this->getKey(),
                'agent_user_id' => $agent->getKey(),
                'status' => AgentAssignment::ACTIVE,
                'starts_at' => now(),
                'source' => $source->value,
                'is_primary' => $primary,
                'status_changed_at' => now(),
            ]);
        }
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
