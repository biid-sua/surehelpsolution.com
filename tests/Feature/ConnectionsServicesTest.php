<?php

namespace Tests\Feature;

use App\Livewire\Admin\Connections;
use App\Models\User;
use App\Services\Billing\Gateways\PaymentGateway;
use App\Services\Billing\Gateways\StripeGateway;
use App\Services\Connections\Connections as Store;
use App\Services\Telephony\TelephonyProvider;
use App\Services\Telephony\TwilioProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Admin › Connections for every outside service (D105): Twilio, email, Stripe, calendars, app
 * notifications and websites are set up from the admin console, tested, and used straight away.
 */
class ConnectionsServicesTest extends TestCase
{
    use RefreshDatabase;

    // Made-up IDs in Twilio's format, built here so secret scanners don't mistake them for real ones.
    private const SID = 'AC'.self::HEX;

    private const KEY = 'SK'.self::HEX;

    private const HEX = '0a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d';

    private function superAdmin(): User
    {
        $user = User::factory()->create(['role' => 'admin', 'is_active' => true, 'must_change_password' => false]);
        $this->enableTwoFactor($user);

        return $user;
    }

    public function test_every_tab_opens_and_shows_the_addresses_to_paste(): void
    {
        $admin = $this->superAdmin();
        foreach (array_keys(Store::TABS) as $tab) {
            $this->actingAs($admin)->get(route('admin.connections', ['tab' => $tab]))->assertOk();
        }
        $this->actingAs($admin)->get(route('admin.connections', ['tab' => 'payments']))->assertSee('Webhook signing secret')->assertSee(route('webhooks.stripe'));
        $this->actingAs($admin)->get(route('admin.connections', ['tab' => 'calendars']))->assertSee(route('app.integrations.calendar.callback', 'microsoft'));
        $this->actingAs($admin)->get(route('admin.connections', ['tab' => 'phone']))->assertSee(route('webhooks.voice.outgoing'));
        $this->actingAs($admin)->get(route('admin.connections', ['tab' => 'nonsense']))->assertOk()->assertSee('Claude API key');
    }

    public function test_twilio_set_up_in_the_admin_makes_calls_real(): void
    {
        config(['telephony.driver' => 'fake']);
        $this->actingAs($this->superAdmin());

        Livewire::test(Connections::class)->set('values.twilio_account_sid', 'not-a-sid')->call('save', 'twilio')
            ->assertHasErrors('values.twilio_account_sid');
        Livewire::test(Connections::class)->set('values.telephony_notify_from', '512-555-0100')->call('save', 'twilio')
            ->assertHasErrors('values.telephony_notify_from');

        Livewire::test(Connections::class)->set('tab', 'phone')
            ->set('values.telephony_driver', 'twilio')
            ->set('values.twilio_account_sid', self::SID)->set('values.twilio_auth_token', 'auth-token-1234')
            ->set('values.twilio_api_key', self::KEY)->set('values.twilio_api_secret', 'api-secret-5678')
            ->set('values.telephony_sms', true)
            ->call('save', 'twilio')->assertHasNoErrors()->assertSee('Saved: ••••1234');

        $this->assertSame('twilio', config('telephony.driver'));
        $this->assertTrue(config('telephony.sms'));
        $this->app->forgetInstance(TelephonyProvider::class);
        $provider = app(TelephonyProvider::class);
        $this->assertInstanceOf(TwilioProvider::class, $provider);
        $this->assertTrue($provider->isConfigured());
        $this->assertStringNotContainsString('auth-token', (string) DB::table('platform_secrets')->pluck('value')->implode(' '));
        $this->assertNotNull(Cache::get('illuminate:queue:restart'), 'workers pick the new keys up');
    }

    public function test_the_twilio_test_checks_both_the_account_and_the_api_key(): void
    {
        Http::fake(['api.twilio.com/*' => Http::sequence()
            ->push(['friendly_name' => 'SureHelp', 'status' => 'active'])
            ->push(['message' => 'Authenticate'], 401)
            ->push(['friendly_name' => 'SureHelp', 'status' => 'active'])]);
        $this->actingAs($this->superAdmin());

        Livewire::test(Connections::class)
            ->set('values.twilio_account_sid', self::SID)->set('values.twilio_auth_token', 'tok')
            ->set('values.twilio_api_key', self::KEY)->set('values.twilio_api_secret', 'wrong')
            ->call('test', 'twilio')->assertDispatched('toast', type: 'warning');
        Http::assertSent(fn (Request $r) => $r->url() === 'https://api.twilio.com/2010-04-01/Accounts/'.self::SID.'.json' && $r->hasHeader('Authorization', 'Basic '.base64_encode(self::SID.':tok')));

        Livewire::test(Connections::class)
            ->set('values.twilio_account_sid', self::SID)->set('values.twilio_auth_token', 'tok')
            ->call('test', 'twilio')->assertDispatched('toast', type: 'success', message: 'Twilio accepted them: account "SureHelp" (active).');
    }

    public function test_stripe_set_up_in_the_admin_takes_card_payments(): void
    {
        config(['billing.gateway' => 'payoneer']);
        Http::fake(['api.stripe.com/v1/balance' => Http::sequence()
            ->push(['error' => ['message' => 'Invalid API Key provided']], 401)
            ->push(['livemode' => false, 'available' => []])]);
        $this->actingAs($this->superAdmin());

        Livewire::test(Connections::class)->set('values.stripe_secret', 'sk_test_bad')->call('test', 'stripe')
            ->assertDispatched('toast', type: 'warning', message: 'Stripe refused the key: Invalid API Key provided');
        Livewire::test(Connections::class)->set('values.stripe_secret', 'sk_test_good')->call('test', 'stripe')
            ->assertDispatched('toast', type: 'success', message: 'Stripe accepted the key (test mode: no real money).');

        Livewire::test(Connections::class)->set('values.stripe_connect_fee_percent', '35')->call('save', 'stripe')
            ->assertHasErrors('values.stripe_connect_fee_percent');
        Livewire::test(Connections::class)
            ->set('values.billing_gateway', 'stripe')->set('values.stripe_secret', 'sk_test_good')->set('values.stripe_webhook_secret', 'whsec_abc')
            ->set('values.stripe_connect_fee_percent', '2.5')
            ->call('save', 'stripe')->assertHasNoErrors();

        $this->assertSame(2.5, config('billing.stripe.connect_fee_percent'));
        $this->assertInstanceOf(StripeGateway::class, app(PaymentGateway::class));
    }

    public function test_email_settings_apply_and_the_test_email_goes_to_the_person_testing(): void
    {
        $admin = $this->superAdmin();
        $this->actingAs($admin);
        Livewire::test(Connections::class)->set('values.mail_from_address', 'not an email')->call('save', 'mail')->assertHasErrors('values.mail_from_address');

        Livewire::test(Connections::class)
            ->set('values.mail_mailer', 'smtp')->set('values.mail_host', 'smtp.example.test')->set('values.mail_port', '465')
            ->set('values.mail_scheme', 'smtps')->set('values.mail_password', 'smtp-pass')->set('values.mail_from_address', 'hello@surehelp.test')
            ->call('save', 'mail')->assertHasNoErrors();
        $this->assertSame(465, config('mail.mailers.smtp.port'));
        $this->assertSame('smtps', config('mail.mailers.smtp.scheme'));
        $this->assertSame('hello@surehelp.test', config('mail.from.address'));

        // The test uses what's in use (the array mailer here) and goes only to the admin.
        config(['mail.default' => 'array']);
        Livewire::test(Connections::class)->call('test', 'mail')->assertDispatched('toast', type: 'success', message: 'Test email sent to '.$admin->email.'. If it doesn\'t arrive in a few minutes, check the spam folder and the settings.');
        $sent = app('mailer')->getSymfonyTransport()->messages();
        $this->assertCount(1, $sent);
        $this->assertSame($admin->email, $sent[0]->getOriginalMessage()->getTo()[0]->getAddress());
    }

    public function test_removing_a_saved_value_goes_back_to_the_server_setting(): void
    {
        $this->actingAs($this->superAdmin());
        Livewire::test(Connections::class)
            ->set('values.microsoft_calendar_client_id', 'ms-client')->set('values.microsoft_calendar_client_secret', 'ms-secret')
            ->set('values.microsoft_calendar_tenant', 'organizations')
            ->call('save', 'microsoft_calendar');
        $this->assertSame('organizations', config('calendar.providers.microsoft.tenant'));
        $this->assertSame('ms-secret', config('calendar.providers.microsoft.client_secret'));

        Livewire::test(Connections::class)->call('remove', 'microsoft_calendar_client_secret');
        $this->assertSame(env('MICROSOFT_CALENDAR_CLIENT_SECRET'), config('calendar.providers.microsoft.client_secret'));
        $this->assertSame('organizations', config('calendar.providers.microsoft.tenant'), 'other saved values stay');
    }

    public function test_google_business_uses_the_calendar_client_unless_it_has_its_own(): void
    {
        config(['social.connectors.google.client_id' => null, 'social.connectors.google.client_secret' => null]);
        $this->actingAs($this->superAdmin());
        Livewire::test(Connections::class)
            ->set('values.google_calendar_client_id', 'cal.apps.googleusercontent.com')->set('values.google_calendar_client_secret', 'cal-secret')
            ->call('save', 'google_calendar');

        $this->assertSame('cal.apps.googleusercontent.com', config('calendar.providers.google.client_id'));
        $this->assertSame('cal.apps.googleusercontent.com', config('social.connectors.google.client_id'));
        $this->assertSame('cal-secret', config('social.connectors.google.client_secret'));
    }

    public function test_app_notifications_and_websites(): void
    {
        $this->actingAs($this->superAdmin());
        Livewire::test(Connections::class)->set('values.fcm_credentials', 'not json')->call('save', 'fcm')->assertHasErrors('values.fcm_credentials');
        Livewire::test(Connections::class)->call('test', 'fcm')->assertDispatched('toast', type: 'warning', message: 'Save the service account key first.');

        Livewire::test(Connections::class)->set('values.mobile_android_latest', 'v2')->call('save', 'mobile')->assertHasErrors('values.mobile_android_latest');
        Livewire::test(Connections::class)->set('values.mobile_android_latest', '1.4.0')->set('values.mobile_android_sha256', 'AA:BB, CC:DD')->call('save', 'mobile')->assertHasNoErrors();
        $this->assertSame('1.4.0', config('mobile.android.latest'));
        $this->assertSame(['AA:BB', 'CC:DD'], config('mobile.app_links.android_sha256'));
        Livewire::test(Connections::class)->assertSet('values.mobile_android_sha256', 'AA:BB, CC:DD');

        Livewire::test(Connections::class)->set('values.sites_domain', 'not a domain')->set('values.sites_ip', '999.1.1.1')->call('save', 'sites')
            ->assertHasErrors(['values.sites_domain', 'values.sites_ip']);
        Livewire::test(Connections::class)->set('values.sites_domain', 'surehelp.site')->set('values.sites_domain_driver', 'cloudflare')->call('save', 'sites')->assertHasNoErrors();
        $this->assertSame('surehelp.site', config('sites.domain'));

        Http::fake(['api.cloudflare.com/client/v4/user/tokens/verify' => Http::response(['result' => ['status' => 'active']]),
            'api.cloudflare.com/client/v4/zones/*' => Http::response(['result' => ['name' => 'surehelp.site']])]);
        Livewire::test(Connections::class)->set('values.cloudflare_token', 'cf-token')->set('values.cloudflare_zone', 'zone123')->call('test', 'sites')
            ->assertDispatched('toast', type: 'success', message: 'Cloudflare accepted the token for surehelp.site.');
    }

    public function test_only_super_admins_test_or_change_anything(): void
    {
        $manager = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $manager->syncRoles(['operations_manager']);
        $this->actingAs($manager);
        Livewire::test(Connections::class)->assertForbidden();
        $this->assertSame([], app(Store::class)->saved());
    }
}
