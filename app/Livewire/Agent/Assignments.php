<?php

namespace App\Livewire\Agent;

use App\Actions\Assignments\AssignAgent;
use App\Actions\Assignments\AssignmentChecks;
use App\Actions\Assignments\ChangeAssignment;
use App\Livewire\Concerns\AgentWorkspaceOnly;
use App\Models\AgentAssignment;
use App\Models\Organization;
use App\Models\User;
use App\Services\Assignments\AssignmentScope;
use App\Support\Audit\Audit;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * "Agent Assignments" for supervisors and platform staff (spec §20A, D40–D41): who serves which
 * company, assigning with checks shown first, and ending, revoking, suspending, resuming or
 * reassigning with history kept. Supervisors only see and manage their own companies.
 */
#[Layout('layouts.portal', ['portal' => 'agent'])]
#[Title('Agent assignments')]
class Assignments extends Component
{
    use AgentWorkspaceOnly, WithPagination;

    #[Url(except: 'current')]
    public string $status = 'current';

    #[Url(except: '')]
    public string $company = '';

    #[Url(except: '')]
    public string $search = '';

    /** @var array<string, string> */
    public array $form = ['agent' => '', 'company' => '', 'starts_on' => '', 'starts_at' => '', 'ends_on' => '', 'type' => 'standard', 'notes' => ''];

    public bool $creating = false;

    public ?int $acting = null;

    public string $action = '';

    public string $reason = '';

    public string $reassignTo = '';

    public function mount(): void
    {
        abort_unless(auth()->user()->hasPermissionIn('agent_assignments.view'), 403);
        $agent = request()->query('agent');
        if (is_string($agent) && ctype_digit($agent)) {
            $this->form['agent'] = $agent;
            $this->creating = true;
        }
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['status', 'company', 'search'], true)) {
            $this->resetPage();
        }
    }

    public function startCreate(): void
    {
        $this->resetValidation();
        $this->creating = true;
    }

    public function assign(AssignAgent $assign, AssignmentScope $scope): void
    {
        $this->validate([
            'form.agent' => ['required', 'integer'],
            'form.company' => ['required', 'string'],
            'form.starts_on' => ['nullable', 'date_format:Y-m-d'],
            'form.starts_at' => ['nullable', 'date_format:H:i'],
            'form.ends_on' => ['nullable', 'date_format:Y-m-d'],
            'form.type' => ['required', Rule::in(array_keys(AgentAssignment::TYPES))],
            'form.notes' => ['nullable', 'string', 'max:2000'],
        ], [], ['form.agent' => 'agent', 'form.company' => 'company']);

        [$agent, $company] = $this->selection($scope, 'agent_assignments.create');
        $timezone = $company->timezoneOrDefault();
        $starts = $this->form['starts_on'] !== '' ? CarbonImmutable::parse($this->form['starts_on'].' '.($this->form['starts_at'] ?: '00:00'), $timezone) : null;
        $ends = $this->form['ends_on'] !== '' ? CarbonImmutable::parse($this->form['ends_on'], $timezone)->endOfDay() : null;

        try {
            $assign->handle($company, $agent, auth()->user(), ['starts_at' => $starts, 'ends_at' => $ends, 'type' => $this->form['type'], 'notes' => $this->form['notes']]);
        } catch (ValidationException $e) {
            foreach ($e->errors() as $key => $messages) {
                $this->addError(str_starts_with($key, 'form.') ? $key : 'form.'.($key === 'agent' ? 'agent' : $key), implode(' ', $messages));
            }

            return;
        } catch (AuthorizationException $e) {
            $this->addError('form.company', $e->getMessage());

            return;
        }

        $this->creating = false;
        $this->form = ['agent' => '', 'company' => '', 'starts_on' => '', 'starts_at' => '', 'ends_on' => '', 'type' => 'standard', 'notes' => ''];
        $this->dispatch('toast', type: 'success', message: $starts && $starts->isFuture() ? "{$agent->name} is scheduled to start at {$company->name}." : "{$agent->name} now serves {$company->name}. They've been notified.");
    }

    public function act(int $id, string $action): void
    {
        abort_unless(in_array($action, ['end', 'revoke', 'suspend', 'reassign'], true), 400);
        $this->assignment($id);
        $this->acting = $id;
        $this->action = $action;
        $this->reason = '';
        $this->reassignTo = '';
        $this->resetValidation();
    }

    public function confirmAction(ChangeAssignment $change, AssignAgent $assign): void
    {
        $assignment = $this->assignment((int) $this->acting);
        $user = auth()->user();

        try {
            match ($this->action) {
                'end' => $change->end($assignment, $user, $this->reason),
                'revoke' => $change->revoke($assignment, $user, $this->reason),
                'suspend' => $change->suspend($assignment, $user, $this->reason),
                'reassign' => $assign->reassign($assignment, User::query()->where('role', 'agent')->findOrFail((int) $this->reassignTo), $user, $this->reason),
                default => abort(400),
            };
        } catch (ValidationException $e) {
            $this->addError('reason', implode(' ', array_merge(...array_values($e->errors()))));

            return;
        } catch (AuthorizationException $e) {
            $this->addError('reason', $e->getMessage());

            return;
        }

        $this->acting = null;
        $this->dispatch('toast', type: 'success', message: 'Assignment updated. The agent has been notified.');
    }

    public function resume(int $id, ChangeAssignment $change): void
    {
        try {
            $change->resume($this->assignment($id), auth()->user());
        } catch (ValidationException|AuthorizationException $e) {
            $this->dispatch('toast', type: 'error', message: $e instanceof ValidationException ? implode(' ', array_merge(...array_values($e->errors()))) : $e->getMessage());

            return;
        }
        $this->dispatch('toast', type: 'success', message: 'Assignment resumed.');
    }

    /** Confirm an automatically created assignment: it becomes a deliberate one, by this person. */
    public function confirmReview(int $id): void
    {
        $assignment = $this->assignment($id);
        abort_unless(auth()->user()->hasPermissionIn('agent_assignments.update', $assignment->organization), 403);
        $assignment->forceFill(['assigned_by_user_id' => auth()->id(), 'source' => 'manual'])->save();
        app(Audit::class)->record('agent.assignment_confirmed', $assignment, organization: $assignment->organization, label: ($assignment->agent->name ?? 'Agent').' → '.($assignment->organization->name ?? 'company'));
    }

    /**
     * @return array{0: User, 1: Organization}
     */
    private function selection(AssignmentScope $scope, string $permission): array
    {
        $agent = User::query()->where('role', 'agent')->find((int) $this->form['agent']);
        $company = $scope->organizations(auth()->user(), $permission)->where('ulid', $this->form['company'])->first();
        if (! $agent) {
            throw ValidationException::withMessages(['form.agent' => ['Choose an agent.']]);
        }
        if (! $company) {
            throw ValidationException::withMessages(['form.company' => ['Choose one of the companies you manage.']]);
        }

        return [$agent, $company];
    }

    /** An assignment in a company this person may see; anything else is "not found". */
    private function assignment(int $id): AgentAssignment
    {
        $assignment = AgentAssignment::query()->with(['organization', 'agent'])->find($id);
        abort_unless($assignment && $assignment->organization && auth()->user()->hasPermissionIn('agent_assignments.view', $assignment->organization), 404);

        return $assignment;
    }

    public function render(AssignmentScope $scope): View
    {
        $viewer = auth()->user();
        $ids = $scope->ids($viewer);
        $companies = Organization::query()->whereIn('id', $ids)->orderBy('name')->get(['id', 'ulid', 'name', 'timezone']);
        $now = now();

        $assignments = AgentAssignment::query()->whereIn('organization_id', $ids)
            ->with(['agent:id,name,email,is_active', 'organization:id,ulid,name,timezone', 'assignedBy:id,name', 'endedBy:id,name'])
            ->when($this->company !== '', fn (Builder $q) => $q->whereHas('organization', fn (Builder $o) => $o->where('ulid', $this->company)))
            ->when(trim($this->search) !== '', fn (Builder $q) => $q->whereHas('agent', fn (Builder $a) => $a->where('name', 'like', '%'.addcslashes(trim($this->search), '%_\\').'%')))
            ->tap(fn (Builder $q) => match ($this->status) {
                'scheduled' => $q->where('status', AgentAssignment::SCHEDULED)->where('starts_at', '>', $now),
                'suspended' => $q->where('status', AgentAssignment::SUSPENDED),
                'ended' => $q->where(fn (Builder $e) => $e->whereIn('status', [AgentAssignment::ENDED, AgentAssignment::REVOKED])->orWhere('ends_at', '<=', $now)),
                'review' => $q->current()->whereIn('source', ['migration', 'automatic'])->whereNull('assigned_by_user_id'),
                'all' => $q,
                default => $q->current(),
            })
            ->latest('id')->paginate(25);

        $preview = null;
        $selectedAgent = $this->form['agent'] !== '' ? User::query()->where('role', 'agent')->find((int) $this->form['agent']) : null;
        $selectedCompany = $this->form['company'] !== '' ? $companies->firstWhere('ulid', $this->form['company']) : null;
        if ($this->creating && $selectedAgent && $selectedCompany) {
            $preview = AssignmentChecks::preview($selectedCompany, $selectedAgent);
        }

        return view('livewire.agent.assignments', [
            'assignments' => $assignments,
            'companies' => $companies,
            'agents' => User::query()->where('role', 'agent')->where('is_active', true)->orderBy('name')->get(['id', 'name', 'email']),
            'preview' => $preview,
            'selectedAgent' => $selectedAgent,
            'selectedCompany' => $selectedCompany,
            'canCreate' => $viewer->hasPermissionIn('agent_assignments.create'),
            'types' => AgentAssignment::TYPES,
            'reviewCount' => AgentAssignment::query()->whereIn('organization_id', $ids)->current()->whereIn('source', ['migration', 'automatic'])->whereNull('assigned_by_user_id')->count(),
        ]);
    }
}
