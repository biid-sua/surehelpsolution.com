<?php

namespace App\Livewire\Client\Customers;

use App\Enums\CustomerStatus;
use App\Livewire\Concerns\ScopedToOrganization;
use App\Models\Customer;
use App\Models\Tag;
use App\Services\Customers\DuplicateCustomers;
use App\Support\Audit\Audit;
use App\Support\Phone;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Customer list (spec §12, §47, §90): search, filters, server pagination, quick add.
 */
#[Layout('layouts.portal', ['portal' => 'client'])]
#[Title('Customers')]
class Index extends Component
{
    use ScopedToOrganization;
    use WithPagination;

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $status = '';

    #[Url(except: '')]
    public string $tag = '';

    /** Where the customer came from (Customer::SOURCES). */
    #[Url(except: '')]
    public string $source = '';

    public bool $adding = false;

    /** @var array<string, string> */
    public array $form = ['first_name' => '', 'last_name' => '', 'company' => '', 'phone' => '', 'email' => ''];

    public function mount(): void
    {
        $this->authorize('customers.view', $this->organization());
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'status', 'tag', 'source'], true)) {
            $this->resetPage();
        }
    }

    public function add(): void
    {
        $this->authorize('customers.create', $this->organization());
        $this->reset('form');
        $this->resetValidation();
        $this->adding = true;
    }

    public function create(Audit $audit): mixed
    {
        $organization = $this->organization();
        $this->authorize('customers.create', $organization);

        $this->validate([
            'form.first_name' => ['nullable', 'string', 'max:100', 'required_without_all:form.last_name,form.company'],
            'form.last_name' => ['nullable', 'string', 'max:100'],
            'form.company' => ['nullable', 'string', 'max:255'],
            'form.phone' => ['nullable', 'string', 'max:40', 'required_without:form.email'],
            'form.email' => ['nullable', 'email', 'max:255'],
        ], [
            'form.first_name.required_without_all' => 'Enter a name or a company.',
            'form.phone.required_without' => 'Enter a phone number or an email.',
        ]);

        $e164 = Phone::normalize($this->form['phone']);
        if (filled($this->form['phone']) && $e164 === null) {
            $this->addError('form.phone', 'This doesn\'t look like a valid phone number.');

            return null;
        }

        // Duplicate prevention (spec §15/§93): point to the existing customer instead of creating a second one.
        if ($e164 && $existing = Customer::withTrashed()->forOrganization($organization)->where('phone_e164', $e164)->first()) {
            $this->addError('form.phone', "{$existing->fullName()} already has this number.");

            return null;
        }

        $customer = Customer::create([
            'organization_id' => $organization->id,
            'first_name' => $this->form['first_name'] ?: null,
            'last_name' => $this->form['last_name'] ?: null,
            'company' => $this->form['company'] ?: null,
            'phone' => $this->form['phone'] ?: null,
            'email' => $this->form['email'] ?: null,
            'status' => CustomerStatus::Lead,
            'source' => 'manual',
            'created_by_user_id' => auth()->id(),
            'last_activity_at' => now(),
        ]);
        $audit->record('customer.created', $customer, new: ['name' => $customer->fullName(), 'source' => 'manual']);

        return $this->redirectRoute('app.customers.show', $customer, navigate: false);
    }

    public function render(): View
    {
        $organization = $this->organization();

        $customers = Customer::query()->forOrganization($organization)
            ->with('tags:id,name,color')
            ->search($this->search)
            ->when(CustomerStatus::tryFrom($this->status), fn (Builder $q, CustomerStatus $s) => $q->where('status', $s))
            ->when($this->tag !== '', fn (Builder $q) => $q->whereHas('tags', fn (Builder $t) => $t->whereKey((int) $this->tag)))
            ->when(in_array($this->source, Customer::SOURCES, true), fn (Builder $q) => $q->where('source', $this->source))
            ->orderByRaw('last_activity_at IS NULL')
            ->orderByDesc('last_activity_at')
            ->orderByDesc('id')
            ->paginate(25);

        return view('livewire.client.customers.index', [
            'customers' => $customers,
            // Cheap check first; only list pairs (one per pair, whatever matched) when there are any.
            'duplicateCount' => auth()->user()->can('customers.delete', $organization) && app(DuplicateCustomers::class)->count($organization) > 0
                ? app(DuplicateCustomers::class)->for($organization)->count() : 0,
            'statuses' => CustomerStatus::cases(),
            'tags' => Tag::query()->forOrganization($organization)->orderBy('name')->get(['id', 'name']),
            'filtered' => $this->search !== '' || $this->status !== '' || $this->tag !== '',
            'canCreate' => auth()->user()->can('customers.create', $organization),
            'timezone' => $organization->timezoneOrDefault(),
        ]);
    }
}
