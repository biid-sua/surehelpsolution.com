<?php

namespace Tests\Feature;

use App\Actions\Organizations\ProvisionUserTenancy;
use App\Enums\NotificationEvent;
use App\Models\AuditLog;
use App\Models\CallLog;
use App\Models\NotificationPreference;
use App\Models\Organization;
use App\Models\User;
use App\Models\VoiceCall;
use App\Services\Telephony\FakeProvider;
use App\Services\Telephony\TelephonyProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * More API for the mobile app (D71): notification settings and the texting number (API-3), the
 * update check (API-5), call details with a signed recording link (API-6) and Results (API-10).
 */
class MobileApiMoreTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Organization $business;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create(['role' => 'client', 'is_active' => true, 'must_change_password' => false]);
        $this->business = app(ProvisionUserTenancy::class)->handle($this->owner);
        $this->business->update(['name' => 'Rivera Plumbing', 'timezone' => 'America/Chicago']);
    }

    private function member(string $role): User
    {
        $user = User::factory()->create(['role' => 'client', 'is_active' => true, 'must_change_password' => false]);
        $this->business->members()->attach($user->id, ['role' => $role, 'status' => 'active']);

        return $user;
    }

    public function test_notification_settings_match_the_web_and_save(): void
    {
        Sanctum::actingAs($this->owner);
        $data = $this->getJson('/api/v1/notifications/settings')->assertOk()->json('data');
        $this->assertContains('database', array_column($data['channels'], 'key'));
        $missed = collect($data['events'])->firstWhere('key', NotificationEvent::CallMissed->value);
        $this->assertNotNull($missed);
        $this->assertNotEmpty($missed['label']);
        $this->assertNotNull($data['daily_summary']);
        $this->assertFalse($data['sms_phone']['verified']);

        $this->putJson('/api/v1/notifications/settings', [
            'events' => [NotificationEvent::CallMissed->value => ['database']],
            'quiet_hours' => ['start' => '21:00', 'end' => '07:00'],
            'daily_summary' => '07:30',
        ])->assertOk()->assertJsonPath('data.quiet_hours.start', '21:00')->assertJsonPath('data.daily_summary.time', '07:30');
        $this->assertSame(['database'], NotificationPreference::query()->where('user_id', $this->owner->id)->where('event', 'call.missed')->value('channels'));

        $this->putJson('/api/v1/notifications/settings', ['quiet_hours' => null])->assertOk()->assertJsonPath('data.quiet_hours', null);
        $this->putJson('/api/v1/notifications/settings', ['events' => ['call.missed' => ['carrier_pigeon']]])->assertStatus(422);
        $this->putJson('/api/v1/notifications/settings', ['quiet_hours' => ['start' => '21:00', 'end' => '21:00']])->assertStatus(422);

        // Staff don't get billing alerts to choose from; agents have their workforce events and no daily summary.
        Sanctum::actingAs($this->member('staff'));
        $keys = array_column($this->getJson('/api/v1/notifications/settings')->json('data.events'), 'key');
        $this->assertNotContains(NotificationEvent::InvoiceIssued->value, $keys);
        $this->putJson('/api/v1/notifications/settings', ['events' => [NotificationEvent::InvoiceIssued->value => ['database']]])->assertStatus(422);

        Sanctum::actingAs(User::factory()->create(['role' => 'agent', 'is_active' => true]));
        $data = $this->getJson('/api/v1/notifications/settings')->assertOk()->json('data');
        $this->assertNull($data['daily_summary']);
        $this->putJson('/api/v1/notifications/settings', ['daily_summary' => '07:30'])->assertStatus(422);
    }

    public function test_the_texting_number_is_verified_with_a_code(): void
    {
        config(['telephony.notify_from' => '+15125559000']);
        $fake = app(TelephonyProvider::class);
        $this->assertInstanceOf(FakeProvider::class, $fake);

        Sanctum::actingAs($this->owner);
        $this->postJson('/api/v1/account/sms-phone', ['phone' => '12'])->assertStatus(422)->assertJsonValidationErrors('phone');
        $this->postJson('/api/v1/account/sms-phone', ['phone' => '(512) 555-0147'])->assertOk()
            ->assertJsonPath('data.number', '+15125550147')->assertJsonPath('data.verified', false);
        preg_match('/\b(\d{6})\b/', end($fake->texts)['body'], $m);

        $this->postJson('/api/v1/account/sms-phone/verify', ['code' => '000000'])->assertStatus(422);
        $this->postJson('/api/v1/account/sms-phone/verify', ['code' => $m[1]])->assertOk()->assertJsonPath('data.verified', true);
        $this->getJson('/api/v1/notifications/settings')->assertJsonPath('data.sms_phone.verified', true);

        $this->deleteJson('/api/v1/account/sms-phone')->assertOk()->assertJsonPath('data.number', null);
    }

    public function test_the_app_learns_whether_it_must_or_may_update(): void
    {
        config(['mobile.ios' => ['minimum' => '1.4.0', 'latest' => '1.6.2', 'store_url' => 'https://apps.apple.com/app/id123']]);

        $this->getJson('/api/v1/app/version?platform=ios&version=1.3.9')->assertOk()
            ->assertJsonPath('data.update_required', true)->assertJsonPath('data.update_available', true)
            ->assertJsonPath('data.store_url', 'https://apps.apple.com/app/id123');
        $this->getJson('/api/v1/app/version?platform=ios&version=1.5.0')->assertJsonPath('data.update_required', false)->assertJsonPath('data.update_available', true);
        $this->getJson('/api/v1/app/version?platform=ios&version=1.6.2')->assertJsonPath('data.update_available', false);
        $this->getJson('/api/v1/app/version?platform=ios')->assertJsonPath('data.minimum', '1.4.0')->assertJsonPath('data.update_required', false);
        $this->getJson('/api/v1/app/version?platform=windows')->assertStatus(422);
    }

    public function test_call_details_with_a_signed_recording_link_for_people_allowed_to_hear_it(): void
    {
        $this->app->instance(TelephonyProvider::class, new class extends FakeProvider
        {
            public function recordingAudio(string $recordingSid): ?string
            {
                return 'ID3-mp3-bytes';
            }
        });
        $log = CallLog::create(['user_id' => User::factory()->create(['role' => 'agent'])->id, 'call_id' => CallLog::generateCallId(), 'organization_id' => $this->business->id, 'call_date' => now()->toDateString(), 'call_time' => '09:00',
            'caller_name' => 'Maria Lopez', 'caller_phone' => '+15125550147', 'reason_for_call' => 'Leak', 'call_outcome' => 'booked', 'agent_name' => 'Sam', 'status' => 'new', 'notes' => 'Kitchen sink']);
        $voice = VoiceCall::create(['organization_id' => $this->business->id, 'provider' => 'fake', 'provider_call_sid' => 'CA'.Str::random(10), 'from_number' => '+15125550147',
            'to_number' => '+15125550100', 'status' => VoiceCall::COMPLETED, 'started_at' => now()->subMinutes(5), 'duration_seconds' => 95, 'call_log_id' => $log->id,
            'recording_sid' => 'RE9', 'recording_seconds' => 90, 'summary' => 'Leaking sink, booked Tuesday.', 'transcript' => [['speaker' => 'caller', 'text' => 'My sink leaks', 'at' => '0:01']]]);

        Sanctum::actingAs($this->owner);
        $call = $this->getJson("/api/v1/client/calls/{$log->call_id}")->assertOk()->json('data.call');
        $this->assertSame(['Maria Lopez', 'Leak', 'Kitchen sink'], [$call['caller']['name'], $call['reason'], $call['notes']]);
        $this->assertSame(['Leaking sink, booked Tuesday.', 95, true], [$call['phone_call']['summary'], $call['phone_call']['duration_seconds'], $call['phone_call']['can_hear']]);
        $this->assertCount(1, $call['phone_call']['transcript']);
        $url = $call['phone_call']['recording']['url'];

        // The signed link plays without a token, once per play in the audit log; a changed link doesn't.
        $this->app['auth']->forgetGuards();
        $this->get($url)->assertOk()->assertHeader('Content-Type', 'audio/mpeg');
        $this->assertSame(1, AuditLog::query()->where('action', 'call.recording_played')->count());
        $this->get(str_replace('user='.$this->owner->id, 'user=999', $url))->assertForbidden();

        // Staff see the call but not the recording, summary or transcript.
        Sanctum::actingAs($this->member('staff'));
        $call = $this->getJson("/api/v1/client/calls/{$log->call_id}")->assertOk()->json('data.call');
        $this->assertSame([false, null, null, null], [$call['phone_call']['can_hear'], $call['phone_call']['recording'], $call['phone_call']['summary'], $call['phone_call']['transcript']]);

        // Another business's call doesn't exist for them.
        $stranger = User::factory()->create(['role' => 'client', 'is_active' => true, 'must_change_password' => false]);
        app(ProvisionUserTenancy::class)->handle($stranger);
        Sanctum::actingAs($stranger->fresh());
        $this->getJson("/api/v1/client/calls/{$log->call_id}")->assertNotFound();
    }

    public function test_results_and_the_pdf_for_a_month(): void
    {
        CallLog::create(['user_id' => User::factory()->create(['role' => 'agent'])->id, 'call_id' => CallLog::generateCallId(), 'organization_id' => $this->business->id, 'call_date' => now()->toDateString(), 'call_time' => '09:00',
            'reason_for_call' => 'service-request', 'call_outcome' => 'booked', 'agent_name' => 'Sam', 'status' => 'new']);

        Sanctum::actingAs($this->owner);
        $data = $this->getJson('/api/v1/client/results')->assertOk()->json('data');
        $this->assertSame(now('America/Chicago')->format('Y-m'), $data['month']);
        $this->assertTrue($data['is_current']);
        $this->assertSame(1, $data['summary']['calls']);
        $this->assertArrayHasKey('leads', $data['sources']);
        $this->assertCount(7, $data['heatmap']);
        $this->assertStringContainsString('/api/v1/client/results/pdf?month=', $data['pdf_url']);

        $this->get('/api/v1/client/results/pdf?month='.$data['month'])->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->getJson('/api/v1/client/results?month=2026-13')->assertStatus(422);
    }
}
