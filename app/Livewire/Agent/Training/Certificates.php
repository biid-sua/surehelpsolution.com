<?php

namespace App\Livewire\Agent\Training;

use App\Livewire\Concerns\AgentWorkspaceOnly;
use App\Models\TrainingCertificate;
use App\Services\Training\LearningProgress;
use App\Services\Training\TrainingAccess;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Certifications of the agents a person looks after (brief §1.11–1.12): active, expiring,
 * expired and revoked, with revoking for those allowed to manage certifications.
 */
#[Layout('layouts.portal', ['portal' => 'agent'])]
#[Title('Certifications')]
class Certificates extends Component
{
    use AgentWorkspaceOnly, WithPagination;

    #[Url(except: '')]
    public string $status = '';

    #[Url(except: '')]
    public string $search = '';

    #[Locked]
    public ?int $revoking = null;

    public string $reason = '';

    public function mount(): void
    {
        abort_unless(auth()->user()->hasPermissionIn('training.view_progress'), 403);
    }

    public function updating(string $name): void
    {
        if (in_array($name, ['status', 'search'], true)) {
            $this->resetPage();
        }
    }

    /** @return Builder<TrainingCertificate> */
    private function visible(TrainingAccess $access): Builder
    {
        $user = auth()->user();
        $query = TrainingCertificate::query()->whereIn('agent_user_id', $access->agents($user)->select('users.id'));
        if (! $user->hasPlatformPermission('training.view_progress')) {
            $companies = $access->companies($user, 'training.view_progress')->select('organizations.id');
            $query->whereHas('course', fn (Builder $q) => $q->whereNull('organization_id')->orWhereIn('organization_id', $companies));
        }

        return $query;
    }

    public function startRevoke(int $id, TrainingAccess $access): void
    {
        $this->revoking = $this->visible($access)->findOrFail($id)->id;
        $this->reason = '';
    }

    public function cancelRevoke(): void
    {
        $this->revoking = null;
    }

    public function revoke(TrainingAccess $access, LearningProgress $learning): void
    {
        $certificate = $this->visible($access)->findOrFail((int) $this->revoking);
        $learning->revokeCertificate($certificate, auth()->user(), $this->reason);
        $this->revoking = null;
        $this->dispatch('toast', type: 'success', message: 'Certificate '.$certificate->number.' revoked.');
    }

    public function render(TrainingAccess $access): View
    {
        $now = now();
        $term = trim($this->search);
        $certificates = $this->visible($access)
            ->when($this->status === 'active', fn (Builder $q) => $q->where('status', TrainingCertificate::ACTIVE)->where(fn (Builder $q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', $now->copy()->addDays(TrainingCertificate::EXPIRING_DAYS))))
            ->when($this->status === 'expiring_soon', fn (Builder $q) => $q->where('status', TrainingCertificate::ACTIVE)->whereBetween('expires_at', [$now, $now->copy()->addDays(TrainingCertificate::EXPIRING_DAYS)]))
            ->when($this->status === 'expired', fn (Builder $q) => $q->where('status', TrainingCertificate::ACTIVE)->where('expires_at', '<=', $now))
            ->when($this->status === 'revoked', fn (Builder $q) => $q->where('status', TrainingCertificate::REVOKED))
            ->when($term !== '', fn (Builder $q) => $q->where(fn (Builder $q) => $q->where('number', 'like', '%'.addcslashes($term, '%_\\').'%')
                ->orWhereHas('agent', fn (Builder $q) => $q->where('name', 'like', '%'.addcslashes($term, '%_\\').'%'))))
            ->with('agent:id,name', 'course:id,ulid,title,organization_id', 'course.organization:id,name')
            ->latest('issued_at')->paginate(30);

        $user = auth()->user();

        return view('livewire.agent.training.certificates', [
            'certificates' => $certificates,
            'canRevoke' => fn (TrainingCertificate $c) => $c->course->organization_id === null
                ? $user->hasPlatformPermission('training.manage_certifications')
                : $user->hasPermissionIn('training.manage_certifications', $c->course->organization),
        ]);
    }
}
