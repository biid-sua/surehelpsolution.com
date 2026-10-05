<?php

namespace App\Services\Social\Connectors;

use App\Exceptions\SocialAuthorizationLost;
use App\Exceptions\SocialPostRejected;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Meta Graph API over plain HTTPS, shared by the Facebook and Instagram adapters.
 * Turns Graph error codes into "reconnect", "content refused" or "try again later".
 */
class MetaGraph
{
    /** Token invalid, expired or the person removed our app / a permission. */
    private const AUTH_CODES = [102, 190, 10, 200, 298];

    /** Too many calls: always temporary. */
    private const RATE_CODES = [4, 17, 32, 613, 80001, 80002];

    public function url(string $path): string
    {
        return 'https://graph.facebook.com/'.config('social.connectors.meta.graph_version', 'v24.0').'/'.ltrim($path, '/');
    }

    public function client(): PendingRequest
    {
        return Http::acceptJson()->timeout(30)->retry(2, 500, fn ($e) => $e instanceof ConnectionException, throw: false);
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public function get(string $path, string $token, array $query = []): array
    {
        return $this->ok($this->client()->get($this->url($path), $query + ['access_token' => $token]));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function post(string $path, string $token, array $data): array
    {
        return $this->ok($this->client()->asForm()->post($this->url($path), $data + ['access_token' => $token]));
    }

    /**
     * @return array<string, mixed>
     *
     * @throws SocialAuthorizationLost
     * @throws SocialPostRejected
     */
    public function ok(Response $response): array
    {
        if ($response->successful()) {
            return (array) $response->json();
        }

        $error = (array) $response->json('error', []);
        $code = (int) ($error['code'] ?? 0);
        $message = (string) ($error['error_user_msg'] ?? $error['message'] ?? 'Meta returned HTTP '.$response->status());

        if (in_array($code, self::AUTH_CODES, true) || $response->status() === 401) {
            throw new SocialAuthorizationLost($message);
        }
        if (in_array($code, self::RATE_CODES, true) || $response->serverError() || $response->status() === 429) {
            throw new RuntimeException('Meta is busy: '.$message);
        }

        throw new SocialPostRejected($message);
    }
}
