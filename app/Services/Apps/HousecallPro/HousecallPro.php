<?php

namespace App\Services\Apps\HousecallPro;

use App\Enums\ServicePriceType;
use App\Exceptions\AppAuthorizationLost;
use App\Jobs\SyncToHousecallPro;
use App\Models\AppConnection;
use App\Models\Appointment;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\User;
use App\Services\Apps\AppRecords;
use App\Services\Billing\FeatureAccess;
use App\Support\DemoGuard;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Housecall Pro for a business (D112). The business pastes its own API key, so there is no app to
 * register. Bookings SureHelp makes go in as scheduled jobs at the customer's address, so they land
 * on the business's Housecall Pro schedule. Housecall Pro customers come into SureHelp so the
 * receptionists know them by name; SureHelp only fills in what's missing. Each record is matched once,
 * and nothing in Housecall Pro is changed or deleted.
 */
class HousecallPro
{
    private const NOT_SENT = ['calendar', 'import', 'housecall_pro'];

    public function __construct(private readonly HousecallProClient $client, private readonly AppRecords $records) {}

    /** Checks the key with Housecall Pro, then saves the connection. Throws AppAuthorizationLost for a key that doesn't work. */
    public function connect(Organization $organization, string $key, User $by): AppConnection
    {
        $company = $this->client->company($key);
        $existing = AppConnection::withoutGlobalScopes()->where('organization_id', $organization->id)->where('provider', AppConnection::HOUSECALL_PRO)->first();
        if ($existing && $existing->account_id !== $company['id']) {
            $existing->links()->delete();   // another Housecall Pro company: nothing matches any more
        }

        return AppConnection::withoutGlobalScopes()->updateOrCreate(['organization_id' => $organization->id, 'provider' => AppConnection::HOUSECALL_PRO], [
            'connected_by_user_id' => $by->id,
            'account_id' => $company['id'],
            'account_name' => $company['name'] ?: null,
            'access_token' => $key,
            'refresh_token' => null,
            'token_expires_at' => null,
            'status' => AppConnection::ACTIVE,
            'last_error' => null,
        ]);
    }

    public function disconnect(AppConnection $connection): void
    {
        $connection->delete();   // the key stays the business's to delete in Housecall Pro
    }

    /** A SureHelp event (D87) that Housecall Pro may want: queue it for the business's connection. */
    public static function event(string $event, Model $subject): void
    {
        $setting = ['appointment.booked' => 'send_bookings', 'customer.created' => 'send_new_customers'][$event] ?? null;
        $organizationId = (int) $subject->getAttribute('organization_id');
        if (! $setting || ($subject instanceof Appointment && in_array($subject->source, self::NOT_SENT, true)) || DemoGuard::isDemo($organizationId)) {
            return;
        }
        $connection = AppConnection::withoutGlobalScopes()->where('organization_id', $organizationId)->where('provider', AppConnection::HOUSECALL_PRO)
            ->where('status', AppConnection::ACTIVE)->first();
        if (! $connection || ! $connection->wants($setting) || ! self::allowed($connection)) {
            return;
        }
        SyncToHousecallPro::dispatch($connection->id, $subject instanceof Appointment ? 'appointment' : 'customer', (int) $subject->getKey())->afterCommit();
    }

    public static function allowed(AppConnection $connection): bool
    {
        $organization = Organization::query()->find($connection->organization_id);

        return $organization !== null && app(FeatureAccess::class)->allows($organization, 'housecall_pro');
    }

    /** The Housecall Pro customer for this SureHelp customer: found by email, phone or name, else added. */
    public function sendCustomer(AppConnection $connection, Customer $customer): string
    {
        return $this->records->once($connection, 'customer', $customer->id, fn () => $this->guarded($connection, function (string $key) use ($connection, $customer) {
            $name = trim($customer->first_name.' '.$customer->last_name);
            foreach (array_filter([$customer->email, $customer->phone_e164, $name ?: null]) as $term) {
                foreach ($this->client->searchCustomers($key, (string) $term) as $found) {
                    if ($this->records->samePerson($customer, $found['email'], $found['phone'])) {
                        $connection->log('customer_matched', $customer->fullName());

                        return (string) $found['id'];
                    }
                }
            }
            $created = $this->client->createCustomer($key, $this->customerInput($customer));
            $connection->log('customer_sent', $customer->fullName());

            return (string) $created['id'];
        }));
    }

    /** A booking SureHelp made, as a scheduled job at the customer's address. */
    public function sendBooking(AppConnection $connection, Appointment $appointment): ?string
    {
        $customer = $appointment->customer;
        $address = $this->addressFor($appointment);
        if (! $customer || $address === null) {
            $connection->log('failed', $appointment->title ?: 'A booking', $customer
                ? 'Housecall Pro jobs need an address, and this booking has none. Add it in Housecall Pro.'
                : 'This booking has no customer, so it wasn\'t sent.');

            return null;
        }

        return $this->records->once($connection, 'appointment', $appointment->id, function () use ($connection, $appointment, $customer, $address) {
            $customerId = $this->sendCustomer($connection, $customer);

            return $this->guarded($connection, function (string $key) use ($connection, $appointment, $customer, $customerId, $address) {
                $known = collect($this->client->customer($key, $customerId)['addresses'] ?? [])
                    ->first(fn (array $a) => Str::lower(trim((string) $a['street'])) === Str::lower($address['street']));
                $addressId = $known ? (string) $known['id'] : $this->client->addAddress($key, $customerId, $address);
                $service = $appointment->service;
                $price = $service && in_array($service->price_type, [ServicePriceType::Fixed, ServicePriceType::StartingFrom], true) ? (int) $service->price_cents : 0;
                $id = $this->client->createJob($key, array_filter([
                    'customer_id' => $customerId,
                    'address_id' => $addressId,
                    'schedule' => ['scheduled_start' => $appointment->starts_at->utc()->toIso8601ZuluString(), 'scheduled_end' => $appointment->ends_at->utc()->toIso8601ZuluString(), 'arrival_window' => 0],
                    'line_items' => $service ? [['name' => Str::limit($service->name, 120, ''), 'quantity' => 1, 'unit_price' => $price]] : null,
                    'tags' => ['SureHelp'],
                    'lead_source' => 'SureHelp',
                ], fn ($v) => $v !== null));
                $what = $service?->name ?: ($appointment->title ?: 'Booking');
                $connection->log('job_sent', $what.', '.$appointment->localStart()->format('D, M j, g:i A').', for '.$customer->fullName());

                return $id;
            });
        });
    }

    /**
     * Brings in Housecall Pro customers, newest first. Customers already matched only get blanks filled.
     *
     * @return array{added: int, matched: int}
     */
    public function pull(AppConnection $connection): array
    {
        $counts = ['added' => 0, 'matched' => 0];
        if (! $connection->isActive() || ! $connection->wants('receive_customers')) {
            return $counts;
        }
        $this->guarded($connection, function (string $key) use ($connection, &$counts) {
            $limit = (int) config('apps.housecall_pro.pull_limit', 2000);
            $page = 1;
            do {
                $batch = $this->client->customers($key, $page++, 100);
                foreach ($batch['customers'] as $customer) {
                    $result = $this->records->receiveCustomer($connection, (string) $customer['id'], $this->localFields($customer));
                    if ($result !== null) {
                        $counts[$result]++;
                    }
                }
            } while ($batch['more'] && ($page - 1) * 100 < $limit);

            return '';
        });
        $connection->forceFill(['last_synced_at' => now(), 'last_error' => null])->save();
        if ($counts['added'] + $counts['matched'] > 0) {
            $connection->log('customer_received', $this->records->summary($counts));
        }

        return $counts;
    }

    /** @return array{street: string, street_line_2?: string, city?: string, state?: string, zip?: string}|null */
    private function addressFor(Appointment $appointment): ?array
    {
        $from = $appointment->address_line1 ? $appointment : $appointment->customer;
        if (! $from || blank($from->address_line1)) {
            return null;
        }

        return array_filter([
            'street' => trim((string) $from->address_line1), 'street_line_2' => $from->address_line2, 'city' => $from->city,
            'state' => $from->state, 'zip' => $from->postal_code,
        ], fn ($v) => filled($v));
    }

    /**
     * @param  array<string, mixed>  $customer  the plain customer shape
     * @return array<string, string>
     */
    private function localFields(array $customer): array
    {
        $address = (array) ($customer['addresses'][0] ?? []);

        return array_filter(array_map(fn ($v) => Str::limit(trim((string) $v), 190, ''), [
            'first_name' => $customer['first_name'] ?? '', 'last_name' => $customer['last_name'] ?? '', 'company' => $customer['company'] ?? '',
            'phone' => $customer['phone'] ?? '',
            'email' => filter_var($customer['email'] ?? '', FILTER_VALIDATE_EMAIL) ? $customer['email'] : '',
            'address_line1' => $address['street'] ?? '', 'address_line2' => $address['street_line_2'] ?? '', 'city' => $address['city'] ?? '',
            'state' => $address['state'] ?? '', 'postal_code' => $address['zip'] ?? '',
        ]), fn ($v) => $v !== '');
    }

    /** @return array<string, mixed> */
    private function customerInput(Customer $customer): array
    {
        // Housecall Pro needs a name: without one, the company or the phone number stands in.
        $named = $customer->first_name || $customer->last_name || $customer->company;

        return array_filter([
            'first_name' => $customer->first_name ? Str::limit($customer->first_name, 100, '') : null,
            'last_name' => $named ? ($customer->last_name ? Str::limit($customer->last_name, 100, '') : null) : $customer->fullName(),
            'company' => $customer->company ? Str::limit($customer->company, 100, '') : null,
            'email' => $customer->email,
            'mobile_number' => $customer->phone_e164,
            'notifications_enabled' => false,   // SureHelp's own consent rules decide who gets texts
            'lead_source' => 'SureHelp',
            'addresses' => $customer->address_line1 ? [array_filter([
                'street' => $customer->address_line1, 'street_line_2' => $customer->address_line2, 'city' => $customer->city,
                'state' => $customer->state, 'zip' => $customer->postal_code,
            ])] : null,
        ], fn ($v) => $v !== null);
    }

    /**
     * Runs a call with the business's key. A key Housecall Pro refuses asks the owner to connect again.
     *
     * @param  callable(string): string  $call
     */
    private function guarded(AppConnection $connection, callable $call): string
    {
        try {
            return $call((string) $connection->access_token);
        } catch (AppAuthorizationLost $e) {
            $connection->forceFill(['status' => AppConnection::NEEDS_REAUTH, 'last_error' => 'Housecall Pro no longer accepts the API key. Paste a new one.'])->save();

            throw $e;
        }
    }
}
