<?php

namespace App\Livewire\Client;

use App\Livewire\Concerns\ScopedToOrganization;
use App\Models\CalendarConnection;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Appointments and service visits. Events load per visible range from
 * app.calendar.events. Google/Microsoft sync arrives in Phase 3.
 */
#[Layout('layouts.portal', ['portal' => 'client'])]
#[Title('Calendar')]
class Calendar extends Component
{
    use ScopedToOrganization;

    public function mount(): void
    {
        $this->authorize('calls.view', $this->organization());
    }

    public function render(): View
    {
        $organization = $this->organization();

        return view('livewire.client.calendar', [
            'organization' => $organization,
            'connections' => CalendarConnection::query()->forOrganization($organization)->get(['id', 'provider', 'status', 'account_email', 'last_synced_at']),
        ]);
    }
}
