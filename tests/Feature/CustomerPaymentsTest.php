<?php

namespace Tests\Feature;

use App\Actions\Organizations\ProvisionUserTenancy;
use App\Livewire\Client\Payments;
use App\Models\Appointment;
use App\Models\BusinessHour;
use App\Models\BusinessProfile;
use App\Models\ChatWidget;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\PaymentAccount;
use App\Models\PaymentRequest;
use App\Models\PhoneNumber;
use App\Models\User;
use App\Models\Website;
use App\Notifications\CustomerEmail;
use App\Notifications\CustomerPaid;
use App\Services\Billing\FeatureAccess;
use App\Services\Telephony\TelephonyProvider;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Deposits and pay-by-text (D85): a business connects its own Stripe account, sends pay links, takes
 * deposits for online bookings, and Stripe confirms payments through the Connect webhook.
 */
class CustomerPaymentsTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'whsec_connect_test';

    private User $owner;

    private Organization $org;

    private bool $chargesEnabled = false;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->travelTo(CarbonImmutable::parse('2026-10-12 15:00', 'UTC'));   // Monday
        config(['billing.stripe.secret' => 'sk_test_platform', 'billing.stripe.connect_webhook_secret' => self::SECRET, 'billing.stripe.connect_fee_percent' => 1]);
        $this->owner = User::factory()->create(['role' => 'client', 'is_active' => true, 'must_change_password' => false]);
        $this->org = app(ProvisionUserTenancy::class)->handle($this->owner);
        $this->org->forceFill(['name' => 'Rivera Plumbing', 'timezone' => 'UTC', 'status' => 'active', 'setup_completed_at' => now()])->save();
        $this->org = $this->org->fresh();
        BusinessProfile::create(['organization_id' => $this->org->id, 'display_name' => 'Rivera Plumbing', 'email' => 'office@rivera.test']);
        PhoneNumber::create(['organization_id' => $this->org->id, 'provider' => 'fake', 'provider_sid' => 'PN1', 'number' => '+15125550100']);
        DB::table('organization_features')->insert(['organization_id' => $this->org->id, 'feature' => 'customer_payments', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        Http::fake(function (Request $request) {
            $url = $request->url();

            return match (true) {
                str_ends_with($url, '/v1/accounts') => Http::response(['id' => 'acct_rivera', 'default_currency' => 'usd', 'charges_enabled' => false]),
                str_ends_with($url, '/v1/account_links') => Http::response(['url' => 'https://connect.stripe.com/setup/s/abc']),
                str_ends_with($url, '/v1/accounts/acct_rivera') => Http::response(['id' => 'acct_rivera', 'charges_enabled' => $this->chargesEnabled, 'details_submitted' => true, 'default_currency' => 'usd']),
                str_ends_with($url, '/v1/checkout/sessions') => Http::response(['id' => 'cs_test_1', 'url' => 'https://checkout.stripe.com/c/pay/cs_test_1']),
                default => Http::response(['error' => ['message' => 'Unexpected '.$url]], 400),
            };
        });
        $this->actingAs($this->owner);
    }

    private function connect(): void
    {
        $this->get(route('app.payments.connect'))->assertRedirect('https://connect.stripe.com/setup/s/abc');
        $this->chargesEnabled = true;
        $this->get(route('app.payments', ['connected' => 1]))->assertOk()->assertSee('Collected this month');
    }

    private function event(array $event): TestResponse
    {
        $payload = json_encode($event);
        $t = time();

        return $this->call('POST', '/api/webhooks/stripe/connect', [], [], [], ['CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => 't='.$t.',v1='.hash_hmac('sha256', $t.'.'.$payload, self::SECRET)], $payload);
    }

    private function paid(PaymentRequest $request, string $account = 'acct_rivera', ?int $amount = null, string $id = 'evt_1'): TestResponse
    {
        return $this->event(['id' => $id, 'type' => 'checkout.session.completed', 'account' => $account, 'data' => ['object' => [
            'id' => 'cs_test_1', 'payment_status' => 'paid', 'amount_total' => $amount ?? $request->amount_cents, 'payment_intent' => 'pi_1',
            'metadata' => ['payment_request' => $request->ulid],
        ]]]);
    }

    public function test_connecting_stripe_then_sending_a_pay_link_that_gets_paid(): void
    {
        $this->get(route('app.payments'))->assertOk()->assertSee('Connect Stripe to get paid');
        $this->connect();
        $account = PaymentAccount::query()->sole();
        $this->assertTrue($account->charges_enabled);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/v1/accounts') && $r['type'] === 'standard' && $r['business_profile[name]'] === 'Rivera Plumbing');

        $ana = Customer::create(['organization_id' => $this->org->id, 'first_name' => 'Ana', 'phone' => '(512) 555-0147', 'email' => 'ana@example.test']);
        Livewire::test(Payments::class)->call('compose', $ana->ulid)->set('amount', 'abc')->set('description', 'Water heater repair')->call('send')->assertHasErrors('amount')
            ->set('amount', '150.50')->call('send')->assertHasNoErrors();

        $request = PaymentRequest::query()->sole();
        $this->assertSame([15050, 151, 'payment', ['sms', 'email']], [$request->amount_cents, $request->fee_cents, $request->kind, $request->sent_via]);
        $text = collect(app(TelephonyProvider::class)->texts)->last();
        $this->assertStringContainsString('request for a payment of $150.50 (Water heater repair). Pay securely: '.$request->link(), $text['body']);
        Notification::assertSentTo(new AnonymousNotifiable, CustomerEmail::class, fn (CustomerEmail $m) => $m->actionUrl === $request->link() && $m->actionText === 'Pay $150.50');

        // The customer opens the link and goes to Stripe Checkout, on Rivera's own account.
        $this->get(route('pay.show', $request->token))->assertOk()->assertSee('$150.50')->assertSee('Water heater repair');
        $this->post(route('pay.checkout', $request->token))->assertRedirect('https://checkout.stripe.com/c/pay/cs_test_1');
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/v1/checkout/sessions') && $r->header('Stripe-Account') === ['acct_rivera']
            && $r['line_items[0][price_data][unit_amount]'] == 15050 && $r['payment_intent_data[application_fee_amount]'] == 151
            && $r['metadata[payment_request]'] === $request->ulid);

        // Forged, wrong account or wrong amount: ignored.
        $this->call('POST', '/api/webhooks/stripe/connect', [], [], [], ['HTTP_STRIPE_SIGNATURE' => 't=1,v1=bad'], '{}')->assertStatus(400);
        $this->paid($request, account: 'acct_someone_else', id: 'evt_a')->assertNoContent();
        $this->paid($request, amount: 100, id: 'evt_b')->assertNoContent();
        $this->assertSame(PaymentRequest::OPEN, $request->fresh()->status);

        $this->paid($request)->assertNoContent();
        $request->refresh();
        $this->assertSame([PaymentRequest::PAID, 'pi_1'], [$request->status, $request->payment_intent_id]);
        Notification::assertSentTo($this->owner, CustomerPaid::class);
        $this->get(route('pay.show', $request->token))->assertSee('Paid, thank you, Ana!');
        $this->post(route('pay.checkout', $request->token))->assertRedirect(route('pay.show', $request->token));

        // Refunds in the Stripe dashboard come back too.
        $this->event(['id' => 'evt_r', 'type' => 'charge.refunded', 'account' => 'acct_rivera', 'data' => ['object' => ['payment_intent' => 'pi_1', 'refunded' => true]]]);
        $this->assertSame(PaymentRequest::REFUNDED, $request->fresh()->status);
    }

    public function test_online_bookings_ask_for_the_deposit(): void
    {
        $this->connect();
        Livewire::test(Payments::class)->set('depositEnabled', true)->set('depositAmount', '50')->call('saveDeposit')->assertHasNoErrors();
        foreach ([1, 2, 3, 4, 5] as $day) {
            BusinessHour::create(['organization_id' => $this->org->id, 'day_of_week' => $day, 'opens_at' => '09:00', 'closes_at' => '17:00']);
        }
        Website::create(['organization_id' => $this->org->id, 'url' => 'https://rivera.test/', 'host' => 'rivera.test', 'verified_at' => now()]);
        $widget = ChatWidget::for($this->org);
        $widget->update(['allowed_origins' => ['rivera.test'], 'features' => ['chat' => true, 'booking' => true, 'call' => false, 'lead' => false], 'bookings_need_confirmation' => false]);

        $response = $this->call('POST', '/api/chat/'.$widget->public_key.'/booking', [], [], [], ['HTTP_ORIGIN' => 'https://rivera.test', 'CONTENT_TYPE' => 'text/plain', 'REMOTE_ADDR' => '203.0.113.9'],
            json_encode(['starts_at' => '2026-10-13 10:00', 'name' => 'Ana Lopez', 'phone' => '512 555 0147', 'email' => 'ana@example.test']))->assertCreated();

        $deposit = PaymentRequest::query()->sole();
        $this->assertSame(['deposit', 5000, Appointment::query()->sole()->id], [$deposit->kind, $deposit->amount_cents, $deposit->appointment_id]);
        $response->assertJsonPath('pay_url', $deposit->link())->assertJsonPath('pay_label', 'Pay $50 deposit');
        $this->assertStringContainsString('Please pay the $50 deposit', $response->json('message'));
    }

    public function test_without_the_plan_feature_or_stripe_nothing_can_be_requested(): void
    {
        DB::table('organization_features')->delete();
        app(FeatureAccess::class)->forget($this->org);
        $this->get(route('app.payments'))->assertOk()->assertSee('Take deposits and payments by text');
        $this->get(route('app.payments.connect'))->assertRedirect(route('app.billing'));

        DB::table('organization_features')->insert(['organization_id' => $this->org->id, 'feature' => 'customer_payments', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        config(['billing.stripe.connect_webhook_secret' => '']);
        app(FeatureAccess::class)->forget($this->org);
        $this->get(route('app.payments'))->assertOk()->assertSee('Coming soon');
        Livewire::test(Payments::class)->call('send')->assertForbidden();
    }

    public function test_staff_can_request_but_not_cancel_or_change_settings(): void
    {
        $this->connect();
        $staff = User::factory()->create(['role' => 'client', 'is_active' => true, 'must_change_password' => false]);
        $this->org->members()->attach($staff->id, ['role' => 'staff', 'status' => 'active']);
        $ana = Customer::create(['organization_id' => $this->org->id, 'first_name' => 'Ana', 'email' => 'ana@example.test']);

        $this->actingAs($staff);
        Livewire::test(Payments::class)->call('compose', $ana->ulid)->set('amount', '80')->set('description', 'Drain cleaning')->set('channels', ['email'])
            ->call('send')->assertHasNoErrors();
        $ulid = PaymentRequest::query()->value('ulid');
        Livewire::test(Payments::class)->call('cancel', $ulid)->assertForbidden();
        Livewire::test(Payments::class)->set('depositEnabled', true)->call('saveDeposit')->assertForbidden();
        $this->get(route('app.payments.connect'))->assertForbidden();
    }
}
