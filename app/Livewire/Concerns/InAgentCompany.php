<?php

namespace App\Livewire\Concerns;

use App\Models\Organization;
use App\Services\Training\TrainingReadiness;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\RedirectResponse;
use Livewire\Attributes\Locked;

/**
 * Company context for agent pages (spec §20A, D40). The company is resolved and authorized on
 * every request, including Livewire updates: the agent must currently be assigned (or be platform
 * staff), otherwise 404, exactly as if the company didn't exist. The id can't be changed from the browser.
 *
 * Required training set to "blocking" leaves only the company's training open (D43).
 */
trait InAgentCompany
{
    use AgentWorkspaceOnly;

    #[Locked]
    public int $companyId;

    protected function enterCompany(Organization $organization, string $permission = 'organization.view'): void
    {
        abort_unless(auth()->user()->hasPermissionIn($permission, $organization), 404);
        if ($this->blockedByTraining($organization, $permission)) {
            session()->flash('status', app(TrainingReadiness::class)->message($organization, 'blocking'));
            // Built directly: the container's redirector may be Livewire's in long-running processes.
            throw new HttpResponseException(new RedirectResponse(route('agent.businesses.training', $organization->ulid)));
        }
        $this->companyId = $organization->id;
    }

    protected function company(string $permission = 'organization.view'): Organization
    {
        $organization = Organization::query()->find($this->companyId);
        abort_unless($organization && auth()->user()->hasPermissionIn($permission, $organization), 404);
        abort_if($this->blockedByTraining($organization, $permission), 403, app(TrainingReadiness::class)->message($organization, 'blocking'));

        return $organization;
    }

    private function blockedByTraining(Organization $organization, string $permission): bool
    {
        return $permission !== 'agent_university.view'
            && app(TrainingReadiness::class)->restriction(auth()->user(), $organization) === 'blocking';
    }
}
