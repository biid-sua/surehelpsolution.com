<?php

namespace App\Services\Apps\Jobber;

use App\Exceptions\AppAuthorizationLost;
use App\Exceptions\AppRequestFailed;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Jobber's GraphQL API (D111). OAuth 2.0: access tokens last an hour and refresh tokens are swapped
 * for new ones as they're used. https://developer.getjobber.com/docs
 */
class GraphqlJobberClient implements JobberClient
{
    private const BASE = 'https://api.getjobber.com/api';

    private const CLIENT_FIELDS = 'id firstName lastName companyName isArchived updatedAt emails { address primary } phones { number primary } billingAddress { street1 street2 city province postalCode }';

    public function authorizationUrl(string $state, string $redirectUri): string
    {
        return self::BASE.'/oauth/authorize?'.http_build_query([
            'response_type' => 'code',
            'client_id' => config('apps.jobber.client_id'),
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

    public function disconnect(string $token): void
    {
        $this->graphql($token, 'mutation { appDisconnect { app { id } userErrors { message } } }');
    }

    public function account(string $token): array
    {
        $account = (array) $this->graphql($token, 'query { account { id name } }')['account'];

        return ['id' => (string) ($account['id'] ?? ''), 'name' => (string) ($account['name'] ?? '')];
    }

    public function searchClients(string $token, string $term): array
    {
        $data = $this->graphql($token, 'query Find($term: String!) { clients(first: 10, searchTerm: $term) { nodes { '.self::CLIENT_FIELDS.' } } }', ['term' => $term]);

        return array_map(fn (array $c) => $this->client($c), array_values(array_filter((array) ($data['clients']['nodes'] ?? []), 'is_array')));
    }

    public function createClient(string $token, array $input): string
    {
        $data = $this->graphql($token, 'mutation Add($input: ClientCreateInput!) { clientCreate(input: $input) { client { id } userErrors { message path } } }', ['input' => $input]);

        return $this->created($data['clientCreate'] ?? [], 'client');
    }

    public function createRequest(string $token, array $input): string
    {
        $data = $this->graphql($token, 'mutation Add($input: RequestCreateInput!) { requestCreate(input: $input) { request { id } userErrors { message path } } }', ['input' => $input]);

        return $this->created($data['requestCreate'] ?? [], 'request');
    }

    public function clientsChangedSince(string $token, ?CarbonInterface $since, ?string $after, int $first): array
    {
        $data = $this->graphql($token,
            'query Changed($first: Int!, $after: String, $filter: ClientFilterAttributes) { clients(first: $first, after: $after, filter: $filter) { nodes { '.self::CLIENT_FIELDS.' } pageInfo { hasNextPage endCursor } } }',
            ['first' => min(100, max(1, $first)), 'after' => $after, 'filter' => $since ? ['updatedAt' => ['after' => $since->toIso8601String()]] : null]);
        $clients = (array) ($data['clients'] ?? []);

        return [
            'clients' => array_map(fn (array $c) => $this->client($c), array_values(array_filter((array) ($clients['nodes'] ?? []), 'is_array'))),
            'next' => ($clients['pageInfo']['hasNextPage'] ?? false) ? (string) $clients['pageInfo']['endCursor'] : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $c
     * @return array<string, mixed>
     */
    private function client(array $c): array
    {
        $primary = function (array $items, string $field): ?string {
            $items = array_values(array_filter($items, 'is_array'));
            $chosen = collect($items)->firstWhere('primary', true) ?? ($items[0] ?? null);

            return is_array($chosen) && ($chosen[$field] ?? '') !== '' ? (string) $chosen[$field] : null;
        };

        return [
            'id' => (string) $c['id'], 'firstName' => $c['firstName'] ?? null, 'lastName' => $c['lastName'] ?? null, 'companyName' => $c['companyName'] ?? null,
            'email' => $primary((array) ($c['emails'] ?? []), 'address'), 'phone' => $primary((array) ($c['phones'] ?? []), 'number'),
            'address' => (array) ($c['billingAddress'] ?? []), 'archived' => (bool) ($c['isArchived'] ?? false), 'updatedAt' => $c['updatedAt'] ?? null,
        ];
    }

    /** @param  array<string, mixed>  $payload */
    private function created(array $payload, string $key): string
    {
        $errors = array_filter(array_map(fn ($e) => is_array($e) ? (string) ($e['message'] ?? '') : '', (array) ($payload['userErrors'] ?? [])));
        if ($errors !== [] || empty($payload[$key]['id'])) {
            throw new AppRequestFailed(Str::limit(implode(' ', $errors) ?: 'Jobber didn\'t add it.', 300));
        }

        return (string) $payload[$key]['id'];
    }

    /**
     * @param  array<string, mixed>  $variables
     * @return array<string, mixed>
     */
    private function graphql(string $token, string $query, array $variables = []): array
    {
        $response = Http::withToken($token)->acceptJson()->asJson()->timeout(30)
            ->withHeaders(['X-JOBBER-GRAPHQL-VERSION' => config('apps.jobber.api_version')])
            ->post(self::BASE.'/graphql', ['query' => $query, 'variables' => (object) $variables]);
        if ($response->status() === 401) {
            throw new AppAuthorizationLost('Jobber no longer accepts our access.');
        }
        $errors = (array) $response->json('errors', []);
        if (! $response->successful() || $errors !== []) {
            $first = is_array($errors[0] ?? null) ? $errors[0] : [];
            $code = (string) ($first['extensions']['code'] ?? ($response->status() === 429 ? 'THROTTLED' : ''));

            throw new AppRequestFailed(Str::limit((string) ($first['message'] ?? 'Jobber answered '.$response->status()), 300), $code);
        }

        return (array) $response->json('data', []);
    }

    /**
     * @param  array<string, string>  $form
     * @return array{access_token: string, refresh_token: string, expires_in: int}
     */
    private function token(array $form): array
    {
        $response = Http::acceptJson()->asForm()->timeout(20)->post(self::BASE.'/oauth/token', $form + [
            'client_id' => (string) config('apps.jobber.client_id'), 'client_secret' => (string) config('apps.jobber.client_secret'),
        ]);
        if ($response->status() === 401 || in_array($response->json('error'), ['invalid_grant', 'invalid_token'], true)) {
            throw new AppAuthorizationLost('Jobber no longer accepts our access.');
        }
        $this->ok($response);
        $access = (string) $response->json('access_token');

        return ['access_token' => $access, 'refresh_token' => (string) $response->json('refresh_token'), 'expires_in' => (int) ($response->json('expires_in') ?? $this->lifetime($access))];
    }

    /** Jobber's access token is a JWT; without expires_in, its own expiry says how long it lasts. */
    private function lifetime(string $jwt): int
    {
        $payload = json_decode((string) base64_decode(strtr(explode('.', $jwt)[1] ?? '', '-_', '+/')), true);
        $exp = is_array($payload) ? (int) ($payload['exp'] ?? 0) : 0;

        return $exp > time() ? $exp - time() : 3600;
    }

    private function ok(Response $response): void
    {
        if (! $response->successful()) {
            throw new AppRequestFailed('Jobber answered '.$response->status().'.');
        }
    }
}
