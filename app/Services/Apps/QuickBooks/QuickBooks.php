<?php

namespace App\Services\Apps\QuickBooks;

use App\Enums\CustomerStatus;
use App\Exceptions\AppAuthorizationLost;
use App\Exceptions\AppRequestFailed;
use App\Jobs\SyncToQuickBooks;
use App\Models\AppConnection;
use App\Models\AppLink;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\PaymentRequest;
use App\Models\User;
use App\Services\Billing\FeatureAccess;
use App\Support\DemoGuard;
use App\Support\Phone;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * QuickBooks Online for a business (D110). Payments customers make through SureHelp (deposits and
 * pay-by-text, D85) go into QuickBooks as payments from that customer, ready to apply to the
 * invoice. New SureHelp customers can go in too. QuickBooks customers come into SureHelp so the
 * receptionists know them by name; SureHelp never overwrites what the business typed, it only fills
 * in what's missing. Each record is matched once (AppLink), so nothing is sent twice.
 */
class QuickBooks
{
    public function __construct(private readonly QuickBooksClient $client) {}

    public function isConfigured(): bool
    {
        return filled(config('apps.quickbooks.client_id')) && filled(config('apps.quickbooks.client_secret'));
    }

    public function redirectUri(): string
    {
        return route('app.apps.quickbooks.callback');
    }

    public function authorizationUrl(string $state): string
    {
        return $this->client->authorizationUrl($state, $this->redirectUri());
    }

    public function connect(Organization $organization, string $code, string $realm, User $by): AppConnection
    {
        $tokens = $this->client->exchange($code, $this->redirectUri());
        $existing = AppConnection::withoutGlobalScopes()->where('organization_id', $organization->id)->where('provider', AppConnection::QUICKBOOKS)->first();
        if ($existing && $existing->account_id !== $realm) {
            $existing->links()->delete();   // another QuickBooks company: nothing matches any more
        }

        return AppConnection::withoutGlobalScopes()->updateOrCreate(['organization_id' => $organization->id, 'provider' => AppConnection::QUICKBOOKS], [
            'connected_by_user_id' => $by->id,
            'account_id' => $realm,
            'account_name' => $this->client->companyName($realm, $tokens['access_token']),
            'access_token' => $tokens['access_token'],
            'refresh_token' => $tokens['refresh_token'],
            'token_expires_at' => now()->addSeconds($tokens['expires_in']),
            'status' => AppConnection::ACTIVE,
            'pulled_through' => $existing?->account_id === $realm ? $existing->pulled_through : null,
            'last_error' => null,
        ]);
    }

    public function disconnect(AppConnection $connection): void
    {
        rescue(fn () => $this->client->revoke((string) ($connection->refresh_token ?: $connection->access_token)), report: false);
        $connection->delete();
    }

    /**
     * A SureHelp event (D87) that QuickBooks may want: queue it for the business's connection.
     */
    public static function event(string $event, Model $subject): void
    {
        $setting = ['payment.paid' => 'send_payments', 'customer.created' => 'send_new_customers'][$event] ?? null;
        $organizationId = (int) $subject->getAttribute('organization_id');
        if (! $setting || DemoGuard::isDemo($organizationId)) {
            return;
        }
        $connection = AppConnection::withoutGlobalScopes()->where('organization_id', $organizationId)->where('provider', AppConnection::QUICKBOOKS)
            ->where('status', AppConnection::ACTIVE)->first();
        if (! $connection || ! $connection->wants($setting) || ! self::allowed($connection)) {
            return;
        }
        SyncToQuickBooks::dispatch($connection->id, $subject instanceof PaymentRequest ? 'payment' : 'customer', (int) $subject->getKey())->afterCommit();
    }

    public static function allowed(AppConnection $connection): bool
    {
        $organization = Organization::query()->find($connection->organization_id);

        return $organization !== null && app(FeatureAccess::class)->allows($organization, 'quickbooks');
    }

    /** The QuickBooks customer for this SureHelp customer: matched by email or name, else added. */
    public function sendCustomer(AppConnection $connection, Customer $customer): string
    {
        return $this->once($connection, 'customer', $customer->id, function () use ($connection, $customer) {
            $token = $this->token($connection);
            $name = $this->displayName($customer);
            $found = $this->client->findCustomer((string) $connection->account_id, $token, $customer->email, $name);
            if ($found && $this->samePerson($customer, $found)) {
                $connection->log('customer_matched', $customer->fullName());

                return (string) $found['Id'];
            }
            $body = $this->customerBody($customer, $name);
            try {
                $created = $this->client->createCustomer((string) $connection->account_id, $token, $body);
            } catch (AppRequestFailed $e) {
                if ($e->appCode !== '6240') {
                    throw $e;
                }
                // The name is taken by someone else (or a supplier): keep them apart.
                $suffix = $customer->phone_e164 ? substr($customer->phone_e164, -4) : Str::upper(substr($customer->ulid, -4));
                $created = $this->client->createCustomer((string) $connection->account_id, $token, ['DisplayName' => Str::limit($name, 90, '').' ('.$suffix.')'] + $body);
            }
            $connection->log('customer_sent', $customer->fullName());

            return (string) $created['Id'];
        });
    }

    /** A paid SureHelp payment, as a QuickBooks payment from the customer (left to apply to an invoice). */
    public function sendPayment(AppConnection $connection, PaymentRequest $payment): ?string
    {
        if ($payment->status !== PaymentRequest::PAID || ! $payment->customer) {
            if ($payment->status === PaymentRequest::PAID) {
                $connection->log('failed', $payment->description.' ('.$payment->amount().')', 'This payment has no customer, so it wasn\'t sent.');
            }

            return null;
        }

        return $this->once($connection, 'payment', $payment->id, function () use ($connection, $payment) {
            $customerId = $this->sendCustomer($connection, $payment->customer);
            $organization = Organization::query()->findOrFail($payment->organization_id);
            $created = $this->client->createPayment((string) $connection->account_id, $this->token($connection), array_filter([
                'CustomerRef' => ['value' => $customerId],
                'TotalAmt' => round($payment->amount_cents / 100, 2),
                'TxnDate' => ($payment->paid_at ?? now())->timezone($organization->timezoneOrDefault())->toDateString(),
                'PaymentRefNum' => $payment->payment_intent_id ? substr($payment->payment_intent_id, -21) : null,
                'PrivateNote' => Str::limit(($payment->kind === 'deposit' ? 'Deposit' : 'Payment').' through SureHelp: '.$payment->description, 3900),
            ], fn ($v) => $v !== null));
            $connection->log('payment_sent', $payment->amount().' from '.$payment->customer->fullName());

            return (string) $created['Id'];
        });
    }

    /**
     * Brings in customers added or changed in QuickBooks since the last run.
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
        $limit = (int) config('apps.quickbooks.pull_limit', 2000);
        $since = $connection->pulled_through ? CarbonImmutable::instance($connection->pulled_through) : null;
        $through = $since;
        $seen = 0;
        while ($seen < $limit) {
            $size = min(500, $limit - $seen);
            $page = $this->client->customersChangedSince((string) $connection->account_id, $token, $since, $seen + 1, $size);
            foreach ($page as $row) {
                $result = $this->receive($connection, $row);
                if ($result !== null) {
                    $counts[$result]++;
                }
                $changed = isset($row['MetaData']['LastUpdatedTime']) ? CarbonImmutable::parse((string) $row['MetaData']['LastUpdatedTime']) : null;
                $through = $changed && (! $through || $changed->greaterThan($through)) ? $changed : $through;
            }
            $seen += count($page);
            if (count($page) < $size) {
                break;
            }
        }
        $connection->forceFill(['pulled_through' => $through, 'last_synced_at' => now(), 'last_error' => null])->save();
        if ($counts['added'] + $counts['matched'] > 0) {
            $connection->log('customer_received', collect([
                $counts['added'] ? $counts['added'].' new '.Str::plural('customer', $counts['added']) : null,
                $counts['matched'] ? $counts['matched'].' matched to customers you had' : null,
            ])->filter()->implode(', '));
        }

        return $counts;
    }

    /**
     * @param  array<string, mixed>  $row  a QuickBooks Customer
     * @return 'added'|'matched'|null
     */
    private function receive(AppConnection $connection, array $row): ?string
    {
        $id = (string) ($row['Id'] ?? '');
        if ($id === '' || ($row['Active'] ?? true) === false) {
            return null;
        }
        $fields = $this->localFields($row);
        $linked = AppLink::withoutGlobalScopes()->where('app_connection_id', $connection->id)->where('local_type', 'customer')->where('external_id', $id)->value('local_id');
        if ($linked) {
            $customer = Customer::withoutGlobalScopes()->find($linked);
            $customer && $this->fillBlanks($customer, $fields);

            return null;
        }
        $e164 = Phone::normalize($fields['phone'] ?? null);
        if ($e164 === null && empty($fields['email'])) {
            return null;   // nothing a receptionist could recognise them by
        }
        $customer = Customer::query()->forOrganization($connection->organization_id)
            ->where(fn ($q) => $q->when($e164, fn ($q) => $q->orWhere('phone_e164', $e164))->when($fields['email'] ?? null, fn ($q, $email) => $q->orWhere('email', Str::lower($email))))
            ->first();
        if ($customer) {
            if (AppLink::withoutGlobalScopes()->where('app_connection_id', $connection->id)->where('local_type', 'customer')->where('local_id', $customer->id)->exists()) {
                return null;   // already matched to another QuickBooks customer
            }
            $this->fillBlanks($customer, $fields);
            $this->link($connection, 'customer', $customer->id, $id);

            return 'matched';
        }
        try {
            $customer = Customer::create($fields + ['organization_id' => $connection->organization_id, 'status' => CustomerStatus::Customer, 'source' => 'quickbooks']);
        } catch (UniqueConstraintViolationException) {
            return null;
        }
        $this->link($connection, 'customer', $customer->id, $id);

        return 'added';
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, string>
     */
    private function localFields(array $row): array
    {
        $first = trim((string) ($row['GivenName'] ?? ''));
        $last = trim((string) ($row['FamilyName'] ?? ''));
        $company = trim((string) ($row['CompanyName'] ?? ''));
        if ($first === '' && $last === '' && $company === '') {
            [$first, $last] = array_pad(explode(' ', trim((string) ($row['DisplayName'] ?? '')), 2), 2, '');
        }
        $address = (array) ($row['BillAddr'] ?? []);

        return array_filter(array_map(fn ($v) => Str::limit(trim((string) $v), 190, ''), [
            'first_name' => $first, 'last_name' => $last, 'company' => $company,
            'phone' => $row['PrimaryPhone']['FreeFormNumber'] ?? $row['Mobile']['FreeFormNumber'] ?? '',
            'email' => filter_var($row['PrimaryEmailAddr']['Address'] ?? '', FILTER_VALIDATE_EMAIL) ? $row['PrimaryEmailAddr']['Address'] : '',
            'address_line1' => $address['Line1'] ?? '', 'address_line2' => $address['Line2'] ?? '', 'city' => $address['City'] ?? '',
            'state' => $address['CountrySubDivisionCode'] ?? '', 'postal_code' => $address['PostalCode'] ?? '',
        ]), fn ($v) => $v !== '');
    }

    /** @param  array<string, string>  $fields */
    private function fillBlanks(Customer $customer, array $fields): void
    {
        foreach ($fields as $key => $value) {
            if (blank($customer->getAttribute($key))) {
                $customer->setAttribute($key, $value);
            }
        }
        if ($customer->isDirty()) {
            try {
                $customer->save();
            } catch (UniqueConstraintViolationException) {
                // That phone number belongs to another of their customers: keep this one as it was.
            }
        }
    }

    /**
     * Found by email, or by name with no email or phone that says it's someone else.
     *
     * @param  array<string, mixed>  $found  a QuickBooks Customer
     */
    private function samePerson(Customer $customer, array $found): bool
    {
        $email = Str::lower((string) ($found['PrimaryEmailAddr']['Address'] ?? ''));
        if ($customer->email && $email !== '') {
            return $email === $customer->email;
        }
        $phone = Phone::normalize($found['PrimaryPhone']['FreeFormNumber'] ?? $found['Mobile']['FreeFormNumber'] ?? null);

        return ! ($customer->phone_e164 && $phone) || $phone === $customer->phone_e164;
    }

    private function displayName(Customer $customer): string
    {
        $name = trim($customer->first_name.' '.$customer->last_name) ?: ($customer->company ?: $customer->fullName());

        return Str::limit(str_replace(':', ' ', $name), 100, '');
    }

    /** @return array<string, mixed> */
    private function customerBody(Customer $customer, string $name): array
    {
        return array_filter([
            'DisplayName' => $name,
            'GivenName' => $customer->first_name ? Str::limit($customer->first_name, 100, '') : null,
            'FamilyName' => $customer->last_name ? Str::limit($customer->last_name, 100, '') : null,
            'CompanyName' => $customer->company ? Str::limit($customer->company, 100, '') : null,
            'PrimaryPhone' => $customer->phone_e164 ? ['FreeFormNumber' => $customer->displayPhone()] : null,
            'PrimaryEmailAddr' => $customer->email ? ['Address' => $customer->email] : null,
            'BillAddr' => $customer->address_line1 ? array_filter([
                'Line1' => $customer->address_line1, 'Line2' => $customer->address_line2, 'City' => $customer->city,
                'CountrySubDivisionCode' => $customer->state, 'PostalCode' => $customer->postal_code,
            ]) : null,
        ], fn ($v) => $v !== null);
    }

    /**
     * Runs $send once per record: the link remembers what it became in QuickBooks, and a lock stops
     * two workers sending the same record at the same moment.
     *
     * @param  callable(): string  $send
     */
    private function once(AppConnection $connection, string $type, int $id, callable $send): string
    {
        $find = fn () => AppLink::withoutGlobalScopes()->where('app_connection_id', $connection->id)->where('local_type', $type)->where('local_id', $id)->value('external_id');
        if ($existing = $find()) {
            return (string) $existing;
        }

        return Cache::lock('quickbooks:'.$connection->id.':'.$type.':'.$id, 60)->block(30, function () use ($find, $send, $connection, $type, $id) {
            if ($existing = $find()) {
                return (string) $existing;
            }
            $external = $send();
            $this->link($connection, $type, $id, $external);

            return $external;
        });
    }

    private function link(AppConnection $connection, string $type, int $id, string $external): void
    {
        AppLink::withoutGlobalScopes()->firstOrCreate(
            ['app_connection_id' => $connection->id, 'local_type' => $type, 'local_id' => $id],
            ['organization_id' => $connection->organization_id, 'external_id' => $external],
        );
    }

    /** A working access token, refreshed when it's about to run out. */
    private function token(AppConnection $connection): string
    {
        if ($connection->access_token && $connection->token_expires_at?->greaterThan(now()->addMinutes(2))) {
            return $connection->access_token;
        }
        try {
            if (! $connection->refresh_token) {
                throw new AppAuthorizationLost('No refresh token.');
            }
            $tokens = $this->client->refresh($connection->refresh_token);
        } catch (AppAuthorizationLost $e) {
            $this->lost($connection);

            throw $e;
        }
        $connection->forceFill([
            'access_token' => $tokens['access_token'], 'refresh_token' => $tokens['refresh_token'] ?: $connection->refresh_token,
            'token_expires_at' => now()->addSeconds($tokens['expires_in']),
        ])->save();

        return $tokens['access_token'];
    }

    public function lost(AppConnection $connection): void
    {
        $connection->forceFill(['status' => AppConnection::NEEDS_REAUTH, 'last_error' => 'QuickBooks no longer accepts our access. Connect it again.'])->save();
    }
}
