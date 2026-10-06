<?php

namespace App\Livewire\Agent\Company;

use App\Actions\Tasks\ChangeTaskStatus;
use App\Enums\TaskStatus;
use App\Livewire\Concerns\InAgentCompany;
use App\Models\Organization;
use App\Models\Task;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * One company's open tasks and call-backs for its agents (spec §20A).
 */
#[Layout('layouts.portal', ['portal' => 'agent'])]
#[Title('Tasks')]
class Tasks extends Component
{
    use InAgentCompany, WithPagination;

    public function mount(Organization $organization): void
    {
        $this->enterCompany($organization, 'tasks.view');
    }

    public function setStatus(string $ulid, string $status, ChangeTaskStatus $change): void
    {
        $company = $this->company('tasks.update');
        $task = Task::query()->forOrganization($company)->where('ulid', $ulid)->firstOrFail(); // never another company's task
        $target = TaskStatus::tryFrom($status) ?? abort(422);
        $change->handle($task, $target, auth()->user());
        $this->dispatch('toast', type: 'success', message: 'Task updated.');
    }

    public function render(): View
    {
        $company = $this->company('tasks.view');

        return view('livewire.agent.company.tasks', [
            'company' => $company,
            'tasks' => Task::query()->forOrganization($company)->open()->with('customer:id,ulid,first_name,last_name,company')->byUrgency()->paginate(25),
            'canUpdate' => auth()->user()->hasPermissionIn('tasks.update', $company),
            'timezone' => $company->timezoneOrDefault(),
        ]);
    }
}
