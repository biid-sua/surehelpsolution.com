<?php

namespace App\Livewire\Admin\Customers;

use App\Livewire\Admin\Concerns\FiltersPlatformRecords;
use App\Livewire\Concerns\PlatformAdminOnly;
use App\Models\Customer;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Every business's customers in one list (spec §5 Super Admin "Customers"), for support lookups.
 * Dates filter by when the customer was added.
 */
#[Layout('layouts.portal', ['portal' => 'admin'])]
#[Title('Customers')]
class Index extends Component
{
    use FiltersPlatformRecords;
    use PlatformAdminOnly;
    use WithPagination;

    public function mount(): void
    {
        $this->authorize('customers.view');
    }

    public function render(): View
    {
        $customers = $this->applyCommonFilters(Customer::withoutGlobalScopes(), 'customers.created_at')
            ->with('organization:id,ulid,name')
            ->when(trim($this->search) !== '', fn (Builder $q) => $q->search(trim($this->search)))
            ->latest('last_activity_at')->latest('id')
            ->paginate(30);

        return view('livewire.admin.records.customers', [
            'customers' => $customers,
            'businesses' => $this->businessOptions(),
        ]);
    }
}
