<?php

namespace App\Livewire\Client;

use App\Livewire\Concerns\ScopedToOrganization;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Service visits booked by agents. Events load per visible range from
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
        return view('livewire.client.calendar', ['organization' => $this->organization()]);
    }
}
