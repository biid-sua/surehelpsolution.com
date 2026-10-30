<?php

namespace App\Services\Apps;

use App\Enums\CustomerStatus;
use App\Exceptions\AppAuthorizationLost;
use App\Models\AppConnection;
use App\Models\AppLink;
use App\Models\Customer;
use App\Support\Phone;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * What every connected app (D110) shares: which SureHelp record is which record in the app, sending
 * each one only once, and bringing the app's customers in without overwriting what the team typed.
 */
class AppRecords
{
    /**
     * Runs $send once per record: the link remembers what it became in the app, and a lock stops two
     * workers sending the same record at the same moment.
     *
     * @param  callable(): string  $send
     */
    public function once(AppConnection $connection, string $type, int $id, callable $send): string
    {
        if ($existing = $this->external($connection, $type, $id)) {
            return $existing;
        }

        return Cache::lock($connection->provider.':'.$connection->id.':'.$type.':'.$id, 60)->block(30, function () use ($send, $connection, $type, $id) {
            if ($existing = $this->external($connection, $type, $id)) {
                return $existing;
            }
            $external = $send();
            $this->link($connection, $type, $id, $external);

            return $external;
        });
    }

    public function external(AppConnection $connection, string $type, int $id): ?string
    {
        $value = AppLink::withoutGlobalScopes()->where('app_connection_id', $connection->id)->where('local_type', $type)->where('local_id', $id)->value('external_id');

        return $value === null ? null : (string) $value;
    }

    public function link(AppConnection $connection, string $type, int $id, string $external): void
    {
        AppLink::withoutGlobalScopes()->firstOrCreate(
            ['app_connection_id' => $connection->id, 'local_type' => $type, 'local_id' => $id],
            ['organization_id' => $connection->organization_id, 'external_id' => $external],
        );
    }

    /**
     * One customer from the app: fills in what a matched customer is missing, else matches by phone
     * or email, else adds them (source = the app). People with no phone or email are left out:
     * nothing a receptionist could recognise them by.
     *
     * @param  array<string, string>  $fields  customer columns
     * @return 'added'|'matched'|null
     */
    public function receiveCustomer(AppConnection $connection, string $externalId, array $fields): ?string
    {
        $linked = AppLink::withoutGlobalScopes()->where('app_connection_id', $connection->id)->where('local_type', 'customer')->where('external_id', $externalId)->value('local_id');
        if ($linked) {
            $customer = Customer::withoutGlobalScopes()->find($linked);
            $customer && $this->fillBlanks($customer, $fields);

            return null;
        }
        $e164 = Phone::normalize($fields['phone'] ?? null);
        if ($e164 === null && empty($fields['email'])) {
            return null;
        }
        $customer = Customer::query()->forOrganization($connection->organization_id)
            ->where(fn ($q) => $q->when($e164, fn ($q) => $q->orWhere('phone_e164', $e164))->when($fields['email'] ?? null, fn ($q, $email) => $q->orWhere('email', Str::lower($email))))
            ->first();
        if ($customer) {
            if ($this->external($connection, 'customer', $customer->id) !== null) {
                return null;   // already matched to another of the app's customers
            }
            $this->fillBlanks($customer, $fields);
            $this->link($connection, 'customer', $customer->id, $externalId);

            return 'matched';
        }
        try {
            $customer = Customer::create($fields + ['organization_id' => $connection->organization_id, 'status' => CustomerStatus::Customer, 'source' => $connection->provider]);
        } catch (UniqueConstraintViolationException) {
            return null;
        }
        $this->link($connection, 'customer', $customer->id, $externalId);

        return 'added';
    }

    /** @param  array<string, string>  $fields */
    public function fillBlanks(Customer $customer, array $fields): void
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

    /** Found by email, or by name with no email or phone that says it's someone else. */
    public function samePerson(Customer $customer, ?string $email, ?string $phone): bool
    {
        $email = Str::lower(trim((string) $email));
        if ($customer->email && $email !== '') {
            return $email === $customer->email;
        }
        $phone = Phone::normalize($phone);

        return ! ($customer->phone_e164 && $phone) || $phone === $customer->phone_e164;
    }

    /** "2 new customers, 1 matched to customers you had", for the history. @param array{added: int, matched: int} $counts */
    public function summary(array $counts): string
    {
        return collect([
            $counts['added'] ? $counts['added'].' new '.Str::plural('customer', $counts['added']) : null,
            $counts['matched'] ? $counts['matched'].' matched to customers you had' : null,
        ])->filter()->implode(', ');
    }

    /**
     * A working access token, refreshed when it's about to run out. When the app no longer accepts
     * us, the connection asks the owner to connect again.
     *
     * @param  callable(string): array{access_token: string, refresh_token: string, expires_in: int}  $refresh
     */
    public function token(AppConnection $connection, callable $refresh, string $appName): string
    {
        if ($connection->access_token && $connection->token_expires_at?->greaterThan(now()->addMinutes(2))) {
            return $connection->access_token;
        }
        try {
            if (! $connection->refresh_token) {
                throw new AppAuthorizationLost('No refresh token.');
            }
            $tokens = $refresh($connection->refresh_token);
        } catch (AppAuthorizationLost $e) {
            $connection->forceFill(['status' => AppConnection::NEEDS_REAUTH, 'last_error' => $appName.' no longer accepts our access. Connect it again.'])->save();

            throw $e;
        }
        $connection->forceFill([
            'access_token' => $tokens['access_token'], 'refresh_token' => $tokens['refresh_token'] ?: $connection->refresh_token,
            'token_expires_at' => now()->addSeconds($tokens['expires_in']),
        ])->save();

        return $tokens['access_token'];
    }

    /** Pieces of a name: first and last, or the whole thing as the first name. @return array{0: string, 1: string} */
    public static function splitName(string $name): array
    {
        $parts = array_pad(explode(' ', trim($name), 2), 2, '');

        return [trim($parts[0]), trim($parts[1])];
    }
}
