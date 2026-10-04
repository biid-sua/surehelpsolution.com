<?php

namespace App\Livewire\Concerns;

/**
 * For agent-workspace Livewire components: re-checked on every request,
 * including /livewire/update calls that bypass route middleware.
 */
trait AgentWorkspaceOnly
{
    public function bootAgentWorkspaceOnly(): void
    {
        $user = auth()->user();
        abort_unless($user && ($user->isAgent() || $user->isAdmin()) && $user->is_active, 403);
    }
}
