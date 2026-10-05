<?php

namespace App\Livewire\Client\Customers;

use App\Actions\Customers\MergeCustomers;
use App\Livewire\Concerns\ScopedToOrganization;
use App\Models\Customer;
use App\Services\Customers\DuplicateCustomers;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Review likely duplicates and merge them, a person deciding each time (spec CRM-04).
 */
#[Layout('layouts.portal', ['portal' => 'client'])]
#[Title('Duplicate customers')]
class Duplicates extends Component
{
    use ScopedToOrganization;

    /** Customer ULIDs being compared. */
    #[Url(as: 'a', except: '')]
    public string $first = '';

    #[Url(as: 'b', except: '')]
    public string $second = '';

    /** Which of the two stays: 'a' or 'b'. */
    public string $keep = 'a';

    public string $search = '';

    public function mount(): void
    {
        $this->authorize('customers.delete', $this->organization());
    }

    public function compare(string $a, string $b): void
    {
        $this->first = $a;
        $this->second = $b;
        $this->keep = 'a';
    }

    public function pick(string $ulid): void
    {
        $this->second = $ulid;
        $this->search = '';
    }

    public function close(): void
    {
        $this->reset('first', 'second', 'search');
    }

    public function merge(MergeCustomers $merge): void
    {
        $this->authorize('customers.delete', $this->organization());
        [$a, $b] = [$this->customer($this->first), $this->customer($this->second)];
        [$keep, $duplicate] = $this->keep === 'b' ? [$b, $a] : [$a, $b];

        $kept = $merge->handle($keep, $duplicate, auth()->user());
        session()->flash('status', $duplicate->fullName().' was merged into '.$kept->fullName().'.');
        $this->redirectRoute('app.customers.show', $kept);
    }

    public function render(DuplicateCustomers $duplicates): View
    {
        $organization = $this->organization();
        $a = $this->first ? $this->customer($this->first) : null;
        $b = $this->second ? $this->customer($this->second) : null;
        $describe = fn (?Customer $c) => $c ? [
            'customer' => $c,
            'calls' => $c->calls()->withoutGlobalScopes()->count(),
            'appointments' => $c->appointments()->withoutGlobalScopes()->count(),
            'tasks' => $c->tasks()->withoutGlobalScopes()->count(),
        ] : null;

        return view('livewire.client.customers.duplicates', [
            'pairs' => $a ? collect() : $duplicates->for($organization),
            'a' => $describe($a),
            'b' => $describe($b),
            'matches' => $a && ! $b && mb_strlen(trim($this->search)) >= 2
                ? Customer::query()->forOrganization($organization)->search($this->search)->whereKeyNot($a->id)->limit(8)->get()
                : collect(),
        ]);
    }

    private function customer(string $ulid): Customer
    {
        return Customer::query()->forOrganization($this->organization())->where('ulid', $ulid)->firstOrFail();
    }
}
