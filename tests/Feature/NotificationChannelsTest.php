<?php

namespace Tests\Feature;

use App\Actions\Organizations\ProvisionUserTenancy;
use App\Enums\NotificationEvent;
use App\Livewire\Account\NotificationDevices;
use App\Livewire\Client\Settings\Notifications as ClientNotifications;
use App\Models\FcmToken;
use App\Models\User;
use App\Notifications\Concerns\RespectsQuietHours;
use App\Services\Account\NotificationPhone;
use App\Services\Telephony\FakeProvider;
use App\Services\Telephony\TelephonyProvider;
use App\Support\NotificationChannels;
use Illuminate\Bus\Queueable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Notification texts and phone push (D63): which channels work, verifying the mobile number,
 * what is sent, FCM HTTP v1 and its dead-device cleanup.
 */
class NotificationChannelsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create(['role' => 'client', 'is_active' => true, 'must_change_password' => false, 'name' => 'Elena Rivera']);
        app(ProvisionUserTenancy::class)->handle($this->owner);
    }

    private function fake(): FakeProvider
    {
        $provider = app(TelephonyProvider::class);
        $this->assertInstanceOf(FakeProvider::class, $provider);

        return $provider;
    }

    private function enableTexts(): void
    {
        config(['telephony.notify_from' => '+15125559000']);
    }

    private function enablePush(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $pem);
        config(['fcm.credentials' => json_encode(['type' => 'service_account', 'project_id' => 'surehelp-app', 'client_email' => 'push@surehelp-app.iam.gserviceaccount.com',
            'private_key' => $pem, 'token_uri' => 'https://oauth2.googleapis.com/token'])]);
    }

    private function verifiedPhone(User $user): void
    {
        $user->forceFill(['sms_phone' => '+15125550147', 'sms_phone_verified_at' => now()])->save();
    }

    public function test_texts_and_push_stay_off_until_set_up(): void
    {
        $this->assertSame(['database', 'mail'], NotificationChannels::enabledKeys());
        $this->assertSame(['database', 'mail'], $this->owner->notificationChannelsFor(NotificationEvent::CallMissed), 'push is a default, but only once it works');

        $this->actingAs($this->owner)->get(route('app.settings.notifications'))->assertOk()->assertSee('Not available yet')
            ->assertSee("Notification texts aren't available yet.", false);

        $this->enableTexts();
        $this->enablePush();
        $this->assertSame(['database', 'mail', 'sms', 'push'], NotificationChannels::enabledKeys());
        $this->assertSame(['database', 'mail', 'push'], $this->owner->notificationChannelsFor(NotificationEvent::CallMissed), 'texts are opt-in');
    }

    public function test_people_verify_their_mobile_number_with_a_texted_code(): void
    {
        $this->enableTexts();
        $this->actingAs($this->owner);

        $page = Livewire::test(NotificationDevices::class)->set('phone', '+44 20 7946 0000')->call('sendCode')->assertHasErrors('phone');
        $page->set('phone', '(512) 555-0147')->call('sendCode')->assertHasNoErrors()->assertSet('codeSent', true);
        $text = $this->fake()->texts[0];
        $this->assertSame(['+15125559000', '+15125550147'], [$text['from'], $text['to']]);
        preg_match('/(\d{6})/', $text['body'], $m);

        $page->set('code', '000000')->call('verify')->assertHasErrors('code');
        $this->assertNull($this->owner->fresh()->sms_phone_verified_at);
        $page->set('code', $m[1])->call('verify')->assertHasNoErrors();
        $this->assertNotNull($this->owner->fresh()->sms_phone_verified_at);
        $this->assertSame('+15125550147', $this->owner->fresh()->routeNotificationForSms());
        $this->assertDatabaseHas('audit_logs', ['action' => 'account.notification_phone_verified']);

        Livewire::test(NotificationDevices::class)->assertSee('(512) 555-0147')->call('removePhone');
        $this->assertNull($this->owner->fresh()->routeNotificationForSms());
    }

    public function test_codes_expire_lock_after_five_tries_and_are_rate_limited(): void
    {
        $this->enableTexts();
        $this->actingAs($this->owner);
        $page = Livewire::test(NotificationDevices::class)->set('phone', '5125550147')->call('sendCode');
        preg_match('/(\d{6})/', $this->fake()->texts[0]['body'], $m);

        foreach (range(1, 5) as $i) {
            $page->set('code', '111111')->call('verify');
        }
        $page->set('code', $m[1])->call('verify')->assertHasErrors('code');
        $this->assertNull($this->owner->fresh()->sms_phone_verified_at, 'locked after 5 wrong codes');

        $page->call('sendCode')->call('sendCode');
        $page->set('codeSent', false)->call('sendCode')->assertHasErrors('phone');
        $this->assertCount(3, $this->fake()->texts);

        $this->travel(11)->minutes();
        $this->assertFalse(app(NotificationPhone::class)->verify($this->owner->fresh(), $m[1]), 'expired');
    }

    public function test_a_notification_goes_by_text_and_push_in_its_short_form(): void
    {
        $this->enableTexts();
        $this->enablePush();
        $this->verifiedPhone($this->owner);
        FcmToken::create(['user_id' => $this->owner->id, 'token' => 'tok-ok', 'platform' => 'ios']);
        FcmToken::create(['user_id' => $this->owner->id, 'token' => 'tok-gone', 'platform' => 'android']);
        $other = User::factory()->create(['role' => 'client', 'is_active' => true]);
        FcmToken::create(['user_id' => $other->id, 'token' => 'someone-elses', 'platform' => 'ios']);
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'ya29.test', 'expires_in' => 3599]),
            'fcm.googleapis.com/*' => function (HttpRequest $request) {
                return $request['message']['token'] === 'tok-gone'
                    ? Http::response(['error' => ['status' => 'NOT_FOUND', 'details' => [['errorCode' => 'UNREGISTERED']]]], 404)
                    : Http::response(['name' => 'projects/surehelp-app/messages/1']);
            },
        ]);

        $this->owner->notify(new ChannelProbe);
        $this->owner->notify(new ChannelProbe);

        $this->assertSame('SureHelp: Missed call from Maria Lopez. Maria called your SureHelp line. '.url('/app/tasks'), $this->fake()->texts[0]['body']);
        $this->assertSame(['+15125559000', '+15125550147'], [$this->fake()->texts[0]['from'], $this->fake()->texts[0]['to']]);

        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/v1/projects/surehelp-app/messages:send') && $r->hasHeader('Authorization', 'Bearer ya29.test')
            && $r['message']['token'] === 'tok-ok' && $r['message']['notification']['title'] === 'Missed call from Maria Lopez' && $r['message']['data']['url'] === url('/app/tasks')
            && $r['message']['data']['route'] === 'app.tasks.index' && $r['message']['data']['route_params'] === '[]' && filled($r['message']['data']['notification_id'] ?? null));
        Http::assertNotSent(fn (HttpRequest $r) => ($r['message']['token'] ?? null) === 'someone-elses');
        $this->assertCount(1, Http::recorded(fn (HttpRequest $r) => str_contains($r->url(), 'oauth2')), 'the access token is cached');
        $this->assertSame(['tok-ok'], FcmToken::query()->where('user_id', $this->owner->id)->pluck('token')->all(), 'Firebase forgot it: removed');
        Cache::flush();
    }

    public function test_nothing_is_texted_to_an_unverified_number(): void
    {
        $this->enableTexts();
        $this->owner->forceFill(['sms_phone' => '+15125550147'])->save();
        $this->owner->notify(new ChannelProbe);
        $this->assertSame([], $this->fake()->texts);
    }

    public function test_quiet_hours_hold_texts_and_push_too(): void
    {
        $this->owner->forceFill(['quiet_hours_start' => '00:00', 'quiet_hours_end' => '23:59'])->save();
        $delays = (new ChannelProbe)->withDelay($this->owner->fresh());
        $this->assertEqualsCanonicalizing(['mail', 'sms', 'push'], array_keys($delays));
    }

    public function test_texting_stop_to_the_notification_number_ends_notification_texts(): void
    {
        $this->enableTexts();
        $this->verifiedPhone($this->owner);
        $this->post('/api/webhooks/sms/incoming', ['MessageSid' => 'SM1', 'From' => '+15125550147', 'To' => '+15125559000', 'Body' => 'STOP'])->assertOk();
        $this->assertNull($this->owner->fresh()->routeNotificationForSms());
    }

    public function test_choosing_texts_for_an_event_in_settings(): void
    {
        $this->enableTexts();
        $this->actingAs($this->owner);
        Livewire::test(ClientNotifications::class)->set('preferences.CallMissed.sms', true)->call('save')->assertHasNoErrors();
        $this->assertContains('sms', $this->owner->fresh()->notificationChannelsFor(NotificationEvent::CallMissed));
    }

    public function test_the_app_removes_its_device_when_signing_out(): void
    {
        FcmToken::create(['user_id' => $this->owner->id, 'token' => 'tok-1']);
        Sanctum::actingAs($this->owner);
        $this->deleteJson('/api/v1/notifications/device-token', ['token' => 'tok-1'])->assertOk();
        $this->assertSame(0, FcmToken::query()->count());
    }
}

/** A notification sent on the new channels only, shaped like the real ones (title, body, url). */
class ChannelProbe extends Notification
{
    use Queueable, RespectsQuietHours;

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['sms', 'push'];
    }

    /** @return array<string, string> */
    public function toArray(object $notifiable): array
    {
        return ['event' => 'call.missed', 'title' => 'Missed call from Maria Lopez', 'body' => 'Maria called your SureHelp line', 'url' => '/app/tasks'];
    }
}
