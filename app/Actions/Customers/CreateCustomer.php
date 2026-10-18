<?php

namespace App\Actions\Customers;

use App\Enums\CustomerStatus;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Phone;
use Illuminate\Validation\ValidationException;

/**
 * Adds a customer by hand, from the business portal or the app (spec §12). A phone number another
 * customer already has points to that customer instead of creating a second one (spec §15/§93).
 */
class CreateCustomer
{
    public function __construct(private readonly Audit $audit) {}

    /** @return array<string, list<mixed>> */
    public static function rules(): array
    {
        return [
            'first_name' => ['nullable', 'string', 'max:100', 'required_without_all:last_name,company'],
            'last_name' => ['nullable', 'string', 'max:100'],
            'company' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40', 'required_without:email'],
            'email' => ['nullable', 'email', 'max:255'],
        ];
    }

    /** @return array<string, string> */
    public static function messages(): array
    {
        return ['first_name.required_without_all' => 'Enter a name or a company.', 'phone.required_without' => 'Enter a phone number or an email.'];
    }

    /**
     * @param  array{first_name?: ?string, last_name?: ?string, company?: ?string, phone?: ?string, email?: ?string}  $data
     *
     * @throws ValidationException `phone` invalid; DuplicateCustomer when the number is taken
     */
    public function handle(Organization $organization, array $data, User $actor): Customer
    {
        $phone = trim((string) ($data['phone'] ?? ''));
        $e164 = Phone::normalize($phone);
        if ($phone !== '' && $e164 === null) {
            throw ValidationException::withMessages(['phone' => ['This doesn\'t look like a valid phone number.']]);
        }
        if ($e164 && $existing = Customer::withTrashed()->forOrganization($organization)->where('phone_e164', $e164)->first()) {
            throw new DuplicateCustomer($existing);
        }

        $value = fn (string $key) => filled($data[$key] ?? null) ? trim((string) $data[$key]) : null;
        $customer = Customer::create([
            'organization_id' => $organization->id,
            'first_name' => $value('first_name'),
            'last_name' => $value('last_name'),
            'company' => $value('company'),
            'phone' => $value('phone'),
            'email' => $value('email'),
            'status' => CustomerStatus::Lead,
            'source' => 'manual',
            'created_by_user_id' => $actor->id,
            'last_activity_at' => now(),
        ]);
        $this->audit->record('customer.created', $customer, new: ['name' => $customer->fullName(), 'source' => 'manual'], organization: $organization, actor: $actor);

        return $customer;
    }
}
