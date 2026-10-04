<?php

namespace App\Actions\Billing;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\User;
use App\Support\Audit\Audit;
use Illuminate\Validation\ValidationException;

/**
 * Cancels an invoice that shouldn't be paid. Its number stays used (no gaps, spec-friendly bookkeeping).
 */
class VoidInvoice
{
    public function __construct(private readonly Audit $audit) {}

    public function handle(Invoice $invoice, User $actor, string $reason): Invoice
    {
        if ($invoice->status !== InvoiceStatus::Open || $invoice->amount_paid_cents > 0) {
            throw ValidationException::withMessages(['invoice' => ['Only unpaid invoices can be voided.']]);
        }
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => ['Say why it is voided.']]);
        }

        $invoice->forceFill(['status' => InvoiceStatus::Void, 'voided_at' => now(), 'notes' => trim(($invoice->notes ? $invoice->notes."\n" : '').'Voided: '.trim($reason))])->save();
        $this->audit->record('invoice.voided', $invoice, new: ['reason' => trim($reason)], organization: $invoice->organization, actor: $actor, label: $invoice->number);

        return $invoice;
    }
}
