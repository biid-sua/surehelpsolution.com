<?php

namespace App\Services\Billing\Stripe;

use App\Exceptions\BillingUnavailable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Stripe's REST API (D64), without the SDK: a handful of form-encoded calls and a webhook
 * signature check, all tested. Requests that create money movements carry an idempotency key.
 */
class StripeApi
{
    private const BASE = 'https://api.stripe.com/v1/';

    public function isConfigured(): bool
    {
        return (string) config('billing.stripe.secret') !== '' && (string) config('billing.stripe.webhook_secret') !== '';
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     *
     * @throws BillingUnavailable
     */
    public function post(string $path, array $data = [], ?string $idempotencyKey = null): array
    {
        return $this->send(fn () => Http::withToken((string) config('billing.stripe.secret'))->asForm()
            ->withHeaders(array_filter(['Idempotency-Key' => $idempotencyKey]))->timeout(20)->post(self::BASE.$path, $data));
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     *
     * @throws BillingUnavailable
     */
    public function get(string $path, array $query = []): array
    {
        return $this->send(fn () => Http::withToken((string) config('billing.stripe.secret'))->timeout(20)->get(self::BASE.$path, $query));
    }

    /**
     * Stripe-Signature: "t=…,v1=…": HMAC-SHA256 of "t.payload" with the endpoint's signing secret, within 5 minutes.
     */
    public function isGenuine(string $payload, string $header, int $toleranceSeconds = 300): bool
    {
        $secret = (string) config('billing.stripe.webhook_secret');
        $parts = [];
        foreach (explode(',', $header) as $piece) {
            [$k, $v] = array_pad(explode('=', trim($piece), 2), 2, '');
            $parts[$k][] = $v;
        }
        $timestamp = (int) ($parts['t'][0] ?? 0);
        if ($secret === '' || $timestamp === 0 || abs(time() - $timestamp) > $toleranceSeconds) {
            return false;
        }
        $expected = hash_hmac('sha256', $timestamp.'.'.$payload, $secret);
        foreach ($parts['v1'] ?? [] as $signature) {
            if (hash_equals($expected, $signature)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  callable(): Response  $call
     * @return array<string, mixed>
     */
    private function send(callable $call): array
    {
        if ((string) config('billing.stripe.secret') === '') {
            throw new BillingUnavailable('Card payments aren\'t set up yet.');
        }
        try {
            $response = $call();
        } catch (ConnectionException $e) {
            report($e);
            throw new BillingUnavailable('The card payment service couldn\'t be reached. Try again in a minute.');
        }
        if (! $response->successful()) {
            $error = (array) $response->json('error');
            throw new BillingUnavailable((string) ($error['message'] ?? 'The card payment service refused the request.'), isset($error['decline_code']) ? (string) $error['decline_code'] : (isset($error['code']) ? (string) $error['code'] : null));
        }

        return (array) $response->json();
    }
}
