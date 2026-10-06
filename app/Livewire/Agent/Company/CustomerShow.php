<?php

namespace App\Livewire\Agent\Company;

use App\Livewire\Concerns\InAgentCompany;
use App\Models\Appointment;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\Task;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * One customer of one company (spec §20A nested resources): looked up inside the company, so a
 * customer id from another company is simply "not found".
 */
#[Layout('layouts.portal', ['portal' => 'agent'])]
#[Title('Customer')]
class CustomerShow extends Component
{
    use InAgentCompany;

    #[Locked]
    public string $customerUlid;

    public function mount(Organization $organization, string $customer): void
    {
        $this->enterCompany($organization, 'customers.view');
        $this->customerUlid = $customer;
        $this->customer($organization); // 404 now if it isn't this company's
    }

    private function customer(Organization $company): Customer
    {
        return Customer::query()->forOrganization($company)->where('ulid', $this->customerUlid)->firstOrFail();
    }

    public function render(): View
    {
        $company = $this->company('customers.view');
        $customer = $this->customer($company);

        return view('livewire.agent.company.customer-show', [
            'company' => $company,
            'customer' => $customer,
            'timeline' => $customer->timeline()->limit(30)->get(),
            'appointments' => Appointment::query()->forOrganization($company)->where('customer_id', $customer->id)
                ->where('starts_at', '>=', now()->subDay())->orderBy('starts_at')->limit(5)->get(),
            'tasks' => Task::query()->forOrganization($company)->where('customer_id', $customer->id)->open()->byUrgency()->limit(5)->get(),
            'timezone' => $company->timezoneOrDefault(),
        ]);
    }
}
