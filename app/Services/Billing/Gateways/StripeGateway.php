<?php

namespace App\Services\Billing\Gateways;

use App\Models\Invoice;
use App\Services\Billing\BillingSettings;
use App\Services\Billing\PaymentOption;

/**
 * Stripe (D64): the business pays an invoice by card on Stripe Checkout, optionally saving the card
 * so later invoices are charged automatically. Stripe confirms each payment through a signed webhook,
 * so nobody records it by hand. Bank transfer stays available when bank details are set.
 */
class StripeGateway implements PaymentGateway
{
    public function __construct(private readonly BillingSettings $settings) {}

    public function key(): string
    {
        return 'stripe';
    }

    public function options(Invoice $invoice): array
    {
        $options = [new PaymentOption('stripe_checkout', 'Pay by card',
            'Secure card payment by Stripe. You can save the card so future invoices are paid automatically.',
            route('app.billing.pay', $invoice))];

        if (filled($this->settings->get('bank_details'))) {
            $options[] = new PaymentOption('bank_transfer', 'Bank transfer',
                'Send '.$invoice->money($invoice->balanceCents()).' to the account below with reference '.$invoice->number.'.',
                details: (string) $this->settings->get('bank_details'));
        }

        return $options;
    }

    public function confirmsAutomatically(): bool
    {
        return true;
    }
}
