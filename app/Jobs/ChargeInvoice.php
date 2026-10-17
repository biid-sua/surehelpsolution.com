<?php

namespace App\Jobs;

use App\Models\Invoice;
use App\Services\Billing\Stripe\CardPayments;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Charges a newly issued invoice to the business's saved card (D64). Tried once; a decline is reported, not retried. */
class ChargeInvoice implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(public readonly int $invoiceId) {}

    public function handle(CardPayments $payments): void
    {
        $invoice = Invoice::withoutGlobalScopes()->with('organization')->find($this->invoiceId);
        if ($invoice) {
            $payments->chargeAutomatically($invoice);
        }
    }
}
