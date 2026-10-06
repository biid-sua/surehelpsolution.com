<?php

namespace App\Livewire\Agent\Training;

use App\Livewire\Concerns\AgentWorkspaceOnly;
use App\Models\AgentAssignment;
use App\Models\Organization;
use App\Models\TrainingAssignment;
use App\Services\Training\TrainingAccess;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Supervisor training dashboard (brief §1.13): required completion, overdue, expiring and at-risk
 * agents, then every agent × course row. Supervisors see the agents serving their companies, and
 * only platform courses or their own companies' courses.
 */
#[Layout('layouts.portal', ['portal' => 'agent'])]
#[Title('Training progress')]
class Progress extends Component
{
    use AgentWorkspaceOnly, WithPagination;

    public const STATUSES = [
        'open' => 'Still to do',
        'overdue' => 'Overdue',
        'assigned' => 'Not started',
        'in_progress' => 'In progress',
        'completed' => 'Completed',
        'expired' => 'Expired',
        'outdated' => 'Update required',
        'revoked' => 'Removed',
    ];

    #[Url(except: '')]
    public string $company = '';

    #[Url(except: '')]
    public string $status = '';

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: false)]
    public bool $requiredOnly = false;

    public function mount(): void
    {
        abort_unless(auth()->user()->hasPermissionIn('training.view_progress'), 403);
    }

    public function updating(string $name): void
    {
        $this->resetPage();
    }

    /** @return Builder<TrainingAssignment> rows the viewer may see, before filters */
    private function visible(TrainingAccess $access): Builder
    {
        $user = auth()->user();
        $query = TrainingAssignment::query()->whereIn('training_assignments.agent_user_id', $access->agents($user)->select('users.id'));
        if (! $user->hasPlatformPermission('training.view_progress')) {
            $companies = $access->companies($user, 'training.view_progress')->select('organizations.id');
            $query->whereHas('course', fn (Builder $q) => $q->whereNull('organization_id')->orWhereIn('organization_id', $companies));
        }

        return $query;
    }

    public function render(TrainingAccess $access): View
    {
        $user = auth()->user();
        $companies = $access->companies($user, 'training.view_progress')->orderBy('name')->get(['organizations.id', 'organizations.ulid', 'organizations.name']);
        $company = $this->company !== '' ? $companies->firstWhere('ulid', $this->company) : null;
        $inCompany = function (Builder $q) use ($company) {
            if ($company instanceof Organization) {
                $q->where(fn (Builder $q) => $q->where('training_assignments.organization_id', $company->id)
                    ->orWhereHas('course', fn (Builder $q) => $q->where('organization_id', $company->id))
                    // Platform courses of the agents serving this company.
                    ->orWhere(fn (Builder $q) => $q->whereHas('course', fn (Builder $q) => $q->whereNull('organization_id'))
                        ->whereIn('training_assignments.agent_user_id', AgentAssignment::query()->where('organization_id', $company->id)->current()->select('agent_user_id'))));
            }
        };

        $base = $this->visible($access)->where($inCompany)->where('training_assignments.status', '!=', TrainingAssignment::REVOKED);
        $required = (clone $base)->where('is_required', true);
        $requiredTotal = (clone $required)->count();
        $requiredDone = (clone $required)->whereEffectiveStatus('completed')->count();
        $overdue = (clone $base)->whereEffectiveStatus('overdue');

        $stats = [
            'agents' => (clone $base)->distinct()->count('training_assignments.agent_user_id'),
            'required' => $requiredTotal ? (int) floor(100 * $requiredDone / $requiredTotal) : null,
            'overdue' => (clone $overdue)->count(),
            'expiring' => (clone $base)->where('status', TrainingAssignment::COMPLETED)->whereNotNull('expires_at')
                ->whereBetween('expires_at', [now(), now()->addDays(30)])->count(),
            // At risk: required training overdue, or due within a week and not started.
            'at_risk' => (clone $required)->where(fn (Builder $q) => $q->where(fn (Builder $q) => $q->whereEffectiveStatus('overdue'))
                ->orWhere(fn (Builder $q) => $q->where('training_assignments.status', TrainingAssignment::ASSIGNED)
                    ->whereBetween('training_assignments.due_at', [now(), now()->addDays(7)])))
                ->distinct()->count('training_assignments.agent_user_id'),
        ];

        $term = trim($this->search);
        $rows = $this->visible($access)->where($inCompany)
            ->when($this->status !== '', fn (Builder $q) => $q->whereEffectiveStatus($this->status), fn (Builder $q) => $q->where('training_assignments.status', '!=', TrainingAssignment::REVOKED))
            ->when($this->requiredOnly, fn (Builder $q) => $q->where('is_required', true))
            ->when($term !== '', fn (Builder $q) => $q->where(fn (Builder $q) => $q->whereHas('agent', fn (Builder $q) => $q->where('name', 'like', '%'.addcslashes($term, '%_\\').'%'))
                ->orWhereHas('course', fn (Builder $q) => $q->where('title', 'like', '%'.addcslashes($term, '%_\\').'%'))))
            ->with('agent:id,name', 'course:id,ulid,title,organization_id', 'course.organization:id,name', 'organization:id,name')
            ->orderByRaw('CASE WHEN training_assignments.status != ? AND training_assignments.due_at < ? THEN 0 ELSE 1 END', [TrainingAssignment::COMPLETED, now()])
            ->orderByDesc('is_required')->orderBy('due_at')->paginate(30);

        return view('livewire.agent.training.progress', [
            'stats' => $stats,
            'rows' => $rows,
            'companies' => $companies,
            'statuses' => self::STATUSES,
        ]);
    }
}
