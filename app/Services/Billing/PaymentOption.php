<?php

namespace App\Services\Billing;

/**
 * One way a client can pay an invoice, as shown on the invoice and billing page.
 */
final class PaymentOption
{
    public function __construct(
        public readonly string $key,
        public readonly string $title,
        public readonly string $description,
        public readonly ?string $url = null,
        public readonly ?string $details = null,
    ) {}
}
