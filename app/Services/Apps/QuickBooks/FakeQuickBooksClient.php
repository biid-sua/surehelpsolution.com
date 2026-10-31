<?php

namespace App\Services\Apps\QuickBooks;

use App\Exceptions\AppAuthorizationLost;
use App\Exceptions\AppRequestFailed;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * QuickBooks kept in memory (D110), for tests and demos: one company, its customers and payments.
 * Like the real one, a display name can only be used once.
 */
class FakeQuickBooksClient implements QuickBooksClient
{
    public string $company = 'Rivera Plumbing LLC';

    /** @var array<string, array<string, mixed>> by Id */
    public array $customers = [];

    /** @var list<array<string, mixed>> */
    public array $payments = [];

    /** @var list<string> */
    public array $revoked = [];

    public int $refreshes = 0;

    /** When true, every call answers as if the business disconnected us in QuickBooks. */
    public bool $lost = false;

    private int $nextId = 100;

    public function authorizationUrl(string $state, string $redirectUri): string
    {
        return 'https://appcenter.intuit.test/connect/oauth2?'.http_build_query(['state' => $state, 'redirect_uri' => $redirectUri]);
    }

    public function exchange(string $code, string $redirectUri): array
    {
        $this->guard();

        return ['access_token' => 'qb-access-'.$code, 'refresh_token' => 'qb-refresh-'.$code, 'expires_in' => 3600];
    }

    public function refresh(string $refreshToken): array
    {
        $this->guard();
        $this->refreshes++;

        return ['access_token' => 'qb-access-'.$this->refreshes, 'refresh_token' => 'qb-refresh-'.$this->refreshes, 'expires_in' => 3600];
    }

    public function revoke(string $token): void
    {
        $this->revoked[] = $token;
    }

    public function companyName(string $realm, string $token): ?string
    {
        $this->guard();

        return $this->company;
    }

    public function findCustomer(string $realm, string $token, ?string $email, string $displayName): ?array
    {
        $this->guard();
        foreach ([fn ($c) => $email && strcasecmp((string) ($c['PrimaryEmailAddr']['Address'] ?? ''), $email) === 0, fn ($c) => ($c['DisplayName'] ?? '') === $displayName] as $test) {
            foreach ($this->customers as $customer) {
                if ($test($customer)) {
                    return $customer;
                }
            }
        }

        return null;
    }

    public function createCustomer(string $realm, string $token, array $customer): array
    {
        $this->guard();
        foreach ($this->customers as $existing) {
            if (($existing['DisplayName'] ?? '') === ($customer['DisplayName'] ?? '')) {
                throw new AppRequestFailed('Duplicate Name Exists Error. The name supplied already exists.', '6240');
            }
        }

        return $this->addCustomer($customer);
    }

    public function createPayment(string $realm, string $token, array $payment): array
    {
        $this->guard();
        if (! isset($this->customers[(string) ($payment['CustomerRef']['value'] ?? '')])) {
            throw new AppRequestFailed('Invalid Reference Id. Customer not found.', '2500');
        }

        return $this->payments[] = $payment + ['Id' => (string) $this->nextId++];
    }

    public function voidPayment(string $realm, string $token, string $id, string $note): void
    {
        $this->guard();
        foreach ($this->payments as $i => $payment) {
            if ($payment['Id'] === $id) {
                $this->payments[$i] = ['TotalAmt' => 0, 'Voided' => true, 'PrivateNote' => $note] + $payment;

                return;
            }
        }

        throw new AppRequestFailed('Object Not Found.', '610');
    }

    public function customersChangedSince(string $realm, string $token, ?CarbonInterface $since, int $start, int $max): array
    {
        $this->guard();
        $rows = array_values(array_filter($this->customers, fn ($c) => ! $since || CarbonImmutable::parse($c['MetaData']['LastUpdatedTime'])->greaterThan($since)));
        usort($rows, fn ($a, $b) => strcmp($a['MetaData']['LastUpdatedTime'], $b['MetaData']['LastUpdatedTime']));

        return array_slice($rows, max(1, $start) - 1, $max);
    }

    /**
     * A customer added in QuickBooks itself.
     *
     * @param  array<string, mixed>  $customer
     * @return array<string, mixed>
     */
    public function addCustomer(array $customer): array
    {
        $id = (string) $this->nextId++;

        return $this->customers[$id] = $customer + ['Id' => $id, 'Active' => true, 'MetaData' => ['LastUpdatedTime' => now()->toIso8601String()]];
    }

    private function guard(): void
    {
        if ($this->lost) {
            throw new AppAuthorizationLost('QuickBooks no longer accepts our access.');
        }
    }
}
