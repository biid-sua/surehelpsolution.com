<?php

namespace App\Livewire\Admin;

use App\Livewire\Concerns\PlatformAdminOnly;
use App\Models\Appointment;
use App\Models\CallLog;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * One box to find anything on the platform (spec ADM-09): businesses, people, callers, calls by ID,
 * appointments. Each group only appears for staff allowed to see it.
 */
#[Layout('layouts.portal', ['portal' => 'admin'])]
#[Title('Search')]
class Search extends Component
{
    use PlatformAdminOnly;

    private const LIMIT = 8;

    #[Url(except: '')]
    public string $q = '';

    public function render(): View
    {
        $term = trim($this->q);
        $user = auth()->user();
        $results = [];

        if (mb_strlen($term) >= 2) {
            $like = '%'.addcslashes($term, '%_\\').'%';
            $digits = preg_replace('/\D/', '', $term) ?? '';

            if ($user->hasPermissionIn('organization.view')) {
                $results['businesses'] = Organization::query()
                    ->where(fn (Builder $q) => $q->where('name', 'like', $like)->orWhere('ulid', $term)->orWhere('slug', 'like', $like))
                    ->orderBy('name')->limit(self::LIMIT)->get(['id', 'ulid', 'name', 'status', 'timezone']);
            }
            if ($user->hasPermissionIn('users.view')) {
                $results['people'] = User::query()
                    ->where(fn (Builder $q) => $q->where('name', 'like', $like)->orWhere('email', 'like', $like)->orWhere('unique_id', $term)
                        ->when(strlen($digits) >= 4, fn (Builder $p) => $p->orWhere('phone', 'like', '%'.$digits.'%')))
                    ->with('organizations:id,ulid,name')->orderBy('name')->limit(self::LIMIT)->get(['id', 'name', 'email', 'role', 'is_active', 'unique_id']);
            }
            if ($user->hasPermissionIn('customers.view')) {
                $results['callers'] = Customer::withoutGlobalScopes()->search($term)
                    ->with('organization:id,ulid,name')->latest('id')->limit(self::LIMIT)->get();
            }
            if ($user->hasPermissionIn('calls.view')) {
                $results['calls'] = CallLog::withoutGlobalScopes()
                    ->where(fn (Builder $q) => $q->where('call_id', 'like', addcslashes(strtoupper($term), '%_\\').'%')->orWhere('caller_name', 'like', $like)
                        ->when(strlen($digits) >= 4, fn (Builder $p) => $p->orWhere('caller_phone', 'like', '%'.$digits.'%')))
                    ->with('organization:id,ulid,name')->latest('created_at')->limit(self::LIMIT)->get();
            }
            if ($user->hasPermissionIn('appointments.view')) {
                $results['appointments'] = Appointment::withoutGlobalScopes()
                    ->where(fn (Builder $q) => $q->where('title', 'like', $like)->orWhere('ulid', $term)
                        ->orWhereHas('customer', fn (Builder $c) => $c->withoutGlobalScopes()->search($term)))
                    ->with(['organization:id,ulid,name,timezone', 'customer' => fn ($c) => $c->withoutGlobalScopes()])->latest('starts_at')->limit(self::LIMIT)->get();
            }
        }

        return view('livewire.admin.search', [
            'term' => $term,
            'results' => $results,
            'total' => collect($results)->sum(fn ($group) => $group->count()),
            'canImpersonate' => $user->hasPermissionIn('users.impersonate'),
        ]);
    }
}
