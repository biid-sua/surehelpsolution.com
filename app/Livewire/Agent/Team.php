<?php

namespace App\Livewire\Agent;

use App\Livewire\Concerns\AgentWorkspaceOnly;
use App\Models\AgentAssignment;
use App\Models\AgentDutySchedule;
use App\Models\CallLog;
use App\Models\User;
use App\Services\Assignments\AssignmentScope;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Agent Management (spec §20A, brief §6): every agent with their workload, availability and the
 * companies they serve. Supervisors see company names only for companies they manage themselves.
 */
#[Layout('layouts.portal', ['portal' => 'agent'])]
#[Title('Team')]
class Team extends Component
{
    use AgentWorkspaceOnly, WithPagination;

    #[Url(except: '')]
    public string $search = '';

    public function mount(): void
    {
        abort_unless(auth()->user()->hasPermissionIn('agent_assignments.view'), 403);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function render(AssignmentScope $scope): View
    {
        $mine = $scope->ids(auth()->user());
        $agents = User::query()->where('role', 'agent')
            ->when(trim($this->search) !== '', fn ($q) => $q->where('name', 'like', '%'.addcslashes(trim($this->search), '%_\\').'%'))
            ->orderByDesc('is_active')->orderBy('name')->paginate(30, ['id', 'name', 'email', 'is_active']);
        $ids = $agents->getCollection()->pluck('id');

        $current = AgentAssignment::query()->whereIn('agent_user_id', $ids)->current()->with('organization:id,name')->get()->groupBy('agent_user_id');
        $onShift = AgentDutySchedule::query()->whereIn('agent_id', $ids)->where('is_active', true)->where('start_datetime', '<=', now())->where('end_datetime', '>', now())->pluck('agent_id')->flip();
        $calls = CallLog::withoutGlobalScopes()->whereIn('user_id', $ids)->where('created_at', '>=', now()->subDays(30))
            ->selectRaw('user_id, COUNT(*) as aggregate')->groupBy('user_id')->pluck('aggregate', 'user_id');

        return view('livewire.agent.team', [
            'agents' => $agents,
            'rows' => $agents->getCollection()->mapWithKeys(fn (User $a) => [$a->id => [
                'total' => count($current->get($a->id, [])),
                'visible' => collect($current->get($a->id, []))->filter(fn ($as) => in_array($as->organization_id, $mine, true))->pluck('organization.name')->all(),
                'on_shift' => $onShift->has($a->id),
                'calls' => (int) ($calls[$a->id] ?? 0),
            ]]),
            'canAssign' => auth()->user()->hasPermissionIn('agent_assignments.create'),
        ]);
    }
}
