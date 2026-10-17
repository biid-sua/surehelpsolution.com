<?php

namespace App\Services;

use App\Models\FcmToken;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Mobile push through Firebase Cloud Messaging HTTP v1 (D63). Google retired the old server-key
 * API in 2024; v1 signs in with a service account (OAuth2, short-lived token, cached) and sends to
 * one device at a time. Devices Firebase no longer knows are removed so they aren't tried again.
 */
class FcmService
{
    private const SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    public function isConfigured(): bool
    {
        return $this->credentials() !== null && $this->projectId() !== '';
    }

    /**
     * @param  list<string>  $tokens  the recipient's own devices only (never a shared list)
     * @param  array{title?: string, body?: string}  $notification
     * @param  array<string, mixed>  $data
     * @return array{sent: int, failed: int, removed: int, skipped?: bool, message?: string}
     */
    public function sendNotification(array $tokens, array $notification, array $data = []): array
    {
        $tokens = array_values(array_filter(array_unique($tokens)));
        if ($tokens === []) {
            return ['sent' => 0, 'failed' => 0, 'removed' => 0, 'skipped' => true, 'message' => 'No device tokens available.'];
        }
        $accessToken = $this->isConfigured() ? $this->accessToken() : null;
        if ($accessToken === null) {
            return ['sent' => 0, 'failed' => count($tokens), 'removed' => 0, 'skipped' => true, 'message' => 'Push isn\'t set up (Firebase service account missing or refused).'];
        }

        $result = ['sent' => 0, 'failed' => 0, 'removed' => 0];
        $endpoint = 'https://fcm.googleapis.com/v1/projects/'.$this->projectId().'/messages:send';
        foreach ($tokens as $token) {
            try {
                $response = Http::withToken($accessToken)->acceptJson()->timeout(10)->post($endpoint, ['message' => [
                    'token' => $token,
                    'notification' => array_filter(['title' => $notification['title'] ?? 'SureHelp', 'body' => $notification['body'] ?? null]),
                    // FCM data values must be strings.
                    'data' => array_map(fn ($v) => is_scalar($v) ? (string) $v : (string) json_encode($v), $data),
                    'android' => ['notification' => ['channel_id' => (string) config('fcm.android_channel_id'), 'sound' => (string) config('fcm.default_sound')]],
                    'apns' => ['payload' => ['aps' => ['sound' => (string) config('fcm.default_sound')]]],
                ]]);
            } catch (ConnectionException) {
                $result['failed']++;

                continue;
            }

            if ($response->successful()) {
                $result['sent']++;
            } elseif ($response->status() === 404 || $response->json('error.status') === 'NOT_FOUND'
                || collect((array) $response->json('error.details'))->contains(fn ($d) => ($d['errorCode'] ?? null) === 'UNREGISTERED')) {
                FcmToken::query()->where('token', $token)->delete(); // the app was removed or signed out
                $result['removed']++;
            } else {
                $result['failed']++;
            }
        }

        // Outcome counts only: never message content or device tokens.
        Log::info('FCM push', $result + ['recipients' => count($tokens)]);

        return $result;
    }

    /** A Google OAuth2 access token for the service account, cached until shortly before it expires. */
    private function accessToken(): ?string
    {
        $credentials = (array) $this->credentials();
        $cacheKey = 'fcm.access_token.'.md5((string) ($credentials['client_email'] ?? ''));

        $token = Cache::get($cacheKey);
        if (is_string($token)) {
            return $token;
        }

        $now = time();
        $tokenUri = (string) ($credentials['token_uri'] ?? 'https://oauth2.googleapis.com/token');
        $segments = [
            self::base64Url((string) json_encode(['alg' => 'RS256', 'typ' => 'JWT'])),
            self::base64Url((string) json_encode(['iss' => $credentials['client_email'], 'scope' => self::SCOPE, 'aud' => $tokenUri, 'iat' => $now, 'exp' => $now + 3600])),
        ];
        $signature = '';
        if (! @openssl_sign(implode('.', $segments), $signature, (string) $credentials['private_key'], OPENSSL_ALGO_SHA256)) {
            Log::warning('FCM: the service account private key could not sign.');

            return null;
        }
        $segments[] = self::base64Url($signature);

        try {
            $response = Http::asForm()->timeout(10)->post($tokenUri, ['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => implode('.', $segments)]);
        } catch (ConnectionException) {
            return null;
        }
        $token = $response->json('access_token');
        if (! $response->successful() || ! is_string($token)) {
            Log::warning('FCM: Google refused the service account.', ['status' => $response->status()]);

            return null;
        }
        Cache::put($cacheKey, $token, max(60, (int) $response->json('expires_in', 3600) - 300));

        return $token;
    }

    /** @return array<string, mixed>|null the service account JSON: a file path, the JSON itself, or base64 of it */
    private function credentials(): ?array
    {
        $raw = trim((string) config('fcm.credentials'));
        if ($raw === '') {
            return null;
        }
        $json = match (true) {
            str_starts_with($raw, '{') => $raw,
            is_file($raw) => (string) file_get_contents($raw),
            default => (string) base64_decode($raw, true),
        };
        $credentials = json_decode($json, true);

        return is_array($credentials) && isset($credentials['client_email'], $credentials['private_key']) ? $credentials : null;
    }

    private function projectId(): string
    {
        return (string) (config('fcm.project_id') ?: ($this->credentials()['project_id'] ?? ''));
    }

    private static function base64Url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
