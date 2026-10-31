<?php

namespace App\Services\Apps\Jobber;

use App\Jobs\SyncToJobber;
use App\Models\AppConnection;
use App\Models\Appointment;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\User;
use App\Services\Apps\AppRecords;
use App\Services\Billing\FeatureAccess;
use App\Support\DemoGuard;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Jobber for a business (D111). Bookings SureHelp makes go into Jobber as new requests from the
 * client, so the office schedules the work where they already do. Jobber clients come into SureHelp
 * so the receptionists know them by name; SureHelp only fills in what's missing. Each record is
 * matched once (AppLink), so nothing is sent twice, and nothing in Jobber is changed or deleted.
 */
class Jobber
{
    /** Bookings that came from elsewhere, not made by SureHelp: never sent. */
    private const NOT_SENT = ['calendar', 'import', 'jobber'];

    public function __construct(private readonly JobberClient $client, private readonly AppRecords $records) {}

    public function isConfigured(): bool
    {
        return filled(config('apps.jobber.client_id')) && filled(config('apps.jobber.client_secret'));
    }

    public function redirectUri(): string
    {
        return route('app.apps.jobber.callback');
    }

    public function authorizationUrl(string $state): string
    {
        return $this->client->authorizationUrl($state, $this->redirectUri());
    }

    public function connect(Organization $organization, string $code, User $by): AppConnection
    {
        $tokens = $this->client->exchange($code, $this->redirectUri());
        $account = $this->client->account($tokens['access_token']);
        $existing = AppConnection::withoutGlobalScopes()->where('organization_id', $organization->id)->where('provider', AppConnection::JOBBER)->first();
        if ($existing && $existing->account_id !== $account['id']) {
            $existing->links()->delete();   // another Jobber account: nothing matches any more
        }

        return AppConnection::withoutGlobalScopes()->updateOrCreate(['organization_id' => $organization->id, 'provider' => AppConnection::JOBBER], [
            'connected_by_user_id' => $by->id,
            'account_id' => $account['id'],
            'account_name' => $account['name'] ?: null,
            'access_token' => $tokens['access_token'],
            'refresh_token' => $tokens['refresh_token'],
            'token_expires_at' => now()->addSeconds($tokens['expires_in']),
            'status' => AppConnection::ACTIVE,
            'pulled_through' => $existing?->account_id === $account['id'] ? $existing->pulled_through : null,
            'last_error' => null,
        ]);
    }

    public function disconnect(AppConnection $connection): void
    {
        rescue(fn () => $this->client->disconnect($this->token($connection)), report: false);
        $connection->delete();
    }

    /** A SureHelp event (D87) that Jobber may want: queue it for the business's connection. */
    public static function event(string $event, Model $subject): void
    {
        $setting = ['appointment.booked' => 'send_bookings', 'appointment.rescheduled' => 'send_bookings', 'appointment.cancelled' => 'send_bookings',
            'customer.created' => 'send_new_customers'][$event] ?? null;
        $organizationId = (int) $subject->getAttribute('organization_id');
        if (! $setting || ($subject instanceof Appointment && in_array($subject->source, self::NOT_SENT, true)) || DemoGuard::isDemo($organizationId)) {
            return;
        }
        $connection = AppConnection::withoutGlobalScopes()->where('organization_id', $organizationId)->where('provider', AppConnection::JOBBER)
            ->where('status', AppConnection::ACTIVE)->first();
        if (! $connection || ! $connection->wants($setting) || ! self::allowed($connection)) {
            return;
        }
        $type = match ($event) {
            'appointment.rescheduled' => 'appointment_moved',
            'appointment.cancelled' => 'appointment_cancelled',
            default => $subject instanceof Appointment ? 'appointment' : 'customer',
        };
        SyncToJobber::dispatch($connection->id, $type, (int) $subject->getKey())->afterCommit();
    }

    public static function allowed(AppConnection $connection): bool
    {
        $organization = Organization::query()->find($connection->organization_id);

        return $organization !== null && app(FeatureAccess::class)->allows($organization, 'jobber');
    }

    /** The Jobber client for this SureHelp customer: found by email, phone or name, else added. */
    public function sendCustomer(AppConnection $connection, Customer $customer): string
    {
        return $this->records->once($connection, 'customer', $customer->id, function () use ($connection, $customer) {
            $token = $this->token($connection);
            $name = trim($customer->first_name.' '.$customer->last_name);
            foreach (array_filter([$customer->email, $customer->phone_e164 ? $customer->displayPhone() : null, $name ?: null]) as $term) {
                foreach ($this->client->searchClients($token, (string) $term) as $found) {
                    if (! $found['archived'] && $this->records->samePerson($customer, $found['email'], $found['phone'])) {
                        $connection->log('customer_matched', $customer->fullName());

                        return (string) $found['id'];
                    }
                }
            }
            $id = $this->client->createClient($token, $this->clientInput($customer));
            $connection->log('customer_sent', $customer->fullName());

            return $id;
        });
    }

    /** A booking SureHelp made, as a new request from the client in Jobber. */
    public function sendBooking(AppConnection $connection, Appointment $appointment): ?string
    {
        if (! $appointment->customer) {
            $connection->log('failed', $appointment->title ?: 'A booking', 'This booking has no customer, so it wasn\'t sent.');

            return null;
        }

        return $this->records->once($connection, 'appointment', $appointment->id, function () use ($connection, $appointment) {
            $clientId = $this->sendCustomer($connection, $appointment->customer);
            $what = $appointment->service?->name ?: ($appointment->title ?: 'Booking');
            $when = $appointment->localStart()->format('D, M j, g:i A');
            $id = $this->client->createRequest($this->token($connection), [
                'clientId' => $clientId,
                'title' => Str::limit($what.' · '.$when.' (booked by SureHelp)', 250, ''),
            ]);
            $connection->log('booking_sent', $what.', '.$when.', for '.$appointment->customer->fullName());

            return $id;
        });
    }

    /** A booking sent as a request was moved or cancelled: the office changes the request in Jobber. */
    public function bookingChanged(AppConnection $connection, Appointment $appointment, bool $cancelled): void
    {
        $this->records->bookingChanged($connection, $appointment, $cancelled, 'Jobber');
    }

    /**
     * Brings in clients added or changed in Jobber since the last run.
     *
     * @return array{added: int, matched: int}
     */
    public function pull(AppConnection $connection): array
    {
        $counts = ['added' => 0, 'matched' => 0];
        if (! $connection->isActive() || ! $connection->wants('receive_customers')) {
            return $counts;
        }
        $token = $this->token($connection);
        $limit = (int) config('apps.jobber.pull_limit', 2000);
        $since = $connection->pulled_through ? CarbonImmutable::instance($connection->pulled_through) : null;
        $through = $since;
        $after = null;
        $seen = 0;
        do {
            $page = $this->client->clientsChangedSince($token, $since, $after, min(100, $limit - $seen));
            foreach ($page['clients'] as $client) {
                $result = $client['archived'] ? null : $this->records->receiveCustomer($connection, (string) $client['id'], $this->localFields($client));
                if ($result !== null) {
                    $counts[$result]++;
                }
                $changed = $client['updatedAt'] ? CarbonImmutable::parse((string) $client['updatedAt']) : null;
                $through = $changed && (! $through || $changed->greaterThan($through)) ? $changed : $through;
            }
            $seen += count($page['clients']);
            $after = $page['next'];
        } while ($after !== null && $seen < $limit);

        $connection->forceFill(['pulled_through' => $through, 'last_synced_at' => now(), 'last_error' => null])->save();
        if ($counts['added'] + $counts['matched'] > 0) {
            $connection->log('customer_received', $this->records->summary($counts));
        }

        return $counts;
    }

    /**
     * @param  array<string, mixed>  $client  the plain client shape
     * @return array<string, string>
     */
    private function localFields(array $client): array
    {
        $address = (array) $client['address'];

        return array_filter(array_map(fn ($v) => Str::limit(trim((string) $v), 190, ''), [
            'first_name' => $client['firstName'] ?? '', 'last_name' => $client['lastName'] ?? '', 'company' => $client['companyName'] ?? '',
            'phone' => $client['phone'] ?? '',
            'email' => filter_var($client['email'] ?? '', FILTER_VALIDATE_EMAIL) ? $client['email'] : '',
            'address_line1' => $address['street1'] ?? '', 'address_line2' => $address['street2'] ?? '', 'city' => $address['city'] ?? '',
            'state' => $address['province'] ?? '', 'postal_code' => $address['postalCode'] ?? '',
        ]), fn ($v) => $v !== '');
    }

    /** @return array<string, mixed> ClientCreateInput */
    private function clientInput(Customer $customer): array
    {
        // Jobber needs a name: without one, the company or the phone number stands in.
        [$first, $last] = $customer->first_name || $customer->last_name || $customer->company ? [$customer->first_name, $customer->last_name] : [null, $customer->fullName()];

        return array_filter([
            'firstName' => $first ? Str::limit($first, 100, '') : null,
            'lastName' => $last ? Str::limit($last, 100, '') : null,
            'companyName' => $customer->company ? Str::limit($customer->company, 100, '') : null,
            'emails' => $customer->email ? [['description' => 'MAIN', 'primary' => true, 'address' => $customer->email]] : null,
            'phones' => $customer->phone_e164 ? [['description' => 'MAIN', 'primary' => true, 'number' => $customer->displayPhone()]] : null,
            'billingAddress' => $customer->address_line1 ? array_filter([
                'street1' => $customer->address_line1, 'street2' => $customer->address_line2, 'city' => $customer->city,
                'province' => $customer->state, 'postalCode' => $customer->postal_code,
            ]) : null,
        ], fn ($v) => $v !== null);
    }

    private function token(AppConnection $connection): string
    {
        return $this->records->token($connection, fn (string $refresh) => $this->client->refresh($refresh), 'Jobber');
    }
}
