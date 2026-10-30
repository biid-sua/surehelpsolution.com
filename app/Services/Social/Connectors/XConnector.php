<?php

namespace App\Services\Social\Connectors;

use App\Enums\SocialNetwork;
use App\Exceptions\SocialAuthorizationLost;
use App\Exceptions\SocialPostRejected;
use App\Models\SocialAccount;
use App\Services\Social\Contracts\SocialConnector;
use App\Services\Social\Data\DiscoveredAccount;
use App\Services\Social\Data\SocialTokens;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * X (D104): OAuth 2.0 with PKCE for a confidential client. The PKCE verifier is derived from the
 * one-time `state` with the app key, so nothing extra is kept between the redirect and the callback.
 * Access tokens last two hours; the refresh token changes every time it's used.
 */
class XConnector implements SocialConnector
{
    public const API = 'https://api.x.com/2';

    public function key(): string
    {
        return 'x';
    }

    public function label(): string
    {
        return 'X';
    }

    public function networks(): array
    {
        return [SocialNetwork::X];
    }

    public function isConfigured(): bool
    {
        return filled(config('social.connectors.x.client_id')) && filled(config('social.connectors.x.client_secret'));
    }

    public function authorizationUrl(string $redirectUri, string $state): string
    {
        return 'https://x.com/i/oauth2/authorize?'.http_build_query([
            'response_type' => 'code',
            'client_id' => config('social.connectors.x.client_id'),
            'redirect_uri' => $redirectUri,
            'scope' => implode(' ', config('social.connectors.x.scopes')),
            'state' => $state,
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', self::verifier($state), true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public function exchangeCode(string $code, string $redirectUri): SocialTokens
    {
        // The callback carries the same state the verifier was made from.
        return $this->tokens($this->tokenRequest()->post(self::API.'/oauth2/token', [
            'code' => $code, 'grant_type' => 'authorization_code', 'redirect_uri' => $redirectUri,
            'code_verifier' => self::verifier((string) request()->query('state')), 'client_id' => config('social.connectors.x.client_id'),
        ]));
    }

    public function discover(SocialTokens $tokens): array
    {
        $me = self::ok(Http::withToken($tokens->accessToken)->acceptJson()->get(self::API.'/users/me', ['user.fields' => 'profile_image_url,username,name']))->json('data');

        return [new DiscoveredAccount(
            SocialNetwork::X, (string) $me['id'], (string) ($me['name'] ?? $me['username']),
            $tokens->accessToken, $tokens->refreshToken, $tokens->expiresAt,
            handle: $me['username'] ?? null, avatarUrl: $me['profile_image_url'] ?? null,
        )];
    }

    public function refresh(SocialAccount $account): SocialTokens
    {
        if (! $account->refresh_token) {
            throw new SocialAuthorizationLost('X access expired.');
        }
        $response = $this->tokenRequest()->post(self::API.'/oauth2/token', [
            'refresh_token' => $account->refresh_token, 'grant_type' => 'refresh_token', 'client_id' => config('social.connectors.x.client_id'),
        ]);
        if ($response->status() === 400 || $response->status() === 401) {
            throw new SocialAuthorizationLost('X access was revoked or expired.');
        }

        return $this->tokens($response);
    }

    /** A 43–128 character verifier, the same for the redirect and the callback. */
    public static function verifier(string $state): string
    {
        return rtrim(strtr(base64_encode(hash_hmac('sha256', 'x-pkce|'.$state, (string) config('app.key'), true)), '+/', '-_'), '=');
    }

    /**
     * @throws SocialAuthorizationLost
     * @throws SocialPostRejected
     */
    public static function ok(Response $response): Response
    {
        if ($response->successful()) {
            return $response;
        }
        $message = (string) ($response->json('detail') ?? $response->json('errors.0.message') ?? $response->json('title') ?? 'X returned HTTP '.$response->status());
        if ($response->status() === 401) {
            throw new SocialAuthorizationLost($message);
        }
        if ($response->status() === 429 || $response->serverError()) {
            throw new RuntimeException('X is busy: '.$message);
        }

        throw new SocialPostRejected($response->status() === 403 && str_contains(strtolower($message), 'access') ? 'X refused the post: your X API plan may not allow posting. '.$message : $message);
    }

    private function tokenRequest(): PendingRequest
    {
        return Http::asForm()->withBasicAuth((string) config('social.connectors.x.client_id'), (string) config('social.connectors.x.client_secret'));
    }

    private function tokens(Response $response): SocialTokens
    {
        $json = self::ok($response)->json();

        return new SocialTokens((string) $json['access_token'], $json['refresh_token'] ?? null,
            CarbonImmutable::now()->addSeconds((int) ($json['expires_in'] ?? 7200)));
    }
}
