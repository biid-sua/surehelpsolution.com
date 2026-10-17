<?php

namespace App\Services\Search;

use App\Models\Appointment;
use App\Models\CallLog;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Search across a business (CLI search, API-17): customers, calls, appointments and tasks, each only
 * when the person may see that kind of record. Shared by the web search page and the app.
 */
class BusinessSearch
{
    /**
     * @return array{customers?: Collection<int, Customer>, calls?: Collection<int, CallLog>, appointments?: Collection<int, Appointment>, tasks?: Collection<int, Task>} newest first
     */
    public function run(Organization $organization, User $user, string $term, int $limit = 8): array
    {
        $term = trim($term);
        if (mb_strlen($term) < 2) {
            return [];
        }
        $like = '%'.addcslashes($term, '%_\\').'%';
        $digits = preg_replace('/\D/', '', $term) ?? '';
        $results = [];

        if ($user->can('customers.view', $organization)) {
            $results['customers'] = Customer::query()->forOrganization($organization)->search($term)->latest('last_activity_at')->limit($limit)->get();
        }
        if ($user->can('calls.view', $organization)) {
            $results['calls'] = CallLog::query()->forOrganization($organization)
                ->where(fn (Builder $q) => $q->where('call_id', 'like', addcslashes(strtoupper($term), '%_\\').'%')->orWhere('caller_name', 'like', $like)
                    ->when(strlen($digits) >= 4, fn (Builder $p) => $p->orWhere('caller_phone', 'like', '%'.$digits.'%')))
                ->latest('created_at')->limit($limit)->get();
        }
        if ($user->can('appointments.view', $organization)) {
            $results['appointments'] = Appointment::query()->forOrganization($organization)
                ->where(fn (Builder $q) => $q->where('title', 'like', $like)->orWhereHas('customer', fn (Builder $c) => $c->search($term)))
                ->with('customer:id,first_name,last_name,company')->latest('starts_at')->limit($limit)->get();
        }
        if ($user->can('tasks.view', $organization)) {
            $results['tasks'] = Task::query()->forOrganization($organization)
                ->where(fn (Builder $q) => $q->where('title', 'like', $like)->orWhereHas('customer', fn (Builder $c) => $c->search($term)))
                ->with('customer:id,first_name,last_name,company')->latest('id')->limit($limit)->get();
        }

        return $results;
    }
}
