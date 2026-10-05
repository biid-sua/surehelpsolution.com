<?php

namespace App\Livewire\Admin;

use App\Livewire\Concerns\PlatformAdminOnly;
use App\Models\AuditLog;
use App\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Audit trail viewer (spec §62). Platform staff with `audit_logs.view` only.
 */
#[Layout('layouts.portal', ['portal' => 'admin'])]
#[Title('Audit log')]
class AuditLogs extends Component
{
    use PlatformAdminOnly;
    use WithPagination;

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $action = '';

    #[Url(except: '')]
    public string $organization = '';

    #[Url(except: '')]
    public string $from = '';

    #[Url(except: '')]
    public string $to = '';

    public function mount(): void
    {
        $this->authorize('audit_logs.view');
    }

    public function updated(): void
    {
        $this->resetPage();
    }

    public function render(): View
    {
        $this->authorize('audit_logs.view');

        $logs = AuditLog::query()
            ->with(['actor:id,name,email', 'impersonator:id,name', 'organization:id,ulid,name'])
            ->when($this->action !== '', fn (Builder $q) => $q->where('action', $this->action))
            ->when($this->organization !== '', fn (Builder $q) => $q->where('organization_id', (int) $this->organization))
            ->when($this->search !== '', function (Builder $q) {
                $term = '%'.addcslashes($this->search, '%_\\').'%';
                $q->where(fn (Builder $inner) => $inner
                    ->where('subject_label', 'like', $term)
                    ->orWhereHas('actor', fn (Builder $actor) => $actor->where('email', 'like', $term)->orWhere('name', 'like', $term)));
            })
            ->when($this->validDate($this->from), fn (Builder $q) => $q->where('created_at', '>=', CarbonImmutable::parse($this->from)->startOfDay()))
            ->when($this->validDate($this->to), fn (Builder $q) => $q->where('created_at', '<=', CarbonImmutable::parse($this->to)->endOfDay()))
            ->latest('created_at')->latest('id')
            ->paginate(25);

        return view('livewire.admin.audit-logs', [
            'logs' => $logs,
            'actions' => AuditLog::query()->distinct()->orderBy('action')->pluck('action'),
            'organizations' => Organization::orderBy('name')->get(['id', 'name']),
        ]);
    }

    private function validDate(string $value): bool
    {
        return (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $value);
    }
}
