<?php

namespace App\Livewire\Admin\Escalations;

use App\Enums\EscalationPriority;
use App\Livewire\Concerns\PlatformAdminOnly;
use App\Models\Escalation;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Every unresolved escalation across businesses, longest-waiting urgent first, so operations
 * can phone an owner who hasn't responded (spec §25).
 */
#[Layout('layouts.portal', ['portal' => 'admin'])]
#[Title('Escalations')]
class Index extends Component
{
    use PlatformAdminOnly;
    use WithPagination;

    public function mount(): void
    {
        $this->authorize('escalations.view');
    }

    public function render(): View
    {
        $active = fn () => Escalation::withoutGlobalScopes()->active();

        return view('livewire.admin.escalations.index', [
            'escalations' => $active()
                ->with(['organization:id,ulid,name,timezone', 'customer:id,first_name,last_name,company', 'call:id,call_id', 'acknowledgedBy:id,name'])
                ->byUrgency()
                ->paginate(30),
            'urgentWaiting' => $active()->where('status', 'open')->where('priority', EscalationPriority::Urgent->value)->count(),
        ]);
    }
}
