<?php

namespace App\Livewire\Concerns;

use App\Models\Organization;
use Livewire\Attributes\Locked;

/**
 * Company context for agent pages (spec §20A, D40). The company is resolved and authorized on
 * every request, including Livewire updates: the agent must currently be assigned (or be platform
 * staff), otherwise 404, exactly as if the company didn't exist. The id can't be changed from the browser.
 */
trait InAgentCompany
{
    use AgentWorkspaceOnly;

    #[Locked]
    public int $companyId;

    protected function enterCompany(Organization $organization, string $permission = 'organization.view'): void
    {
        abort_unless(auth()->user()->hasPermissionIn($permission, $organization), 404);
        $this->companyId = $organization->id;
    }

    protected function company(string $permission = 'organization.view'): Organization
    {
        $organization = Organization::query()->find($this->companyId);
        abort_unless($organization && auth()->user()->hasPermissionIn($permission, $organization), 404);

        return $organization;
    }
}
