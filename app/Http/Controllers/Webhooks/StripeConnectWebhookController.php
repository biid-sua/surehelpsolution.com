<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\PaymentAccount;
use App\Services\Billing\Stripe\StripeApi;
use App\Services\Payments\CustomerPayments;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

/**
 * Events from businesses' own Stripe accounts (D85): customers' payments and account changes. Signed
 * with the Connect endpoint's secret; each event names the account it came from.
 */
class StripeConnectWebhookController extends Controller
{
    public function __invoke(Request $request, StripeApi $stripe, CustomerPayments $payments): Response
    {
        $payload = (string) $request->getContent();
        $secret = (string) config('billing.stripe.connect_webhook_secret');
        abort_unless($secret !== '' && $stripe->isGenuine($payload, (string) $request->header('Stripe-Signature'), 300, $secret), 400);

        $event = json_decode($payload, true);
        if (! is_array($event) || ! isset($event['id'], $event['type'], $event['account'])) {
            return response()->noContent();
        }
        if (! Cache::add('stripe-connect-event:'.$event['id'], true, now()->addDays(3))) {
            return response()->noContent();
        }

        $object = (array) ($event['data']['object'] ?? []);
        $account = (string) $event['account'];
        match ($event['type']) {
            'checkout.session.completed', 'checkout.session.async_payment_succeeded' => $payments->completed($object, $account),
            'charge.refunded' => $payments->refunded($object, $account),
            'account.updated' => ($known = PaymentAccount::withoutGlobalScopes()->where('stripe_account_id', $account)->first()) ? $payments->refresh($known, $object) : null,
            default => null,
        };

        return response()->noContent();
    }
}
