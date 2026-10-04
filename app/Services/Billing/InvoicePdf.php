<?php

namespace App\Services\Billing;

use App\Models\Invoice;
use App\Services\Billing\Gateways\PaymentGateway;
use Barryvdh\DomPDF\Facade\Pdf;

/**
 * The invoice as a PDF (spec §29: download PDF). The same Blade view renders the on-screen invoice.
 */
class InvoicePdf
{
    public function __construct(
        private readonly BillingSettings $settings,
        private readonly PaymentGateway $gateway,
    ) {}

    public function render(Invoice $invoice): string
    {
        return Pdf::loadView('billing.invoice', $this->data($invoice) + ['pdf' => true])->setPaper('letter')->output();
    }

    public function filename(Invoice $invoice): string
    {
        return $invoice->number.'.pdf';
    }

    /**
     * @return array<string, mixed>
     */
    public function data(Invoice $invoice): array
    {
        $invoice->loadMissing(['items', 'payments', 'organization']);

        return [
            'invoice' => $invoice,
            'company' => $this->settings->all(),
            'options' => $invoice->status->value === 'open' ? $this->gateway->options($invoice) : [],
        ];
    }
}
