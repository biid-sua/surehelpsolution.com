<?php

namespace Tests\Feature;

use App\Actions\Organizations\ProvisionUserTenancy;
use App\Livewire\Account\Profile;
use App\Livewire\Account\Security;
use App\Livewire\Admin\Users\Index as AdminUsers;
use App\Livewire\Client\Settings\Team;
use App\Models\LegalAcceptance;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\User;
use App\Notifications\Account\ResetPassword;
use App\Notifications\Account\TeamInvitation;
use App\Notifications\Account\VerifyEmail;
use App\Services\Account\TwoFactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/**
 * Accounts (D8, D24): password reset, two-step sign-in, idle timeout and signing out everywhere,
 * the profile page, team invitations and accepting the terms.
 */
class AccountSecurityTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role, array $attributes = []): User
    {
        return User::factory()->create(array_merge(['role' => $role, 'is_active' => true, 'must_change_password' => false, 'password' => 'Secret123!'], $attributes));
    }

    /** @return array{0: User, 1: Organization} */
    private function owner(): array
    {
        $owner = $this->user('client');
        $organization = app(ProvisionUserTenancy::class)->handle($owner);
        $organization->update(['name' => 'Rivera Plumbing']);

        return [$owner, $organization->fresh()];
    }

    // Signing in -------------------------------------------------------------------

    public function test_sign_in_page_replaces_the_website_popup_and_returns_people_where_they_were_going(): void
    {
        $this->get(route('home'))->assertOk()->assertSee(route('login'), false)->assertDontSee('loginModal');
        $this->get(route('login'))->assertOk()->assertSee('Sign in')->assertSee('Forgot password?');

        [$owner] = $this->owner();
        $this->get(route('app.customers.index'))->assertRedirect(route('login'));
        $this->post(route('auth.login'), ['email' => $owner->email, 'password' => 'nope'])->assertSessionHasErrors('email');
        $this->post(route('auth.login'), ['email' => $owner->email, 'password' => 'Secret123!'])->assertRedirect(route('app.customers.index'));
        $this->assertAuthenticatedAs($owner);

        $this->get(route('login'))->assertRedirect(route('app.dashboard'));
        $this->post(route('auth.logout'))->assertRedirect(route('login'));
        $this->get(route('login'))->assertSee('You\'re signed out.');
    }

    // Password reset -------------------------------------------------------------------

    public function test_forgot_password_never_reveals_accounts_and_a_reset_signs_out_everywhere(): void
    {
        Notification::fake();
        $agent = $this->user('agent', ['email_verified_at' => null]);
        $agent->createToken('phone');
        $off = $this->user('client', ['is_active' => false]);

        $this->post(route('password.email'), ['email' => 'nobody@example.test'])->assertSessionHas('status');
        $this->post(route('password.email'), ['email' => $off->email])->assertSessionHas('status');
        $this->post(route('password.email'), ['email' => $agent->email])->assertSessionHas('status');
        Notification::assertSentToTimes($agent, ResetPassword::class, 1);
        Notification::assertNotSentTo($off, ResetPassword::class);

        $token = null;
        Notification::assertSentTo($agent, ResetPassword::class, function (ResetPassword $n) use (&$token, $agent) {
            $token = $n->token;

            return str_contains($n->toMail($agent)->actionUrl, route('password.reset', $n->token));
        });

        $this->get(route('password.reset', ['token' => $token, 'email' => $agent->email]))->assertOk()->assertSee('Choose a new password');
        $this->post(route('password.store'), ['token' => 'wrong', 'email' => $agent->email, 'password' => 'NewSecret123', 'password_confirmation' => 'NewSecret123'])
            ->assertSessionHasErrors('email');
        $this->post(route('password.store'), ['token' => $token, 'email' => $agent->email, 'password' => 'NewSecret123', 'password_confirmation' => 'NewSecret123'])
            ->assertRedirect(route('login'));

        $agent->refresh();
        $this->assertTrue(Hash::check('NewSecret123', $agent->password));
        $this->assertSame(1, $agent->session_epoch);
        $this->assertSame(0, $agent->tokens()->count());
        $this->assertNotNull($agent->email_verified_at, 'the reset proves the inbox');
        $this->get(route('login'))->assertSee('Your password is changed.');
    }

    // Two-step sign-in -------------------------------------------------------------------

    public function test_setting_up_two_step_sign_in_and_signing_in_with_codes(): void
    {
        [$owner] = $this->owner();
        $this->actingAs($owner);

        $component = Livewire::test(Security::class)->call('startSetup')->assertSee('Scan this with an authenticator app');
        $secret = str_replace(' ', '', $component->viewData('secret'));
        $this->assertSame(32, strlen($secret));
        $this->assertStringNotContainsString($secret, (string) $component->get('pendingSecret'), 'kept encrypted in the page');
        $component->set('code', '000000')->call('confirmSetup')->assertHasErrors('code');
        $component->set('code', $this->twoFactorCode($secret))->call('confirmSetup')->assertHasNoErrors();

        $owner->refresh();
        $this->assertTrue($owner->hasTwoFactor());
        $codes = $component->get('freshCodes');
        $this->assertCount(8, $codes);
        $this->assertNotContains($codes[0], $owner->two_factor_recovery_codes, 'stored hashed');
        $this->assertStringNotContainsString($secret, (string) $owner->getRawOriginal('two_factor_secret'), 'stored encrypted');

        // Next sign-in needs the second step.
        $this->post(route('auth.logout'));
        $this->post(route('auth.login'), ['email' => $owner->email, 'password' => 'Secret123!'])->assertRedirect(route('two-factor.challenge'));
        $this->assertGuest();
        $this->get(route('app.dashboard'))->assertRedirect(route('login'));
        $this->post(route('two-factor.verify'), ['code' => '123456'])->assertSessionHasErrors('code');
        $this->assertGuest();

        // A recovery code works exactly once.
        $this->post(route('two-factor.verify'), ['recovery_code' => $codes[0]])->assertRedirect(route('app.dashboard'));
        $this->assertAuthenticatedAs($owner);
        $this->assertCount(7, $owner->fresh()->two_factor_recovery_codes);
        $this->post(route('auth.logout'));
        $this->post(route('auth.login'), ['email' => $owner->email, 'password' => 'Secret123!']);
        $this->post(route('two-factor.verify'), ['recovery_code' => $codes[0]])->assertSessionHasErrors('code');

        // An app code works, but the same code can't be replayed. (Setup used this 30-second window's code;
        // forgetting that stands in for waiting for the next window.)
        Cache::forget('two-factor:last:'.$owner->id);
        $code = $this->twoFactorCode($secret);
        $this->post(route('two-factor.verify'), ['code' => $code])->assertRedirect(route('app.dashboard'));
        $this->post(route('auth.logout'));
        $this->post(route('auth.login'), ['email' => $owner->email, 'password' => 'Secret123!']);
        $this->post(route('two-factor.verify'), ['code' => $code])->assertSessionHasErrors('code');

        // Owners may turn it off with their password.
        $this->actingAs($owner);
        Livewire::test(Security::class)->set('password', 'wrong')->call('disable')->assertHasErrors('password');
        Livewire::test(Security::class)->set('password', 'Secret123!')->call('disable')->assertHasNoErrors();
        $this->assertFalse($owner->fresh()->hasTwoFactor());
    }

    public function test_staff_and_agents_must_set_up_two_step_sign_in_on_web_and_app(): void
    {
        $agent = $this->user('agent');

        $this->post(route('auth.login'), ['email' => $agent->email, 'password' => 'Secret123!'])->assertRedirect(route('agent.home'));
        $this->get(route('agent.home'))->assertRedirect(route('account.security'));
        $this->get(route('account.security'))->assertOk()->assertSee('Set up two-step sign-in to continue');

        // The mobile app refuses until it's set up, then asks for the code.
        $this->postJson('/api/v1/login', ['email' => $agent->email, 'password' => 'Secret123!'])->assertForbidden()->assertJsonPath('two_factor_setup_required', true);
        $secret = $this->enableTwoFactor($agent);
        $this->app['auth']->forgetGuards();   // next request loads the updated account, as a real one would
        $this->get(route('agent.home'))->assertOk();
        $this->postJson('/api/v1/login', ['email' => $agent->email, 'password' => 'Secret123!'])->assertUnauthorized()->assertJsonPath('two_factor_required', true);
        $this->postJson('/api/v1/login', ['email' => $agent->email, 'password' => 'Secret123!', 'two_factor_code' => '000000'])->assertUnauthorized();
        $this->postJson('/api/v1/login', ['email' => $agent->email, 'password' => 'Secret123!', 'two_factor_code' => $this->twoFactorCode($secret)])->assertOk();

        // Required means it can't be switched off, only reset by a Super Admin.
        $this->actingAs($agent);
        Livewire::test(Security::class)->set('password', 'Secret123!')->call('disable')->assertForbidden();
        $this->actingAs($this->user('admin'));
        Livewire::test(AdminUsers::class)->call('resetTwoFactor', $agent->id);
        $agent->refresh();
        $this->assertFalse($agent->hasTwoFactor());
        $this->assertSame(0, $agent->tokens()->count());
    }

    // Sessions -------------------------------------------------------------------

    public function test_idle_staff_sessions_end_and_signing_out_everywhere_ends_other_sessions(): void
    {
        $admin = $this->user('admin');
        $this->enableTwoFactor($admin);
        $this->actingAs($admin)->withSession(['auth.last_activity' => now()->subMinutes(31)->timestamp])
            ->get(route('admin.home'))->assertRedirect(route('login'));
        $this->assertGuest();
        $this->get(route('login'))->assertSee('signed out after 30 minutes without activity');

        // Business owners have no idle limit.
        [$owner] = $this->owner();
        $this->actingAs($owner)->withSession(['auth.last_activity' => now()->subHours(3)->timestamp])->get(route('app.dashboard'))->assertOk();

        // An older session epoch means "signed out everywhere".
        $this->actingAs($owner)->withSession(['auth.epoch' => 0]);
        $owner->forceFill(['session_epoch' => 1])->save();
        $this->get(route('app.dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_profile_details_password_and_devices(): void
    {
        [$owner] = $this->owner();
        $owner->createToken('Pixel 9');
        $this->actingAs($owner)->get(route('account.profile'))->assertOk()->assertSee('Your account')->assertSee('Team');

        Livewire::test(Profile::class)->set('name', 'Maria R.')->set('timezone', 'America/Chicago')->call('saveDetails')->assertHasNoErrors();
        $this->assertSame('America/Chicago', $owner->fresh()->timezone);
        Livewire::test(Profile::class)->set('timezone', 'Mars/Base')->call('saveDetails')->assertHasErrors('timezone');

        Livewire::test(Profile::class)->set('currentPassword', 'wrong')->set('newPassword', 'NewSecret123')->set('newPassword_confirmation', 'NewSecret123')
            ->call('changePassword')->assertHasErrors('currentPassword');
        Livewire::test(Profile::class)->set('currentPassword', 'Secret123!')->set('newPassword', 'NewSecret123')->set('newPassword_confirmation', 'NewSecret123')
            ->call('changePassword')->assertHasNoErrors();
        $owner->refresh();
        $this->assertTrue(Hash::check('NewSecret123', $owner->password));
        $this->assertSame(1, $owner->session_epoch);
        $this->assertSame(0, $owner->tokens()->count());
        $this->assertSame(1, session('auth.epoch'), 'this browser stays signed in');

        $token = $owner->createToken('iPhone')->accessToken;
        Livewire::test(Security::class)->assertSee('iPhone')->call('revokeToken', $token->id);
        $this->assertSame(0, $owner->tokens()->count());
        Livewire::test(Security::class)->set('password', 'wrong')->call('signOutOthers')->assertHasErrors('password');
        Livewire::test(Security::class)->set('password', 'NewSecret123')->call('signOutOthers')->assertHasNoErrors();
        $this->assertSame(2, $owner->fresh()->session_epoch);
        Livewire::test(Security::class)->assertSee('Recent sign-in activity')->assertSee('Signed out of all devices');
    }

    public function test_email_confirmation_link(): void
    {
        Notification::fake();
        [$owner] = $this->owner();
        $owner->forceFill(['email_verified_at' => null])->save();

        $this->actingAs($owner)->post(route('account.verification.send'))->assertSessionHas('status');
        Notification::assertSentTo($owner, VerifyEmail::class);

        $url = URL::temporarySignedRoute('verification.verify', now()->addDay(), ['id' => $owner->id, 'hash' => sha1('someone@else.test')]);
        $this->get($url)->assertForbidden();
        $this->get(route('verification.verify', ['id' => $owner->id, 'hash' => sha1($owner->email)]))->assertForbidden();   // unsigned
        $url = URL::temporarySignedRoute('verification.verify', now()->addDay(), ['id' => $owner->id, 'hash' => sha1($owner->email)]);
        $this->get($url)->assertRedirect(route('account.profile'));
        $this->assertNotNull($owner->fresh()->email_verified_at);
    }

    // Team -------------------------------------------------------------------

    public function test_owner_invites_a_manager_who_joins_with_the_emailed_link(): void
    {
        Notification::fake();
        [$owner, $org] = $this->owner();
        $this->actingAs($owner)->get(route('app.settings.team'))->assertOk()->assertSee('Invite someone');

        Livewire::test(Team::class)->set('email', $owner->email)->call('invite')->assertHasErrors('email');
        Livewire::test(Team::class)->set('email', 'Sam@Rivera.test')->set('role', 'manager')->call('invite')->assertHasNoErrors();
        $invitation = OrganizationInvitation::sole();
        $this->assertSame('sam@rivera.test', $invitation->email);

        $token = null;
        Notification::assertSentOnDemand(TeamInvitation::class, function (TeamInvitation $n, array $channels, AnonymousNotifiable $to) use (&$token) {
            $token = $n->token;

            return $to->routes['mail'] === 'sam@rivera.test';
        });
        $this->assertSame(hash('sha256', $token), $invitation->token_hash, 'only the hash is stored');

        $this->post(route('auth.logout'));
        $this->get(route('invitations.show', 'not-a-token'))->assertOk()->assertSee('Invitation not valid');
        $this->get(route('invitations.show', $token))->assertOk()->assertSee('Join Rivera Plumbing')->assertSee('as manager');
        $this->post(route('invitations.accept', $token), ['name' => 'Sam Lee', 'password' => 'Secret123!', 'password_confirmation' => 'Secret123!'])
            ->assertSessionHasErrors('accept');
        $this->post(route('invitations.accept', $token), ['name' => 'Sam Lee', 'password' => 'Secret123!', 'password_confirmation' => 'Secret123!', 'accept' => '1'])
            ->assertRedirect(route('app.dashboard'));

        $sam = User::where('email', 'sam@rivera.test')->sole();
        $this->assertAuthenticatedAs($sam);
        $this->assertSame('manager', $sam->organizationRole($org));
        $this->assertNotNull($sam->email_verified_at);
        $this->assertSame(3, LegalAcceptance::where('user_id', $sam->id)->count());
        $this->assertNotNull($invitation->fresh()->accepted_at);
        $this->get(route('app.dashboard'))->assertOk();

        // Used links don't work twice.
        $this->post(route('auth.logout'));
        $this->get(route('invitations.show', $token))->assertSee('Invitation not valid');
    }

    public function test_team_roles_and_removal(): void
    {
        Notification::fake();
        [$owner, $org] = $this->owner();
        $manager = $this->user('client');
        $staff = $this->user('client');
        $org->members()->attach($manager->id, ['role' => 'manager', 'status' => 'active']);
        $org->members()->attach($staff->id, ['role' => 'staff', 'status' => 'active']);

        // Staff can't open the team page; managers invite staff only.
        $this->actingAs($staff)->get(route('app.settings.team'))->assertForbidden();
        $this->actingAs($manager);
        Livewire::test(Team::class)->set('email', 'x@rivera.test')->set('role', 'manager')->call('invite')->assertHasErrors('role');
        Livewire::test(Team::class)->set('email', 'x@rivera.test')->set('role', 'staff')->call('invite')->assertHasNoErrors();
        Livewire::test(Team::class)->call('remove', $staff->id)->assertForbidden();

        // Owners change roles and remove people; nobody touches the owner.
        $this->actingAs($owner);
        Livewire::test(Team::class)->call('revoke', OrganizationInvitation::sole()->id);
        $this->assertNotNull(OrganizationInvitation::sole()->revoked_at);
        Livewire::test(Team::class)->call('changeRole', $staff->id, 'manager');
        $this->assertSame('manager', $staff->fresh()->organizationRole($org));
        Livewire::test(Team::class)->call('remove', $owner->id)->assertForbidden();
        $staff->createToken('phone');
        Livewire::test(Team::class)->call('remove', $staff->id);
        $staff = $staff->fresh();
        $this->assertNull($staff->organizationRole($org));
        $this->assertFalse($staff->is_active);
        $this->assertSame(0, $staff->tokens()->count());
    }

    // Terms -------------------------------------------------------------------

    public function test_everyone_accepts_the_current_terms_and_again_when_they_change(): void
    {
        $owner = User::factory()->withoutLegalAcceptance()->create(['role' => 'client', 'is_active' => true, 'must_change_password' => false]);
        app(ProvisionUserTenancy::class)->handle($owner);

        $this->actingAs($owner)->get(route('app.calls.index'))->assertRedirect(route('account.terms'));
        $this->get(route('account.terms'))->assertOk()->assertSee('Terms of Use')->assertSee('Data Processing Addendum')->assertSee('Please read and accept');
        $this->post(route('account.terms.accept'))->assertSessionHasErrors('accept');
        $this->post(route('account.terms.accept'), ['accept' => '1'])->assertRedirect(route('app.calls.index'));
        $this->assertSame(['dpa', 'privacy', 'terms'], LegalAcceptance::where('user_id', $owner->id)->orderBy('document')->pluck('document')->all());
        $this->get(route('app.calls.index'))->assertOk();

        // A new version asks again, as an update.
        config(['account.legal.terms.version' => '2027-01-01']);
        $this->get(route('app.calls.index'))->assertRedirect(route('account.terms'));
        $this->get(route('account.terms'))->assertSee('We updated some of our terms')->assertDontSee('Privacy Policy');

        // Agents don't sign the business DPA.
        $agent = User::factory()->withoutLegalAcceptance()->create(['role' => 'agent', 'is_active' => true]);
        $this->enableTwoFactor($agent);
        $this->actingAs($agent)->get(route('account.terms'))->assertDontSee('Data Processing Addendum');
    }

    public function test_totp_engine_accepts_only_current_codes(): void
    {
        $engine = app(Google2FA::class);
        $secret = $engine->generateSecretKey(32);
        $old = $engine->oathTotp($secret, $engine->getTimestamp() - 10);

        $this->assertFalse(app(TwoFactor::class)->verify($secret, $old, 99));
        $this->assertTrue(app(TwoFactor::class)->verify($secret, $engine->getCurrentOtp($secret), 99));
    }
}
