<?php

namespace App\Services\Social\Connectors;

use App\Enums\SocialNetwork;
use App\Exceptions\SocialAuthorizationLost;
use App\Models\SocialAccount;
use App\Services\Social\Contracts\SocialConnector;
use App\Services\Social\Data\DiscoveredAccount;
use App\Services\Social\Data\SocialTokens;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * LinkedIn: the person's own profile, plus the Company Pages they administer
 * (Community Management API, granted to SureHelp after LinkedIn's review).
 */
class LinkedInConnector implements SocialConnector
{
    private const TOKEN_URL = 'https://www.linkedin.com/oauth/v2/accessToken';

    public function __construct(private readonly LinkedInApi $api) {}

    public function key(): string
    {
        return 'linkedin';
    }

    public function label(): string
    {
        return 'LinkedIn';
    }

    public function networks(): array
    {
        return [SocialNetwork::LinkedIn];
    }

    public function isConfigured(): bool
    {
        return filled(config('social.connectors.linkedin.client_id')) && filled(config('social.connectors.linkedin.client_secret'));
    }

    public function authorizationUrl(string $redirectUri, string $state): string
    {
        return 'https://www.linkedin.com/oauth/v2/authorization?'.http_build_query([
            'response_type' => 'code',
            'client_id' => config('social.connectors.linkedin.client_id'),
            'redirect_uri' => $redirectUri,
            'state' => $state,
            'scope' => implode(' ', config('social.connectors.linkedin.scopes')),
        ]);
    }

    public function exchangeCode(string $code, string $redirectUri): SocialTokens
    {
        return $this->tokens(Http::asForm()->acceptJson()->post(self::TOKEN_URL, [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirectUri,
            'client_id' => config('social.connectors.linkedin.client_id'),
            'client_secret' => config('social.connectors.linkedin.client_secret'),
        ]));
    }

    public function discover(SocialTokens $tokens): array
    {
        $me = $this->api->ok($this->api->client($tokens->accessToken)->get('https://api.linkedin.com/v2/userinfo'))->json();
        $accounts = [new DiscoveredAccount(
            SocialNetwork::LinkedIn, 'urn:li:person:'.$me['sub'], (string) ($me['name'] ?? 'LinkedIn profile'), $tokens->accessToken,
            $tokens->refreshToken, $tokens->expiresAt, avatarUrl: $me['picture'] ?? null, meta: ['kind' => 'person'],
        )];

        // Company Pages need the Community Management API. Before SureHelp's approval this call is refused:
        // the person can still post to their own profile.
        try {
            $acls = $this->api->ok($this->api->client($tokens->accessToken)->get(LinkedInApi::REST.'/organizationAcls', [
                'q' => 'roleAssignee', 'role' => 'ADMINISTRATOR', 'state' => 'APPROVED',
            ]))->json('elements', []);
        } catch (\Throwable $e) {
            report($e);

            return $accounts;
        }

        foreach ($acls as $acl) {
            $urn = (string) ($acl['organization'] ?? '');
            $id = substr($urn, strrpos($urn, ':') + 1);
            if ($id === '') {
                continue;
            }

            $org = $this->api->client($tokens->accessToken)->get(LinkedInApi::REST.'/organizations/'.$id)->json() ?? [];
            $accounts[] = new DiscoveredAccount(
                SocialNetwork::LinkedIn, $urn, (string) ($org['localizedName'] ?? 'LinkedIn Page'), $tokens->accessToken,
                $tokens->refreshToken, $tokens->expiresAt, handle: $org['vanityName'] ?? null, meta: ['kind' => 'organization'],
            );
        }

        return $accounts;
    }

    public function refresh(SocialAccount $account): SocialTokens
    {
        if (! $account->refresh_token) {
            throw new SocialAuthorizationLost('LinkedIn access expired.');
        }

        $response = Http::asForm()->acceptJson()->post(self::TOKEN_URL, [
            'grant_type' => 'refresh_token',
            'refresh_token' => $account->refresh_token,
            'client_id' => config('social.connectors.linkedin.client_id'),
            'client_secret' => config('social.connectors.linkedin.client_secret'),
        ]);

        if ($response->clientError()) {
            throw new SocialAuthorizationLost('LinkedIn access expired.');
        }

        $tokens = $this->tokens($response);

        return new SocialTokens($tokens->accessToken, $tokens->refreshToken ?? $account->refresh_token, $tokens->expiresAt);
    }

    private function tokens(Response $response): SocialTokens
    {
        $json = $this->api->ok($response)->json();

        return new SocialTokens(
            (string) $json['access_token'],
            $json['refresh_token'] ?? null,
            CarbonImmutable::now()->addSeconds((int) ($json['expires_in'] ?? 5184000)),
        );
    }
}
