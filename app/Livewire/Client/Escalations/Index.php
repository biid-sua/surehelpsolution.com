<?php

namespace App\Livewire\Client\Escalations;

use App\Actions\Escalations\AcknowledgeEscalation;
use App\Actions\Escalations\AssignEscalation;
use App\Actions\Escalations\ResolveEscalation;
use App\Enums\EscalationStatus;
use App\Livewire\Concerns\ScopedToOrganization;
use App\Models\Escalation;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Things our team escalated to the business: acknowledge, hand to someone, resolve with a note (spec §25).
 */
#[Layout('layouts.portal', ['portal' => 'client'])]
#[Title('Escalations')]
class Index extends Component
{
    use ScopedToOrganization;
    use WithPagination;

    public const VIEWS = ['active' => 'Needs attention', 'resolved' => 'Resolved'];

    #[Url(except: 'active')]
    public string $view = 'active';

    /** Opened from a notification: ?escalation=<ulid>. */
    #[Url(as: 'escalation', except: '')]
    public string $focus = '';

    public ?string $resolving = null;

    public string $resolutionNotes = '';

    public function mount(): void
    {
        $this->authorize('escalations.view', $this->organization());
        $this->view = array_key_exists($this->view, self::VIEWS) ? $this->view : 'active';

        // A link to a resolved escalation opens the Resolved tab.
        if ($this->focus !== '' && ($escalation = $this->find($this->focus, false)) && ! $escalation->status->isActive()) {
            $this->view = 'resolved';
        }
    }

    public function updated(string $property): void
    {
        if ($property === 'view') {
            $this->resetPage();
        }
    }

    public function acknowledge(string $ulid, AcknowledgeEscalation $acknowledge): void
    {
        $this->authorize('escalations.resolve', $this->organization());
        $acknowledge->handle($this->find($ulid), auth()->user());
    }

    public function assign(string $ulid, string $userId, AssignEscalation $assign): void
    {
        $this->authorize('escalations.resolve', $this->organization());

        try {
            $assign->handle($this->find($ulid), $userId !== '' ? (int) $userId : null, auth()->user());
            $this->dispatch('toast', type: 'success', message: $userId !== '' ? 'Escalation handed over.' : 'Escalation unassigned.');
        } catch (ValidationException $e) {
            $this->dispatch('toast', type: 'error', message: $e->getMessage());
        }
    }

    public function startResolve(string $ulid): void
    {
        $this->authorize('escalations.resolve', $this->organization());
        $this->find($ulid);
        $this->resetValidation();
        $this->resolutionNotes = '';
        $this->resolving = $ulid;
    }

    public function resolve(ResolveEscalation $resolve): void
    {
        $this->authorize('escalations.resolve', $this->organization());
        $this->validate(['resolutionNotes' => ['required', 'string', 'max:5000']], ['resolutionNotes.required' => 'Say briefly what was done.']);

        $resolve->handle($this->find((string) $this->resolving), auth()->user(), $this->resolutionNotes);
        $this->resolving = null;
        $this->dispatch('toast', type: 'success', message: 'Escalation resolved.');
    }

    private function find(string $ulid, bool $fail = true): ?Escalation
    {
        $query = Escalation::query()->forOrganization($this->organization())->where('ulid', $ulid);

        return $fail ? $query->firstOrFail() : $query->first();
    }

    /**
     * @return Collection<int, User>
     */
    private function team(): Collection
    {
        $organization = $this->organization();

        return $organization->members()->wherePivot('status', 'active')->where('users.is_active', true)
            ->orderBy('users.name')->get(['users.id', 'users.name', 'users.role'])
            ->filter(fn (User $u) => $u->hasPermissionIn('escalations.view', $organization))
            ->values();
    }

    public function render(): View
    {
        $organization = $this->organization();
        $base = fn () => Escalation::query()->forOrganization($organization);
        $canResolve = auth()->user()->can('escalations.resolve', $organization);

        $escalations = $base()
            ->with(['customer:id,ulid,first_name,last_name,company', 'call:id,call_id', 'assignee:id,name', 'acknowledgedBy:id,name', 'resolvedBy:id,name'])
            ->when($this->view === 'resolved',
                fn ($q) => $q->where('status', EscalationStatus::Resolved->value)->latest('resolved_at')->latest('id'),
                fn ($q) => $q->active()->byUrgency())
            ->paginate(20);

        return view('livewire.client.escalations.index', [
            'escalations' => $escalations,
            'views' => self::VIEWS,
            'activeCount' => $base()->active()->count(),
            'team' => $canResolve ? $this->team() : collect(),
            'canResolve' => $canResolve,
            'resolvingEscalation' => $this->resolving ? $this->find($this->resolving, false) : null,
            'timezone' => $organization->timezoneOrDefault(),
        ]);
    }
}
