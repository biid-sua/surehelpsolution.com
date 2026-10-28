<?php

namespace Tests\Feature;

use App\Actions\Billing\ManageSubscription;
use App\Actions\Organizations\ProvisionUserTenancy;
use App\Enums\SubscriptionStatus;
use App\Livewire\Client\Billing\Index as ClientBilling;
use App\Livewire\Client\Dashboard;
use App\Livewire\Client\Tasks\Index as ClientTasks;
use App\Models\Appointment;
use App\Models\CallLog;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\PhoneNumber;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Task;
use App\Models\User;
use App\Notifications\TrialNotice;
use App\Services\Billing\BillingRun;
use App\Services\Billing\FeatureAccess;
use App\Services\Billing\Trials;
use App\Services\Telephony\AgentPresence;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Self-serve free trials (D97): sample data, keeping the plan, reminders, the trial ending into a
 * read-only account, starting a plan again, and the calls our receptionists answer during a trial.
 */
class TrialTest extends TestCase
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
        $this->plan = Plan::create(['slug' => 'receptionist', 'name' => 'Receptionist', 'price_cents' => 29900, 'trial_days' => 14, 'sort_order' => 1]);
        $this->bigger = Plan::create(['slug' => 'growth', 'name' => 'Growth', 'price_cents' => 49900, 'trial_days' => 14, 'sort_order' => 2]);
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
        ])->assertRedirect(route('verification.notice'));
        auth()->logout();
        $user = User::where('email', $email)->firstOrFail();
        $user->markEmailAsVerified();

        return [$user, Organization::where('owner_user_id', $user->id)->firstOrFail()];
    }

    private function runOn(string $date): array
    {
        Carbon::setTestNow($date.' 06:05:00');
        app()->forgetScopedInstances();

        return app(BillingRun::class)->run();
    }

    public function test_a_new_trial_starts_with_sample_data_that_contacts_nobody_and_never_counts(): void
    {
        [$owner, $business] = $this->signUp();

        $subscription = Subscription::withoutGlobalScopes()->sole();
        $this->assertSame(SubscriptionStatus::Trialing, $subscription->status);
        $this->assertTrue($subscription->confirm_by_trial_end);
        $this->assertTrue($subscription->awaitsConfirmation());

        $this->assertSame(6, Customer::withoutGlobalScopes()->where('organization_id', $business->id)->count());
        $this->assertSame(8, CallLog::withoutGlobalScopes()->where('organization_id', $business->id)->count());
        $this->assertSame(3, Appointment::withoutGlobalScopes()->where('organization_id', $business->id)->count());
        $this->assertSame(2, Task::withoutGlobalScopes()->where('organization_id', $business->id)->count());
        $this->assertTrue(Customer::withoutGlobalScopes()->where('organization_id', $business->id)->get()
            ->every(fn (Customer $c) => str_ends_with($c->email, '@example.com') && ! $c->sms_consent && ! $c->email_consent));
        $this->assertSame(0, app(Trials::class)->callsUsed($subscription), 'sample calls are dated before the trial');
        Mail::assertNothingQueued();
        Mail::assertNothingSent();

        $this->actingAs($owner)->get(route('app.dashboard'))->assertOk()
            ->assertSee('You&#039;re looking at sample data', false)->assertSee('Free trial: 14 days left');
    }

    public function test_the_owner_removes_the_sample_data_and_only_the_sample_data(): void
    {
        [$owner, $business] = $this->signUp();
        $real = Customer::create(['organization_id' => $business->id, 'first_name' => 'Real', 'last_name' => 'Person', 'phone' => '+15125550177']);
        [$other, $otherBusiness] = $this->signUp('bo@other.test');

        Livewire::actingAs($owner)->test(Dashboard::class)->call('removeSampleData')->assertDispatched('toast');

        $this->assertSame([$real->id], Customer::withoutGlobalScopes()->where('organization_id', $business->id)->pluck('id')->all());
        $this->assertSame(0, CallLog::withoutGlobalScopes()->where('organization_id', $business->id)->count());
        $this->assertSame(0, Appointment::withoutGlobalScopes()->where('organization_id', $business->id)->count());
        $this->assertNull($business->fresh()->sample_data);
        // The other business's sample data is untouched.
        $this->assertSame(8, CallLog::withoutGlobalScopes()->where('organization_id', $otherBusiness->id)->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'organization.sample_data_removed', 'organization_id' => $business->id]);

        $this->actingAs($owner)->get(route('app.dashboard'))->assertOk()->assertDontSee('looking at sample data');
    }

    public function test_keeping_the_plan_carries_on_after_the_trial_with_the_first_invoice(): void
    {
        [$owner, $business] = $this->signUp();

        Livewire::actingAs($owner)->test(ClientBilling::class)->assertSee('Keep this plan')
            ->call('keepPlan', $this->bigger->id)->assertDispatched('toast');

        $subscription = Subscription::withoutGlobalScopes()->sole();
        $this->assertNotNull($subscription->confirmed_at);
        $this->assertSame($this->bigger->id, $subscription->plan_id, 'switching during a trial happens now');
        $this->assertFalse($subscription->awaitsConfirmation());

        $report = $this->runOn('2026-11-16');
        $this->assertSame(1, $report['trials_converted']);
        $this->assertSame(0, $report['trials_ended']);
        $this->assertSame(SubscriptionStatus::Active, $subscription->fresh()->status);
        $this->assertSame(49900, Invoice::withoutGlobalScopes()->where('organization_id', $business->id)->sole()->total_cents);
    }

    public function test_reminders_go_out_three_days_and_one_day_before_once_each(): void
    {
        [$owner] = $this->signUp();

        $this->assertSame(0, $this->runOn('2026-11-12')['trial_reminders']);
        $this->assertSame(1, $this->runOn('2026-11-13')['trial_reminders']);
        $this->assertSame(0, $this->runOn('2026-11-13')['trial_reminders'], 'once');
        $this->assertSame(0, $this->runOn('2026-11-14')['trial_reminders']);
        $this->assertSame(1, $this->runOn('2026-11-15')['trial_reminders']);

        Notification::assertSentToTimes($owner, TrialNotice::class, 2);
        Notification::assertSentTo($owner, TrialNotice::class, fn (TrialNotice $n) => $n->kind === 'ending' && $n->daysLeft === 1);
    }

    public function test_an_unkept_trial_ends_into_a_read_only_account_and_a_plan_opens_it_again(): void
    {
        [$owner, $business] = $this->signUp();

        $report = $this->runOn('2026-11-16');
        $this->assertSame(1, $report['trials_ended']);
        $this->assertSame(0, $report['trials_converted']);
        $subscription = Subscription::withoutGlobalScopes()->sole();
        $this->assertSame(SubscriptionStatus::Cancelled, $subscription->status);
        $this->assertSame(Trials::ENDED, $subscription->ended_reason);
        $this->assertSame(0, Invoice::withoutGlobalScopes()->count(), 'nothing charged');
        Notification::assertSentTo($owner, TrialNotice::class, fn (TrialNotice $n) => $n->kind === 'ended');
        $this->assertTrue(app(Trials::class)->ended($business));
        config(['features.seo_service.default_mode' => 'everyone']);
        $this->assertFalse(app(FeatureAccess::class)->allows($business, 'seo_service'), 'features are off');

        // Looking still works; nothing has been deleted.
        $this->actingAs($owner)->get(route('app.dashboard'))->assertOk()->assertSee('Your free trial has ended');
        $this->assertSame(8, CallLog::withoutGlobalScopes()->where('organization_id', $business->id)->count());

        // Saving is held back, on the web and in the app.
        Livewire::actingAs($owner)->test(ClientTasks::class)->call('create')->assertDispatched('toast', type: 'warning');
        $this->actingAs($owner)->post(route('app.seo.search-console.disconnect'))
            ->assertRedirect(route('app.billing'))->assertSessionHas('trial_locked');
        Sanctum::actingAs($owner);
        $this->postJson('/api/v1/client/customers', ['first_name' => 'New', 'phone' => '+15125550123'])
            ->assertForbidden()->assertJsonPath('message', 'Your free trial has ended, so your account is read-only. Choose a plan under Billing to make changes.');
        $this->assertSame(6, Customer::withoutGlobalScopes()->where('organization_id', $business->id)->count());

        // Choosing a plan opens everything again, with the first invoice now.
        Livewire::actingAs($owner)->test(ClientBilling::class)->assertSee('Start this plan')
            ->call('startPlan', $this->plan->id)->assertRedirect(route('app.billing'));
        app()->forgetScopedInstances();
        $this->assertFalse(app(Trials::class)->ended($business));
        $this->assertSame(SubscriptionStatus::Active, Subscription::withoutGlobalScopes()->latest('id')->first()->status);
        $this->assertSame(1, Invoice::withoutGlobalScopes()->where('organization_id', $business->id)->count());
    }

    public function test_a_plan_is_kept_only_for_your_own_business_and_only_from_public_plans(): void
    {
        [$owner, $business] = $this->signUp();
        [, $otherBusiness] = $this->signUp('bo@other.test');

        Livewire::actingAs($owner)->test(ClientBilling::class)->call('keepPlan', $this->plan->id);

        $this->assertNotNull(Subscription::withoutGlobalScopes()->where('organization_id', $business->id)->sole()->confirmed_at);
        $this->assertNull(Subscription::withoutGlobalScopes()->where('organization_id', $otherBusiness->id)->sole()->confirmed_at);
        // A kept plan can't be "started" again, and a hidden plan can't be chosen.
        Livewire::actingAs($owner)->test(ClientBilling::class)->call('startPlan', $this->plan->id)->assertDispatched('toast', type: 'warning');
        $hidden = Plan::create(['slug' => 'hidden', 'name' => 'Hidden', 'price_cents' => 100, 'is_public' => false]);
        $this->expectException(ModelNotFoundException::class);
        Livewire::actingAs($owner)->test(ClientBilling::class)->call('startPlan', $hidden->id);
    }

    public function test_a_trial_answers_its_included_calls_then_sends_calls_to_the_business(): void
    {
        config(['signup.trial_calls' => 2]);
        [$owner, $business] = $this->signUp();
        $agent = User::factory()->create(['role' => 'agent', 'is_active' => true, 'must_change_password' => false]);
        $business->assignAgent($agent);
        app(AgentPresence::class)->set($agent, AgentPresence::AVAILABLE);
        PhoneNumber::create(['organization_id' => $business->id, 'provider' => 'fake', 'provider_sid' => 'PNtrial', 'number' => '+15125550100', 'transfer_number' => '+15125550199']);
        $ring = fn (string $sid) => $this->post('/api/webhooks/voice/incoming', ['CallSid' => $sid, 'From' => '+15125550147', 'To' => '+15125550100'])->assertOk()->getContent();

        $this->assertStringContainsString('<Client', $ring('CA1'));
        foreach ([1, 2] as $n) {
            CallLog::create(['call_id' => CallLog::generateCallId(), 'organization_id' => $business->id, 'call_date' => now()->toDateString(), 'call_time' => '10:00:00',
                'reason_for_call' => 'general-inquiry', 'call_outcome' => 'provided-information', 'agent_name' => $agent->name, 'status' => 'information-provided', 'user_id' => $agent->id]);
        }
        app()->forgetScopedInstances();
        $xml = $ring('CA2');
        $this->assertStringNotContainsString('<Client', $xml, 'the trial calls are used up');
        $this->assertStringContainsString('+15125550199', $xml, 'the business\'s own number rings');

        $this->actingAs($owner)->get(route('app.billing'))->assertSee('calls now go to your own number');

        // Keeping the plan has us answer again.
        Livewire::actingAs($owner)->test(ClientBilling::class)->call('keepPlan', $this->plan->id);
        app()->forgetScopedInstances();
        $this->assertStringContainsString('<Client', $ring('CA3'));
    }

    public function test_trials_staff_set_up_still_convert_on_their_own(): void
    {
        $owner = User::factory()->create(['role' => 'client', 'is_active' => true]);
        $business = app(ProvisionUserTenancy::class)->handle($owner);
        app(ManageSubscription::class)->subscribe($business, $this->plan, $owner);

        $this->assertSame(0, $this->runOn('2026-11-13')['trial_reminders']);
        $this->assertSame(1, $this->runOn('2026-11-16')['trials_converted']);
        $this->assertFalse(app(Trials::class)->ended($business));
        $this->assertNull($business->fresh()->sample_data);
    }
}
