<?php

namespace App\Services\Apps\QuickBooks;

use Carbon\CarbonInterface;

/**
 * QuickBooks Online's accounting API (D110), behind one interface: Intuit's own, or a fake one kept
 * in memory. Records are QuickBooks' JSON shapes (Customer, Payment). Calls throw
 * AppAuthorizationLost when the access is gone and AppRequestFailed when QuickBooks says no.
 */
interface QuickBooksClient
{
    public function authorizationUrl(string $state, string $redirectUri): string;

    /** @return array{access_token: string, refresh_token: string, expires_in: int} */
    public function exchange(string $code, string $redirectUri): array;

    /** @return array{access_token: string, refresh_token: string, expires_in: int} */
    public function refresh(string $refreshToken): array;

    public function revoke(string $token): void;

    public function companyName(string $realm, string $token): ?string;

    /** @return array<string, mixed>|null the first active customer with this email, else with this display name */
    public function findCustomer(string $realm, string $token, ?string $email, string $displayName): ?array;

    /**
     * @param  array<string, mixed>  $customer
     * @return array<string, mixed>
     */
    public function createCustomer(string $realm, string $token, array $customer): array;

    /**
     * @param  array<string, mixed>  $payment
     * @return array<string, mixed>
     */
    public function createPayment(string $realm, string $token, array $payment): array;

    /**
     * Customers added or changed after $since (all of them when null), oldest change first.
     *
     * @return list<array<string, mixed>>
     */
    public function customersChangedSince(string $realm, string $token, ?CarbonInterface $since, int $start, int $max): array;
}
