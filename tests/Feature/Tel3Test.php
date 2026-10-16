<?php

namespace Tests\Feature;

use App\Actions\Appointments\BookAppointment;
use App\Actions\Organizations\ProvisionUserTenancy;
use App\Livewire\Client\Business\CustomerEmails;
use App\Livewire\Client\Inbox\Index as Inbox;
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
use App\Services\Privacy\AccountClosure;
use App\Services\Telephony\AgentPresence;
use App\Services\Telephony\FakeProvider;
use App\Services\Telephony\TelephonyProvider;
use App\Services\Telephony\TwilioProvider;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * TEL-3 (D62): warm transfer, starting text conversations, texted pictures, the business's own
 * wording for appointment texts, and phone data on account closure.
 */
class Tel3Test extends TestCase
{
    use RefreshDatabase;

    private const TZ = 'America/Chicago';

    private User $agent;

    private User $owner;

    private Organization $business;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Storage::fake('local');
        Carbon::setTestNow(CarbonImmutable::parse('2026-10-14 10:00', self::TZ));
        $this->agent = User::factory()->create(['role' => 'agent', 'is_active' => true, 'must_change_password' => false]);
        $this->owner = User::factory()->create(['role' => 'client', 'is_active' => true, 'must_change_password' => false]);
        $this->business = app(ProvisionUserTenancy::class)->handle($this->owner);
        $this->business->forceFill(['name' => 'Rivera Plumbing', 'timezone' => self::TZ, 'setup_completed_at' => now()])->save();
        BusinessProfile::create(['organization_id' => $this->business->id, 'display_name' => 'Rivera Plumbing', 'phone' => '5125550100']);
        $this->business->assignAgent($this->agent);
        PhoneNumber::create(['organization_id' => $this->business->id, 'provider' => 'fake', 'provider_sid' => 'PN1', 'number' => '+15125550100', 'transfer_number' => '+15125550199']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function fake(): FakeProvider
    {
        $provider = app(TelephonyProvider::class);
        $this->assertInstanceOf(FakeProvider::class, $provider);

        return $provider;
    }

    private function answeredCall(): VoiceCall
    {
        app(AgentPresence::class)->set($this->agent, AgentPresence::AVAILABLE);
        $this->post('/api/webhooks/voice/incoming', ['CallSid' => 'CA1', 'From' => '+15125550147', 'To' => '+15125550100'])->assertOk();
        $call = VoiceCall::withoutGlobalScopes()->sole();
        $this->actingAs($this->agent)->postJson(route('agent.phone.answer', $call))->assertOk();

        return $call->fresh();
    }

    public function test_warm_transfer_holds_the_caller_consults_the_owner_then_connects(): void
    {
        $call = $this->answeredCall();

        $consult = $this->postJson(route('agent.phone.hold', $call))->assertOk()->json('consult');
        $this->assertSame(['call' => 'CA1', 'url' => route('webhooks.voice.hold', $call)], $this->fake()->redirects[0]);
        $this->assertNotNull($call->fresh()->held_at);
        $this->assertSame('(512) 555-0147', $consult['consult_for']['caller']);
        $this->assertTrue($consult['outbound']);
        $this->postJson(route('agent.phone.check-in'))->assertJsonPath('active.id', $consult['id']);

        // The held caller's TwiML: a waiting room, and the Dial action keeps them there.
        $xml = $this->post("/api/webhooks/voice/calls/{$call->ulid}/hold")->assertOk()->getContent();
        $this->assertStringContainsString('startConferenceOnEnter="false"', $xml);
        $this->assertStringContainsString('>call-'.$call->ulid.'</Conference>', $xml);
        $this->assertStringContainsString('<Conference', $this->post("/api/webhooks/voice/calls/{$call->ulid}/dial-finished", ['DialCallStatus' => 'completed'])->getContent());
        // The agent's first leg dropping doesn't end their work on the call.
        $this->post("/api/webhooks/voice/calls/{$call->ulid}/agent", ['CallStatus' => 'completed', 'To' => 'client:agent-'.$this->agent->id]);
        $this->assertSame(AgentPresence::BUSY, $this->agent->fresh()->phone_status);

        $this->postJson(route('agent.phone.connect', $consult['id']))->assertOk()->assertJson(['message' => 'Connected to (512) 555-0199. You can leave the call.']);
        $call->refresh();
        $this->assertSame([VoiceCall::TRANSFERRED, '+15125550199', null], [$call->status, $call->transferred_to, $call->held_at]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'call.held_for_transfer']);

        $join = $this->post("/api/webhooks/voice/calls/{$call->ulid}/join")->assertOk()->getContent();
        $this->assertStringContainsString('startConferenceOnEnter="true"', $join);
    }

    public function test_back_to_the_caller_when_the_owner_cant_take_it(): void
    {
        $call = $this->answeredCall();
        $consult = $this->postJson(route('agent.phone.hold', $call))->json('consult');

        $this->postJson(route('agent.phone.resume', $call))->assertOk()->assertJsonPath('call.held', false);
        $this->assertSame(VoiceCall::COMPLETED, VoiceCall::withoutGlobalScopes()->where('ulid', $consult['id'])->sole()->status);
        $this->assertSame(VoiceCall::IN_PROGRESS, $call->fresh()->status);
        $this->postJson(route('agent.phone.connect', $consult['id']))->assertStatus(422);

        // Another agent can't touch the held call.
        $other = User::factory()->create(['role' => 'agent', 'is_active' => true, 'must_change_password' => false]);
        $this->business->assignAgent($other);
        $this->actingAs($other)->postJson(route('agent.phone.hold', $call))->assertNotFound();
    }

    public function test_twilio_rejoin_is_only_for_the_agent_who_held_the_caller(): void
    {
        $call = $this->answeredCall();
        $call->forceFill(['held_at' => now()])->save();
        config(['telephony.driver' => 'twilio', 'telephony.twilio' => ['account_sid' => 'AC1', 'auth_token' => 'tok', 'api_key' => 'SK1', 'api_secret' => 'sec', 'twiml_app_sid' => 'AP1', 'webhook_url' => '', 'country' => 'US']]);
        $this->app->forgetInstance(TelephonyProvider::class);
        $post = function (array $fields) {
            $url = url('/api/webhooks/voice/outgoing');

            return $this->post($url, $fields, ['X-Twilio-Signature' => TwilioProvider::signature('tok', $url, $fields)])->assertOk()->getContent();
        };

        $this->assertStringContainsString('no longer waiting', $post(['CallSid' => 'CAx', 'From' => 'client:agent-999', 'callId' => $call->ulid, 'rejoin' => '1']));
        $xml = $post(['CallSid' => 'CAy', 'From' => 'client:agent-'.$this->agent->id, 'callId' => $call->ulid, 'rejoin' => '1']);
        $this->assertStringContainsString('startConferenceOnEnter="true"', $xml);
        $this->assertNull($call->fresh()->held_at);
        $this->assertStringContainsString('no longer waiting', $post(['CallSid' => 'CAz', 'From' => 'client:agent-'.$this->agent->id, 'callId' => $call->ulid, 'rejoin' => '1']), 'only once');
    }

    public function test_the_business_starts_texts_only_with_people_who_agreed(): void
    {
        $yes = Customer::create(['organization_id' => $this->business->id, 'first_name' => 'Ana', 'phone' => '5125550101', 'sms_consent' => true]);
        Customer::create(['organization_id' => $this->business->id, 'first_name' => 'Ben', 'phone' => '5125550102']);
        $this->actingAs($this->owner);

        $this->get(route('app.customers.show', $yes))->assertSee('Text (512) 555-0101');
        $this->get(route('app.inbox.index', ['text' => '+15125550101']))->assertOk()->assertSee('New text message');

        Livewire::test(Inbox::class)->call('compose')->set('newTo', '512 555 0102')->set('newBody', 'Hi Ben')->call('startText')->assertHasErrors('newTo');
        $this->assertSame([], $this->fake()->texts);

        Livewire::test(Inbox::class)->call('compose')->set('newTo', '512 555 0101')->set('newBody', 'Hi Ana, your part arrived.')->call('startText')->assertHasNoErrors();
        $this->assertSame('Hi Ana, your part arrived.', $this->fake()->texts[0]['body']);
        $this->assertSame($yes->id, Conversation::withoutGlobalScopes()->sole()->customer_id);
        $this->assertDatabaseHas('audit_logs', ['action' => 'sms.conversation_started']);

        // Someone who texted before can be texted again; STOP still wins.
        $this->post('/api/webhooks/sms/incoming', ['MessageSid' => 'SM5', 'From' => '+15125550177', 'To' => '+15125550100', 'Body' => 'Hello?']);
        Livewire::test(Inbox::class)->call('compose')->set('newTo', '5125550177')->set('newBody', 'Hi!')->call('startText')->assertHasNoErrors();
        SmsOptOut::create(['organization_id' => $this->business->id, 'phone' => '+15125550177', 'opted_out_at' => now()]);
        Livewire::test(Inbox::class)->call('compose')->set('newTo', '5125550177')->set('newBody', 'Again')->call('startText')->assertHasErrors('newTo');

        // Staff without messages.send can't start texts.
        $staff = User::factory()->create(['role' => 'client', 'is_active' => true, 'must_change_password' => false]);
        $this->business->members()->attach($staff->id, ['role' => 'staff', 'status' => 'active']);
        $this->assertTrue($staff->hasPermissionIn('messages.send', $this->business), 'staff can send by default');
    }

    public function test_texted_pictures_are_kept_privately_and_shown_in_the_inbox(): void
    {
        $url = 'https://api.twilio.com/2010-04-01/Accounts/AC1/Messages/MM1/Media/ME1';
        $this->fake()->media[$url] = ['body' => 'PNG-bytes', 'type' => 'image/png'];
        $this->fake()->media['https://x/doc'] = ['body' => '%PDF', 'type' => 'application/pdf'];

        $this->post('/api/webhooks/sms/incoming', ['MessageSid' => 'SM1', 'From' => '+15125550147', 'To' => '+15125550100', 'Body' => '',
            'NumMedia' => '2', 'MediaUrl0' => $url, 'MediaUrl1' => 'https://x/doc'])->assertOk();
        $message = Message::withoutGlobalScopes()->sole();
        $this->assertCount(1, $message->attachments);
        $this->assertStringContainsString("couldn't be shown", (string) $message->body);
        $picture = $message->attachments[0]['url'];
        $this->assertStringStartsWith(url('/inbox-media/'.$this->business->ulid.'/'), $picture);

        $this->actingAs($this->owner)->get($picture)->assertOk()->assertHeader('Content-Security-Policy', "default-src 'none'");
        $this->assertSame('PNG-bytes', $this->get($picture)->streamedContent());
        $this->actingAs($this->owner)->get(route('app.inbox.show', $message->conversation))->assertSee('alt="Picture sent by text"', false);

        // Another business can't see it.
        $stranger = User::factory()->create(['role' => 'client', 'is_active' => true, 'must_change_password' => false]);
        app(ProvisionUserTenancy::class)->handle($stranger);
        $this->actingAs($stranger->fresh())->get($picture)->assertNotFound();

        // Twilio retries: nothing is downloaded or stored twice.
        $this->post('/api/webhooks/sms/incoming', ['MessageSid' => 'SM1', 'From' => '+15125550147', 'To' => '+15125550100', 'NumMedia' => '1', 'MediaUrl0' => $url]);
        $this->assertCount(1, Storage::disk('local')->allFiles('inbox-media'));
    }

    public function test_businesses_write_their_own_appointment_texts(): void
    {
        $this->actingAs($this->owner);
        Livewire::test(CustomerEmails::class)->call('edit', 'appointment_reminder')->set('draft.sms', 'See you {date} at {time}! {nickname}')->call('save')->assertHasErrors('draft.sms');
        Livewire::test(CustomerEmails::class)->call('edit', 'appointment_reminder')->set('draft.sms', 'Hi {first_name}, see you {date} at {time}. Rivera')
            ->assertSee('Hi Ana, see you')->call('save')->assertHasNoErrors();

        $ana = Customer::create(['organization_id' => $this->business->id, 'first_name' => 'Ana', 'phone' => '5125550101', 'sms_consent' => true]);
        $appointment = app(BookAppointment::class)->handle($this->business, ['starts_at' => CarbonImmutable::parse('2026-10-16 10:00', self::TZ), 'title' => 'Visit', 'customer_id' => $ana->id]);
        app(CustomerMessages::class)->send($appointment, 'appointment_reminder');
        $this->assertSame('Hi Ana, see you Friday, October 16 at 10:00 AM. Rivera', last($this->fake()->texts)['body']);
    }

    public function test_closing_the_account_removes_phone_data_and_releases_numbers(): void
    {
        $call = $this->answeredCall();
        SmsOptOut::create(['organization_id' => $this->business->id, 'phone' => '+15125550166', 'opted_out_at' => now()]);
        Storage::disk('local')->put('inbox-media/'.$this->business->ulid.'/x.png', 'x');

        app(AccountClosure::class)->close($this->business);

        $this->assertSame(0, VoiceCall::withoutGlobalScopes()->count());
        $this->assertSame(0, SmsOptOut::withoutGlobalScopes()->count());
        $this->assertSame(0, PhoneNumber::withoutGlobalScopes()->count());
        $this->assertContains('PN1', $this->fake()->released);
        $this->assertFalse(Storage::disk('local')->exists('inbox-media/'.$this->business->ulid.'/x.png'));
        $this->assertNull(VoiceCall::withoutGlobalScopes()->find($call->id));
    }
}
