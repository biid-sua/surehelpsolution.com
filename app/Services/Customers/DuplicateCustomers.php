<?php

namespace App\Services\Customers;

use App\Models\Customer;
use App\Models\Organization;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Likely duplicate customers for a person to review (spec CRM-04): the same email address, or the
 * same full name. Phones can't repeat (they're unique per business), so they aren't a signal here.
 */
class DuplicateCustomers
{
    /** @return Collection<int, array{a: Customer, b: Customer, reason: string}> */
    public function for(Organization $organization, int $limit = 25): Collection
    {
        $customers = Customer::query()->forOrganization($organization)
            ->get(['id', 'ulid', 'organization_id', 'first_name', 'last_name', 'company', 'email', 'phone', 'phone_e164', 'status', 'created_at']);

        $pairs = collect();
        $seen = [];
        $add = function (Collection $group, string $reason) use (&$pairs, &$seen) {
            $group = $group->sortBy('id')->values();
            for ($i = 1; $i < $group->count(); $i++) {
                $key = $group[0]->id.'-'.$group[$i]->id;
                if (! isset($seen[$key])) {
                    $seen[$key] = true;
                    $pairs->push(['a' => $group[0], 'b' => $group[$i], 'reason' => $reason]);
                }
            }
        };

        $customers->filter(fn (Customer $c) => filled($c->email))->groupBy(fn (Customer $c) => mb_strtolower(trim($c->email)))
            ->filter(fn (Collection $g) => $g->count() > 1)->each(fn (Collection $g) => $add($g, 'Same email'));
        $customers->filter(fn (Customer $c) => filled($c->first_name) && filled($c->last_name))
            ->groupBy(fn (Customer $c) => mb_strtolower(trim($c->first_name).' '.trim($c->last_name)))
            ->filter(fn (Collection $g) => $g->count() > 1)->each(fn (Collection $g) => $add($g, 'Same name'));

        return $pairs->take($limit)->values();
    }

    /** Records sharing an email or a full name, counted in the database: a quick "any duplicates?" check (a pair matching both counts twice). */
    public function count(Organization $organization): int
    {
        $name = DB::connection((new Customer)->getConnectionName())->getDriverName() === 'sqlite'
            ? "first_name || ' ' || last_name" : "CONCAT(first_name, ' ', last_name)";
        $extra = fn ($query, string $key) => (int) $query->toBase()->selectRaw('COUNT(*) - 1 as extra')
            ->groupByRaw("LOWER(TRIM($key))")->havingRaw('COUNT(*) > 1')->get()->sum('extra');

        return $extra(Customer::query()->forOrganization($organization)->whereNotNull('email')->where('email', '<>', ''), 'email')
            + $extra(Customer::query()->forOrganization($organization)->whereNotNull('first_name')->where('first_name', '<>', '')
                ->whereNotNull('last_name')->where('last_name', '<>', ''), $name);
    }
}
