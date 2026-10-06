<?php

namespace App\Livewire\Agent\Company;

use App\Livewire\Concerns\InAgentCompany;
use App\Models\Organization;
use App\Models\TrainingAssignment;
use App\Models\TrainingCourse;
use App\Models\TrainingRule;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Company training (spec §20A–20B, brief §1.6–1.7): what this company requires of the agents
 * serving it, where the agent stands on each, and the company's other courses.
 */
#[Layout('layouts.portal', ['portal' => 'agent'])]
class Training extends Component
{
    use InAgentCompany;

    public function mount(Organization $organization): void
    {
        $this->enterCompany($organization, 'agent_university.view');
    }

    public function render(): View
    {
        $company = $this->company('agent_university.view');
        $user = auth()->user();

        $rules = TrainingRule::query()->where('scope', TrainingRule::COMPANY)->where('organization_id', $company->id)->where('is_active', true)
            ->whereHas('course', fn (Builder $q) => $q->where('is_active', true)->where('current_version', '>', 0))
            ->with('course:id,ulid,title,summary,organization_id,estimated_minutes,difficulty,current_version')->get()
            ->unique('course_id')->values();
        $mine = TrainingAssignment::query()->where('agent_user_id', $user->id)->open()
            ->whereIn('course_id', $rules->pluck('course_id')->merge(TrainingCourse::query()->where('organization_id', $company->id)->pluck('id')))
            ->get()->keyBy('course_id');

        $required = $rules->where('is_required', true);

        return view('livewire.agent.company.training', [
            'company' => $company,
            'rules' => $rules,
            'mine' => $mine,
            'requiredCount' => $required->count(),
            'requiredDone' => $required->filter(fn (TrainingRule $r) => $mine->get($r->course_id)?->isDone())->count(),
            'other' => TrainingCourse::query()->where('organization_id', $company->id)->where('is_active', true)->where('current_version', '>', 0)
                ->whereNotIn('id', $rules->pluck('course_id'))->orderBy('title')->get(),
        ])->title('Training · '.$company->name);
    }
}
