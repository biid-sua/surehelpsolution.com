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
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Google Business Profile: the locations the person manages. Posting needs Business Profile API
 * access granted to SureHelp's Google Cloud project (GOOGLE_BUSINESS_ENABLED).
 */
class GoogleBusinessConnector implements SocialConnector
{
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    public function key(): string
    {
        return 'google';
    }

    public function label(): string
    {
        return 'Google Business Profile';
    }

    public function networks(): array
    {
        return [SocialNetwork::GoogleBusiness];
    }

    public function isConfigured(): bool
    {
        return (bool) config('social.connectors.google.enabled')
            && filled(config('social.connectors.google.client_id')) && filled(config('social.connectors.google.client_secret'));
    }

    public function authorizationUrl(string $redirectUri, string $state): string
    {
        return 'https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query([
            'client_id' => config('social.connectors.google.client_id'),
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => implode(' ', config('social.connectors.google.scopes')),
            'access_type' => 'offline',
            'prompt' => 'consent',
            'state' => $state,
        ]);
    }

    public function exchangeCode(string $code, string $redirectUri): SocialTokens
    {
        return $this->tokens(Http::asForm()->post(self::TOKEN_URL, [
            'code' => $code,
            'client_id' => config('social.connectors.google.client_id'),
            'client_secret' => config('social.connectors.google.client_secret'),
            'redirect_uri' => $redirectUri,
            'grant_type' => 'authorization_code',
        ]));
    }

    public function discover(SocialTokens $tokens): array
    {
        $accounts = [];
        $gbpAccounts = self::ok(Http::withToken($tokens->accessToken)->acceptJson()
            ->get('https://mybusinessaccountmanagement.googleapis.com/v1/accounts'))->json('accounts', []);

        foreach ($gbpAccounts as $gbpAccount) {
            $locations = self::ok(Http::withToken($tokens->accessToken)->acceptJson()
                ->get('https://mybusinessbusinessinformation.googleapis.com/v1/'.$gbpAccount['name'].'/locations', [
                    'readMask' => 'name,title,storefrontAddress,metadata',
                    'pageSize' => 100,
                ]))->json('locations', []);

            foreach ($locations as $location) {
                $city = $location['storefrontAddress']['locality'] ?? null;
                $accounts[] = new DiscoveredAccount(
                    SocialNetwork::GoogleBusiness,
                    $gbpAccount['name'].'/'.$location['name'],   // accounts/1/locations/2: what localPosts needs
                    (string) ($location['title'] ?? 'Business location'),
                    $tokens->accessToken, $tokens->refreshToken, $tokens->expiresAt,
                    handle: $city,
                    meta: ['maps_uri' => $location['metadata']['mapsUri'] ?? null],
                );
            }
        }

        return $accounts;
    }

    public function refresh(SocialAccount $account): SocialTokens
    {
        if (! $account->refresh_token) {
            throw new SocialAuthorizationLost('Google access expired.');
        }

        $response = Http::asForm()->post(self::TOKEN_URL, [
            'refresh_token' => $account->refresh_token,
            'client_id' => config('social.connectors.google.client_id'),
            'client_secret' => config('social.connectors.google.client_secret'),
            'grant_type' => 'refresh_token',
        ]);

        if (in_array($response->json('error'), ['invalid_grant', 'unauthorized_client'], true) || $response->status() === 401) {
            throw new SocialAuthorizationLost('Google access was revoked or expired.');
        }

        $tokens = $this->tokens($response);

        return new SocialTokens($tokens->accessToken, $account->refresh_token, $tokens->expiresAt);
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

        $message = (string) ($response->json('error.message') ?? 'Google returned HTTP '.$response->status());

        if ($response->status() === 401 || ($response->status() === 403 && str_contains($message, 'PERMISSION_DENIED'))) {
            throw new SocialAuthorizationLost($message);
        }
        if ($response->status() === 429 || $response->serverError()) {
            throw new RuntimeException('Google is busy: '.$message);
        }

        throw new SocialPostRejected($message);
    }

    private function tokens(Response $response): SocialTokens
    {
        $json = self::ok($response)->json();

        return new SocialTokens(
            (string) $json['access_token'],
            $json['refresh_token'] ?? null,
            CarbonImmutable::now()->addSeconds((int) ($json['expires_in'] ?? 3600)),
        );
    }
}
