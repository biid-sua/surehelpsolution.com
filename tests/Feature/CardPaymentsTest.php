<?php

namespace Tests\Feature;

use App\Actions\Billing\IssueInvoice;
use App\Actions\Organizations\ProvisionUserTenancy;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Livewire\Client\Billing\Index as Billing;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\User;
use App\Notifications\AutopayFailed;
use App\Notifications\PaymentReceived;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Card payments through Stripe (D64): Checkout for an invoice, booking what Stripe confirms (once),
 * saving the card, automatic charges of new invoices, declines, and the signed webhook.
 */
class CardPaymentsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Organization $business;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        config(['billing.gateway' => 'stripe', 'billing.stripe' => ['secret' => 'sk_test_123', 'webhook_secret' => 'whsec_test']]);
        $this->owner = User::factory()->create(['role' => 'client', 'is_active' => true, 'must_change_password' => false]);
        $this->business = app(ProvisionUserTenancy::class)->handle($this->owner);
        $this->business->update(['name' => 'Rivera Plumbing']);
    }

    private function invoice(int $cents = 29900): Invoice
    {
        return app(IssueInvoice::class)->issue($this->business->fresh(), [['description' => 'Receptionist plan', 'quantity' => 1, 'unit_cents' => $cents]]);
    }

    /** @param array<string, mixed> $object */
    private function webhook(string $type, array $object, string $id = 'evt_1', ?string $secret = null)
    {
        $payload = json_encode(['id' => $id, 'type' => $type, 'data' => ['object' => $object]]);
        $t = time();
        $signature = hash_hmac('sha256', $t.'.'.$payload, $secret ?? 'whsec_test');

        return $this->call('POST', '/api/webhooks/stripe', [], [], [], ['HTTP_STRIPE_SIGNATURE' => "t={$t},v1={$signature}", 'CONTENT_TYPE' => 'application/json'], $payload);
    }

    public function test_paying_an_invoice_by_card_goes_to_stripe_checkout(): void
    {
        $invoice = $this->invoice();
        Http::fake([
            'api.stripe.com/v1/customers' => Http::response(['id' => 'cus_1']),
            'api.stripe.com/v1/checkout/sessions' => Http::response(['id' => 'cs_1', 'url' => 'https://checkout.stripe.com/c/pay/cs_1']),
        ]);

        $this->actingAs($this->owner)->get(route('app.billing'))->assertSee('Pay by card')->assertSee('Pay and save card for automatic payments');
        $this->get(route('app.billing.pay', [$invoice, 'save' => 1]))->assertRedirect('https://checkout.stripe.com/c/pay/cs_1');

        $this->assertSame('cus_1', $this->business->fresh()->stripe_customer_id);
        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), 'checkout/sessions') && $r->hasHeader('Authorization', 'Bearer sk_test_123')
            && $r['line_items'][0]['price_data']['unit_amount'] === 29900 && $r['payment_intent_data']['setup_future_usage'] === 'off_session'
            && $r['metadata']['invoice'] === $invoice->ulid && $r['customer'] === 'cus_1' && $r->hasHeader('Idempotency-Key'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'billing.checkout_started']);

        // Managers can see billing but not pay; other businesses can't reach the invoice.
        $manager = User::factory()->create(['role' => 'client', 'is_active' => true, 'must_change_password' => false]);
        $this->business->members()->attach($manager->id, ['role' => 'manager', 'status' => 'active']);
        $this->actingAs($manager)->get(route('app.billing.pay', $invoice))->assertForbidden();
    }

    public function test_stripe_confirms_the_payment_once_and_the_card_is_kept(): void
    {
        $invoice = $this->invoice();
        Http::fake(['api.stripe.com/v1/payment_intents/pi_1*' => Http::response(['id' => 'pi_1', 'payment_method' => ['id' => 'pm_1', 'card' => ['brand' => 'visa', 'last4' => '4242', 'exp_month' => 8, 'exp_year' => 2028]]])]);
        $session = ['payment_status' => 'paid', 'payment_intent' => 'pi_1', 'amount_total' => 29900, 'client_reference_id' => $invoice->ulid, 'metadata' => ['invoice' => $invoice->ulid, 'save_card' => '1']];

        $this->webhook('checkout.session.completed', $session)->assertNoContent();
        $this->webhook('checkout.session.completed', $session, 'evt_2')->assertNoContent(); // Stripe repeats itself
        $this->webhook('payment_intent.succeeded', ['id' => 'pi_1', 'amount_received' => 29900, 'metadata' => ['invoice' => $invoice->ulid]], 'evt_3');

        $payment = Payment::withoutGlobalScopes()->sole();
        $this->assertSame([PaymentMethod::StripeCard, 'stripe', 'pi_1', 29900, null], [$payment->method, $payment->provider, $payment->reference, $payment->amount_cents, $payment->recorded_by_user_id]);
        $this->assertSame(InvoiceStatus::Paid, $invoice->fresh()->status);
        Notification::assertSentTo($this->owner, PaymentReceived::class);
        $this->assertSame(['pm_1', 'Visa •••• 4242 · 08/28'], [$this->business->fresh()->autopay_payment_method, $this->business->fresh()->autopay_card_label]);
    }

    public function test_new_invoices_are_charged_to_the_saved_card(): void
    {
        $this->business->forceFill(['stripe_customer_id' => 'cus_1', 'autopay_payment_method' => 'pm_1', 'autopay_card_label' => 'Visa •••• 4242'])->save();
        Http::fake(['api.stripe.com/v1/payment_intents' => Http::response(['id' => 'pi_9', 'status' => 'succeeded', 'amount_received' => 29900, 'metadata' => ['autopay' => '1']])]);

        $invoice = $this->invoice();

        $this->assertSame(InvoiceStatus::Paid, $invoice->fresh()->status);
        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), 'payment_intents') && $r['customer'] === 'cus_1' && $r['payment_method'] === 'pm_1'
            && $r['off_session'] === 'true' && $r['amount'] === 29900 && $r->header('Idempotency-Key')[0] === 'autopay-'.$invoice->ulid);
        $this->assertSame('pi_9', Payment::withoutGlobalScopes()->sole()->reference);
    }

    public function test_a_declined_automatic_charge_tells_the_business_and_leaves_the_invoice_open(): void
    {
        $this->business->forceFill(['stripe_customer_id' => 'cus_1', 'autopay_payment_method' => 'pm_1', 'autopay_card_label' => 'Visa •••• 4242'])->save();
        Http::fake(['api.stripe.com/v1/payment_intents' => Http::response(['error' => ['type' => 'card_error', 'code' => 'card_declined', 'decline_code' => 'insufficient_funds', 'message' => 'Your card has insufficient funds.']], 402)]);

        $invoice = $this->invoice();

        $invoice->refresh();
        $this->assertSame(InvoiceStatus::Open, $invoice->status);
        $this->assertSame('Your card has insufficient funds.', $invoice->autopay_error);
        Notification::assertSentTo($this->owner, AutopayFailed::class);
        $this->actingAs($this->owner)->get(route('app.billing'))->assertSee("Automatic payment didn't go through", false);
        $this->assertSame(0, Payment::withoutGlobalScopes()->count());
    }

    public function test_turning_off_automatic_payments_removes_the_card(): void
    {
        $this->business->forceFill(['stripe_customer_id' => 'cus_1', 'autopay_payment_method' => 'pm_1', 'autopay_card_label' => 'Visa •••• 4242'])->save();
        Http::fake(['api.stripe.com/v1/payment_methods/pm_1/detach' => Http::response(['id' => 'pm_1'])]);

        $this->actingAs($this->owner);
        Livewire::test(Billing::class)->assertSee('Visa •••• 4242')->call('stopAutopay');
        $this->assertNull($this->business->fresh()->autopay_payment_method);
        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), 'payment_methods/pm_1/detach'));

        Http::fake(['*' => Http::response([], 500)]);
        $this->invoice();
        Http::assertNothingSent();
    }

    public function test_webhooks_need_stripes_signature(): void
    {
        $invoice = $this->invoice();
        $session = ['payment_status' => 'paid', 'payment_intent' => 'pi_x', 'amount_total' => 29900, 'metadata' => ['invoice' => $invoice->ulid]];

        $this->webhook('checkout.session.completed', $session, 'evt_9', 'whsec_wrong')->assertStatus(400);
        $this->call('POST', '/api/webhooks/stripe', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{}')->assertStatus(400);
        $old = time() - 600;
        $payload = json_encode(['id' => 'evt_10', 'type' => 'checkout.session.completed', 'data' => ['object' => $session]]);
        $this->call('POST', '/api/webhooks/stripe', [], [], [], ['HTTP_STRIPE_SIGNATURE' => "t={$old},v1=".hash_hmac('sha256', $old.'.'.$payload, 'whsec_test'), 'CONTENT_TYPE' => 'application/json'], $payload)
            ->assertStatus(400);
        $this->assertSame(0, Payment::withoutGlobalScopes()->count());
    }

    public function test_payoneer_stays_the_default_and_hides_card_payments(): void
    {
        config(['billing.gateway' => 'payoneer']);
        $invoice = $this->invoice();
        $this->actingAs($this->owner)->get(route('app.billing'))->assertDontSee('Automatic payments');
        $this->get(route('app.billing.pay', $invoice))->assertNotFound();
    }
}
