<?php

namespace Tests\Feature;

use App\Actions\Billing\ManageSubscription;
use App\Actions\Organizations\ProvisionUserTenancy;
use App\Enums\OrganizationStatus;
use App\Models\CreditPurchase;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Billing\AiCredits;
use App\Services\Billing\BillingRun;
use App\Services\Billing\Trials;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Mobile API: the free trial (D97) and AI credits (D100) in the app's Billing screen.
 */
class MobileApiTrialCreditsTest extends TestCase
{
    use RefreshDatabase;

    private Plan $plan;

    private Plan $bigger;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Mail::fake();
        Carbon::setTestNow(CarbonImmutable::parse('2026-11-02 15:00', 'UTC'));
        $this->plan = Plan::create(['slug' => 'receptionist', 'name' => 'Receptionist', 'price_cents' => 29900, 'trial_days' => 14, 'sort_order' => 1,
            'description' => "Live answering 24/7\nAppointment booking"]);
        $this->bigger = Plan::create(['slug' => 'growth', 'name' => 'Growth', 'price_cents' => 49900, 'trial_days' => 14, 'sort_order' => 2, 'is_featured' => true]);
        Plan::create(['slug' => 'enterprise', 'name' => 'Enterprise', 'price_cents' => 0, 'contact_only' => true, 'sort_order' => 3]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array{0: User, 1: Organization} */
    private function signUp(string $email = 'ana@rivera.test'): array
    {
        $this->post(route('signup.store'), [
            'business' => 'Rivera Plumbing', 'name' => 'Ana Rivera', 'email' => $email, 'phone' => '+15125550100',
            'password' => 'long-secret-1', 'password_confirmation' => 'long-secret-1', 'plan' => 'receptionist', 'accept' => '1',
        ]);
        auth()->logout();
        $user = User::where('email', $email)->firstOrFail();
        $user->markEmailAsVerified();

        return [$user, Organization::where('owner_user_id', $user->id)->firstOrFail()];
    }

    /** @return array{0: User, 1: Organization} */
    private function subscribed(string $name): array
    {
        $owner = User::factory()->create(['role' => 'client', 'is_active' => true, 'must_change_password' => false]);
        $business = app(ProvisionUserTenancy::class)->handle($owner);
        $business->update(['name' => $name, 'status' => OrganizationStatus::Active]);
        app(ManageSubscription::class)->subscribe($business, $this->plan, $owner, withTrial: false);

        return [$owner, $business->fresh()];
    }

    private function member(Organization $business, string $role): User
    {
        $user = User::factory()->create(['role' => 'client', 'is_active' => true, 'must_change_password' => false]);
        $business->members()->attach($user->id, ['role' => $role, 'status' => 'active']);

        return $user;
    }

    public function test_the_app_shows_the_trial_and_the_owner_keeps_a_plan(): void
    {
        [$owner] = $this->signUp();
        Sanctum::actingAs($owner);

        $trial = $this->getJson('/api/v1/client/billing')->assertOk()->json('data.trial');
        $this->assertSame(['status' => 'active', 'ends_on' => '2026-11-16', 'days_left' => 14, 'calls_included' => 25, 'calls_used' => 0, 'plan_id' => $this->plan->id], $trial);

        $plans = $this->getJson('/api/v1/client/billing/plans')->assertOk()->json('data.plans');
        $this->assertSame(['Receptionist', 'Growth'], array_column($plans, 'name'), 'plans priced on request are not offered');
        $this->assertSame([true, false], array_column($plans, 'current'));
        $this->assertSame(['Live answering 24/7', 'Appointment booking'], $plans[0]['highlights']);

        $this->postJson('/api/v1/client/billing/trial/keep', ['plan_id' => $this->bigger->id])->assertOk()
            ->assertJsonPath('message', 'Thank you! Growth carries on after your trial. Nothing is charged until then.');
        $subscription = Subscription::withoutGlobalScopes()->sole();
        $this->assertNotNull($subscription->confirmed_at);
        $this->assertSame($this->bigger->id, $subscription->plan_id);
        $this->assertNull($this->getJson('/api/v1/client/billing')->json('data.trial'), 'nothing left to keep');
        $this->postJson('/api/v1/client/billing/trial/keep', ['plan_id' => $this->plan->id])->assertNotFound();
    }

    public function test_after_the_trial_ended_the_owner_starts_a_plan_from_the_app(): void
    {
        [$owner, $business] = $this->signUp();
        Carbon::setTestNow('2026-11-16 06:05:00');
        app()->forgetScopedInstances();
        app(BillingRun::class)->run();
        $this->assertTrue(app(Trials::class)->ended($business));

        Sanctum::actingAs($owner);
        $this->getJson('/api/v1/client/billing')->assertOk()->assertJsonPath('data.trial.status', 'ended')->assertJsonPath('data.plan', null);
        // The account is read-only, but choosing a plan still works.
        $this->postJson('/api/v1/client/customers', ['name' => 'New customer'])->assertForbidden();
        $this->postJson('/api/v1/client/billing/trial/keep', ['plan_id' => $this->plan->id])->assertNotFound();

        $invoice = $this->postJson('/api/v1/client/billing/trial/start', ['plan_id' => $this->plan->id])->assertCreated()
            ->assertJsonPath('message', 'Welcome back! Receptionist is on.')->json('data.invoice');
        $this->assertSame(29900, $invoice['total_cents']);
        app()->forgetScopedInstances();
        $this->assertFalse(app(Trials::class)->ended($business));
        $this->getJson('/api/v1/client/billing')->assertJsonPath('data.trial', null)->assertJsonPath('data.plan.name', 'Receptionist');
        $this->postJson('/api/v1/client/billing/trial/start', ['plan_id' => $this->plan->id])->assertStatus(422);
    }

    public function test_plans_priced_on_request_or_unknown_cannot_be_chosen(): void
    {
        [$owner] = $this->signUp();
        Sanctum::actingAs($owner);
        $enterprise = Plan::where('slug', 'enterprise')->firstOrFail();

        $this->postJson('/api/v1/client/billing/trial/keep', ['plan_id' => $enterprise->id])->assertNotFound();
        $this->postJson('/api/v1/client/billing/trial/keep', [])->assertStatus(422);
        $this->assertNull(Subscription::withoutGlobalScopes()->sole()->confirmed_at);
    }

    public function test_owners_buy_credits_and_see_their_balance(): void
    {
        [$owner, $business] = $this->subscribed('Rivera Plumbing');
        app(AiCredits::class)->grant($business, 30, 'Welcome gift', User::factory()->create(['role' => 'admin']));
        Sanctum::actingAs($owner);

        $credits = $this->getJson('/api/v1/client/billing')->assertOk()->json('data.credits');
        $this->assertSame(30, $credits['balance']);
        $this->assertSame([0, 1, 2], array_column($credits['packs'], 'pack'));
        $this->assertSame(['pack' => 1, 'credits' => 500, 'price_cents' => 4000], $credits['packs'][1]);

        $response = $this->postJson('/api/v1/client/billing/credits', ['pack' => 1])->assertCreated();
        $invoice = $response->json('data.invoice');
        $this->assertSame(4000, $invoice['total_cents']);
        $this->assertStringContainsString('credits are added as soon as it\'s paid', $response->json('message'));
        $this->assertSame([['credits' => 500, 'invoice_id' => $invoice['id']]], $this->getJson('/api/v1/client/billing')->json('data.credits.pending'));
        $this->assertSame(30, app(AiCredits::class)->balance($business), 'nothing until it is paid');

        $this->postJson('/api/v1/client/billing/credits', ['pack' => 99])->assertStatus(422);
    }

    public function test_only_owners_buy_and_credits_stay_with_their_business(): void
    {
        [$owner, $business] = $this->subscribed('Rivera Plumbing');
        [$otherOwner, $other] = $this->subscribed('Other Co');
        app(AiCredits::class)->buy($other, 0, $otherOwner);

        Sanctum::actingAs($this->member($business, 'manager'));
        $this->getJson('/api/v1/client/billing')->assertOk()->assertJsonPath('data.credits.pending', []);
        $this->postJson('/api/v1/client/billing/credits', ['pack' => 0])->assertForbidden();
        $this->postJson('/api/v1/client/billing/trial/keep', ['plan_id' => $this->plan->id])->assertForbidden();

        Sanctum::actingAs($owner);
        $this->getJson('/api/v1/client/billing')->assertJsonPath('data.credits.balance', 0)->assertJsonPath('data.credits.pending', []);
        $this->assertSame(1, CreditPurchase::withoutGlobalScopes()->count(), 'only the other business bought');
    }
}
