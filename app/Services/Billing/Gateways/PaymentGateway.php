<?php

namespace App\Services\Billing\Gateways;

use App\Models\Invoice;
use App\Services\Billing\PaymentOption;

/**
 * A payment provider. Today: Payoneer, where the client pays on Payoneer's own pages and SureHelp
 * records the payment. An automated provider (Payoneer Checkout, Stripe) implements the same
 * contract and confirms payments itself through webhooks (docs/billing.md).
 */
interface PaymentGateway
{
    public function key(): string;

    /**
     * @return list<PaymentOption>
     */
    public function options(Invoice $invoice): array;

    /** Payments are confirmed by the provider automatically (webhooks) rather than recorded by a person. */
    public function confirmsAutomatically(): bool;
}
