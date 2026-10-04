<?php

namespace App\Actions\Billing;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\User;
use App\Notifications\PaymentReported;
use App\Support\Audit\Audit;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * The client says "I've paid" (Payoneer doesn't tell us automatically): the SureHelp billing team is
 * asked to confirm it against Payoneer and record it.
 */
class ReportPayment
{
    public function __construct(private readonly Audit $audit) {}

    public function handle(Invoice $invoice, User $actor, string $note): Invoice
    {
        if ($invoice->status !== InvoiceStatus::Open) {
            throw ValidationException::withMessages(['note' => ['This invoice is already settled.']]);
        }
        // Once is enough: repeated reports would email the billing team every time.
        if ($invoice->client_reported_paid_at !== null) {
            throw ValidationException::withMessages(['note' => ['You already told us about this payment. We\'ll confirm it shortly.']]);
        }

        $invoice->forceFill(['client_reported_paid_at' => now(), 'client_payment_note' => mb_substr(trim($note), 0, 500) ?: null])->save();
        $this->audit->record('invoice.payment_reported', $invoice, new: ['note' => $invoice->client_payment_note], organization: $invoice->organization, actor: $actor, label: $invoice->number);

        $billingTeam = User::query()->where('role', 'admin')->where('is_active', true)->get()
            ->filter(fn (User $u) => $u->hasPermissionIn('billing.manage'));
        Notification::send($billingTeam, new PaymentReported($invoice, $actor));

        return $invoice;
    }
}
