<?php

namespace App\Livewire\Client;

use App\Models\Agency as AgencyModel;
use App\Services\Agencies\AgencyDesk;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * The agency dashboard (D118): every business the agency looks after, how this month is going for
 * each, and the agency's share. Agency owners can add a new client business.
 */
#[Layout('layouts.portal', ['portal' => 'client'])]
#[Title('Your agency')]
class Agency extends Component
{
    public string $name = '';

    public string $timezone = 'America/New_York';

    public function mount(): void
    {
        abort_unless($this->agency() !== null, 404);
    }

    public function addBusiness(AgencyDesk $desk): void
    {
        $agency = $this->agency() ?? abort(404);
        abort_unless($agency->members()->whereKey(auth()->id())->wherePivot('role', 'owner')->exists(), 403);
        $this->validate([
            'name' => ['required', 'string', 'max:120'],
            'timezone' => ['required', 'timezone:per_country,US'],
        ], [], ['name' => 'business name']);
        $organization = $desk->createBusiness($agency, trim($this->name), $this->timezone, auth()->user());
        $this->reset('name');
        $this->dispatch('toast', type: 'success', message: $organization->name.' was added. Open it to finish the setup and invite the owner.');
    }

    private function agency(): ?AgencyModel
    {
        return AgencyModel::of(auth()->user());
    }

    public function render(AgencyDesk $desk): View
    {
        $agency = $this->agency() ?? abort(404);
        $start = CarbonImmutable::now('America/New_York')->startOfMonth();

        return view('livewire.client.agency', [
            'agency' => $agency,
            'board' => $desk->dashboard($agency, $start, $start->endOfMonth()),
            'month' => $start,
            'isOwner' => $agency->members()->whereKey(auth()->id())->wherePivot('role', 'owner')->exists(),
            'zones' => ['America/New_York' => 'Eastern', 'America/Chicago' => 'Central', 'America/Denver' => 'Mountain', 'America/Phoenix' => 'Arizona',
                'America/Los_Angeles' => 'Pacific', 'America/Anchorage' => 'Alaska', 'Pacific/Honolulu' => 'Hawaii'],
        ]);
    }
}
