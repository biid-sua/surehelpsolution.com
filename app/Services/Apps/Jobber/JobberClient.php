<?php

namespace App\Services\Apps\Jobber;

use Carbon\CarbonInterface;

/**
 * Jobber's GraphQL API (D111), behind one interface: Jobber's own, or a fake one kept in memory.
 * Clients come back in one plain shape: id, firstName, lastName, companyName, email, phone,
 * address (street1, street2, city, province, postalCode), archived and updatedAt. Calls throw
 * AppAuthorizationLost when the access is gone and AppRequestFailed when Jobber says no.
 */
interface JobberClient
{
    public function authorizationUrl(string $state, string $redirectUri): string;

    /** @return array{access_token: string, refresh_token: string, expires_in: int} */
    public function exchange(string $code, string $redirectUri): array;

    /** @return array{access_token: string, refresh_token: string, expires_in: int} */
    public function refresh(string $refreshToken): array;

    /** Removes SureHelp from the business's Jobber account. */
    public function disconnect(string $token): void;

    /** @return array{id: string, name: string} */
    public function account(string $token): array;

    /** @return list<array<string, mixed>> clients matching an email, a phone or a name */
    public function searchClients(string $token, string $term): array;

    /** @param  array<string, mixed>  $input  ClientCreateInput */
    public function createClient(string $token, array $input): string;

    /** @param  array<string, mixed>  $input  RequestCreateInput */
    public function createRequest(string $token, array $input): string;

    /**
     * Clients added or changed after $since (all of them when null).
     *
     * @return array{clients: list<array<string, mixed>>, next: ?string} next: the cursor for the following page
     */
    public function clientsChangedSince(string $token, ?CarbonInterface $since, ?string $after, int $first): array;
}
