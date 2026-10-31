<?php

namespace App\Services\Apps\HousecallPro;

use App\Exceptions\AppAuthorizationLost;
use App\Exceptions\AppRequestFailed;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Housecall Pro's public REST API (D112), signed with the business's own API key (made in Housecall
 * Pro under App Store › API). https://docs.housecallpro.com
 */
class RestHousecallProClient implements HousecallProClient
{
    public function company(string $key): array
    {
        $company = (array) $this->ok($this->http($key)->get('/company'))->json();

        return ['id' => (string) ($company['id'] ?? ''), 'name' => (string) ($company['name'] ?? '')];
    }

    public function searchCustomers(string $key, string $term): array
    {
        $rows = $this->ok($this->http($key)->get('/customers', ['q' => $term, 'page' => 1, 'page_size' => 10]))->json('customers', []);

        return array_map(fn (array $c) => $this->plain($c), array_values(array_filter((array) $rows, 'is_array')));
    }

    public function createCustomer(string $key, array $customer): array
    {
        return $this->plain((array) $this->ok($this->http($key)->post('/customers', $customer))->json());
    }

    public function customer(string $key, string $id): ?array
    {
        $response = $this->http($key)->get('/customers/'.rawurlencode($id));
        if ($response->status() === 404) {
            return null;
        }

        return $this->plain((array) $this->ok($response)->json());
    }

    public function addAddress(string $key, string $customerId, array $address): string
    {
        return (string) $this->ok($this->http($key)->post('/customers/'.rawurlencode($customerId).'/addresses', $address))->json('id');
    }

    public function createJob(string $key, array $job): string
    {
        $id = (string) $this->ok($this->http($key)->post('/jobs', $job))->json('id');
        if ($id === '') {
            throw new AppRequestFailed('Housecall Pro didn\'t add the job.');
        }

        return $id;
    }

    public function updateSchedule(string $key, string $jobId, string $start, string $end): void
    {
        $this->ok($this->http($key)->put('/jobs/'.rawurlencode($jobId).'/schedule', ['start_time' => $start, 'end_time' => $end, 'arrival_window' => 0]));
    }

    public function customers(string $key, int $page, int $pageSize): array
    {
        $response = $this->ok($this->http($key)->get('/customers', ['page' => $page, 'page_size' => $pageSize, 'sort_direction' => 'desc']));
        $rows = array_values(array_filter((array) $response->json('customers', []), 'is_array'));

        return ['customers' => array_map(fn (array $c) => $this->plain($c), $rows), 'more' => $page < (int) $response->json('total_pages', 1)];
    }

    /**
     * @param  array<string, mixed>  $c
     * @return array<string, mixed>
     */
    private function plain(array $c): array
    {
        return [
            'id' => (string) ($c['id'] ?? ''), 'first_name' => $c['first_name'] ?? null, 'last_name' => $c['last_name'] ?? null, 'company' => $c['company'] ?? null,
            'email' => $c['email'] ?? null, 'phone' => $c['mobile_number'] ?? $c['home_number'] ?? $c['work_number'] ?? null,
            'addresses' => array_values(array_map(fn (array $a) => [
                'id' => (string) ($a['id'] ?? ''), 'street' => $a['street'] ?? null, 'street_line_2' => $a['street_line_2'] ?? null,
                'city' => $a['city'] ?? null, 'state' => $a['state'] ?? null, 'zip' => $a['zip'] ?? null,
            ], array_filter((array) ($c['addresses'] ?? []), 'is_array'))),
        ];
    }

    private function http(string $key): PendingRequest
    {
        return Http::baseUrl((string) config('apps.housecall_pro.base_url'))->withHeaders(['Authorization' => 'Token '.$key])->acceptJson()->asJson()->timeout(30);
    }

    private function ok(Response $response): Response
    {
        if (in_array($response->status(), [401, 403], true)) {
            throw new AppAuthorizationLost('Housecall Pro no longer accepts this API key.');
        }
        if (! $response->successful()) {
            $message = $response->json('error.message') ?? $response->json('message') ?? $response->json('error');

            throw new AppRequestFailed(Str::limit(is_string($message) && $message !== '' ? $message : 'Housecall Pro answered '.$response->status().'.', 300),
                $response->status() === 429 ? 'THROTTLED' : (string) $response->status());
        }

        return $response;
    }
}
