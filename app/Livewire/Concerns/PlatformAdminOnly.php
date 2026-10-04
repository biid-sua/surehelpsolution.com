<?php

namespace App\Livewire\Concerns;

/**
 * For admin-console Livewire components: re-checked on every request,
 * including /livewire/update calls that bypass route middleware.
 */
trait PlatformAdminOnly
{
    public function bootPlatformAdminOnly(): void
    {
        abort_unless(auth()->user()?->isAdmin(), 403);
    }
}
