<?php

namespace App\Livewire\Concerns;

use App\Services\Social\House\HouseSocial;
use App\Support\Tenancy\CurrentOrganization;

/**
 * The business social screens, reused in the admin console for SureHelp's own accounts (D104): the
 * organization is SureHelp's internal one, set on every request (Livewire updates skip route
 * middleware), and only platform staff who may manage social media get in.
 */
trait HouseSocialAdmin
{
    private bool $inHouse = false;

    /** Replaces the business portal's check on the business screens this extends. */
    public function bootScopedToOrganization(): void
    {
        $this->enterHouse();
    }

    /** Livewire runs boot<Trait>() for this trait itself, so screens of its own are checked too. */
    public function bootHouseSocialAdmin(): void
    {
        $this->enterHouse();
    }

    private function enterHouse(): void
    {
        if ($this->inHouse) {
            return;
        }
        $user = auth()->user();
        abort_unless($user?->role === 'admin' && $user->hasPermissionIn('social.view'), 403);

        app(CurrentOrganization::class)->set(app(HouseSocial::class)->organization());
        $this->inHouse = true;
    }

    public function socialPortal(): string
    {
        return 'admin';
    }
}
