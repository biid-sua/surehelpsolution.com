<?php

namespace Tests\Feature;

use App\Actions\Appointments\BookAppointment;
use App\Actions\Inbox\SendReply;
use App\Actions\Organizations\ProvisionUserTenancy;
use App\Enums\InboxChannel;
use App\Livewire\Client\Business\PhoneLine;
use App\Models\BusinessHour;
use App\Models\BusinessProfile;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\Organization;
use App\Models\PhoneNumber;
use App\Models\SmsOptOut;
use App\Models\User;
use App\Models\VoiceCall;
use App\Services\Messages\CustomerMessages;
use App\Services\Telephony\AgentPresence;
use App\Services\Telephony\TelephonyProvider;
use App\Services\Telephony\TwilioProvider;
use App\Services\Telephony\VoiceStats;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Phone lines, part 2 (D61): after-hours routing, agents calling out, the business's Phone line
 * page, and texting: the Inbox channel, STOP/START, missed-call text-back and appointment texts.
 */
class CallingOutAndTextingTest extends TestCase
{
    use RefreshDatabase;

    private const TZ = 'America/Chicago';

    private User $agent;

    private User $owner;

    private Organization $business;

    private PhoneNumber $line;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Carbon::setTestNow(CarbonImmutable::parse('2026-10-14 10:00', self::TZ)); // Wednesday morning
        $this->agent = User::factory()->create(['role' => 'agent', 'is_active' => true, 'must_change_password' => false, 'name' => 'Jamie Agent']);
        $this->owner = User::factory()->create(['role' => 'client', 'is_active' => true, 'must_change_password' => false]);
        $this->business = app(ProvisionUserTenancy::class)->handle($this->owner);
        $this->business->forceFill(['name' => 'Rivera Plumbing', 'timezone' => self::TZ, 'setup_completed_at' => now()])->save();
        BusinessProfile::create(['organization_id' => $this->business->id, 'display_name' => 'Rivera Plumbing', 'phone' => '5125550100']);
        $this->business->assignAgent($this->agent);
        $this->line = PhoneNumber::create(['organization_id' => $this->business->id, 'provider' => 'fake', 'provider_sid' => 'PN1', 'number' => '+15125550100', 'transfer_number' => '+15125550199']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function fake(): TelephonyProvider
    {
        return app(TelephonyProvider::class);
    }

    private function openWeekdays(): void
    {
        foreach ([1, 2, 3, 4, 5] as $day) {
            BusinessHour::create(['organization_id' => $this->business->id, 'day_of_week' => $day, 'opens_at' => '08:00', 'closes_at' => '17:00']);
        }
    }

    private function ring(string $sid, string $from = '+15125550147'): string
    {
        return $this->post('/api/webhooks/voice/incoming', ['CallSid' => $sid, 'From' => $from, 'To' => '+15125550100'])->assertOk()->getContent();
    }

    private function text(string $body, string $from = '+15125550147', string $sid = 'SM1'): void
    {
        $this->post('/api/webhooks/sms/incoming', ['MessageSid' => $sid, 'From' => $from, 'To' => '+15125550100', 'Body' => $body])->assertOk();
    }

    public function test_after_hours_calls_follow_the_lines_setting(): void
    {
        $this->openWeekdays();
        app(AgentPresence::class)->set($this->agent, AgentPresence::AVAILABLE);

        $this->assertStringContainsString('<Client', $this->ring('CA1'), 'open: agents answer');
        $this->assertFalse(VoiceCall::withoutGlobalScopes()->where('provider_call_sid', 'CA1')->sole()->after_hours);

        Carbon::setTestNow(CarbonImmutable::parse('2026-10-14 21:00', self::TZ));
        app(AgentPresence::class)->set($this->agent, AgentPresence::AVAILABLE);
        $this->assertStringContainsString('<Client', $this->ring('CA2'), '"agents": as during the day');
        $this->assertTrue(VoiceCall::withoutGlobalScopes()->where('provider_call_sid', 'CA2')->sole()->after_hours);

        $this->line->update(['after_hours' => 'owner']);
        $xml = $this->ring('CA3');
        $this->assertStringNotContainsString('<Client', $xml);
        $this->assertStringContainsString('<Number>+15125550199</Number>', $xml);

        $this->line->update(['after_hours' => 'voicemail']);
        $xml = $this->ring('CA4');
        $this->assertStringNotContainsString('<Number>', $xml);
        $this->assertStringContainsString('<Record', $xml);
    }

    public function test_a_business_without_opening_hours_is_never_closed(): void
    {
        Carbon::setTestNow(CarbonImmutable::parse('2026-10-14 23:00', self::TZ));
        $this->line->update(['after_hours' => 'voicemail']);
        app(AgentPresence::class)->set($this->agent, AgentPresence::AVAILABLE);

        $this->assertStringContainsString('<Client', $this->ring('CA1'));
    }

    public function test_agents_call_customers_with_the_business_number(): void
    {
        $maria = Customer::create(['organization_id' => $this->business->id, 'first_name' => 'Maria', 'last_name' => 'Lopez', 'phone' => '(512) 555-0147']);
        $this->actingAs($this->agent);

        $call = $this->postJson(route('agent.phone.dial'), ['organization' => $this->business->ulid, 'number' => '512-555-0147'])->assertOk()->json('call');
        $this->assertTrue($call['outbound']);
        $this->assertSame('Maria Lopez', $call['caller']);
        $this->assertFalse($call['can_transfer']);
        $voice = VoiceCall::withoutGlobalScopes()->sole();
        $this->assertSame(['outbound', '+15125550100', '+15125550147', $this->agent->id, $maria->id], [$voice->direction, $voice->from_number, $voice->to_number, $voice->placed_by_user_id, $voice->customer_id]);
        $this->assertSame(VoiceCall::IN_PROGRESS, $voice->status, 'the demo customer picks up');
        $this->assertDatabaseHas('audit_logs', ['action' => 'call.placed']);

        $this->postJson(route('agent.phone.check-in'))->assertJsonPath('active.id', $voice->ulid);
        $this->postJson(route('agent.phone.dial'), ['organization' => $this->business->ulid, 'number' => '5125550111'])->assertStatus(422)->assertJson(['message' => 'Finish your current call first.']);
        $this->postJson(route('agent.phone.hang-up', $voice))->assertOk();
        $this->assertSame(VoiceCall::COMPLETED, $voice->fresh()->status);
        $this->assertSame(0, app(VoiceStats::class)->summary($this->business->id, now()->subDay(), now()->addDay())['total'], 'calls out are not in the answer rate');

        // Only US/Canadian numbers, never premium rate; only businesses the agent serves.
        $this->postJson(route('agent.phone.dial'), ['organization' => $this->business->ulid, 'number' => '+44 20 7946 0000'])->assertStatus(422);
        $this->postJson(route('agent.phone.dial'), ['organization' => $this->business->ulid, 'number' => '+1 900 555 0100'])->assertStatus(422);
        $other = app(ProvisionUserTenancy::class)->handle(User::factory()->create(['role' => 'client', 'is_active' => true]));
        $this->postJson(route('agent.phone.dial'), ['organization' => $other->ulid, 'number' => '5125550147'])->assertNotFound();
    }

    public function test_twilio_places_only_the_call_the_server_approved(): void
    {
        config(['telephony.driver' => 'twilio', 'telephony.twilio' => ['account_sid' => 'AC1', 'auth_token' => 'tok', 'api_key' => 'SK1', 'api_secret' => 'sec', 'twiml_app_sid' => 'AP1', 'webhook_url' => '', 'country' => 'US']]);
        $this->app->forgetInstance(TelephonyProvider::class);
        $this->actingAs($this->agent);
        $id = $this->postJson(route('agent.phone.dial'), ['organization' => $this->business->ulid, 'number' => '5125550147'])->assertOk()->json('call.id');
        $this->assertSame(VoiceCall::RINGING, VoiceCall::withoutGlobalScopes()->sole()->status);

        $sign = fn (array $fields) => ['X-Twilio-Signature' => TwilioProvider::signature('tok', url('/api/webhooks/voice/outgoing'), $fields)];
        $other = ['CallSid' => 'CAx', 'From' => 'client:agent-999', 'callId' => $id];
        $this->post('/api/webhooks/voice/outgoing', $other, $sign($other))->assertOk()->assertSee('could not be placed');

        $fields = ['CallSid' => 'CAout', 'From' => 'client:agent-'.$this->agent->id, 'callId' => $id];
        $xml = $this->post('/api/webhooks/voice/outgoing', $fields, $sign($fields))->assertOk()->getContent();
        $this->assertStringContainsString('callerId="+15125550100"', $xml);
        $this->assertStringContainsString('<Number statusCallback=', $xml);
        $this->assertStringContainsString('>+15125550147</Number>', $xml);
        $this->assertSame('CAout', VoiceCall::withoutGlobalScopes()->sole()->provider_call_sid);

        $this->post('/api/webhooks/voice/outgoing', $fields, $sign($fields))->assertSee('could not be placed', false); // only once

        $callee = ['CallStatus' => 'in-progress'];
        $url = url("/api/webhooks/voice/calls/{$id}/callee");
        $this->post($url, $callee, ['X-Twilio-Signature' => TwilioProvider::signature('tok', $url, $callee)])->assertNoContent();
        $this->assertSame(VoiceCall::IN_PROGRESS, VoiceCall::withoutGlobalScopes()->sole()->status);
    }

    public function test_the_business_sees_its_line_and_chooses_where_calls_go(): void
    {
        $this->actingAs($this->owner);
        $this->get(route('app.business.phone'))->assertOk()->assertSee('(512) 555-0100')->assertSee('*72 5125550100')->assertSee('**61*5125550100#', false);

        Livewire::test(PhoneLine::class)->set('transferNumber', '(512) 555-0100')->call('save')->assertHasErrors('transferNumber');
        Livewire::test(PhoneLine::class)->set('transferNumber', '512 555 0177')->set('afterHours', 'voicemail')->set('textBack', true)
            ->set('textBackMessage', 'Sorry we missed you! {business}')->call('save')->assertHasNoErrors();
        $line = $this->line->fresh();
        $this->assertSame(['+15125550177', 'voicemail', true, 'Sorry we missed you! {business}'], [$line->transfer_number, $line->after_hours, $line->text_back, $line->text_back_message]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'phone_line.updated']);

        $staff = User::factory()->create(['role' => 'client', 'is_active' => true, 'must_change_password' => false]);
        $this->business->members()->attach($staff->id, ['role' => 'staff', 'status' => 'active']);
        $this->actingAs($staff);
        $this->get(route('app.business.phone'))->assertForbidden(); // staff don't see business settings

        // Another business has no line and never sees this one.
        $this->actingAs(User::factory()->create(['role' => 'client', 'is_active' => true, 'must_change_password' => false]));
        app(ProvisionUserTenancy::class)->handle(auth()->user());
        $this->get(route('app.business.phone'))->assertOk()->assertSee('No phone line yet')->assertDontSee('(512) 555-0100');
    }

    public function test_texts_land_in_the_inbox_and_replies_go_back_by_text(): void
    {
        $maria = Customer::create(['organization_id' => $this->business->id, 'first_name' => 'Maria', 'last_name' => 'Lopez', 'phone' => '(512) 555-0147']);

        $this->text('Do you fix water heaters?');
        $conversation = Conversation::withoutGlobalScopes()->sole();
        $this->assertSame([InboxChannel::Sms, '+15125550100', '+15125550147', $maria->id, 'Maria Lopez'],
            [$conversation->channel, $conversation->channel_key, $conversation->external_thread_id, $conversation->customer_id, $conversation->contact_name]);
        $this->text('Do you fix water heaters?'); // Twilio retry
        $this->assertSame(1, Message::withoutGlobalScopes()->where('direction', 'in')->count());

        app(SendReply::class)->handle($conversation, 'Yes! When suits you?', $this->owner);
        $this->assertSame([['from' => '+15125550100', 'to' => '+15125550147', 'body' => 'Yes! When suits you?']],
            array_map(fn ($t) => array_intersect_key($t, ['from' => 1, 'to' => 1, 'body' => 1]), $this->fake()->texts));
        $sent = Message::withoutGlobalScopes()->where('direction', 'out')->sole();
        $this->assertSame('sent', $sent->status);

        // Delivery failure report.
        $this->post('/api/webhooks/sms/status', ['MessageSid' => $sent->external_id, 'MessageStatus' => 'undelivered', 'ErrorCode' => '30007'])->assertNoContent();
        $this->assertSame('failed', $sent->fresh()->status);
        $this->assertStringContainsString('registration', $sent->fresh()->error);
    }

    public function test_stop_means_no_more_texts_until_start(): void
    {
        $maria = Customer::create(['organization_id' => $this->business->id, 'first_name' => 'Maria', 'phone' => '(512) 555-0147', 'sms_consent' => true]);
        $this->text('Stop', sid: 'SM1');
        $this->assertTrue(SmsOptOut::withoutGlobalScopes()->where('phone', '+15125550147')->exists());
        $this->assertFalse($maria->fresh()->sms_consent);

        $conversation = Conversation::withoutGlobalScopes()->sole();
        try {
            app(SendReply::class)->handle($conversation, 'Are you sure?', $this->owner);
        } catch (ValidationException) {
        }
        $this->assertSame([], $this->fake()->texts);
        $this->assertStringContainsString('texted STOP', (string) Message::withoutGlobalScopes()->where('direction', 'out')->sole()->error);

        $this->text('START', sid: 'SM2');
        app(SendReply::class)->handle($conversation->fresh(), 'Welcome back', $this->owner);
        $this->assertCount(1, $this->fake()->texts);
    }

    public function test_texting_stays_off_with_twilio_until_registration_is_approved(): void
    {
        config(['telephony.driver' => 'twilio', 'telephony.sms' => false, 'telephony.twilio' => ['account_sid' => 'AC1', 'auth_token' => 'tok', 'api_key' => 'SK1', 'api_secret' => 'sec', 'twiml_app_sid' => '', 'webhook_url' => '', 'country' => 'US']]);
        $this->app->forgetInstance(TelephonyProvider::class);
        $conversation = Conversation::create(['organization_id' => $this->business->id, 'channel' => InboxChannel::Sms, 'channel_key' => '+15125550100', 'external_thread_id' => '+15125550147', 'last_inbound_at' => now()]);

        app(SendReply::class)->handle($conversation, 'Hello', $this->owner);
        $message = Message::withoutGlobalScopes()->sole();
        $this->assertSame('failed', $message->status);
        $this->assertStringContainsString('carrier registration', $message->error);
    }

    public function test_missed_callers_get_one_text_back(): void
    {
        $this->line->update(['text_back' => true, 'transfer_number' => null]);
        $this->ring('CA1');
        $this->post('/api/webhooks/voice/status', ['CallSid' => 'CA1', 'CallStatus' => 'completed'])->assertNoContent();

        $this->assertCount(1, $this->fake()->texts);
        $this->assertSame('Sorry we missed your call to Rivera Plumbing. We\'ll call you back as soon as we can, or reply here with what you need.', $this->fake()->texts[0]['body']);
        $this->assertNotNull(VoiceCall::withoutGlobalScopes()->where('provider_call_sid', 'CA1')->sole()->texted_back_at);
        $message = Message::withoutGlobalScopes()->sole();
        $this->assertSame(['system', 'sent', InboxChannel::Sms], [$message->author_type, $message->status, $message->conversation->channel]);

        // Same caller again within 12 hours: no second text. Their reply continues the same conversation.
        $this->ring('CA2');
        $this->post('/api/webhooks/voice/status', ['CallSid' => 'CA2', 'CallStatus' => 'completed']);
        $this->assertCount(1, $this->fake()->texts);
        $this->text('Need a plumber today', sid: 'SM9');
        $this->assertSame(1, Conversation::withoutGlobalScopes()->count());

        // Someone who texted STOP isn't texted.
        SmsOptOut::create(['organization_id' => $this->business->id, 'phone' => '+15125550166', 'opted_out_at' => now()]);
        $this->ring('CA3', '+15125550166');
        $this->post('/api/webhooks/voice/status', ['CallSid' => 'CA3', 'CallStatus' => 'completed']);
        $this->assertCount(1, $this->fake()->texts);
    }

    public function test_appointment_texts_go_only_to_customers_who_agreed(): void
    {
        $yes = Customer::create(['organization_id' => $this->business->id, 'first_name' => 'Ana', 'phone' => '5125550101', 'sms_consent' => true, 'sms_consent_at' => now()]);
        $no = Customer::create(['organization_id' => $this->business->id, 'first_name' => 'Ben', 'phone' => '5125550102']);
        $book = fn (Customer $c, string $at = '2026-10-16 10:00') => app(BookAppointment::class)->handle($this->business, ['starts_at' => CarbonImmutable::parse($at, self::TZ), 'title' => 'Water heater check', 'customer_id' => $c->id]);

        $appointment = $book($yes);
        $this->assertSame('Rivera Plumbing: your Water heater check is booked for Friday, October 16 at 10:00 AM. Reply here or call (512) 555-0100 to change it.',
            $this->fake()->texts[0]['body'], 'the booking confirmation goes by text too');
        $this->assertTrue(app(CustomerMessages::class)->send($appointment, 'appointment_reminder'));
        $this->assertSame('+15125550101', $this->fake()->texts[1]['to']);
        $this->assertSame("Rivera Plumbing: reminder of your Water heater check on Friday, October 16 at 10:00 AM. Reply here if you can't make it.", $this->fake()->texts[1]['body']);
        $this->assertDatabaseHas('customer_timeline_events', ['customer_id' => $yes->id, 'type' => 'sms_sent']);
        $this->assertSame(1, Conversation::withoutGlobalScopes()->count(), 'both texts are one Inbox conversation');

        $this->assertFalse(app(CustomerMessages::class)->send($book($no, '2026-10-16 14:00'), 'appointment_reminder'), 'no email, no consent: nothing goes out');
        $this->assertCount(2, $this->fake()->texts);
    }
}
