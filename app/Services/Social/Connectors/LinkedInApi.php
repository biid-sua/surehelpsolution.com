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
 * LinkedIn REST API (versioned) over plain HTTPS, shared by the connector and the publisher.
 */
class LinkedInApi
{
    public const REST = 'https://api.linkedin.com/rest';

    public function client(string $token): PendingRequest
    {
        return Http::withToken($token)->acceptJson()->timeout(30)
            ->withHeaders([
                'LinkedIn-Version' => (string) config('social.connectors.linkedin.api_version', '202609'),
                'X-Restli-Protocol-Version' => '2.0.0',
            ])
            ->retry(2, 500, fn ($e) => $e instanceof ConnectionException, throw: false);
    }

    /**
     * @throws SocialAuthorizationLost
     * @throws SocialPostRejected
     */
    public function ok(Response $response): Response
    {
        if ($response->successful()) {
            return $response;
        }

        $message = (string) ($response->json('message') ?? 'LinkedIn returned HTTP '.$response->status());

        if ($response->status() === 401 || ($response->status() === 403 && str_contains(strtolower($message), 'revoked'))) {
            throw new SocialAuthorizationLost($message);
        }
        if ($response->status() === 429 || $response->serverError()) {
            throw new RuntimeException('LinkedIn is busy: '.$message);
        }

        throw new SocialPostRejected($message);
    }

    /**
     * Post text uses LinkedIn's "little text" format: reserved characters are escaped and
     * #words become real hashtags.
     */
    public static function commentary(string $text): string
    {
        // 1. Escape every reserved character, "#" included.
        $escaped = preg_replace('/([\\\\|{}@\[\]()<>*_~#])/u', '\\\\$1', $text) ?? $text;

        // 2. "\#word" at the start of a word is a hashtag the author meant.
        return preg_replace_callback('/(?<![\p{L}\p{N}\\\\])\\\\#([\p{L}\p{N}]+)/u', fn (array $m) => '{hashtag|\#|'.$m[1].'}', $escaped) ?? $escaped;
    }
}
