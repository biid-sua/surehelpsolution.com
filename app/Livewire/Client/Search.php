<?php

namespace App\Livewire\Client;

use App\Livewire\Concerns\ScopedToOrganization;
use App\Services\Search\BusinessSearch;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * One box to find anything in the business (the client portal's search): customers, calls (by name,
 * number or call ID), appointments and tasks. Only this business's records, and only groups the person may see.
 */
#[Layout('layouts.portal', ['portal' => 'client'])]
#[Title('Search')]
class Search extends Component
{
    use ScopedToOrganization;

    private const LIMIT = 8;

    #[Url(except: '')]
    public string $q = '';

    public function mount(): void
    {
        $this->authorize('dashboard.view', $this->organization());
    }

    public function render(BusinessSearch $search): View
    {
        $organization = $this->organization();
        $term = trim($this->q);
        $results = $search->run($organization, auth()->user(), $term, self::LIMIT);

        return view('livewire.client.search', [
            'term' => $term,
            'results' => $results,
            'total' => collect($results)->sum(fn ($group) => $group->count()),
            'timezone' => $organization->timezoneOrDefault(),
        ]);
    }
}
