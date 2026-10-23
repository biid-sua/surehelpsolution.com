<?php

namespace Tests\Feature;

use App\Livewire\Admin\SystemStatus;
use App\Models\User;
use App\Services\System\LaunchReadiness;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Go-live readiness (D79): `launch:check` and Admin › System status.
 */
class LaunchReadinessTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, string> label => status */
    private function statuses(): array
    {
        return collect(app(LaunchReadiness::class)->checks())->mapWithKeys(fn ($c) => [$c['label'] => $c['status']])->all();
    }

    public function test_it_flags_what_blocks_launch_and_what_is_merely_off(): void
    {
        config(['app.env' => 'local', 'app.debug' => true, 'app.url' => 'http://localhost', 'mail.default' => 'log', 'ai.anthropic.api_key' => null]);
        $s = $this->statuses();
        $this->assertSame(['fail', 'fail', 'fail', 'ok', 'ok'], [$s['Production mode'], $s['Site address uses HTTPS'], $s['Emails are really sent'], $s['Database'], $s['Database up to date']]);
        $this->assertSame('fail', $s['Scheduler (cron) is running'], 'never seen');
        $this->assertSame('off', $s['AI (assistant, sales chat, SEO, social writer)']);
        $this->artisan('launch:check')->assertFailed()->expectsOutputToContain('Not ready to go live yet');

        config(['app.env' => 'production', 'app.debug' => false, 'app.url' => 'https://surehelpsolution.com', 'mail.default' => 'smtp',
            'mail.from.address' => 'hello@surehelpsolution.com', 'ai.anthropic.api_key' => 'sk-test', 'telephony.driver' => 'twilio',
            'telephony.twilio.account_sid' => 'AC1', 'telephony.twilio.auth_token' => 't', 'telephony.twilio.api_key' => 'SK1', 'telephony.twilio.api_secret' => 's', 'telephony.twilio.twiml_app_sid' => 'AP1']);
        Cache::put('scheduler:heartbeat', now()->timestamp);
        $s = $this->statuses();
        $this->assertSame(['ok', 'ok', 'ok', 'ok', 'ok', 'ok'], [$s['Production mode'], $s['Site address uses HTTPS'], $s['Emails are really sent'], $s['Sender address'],
            $s['Scheduler (cron) is running'], $s['Phone calls (Twilio)']]);
        $this->assertSame('ok', $s['AI (assistant, sales chat, SEO, social writer)']);
        $this->assertSame('off', $s['Text messages (SMS)'], 'texting waits for A2P approval');

        $this->assertStringNotContainsString('sk-test', json_encode(app(LaunchReadiness::class)->checks()), 'never prints secrets');
    }

    public function test_it_lists_the_addresses_to_register_and_sends_a_test_email(): void
    {
        config(['app.url' => 'https://surehelpsolution.com']);
        $this->artisan('launch:check --urls')->assertSuccessful()
            ->expectsOutputToContain(route('webhooks.stripe'))
            ->expectsOutputToContain(route('app.integrations.calendar.callback', 'google'));

        Mail::fake();
        $this->artisan('launch:check --mail=ops@surehelp.test');
        Mail::assertSentCount(1);
    }

    public function test_admins_see_system_status(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true, 'must_change_password' => false]);
        $this->actingAs($admin)->get(route('admin.system'))->assertOk()
            ->assertSee('Fix before launch')->assertSee('Addresses to register')->assertSee(route('webhooks.voice.outgoing'));

        Mail::fake();
        Livewire::test(SystemStatus::class)->set('testEmail', 'ops@surehelp.test')->call('sendTestEmail')->assertHasNoErrors();
        Mail::assertSentCount(1);

        $agent = User::factory()->create(['role' => 'agent', 'is_active' => true, 'must_change_password' => false]);
        $this->actingAs($agent)->get(route('admin.system'))->assertForbidden();
    }
}
