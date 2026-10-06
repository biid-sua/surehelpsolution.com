<?php

namespace App\Livewire\Agent\Company;

use App\Livewire\Concerns\InAgentCompany;
use App\Models\Customer;
use App\Models\Organization;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * One company's customers for its assigned agents (spec §20A): searched and paged in the database,
 * scoped to that company only.
 */
#[Layout('layouts.portal', ['portal' => 'agent'])]
#[Title('Customers')]
class Customers extends Component
{
    use InAgentCompany, WithPagination;

    #[Url(except: '')]
    public string $search = '';

    public function mount(Organization $organization): void
    {
        $this->enterCompany($organization, 'customers.view');
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function render(): View
    {
        $company = $this->company('customers.view');

        return view('livewire.agent.company.customers', [
            'company' => $company,
            'customers' => Customer::query()->forOrganization($company)->search($this->search)
                ->orderByRaw('last_activity_at IS NULL')->orderByDesc('last_activity_at')->orderByDesc('id')->paginate(25),
            'timezone' => $company->timezoneOrDefault(),
        ]);
    }
}
