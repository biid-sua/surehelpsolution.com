<?php

namespace App\Livewire\Agent;

use App\Livewire\Concerns\AgentWorkspaceOnly;
use App\Models\AgentAssignment;
use App\Models\AgentDutySchedule;
use App\Models\AuditLog;
use App\Models\CallLog;
use App\Models\QaReview;
use App\Models\Task;
use App\Models\TrainingAssignment;
use App\Models\TrainingCertificate;
use App\Models\User;
use App\Services\Assignments\AssignmentScope;
use App\Services\Training\TrainingAccess;
use App\Services\Training\TrainingReadiness;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * An agent's profile for supervisors (brief §7): basics, availability, companies and history,
 * training, performance, tasks and recent activity. Only what concerns the companies the viewer manages;
 * nothing sensitive (no passwords, security settings or personal documents).
 */
#[Layout('layouts.portal', ['portal' => 'agent'])]
#[Title('Agent')]
class TeamMember extends Component
{
    use AgentWorkspaceOnly;

    #[Locked]
    public int $agentId;

    public function mount(User $agent): void
    {
        abort_unless(auth()->user()->hasPermissionIn('agent_assignments.view') && $agent->isAgent(), 404);
        $this->agentId = $agent->id;
    }

    public function render(AssignmentScope $scope, TrainingReadiness $readiness): View
    {
        abort_unless(auth()->user()->hasPermissionIn('agent_assignments.view'), 404);
        $agent = User::query()->where('role', 'agent')->findOrFail($this->agentId);
        $mine = $scope->ids(auth()->user());

        $history = AgentAssignment::query()->where('agent_user_id', $agent->id)->whereIn('organization_id', $mine)
            ->with(['organization:id,ulid,name,timezone', 'assignedBy:id,name', 'endedBy:id,name'])->latest('id')->get();
        $current = $history->filter(fn (AgentAssignment $a) => $a->isCurrent());

        return view('livewire.agent.team-member', [
            'agent' => $agent,
            'current' => $current,
            'history' => $history,
            'otherCompanies' => AgentAssignment::query()->where('agent_user_id', $agent->id)->current()->whereNotIn('organization_id', $mine)->count(),
            'shifts' => AgentDutySchedule::query()->where('agent_id', $agent->id)->where('is_active', true)->where('end_datetime', '>', now())
                ->where('start_datetime', '<', now()->addDays(7))->orderBy('start_datetime')->limit(10)->get(),
            'readiness' => $current->mapWithKeys(fn (AgentAssignment $a) => [$a->organization_id => $readiness->for($agent, $a->organization)]),
            'performance' => [
                'calls_30' => CallLog::withoutGlobalScopes()->where('user_id', $agent->id)->whereIn('organization_id', $mine)->where('created_at', '>=', now()->subDays(30))->count(),
                'qa_avg' => QaReview::query()->where('agent_user_id', $agent->id)->whereIn('organization_id', $mine)->completed()->where('reviewed_at', '>=', now()->subDays(90))->avg('score'),
                'qa_count' => QaReview::query()->where('agent_user_id', $agent->id)->whereIn('organization_id', $mine)->completed()->where('reviewed_at', '>=', now()->subDays(90))->count(),
            ],
            'tasks' => Task::withoutGlobalScopes()->whereIn('organization_id', $mine)->where('assigned_to_user_id', $agent->id)->open()->with('organization:id,name')->byUrgency()->limit(8)->get(),
            'activity' => AuditLog::query()->where('actor_id', $agent->id)->whereIn('organization_id', $mine)->latest('id')->limit(10)->get(),
            'canAssign' => auth()->user()->hasPermissionIn('agent_assignments.create'),
            'training' => $this->training($agent, $mine),
        ]);
    }

    /**
     * The agent's training, limited to platform courses and the viewer's companies' courses.
     *
     * @param  list<int>  $mine
     * @return array{rows: Collection<int, TrainingAssignment>, required: int, required_done: int, certificates: Collection<int, TrainingCertificate>}|null
     */
    private function training(User $agent, array $mine): ?array
    {
        $viewer = auth()->user();
        if (! app(TrainingAccess::class)->canSeeAgent($viewer, $agent)) {
            return null;
        }
        $platform = $viewer->hasPlatformPermission('training.view_progress');
        $courseScope = fn (Builder $q) => $q->when(! $platform, fn (Builder $q) => $q->where(fn (Builder $q) => $q->whereNull('organization_id')->orWhereIn('organization_id', $mine)));
        $rows = TrainingAssignment::query()->where('agent_user_id', $agent->id)->open()->whereHas('course', $courseScope)
            ->with('course:id,ulid,title,organization_id', 'course.organization:id,name')->orderByDesc('is_required')->orderBy('due_at')->get();
        $required = $rows->where('is_required', true);

        return [
            'rows' => $rows,
            'required' => $required->count(),
            'required_done' => $required->filter->isDone()->count(),
            'certificates' => TrainingCertificate::query()->where('agent_user_id', $agent->id)->whereHas('course', $courseScope)->latest('issued_at')->limit(10)->get(),
        ];
    }
}
