<?php

namespace App\Livewire\Client\Calls;

use App\Livewire\Concerns\ScopedToOrganization;
use App\Models\CallLog;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

#[Layout('layouts.portal', ['portal' => 'client'])]
class Show extends Component
{
    use ScopedToOrganization;

    #[Locked]
    public string $callId;

    public function mount(string $callId): void
    {
        $this->callId = $callId;
        $this->authorize('view', $this->call());
    }

    /**
     * Looked up inside the client's organization: another tenant's ID is a 404, never a 403,
     * so the existence of other businesses' calls isn't revealed.
     */
    private function call(): CallLog
    {
        return CallLog::query()
            ->forOrganization($this->organization())
            ->where('call_id', $this->callId)
            ->firstOrFail();
    }

    public function render(): View
    {
        $call = $this->call();
        $organization = $this->organization();

        return view('livewire.client.calls.show', [
            'call' => $call,
            'timezone' => $organization->timezone ?: config('app.timezone'),
            'callTasks' => auth()->user()->can('tasks.view', $organization) ? $call->tasks()->latest('id')->get() : null,
            'callEscalations' => auth()->user()->can('escalations.view', $organization) ? $call->escalations()->latest('id')->get() : collect(),
        ])->title('Call '.$call->call_id);
    }
}
