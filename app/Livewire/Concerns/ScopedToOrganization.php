<?php

namespace App\Livewire\Concerns;

use App\Models\Organization;
use App\Support\Tenancy\CurrentOrganization;

/**
 * For client-portal Livewire components.
 *
 * Livewire's follow-up requests (/livewire/update) don't run route middleware,
 * so the tenant context is re-established here on every request from the
 * authenticated user's own membership — never from component state the browser
 * could tamper with.
 */
trait ScopedToOrganization
{
    public function bootScopedToOrganization(): void
    {
        $user = auth()->user();
        $organization = $user?->isClient() ? $user->primaryOrganization() : null;

        abort_if($organization === null, 403, 'Your account is not linked to a business yet. Please contact support.');

        app(CurrentOrganization::class)->set($organization);
    }

    protected function organization(): Organization
    {
        /** @var Organization */
        return app(CurrentOrganization::class)->get();
    }
}
