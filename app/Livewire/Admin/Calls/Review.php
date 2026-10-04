<?php

namespace App\Livewire\Admin\Calls;

use App\Actions\Calls\AttributeCall;
use App\Enums\CallOwnershipSource;
use App\Livewire\Concerns\PlatformAdminOnly;
use App\Models\CallLog;
use App\Models\Organization;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Review queue for calls the tenancy backfill could not attribute with certainty (docs/decisions.md D2).
 */
#[Layout('layouts.portal', ['portal' => 'admin'])]
#[Title('Call review')]
class Review extends Component
{
    use PlatformAdminOnly;
    use WithPagination;

    /** @var array<int, string> selected organization id per call id */
    public array $assignTo = [];

    public function mount(): void
    {
        $this->authorize('calls.update');
    }

    public function confirm(int $callId, AttributeCall $attribute): void
    {
        $call = $this->queue()->whereKey($callId)->firstOrFail();

        if ($call->organization_id === null) {
            $this->addError("assignTo.$callId", 'Choose a business first.');

            return;
        }

        $attribute->handle(auth()->user(), $call, $call->organization);
        $this->dispatch('toast', type: 'success', message: "Call {$call->call_id} confirmed for {$call->organization->name}.");
    }

    public function assign(int $callId, AttributeCall $attribute): void
    {
        $call = $this->queue()->whereKey($callId)->firstOrFail();
        $organization = Organization::find($this->assignTo[$callId] ?? null);

        if (! $organization) {
            $this->addError("assignTo.$callId", 'Choose a business first.');

            return;
        }

        $attribute->handle(auth()->user(), $call, $organization);
        unset($this->assignTo[$callId]);
        $this->dispatch('toast', type: 'success', message: "Call {$call->call_id} assigned to {$organization->name}.");
    }

    /**
     * @return Builder<CallLog>
     */
    private function queue()
    {
        return CallLog::query()->whereIn('ownership_source', [
            CallOwnershipSource::EmailMatch->value,
            CallOwnershipSource::Unassigned->value,
        ]);
    }

    public function render(): View
    {
        return view('livewire.admin.calls.review', [
            'calls' => $this->queue()->with('organization:id,ulid,name')->latest('created_at')->paginate(15),
            'organizations' => Organization::orderBy('name')->get(['id', 'name']),
        ]);
    }
}
