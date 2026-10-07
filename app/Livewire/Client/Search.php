<?php

namespace App\Livewire\Client;

use App\Livewire\Concerns\ScopedToOrganization;
use App\Models\Appointment;
use App\Models\CallLog;
use App\Models\Customer;
use App\Models\Task;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
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

    public function render(): View
    {
        $organization = $this->organization();
        $user = auth()->user();
        $term = trim($this->q);
        $results = [];

        if (mb_strlen($term) >= 2) {
            $like = '%'.addcslashes($term, '%_\\').'%';
            $digits = preg_replace('/\D/', '', $term) ?? '';

            if ($user->can('customers.view', $organization)) {
                $results['customers'] = Customer::query()->forOrganization($organization)->search($term)->latest('last_activity_at')->limit(self::LIMIT)->get();
            }
            if ($user->can('calls.view', $organization)) {
                $results['calls'] = CallLog::query()->forOrganization($organization)
                    ->where(fn (Builder $q) => $q->where('call_id', 'like', addcslashes(strtoupper($term), '%_\\').'%')->orWhere('caller_name', 'like', $like)
                        ->when(strlen($digits) >= 4, fn (Builder $p) => $p->orWhere('caller_phone', 'like', '%'.$digits.'%')))
                    ->latest('created_at')->limit(self::LIMIT)->get();
            }
            if ($user->can('appointments.view', $organization)) {
                $results['appointments'] = Appointment::query()->forOrganization($organization)
                    ->where(fn (Builder $q) => $q->where('title', 'like', $like)->orWhereHas('customer', fn (Builder $c) => $c->search($term)))
                    ->with('customer:id,first_name,last_name,company')->latest('starts_at')->limit(self::LIMIT)->get();
            }
            if ($user->can('tasks.view', $organization)) {
                $results['tasks'] = Task::query()->forOrganization($organization)
                    ->where(fn (Builder $q) => $q->where('title', 'like', $like)->orWhereHas('customer', fn (Builder $c) => $c->search($term)))
                    ->with('customer:id,first_name,last_name,company')->latest('id')->limit(self::LIMIT)->get();
            }
        }

        return view('livewire.client.search', [
            'term' => $term,
            'results' => $results,
            'total' => collect($results)->sum(fn ($group) => $group->count()),
            'timezone' => $organization->timezoneOrDefault(),
        ]);
    }
}
