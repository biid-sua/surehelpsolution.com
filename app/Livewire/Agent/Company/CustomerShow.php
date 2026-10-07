<?php

namespace App\Livewire\Agent\Company;

use App\Actions\Customers\RecordTimelineEvent;
use App\Actions\Customers\UpdateCustomer;
use App\Enums\CustomerStatus;
use App\Enums\TimelineEventType;
use App\Livewire\Concerns\InAgentCompany;
use App\Models\Appointment;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\Task;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * One customer of one company (spec §20A nested resources): looked up inside the company, so a
 * customer id from another company is simply "not found". Agents with customers.update correct the
 * details and add notes (AGT-14), through the same action as the business portal.
 */
#[Layout('layouts.portal', ['portal' => 'agent'])]
#[Title('Customer')]
class CustomerShow extends Component
{
    use InAgentCompany;

    #[Locked]
    public string $customerUlid;

    public bool $editing = false;

    /** @var array<string, mixed> */
    public array $form = [];

    public string $note = '';

    public function mount(Organization $organization, string $customer): void
    {
        $this->enterCompany($organization, 'customers.view');
        $this->customerUlid = $customer;
        $this->customer($organization); // 404 now if it isn't this company's
    }

    public function edit(): void
    {
        $this->form = UpdateCustomer::formFor($this->customer($this->company('customers.update')));
        $this->resetValidation();
        $this->editing = true;
    }

    public function save(UpdateCustomer $update): void
    {
        $company = $this->company('customers.update');
        $data = $this->validate(collect(UpdateCustomer::rules())->mapWithKeys(fn ($r, $k) => ["form.$k" => $r])->all())['form'];

        try {
            $update->handle($this->customer($company), $data, auth()->user());
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                $this->addError('form.'.$field, $messages[0]);
            }

            return;
        }

        $this->editing = false;
        $this->dispatch('toast', type: 'success', message: 'Customer updated.');
    }

    public function addNote(RecordTimelineEvent $timeline): void
    {
        $company = $this->company('customers.update');
        $this->validate(['note' => ['required', 'string', 'max:5000']], attributes: ['note' => 'note']);

        $timeline->handle($this->customer($company), TimelineEventType::NoteAdded, 'Note added', trim($this->note), actorId: auth()->id());

        $this->reset('note');
        $this->dispatch('toast', type: 'success', message: 'Note added.');
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
            'canUpdate' => auth()->user()->hasPermissionIn('customers.update', $company),
            'statuses' => CustomerStatus::cases(),
            'contactMethods' => Customer::CONTACT_METHODS,
        ]);
    }
}
