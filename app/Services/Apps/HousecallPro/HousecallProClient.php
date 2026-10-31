<?php

namespace App\Services\Apps\HousecallPro;

/**
 * Housecall Pro's public API (D112), behind one interface: Housecall Pro's own, or a fake one kept in
 * memory. The business's own API key signs every call. Customers come back in one plain shape: id,
 * first_name, last_name, company, email, phone and addresses (id, street, street_line_2, city, state,
 * zip). Calls throw AppAuthorizationLost when the key no longer works and AppRequestFailed when
 * Housecall Pro says no.
 */
interface HousecallProClient
{
    /** @return array{id: string, name: string} */
    public function company(string $key): array;

    /** @return list<array<string, mixed>> customers matching an email, a phone or a name */
    public function searchCustomers(string $key, string $term): array;

    /**
     * @param  array<string, mixed>  $customer
     * @return array<string, mixed> the customer as added
     */
    public function createCustomer(string $key, array $customer): array;

    /** @return array<string, mixed>|null */
    public function customer(string $key, string $id): ?array;

    /** @param  array<string, string>  $address  street, street_line_2, city, state, zip */
    public function addAddress(string $key, string $customerId, array $address): string;

    /** @param  array<string, mixed>  $job */
    public function createJob(string $key, array $job): string;

    /** Moves a job to a new time (start and end in UTC, ISO 8601). */
    public function updateSchedule(string $key, string $jobId, string $start, string $end): void;

    /** @return array{customers: list<array<string, mixed>>, more: bool} newest first */
    public function customers(string $key, int $page, int $pageSize): array;
}
