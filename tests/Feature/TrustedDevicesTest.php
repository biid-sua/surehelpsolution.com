<?php

namespace Tests\Feature;

use App\Livewire\Account\Security;
use App\Livewire\Admin\Users\Index as AdminUsers;
use App\Models\TrustedDevice;
use App\Models\User;
use App\Services\Account\TrustedDevices;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/**
 * "Remember this device for 30 days" at the two-step sign-in (D103): the code is skipped in that
 * browser only, the password never is, and devices can be listed, forgotten and are forgotten
 * when the password changes or two-step sign-in is reset.
 */
class TrustedDevicesTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';

    private function user(string $role = 'client', string $email = 'ana@rivera.test'): User
    {
        return User::factory()->create(['role' => $role, 'email' => $email, 'is_active' => true, 'must_change_password' => false, 'password' => 'Secret123!',
            'two_factor_secret' => self::SECRET, 'two_factor_confirmed_at' => now(), 'two_factor_recovery_codes' => []]);
    }

    private function code(User $user): string
    {
        Cache::forget('two-factor:last:'.$user->id);   // a fresh 30-second window, not a replay

        return app(Google2FA::class)->getCurrentOtp(self::SECRET);
    }

    /** Password, then the code with or without "remember"; returns the response that set the cookie. */
    private function signInWithCode(User $user, bool $remember): TestResponse
    {
        $this->post(route('auth.login'), ['email' => $user->email, 'password' => 'Secret123!'])->assertRedirect(route('two-factor.challenge'));

        return $this->post(route('two-factor.verify'), array_filter(['code' => $this->code($user), 'remember_device' => $remember ? '1' : null]));
    }

    private function cookieFrom(TestResponse $response): string
    {
        return (string) $response->getCookie(TrustedDevices::COOKIE)?->getValue();
    }

    public function test_a_remembered_browser_skips_the_code_but_never_the_password(): void
    {
        $user = $this->user();
        $this->get(route('login'));
        $this->post(route('auth.login'), ['email' => $user->email, 'password' => 'Secret123!']);
        $this->get(route('two-factor.challenge'))->assertOk()->assertSee('Remember this device for 30 days');

        $response = $this->post(route('two-factor.verify'), ['code' => $this->code($user), 'remember_device' => '1']);
        $response->assertRedirect()->assertCookie(TrustedDevices::COOKIE);
        $cookie = $this->cookieFrom($response);
        $device = TrustedDevice::query()->sole();
        $this->assertSame(hash('sha256', $cookie), $device->token_hash, 'only a hash is stored');
        $this->assertTrue($device->expires_at->between(now()->addDays(29), now()->addDays(31)));
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.device_trusted']);

        // Next time, in this browser: password only.
        $this->post(route('auth.logout'));
        $this->withCookie(TrustedDevices::COOKIE, $cookie)
            ->post(route('auth.login'), ['email' => $user->email, 'password' => 'Secret123!'])->assertRedirect($user->homeUrl());
        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.login', 'subject_id' => $user->id, 'new_values->method' => 'trusted_device']);

        // The wrong password is still refused.
        $this->post(route('auth.logout'));
        $this->withCookie(TrustedDevices::COOKIE, $cookie)
            ->post(route('auth.login'), ['email' => $user->email, 'password' => 'wrong-password'])->assertSessionHasErrors();
        $this->assertGuest();

        // Another browser, without the cookie, still needs the code.
        $this->defaultCookies = [];
        $this->post(route('auth.login'), ['email' => $user->email, 'password' => 'Secret123!'])->assertRedirect(route('two-factor.challenge'));
    }

    public function test_without_ticking_the_box_nothing_is_remembered(): void
    {
        $user = $this->user();
        $this->signInWithCode($user, false)->assertRedirect()->assertCookieMissing(TrustedDevices::COOKIE);
        $this->assertSame(0, TrustedDevice::query()->count());
    }

    public function test_a_cookie_only_works_for_its_own_person_and_until_it_expires(): void
    {
        $ana = $this->user();
        $ben = $this->user('client', 'ben@other.test');
        $cookie = $this->cookieFrom($this->signInWithCode($ana, true));
        $this->post(route('auth.logout'));

        // Ben's password with Ana's cookie in the same browser: Ben still needs his code.
        $this->withCookie(TrustedDevices::COOKIE, $cookie)
            ->post(route('auth.login'), ['email' => $ben->email, 'password' => 'Secret123!'])->assertRedirect(route('two-factor.challenge'));
        $this->assertGuest();

        // A made-up cookie does nothing.
        $this->withCookie(TrustedDevices::COOKIE, str_repeat('a', 64))
            ->post(route('auth.login'), ['email' => $ana->email, 'password' => 'Secret123!'])->assertRedirect(route('two-factor.challenge'));

        // After 30 days the code is needed again, and the expired record is pruned.
        $this->travel(31)->days();
        $this->withCookie(TrustedDevices::COOKIE, $cookie)
            ->post(route('auth.login'), ['email' => $ana->email, 'password' => 'Secret123!'])->assertRedirect(route('two-factor.challenge'));
        $this->artisan('model:prune', ['--model' => [TrustedDevice::class]])->assertSuccessful();
        $this->assertSame(0, TrustedDevice::query()->count());
    }

    public function test_people_see_and_forget_their_remembered_devices(): void
    {
        $ana = $this->user();
        $ben = $this->user('client', 'ben@other.test');
        $cookie = $this->cookieFrom($this->signInWithCode($ana, true));
        $this->post(route('auth.logout'));
        $this->signInWithCode($ana, true);
        $this->post(route('auth.logout'));
        $this->signInWithCode($ben, true);
        [$first] = TrustedDevice::query()->where('user_id', $ana->id)->orderBy('id')->get();
        $bens = TrustedDevice::query()->where('user_id', $ben->id)->sole();

        $this->actingAs($ana);
        Livewire::test(Security::class)->assertSee('Remembered devices')->assertSee('Forget all devices');

        // Someone else's device can't be touched.
        try {
            Livewire::test(Security::class)->call('forgetDevice', $bens->id);
            $this->fail('another person\'s device was found');
        } catch (ModelNotFoundException) {
        }
        $this->assertModelExists($bens);

        Livewire::test(Security::class)->call('forgetDevice', $first->id)->assertDispatched('toast', type: 'success');
        $this->assertModelMissing($first);
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.device_forgotten']);
        Livewire::test(Security::class)->call('forgetAllDevices');
        $this->assertSame(0, TrustedDevice::query()->where('user_id', $ana->id)->count());
        $this->assertModelExists($bens);

        // The forgotten browser's cookie no longer skips the code.
        $this->assertFalse(app(TrustedDevices::class)->recognises($ana, Request::create('/login', 'POST', cookies: [TrustedDevices::COOKIE => $cookie])));
    }

    public function test_resetting_two_step_or_signing_out_everywhere_forgets_every_device(): void
    {
        $agent = $this->user('agent', 'john@surehelp.test');
        $this->signInWithCode($agent, true);
        $this->post(route('auth.logout'));
        $this->assertSame(1, TrustedDevice::query()->count());

        $this->actingAs($this->user('admin', 'admin@surehelp.test'));
        Livewire::test(AdminUsers::class)->call('resetTwoFactor', $agent->id);
        $this->assertSame(0, TrustedDevice::query()->where('user_id', $agent->id)->count());

        // An owner turning two-step sign-in off forgets theirs too.
        $owner = $this->user('client', 'owner@rivera.test');
        $this->post(route('auth.logout'));
        $this->signInWithCode($owner, true);
        $this->actingAs($owner);
        Livewire::test(Security::class)->set('password', 'Secret123!')->call('disable')->assertHasNoErrors();
        $this->assertSame(0, TrustedDevice::query()->where('user_id', $owner->id)->count());
    }

    public function test_the_option_can_be_switched_off(): void
    {
        config(['account.two_factor.remember_days' => 0]);
        $user = $this->user();
        $this->post(route('auth.login'), ['email' => $user->email, 'password' => 'Secret123!']);
        $this->get(route('two-factor.challenge'))->assertOk()->assertDontSee('Remember this device');
        $this->post(route('two-factor.verify'), ['code' => $this->code($user), 'remember_device' => '1'])->assertCookieMissing(TrustedDevices::COOKIE);
        $this->assertSame(0, TrustedDevice::query()->count());
    }
}
