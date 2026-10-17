<?php

namespace App\Services\Billing\Stripe;

use App\Actions\Billing\RecordPayment;
use App\Actions\Notifications\NotifyOrganization;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Exceptions\BillingUnavailable;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\User;
use App\Notifications\AutopayFailed;
use App\Support\Audit\Audit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Card payments for SureHelp invoices (D64): Stripe Checkout for paying an invoice (optionally
 * saving the card), automatic charges of new invoices to the saved card, and booking what Stripe
 * confirms. Booking is idempotent: the PaymentIntent id is the payment's reference.
 */
class CardPayments
{
    public function __construct(
        private readonly StripeApi $stripe,
        private readonly RecordPayment $record,
        private readonly Audit $audit,
    ) {}

    public function enabled(): bool
    {
        return config('billing.gateway') === 'stripe' && $this->stripe->isConfigured();
    }

    /**
     * A Stripe Checkout page for what's still owed on an invoice.
     *
     * @throws BillingUnavailable
     */
    public function checkoutUrl(Invoice $invoice, User $payer, bool $saveCard): string
    {
        if ($invoice->status !== InvoiceStatus::Open || $invoice->balanceCents() <= 0) {
            throw new BillingUnavailable('This invoice has nothing left to pay.');
        }
        $organization = $invoice->organization;
        $customer = $this->customer($organization, $payer);
        $session = $this->stripe->post('checkout/sessions', array_filter([
            'mode' => 'payment',
            'customer' => $customer,
            'client_reference_id' => $invoice->ulid,
            'line_items' => [['quantity' => 1, 'price_data' => [
                'currency' => strtolower($invoice->currency),
                'unit_amount' => $invoice->balanceCents(),
                'product_data' => ['name' => 'SureHelp invoice '.$invoice->number],
            ]]],
            'payment_intent_data' => array_filter([
                'metadata' => ['invoice' => $invoice->ulid, 'save_card' => $saveCard ? '1' : '0'],
                'setup_future_usage' => $saveCard ? 'off_session' : null,
            ]),
            'metadata' => ['invoice' => $invoice->ulid, 'save_card' => $saveCard ? '1' : '0'],
            'success_url' => route('app.billing', ['paid' => $invoice->ulid]),
            'cancel_url' => route('app.billing'),
        ]), 'checkout-'.$invoice->ulid.'-'.$invoice->balanceCents().'-'.($saveCard ? 's' : 'n').'-'.now()->format('YmdHi'));
        $this->audit->record('billing.checkout_started', $invoice, new: ['save_card' => $saveCard], organization: $organization, actor: $payer, label: $invoice->number);

        return (string) $session['url'];
    }

    /**
     * Stripe says a Checkout payment went through: book it, and keep the card when the payer asked.
     *
     * @param  array<string, mixed>  $session  the checkout.session object
     */
    public function checkoutCompleted(array $session): ?Payment
    {
        if (($session['payment_status'] ?? '') !== 'paid' || ! is_string($session['payment_intent'] ?? null)) {
            return null;
        }
        $invoice = Invoice::withoutGlobalScopes()->where('ulid', (string) ($session['metadata']['invoice'] ?? $session['client_reference_id'] ?? ''))->first();
        if (! $invoice) {
            return null;
        }
        $payment = $this->book($invoice, (int) ($session['amount_total'] ?? 0), $session['payment_intent']);
        if (($session['metadata']['save_card'] ?? '0') === '1') {
            $this->keepCard($invoice->organization, $session['payment_intent']);
        }

        return $payment;
    }

    /**
     * An automatic charge (or any PaymentIntent for an invoice) succeeded.
     *
     * @param  array<string, mixed>  $intent
     */
    public function intentSucceeded(array $intent): ?Payment
    {
        $invoice = Invoice::withoutGlobalScopes()->where('ulid', (string) ($intent['metadata']['invoice'] ?? ''))->first();

        return $invoice ? $this->book($invoice, (int) ($intent['amount_received'] ?? $intent['amount'] ?? 0), (string) $intent['id']) : null;
    }

    /**
     * Charge a new invoice to the saved card (automatic payments). A decline leaves the invoice open
     * with the normal reminders, and tells the business so they can pay another way.
     */
    public function chargeAutomatically(Invoice $invoice): void
    {
        $organization = $invoice->organization;
        if (! $this->enabled() || ! $organization?->autopay_payment_method || ! $organization->stripe_customer_id
            || $invoice->status !== InvoiceStatus::Open || $invoice->balanceCents() <= 0 || $invoice->autopay_attempted_at) {
            return;
        }
        $invoice->forceFill(['autopay_attempted_at' => now(), 'autopay_error' => null])->save();

        try {
            $intent = $this->stripe->post('payment_intents', [
                'amount' => $invoice->balanceCents(),
                'currency' => strtolower($invoice->currency),
                'customer' => $organization->stripe_customer_id,
                'payment_method' => $organization->autopay_payment_method,
                'off_session' => 'true',
                'confirm' => 'true',
                'description' => 'SureHelp invoice '.$invoice->number,
                'metadata' => ['invoice' => $invoice->ulid, 'autopay' => '1'],
            ], 'autopay-'.$invoice->ulid);
        } catch (BillingUnavailable $e) {
            $this->failed($invoice, $e->getMessage());

            return;
        }

        if (($intent['status'] ?? '') === 'succeeded') {
            $this->book($invoice, (int) ($intent['amount_received'] ?? $intent['amount'] ?? $invoice->balanceCents()), (string) $intent['id']);
        } elseif (in_array($intent['status'] ?? '', ['requires_action', 'requires_payment_method'], true)) {
            $this->failed($invoice, 'Your bank asked to confirm this payment. Pay this invoice by card on your Billing page.');
        }
    }

    /** @param array<string, mixed> $intent a payment_intent.payment_failed event's object */
    public function intentFailed(array $intent): void
    {
        $invoice = Invoice::withoutGlobalScopes()->where('ulid', (string) ($intent['metadata']['invoice'] ?? ''))->first();
        if ($invoice && ($intent['metadata']['autopay'] ?? '') === '1' && $invoice->status === InvoiceStatus::Open && ! $invoice->autopay_error) {
            $this->failed($invoice, (string) ($intent['last_payment_error']['message'] ?? 'The card was declined.'));
        }
    }

    public function stopAutopay(Organization $organization, User $actor): void
    {
        $method = $organization->autopay_payment_method;
        $organization->forceFill(['autopay_payment_method' => null, 'autopay_card_label' => null, 'autopay_enabled_at' => null])->save();
        if ($method && $this->stripe->isConfigured()) {
            try {
                $this->stripe->post("payment_methods/{$method}/detach");
            } catch (BillingUnavailable $e) {
                report($e); // the card is no longer used either way
            }
        }
        $this->audit->record('billing.autopay_stopped', $organization, organization: $organization, actor: $actor);
    }

    private function book(Invoice $invoice, int $amountCents, string $intentId): ?Payment
    {
        $existing = Payment::withoutGlobalScopes()->where('provider', 'stripe')->where('reference', $intentId)->first();
        if ($existing) {
            return $existing; // Stripe sends events more than once
        }
        $amount = min($amountCents, $invoice->fresh()?->balanceCents() ?? 0);
        if ($amount <= 0) {
            report(new \RuntimeException("Stripe payment {$intentId} for invoice {$invoice->number} has nothing left to pay: refund or credit it by hand."));

            return null;
        }
        try {
            return $this->record->handle($invoice, $amount, PaymentMethod::StripeCard, $intentId, null, CarbonImmutable::now(), 'Confirmed by Stripe');
        } catch (ValidationException) {
            return Payment::withoutGlobalScopes()->where('provider', 'stripe')->where('reference', $intentId)->first();
        }
    }

    private function keepCard(Organization $organization, string $intentId): void
    {
        try {
            $intent = $this->stripe->get("payment_intents/{$intentId}", ['expand' => ['payment_method']]);
        } catch (BillingUnavailable $e) {
            report($e);

            return;
        }
        $method = $intent['payment_method'] ?? null;
        if (! is_array($method) || ! isset($method['id'])) {
            return;
        }
        $card = (array) ($method['card'] ?? []);
        $label = Str::title((string) ($card['brand'] ?? 'Card')).' •••• '.($card['last4'] ?? '????').(isset($card['exp_month'], $card['exp_year']) ? sprintf(' · %02d/%02d', $card['exp_month'], $card['exp_year'] % 100) : '');
        $organization->forceFill(['autopay_payment_method' => (string) $method['id'], 'autopay_card_label' => $label, 'autopay_enabled_at' => now()])->save();
        $this->audit->record('billing.autopay_started', $organization, new: ['card' => $label], organization: $organization, actor: null);
    }

    private function failed(Invoice $invoice, string $why): void
    {
        $invoice->forceFill(['autopay_error' => Str::limit($why, 250)])->save();
        $this->audit->record('billing.autopay_failed', $invoice, new: ['error' => $why], organization: $invoice->organization, actor: null, label: $invoice->number);
        app(NotifyOrganization::class)->handle($invoice->organization, new AutopayFailed($invoice), 'billing.view');
    }

    /** The business's Stripe customer, created on its first card payment. */
    private function customer(Organization $organization, User $payer): string
    {
        if ($organization->stripe_customer_id) {
            return $organization->stripe_customer_id;
        }
        $customer = $this->stripe->post('customers', [
            'name' => $organization->name,
            'email' => $organization->profile->email ?? $organization->owner->email ?? $payer->email,
            'metadata' => ['organization' => $organization->ulid],
        ], 'customer-'.$organization->ulid);
        $organization->forceFill(['stripe_customer_id' => (string) $customer['id']])->save();

        return (string) $customer['id'];
    }
}
