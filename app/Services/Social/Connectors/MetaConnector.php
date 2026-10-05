<?php

namespace App\Services\Social\Connectors;

use App\Enums\SocialNetwork;
use App\Exceptions\SocialAuthorizationLost;
use App\Models\SocialAccount;
use App\Services\Social\Contracts\SocialConnector;
use App\Services\Social\Data\DiscoveredAccount;
use App\Services\Social\Data\SocialTokens;
use Carbon\CarbonImmutable;

/**
 * Facebook Login: one sign-in gives the business's Facebook Pages and the Instagram professional
 * accounts linked to them. Page tokens obtained from a long-lived user token don't expire.
 */
class MetaConnector implements SocialConnector
{
    public function __construct(private readonly MetaGraph $graph) {}

    public function key(): string
    {
        return 'meta';
    }

    public function label(): string
    {
        return 'Facebook & Instagram';
    }

    public function networks(): array
    {
        return [SocialNetwork::Facebook, SocialNetwork::Instagram];
    }

    public function isConfigured(): bool
    {
        return filled(config('social.connectors.meta.client_id')) && filled(config('social.connectors.meta.client_secret'));
    }

    public function authorizationUrl(string $redirectUri, string $state): string
    {
        return 'https://www.facebook.com/'.config('social.connectors.meta.graph_version', 'v24.0').'/dialog/oauth?'.http_build_query([
            'client_id' => config('social.connectors.meta.client_id'),
            'redirect_uri' => $redirectUri,
            'state' => $state,
            'response_type' => 'code',
            'scope' => implode(',', config('social.connectors.meta.scopes')),
        ]);
    }

    public function exchangeCode(string $code, string $redirectUri): SocialTokens
    {
        $short = $this->graph->ok($this->graph->client()->get($this->graph->url('oauth/access_token'), [
            'client_id' => config('social.connectors.meta.client_id'),
            'client_secret' => config('social.connectors.meta.client_secret'),
            'redirect_uri' => $redirectUri,
            'code' => $code,
        ]));

        // Swap the 1-hour token for a 60-day one, so the Page tokens derived from it never expire.
        $long = $this->graph->ok($this->graph->client()->get($this->graph->url('oauth/access_token'), [
            'grant_type' => 'fb_exchange_token',
            'client_id' => config('social.connectors.meta.client_id'),
            'client_secret' => config('social.connectors.meta.client_secret'),
            'fb_exchange_token' => $short['access_token'],
        ]));

        return new SocialTokens((string) $long['access_token'], null, isset($long['expires_in']) ? CarbonImmutable::now()->addSeconds((int) $long['expires_in']) : null);
    }

    public function discover(SocialTokens $tokens): array
    {
        $accounts = [];
        $next = $this->graph->url('me/accounts');
        $query = [
            'fields' => 'id,name,access_token,picture{url},instagram_business_account{id,username,name,profile_picture_url}',
            'limit' => 100,
            'access_token' => $tokens->accessToken,
        ];

        for ($page = 0; $next && $page < 10; $page++) {
            $json = $this->graph->ok($this->graph->client()->get($next, $query));
            $query = []; // the "next" link already carries the parameters

            foreach ($json['data'] ?? [] as $fbPage) {
                $accounts[] = new DiscoveredAccount(
                    SocialNetwork::Facebook, (string) $fbPage['id'], (string) $fbPage['name'], (string) $fbPage['access_token'],
                    avatarUrl: $fbPage['picture']['data']['url'] ?? null,
                );

                if ($ig = $fbPage['instagram_business_account'] ?? null) {
                    // Instagram publishing uses the linked Page's token.
                    $accounts[] = new DiscoveredAccount(
                        SocialNetwork::Instagram, (string) $ig['id'], (string) ($ig['name'] ?? $ig['username'] ?? 'Instagram'), (string) $fbPage['access_token'],
                        handle: $ig['username'] ?? null, avatarUrl: $ig['profile_picture_url'] ?? null, meta: ['page_id' => (string) $fbPage['id']],
                    );
                }
            }

            $next = $json['paging']['next'] ?? null;
        }

        return $accounts;
    }

    public function refresh(SocialAccount $account): SocialTokens
    {
        // Page tokens don't expire; when Meta stops accepting one, the person has to sign in again.
        throw new SocialAuthorizationLost('Facebook access needs to be renewed.');
    }
}
