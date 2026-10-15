<?php

namespace App\Services\Telephony;

use App\Exceptions\TelephonyUnavailable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * Twilio Programmable Voice over its REST API (D60). No SDK: the three things it needs (REST
 * calls, the browser-phone access token and the webhook signature) are small and fully tested.
 */
class TwilioProvider implements TelephonyProvider
{
    private const API = 'https://api.twilio.com/2010-04-01/Accounts/';

    public function name(): string
    {
        return 'twilio';
    }

    public function isConfigured(): bool
    {
        return $this->config('account_sid') !== '' && $this->config('auth_token') !== ''
            && $this->config('api_key') !== '' && $this->config('api_secret') !== '';
    }

    public function usesBrowserSdk(): bool
    {
        return true;
    }

    /**
     * A Voice access token: a JWT signed with the API key secret, allowed to receive calls as
     * `$identity` (and to place calls through the TwiML App when one is set).
     */
    public function accessToken(string $identity, int $ttlSeconds = 3600): string
    {
        $now = time();
        $voice = ['incoming' => ['allow' => true]];
        if ($this->config('twiml_app_sid') !== '') {
            $voice['outgoing'] = ['application_sid' => $this->config('twiml_app_sid')];
        }
        $header = ['typ' => 'JWT', 'alg' => 'HS256', 'cty' => 'twilio-fpa;v=1'];
        $payload = [
            'jti' => $this->config('api_key').'-'.$now,
            'iss' => $this->config('api_key'),
            'sub' => $this->config('account_sid'),
            'iat' => $now,
            'nbf' => $now,
            'exp' => $now + $ttlSeconds,
            'grants' => ['identity' => $identity, 'voice' => $voice],
        ];
        $segments = [self::base64Url((string) json_encode($header)), self::base64Url((string) json_encode($payload))];
        $segments[] = self::base64Url(hash_hmac('sha256', implode('.', $segments), $this->config('api_secret'), true));

        return implode('.', $segments);
    }

    /**
     * X-Twilio-Signature: base64(HMAC-SHA1(auth token, public URL + each POST field name and value, sorted by name)).
     */
    public function isGenuine(Request $request): bool
    {
        $token = $this->config('auth_token');
        $signature = (string) $request->header('X-Twilio-Signature');
        if ($token === '' || $signature === '') {
            return false;
        }

        return hash_equals(self::signature($token, $this->publicUrl($request), $request->request->all()), $signature);
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    public static function signature(string $authToken, string $url, array $fields): string
    {
        ksort($fields, SORT_STRING);
        $data = $url;
        foreach ($fields as $name => $value) {
            $data .= $name.(is_array($value) ? implode('', $value) : (string) $value);
        }

        return base64_encode(hash_hmac('sha1', $data, $authToken, true));
    }

    public function availableNumbers(string $areaCode): array
    {
        $country = strtoupper($this->config('country') ?: 'US');
        $response = $this->send(fn (PendingRequest $http) => $http->get("AvailablePhoneNumbers/{$country}/Local.json", [
            'AreaCode' => $areaCode, 'VoiceEnabled' => 'true', 'PageSize' => 12,
        ]));

        return array_values(array_map(fn (array $n) => [
            'number' => (string) $n['phone_number'],
            'locality' => $n['locality'] ?? null,
            'region' => $n['region'] ?? null,
        ], (array) $response->json('available_phone_numbers', [])));
    }

    public function buyNumber(string $number, array $urls): array
    {
        $response = $this->send(fn (PendingRequest $http) => $http->asForm()->post('IncomingPhoneNumbers.json', ['PhoneNumber' => $number] + $this->routing($urls)));

        return ['sid' => (string) $response->json('sid'), 'number' => (string) $response->json('phone_number')];
    }

    public function connectNumber(string $sid, array $urls): string
    {
        $response = $this->send(fn (PendingRequest $http) => $http->asForm()->post("IncomingPhoneNumbers/{$sid}.json", $this->routing($urls)));

        return (string) $response->json('phone_number');
    }

    public function releaseNumber(string $sid): void
    {
        $this->send(fn (PendingRequest $http) => $http->delete("IncomingPhoneNumbers/{$sid}.json"), allowMissing: true);
    }

    public function redirectCall(string $callSid, string $url): void
    {
        $this->send(fn (PendingRequest $http) => $http->asForm()->post("Calls/{$callSid}.json", ['Url' => $url, 'Method' => 'POST']));
    }

    public function recordingAudio(string $recordingSid): ?string
    {
        try {
            $response = $this->http()->timeout(30)->get("Recordings/{$recordingSid}.mp3");
        } catch (ConnectionException) {
            return null;
        }

        return $response->successful() ? $response->body() : null;
    }

    /**
     * @param  array{voice: string, status: string}  $urls
     * @return array<string, string>
     */
    private function routing(array $urls): array
    {
        return ['VoiceUrl' => $urls['voice'], 'VoiceMethod' => 'POST', 'StatusCallback' => $urls['status'], 'StatusCallbackMethod' => 'POST'];
    }

    /**
     * @param  callable(PendingRequest): Response  $call
     */
    private function send(callable $call, bool $allowMissing = false): Response
    {
        if (! $this->isConfigured()) {
            throw new TelephonyUnavailable('Twilio isn\'t set up yet. Add the Twilio keys to the server settings first.');
        }

        try {
            $response = $call($this->http());
        } catch (ConnectionException $e) {
            report($e);
            throw new TelephonyUnavailable('Twilio couldn\'t be reached. Try again in a minute.');
        }

        if ($response->successful() || ($allowMissing && $response->status() === 404)) {
            return $response;
        }

        throw new TelephonyUnavailable('Twilio said: '.($response->json('message') ?: 'error '.$response->status()));
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl(self::API.$this->config('account_sid').'/')
            ->withBasicAuth($this->config('account_sid'), $this->config('auth_token'))
            ->acceptJson()->timeout(15);
    }

    private function publicUrl(Request $request): string
    {
        $base = rtrim($this->config('webhook_url'), '/');

        return ($base !== '' ? $base : $request->getSchemeAndHttpHost()).$request->getRequestUri();
    }

    private function config(string $key): string
    {
        return (string) config('telephony.twilio.'.$key, '');
    }

    private static function base64Url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
