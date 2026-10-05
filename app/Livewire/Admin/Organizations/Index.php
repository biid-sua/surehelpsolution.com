<?php

namespace App\Livewire\Admin\Organizations;

use App\Enums\OrganizationStatus;
use App\Livewire\Concerns\PlatformAdminOnly;
use App\Models\Organization;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.portal', ['portal' => 'admin'])]
#[Title('Organizations')]
class Index extends Component
{
    use PlatformAdminOnly;
    use WithPagination;

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $status = '';

    public function mount(): void
    {
        $this->authorize('organization.view');
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'status'], true)) {
            $this->resetPage();
        }
    }

    public function render(): View
    {
        $organizations = Organization::query()
            ->with('owner:id,name,email')
            ->withCount([
                'agents',
                'callLogs as calls_30d' => fn ($q) => $q->where('created_at', '>=', now()->subDays(30)),
            ])
            ->when($this->search !== '', function (Builder $query) {
                $term = '%'.addcslashes($this->search, '%_\\').'%';
                $query->where(fn (Builder $q) => $q->where('name', 'like', $term)
                    ->orWhereHas('owner', fn (Builder $owner) => $owner->where('email', 'like', $term)->orWhere('name', 'like', $term)));
            })
            ->when(OrganizationStatus::tryFrom($this->status), fn (Builder $q, OrganizationStatus $status) => $q->where('status', $status))
            ->when($this->status === 'setup', fn (Builder $q) => $q->whereNull('setup_completed_at'))
            ->orderBy('name')
            ->paginate(20);

        return view('livewire.admin.organizations.index', [
            'organizations' => $organizations,
            'statuses' => OrganizationStatus::cases(),
        ]);
    }
}
