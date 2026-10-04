<?php

namespace Tests\Feature;

use App\Actions\Billing\IssueInvoice;
use App\Actions\Billing\ManageSubscription;
use App\Actions\Billing\RecordPayment;
use App\Actions\Organizations\ProvisionUserTenancy;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Enums\SubscriptionStatus;
use App\Livewire\Admin\Billing\Index as AdminBilling;
use App\Livewire\Client\Billing\Index as ClientBilling;
use App\Livewire\Client\Settings\Notifications as NotificationSettings;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Notifications\InvoiceIssued;
use App\Notifications\InvoiceOverdue;
use App\Notifications\PaymentReceived;
use App\Notifications\PaymentReported;
use App\Notifications\PlanChangeRequested;
use App\Services\Billing\BillingRun;
use App\Services\Billing\BillingSettings;
use App\Services\Billing\Entitlements;
use App\Services\Billing\InvoiceNumbers;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * P5-1 — billing with Payoneer (spec §28–31).
 */
class BillingTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Carbon::setTestNow('2026-10-10 09:00:00');
        $this->admin = User::factory()->create(['role' => 'admin', 'is_active' => true, 'must_change_password' => false]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array{0: User, 1: Organization} */
    private function business(string $name = 'Rivera Plumbing'): array
    {
        $owner = User::factory()->create(['role' => 'client', 'is_active' => true, 'must_change_password' => false]);
        $organization = app(ProvisionUserTenancy::class)->handle($owner);
        $organization->update(['name' => $name]);

        return [$owner, $organization->fresh()];
    }

    private function member(Organization $organization, string $role): User
    {
        $user = User::factory()->create(['role' => 'client', 'is_active' => true, 'must_change_password' => false]);
        $organization->members()->attach($user->id, ['role' => $role, 'status' => 'active']);

        return $user;
    }

    private function plan(array $extra = []): Plan
    {
        return Plan::create(array_merge(['slug' => 'receptionist', 'name' => 'Virtual Receptionist', 'price_cents' => 29900, 'interval' => 'month', 'trial_days' => 0], $extra));
    }

    private function runOn(string $date): array
    {
        Carbon::setTestNow($date.' 06:05:00');

        return app(BillingRun::class)->run();
    }

    public function test_admin_creates_a_plan_and_subscribes_a_business_which_gets_its_invoice(): void
    {
        [$owner, $org] = $this->business();
        app(BillingSettings::class)->save(['due_days' => 10, 'payoneer_payment_link' => 'https://link.payoneer.com/Token?t=ABC']);

        $this->actingAs($this->admin)->get(route('admin.billing', ['tab' => 'plans']))->assertOk()->assertSee('No plans yet');
        Livewire::test(AdminBilling::class)->set('tab', 'plans')
            ->set('plan.name', 'Virtual Receptionist')->set('plan.price', '299')->set('plan.features', 'calendar_sync, ai.website_chatbot')
            ->call('savePlan')->assertHasNoErrors();
        $plan = Plan::sole();
        $this->assertSame(29900, $plan->price_cents);
        $this->assertSame(['calendar_sync', 'ai.website_chatbot'], $plan->features);

        Livewire::test(AdminBilling::class)->set('tab', 'subscriptions')
            ->set('subscribe.organization_id', (string) $org->id)->set('subscribe.plan_id', (string) $plan->id)->set('subscribe.trial', '')
            ->call('startSubscription')->assertHasNoErrors();

        $subscription = Subscription::withoutGlobalScopes()->sole();
        $this->assertSame(SubscriptionStatus::Active, $subscription->status);
        $this->assertSame('2026-11-10', $subscription->current_period_end->toDateString());

        $invoice = Invoice::withoutGlobalScopes()->sole();
        $this->assertSame('INV-2026-0001', $invoice->number);
        $this->assertSame(29900, $invoice->total_cents);
        $this->assertSame('2026-10-20', $invoice->due_at->toDateString());
        $this->assertSame('Rivera Plumbing', $invoice->billing_details['name']);
        $this->assertStringContainsString('Oct 10, 2026 – Nov 9, 2026', $invoice->items->sole()->description);
        Notification::assertSentTo($owner, InvoiceIssued::class);
        $this->assertDatabaseHas('audit_logs', ['action' => 'invoice.issued', 'organization_id' => $org->id]);

        $this->assertTrue(app(Entitlements::class)->allows($org, 'calendar_sync'));
        $this->assertFalse(app(Entitlements::class)->allows($org, 'sms'));

        // The email carries the PDF.
        $mail = (new InvoiceIssued($invoice))->toMail($owner);
        $this->assertCount(1, $mail->rawAttachments);
        $this->assertStringStartsWith('%PDF', $mail->rawAttachments[0]['data']);
    }

    public function test_client_sees_what_is_due_and_how_to_pay_with_payoneer(): void
    {
        [$owner, $org] = $this->business();
        app(BillingSettings::class)->save([
            'payoneer_payment_link' => 'https://link.payoneer.com/Token?t=DEFAULT',
            'bank_details' => "USD ACH\nRouting (ABA): 026073150\nAccount: 8000000000",
        ]);
        app(ManageSubscription::class)->subscribe($org, $this->plan(), $this->admin, withTrial: false);
        $invoice = Invoice::withoutGlobalScopes()->sole();

        $this->actingAs($owner)->get(route('app.billing'))->assertOk()
            ->assertSee('$299')->assertSee('Pay by card or bank (Payoneer)')
            ->assertSee('https://link.payoneer.com/Token?t=DEFAULT', false)
            ->assertSee('Routing (ABA): 026073150')->assertSee($invoice->number)->assertSee('Virtual Receptionist');

        // A link made in Payoneer for this exact invoice wins over the default one.
        $invoice->forceFill(['payment_url' => 'https://link.payoneer.com/Token?t=INV1'])->save();
        $this->get(route('app.billing'))->assertSee('https://link.payoneer.com/Token?t=INV1', false);

        $this->get(route('app.billing.invoice', $invoice))->assertOk()->assertSee('INVOICE')->assertSee('Download PDF');
        $pdf = $this->get(route('app.billing.invoice.pdf', $invoice))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $pdf->getContent());

        // "I've paid": the billing team is asked to confirm.
        Livewire::test(ClientBilling::class)->call('startReport', $invoice->ulid)->call('report')->assertHasErrors('paymentNote')
            ->set('paymentNote', 'Card via Payoneer link, Oct 11')->call('report')->assertHasNoErrors();
        $this->assertNotNull($invoice->fresh()->client_reported_paid_at);
        Notification::assertSentTo($this->admin, PaymentReported::class);

        // Reporting again (e.g. a replayed request) is refused, so the billing team is emailed once.
        Livewire::test(ClientBilling::class)->set('reporting', $invoice->ulid)->set('paymentNote', 'Again')->call('report')->assertHasErrors('paymentNote');
        Notification::assertSentToTimes($this->admin, PaymentReported::class, 1);
        $this->actingAs($this->admin);
        Livewire::test(AdminBilling::class)->assertSee('Reported paid')->assertSee($invoice->number);
    }

    public function test_recording_payments_settles_invoices_and_guards_against_mistakes(): void
    {
        [$owner, $org] = $this->business();
        app(ManageSubscription::class)->subscribe($org, $this->plan(), $this->admin, withTrial: false);
        $invoice = Invoice::withoutGlobalScopes()->sole();
        $record = app(RecordPayment::class);

        $fails = function (callable $fn, string $key) {
            try {
                $fn();
                $this->fail("expected {$key} error");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey($key, $e->errors());
            }
        };

        $fails(fn () => $record->handle($invoice, 30000, PaymentMethod::PayoneerCard, 'PX-1', $this->admin), 'amount');   // more than owed
        $record->handle($invoice, 10000, PaymentMethod::PayoneerBank, 'PX-1', $this->admin);
        $this->assertSame(InvoiceStatus::Open, $invoice->fresh()->status);
        $this->assertSame(19900, $invoice->fresh()->balanceCents());
        $fails(fn () => $record->handle($invoice, 19900, PaymentMethod::PayoneerCard, 'PX-1', $this->admin), 'reference');  // same Payoneer transaction

        $this->actingAs($this->admin);
        Livewire::test(AdminBilling::class)->call('select', $invoice->ulid)
            ->assertSet('payment.amount', '199')
            ->set('payment.reference', 'PX-2')->call('recordPayment')->assertHasNoErrors();

        $invoice->refresh();
        $this->assertSame(InvoiceStatus::Paid, $invoice->status);
        $this->assertNotNull($invoice->paid_at);
        $this->assertCount(2, $invoice->payments);
        Notification::assertSentTo($owner, PaymentReceived::class);
        $fails(fn () => $record->handle($invoice, 100, PaymentMethod::Other, null, $this->admin), 'amount');               // nothing owed
    }

    public function test_trials_convert_and_renewals_invoice_once_on_the_billing_day(): void
    {
        [, $org] = $this->business();
        Carbon::setTestNow('2026-01-31 09:00:00');
        app(ManageSubscription::class)->subscribe($org, $this->plan(['trial_days' => 14]), $this->admin);
        $subscription = Subscription::withoutGlobalScopes()->sole();
        $this->assertSame(SubscriptionStatus::Trialing, $subscription->status);
        $this->assertSame(0, Invoice::withoutGlobalScopes()->count(), 'nothing is charged during the trial');

        $this->assertSame(0, $this->runOn('2026-02-13')['trials_converted']);
        $this->assertSame(1, $this->runOn('2026-02-14')['trials_converted']);
        $this->assertSame(0, $this->runOn('2026-02-14')['trials_converted'], 'idempotent');
        $this->assertSame(1, Invoice::withoutGlobalScopes()->count());
        $this->assertSame('2026-03-14', $subscription->fresh()->current_period_end->toDateString());

        // Monthly renewals keep the billing day; a scheduled plan change applies at renewal.
        $pro = Plan::create(['slug' => 'pro', 'name' => 'Pro', 'price_cents' => 49900, 'interval' => 'month']);
        app(ManageSubscription::class)->changePlan($subscription->fresh(), $pro, $this->admin);
        $this->assertSame(29900, $subscription->fresh()->price_cents, 'not before renewal');
        $this->assertSame(1, $this->runOn('2026-03-14')['renewed']);
        $this->runOn('2026-03-14');
        $subscription->refresh();
        $this->assertSame($pro->id, $subscription->plan_id);
        $this->assertSame(49900, $subscription->price_cents);
        $this->assertSame(2, Invoice::withoutGlobalScopes()->count());
        $this->assertSame(49900, Invoice::withoutGlobalScopes()->latest('id')->first()->total_cents);
    }

    public function test_month_end_billing_days_do_not_drift(): void
    {
        $this->assertSame('2026-02-28', ManageSubscription::advance(CarbonImmutable::parse('2026-01-31'), 'month')->toDateString());
        $this->assertSame('2026-03-31', ManageSubscription::advance(CarbonImmutable::parse('2026-02-28'), 'month', 31)->toDateString());
        $this->assertSame('2027-01-31', ManageSubscription::advance(CarbonImmutable::parse('2026-01-31'), 'year')->toDateString());
    }

    public function test_overdue_invoices_get_a_few_spaced_reminders_and_cancellation_ends_at_period_end(): void
    {
        [$owner, $org] = $this->business();
        app(ManageSubscription::class)->subscribe($org, $this->plan(), $this->admin, withTrial: false);   // due Oct 17
        $subscription = Subscription::withoutGlobalScopes()->sole();

        $this->assertSame(0, $this->runOn('2026-10-17')['reminders']);
        $this->assertSame(1, $this->runOn('2026-10-18')['reminders']);
        $this->assertSame(SubscriptionStatus::PastDue, $subscription->fresh()->status);
        $this->assertSame(0, $this->runOn('2026-10-20')['reminders'], 'not again within a week');
        $this->assertSame(1, $this->runOn('2026-10-25')['reminders']);
        $this->runOn('2026-11-01');
        $this->runOn('2026-11-08');   // a fourth week: no more reminders
        Notification::assertSentToTimes($owner, InvoiceOverdue::class, 3);   // Oct 18, Oct 25, Nov 1 — then it stops
    }

    public function test_cancel_at_period_end_and_void(): void
    {
        [, $org] = $this->business();
        app(ManageSubscription::class)->subscribe($org, $this->plan(), $this->admin, withTrial: false);
        $subscription = Subscription::withoutGlobalScopes()->sole();
        app(ManageSubscription::class)->cancel($subscription, $this->admin);

        $this->assertSame(1, $this->runOn('2026-11-10')['ended']);
        $this->assertSame(SubscriptionStatus::Cancelled, $subscription->fresh()->status);
        $this->assertSame(1, Invoice::withoutGlobalScopes()->count(), 'no invoice after the end');

        $invoice = Invoice::withoutGlobalScopes()->sole();
        $this->actingAs($this->admin);
        Livewire::test(AdminBilling::class)->call('select', $invoice->ulid)->call('void')->assertHasErrors('voidReason')
            ->set('voidReason', 'Duplicate')->call('void')->assertHasNoErrors();
        $this->assertSame(InvoiceStatus::Void, $invoice->fresh()->status);
    }

    public function test_invoice_numbers_are_unique_and_restart_each_year(): void
    {
        $numbers = app(InvoiceNumbers::class);
        $this->assertSame('INV-2026-0001', $numbers->next());
        $this->assertSame('INV-2026-0002', $numbers->next());
        $this->assertSame('INV-2027-0001', $numbers->next(2027));
    }

    public function test_access_and_isolation(): void
    {
        [$owner, $org] = $this->business();
        [$other, $otherOrg] = $this->business('Bright Smile Dental');
        $manager = $this->member($org, 'manager');
        $staff = $this->member($org, 'staff');
        app(ManageSubscription::class)->subscribe($otherOrg, $this->plan(), $this->admin, withTrial: false);
        $theirs = Invoice::withoutGlobalScopes()->sole();
        $plan = Plan::sole();

        $this->actingAs($owner)->get(route('app.billing.invoice', $theirs))->assertNotFound();
        $this->get(route('app.billing.invoice.pdf', $theirs))->assertNotFound();
        $this->get(route('app.billing'))->assertOk()->assertDontSee($theirs->number);

        // Owners request plans; managers can look but not ask; staff never see billing.
        Livewire::test(ClientBilling::class)->call('requestPlan', $plan->id);
        Notification::assertSentTo($this->admin, PlanChangeRequested::class);
        $this->actingAs($manager)->get(route('app.billing'))->assertOk();
        Livewire::test(ClientBilling::class)->call('requestPlan', $plan->id)->assertForbidden();
        $mine = app(IssueInvoice::class)->issue($org, [['description' => 'Setup', 'quantity' => 1, 'unit_cents' => 5000]], actor: $this->admin);
        Livewire::test(ClientBilling::class)->assertDontSee('I\'ve paid')->call('startReport', $mine->ulid)->assertForbidden();
        $this->actingAs($staff)->get(route('app.billing'))->assertForbidden();
        Livewire::test(NotificationSettings::class)->assertDontSee('New invoice');
        $this->actingAs($owner);
        Livewire::test(NotificationSettings::class)->assertSee('New invoice');

        // Clients can't reach the billing desk.
        $this->actingAs($owner)->get(route('admin.billing'))->assertForbidden();
        $this->assertNotNull($other);
    }

    public function test_billing_settings_and_one_off_invoices(): void
    {
        [$owner, $org] = $this->business();
        $this->actingAs($this->admin);

        Livewire::test(AdminBilling::class)->set('tab', 'settings')
            ->set('settings.payoneer_payment_link', 'http://not-secure.example')->call('saveSettings')->assertHasErrors('settings.payoneer_payment_link')
            ->set('settings.payoneer_payment_link', 'https://link.payoneer.com/Token?t=X')->set('settings.payoneer_email', 'billing@surehelp.test')
            ->call('saveSettings')->assertHasNoErrors();
        $this->assertSame('billing@surehelp.test', app(BillingSettings::class)->get('payoneer_email'));

        Livewire::test(AdminBilling::class)
            ->set('oneOff.organization_id', (string) $org->id)->set('oneOff.description', 'Onboarding and script setup')->set('oneOff.amount', '150')
            ->call('issueOneOff')->assertHasNoErrors();
        $invoice = Invoice::withoutGlobalScopes()->sole();
        $this->assertSame(15000, $invoice->total_cents);
        $this->assertNull($invoice->subscription_id);
        Notification::assertSentTo($owner, InvoiceIssued::class);

        $this->expectException(ValidationException::class);
        app(IssueInvoice::class)->issue($org, [['description' => 'Nothing', 'quantity' => 1, 'unit_cents' => 0]]);
    }
}
