<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Services\Billing\Stripe\CardPayments;
use App\Services\Billing\Stripe\StripeApi;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

/**
 * Stripe tells us about card payments here (D64). Every event is checked against the endpoint's
 * signing secret; repeats are ignored (by event id, and again by payment reference).
 */
class StripeWebhookController extends Controller
{
    public function __invoke(Request $request, StripeApi $stripe, CardPayments $payments): Response
    {
        $payload = (string) $request->getContent();
        abort_unless($stripe->isGenuine($payload, (string) $request->header('Stripe-Signature')), 400);

        $event = json_decode($payload, true);
        if (! is_array($event) || ! isset($event['id'], $event['type'])) {
            return response()->noContent();
        }
        if (! Cache::add('stripe-event:'.$event['id'], true, now()->addDays(3))) {
            return response()->noContent();
        }

        $object = (array) ($event['data']['object'] ?? []);
        match ($event['type']) {
            'checkout.session.completed', 'checkout.session.async_payment_succeeded' => $payments->checkoutCompleted($object),
            'payment_intent.succeeded' => $payments->intentSucceeded($object),
            'payment_intent.payment_failed' => $payments->intentFailed($object),
            default => null,
        };

        return response()->noContent();
    }
}
