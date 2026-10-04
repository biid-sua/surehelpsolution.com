<?php

namespace App\Support\Tenancy;

use App\Models\Organization;

/**
 * The organization the current request (or job) acts for.
 *
 * Always resolved on the server from the authenticated user's memberships or
 * assignments, never from an id supplied by the browser (spec §4).
 * Registered as a scoped binding, so it resets between requests and jobs.
 */
class CurrentOrganization
{
    private ?Organization $organization = null;

    public function set(?Organization $organization): void
    {
        $this->organization = $organization;
    }

    public function get(): ?Organization
    {
        return $this->organization;
    }

    public function id(): ?int
    {
        return $this->organization?->getKey();
    }

    public function has(): bool
    {
        return $this->organization !== null;
    }

    public function clear(): void
    {
        $this->organization = null;
    }

    /**
     * Run a callback with a given organization as context, restoring the previous one afterwards.
     */
    public function runAs(?Organization $organization, callable $callback): mixed
    {
        $previous = $this->organization;
        $this->organization = $organization;

        try {
            return $callback();
        } finally {
            $this->organization = $previous;
        }
    }
}
