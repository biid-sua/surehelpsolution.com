<?php

namespace App\Services\Apps\Jobber;

use App\Exceptions\AppAuthorizationLost;
use App\Exceptions\AppRequestFailed;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;

/**
 * Jobber kept in memory (D111), for tests and demos: one account, its clients and requests.
 */
class FakeJobberClient implements JobberClient
{
    /** @var array{id: string, name: string} */
    public array $account = ['id' => 'acc_1', 'name' => 'Rivera Plumbing'];

    /** @var array<string, array<string, mixed>> by id, in the plain client shape */
    public array $clients = [];

    /** @var list<array<string, mixed>> */
    public array $requests = [];

    public int $disconnects = 0;

    public int $refreshes = 0;

    public bool $lost = false;

    private int $nextId = 1;

    public function authorizationUrl(string $state, string $redirectUri): string
    {
        return 'https://api.getjobber.test/api/oauth/authorize?'.http_build_query(['state' => $state, 'redirect_uri' => $redirectUri]);
    }

    public function exchange(string $code, string $redirectUri): array
    {
        $this->guard();

        return ['access_token' => 'jb-access-'.$code, 'refresh_token' => 'jb-refresh-'.$code, 'expires_in' => 3600];
    }

    public function refresh(string $refreshToken): array
    {
        $this->guard();
        $this->refreshes++;

        return ['access_token' => 'jb-access-r'.$this->refreshes, 'refresh_token' => 'jb-refresh-r'.$this->refreshes, 'expires_in' => 3600];
    }

    public function disconnect(string $token): void
    {
        $this->disconnects++;
    }

    public function account(string $token): array
    {
        $this->guard();

        return $this->account;
    }

    public function searchClients(string $token, string $term): array
    {
        $this->guard();
        $needle = Str::lower($term);

        return array_values(array_filter($this->clients, fn (array $c) => Str::lower((string) $c['email']) === $needle
            || preg_replace('/\D/', '', (string) $c['phone']) === preg_replace('/\D/', '', $term) && preg_replace('/\D/', '', $term) !== ''
            || Str::lower(trim($c['firstName'].' '.$c['lastName'])) === $needle));
    }

    public function createClient(string $token, array $input): string
    {
        $this->guard();
        if (blank($input['firstName'] ?? null) && blank($input['lastName'] ?? null) && blank($input['companyName'] ?? null)) {
            throw new AppRequestFailed('A client needs a name or a company name.');
        }

        return (string) $this->addClient([
            'firstName' => $input['firstName'] ?? null, 'lastName' => $input['lastName'] ?? null, 'companyName' => $input['companyName'] ?? null,
            'email' => $input['emails'][0]['address'] ?? null, 'phone' => $input['phones'][0]['number'] ?? null, 'address' => $input['billingAddress'] ?? [],
        ])['id'];
    }

    public function createRequest(string $token, array $input): string
    {
        $this->guard();
        if (! isset($this->clients[(string) ($input['clientId'] ?? '')])) {
            throw new AppRequestFailed('Client not found.');
        }
        $this->requests[] = $input + ['id' => 'req_'.$this->nextId++];

        return (string) end($this->requests)['id'];
    }

    public function clientsChangedSince(string $token, ?CarbonInterface $since, ?string $after, int $first): array
    {
        $this->guard();
        $rows = array_values(array_filter($this->clients, fn ($c) => ! $since || CarbonImmutable::parse($c['updatedAt'])->greaterThan($since)));
        usort($rows, fn ($a, $b) => strcmp($a['updatedAt'], $b['updatedAt']));
        $offset = (int) ($after ?? 0);
        $page = array_slice($rows, $offset, $first);

        return ['clients' => $page, 'next' => $offset + $first < count($rows) ? (string) ($offset + $first) : null];
    }

    /**
     * A client added in Jobber itself.
     *
     * @param  array<string, mixed>  $client
     * @return array<string, mixed>
     */
    public function addClient(array $client): array
    {
        $id = 'cl_'.$this->nextId++;

        return $this->clients[$id] = $client + ['id' => $id, 'firstName' => null, 'lastName' => null, 'companyName' => null, 'email' => null,
            'phone' => null, 'address' => [], 'archived' => false, 'updatedAt' => now()->toIso8601String()];
    }

    private function guard(): void
    {
        if ($this->lost) {
            throw new AppAuthorizationLost('Jobber no longer accepts our access.');
        }
    }
}
