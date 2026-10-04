<?php

namespace App\Actions\Billing;

use App\Actions\Notifications\NotifyOrganization;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Enums\SubscriptionStatus;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Notifications\PaymentReceived;
use App\Support\Audit\Audit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Books money received against an invoice (spec §28). A payment can't exceed what is owed, and the
 * same provider transaction can't be booked twice.
 */
class RecordPayment
{
    public function __construct(
        private readonly Audit $audit,
        private readonly NotifyOrganization $notify,
    ) {}

    /**
     * @throws ValidationException
     */
    public function handle(Invoice $invoice, int $amountCents, PaymentMethod $method, ?string $reference, User $actor, ?CarbonImmutable $receivedAt = null, ?string $notes = null): Payment
    {
        $reference = filled($reference) ? trim((string) $reference) : null;

        $payment = DB::transaction(function () use ($invoice, $amountCents, $method, $reference, $actor, $receivedAt, $notes) {
            $invoice = Invoice::withoutGlobalScopes()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();

            if ($invoice->status !== InvoiceStatus::Open) {
                throw ValidationException::withMessages(['amount' => ["Invoice {$invoice->number} is {$invoice->status->label()}: nothing is owed."]]);
            }
            if ($amountCents <= 0 || $amountCents > $invoice->balanceCents()) {
                throw ValidationException::withMessages(['amount' => ['Enter an amount up to '.$invoice->money($invoice->balanceCents()).'.']]);
            }
            if ($reference && Payment::withoutGlobalScopes()->where('provider', $method->provider())->where('reference', $reference)->exists()) {
                throw ValidationException::withMessages(['reference' => ['A payment with this reference is already recorded.']]);
            }

            $payment = Payment::create([
                'organization_id' => $invoice->organization_id,
                'invoice_id' => $invoice->id,
                'amount_cents' => $amountCents,
                'currency' => $invoice->currency,
                'method' => $method,
                'provider' => $method->provider(),
                'reference' => $reference,
                'received_at' => $receivedAt ?? now(),
                'recorded_by_user_id' => $actor->id,
                'notes' => $notes,
            ]);

            $paid = $invoice->amount_paid_cents + $amountCents;
            $invoice->forceFill(array_filter([
                'amount_paid_cents' => $paid,
                'status' => $paid >= $invoice->total_cents ? InvoiceStatus::Paid : null,
                'paid_at' => $paid >= $invoice->total_cents ? ($receivedAt ?? now()) : null,
            ], fn ($v) => $v !== null))->save();

            // Paid up: a past-due subscription is back in good standing.
            $subscription = $invoice->subscription;
            if ($subscription && $subscription->status === SubscriptionStatus::PastDue
                && ! Invoice::withoutGlobalScopes()->where('subscription_id', $subscription->id)->where('status', InvoiceStatus::Open->value)->where('due_at', '<', now())->exists()) {
                $subscription->forceFill(['status' => SubscriptionStatus::Active])->save();
            }

            return $payment;
        });

        $invoice->refresh();
        $this->audit->record('payment.recorded', $payment, new: ['invoice' => $invoice->number, 'amount_cents' => $amountCents, 'method' => $method->value, 'reference' => $reference],
            organization: $invoice->organization, actor: $actor, label: $invoice->number);
        $this->notify->handle($invoice->organization, new PaymentReceived($payment), 'billing.view');

        return $payment;
    }
}
