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
 * YouTube channels (D104), through a Google OAuth client with the YouTube Data API v3 turned on.
 * Without its own client ID, the Google Business Profile client is used.
 */
class YouTubeConnector implements SocialConnector
{
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    public function key(): string
    {
        return 'youtube';
    }

    public function label(): string
    {
        return 'YouTube';
    }

    public function networks(): array
    {
        return [SocialNetwork::YouTube];
    }

    public function isConfigured(): bool
    {
        return filled($this->clientId()) && filled($this->clientSecret());
    }

    public function authorizationUrl(string $redirectUri, string $state): string
    {
        return 'https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query([
            'client_id' => $this->clientId(),
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => implode(' ', config('social.connectors.youtube.scopes')),
            'access_type' => 'offline',
            'prompt' => 'consent',
            'state' => $state,
        ]);
    }

    public function exchangeCode(string $code, string $redirectUri): SocialTokens
    {
        return $this->tokens(Http::asForm()->post(self::TOKEN_URL, [
            'code' => $code, 'client_id' => $this->clientId(), 'client_secret' => $this->clientSecret(),
            'redirect_uri' => $redirectUri, 'grant_type' => 'authorization_code',
        ]));
    }

    public function discover(SocialTokens $tokens): array
    {
        $channels = GoogleBusinessConnector::ok(Http::withToken($tokens->accessToken)->acceptJson()
            ->get('https://www.googleapis.com/youtube/v3/channels', ['part' => 'snippet', 'mine' => 'true']))->json('items', []);

        return array_map(fn (array $channel) => new DiscoveredAccount(
            SocialNetwork::YouTube,
            (string) $channel['id'],
            (string) ($channel['snippet']['title'] ?? 'YouTube channel'),
            $tokens->accessToken, $tokens->refreshToken, $tokens->expiresAt,
            handle: $channel['snippet']['customUrl'] ?? null,
            avatarUrl: $channel['snippet']['thumbnails']['default']['url'] ?? null,
        ), $channels);
    }

    public function refresh(SocialAccount $account): SocialTokens
    {
        if (! $account->refresh_token) {
            throw new SocialAuthorizationLost('YouTube access expired.');
        }
        $response = Http::asForm()->post(self::TOKEN_URL, [
            'refresh_token' => $account->refresh_token, 'client_id' => $this->clientId(), 'client_secret' => $this->clientSecret(), 'grant_type' => 'refresh_token',
        ]);
        if (in_array($response->json('error'), ['invalid_grant', 'unauthorized_client'], true) || $response->status() === 401) {
            throw new SocialAuthorizationLost('YouTube access was revoked or expired.');
        }
        $tokens = $this->tokens($response);

        return new SocialTokens($tokens->accessToken, $account->refresh_token, $tokens->expiresAt);
    }

    private function clientId(): ?string
    {
        return config('social.connectors.youtube.client_id') ?: config('social.connectors.google.client_id');
    }

    private function clientSecret(): ?string
    {
        return config('social.connectors.youtube.client_id') ? config('social.connectors.youtube.client_secret') : config('social.connectors.google.client_secret');
    }

    private function tokens(Response $response): SocialTokens
    {
        $json = GoogleBusinessConnector::ok($response)->json();

        return new SocialTokens((string) $json['access_token'], $json['refresh_token'] ?? null,
            CarbonImmutable::now()->addSeconds((int) ($json['expires_in'] ?? 3600)));
    }
}
