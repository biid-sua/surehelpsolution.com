<?php

namespace Tests\Feature;

use App\Actions\Billing\IssueInvoice;
use App\Actions\Billing\ManageSubscription;
use App\Actions\Billing\RecordPayment;
use App\Actions\Organizations\ProvisionUserTenancy;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Livewire\Client\Billing\Index as ClientBilling;
use App\Models\BillingCredit;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Referral;
use App\Models\Subscription;
use App\Models\User;
use App\Notifications\ReferralRewarded;
use App\Services\Billing\Referrals;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The referral programme (D90).
 */
class ReferralsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Organization $referrer;

    private Plan $plan;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->travelTo(CarbonImmutable::parse('2026-10-12 15:00', 'UTC'));
        $this->plan = Plan::create(['slug' => 'receptionist', 'name' => 'Receptionist', 'price_cents' => 29900, 'interval' => 'month', 'trial_days' => 0, 'sort_order' => 1]);
        $this->owner = User::factory()->create(['role' => 'client', 'is_active' => true, 'must_change_password' => false]);
        $this->referrer = app(ProvisionUserTenancy::class)->handle($this->owner);
        $this->referrer->forceFill(['name' => 'Rivera Plumbing', 'status' => 'active', 'setup_completed_at' => now()])->save();
        app(ManageSubscription::class)->subscribe($this->referrer, $this->plan, $this->owner, withTrial: false);
    }

    private function signUpWith(string $code, string $email = 'bo@bright.test'): Organization
    {
        $this->get(route('signup', ['ref' => $code]))->assertOk();
        $this->post(route('signup.store'), ['business' => 'Bright Electric', 'name' => 'Bo Bright', 'email' => $email, 'password' => 'long-secret-1',
            'password_confirmation' => 'long-secret-1', 'plan' => 'receptionist', 'accept' => '1'])->assertRedirect(route('verification.notice'));
        auth()->logout();

        return User::where('email', $email)->firstOrFail()->primaryOrganization();
    }

    public function test_a_referred_business_paying_earns_a_free_month(): void
    {
        $code = app(Referrals::class)->code($this->referrer);
        $bright = $this->signUpWith($code);
        $referral = Referral::query()->sole();
        $this->assertSame([$this->referrer->id, $bright->id, 'signed_up'], [$referral->referrer_organization_id, $referral->referred_organization_id, $referral->status]);

        $invoice = Invoice::withoutGlobalScopes()->where('organization_id', $bright->id)->sole();
        app(RecordPayment::class)->handle($invoice, $invoice->total_cents, PaymentMethod::Other, 'BANK-1', null);
        $this->assertSame(['rewarded', 29900], [$referral->fresh()->status, $referral->fresh()->credit_cents]);
        Notification::assertSentTo($this->owner, ReferralRewarded::class);
        $this->assertSame(29900, BillingCredit::query()->sole()->remaining());

        // Rivera's next month is free, marked paid, with the credit line on the invoice.
        $subscription = Subscription::withoutGlobalScopes()->where('organization_id', $this->referrer->id)->sole();
        $subscription->forceFill(['current_period_start' => now()->addMonth(), 'current_period_end' => now()->addMonths(2)])->save();
        $next = app(IssueInvoice::class)->forPeriod($subscription->fresh());
        $this->assertSame([0, InvoiceStatus::Paid], [$next->total_cents, $next->status]);
        $this->assertSame(-29900, $next->items()->where('unit_cents', '<', 0)->value('amount_cents'));
        $this->assertSame(0, BillingCredit::query()->sole()->remaining());

        // Paying again doesn't reward twice.
        $one = app(IssueInvoice::class)->issue($bright->fresh(), [['description' => 'Extra', 'quantity' => 1, 'unit_cents' => 5000]]);
        app(RecordPayment::class)->handle($one, 5000, PaymentMethod::Other, 'BANK-2', null);
        $this->assertSame(1, BillingCredit::query()->count());

        $this->actingAs($this->owner);
        Livewire::test(ClientBilling::class)->assertSee('Refer a business, get a month free')->assertSee('ref='.$code);
    }

    public function test_unknown_or_own_codes_are_ignored(): void
    {
        $this->signUpWith('NOPE1234');
        $this->assertSame(0, Referral::query()->count());
        $this->assertNull(app(Referrals::class)->signedUp($this->referrer, app(Referrals::class)->code($this->referrer)));
    }
}
