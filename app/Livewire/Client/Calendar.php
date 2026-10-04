<?php

namespace App\Livewire\Client;

use App\Livewire\Concerns\ScopedToOrganization;
use App\Support\Calendar\CalendarSources;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * The business calendar: SureHelp appointments, service visits, and busy times from the connected
 * Google and Microsoft calendars, each tagged with its source and switchable on and off.
 * Events load per visible range from app.calendar.events.
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

    public function render(CalendarSources $sources): View
    {
        $organization = $this->organization();
        $all = $sources->for($organization);
        $external = collect($all)->where('kind', 'external');

        return view('livewire.client.calendar', [
            'organization' => $organization,
            'sources' => $all,
            'anyConnected' => $external->contains('connected', true),
            'needsReconnect' => $external->where('status', 'needs_reauth')->pluck('label'),
        ]);
    }
}
