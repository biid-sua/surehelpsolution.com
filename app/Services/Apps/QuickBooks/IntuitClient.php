<?php

namespace App\Services\Apps\QuickBooks;

use App\Exceptions\AppAuthorizationLost;
use App\Exceptions\AppRequestFailed;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Intuit's QuickBooks Online API (D110). OAuth 2.0: access tokens last an hour, refresh tokens about
 * 100 days and change as they're used. https://developer.intuit.com/app/developer/qbo/docs/develop
 */
class IntuitClient implements QuickBooksClient
{
    private const AUTH_URL = 'https://appcenter.intuit.com/connect/oauth2';

    private const TOKEN_URL = 'https://oauth.platform.intuit.com/oauth2/v1/tokens/bearer';

    private const REVOKE_URL = 'https://developer.api.intuit.com/v2/oauth2/tokens/revoke';

    public const SCOPE = 'com.intuit.quickbooks.accounting';

    public function authorizationUrl(string $state, string $redirectUri): string
    {
        return self::AUTH_URL.'?'.http_build_query([
            'client_id' => config('apps.quickbooks.client_id'),
            'response_type' => 'code',
            'scope' => self::SCOPE,
            'redirect_uri' => $redirectUri,
            'state' => $state,
        ]);
    }

    public function exchange(string $code, string $redirectUri): array
    {
        return $this->token(['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => $redirectUri]);
    }

    public function refresh(string $refreshToken): array
    {
        return $this->token(['grant_type' => 'refresh_token', 'refresh_token' => $refreshToken]);
    }

    public function revoke(string $token): void
    {
        Http::withBasicAuth((string) config('apps.quickbooks.client_id'), (string) config('apps.quickbooks.client_secret'))
            ->acceptJson()->asJson()->timeout(15)->post(self::REVOKE_URL, ['token' => $token]);
    }

    public function companyName(string $realm, string $token): ?string
    {
        $name = $this->ok($this->api($token)->get($this->url($realm, 'companyinfo/'.$realm)))->json('CompanyInfo.CompanyName');

        return is_string($name) && $name !== '' ? $name : null;
    }

    public function findCustomer(string $realm, string $token, ?string $email, string $displayName): ?array
    {
        $wheres = array_filter([$email ? "PrimaryEmailAddr = '".$this->quote($email)."'" : null, "DisplayName = '".$this->quote($displayName)."'"]);
        foreach ($wheres as $where) {
            $found = $this->query($realm, $token, 'SELECT * FROM Customer WHERE '.$where.' MAXRESULTS 1');
            if ($found !== []) {
                return $found[0];
            }
        }

        return null;
    }

    public function createCustomer(string $realm, string $token, array $customer): array
    {
        return (array) $this->ok($this->api($token)->post($this->url($realm, 'customer'), $customer))->json('Customer');
    }

    public function createPayment(string $realm, string $token, array $payment): array
    {
        return (array) $this->ok($this->api($token)->post($this->url($realm, 'payment'), $payment))->json('Payment');
    }

    public function voidPayment(string $realm, string $token, string $id, string $note): void
    {
        // QuickBooks needs the current SyncToken to change a record.
        $payment = (array) $this->ok($this->api($token)->get($this->url($realm, 'payment/'.rawurlencode($id))))->json('Payment');
        $this->ok($this->api($token)->post($this->url($realm, 'payment').'&operation=update&include=void', [
            'Id' => $id, 'SyncToken' => (string) ($payment['SyncToken'] ?? '0'), 'sparse' => true, 'PrivateNote' => $note,
        ]));
    }

    public function customersChangedSince(string $realm, string $token, ?CarbonInterface $since, int $start, int $max): array
    {
        $where = $since ? " WHERE MetaData.LastUpdatedTime > '".$since->toIso8601String()."'" : '';

        return $this->query($realm, $token, 'SELECT * FROM Customer'.$where.' ORDERBY MetaData.LastUpdatedTime STARTPOSITION '.max(1, $start).' MAXRESULTS '.min(1000, max(1, $max)));
    }

    /** @return list<array<string, mixed>> */
    private function query(string $realm, string $token, string $sql): array
    {
        $rows = $this->ok($this->api($token)->get($this->url($realm, 'query'), ['query' => $sql, 'minorversion' => config('apps.quickbooks.minor_version')]))
            ->json('QueryResponse.Customer', []);

        return array_values(array_filter(is_array($rows) ? $rows : [], 'is_array'));
    }

    /** Text inside a QuickBooks query: quotes and backslashes are escaped with a backslash. */
    private function quote(string $value): string
    {
        return str_replace(['\\', "'"], ['\\\\', "\\'"], $value);
    }

    private function url(string $realm, string $path): string
    {
        $base = config('apps.quickbooks.environment') === 'sandbox' ? 'https://sandbox-quickbooks.api.intuit.com' : 'https://quickbooks.api.intuit.com';
        $minor = str_contains($path, '?') || $path === 'query' ? '' : '?minorversion='.config('apps.quickbooks.minor_version');

        return $base.'/v3/company/'.rawurlencode($realm).'/'.$path.$minor;
    }

    private function api(string $token): PendingRequest
    {
        return Http::withToken($token)->acceptJson()->asJson()->timeout(30);
    }

    /**
     * @param  array<string, string>  $form
     * @return array{access_token: string, refresh_token: string, expires_in: int}
     */
    private function token(array $form): array
    {
        $response = Http::withBasicAuth((string) config('apps.quickbooks.client_id'), (string) config('apps.quickbooks.client_secret'))
            ->acceptJson()->asForm()->timeout(20)->post(self::TOKEN_URL, $form);
        if ($response->status() === 400 && $response->json('error') === 'invalid_grant') {
            throw new AppAuthorizationLost('QuickBooks no longer accepts our access.');
        }
        $this->ok($response);

        return [
            'access_token' => (string) $response->json('access_token'),
            'refresh_token' => (string) $response->json('refresh_token'),
            'expires_in' => (int) $response->json('expires_in', 3600),
        ];
    }

    private function ok(Response $response): Response
    {
        if ($response->status() === 401) {
            throw new AppAuthorizationLost('QuickBooks no longer accepts our access.');
        }
        if (! $response->successful()) {
            $error = $response->json('Fault.Error.0');
            $message = is_array($error) ? trim(($error['Message'] ?? '').'. '.($error['Detail'] ?? ''), '. ') : 'QuickBooks answered '.$response->status();

            throw new AppRequestFailed(Str::limit($message, 300), is_array($error) ? (string) ($error['code'] ?? '') : '');
        }

        return $response;
    }
}
