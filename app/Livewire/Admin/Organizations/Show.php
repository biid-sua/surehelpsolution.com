<?php

namespace App\Livewire\Admin\Organizations;

use App\Enums\AgentAssignmentSource;
use App\Livewire\Concerns\PlatformAdminOnly;
use App\Models\Organization;
use App\Models\User;
use App\Support\Audit\Audit;
use Illuminate\Contracts\View\View;
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

    public function assignAgent(): void
    {
        $organization = $this->organization();
        $this->authorize('organization.update', $organization);

        $this->validate(['agentToAdd' => ['required', 'integer']]);
        $agent = User::where('role', 'agent')->where('is_active', true)->find($this->agentToAdd);

        if (! $agent) {
            $this->addError('agentToAdd', 'Choose an active agent.');

            return;
        }

        $organization->assignAgent($agent, AgentAssignmentSource::Manual);
        app(Audit::class)->record('agent.assigned', $organization, new: ['agent_id' => $agent->id, 'agent' => $agent->name]);
        $this->agentToAdd = '';
        $this->dispatch('toast', type: 'success', message: "{$agent->name} can now handle calls for {$organization->name}.");
    }

    public function unassignAgent(int $agentId): void
    {
        $organization = $this->organization();
        $this->authorize('organization.update', $organization);

        $agent = $organization->agents()->whereKey($agentId)->first();
        $organization->agents()->detach($agentId);
        if ($agent) {
            app(Audit::class)->record('agent.unassigned', $organization, old: ['agent_id' => $agent->id, 'agent' => $agent->name]);
        }
        $this->dispatch('toast', type: 'success', message: 'Agent removed. They can no longer log calls for this business.');
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
        ])->title($organization->name);
    }
}
