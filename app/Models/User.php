<?php

namespace App\Models;

use App\Enums\NotificationEvent;
use App\Enums\OrganizationStatus;
use App\Notifications\Account\ResetPassword;
use App\Notifications\Account\VerifyEmail;
use App\Support\Audit\Audit;
use App\Support\Authorization\RoleCatalog;
use App\Support\Tenancy\CurrentOrganization;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Traits\HasRoles;

/**
 * @property list<string>|null $two_factor_recovery_codes hashed one-time codes
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, Notifiable;

    /**
     * Per-instance memo of organization roles, keyed by organization id.
     *
     * @var array<int, string|null>
     */
    private array $organizationRoleCache = [];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'phone',
        'is_active',
        'unique_id',
        'must_change_password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'must_change_password' => 'boolean',
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
            'last_login_at' => 'datetime',
            'session_epoch' => 'integer',
        ];
    }

    /** Two-step sign-in is set up and confirmed with a first code. */
    public function hasTwoFactor(): bool
    {
        return $this->two_factor_confirmed_at !== null && $this->two_factor_secret !== null;
    }

    /** Staff and agents must use two-step sign-in (D8). */
    public function requiresTwoFactor(): bool
    {
        return in_array($this->role, config('account.two_factor.required_for', []), true);
    }

    /** @return HasMany<LegalAcceptance, $this> */
    public function legalAcceptances(): HasMany
    {
        return $this->hasMany(LegalAcceptance::class);
    }

    /**
     * Branded reset email, linking to our reset page.
     *
     * @param  string  $token
     */
    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new ResetPassword($token));
    }

    public function sendEmailVerificationNotification(): void
    {
        $this->notify(new VerifyEmail);
    }

    /**
     * Check if user is admin
     */
    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    /**
     * Check if user is agent
     */
    public function isAgent(): bool
    {
        return $this->role === 'agent';
    }

    /**
     * Check if user is client
     */
    public function isClient(): bool
    {
        return $this->role === 'client';
    }

    /**
     * Organizations this user belongs to as a member (business owner, manager, staff).
     *
     * @return BelongsToMany<Organization, $this>
     */
    public function organizations(): BelongsToMany
    {
        return $this->belongsToMany(Organization::class)
            ->withPivot(['role', 'status', 'invited_at', 'joined_at'])
            ->withTimestamps();
    }

    /**
     * Organizations this agent is assigned to serve.
     *
     * @return BelongsToMany<Organization, $this>
     */
    public function assignedOrganizations(): BelongsToMany
    {
        return $this->belongsToMany(Organization::class, 'agent_assignments', 'agent_user_id', 'organization_id')
            ->withPivot(['is_primary', 'source'])
            ->withTimestamps();
    }

    /**
     * The organization a client user works in. Clients have exactly one for now (docs/decisions.md D1).
     */
    public function primaryOrganization(): ?Organization
    {
        return $this->organizations()
            ->wherePivot('status', 'active')
            ->orderBy('organizations.id')
            ->first();
    }

    /**
     * The user's role inside an organization (owner, manager, staff), or null if not an active member.
     */
    public function organizationRole(Organization $organization): ?string
    {
        $key = $organization->getKey();

        if (! array_key_exists($key, $this->organizationRoleCache)) {
            $this->organizationRoleCache[$key] = $this->organizations()
                ->wherePivot('status', 'active')
                ->whereKey($key)
                ->first()?->getRelationValue('pivot')?->getAttribute('role');
        }

        return $this->organizationRoleCache[$key];
    }

    /**
     * The single permission check (docs/permissions.md).
     *
     * - Global roles with scope "platform" grant their permissions everywhere.
     * - Global roles with scope "assigned" (agents) grant them only in assigned organizations.
     * - Organization roles grant their permissions inside that organization only.
     *
     * Without an organization, answers whether the user holds the permission at all;
     * data access must still be checked against the specific organization.
     */
    public function hasPermissionIn(string $permission, ?Organization $organization = null): bool
    {
        $organization ??= app(CurrentOrganization::class)->get();
        $catalog = app(RoleCatalog::class);
        $this->loadMissing('roles.permissions');

        /** @var Role $role */
        foreach ($this->roles as $role) {
            if (! $role->getRelationValue('permissions')->contains('name', $permission)) {
                continue;
            }

            if ($catalog->roleScope($role->name) === 'platform' || $organization === null) {
                return true;
            }

            if ($this->assignedOrganizations()->whereKey($organization->getKey())->exists()) {
                return true;
            }
        }

        if ($organization === null) {
            return $this->organizations()->wherePivot('status', 'active')->get()
                ->contains(fn (Organization $org) => in_array($permission, $catalog->organizationRolePermissions($org->getRelationValue('pivot')?->getAttribute('role')), true));
        }

        return in_array($permission, $catalog->organizationRolePermissions($this->organizationRole($organization)), true);
    }

    /**
     * Businesses this user handles calls for in the agent workspace: every active business for
     * platform admins, the assigned ones for agents (docs/decisions.md D3).
     *
     * @return Builder<Organization>
     */
    public function workableOrganizations(): Builder
    {
        $query = Organization::query()->whereIn('status', [OrganizationStatus::Active->value, OrganizationStatus::Onboarding->value]);

        if ($this->isAdmin()) {
            return $query;
        }

        if (! $this->isAgent()) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn('organizations.id', $this->assignedOrganizations()->select('organizations.id'));
    }

    /**
     * Client accounts the given user may log calls for: all for admins,
     * only clients of assigned organizations for agents, none otherwise.
     */
    public function scopeClientsVisibleTo(Builder $query, User $viewer): Builder
    {
        $query->where('role', 'client');

        if ($viewer->isAdmin()) {
            return $query;
        }

        if (! $viewer->isAgent()) {
            return $query->whereRaw('1 = 0');
        }

        $organizationIds = $viewer->assignedOrganizations()->pluck('organizations.id');

        return $query->whereHas('organizations', fn (Builder $q) => $q->whereIn('organizations.id', $organizationIds));
    }

    /**
     * @return HasMany<NotificationPreference, $this>
     */
    public function notificationPreferences(): HasMany
    {
        return $this->hasMany(NotificationPreference::class);
    }

    /**
     * Channels this user wants for an event: their saved choice, else the event's
     * defaults, always limited to channels that are switched on (config/notifications.php).
     *
     * @return list<string>
     */
    public function notificationChannelsFor(NotificationEvent $event): array
    {
        $saved = $this->notificationPreferences->firstWhere('event', $event->value);
        $channels = $saved ? (array) $saved->channels : $event->defaultChannels();

        return array_values(array_filter(
            $channels,
            fn (string $channel) => (bool) config("notifications.channels.{$channel}.enabled"),
        ));
    }

    /**
     * Where this user lands after signing in.
     */
    public function homeUrl(): string
    {
        return match ($this->role) {
            'admin' => route('admin.home'),
            'agent' => route('agent.home'),
            // A business owner who hasn't finished setup starts in the setup wizard (spec ONB).
            'client' => ($organization = $this->primaryOrganization()) && ! $organization->isSetUp() && $this->organizationRole($organization) === 'owner'
                ? route('app.setup')
                : route('app.dashboard'),
        };
    }

    public function requiresPasswordChange(): bool
    {
        return $this->must_change_password && in_array($this->role, ['agent', 'client'], true);
    }

    /**
     * Generate a unique ID for the user based on their role
     */
    public static function generateUniqueId($role): string
    {
        $prefix = match ($role) {
            'admin' => 'ADM',
            'agent' => 'AGT',
            'client' => 'CLT',
            default => 'USR'
        };

        // Generate a 6-digit number
        $number = str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT);

        $uniqueId = $prefix.$number;

        // Check if this ID already exists
        while (static::where('unique_id', $uniqueId)->exists()) {
            $number = str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT);
            $uniqueId = $prefix.$number;
        }

        return $uniqueId;
    }

    /**
     * Boot method to automatically generate unique_id when creating a user
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($user) {
            if (empty($user->unique_id)) {
                $user->unique_id = static::generateUniqueId($user->role ?? 'client');
            }
        });

        // Keep the global role in step with the portal type on every code path,
        // so e.g. demoting an admin can never leave Super Admin behind.
        static::created(function (User $user) {
            $user->syncDefaultRole();
            app(Audit::class)->record('user.created', $user, new: [
                'role' => $user->role,
                'email' => $user->email,
                'is_active' => $user->is_active,
            ]);
        });

        static::updated(function (User $user) {
            if ($user->wasChanged('role')) {
                $user->syncDefaultRole(replace: true);
            }

            // Every path that changes a user (web, API, self-service) is audited here.
            app(Audit::class)->changes('user.updated', $user, ['name', 'email', 'phone', 'role', 'is_active']);

            if ($user->wasChanged('password')) {
                app(Audit::class)->record('user.password_changed', $user, new: [
                    'by' => auth()->id() === $user->id ? 'self' : 'administrator',
                    'must_change_password' => (bool) $user->must_change_password,
                ]);
            }
        });
    }

    /**
     * Give the default global role for the portal type (admin → super_admin, agent → agent; clients none).
     */
    public function syncDefaultRole(bool $replace = false): void
    {
        $default = config("authorization.portal_defaults.{$this->role}");

        if ($replace) {
            $this->syncRoles($default ? [$default] : []);
        } elseif ($default && ! $this->roles()->exists()) {
            $this->assignRole($default);
        }
    }
}
