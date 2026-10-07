<?php

namespace App\Livewire\Admin\Calls;

use App\Livewire\Admin\Concerns\FiltersPlatformRecords;
use App\Livewire\Concerns\PlatformAdminOnly;
use App\Models\CallLog;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Every call on the platform (spec §5 Super Admin "Calls"), newest first, with business, date and text filters.
 */
#[Layout('layouts.portal', ['portal' => 'admin'])]
#[Title('Calls')]
class Index extends Component
{
    use FiltersPlatformRecords;
    use PlatformAdminOnly;
    use WithPagination;

    public const STATUSES = ['new', 'service-requested', 'information-provided', 'completed', 'cancelled', 'spam'];

    #[Url(except: '')]
    public string $status = '';

    /** Call being looked at (id). */
    public ?int $open = null;

    public function mount(): void
    {
        $this->authorize('calls.view');
    }

    public function render(): View
    {
        $calls = $this->applyCommonFilters(CallLog::withoutGlobalScopes(), 'call_logs.created_at')
            ->with(['organization:id,ulid,name', 'customer:id,ulid,first_name,last_name,company'])
            ->when(in_array($this->status, self::STATUSES, true), fn (Builder $q) => $q->where('status', $this->status))
            ->when(trim($this->search) !== '', fn (Builder $q) => $q->where(fn (Builder $s) => $s
                ->where('caller_name', 'like', $this->likeTerm())->orWhere('caller_phone', 'like', $this->likeTerm())
                ->orWhere('call_id', 'like', $this->likeTerm())->orWhere('agent_name', 'like', $this->likeTerm())))
            ->latest('created_at')->latest('id')
            ->paginate(30);

        return view('livewire.admin.records.calls', [
            'calls' => $calls,
            'businesses' => $this->businessOptions(),
            'statuses' => self::STATUSES,
        ]);
    }
}
