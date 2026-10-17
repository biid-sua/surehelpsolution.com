<?php

namespace Tests\Feature;

use App\Actions\Organizations\ProvisionUserTenancy;
use App\Enums\OrganizationStatus;
use App\Enums\SubscriptionStatus;
use App\Models\LegalAcceptance;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Notifications\Account\VerifyEmail;
use App\Notifications\NewSignup;
use App\Services\Account\LegalDocuments;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Self-serve sign-up (D65): the /start form, the free trial, email confirmation before the portal,
 * staff notice, and abuse protection (spam trap, rate limit, off switch).
 */
class SignupTest extends TestCase
{
    use RefreshDatabase;

    private Plan $plan;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->plan = Plan::create(['slug' => 'receptionist', 'name' => 'Receptionist', 'price_cents' => 29900, 'trial_days' => 14, 'sort_order' => 2]);
        Plan::create(['slug' => 'starter', 'name' => 'Starter', 'price_cents' => 14900, 'trial_days' => 14, 'sort_order' => 1]);
        Plan::create(['slug' => 'hidden', 'name' => 'Hidden', 'price_cents' => 100, 'is_public' => false]);
    }

    /** @return array<string, mixed> */
    private function form(array $overrides = []): array
    {
        return array_merge([
            'business' => 'Rivera Plumbing',
            'name' => 'Ana Rivera',
            'email' => 'Ana@Rivera.test',
            'phone' => '+15125550100',
            'password' => 'long-secret-1',
            'password_confirmation' => 'long-secret-1',
            'plan' => 'receptionist',
            'accept' => '1',
        ], $overrides);
    }

    public function test_the_page_lists_public_plans_and_preselects_the_one_asked_for(): void
    {
        $this->get(route('signup', ['plan' => 'receptionist']))
            ->assertOk()
            ->assertSee('Starter')->assertSee('Receptionist')->assertDontSee('Hidden')
            ->assertSee('value="receptionist" checked', false)
            ->assertSee('14-day free trial');
    }

    public function test_a_business_signs_up_and_starts_its_free_trial(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);

        $this->post(route('signup.store'), $this->form())->assertRedirect(route('verification.notice'));

        $user = User::where('email', 'ana@rivera.test')->firstOrFail();
        $this->assertAuthenticatedAs($user);
        $this->assertSame('client', $user->role);
        $this->assertFalse($user->hasVerifiedEmail());
        $this->assertFalse($user->requiresPasswordChange());

        $business = Organization::where('owner_user_id', $user->id)->firstOrFail();
        $this->assertSame('Rivera Plumbing', $business->name);
        $this->assertSame('rivera-plumbing', $business->slug);
        $this->assertSame(OrganizationStatus::Onboarding, $business->status);
        $this->assertNotNull($business->signed_up_at);

        $subscription = Subscription::query()->forOrganization($business)->firstOrFail();
        $this->assertSame(SubscriptionStatus::Trialing, $subscription->status);
        $this->assertSame($this->plan->id, $subscription->plan_id);

        $this->assertTrue(LegalAcceptance::where('user_id', $user->id)->exists());
        $this->assertDatabaseHas('audit_logs', ['action' => 'organization.signed_up', 'organization_id' => $business->id]);
        Notification::assertSentTo($user, VerifyEmail::class);
        Notification::assertSentTo($admin, NewSignup::class, fn (NewSignup $n) => $n->organization->is($business));
    }

    public function test_the_portal_waits_for_the_email_to_be_confirmed(): void
    {
        $this->post(route('signup.store'), $this->form());
        $user = User::where('email', 'ana@rivera.test')->firstOrFail();

        $this->get(route('app.dashboard'))->assertRedirect(route('verification.notice'));
        $this->get(route('verification.notice'))->assertOk()->assertSee('ana@rivera.test');
        $this->post(route('account.verification.send'))->assertSessionHas('status');

        $link = URL::temporarySignedRoute('verification.verify', now()->addHour(), ['id' => $user->id, 'hash' => sha1($user->email)]);
        $this->get($link)->assertRedirect(route('app.dashboard'));   // where they were going
        $this->assertTrue($user->fresh()->hasVerifiedEmail());

        $this->actingAs($user->fresh());   // a real next request loads the confirmed user
        $this->get(route('app.dashboard'))->assertOk();
        $this->get(route('verification.notice'))->assertRedirect();
    }

    public function test_the_mobile_app_also_waits_for_the_email_to_be_confirmed(): void
    {
        $this->post(route('signup.store'), $this->form());
        auth()->logout();
        $user = User::where('email', 'ana@rivera.test')->firstOrFail();

        $this->postJson('/api/v1/login', ['email' => 'ana@rivera.test', 'password' => 'long-secret-1'])
            ->assertForbidden()->assertJson(['email_verification_required' => true]);

        $user->markEmailAsVerified();
        $this->postJson('/api/v1/login', ['email' => 'ana@rivera.test', 'password' => 'long-secret-1'])->assertOk();
    }

    public function test_businesses_set_up_by_staff_are_not_asked_to_confirm_their_email(): void
    {
        $owner = User::factory()->create(['role' => 'client', 'is_active' => true, 'must_change_password' => false, 'email_verified_at' => null]);
        app(ProvisionUserTenancy::class)->handle($owner);
        app(LegalDocuments::class)->accept($owner);

        $this->actingAs($owner)->get(route('app.dashboard'))->assertOk();
    }

    public function test_the_form_is_validated(): void
    {
        User::factory()->create(['email' => 'taken@rivera.test']);

        $this->post(route('signup.store'), $this->form(['email' => 'taken@rivera.test']))->assertSessionHasErrors('email');
        $this->post(route('signup.store'), $this->form(['plan' => 'hidden']))->assertSessionHasErrors('plan');
        $this->post(route('signup.store'), $this->form(['accept' => null]))->assertSessionHasErrors('accept');
        $this->post(route('signup.store'), $this->form(['password_confirmation' => 'other']))->assertSessionHasErrors('password');
        $this->assertSame(0, Organization::whereNotNull('signed_up_at')->count());
        $this->assertGuest();
    }

    public function test_bots_that_fill_the_hidden_field_create_nothing(): void
    {
        $this->post(route('signup.store'), $this->form(['website' => 'http://spam.test']))->assertRedirect(route('login'));

        $this->assertDatabaseMissing('users', ['email' => 'ana@rivera.test']);
        $this->assertGuest();
    }

    public function test_sign_ups_are_rate_limited_and_can_be_switched_off(): void
    {
        config(['signup.per_hour' => 2]);
        $this->post(route('signup.store'), $this->form(['email' => 'one@rivera.test', 'accept' => null]));
        $this->post(route('signup.store'), $this->form(['email' => 'two@rivera.test', 'accept' => null]));
        $this->post(route('signup.store'), $this->form(['email' => 'three@rivera.test']))->assertStatus(429);

        config(['signup.enabled' => false]);
        $this->get(route('signup'))->assertOk()->assertSee('Sign-ups are closed');
    }

    public function test_signed_in_people_are_sent_home_and_the_site_links_here(): void
    {
        $owner = User::factory()->create(['role' => 'client', 'is_active' => true, 'must_change_password' => false]);
        $this->actingAs($owner)->get(route('signup'))->assertRedirect();
        auth()->logout();

        $this->get(route('login'))->assertSee(route('signup'), false);
        $this->get(route('home'))->assertSee(route('signup', ['plan' => 'essential']), false);
    }
}
