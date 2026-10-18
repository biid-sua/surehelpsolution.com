<?php

namespace App\Livewire\Client\Customers;

use App\Actions\Customers\CreateCustomer;
use App\Actions\Customers\DuplicateCustomer;
use App\Enums\CustomerStatus;
use App\Livewire\Concerns\ScopedToOrganization;
use App\Models\Customer;
use App\Models\Tag;
use App\Services\Customers\DuplicateCustomers;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
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

    public function create(CreateCustomer $create): mixed
    {
        $organization = $this->organization();
        $this->authorize('customers.create', $organization);

        $validator = Validator::make($this->form, CreateCustomer::rules(), CreateCustomer::messages());
        if ($validator->fails()) {
            foreach ($validator->errors()->messages() as $field => $messages) {
                $this->addError('form.'.$field, $messages[0]);
            }

            return null;
        }

        try {
            $customer = $create->handle($organization, $this->form, auth()->user());
        } catch (ValidationException $e) {
            $this->addError('form.phone', collect($e->errors())->flatten()->first());

            return null;
        } catch (DuplicateCustomer $e) {
            $this->addError('form.phone', $e->getMessage());

            return null;
        }

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
