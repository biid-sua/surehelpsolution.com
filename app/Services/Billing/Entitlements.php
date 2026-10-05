<?php

namespace App\Services\Billing;

use App\Models\Organization;
use App\Models\OrganizationAddon;
use App\Models\Subscription;
use Illuminate\Validation\ValidationException;

/**
 * What a business's plan and add-ons include (spec §31). Checked on the server; hiding a button is
 * never enough. Request-scoped and memoised.
 *
 * Calls are never refused for being over the plan: a receptionist service answers every call, and
 * calls beyond what the plan includes are billed (Usage). Seats and connections are hard limits.
 */
class Entitlements
{
    /** @var array<int, ?Subscription> */
    private array $cache = [];

    /** @var array<int, list<string>> */
    private array $addonCache = [];

    public function subscription(Organization $organization): ?Subscription
    {
        if (! array_key_exists($organization->id, $this->cache)) {
            $this->cache[$organization->id] = Subscription::query()->forOrganization($organization)->current()->with('plan')->latest('id')->first();
        }

        return $this->cache[$organization->id];
    }

    /**
     * Slugs of the add-ons a business has turned on.
     *
     * @return list<string>
     */
    public function addons(Organization $organization): array
    {
        return $this->addonCache[$organization->id] ??= OrganizationAddon::withoutGlobalScopes()->forOrganization($organization)->active()
            ->join('addons', 'addons.id', '=', 'organization_addons.addon_id')->pluck('addons.slug')->all();
    }

    public function allows(Organization $organization, string $feature): bool
    {
        return in_array($feature, $this->subscription($organization)?->plan->features ?? [], true)
            || in_array($feature, $this->addons($organization), true);
    }

    /** A plan limit (e.g. team_members), or null for unlimited / not set. */
    public function limit(Organization $organization, string $key): ?int
    {
        $limits = $this->subscription($organization)?->plan->limits ?? [];

        return isset($limits[$key]) ? (int) $limits[$key] : null;
    }

    /**
     * Refuses adding one more of something the plan caps (team members, calendars).
     *
     * @throws ValidationException
     */
    public function ensureRoomFor(Organization $organization, string $key, int $current, string $field, string $what): void
    {
        $limit = $this->limit($organization, $key);
        if ($limit !== null && $current >= $limit) {
            throw ValidationException::withMessages([$field => "Your plan includes {$limit} {$what}. Remove one, or ask us about a bigger plan."]);
        }
    }

    public function forget(Organization $organization): void
    {
        unset($this->cache[$organization->id], $this->addonCache[$organization->id]);
    }
}
