<?php

namespace App\Livewire\Client\Calls;

use App\Livewire\Concerns\ScopedToOrganization;
use App\Queries\CallLogFilters;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Searchable, filterable, server-paginated call history (spec §14, §47, §90).
 * Filters live in the URL so they survive navigation and can be shared.
 */
#[Layout('layouts.portal', ['portal' => 'client'])]
#[Title('Calls')]
class Index extends Component
{
    use ScopedToOrganization;
    use WithPagination;

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: 'all')]
    public string $view = 'all';

    #[Url(except: '')]
    public string $from = '';

    #[Url(except: '')]
    public string $to = '';

    public function mount(): void
    {
        $this->authorize('calls.view', $this->organization());
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'view', 'from', 'to'], true)) {
            $this->resetPage();
        }
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'view', 'from', 'to');
        $this->resetPage();
    }

    public function filters(): CallLogFilters
    {
        return CallLogFilters::fromArray([
            'search' => $this->search,
            'view' => $this->view,
            'from' => $this->from,
            'to' => $this->to,
        ]);
    }

    public function render(): View
    {
        $organization = $this->organization();
        $filters = $this->filters();

        return view('livewire.client.calls.index', [
            'organization' => $organization,
            'calls' => $filters->apply($organization)->paginate(20),
            'views' => CallLogFilters::VIEWS,
            'filtered' => $this->search !== '' || $this->view !== 'all' || $this->from !== '' || $this->to !== '',
            'exportUrl' => route('app.calls.export', array_filter([
                'search' => $filters->search,
                'view' => $filters->view === 'all' ? null : $filters->view,
                'from' => $filters->from,
                'to' => $filters->to,
            ])),
        ]);
    }
}
