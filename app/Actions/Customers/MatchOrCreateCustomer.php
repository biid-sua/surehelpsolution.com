<?php

namespace App\Actions\Customers;

use App\Enums\CustomerStatus;
use App\Models\Customer;
use App\Models\Organization;
use App\Support\Phone;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

/**
 * Finds the customer a caller belongs to, or creates one (spec §15: avoid duplicates).
 *
 * Match order: normalised phone (DB-unique per business) → email → new customer.
 * An existing customer is only *enriched* (empty fields filled), never overwritten,
 * because callers often give a shortened name or a different email.
 */
class MatchOrCreateCustomer
{
    /**
     * @param  array{name?: ?string, phone?: ?string, email?: ?string, address?: ?string}  $caller
     * @return array{customer: Customer, created: bool}|null null when there is nothing to identify the caller by
     */
    public function handle(Organization $organization, array $caller, string $source = 'call', ?int $actorId = null): ?array
    {
        $phone = trim((string) ($caller['phone'] ?? ''));
        $email = Str::lower(trim((string) ($caller['email'] ?? '')));
        $name = trim((string) ($caller['name'] ?? ''));
        $e164 = Phone::normalize($phone);

        if ($e164 === null && $email === '' && $name === '') {
            return null;
        }

        $existing = $this->find($organization, $e164, $email);
        if ($existing) {
            $this->enrich($existing, $phone, $email, $name, $caller['address'] ?? null);

            return ['customer' => $existing, 'created' => false];
        }

        // Without a phone or email there is no reliable identity: don't create a nameless duplicate.
        if ($e164 === null && $email === '') {
            return null;
        }

        [$first, $last] = $this->splitName($name);

        try {
            $customer = Customer::create([
                'organization_id' => $organization->getKey(),
                'first_name' => $first,
                'last_name' => $last,
                'phone' => $phone !== '' ? $phone : null,
                'email' => $email !== '' ? $email : null,
                'address_line1' => filled($caller['address'] ?? null) ? Str::limit(trim((string) $caller['address']), 250, '') : null,
                'status' => CustomerStatus::Lead,
                'source' => in_array($source, Customer::SOURCES, true) ? $source : 'manual',
                'created_by_user_id' => $actorId,
            ]);

            return ['customer' => $customer, 'created' => true];
        } catch (UniqueConstraintViolationException) {
            // Another request created this phone number a moment ago: use that customer.
            $customer = $this->find($organization, $e164, $email);
            if (! $customer) {
                throw new \RuntimeException('Customer vanished after a unique conflict.');
            }

            return ['customer' => $customer, 'created' => false];
        }
    }

    /**
     * Read-only preview for the agent: who this caller probably is, and why (spec §15: confirm before merging).
     *
     * @return array{customer: Customer, matched_by: 'phone'|'email'}|null
     */
    public function lookup(Organization $organization, ?string $phone, ?string $email): ?array
    {
        $e164 = Phone::normalize((string) $phone);
        if ($e164 !== null && $customer = Customer::query()->forOrganization($organization)->where('phone_e164', $e164)->first()) {
            return ['customer' => $customer, 'matched_by' => 'phone'];
        }

        $email = Str::lower(trim((string) $email));
        if ($email !== '') {
            $byEmail = Customer::query()->forOrganization($organization)->where('email', $email)->limit(2)->get();
            if ($byEmail->count() === 1) {
                return ['customer' => $byEmail->first(), 'matched_by' => 'email'];
            }
        }

        return null;
    }

    /**
     * The agent confirmed this is the same person: add what we didn't know, never overwrite.
     *
     * @param  array{name?: ?string, phone?: ?string, email?: ?string, address?: ?string}  $caller
     */
    public function confirm(Customer $customer, array $caller): Customer
    {
        $this->enrich($customer, trim((string) ($caller['phone'] ?? '')), Str::lower(trim((string) ($caller['email'] ?? ''))), trim((string) ($caller['name'] ?? '')), $caller['address'] ?? null);

        return $customer;
    }

    /**
     * The agent said this is a different person, e.g. a spouse calling from the same number.
     * A number belongs to one customer, so a number already on file is left off the new record.
     *
     * @param  array{name?: ?string, phone?: ?string, email?: ?string, address?: ?string}  $caller
     */
    public function createDistinct(Organization $organization, array $caller, string $source = 'call', ?int $actorId = null): Customer
    {
        $phone = trim((string) ($caller['phone'] ?? ''));
        $e164 = Phone::normalize($phone);
        $phoneTaken = $e164 !== null && Customer::withTrashed()->forOrganization($organization)->where('phone_e164', $e164)->exists();
        [$first, $last] = $this->splitName(trim((string) ($caller['name'] ?? '')));
        $email = Str::lower(trim((string) ($caller['email'] ?? '')));

        return Customer::create([
            'organization_id' => $organization->getKey(),
            'first_name' => $first ?? 'Unknown',
            'last_name' => $last,
            'phone' => $phone !== '' && ! $phoneTaken ? $phone : null,
            'email' => $email !== '' ? $email : null,
            'address_line1' => filled($caller['address'] ?? null) ? Str::limit(trim((string) $caller['address']), 250, '') : null,
            'status' => CustomerStatus::Lead,
            'source' => in_array($source, Customer::SOURCES, true) ? $source : 'manual',
            'created_by_user_id' => $actorId,
            'notes' => $phoneTaken ? 'Shares phone number '.$phone.' with another customer.' : null,
        ]);
    }

    private function find(Organization $organization, ?string $e164, string $email): ?Customer
    {
        if ($e164 !== null) {
            $byPhone = Customer::withTrashed()->forOrganization($organization)->where('phone_e164', $e164)->first();
            if ($byPhone) {
                if ($byPhone->trashed()) {
                    $byPhone->restore();
                }

                return $byPhone;
            }
        }

        if ($email !== '') {
            // Only an unambiguous email match counts.
            $byEmail = Customer::query()->forOrganization($organization)->where('email', $email)->limit(2)->get();

            return $byEmail->count() === 1 ? $byEmail->first() : null;
        }

        return null;
    }

    private function enrich(Customer $customer, string $phone, string $email, string $name, ?string $address): void
    {
        [$first, $last] = $this->splitName($name);

        $customer->fill(array_filter([
            'first_name' => $customer->first_name ? null : $first,
            'last_name' => $customer->last_name ? null : $last,
            'phone' => ! $customer->phone && $phone !== '' && ! Customer::query()->forOrganization($customer->organization_id)
                ->where('phone_e164', Phone::normalize($phone))->exists() ? $phone : null,
            'email' => ! $customer->email && $email !== '' ? $email : null,
            'address_line1' => ! $customer->address_line1 && filled($address) ? Str::limit(trim((string) $address), 250, '') : null,
        ], fn ($value) => $value !== null));

        if ($customer->isDirty()) {
            $customer->save();
        }
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    private function splitName(string $name): array
    {
        if ($name === '') {
            return [null, null];
        }

        $parts = preg_split('/\s+/', $name, 2) ?: [$name];

        return [Str::limit($parts[0], 100, ''), isset($parts[1]) ? Str::limit($parts[1], 100, '') : null];
    }
}
