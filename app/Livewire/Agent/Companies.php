<?php

namespace App\Livewire\Agent;

use App\Livewire\Concerns\AgentWorkspaceOnly;
use App\Models\AgentAssignment;
use App\Models\Appointment;
use App\Models\Organization;
use App\Models\Task;
use App\Services\Business\BusinessHours;
use App\Services\Training\TrainingReadiness;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * "My Companies" (spec §20A): only the companies the agent currently serves, with today's workload
 * and whether their required training is done. Platform admins see every active company.
 */
#[Layout('layouts.portal', ['portal' => 'agent'])]
#[Title('My companies')]
class Companies extends Component
{
    use AgentWorkspaceOnly;

    public function render(BusinessHours $hours, TrainingReadiness $readiness): View
    {
        $user = auth()->user();
        $organizations = $user->workableOrganizations()->orderBy('name')->get(['id', 'ulid', 'name', 'timezone', 'status']);
        $ids = $organizations->pluck('id');

        $openTasks = Task::withoutGlobalScopes()->whereIn('organization_id', $ids)->open()
            ->selectRaw('organization_id, COUNT(*) as aggregate')->groupBy('organization_id')->pluck('aggregate', 'organization_id');
        $assignments = AgentAssignment::query()->where('agent_user_id', $user->id)->current()->get()->keyBy('organization_id');

        return view('livewire.agent.companies', [
            'companies' => $organizations->map(function (Organization $o) use ($hours, $readiness, $user, $openTasks, $assignments) {
                // "Today" is each company's own day (timezones differ), so this is one small indexed count per company.
                $today = Appointment::withoutGlobalScopes()->where('organization_id', $o->id)->blocking()
                    ->whereBetween('starts_at', [now($o->timezoneOrDefault())->startOfDay()->utc(), now($o->timezoneOrDefault())->endOfDay()->utc()])->count();

                return [
                    'organization' => $o,
                    'assignment' => $assignments->get($o->id),
                    'status' => $hours->status($o),
                    'local_time' => now($o->timezoneOrDefault())->format('g:i A'),
                    'appointments_today' => $today,
                    'open_tasks' => (int) ($openTasks[$o->id] ?? 0),
                    'readiness' => $user->isAgent() ? $readiness->for($user, $o) : null,
                ];
            }),
            'isAdmin' => $user->isAdmin(),
        ]);
    }
}
