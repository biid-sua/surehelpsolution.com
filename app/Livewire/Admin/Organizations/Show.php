<?php

namespace App\Livewire\Admin\Organizations;

use App\Actions\Assignments\AssignAgent;
use App\Actions\Assignments\ChangeAssignment;
use App\Actions\Organizations\ChangeOrganizationStatus;
use App\Enums\OrganizationStatus;
use App\Livewire\Concerns\PlatformAdminOnly;
use App\Models\AgentAssignment;
use App\Models\Organization;
use App\Models\User;
use App\Services\Setup\SetupProgress;
use App\Support\Audit\Audit;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Organization detail: profile basics, members, and agent assignments (docs/decisions.md D3).
 */
#[Layout('layouts.portal', ['portal' => 'admin'])]
class Show extends Component
{
    use PlatformAdminOnly;

    /** US business timezones first; any IANA zone is accepted. */
    public const PRIMARY_TIMEZONES = [
        'America/New_York' => 'Eastern (New York)',
        'America/Chicago' => 'Central (Chicago)',
        'America/Denver' => 'Mountain (Denver)',
        'America/Phoenix' => 'Mountain, no DST (Phoenix)',
        'America/Los_Angeles' => 'Pacific (Los Angeles)',
        'America/Anchorage' => 'Alaska (Anchorage)',
        'Pacific/Honolulu' => 'Hawaii (Honolulu)',
    ];

    #[Locked]
    public int $organizationId;

    public string $name = '';

    public string $timezone = '';

    public string $agentToAdd = '';

    public string $statusReason = '';

    public function mount(Organization $organization): void
    {
        $this->authorize('organization.view', $organization);

        $this->organizationId = $organization->id;
        $this->name = $organization->name;
        $this->timezone = (string) $organization->timezone;
    }

    private function organization(): Organization
    {
        return Organization::findOrFail($this->organizationId);
    }

    public function save(): void
    {
        $organization = $this->organization();
        $this->authorize('organization.update', $organization);

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'timezone' => ['nullable', 'timezone:all'],
        ]);

        $organization->update([
            'name' => $validated['name'],
            'timezone' => $validated['timezone'] ?: null,
        ]);
        app(Audit::class)->changes('organization.updated', $organization, ['name', 'timezone']);

        $this->dispatch('toast', type: 'success', message: 'Business details saved.');
    }

    /** Go live, resume or reactivate (D45). */
    public function setActive(ChangeOrganizationStatus $change): void
    {
        $this->changeStatus(OrganizationStatus::Active, $change);
    }

    public function setPaused(ChangeOrganizationStatus $change): void
    {
        $this->changeStatus(OrganizationStatus::Paused, $change);
    }

    public function setCancelled(ChangeOrganizationStatus $change): void
    {
        $this->changeStatus(OrganizationStatus::Cancelled, $change);
    }

    private function changeStatus(OrganizationStatus $status, ChangeOrganizationStatus $change): void
    {
        $organization = $this->organization();
        $this->authorize('organization.update', $organization);
        $this->resetValidation();

        $change->handle($organization, $status, auth()->user(), $this->statusReason);
        $this->reset('statusReason');
        $this->dispatch('toast', type: 'success', message: "{$organization->name} is now {$status->label()}. The owner has been told.");
    }

    public function assignAgent(AssignAgent $assign): void
    {
        $organization = $this->organization();
        $this->authorize('agent_assignments.create', $organization);

        $this->validate(['agentToAdd' => ['required', 'integer']]);
        $agent = User::where('role', 'agent')->find($this->agentToAdd);
        if (! $agent) {
            $this->addError('agentToAdd', 'Choose an active agent.');

            return;
        }

        try {
            $assign->handle($organization, $agent, auth()->user());
        } catch (ValidationException $e) {
            $this->addError('agentToAdd', implode(' ', array_merge(...array_values($e->errors()))));

            return;
        }

        $this->agentToAdd = '';
        $this->dispatch('toast', type: 'success', message: "{$agent->name} can now handle calls for {$organization->name}.");
    }

    /** Ends the agent's current assignment (kept in the history, D40). */
    public function unassignAgent(int $agentId, ChangeAssignment $change): void
    {
        $organization = $this->organization();
        $this->authorize('agent_assignments.update', $organization);

        $assignment = AgentAssignment::query()->where('organization_id', $organization->id)->where('agent_user_id', $agentId)->open()->latest('id')->first();
        if ($assignment) {
            $change->end($assignment, auth()->user(), 'Removed from the admin console');
        }
        $this->dispatch('toast', type: 'success', message: 'Agent removed. They can no longer see this business or log calls for it.');
    }

    public function render(): View
    {
        $organization = $this->organization()->load(['owner', 'members']);
        $assigned = $organization->agents()->orderBy('name')->get();

        return view('livewire.admin.organizations.show', [
            'organization' => $organization,
            'agents' => $assigned,
            'availableAgents' => User::where('role', 'agent')->where('is_active', true)
                ->whereNotIn('id', $assigned->pluck('id'))
                ->orderBy('name')
                ->get(['id', 'name', 'email']),
            'callStats' => [
                'total' => $organization->callLogs()->count(),
                'last30' => $organization->callLogs()->where('created_at', '>=', now()->subDays(30))->count(),
            ],
            'primaryTimezones' => self::PRIMARY_TIMEZONES,
            'otherTimezones' => array_values(array_diff(\DateTimeZone::listIdentifiers(), array_keys(self::PRIMARY_TIMEZONES))),
            'canUpdate' => auth()->user()->can('organization.update', $organization),
            'canAssign' => auth()->user()->hasPermissionIn('agent_assignments.create', $organization),
            'setupCount' => app(SetupProgress::class)->count($organization),
            'canImpersonate' => auth()->user()->hasPermissionIn('users.impersonate'),
            'transitions' => ChangeOrganizationStatus::TRANSITIONS[$organization->status->value],
        ])->title($organization->name);
    }
}
