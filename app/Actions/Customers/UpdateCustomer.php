<?php

namespace App\Actions\Customers;

use App\Enums\CustomerStatus;
use App\Models\Customer;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Phone;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Edits a customer's details from the business portal or the agent workspace (spec §12, AGT-14).
 * Consent changes keep a timestamp and who recorded them (spec §57).
 */
class UpdateCustomer
{
    public function __construct(private readonly Audit $audit) {}

    /** @return array<string, list<mixed>> validation rules for the form's fields */
    public static function rules(): array
    {
        return [
            'first_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
            'company' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:255'],
            'address_line1' => ['nullable', 'string', 'max:255'],
            'address_line2' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'max:100'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'status' => ['required', Rule::enum(CustomerStatus::class)],
            'preferred_contact' => ['nullable', Rule::in(array_keys(Customer::CONTACT_METHODS))],
            'sms_consent' => ['boolean'],
            'email_consent' => ['boolean'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /** @return array<string, mixed> the customer's current values, shaped for the form */
    public static function formFor(Customer $c): array
    {
        return [
            'first_name' => (string) $c->first_name, 'last_name' => (string) $c->last_name, 'company' => (string) $c->company,
            'phone' => (string) $c->phone, 'email' => (string) $c->email,
            'address_line1' => (string) $c->address_line1, 'address_line2' => (string) $c->address_line2,
            'city' => (string) $c->city, 'state' => (string) $c->state, 'postal_code' => (string) $c->postal_code,
            'status' => $c->status->value, 'preferred_contact' => (string) $c->preferred_contact,
            'sms_consent' => $c->sms_consent, 'email_consent' => $c->email_consent, 'notes' => (string) $c->notes,
        ];
    }

    /**
     * @param  array<string, mixed>  $data  already validated against rules()
     *
     * @throws ValidationException keyed by field name
     */
    public function handle(Customer $customer, array $data, User $actor): Customer
    {
        $e164 = Phone::normalize($data['phone'] ?? null);
        if (filled($data['phone'] ?? null) && $e164 === null) {
            throw ValidationException::withMessages(['phone' => ['This doesn\'t look like a valid phone number.']]);
        }
        if ($e164 && Customer::withTrashed()->where('organization_id', $customer->organization_id)->where('phone_e164', $e164)->whereKeyNot($customer->id)->exists()) {
            throw ValidationException::withMessages(['phone' => ['Another customer already has this number.']]);
        }

        $value = fn (string $key) => filled($data[$key] ?? null) ? trim((string) $data[$key]) : null;
        $customer->fill([
            'first_name' => $value('first_name'), 'last_name' => $value('last_name'), 'company' => $value('company'),
            'phone' => $value('phone'), 'email' => $value('email'),
            'address_line1' => $value('address_line1'), 'address_line2' => $value('address_line2'),
            'city' => $value('city'), 'state' => $value('state'), 'postal_code' => $value('postal_code'),
            'status' => $data['status'], 'preferred_contact' => $value('preferred_contact'), 'notes' => $value('notes'),
        ]);

        foreach (['sms', 'email'] as $channel) {
            $given = (bool) ($data["{$channel}_consent"] ?? false);
            if ($given !== (bool) $customer->{"{$channel}_consent"}) {
                $customer->{"{$channel}_consent"} = $given;
                $customer->{"{$channel}_consent_at"} = $given ? now() : null;
                $customer->consent_source = 'recorded by '.$actor->name;
            }
        }

        $customer->save();
        $this->audit->changes('customer.updated', $customer);

        return $customer;
    }
}
