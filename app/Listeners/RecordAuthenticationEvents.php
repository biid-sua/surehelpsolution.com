<?php

namespace App\Listeners;

use App\Models\User;
use App\Support\Audit\Audit;
use Illuminate\Auth\Events\Logout;

/**
 * Login history (spec §61, §71; docs/decisions.md D8). Auto-discovered. Sign-ins themselves are
 * recorded by SignIn (with the method used: password, two-step code, recovery code, invitation).
 */
class RecordAuthenticationEvents
{
    public function __construct(private readonly Audit $audit) {}

    public function handleLogout(Logout $event): void
    {
        if ($event->user instanceof User) {
            $this->audit->record('auth.logout', $event->user, new: ['channel' => 'web'], actor: $event->user);
        }
    }
}
