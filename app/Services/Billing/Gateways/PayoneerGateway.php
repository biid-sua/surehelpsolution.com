<?php

namespace App\Services\Billing\Gateways;

use App\Models\Invoice;
use App\Services\Billing\BillingSettings;
use App\Services\Billing\PaymentOption;

/**
 * Payoneer for a regular business account (docs/decisions.md D22):
 *  - card or bank debit (ACH) through a Payoneer payment link / payment request, created in Payoneer;
 *  - bank transfer to the Payoneer receiving account details.
 * Payoneer doesn't call us back for these, so the SureHelp team records each payment (or confirms one
 * the client reported) in Admin › Billing. Payoneer Checkout (automatic) needs separate merchant approval.
 */
class PayoneerGateway implements PaymentGateway
{
    public function __construct(private readonly BillingSettings $settings) {}

    public function key(): string
    {
        return 'payoneer';
    }

    public function options(Invoice $invoice): array
    {
        $options = [];
        $link = $invoice->payment_url ?: $this->settings->get('payoneer_payment_link');

        if (filled($link)) {
            $options[] = new PaymentOption('payoneer_link', 'Pay by card or bank (Payoneer)',
                'Secure payment page by Payoneer. Pay with a credit or debit card, or directly from your bank account (ACH).'
                .($invoice->payment_url ? '' : ' Enter '.$invoice->money($invoice->balanceCents()).' and invoice '.$invoice->number.'.'),
                $link);
        } elseif (filled($this->settings->get('payoneer_email'))) {
            $options[] = new PaymentOption('payoneer_request', 'Pay by card or bank (Payoneer)',
                'We\'ll email you a secure Payoneer payment request for this invoice. Prefer to pay now? Ask us at '.$this->settings->get('payoneer_email').'.');
        }

        if (filled($this->settings->get('bank_details'))) {
            $options[] = new PaymentOption('bank_transfer', 'Bank transfer',
                'Send '.$invoice->money($invoice->balanceCents()).' to the account below with reference '.$invoice->number.'.',
                details: (string) $this->settings->get('bank_details'));
        }

        return $options;
    }

    public function confirmsAutomatically(): bool
    {
        return false;
    }
}
