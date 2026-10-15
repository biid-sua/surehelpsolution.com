<?php

namespace Tests\Feature;

use App\Actions\Organizations\ProvisionUserTenancy;
use App\Enums\TaskType;
use App\Livewire\Admin\PhoneLines;
use App\Livewire\Agent\Workspace;
use App\Models\AuditLog;
use App\Models\CallLog;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\PhoneNumber;
use App\Models\Task;
use App\Models\User;
use App\Models\VoiceCall;
use App\Notifications\MissedVoiceCall;
use App\Services\Ai\Contracts\AiProvider;
use App\Services\Telephony\AgentPresence;
use App\Services\Telephony\FakeProvider;
use App\Services\Telephony\TelephonyProvider;
use App\Services\Telephony\TwilioProvider;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Support\ScriptedAiProvider;
use Tests\TestCase;

/**
 * Live calls (D60): lines, routing to available agents, the softphone, missed calls, recordings,
 * transcripts and AI summaries, transfer to the owner, and Twilio's signatures and tokens.
 */
class TelephonyTest extends TestCase
{
    use RefreshDatabase;

    private User $agent;

    private Organization $business;

    private PhoneNumber $line;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Carbon::setTestNow(CarbonImmutable::parse('2026-10-15 15:00', 'UTC'));
        $this->agent = $this->newAgent('Jamie Agent');
        $owner = User::factory()->create(['role' => 'client', 'is_active' => true, 'must_change_password' => false]);
        $this->business = app(ProvisionUserTenancy::class)->handle($owner);
        $this->business->update(['name' => 'Rivera Plumbing', 'timezone' => 'America/Chicago']);
        $this->business->assignAgent($this->agent);
        $this->line = PhoneNumber::create(['organization_id' => $this->business->id, 'provider' => 'fake', 'provider_sid' => 'PNfake1', 'number' => '+15125550100', 'transfer_number' => '+15125550199']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function newAgent(string $name): User
    {
        return User::factory()->create(['role' => 'agent', 'is_active' => true, 'must_change_password' => false, 'name' => $name]);
    }

    private function ready(User $agent): void
    {
        app(AgentPresence::class)->set($agent, AgentPresence::AVAILABLE);
    }

    private function ring(string $sid = 'CA100', string $from = '+15125550147'): string
    {
        return $this->post('/api/webhooks/voice/incoming', ['CallSid' => $sid, 'From' => $from, 'To' => '+15125550100'])
            ->assertOk()->assertHeader('Content-Type', 'text/xml; charset=UTF-8')->getContent();
    }

    public function test_a_call_rings_every_ready_agent_who_serves_the_business(): void
    {
        $maria = Customer::create(['organization_id' => $this->business->id, 'first_name' => 'Maria', 'last_name' => 'Lopez', 'phone' => '(512) 555-0147']);
        $second = $this->newAgent('Sam Second');
        $this->business->assignAgent($second);
        $away = $this->newAgent('Away Agent');
        $this->business->assignAgent($away);
        $elsewhere = $this->newAgent('Other Business Agent');
        foreach ([$this->agent, $second, $elsewhere] as $agent) {
            $this->ready($agent);
        }
        app(AgentPresence::class)->set($away, AgentPresence::AWAY);

        $xml = $this->ring();

        $call = VoiceCall::withoutGlobalScopes()->sole();
        $this->assertSame(VoiceCall::RINGING, $call->status);
        $this->assertSame($maria->id, $call->customer_id, 'caller matched by phone');
        $this->assertEqualsCanonicalizing([$this->agent->id, $second->id], $call->offered_to);
        $this->assertStringContainsString('<Identity>agent-'.$this->agent->id.'</Identity>', $xml);
        $this->assertStringContainsString('<Identity>agent-'.$second->id.'</Identity>', $xml);
        $this->assertStringNotContainsString('agent-'.$away->id.'<', $xml);
        $this->assertStringNotContainsString('agent-'.$elsewhere->id.'<', $xml);
        $this->assertStringContainsString('<Parameter name="caller" value="Maria Lopez"/>', $xml);
        $this->assertStringContainsString('record="record-from-answer-dual"', $xml);
        $this->assertStringContainsString('<Transcription', $xml);
        $this->assertStringContainsString('may be recorded', $xml);

        // Twilio retries: same call, not a second one.
        $this->ring();
        $this->assertSame(1, VoiceCall::withoutGlobalScopes()->count());
    }

    public function test_an_agent_whose_phone_stopped_checking_in_is_not_rung(): void
    {
        $this->ready($this->agent);
        Carbon::setTestNow(now()->addMinutes(5));

        $xml = $this->ring();
        $this->assertStringNotContainsString('<Client', $xml);
        $this->assertStringContainsString('<Number>+15125550199</Number>', $xml, 'straight to the owner');
    }

    public function test_an_agent_already_on_a_call_is_not_rung(): void
    {
        $this->ready($this->agent);
        $this->ring('CA1');
        $this->post('/api/webhooks/voice/calls/'.VoiceCall::withoutGlobalScopes()->sole()->ulid.'/agent', ['CallStatus' => 'in-progress', 'To' => 'client:agent-'.$this->agent->id])->assertNoContent();

        $xml = $this->ring('CA2', '+15125550111');
        $this->assertStringNotContainsString('<Client', $xml, 'busy on the first call');
    }

    public function test_nobody_answers_owner_then_voicemail_and_a_call_back_task(): void
    {
        $this->ready($this->agent);
        $this->ring();
        $call = VoiceCall::withoutGlobalScopes()->sole();

        $xml = $this->post("/api/webhooks/voice/calls/{$call->ulid}/dial-finished", ['DialCallStatus' => 'no-answer'])->assertOk()->getContent();
        $this->assertStringContainsString('<Number>+15125550199</Number>', $xml);

        $xml = $this->post("/api/webhooks/voice/calls/{$call->ulid}/owner-finished", ['DialCallStatus' => 'no-answer'])->assertOk()->getContent();
        $this->assertStringContainsString('<Record', $xml);
        $this->assertStringContainsString('voicemail=1', $xml);

        $this->post('/api/webhooks/voice/status', ['CallSid' => 'CA100', 'CallStatus' => 'completed'])->assertNoContent();
        $call->refresh();
        $this->assertSame(VoiceCall::MISSED, $call->status);
        $task = Task::withoutGlobalScopes()->sole();
        $this->assertSame(TaskType::Callback, $task->type);
        $this->assertSame('high', $task->priority->value);
        $this->assertSame($task->id, $call->task_id);
        Notification::assertSentTo($this->business->owner, MissedVoiceCall::class);

        // The voicemail is saved after the call ended: the task says so, no second task.
        $this->post("/api/webhooks/voice/calls/{$call->ulid}/recording?voicemail=1", ['RecordingSid' => 'RE1', 'RecordingDuration' => '31', 'RecordingStatus' => 'completed'])->assertNoContent();
        $this->assertSame(VoiceCall::VOICEMAIL, $call->refresh()->status);
        $this->assertSame('RE1', $call->recording_sid);
        $this->assertSame(1, Task::withoutGlobalScopes()->count());
        $this->assertStringContainsString('voicemail', $task->fresh()->description);
    }

    public function test_a_line_without_an_owner_number_goes_to_voicemail(): void
    {
        $this->line->update(['transfer_number' => null, 'record_calls' => false]);

        $xml = $this->ring();
        $this->assertStringContainsString('<Record', $xml);
        $this->assertStringNotContainsString('may be recorded', $xml);
        $this->assertStringNotContainsString('<Transcription', $xml);
    }

    public function test_unknown_numbers_are_turned_away(): void
    {
        $xml = $this->post('/api/webhooks/voice/incoming', ['CallSid' => 'CA9', 'From' => '+15125550147', 'To' => '+15125559999'])->assertOk()->getContent();
        $this->assertStringContainsString('not in service', $xml);
        $this->assertSame(0, VoiceCall::withoutGlobalScopes()->count());
    }

    public function test_the_softphone_answers_screen_pops_and_links_the_call_log(): void
    {
        $maria = Customer::create(['organization_id' => $this->business->id, 'first_name' => 'Maria', 'last_name' => 'Lopez', 'phone' => '(512) 555-0147']);
        $other = $this->newAgent('Sam Second');
        $this->business->assignAgent($other);
        $this->ready($this->agent);
        $this->ready($other);
        $this->ring();
        $call = VoiceCall::withoutGlobalScopes()->sole();

        $this->actingAs($this->agent);
        $state = $this->postJson(route('agent.phone.check-in'))->assertOk()->json();
        $this->assertSame($call->ulid, $state['ringing'][0]['id']);
        $this->assertSame('Maria Lopez', $state['ringing'][0]['caller']);

        $answered = $this->postJson(route('agent.phone.answer', $call))->assertOk()->json('call');
        $this->assertStringContainsString('/agent/businesses/'.$this->business->ulid.'?call='.$call->ulid, $answered['workspace_url']);
        $this->assertSame(AgentPresence::BUSY, $this->agent->fresh()->phone_status);

        $this->actingAs($other)->postJson(route('agent.phone.answer', $call))->assertStatus(409);
        $this->assertSame($this->agent->id, $call->fresh()->answered_by_user_id);

        // The call screen opens with the caller filled in and confirmed.
        $this->actingAs($this->agent);
        $this->get(route('agent.businesses.show', [$this->business->ulid, 'call' => $call->ulid]))->assertOk()->assertSee('Phone call');
        Livewire::withQueryParams(['call' => $call->ulid])->test(Workspace::class, ['organization' => $this->business])
            ->assertSet('customerId', $maria->id)
            ->assertSet('entry.phone', '(512) 555-0147')
            ->set('entry.reason', 'service-request')->set('entry.outcome', 'provided-information')
            ->call('save')->assertHasNoErrors();

        $log = CallLog::withoutGlobalScopes()->sole();
        $this->assertSame($log->id, $call->fresh()->call_log_id);

        // Another agent can't pull the call into their screen.
        Livewire::actingAs($other)->withQueryParams(['call' => $call->ulid])->test(Workspace::class, ['organization' => $this->business])->assertSet('voiceCallId', null);
    }

    public function test_hanging_up_records_talk_time_and_the_agent_finishes_up(): void
    {
        $this->ready($this->agent);
        $this->ring();
        $call = VoiceCall::withoutGlobalScopes()->sole();
        Carbon::setTestNow(now()->addSeconds(8));
        $this->post("/api/webhooks/voice/calls/{$call->ulid}/agent", ['CallStatus' => 'in-progress', 'To' => 'client:agent-'.$this->agent->id])->assertNoContent();
        Carbon::setTestNow(now()->addSeconds(95));
        $this->post("/api/webhooks/voice/calls/{$call->ulid}/agent", ['CallStatus' => 'completed', 'To' => 'client:agent-'.$this->agent->id])->assertNoContent();
        $this->post('/api/webhooks/voice/status', ['CallSid' => 'CA100', 'CallStatus' => 'completed', 'CallDuration' => '103'])->assertNoContent();

        $call->refresh();
        $this->assertSame(VoiceCall::COMPLETED, $call->status);
        $this->assertSame(8, $call->wait_seconds);
        $this->assertSame(95, $call->duration_seconds);
        $this->assertSame(AgentPresence::WRAP_UP, $this->agent->fresh()->phone_status);
        $this->assertSame(0, Task::withoutGlobalScopes()->count(), 'answered calls need no call back');

        // Wrap-up ends by itself.
        Carbon::setTestNow(now()->addSeconds((int) config('telephony.wrap_up_seconds') + 1));
        $this->agent->forceFill(['phone_seen_at' => now()])->save();
        $this->assertSame(AgentPresence::AVAILABLE, app(AgentPresence::class)->status($this->agent->fresh()));
    }

    public function test_transcript_becomes_an_ai_summary_in_the_call_log(): void
    {
        $ai = (new ScriptedAiProvider)->reply(json_encode(['summary' => 'Maria asked for a water heater check. Booked for Friday morning.']));
        $this->app->instance(AiProvider::class, $ai);
        $this->ready($this->agent);
        $this->ring();
        $call = VoiceCall::withoutGlobalScopes()->sole();
        $log = CallLog::create(['call_id' => 'CL-1', 'organization_id' => $this->business->id, 'call_date' => '2026-10-15', 'call_time' => '10:00', 'reason_for_call' => 'service-request',
            'call_outcome' => 'provided-information', 'agent_name' => 'Jamie', 'status' => 'new', 'user_id' => $this->agent->id]);
        $call->forceFill(['call_log_id' => $log->id])->save();

        $url = "/api/webhooks/voice/calls/{$call->ulid}/transcription";
        $this->post($url, ['TranscriptionEvent' => 'transcription-content', 'Track' => 'inbound_track', 'Final' => 'true', 'TranscriptionData' => json_encode(['transcript' => 'Hi, my water heater is leaking.'])])->assertNoContent();
        $this->post($url, ['TranscriptionEvent' => 'transcription-content', 'Track' => 'inbound_track', 'Final' => 'false', 'TranscriptionData' => json_encode(['transcript' => 'Hi, my wat'])])->assertNoContent();
        $this->post($url, ['TranscriptionEvent' => 'transcription-content', 'Track' => 'outbound_track', 'Final' => 'true', 'TranscriptionData' => json_encode(['transcript' => 'I can book you for Friday.'])])->assertNoContent();
        $this->post($url, ['TranscriptionEvent' => 'transcription-stopped'])->assertNoContent();

        $call->refresh();
        $this->assertSame([['caller', 'Hi, my water heater is leaking.'], ['agent', 'I can book you for Friday.']], array_map(fn ($l) => [$l['speaker'], $l['text']], $call->transcript));
        $this->assertSame('done', $call->summary_status);
        $this->assertStringContainsString('water heater', $call->summary);
        $this->assertStringContainsString("Caller: Hi, my water heater is leaking.\nReceptionist: I can book you for Friday.", $ai->requests[0]->messages[0]['content']);
        $this->assertSame('AI summary: '.$call->summary, $log->fresh()->notes, 'empty notes are filled');
    }

    public function test_transfer_to_the_owner_redirects_the_live_call(): void
    {
        $this->ready($this->agent);
        $this->ring();
        $call = VoiceCall::withoutGlobalScopes()->sole();
        $this->actingAs($this->agent)->postJson(route('agent.phone.answer', $call))->assertOk();

        $this->postJson(route('agent.phone.transfer', $call))->assertOk()->assertJson(['message' => 'Transferred to (512) 555-0199.']);

        /** @var FakeProvider $fake */
        $fake = app(TelephonyProvider::class);
        $this->assertSame('CA100', $fake->redirects[0]['call']);
        $this->assertStringEndsWith("/api/webhooks/voice/calls/{$call->ulid}/transfer", $fake->redirects[0]['url']);
        $this->assertSame(VoiceCall::TRANSFERRED, $call->fresh()->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'call.transferred']);

        $xml = $this->post("/api/webhooks/voice/calls/{$call->ulid}/transfer")->assertOk()->getContent();
        $this->assertStringContainsString('<Number>+15125550199</Number>', $xml);
        $this->assertStringContainsString('callerId="+15125550100"', $xml);
    }

    public function test_demo_phone_simulates_calls_only_for_the_agents_businesses(): void
    {
        $this->actingAs($this->agent)->postJson(route('agent.phone.presence'), ['status' => 'available'])->assertOk()->assertJson(['status' => 'available']);
        $state = $this->postJson(route('agent.phone.simulate'), ['organization' => $this->business->ulid])->assertOk()->json();
        $this->assertCount(1, $state['ringing']);
        $this->assertSame('Rivera Plumbing', $state['ringing'][0]['business']);

        $call = VoiceCall::withoutGlobalScopes()->sole();
        $this->postJson(route('agent.phone.answer', $call))->assertOk();
        $this->postJson(route('agent.phone.hang-up', $call))->assertOk()->assertJson(['status' => 'wrap_up', 'active' => null]);
        $this->assertSame(VoiceCall::COMPLETED, $call->fresh()->status);

        $other = app(ProvisionUserTenancy::class)->handle(User::factory()->create(['role' => 'client', 'is_active' => true]));
        $this->postJson(route('agent.phone.simulate'), ['organization' => $other->ulid])->assertNotFound();
        $this->getJson(route('agent.phone.token'))->assertNotFound(); // no vendor token in demo mode
    }

    public function test_webhooks_need_twilios_signature(): void
    {
        config(['telephony.driver' => 'twilio', 'telephony.twilio' => ['account_sid' => 'AC1', 'auth_token' => 'secret-token', 'api_key' => 'SK1', 'api_secret' => 'sk-secret', 'twiml_app_sid' => '', 'webhook_url' => '', 'country' => 'US']]);
        $this->app->forgetInstance(TelephonyProvider::class);
        $fields = ['CallSid' => 'CA100', 'From' => '+15125550147', 'To' => '+15125550100'];
        $url = url('/api/webhooks/voice/incoming');

        $this->post($url, $fields)->assertForbidden();
        $this->post($url, $fields, ['X-Twilio-Signature' => 'bad'])->assertForbidden();
        $this->post($url, $fields, ['X-Twilio-Signature' => TwilioProvider::signature('secret-token', $url, $fields)])->assertOk();
        $this->assertSame(1, VoiceCall::withoutGlobalScopes()->count());
        $this->assertSame('twilio', VoiceCall::withoutGlobalScopes()->sole()->provider);
    }

    public function test_twilio_signature_matches_twilios_published_example(): void
    {
        // From Twilio's "Validating requests" guide.
        $fields = ['CallSid' => 'CA1234567890ABCDE', 'Caller' => '+12349013030', 'Digits' => '1234', 'From' => '+12349013030', 'To' => '+18005551212'];
        $this->assertSame('0/KCTR6DLpKmkAf8muzZqo1nDgQ=', TwilioProvider::signature('12345', 'https://mycompany.com/myapp.php?foo=1&bar=2', $fields));
    }

    public function test_twilio_browser_phone_token_and_rest_calls(): void
    {
        config(['telephony.driver' => 'twilio', 'telephony.twilio' => ['account_sid' => 'AC1', 'auth_token' => 'tok', 'api_key' => 'SK1', 'api_secret' => 'sk-secret', 'twiml_app_sid' => 'AP1', 'webhook_url' => '', 'country' => 'US']]);
        $this->app->forgetInstance(TelephonyProvider::class);

        $token = $this->actingAs($this->agent)->getJson(route('agent.phone.token'))->assertOk()->json('token');
        [$header, $payload, $signature] = explode('.', $token);
        $decode = fn (string $part) => json_decode(base64_decode(strtr($part, '-_', '+/')), true);
        $this->assertSame('twilio-fpa;v=1', $decode($header)['cty']);
        $claims = $decode($payload);
        $this->assertSame(['SK1', 'AC1', 'agent-'.$this->agent->id, true, 'AP1'], [$claims['iss'], $claims['sub'], $claims['grants']['identity'], $claims['grants']['voice']['incoming']['allow'], $claims['grants']['voice']['outgoing']['application_sid']]);
        $this->assertSame(rtrim(strtr(base64_encode(hash_hmac('sha256', "{$header}.{$payload}", 'sk-secret', true)), '+/', '-_'), '='), $signature);

        Http::fake([
            '*/AvailablePhoneNumbers/US/Local.json*' => Http::response(['available_phone_numbers' => [['phone_number' => '+15125550123', 'locality' => 'Austin', 'region' => 'TX']]]),
            '*/IncomingPhoneNumbers.json' => Http::response(['sid' => 'PN123', 'phone_number' => '+15125550123'], 201),
        ]);
        $provider = app(TelephonyProvider::class);
        $this->assertSame('Austin', $provider->availableNumbers('512')[0]['locality']);
        $this->assertSame(['sid' => 'PN123', 'number' => '+15125550123'], $provider->buyNumber('+15125550123', ['voice' => 'https://x/in', 'status' => 'https://x/st']));
        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/Accounts/AC1/IncomingPhoneNumbers.json') && $r['VoiceUrl'] === 'https://x/in' && $r['StatusCallback'] === 'https://x/st'
            && $r->hasHeader('Authorization', 'Basic '.base64_encode('AC1:tok')));
    }

    public function test_admins_add_edit_and_release_lines(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true, 'must_change_password' => false]);
        $this->actingAs($admin)->get(route('admin.phone-lines'))->assertOk()->assertSee('(512) 555-0100');

        $page = Livewire::test(PhoneLines::class)->set('organization', $this->business->ulid)->set('areaCode', '737')->call('search')->assertHasNoErrors();
        $number = $page->get('found')[0]['number'];
        $page->set('number', $number)->set('transferNumber', 'not a phone')->call('add')->assertHasErrors('transferNumber');
        $page->set('transferNumber', '(737) 555-0142')->set('label', 'Second line')->call('add')->assertHasNoErrors();
        $new = PhoneNumber::withoutGlobalScopes()->where('number', $number)->sole();
        $this->assertSame('+17375550142', $new->transfer_number);
        $this->assertDatabaseHas('audit_logs', ['action' => 'phone_line.added']);

        Livewire::test(PhoneLines::class)->call('edit', $new->ulid)->set('editRecord', false)->set('editTransfer', '')->call('update')->assertHasNoErrors();
        $this->assertFalse($new->fresh()->record_calls);
        $this->assertNull($new->fresh()->transfer_number);

        Livewire::test(PhoneLines::class)->call('release', $new->ulid);
        $this->assertSame('released', $new->fresh()->status);
        $this->assertContains($new->provider_sid, app(TelephonyProvider::class)->released);
        $this->post('/api/webhooks/voice/incoming', ['CallSid' => 'CA5', 'From' => '+15125550147', 'To' => $number])->assertSee('not in service');

        $operations = User::factory()->create(['role' => 'admin', 'is_active' => true, 'must_change_password' => false]);
        $operations->syncRoles(['operations_manager']);
        $this->actingAs($operations)->get(route('admin.phone-lines'))->assertOk();
        Livewire::test(PhoneLines::class)->call('release', $this->line->ulid)->assertForbidden();
        $this->actingAs($this->business->owner)->get(route('admin.phone-lines'))->assertForbidden();
    }

    public function test_recordings_play_only_for_people_allowed_to_hear_them(): void
    {
        $this->app->instance(TelephonyProvider::class, new class extends FakeProvider
        {
            public function recordingAudio(string $recordingSid): ?string
            {
                return 'ID3-mp3-bytes';
            }
        });
        $this->ready($this->agent);
        $this->ring();
        $call = VoiceCall::withoutGlobalScopes()->sole();
        $call->forceFill(['recording_sid' => 'RE9', 'status' => VoiceCall::COMPLETED])->save();

        $this->actingAs($this->business->owner)->get(route('calls.recording', $call))->assertOk()->assertHeader('Content-Type', 'audio/mpeg');
        $this->assertSame(1, AuditLog::query()->where('action', 'call.recording_played')->count());
        $this->actingAs($this->agent)->get(route('calls.recording', $call))->assertNotFound(); // agents without the permission
        $stranger = User::factory()->create(['role' => 'client', 'is_active' => true, 'must_change_password' => false]);
        app(ProvisionUserTenancy::class)->handle($stranger);
        $this->actingAs($stranger->fresh())->get(route('calls.recording', $call))->assertNotFound();
    }
}
