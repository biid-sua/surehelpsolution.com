<?php

namespace App\Services\Apps\HousecallPro;

use App\Exceptions\AppAuthorizationLost;
use App\Exceptions\AppRequestFailed;
use Illuminate\Support\Str;

/**
 * Housecall Pro kept in memory (D112), for tests and demos. The only key it accepts is $key.
 */
class FakeHousecallProClient implements HousecallProClient
{
    public string $key = 'hcp_test_key';

    /** @var array{id: string, name: string} */
    public array $company = ['id' => 'co_1', 'name' => 'Rivera Plumbing'];

    /** @var array<string, array<string, mixed>> by id, in the plain customer shape */
    public array $customers = [];

    /** @var list<array<string, mixed>> */
    public array $jobs = [];

    private int $nextId = 1;

    public function company(string $key): array
    {
        $this->guard($key);

        return $this->company;
    }

    public function searchCustomers(string $key, string $term): array
    {
        $this->guard($key);
        $needle = Str::lower($term);
        $digits = preg_replace('/\D/', '', $term);

        return array_values(array_filter($this->customers, fn (array $c) => Str::lower((string) $c['email']) === $needle
            || ($digits !== '' && strlen($digits) >= 7 && str_ends_with((string) preg_replace('/\D/', '', (string) $c['phone']), substr($digits, -10)))
            || Str::lower(trim($c['first_name'].' '.$c['last_name'])) === $needle));
    }

    public function createCustomer(string $key, array $customer): array
    {
        $this->guard($key);
        if (blank($customer['first_name'] ?? null) && blank($customer['last_name'] ?? null) && blank($customer['company'] ?? null)) {
            throw new AppRequestFailed('first_name, last_name or company is required', '422');
        }

        return $this->addCustomer([
            'first_name' => $customer['first_name'] ?? null, 'last_name' => $customer['last_name'] ?? null, 'company' => $customer['company'] ?? null,
            'email' => $customer['email'] ?? null, 'phone' => $customer['mobile_number'] ?? null,
            'addresses' => array_map(fn (array $a) => $a + ['id' => 'adr_'.$this->nextId++], (array) ($customer['addresses'] ?? [])),
        ]);
    }

    public function customer(string $key, string $id): ?array
    {
        $this->guard($key);

        return $this->customers[$id] ?? null;
    }

    public function addAddress(string $key, string $customerId, array $address): string
    {
        $this->guard($key);
        $id = 'adr_'.$this->nextId++;
        $this->customers[$customerId]['addresses'][] = $address + ['id' => $id];

        return $id;
    }

    public function createJob(string $key, array $job): string
    {
        $this->guard($key);
        $customer = $this->customers[(string) ($job['customer_id'] ?? '')] ?? null;
        if (! $customer || ! in_array($job['address_id'] ?? null, array_column($customer['addresses'], 'id'), true)) {
            throw new AppRequestFailed('customer_id and address_id must belong together', '422');
        }
        $this->jobs[] = $job + ['id' => 'job_'.$this->nextId++];

        return (string) end($this->jobs)['id'];
    }

    public function customers(string $key, int $page, int $pageSize): array
    {
        $this->guard($key);
        $all = array_reverse(array_values($this->customers));

        return ['customers' => array_slice($all, ($page - 1) * $pageSize, $pageSize), 'more' => $page * $pageSize < count($all)];
    }

    /**
     * A customer added in Housecall Pro itself.
     *
     * @param  array<string, mixed>  $customer
     * @return array<string, mixed>
     */
    public function addCustomer(array $customer): array
    {
        $id = 'cus_'.$this->nextId++;

        return $this->customers[$id] = $customer + ['id' => $id, 'first_name' => null, 'last_name' => null, 'company' => null, 'email' => null, 'phone' => null, 'addresses' => []];
    }

    private function guard(string $key): void
    {
        if ($key !== $this->key) {
            throw new AppAuthorizationLost('Housecall Pro no longer accepts this API key.');
        }
    }
}
