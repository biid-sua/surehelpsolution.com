<?php

namespace App\Services\Billing;

use App\Models\Organization;
use App\Models\Subscription;

/**
 * What a business's plan includes (spec §31). Checked on the server; hiding a button is never enough.
 * Request-scoped and memoised.
 */
class Entitlements
{
    /** @var array<int, ?Subscription> */
    private array $cache = [];

    public function subscription(Organization $organization): ?Subscription
    {
        if (! array_key_exists($organization->id, $this->cache)) {
            $this->cache[$organization->id] = Subscription::query()->forOrganization($organization)->current()->with('plan')->latest('id')->first();
        }

        return $this->cache[$organization->id];
    }

    public function allows(Organization $organization, string $feature): bool
    {
        return in_array($feature, $this->subscription($organization)?->plan->features ?? [], true);
    }

    /** A plan limit (e.g. call_minutes), or null for unlimited / not set. */
    public function limit(Organization $organization, string $key): ?int
    {
        $limits = $this->subscription($organization)?->plan->limits ?? [];

        return isset($limits[$key]) ? (int) $limits[$key] : null;
    }
}
