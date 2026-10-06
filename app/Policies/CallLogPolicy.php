<?php

namespace App\Policies;

use App\Models\CallLog;
use App\Models\User;

class CallLogPolicy
{
    /**
     * View a single call: needs calls.view in the call's organization.
     * Unattributed calls are visible to platform staff only.
     */
    public function view(User $user, CallLog $callLog): bool
    {
        // An agent's own call stays visible only while they still serve that company (D40).
        if ($callLog->user_id === $user->id && $callLog->organization_id === null) {
            return true;
        }

        return $this->allowedIn($user, 'calls.view', $callLog);
    }

    /**
     * Agents edit the calls they logged (while still assigned); platform staff edit any.
     */
    public function update(User $user, CallLog $callLog): bool
    {
        if ($user->isAdmin()) {
            return $user->hasPermissionIn('calls.update', $callLog->organization);
        }

        return $callLog->user_id === $user->id
            && $this->allowedIn($user, 'calls.update', $callLog);
    }

    private function allowedIn(User $user, string $permission, CallLog $callLog): bool
    {
        if ($callLog->organization_id === null) {
            // No tenant to check against: only platform-scoped roles qualify.
            return $user->isAdmin() && $user->hasPermissionIn($permission);
        }

        return $user->hasPermissionIn($permission, $callLog->organization);
    }
}
