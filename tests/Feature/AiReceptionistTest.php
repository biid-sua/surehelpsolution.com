<?php

namespace Tests\Feature;

use App\Actions\Inbox\SendReply;
use App\Actions\Organizations\ProvisionUserTenancy;
use App\Enums\InboxChannel;
use App\Enums\TaskType;
use App\Livewire\Client\AiReceptionist;
use App\Models\Appointment;
use App\Models\BusinessHour;
use App\Models\BusinessProfile;
use App\Models\Conversation;
use App\Models\Escalation;
use App\Models\Organization;
use App\Models\PhoneNumber;
use App\Models\Task;
use App\Models\User;
use App\Models\VoiceCall;
use App\Models\VoiceReceptionist;
use App\Services\Ai\Contracts\AiProvider;
use App\Services\Billing\FeatureAccess;
use App\Services\Metrics\ResultsReport;
use App\Services\Telephony\AgentPresence;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\ScriptedAiProvider;
use Tests\TestCase;

/**
 * The AI receptionist (D108): answers calls nobody can take, by voice, with the messaging
 * assistant's brain and tools; books, hands over and puts callers through; every call in the Inbox.
 */
class AiReceptionistTest extends TestCase
{
    use RefreshDatabase;

    private const TZ = 'America/Chicago';

    private ScriptedAiProvider $ai;

    private User $owner;

    private User $agent;

    private Organization $business;

    private PhoneNumber $line;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Carbon::setTestNow(CarbonImmutable::parse('2026-10-14 10:00', self::TZ)); // Wednesday morning
        $this->ai = new ScriptedAiProvider;
        $this->app->instance(AiProvider::class, $this->ai);

        $this->owner = User::factory()->create(['role' => 'client', 'is_active' => true, 'must_change_password' => false]);
        $this->business = app(ProvisionUserTenancy::class)->handle($this->owner);
        $this->business->forceFill(['name' => 'Rivera Plumbing', 'timezone' => self::TZ, 'setup_completed_at' => now()])->save();
        BusinessProfile::create(['organization_id' => $this->business->id, 'display_name' => 'Rivera Plumbing', 'phone' => '5125550100']);
        foreach ([1, 2, 3, 4, 5] as $day) {
            BusinessHour::create(['organization_id' => $this->business->id, 'day_of_week' => $day, 'opens_at' => '08:00', 'closes_at' => '17:00']);
        }
        $this->agent = User::factory()->create(['role' => 'agent', 'is_active' => true, 'must_change_password' => false]);
        $this->business->assignAgent($this->agent);
        $this->line = PhoneNumber::create(['organization_id' => $this->business->id, 'provider' => 'fake', 'provider_sid' => 'PN1', 'number' => '+15125550100', 'transfer_number' => '+15125550199']);

        // The feature is for picked businesses only at first; this one is picked.
        app(FeatureAccess::class)->pick($this->business, 'ai_receptionist', true, null);
        VoiceReceptionist::create(['organization_id' => $this->business->id, 'mode' => 'backup']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function ring(string $sid = 'CA1', string $from = '+15125550147'): string
    {
        return $this->post('/api/webhooks/voice/incoming', ['CallSid' => $sid, 'From' => $from, 'To' => '+15125550100'])->assertOk()->getContent();
    }

    private function say(VoiceCall $call, string $speech): string
    {
        return $this->post(route('webhooks.voice.ai', $call), ['CallSid' => $call->provider_call_sid, 'SpeechResult' => $speech])->assertOk()->getContent();
    }

    private function voiceCall(string $sid = 'CA1'): VoiceCall
    {
        return VoiceCall::withoutGlobalScopes()->where('provider_call_sid', $sid)->sole();
    }

    public function test_when_nobody_is_free_it_answers_books_the_job_and_ends_the_call(): void
    {
        $xml = $this->ring();
        $this->assertStringContainsString('<Gather', $xml);
        $this->assertStringContainsString('Thanks for calling Rivera Plumbing. I\'m the virtual assistant.', $xml);
        $call = $this->voiceCall();
        $this->assertStringContainsString(route('webhooks.voice.ai', $call), $xml);
        $this->assertSame(VoiceCall::IN_PROGRESS, $call->status);
        $conversation = Conversation::withoutGlobalScopes()->findOrFail($call->ai_conversation_id);
        $this->assertSame(InboxChannel::Phone, $conversation->channel);

        // The caller asks for a plumber tomorrow at 10; it books.
        $this->ai->tool('book_appointment', ['starts_at' => '2026-10-15 10:00', 'name' => 'Ana Lopez', 'phone' => '+15125550147', 'notes' => 'Leaking pipe'])
            ->reply('You\'re booked for Thursday at ten. Anything else?');
        $xml = $this->say($call, 'Hi, I\'m Ana, my pipe is leaking, can someone come tomorrow at ten?');
        $this->assertStringContainsString('<Gather', $xml);
        $this->assertStringContainsString('You\'re booked for Thursday at ten.', $xml);
        $this->assertStringContainsString('Polly.Joanna-Neural', $xml);
        $appointment = Appointment::query()->forOrganization($this->business)->sole();
        $this->assertSame('ai_phone', $appointment->source);
        $this->assertStringContainsString('This is a phone call', $this->ai->requests[0]->instructions);
        $this->assertStringContainsString('(512) 555-0147', $this->ai->requests[0]->instructions);
        $this->assertContains('end_call', $this->ai->toolNames(0));
        $this->assertContains('transfer_to_owner', $this->ai->toolNames(0));

        // Nothing else: it says goodbye and hangs up.
        $this->ai->tool('end_call', ['summary' => 'Booked'])->reply('Thanks for calling. Goodbye.');
        $xml = $this->say($call, 'No, that\'s all, thanks');
        $this->assertStringContainsString('<Hangup', $xml);
        $this->assertStringNotContainsString('<Gather', $xml);

        // The whole call is in the Inbox; it counts in Results; nobody needs to call back.
        $this->assertSame(5, $conversation->messages()->count());
        $this->post('/api/webhooks/voice/status', ['CallSid' => 'CA1', 'CallStatus' => 'completed'])->assertNoContent();
        $this->assertSame(VoiceCall::COMPLETED, $call->fresh()->status);
        $this->assertSame(0, Task::query()->forOrganization($this->business)->where('type', TaskType::Callback->value)->count());
        $report = app(ResultsReport::class);
        $period = $report->month($this->business);
        $r = $report->compute($this->business, $period['start'], $period['end']);
        $this->assertSame([1, 1], [$r['ai_calls'], $r['ai_booked']]);

        $this->actingAs($this->owner)->get(route('app.inbox.show', $conversation))->assertOk()
            ->assertSee('my pipe is leaking')->assertSee('This was a phone call with the AI receptionist');
    }

    public function test_when_it_answers_follows_the_businesss_choice(): void
    {
        // "backup": an agent is free, so the agent rings; nobody picks up, so it answers.
        app(AgentPresence::class)->set($this->agent, AgentPresence::AVAILABLE);
        $this->assertStringContainsString('<Client', $this->ring('CA1'));
        $xml = $this->post(route('webhooks.voice.dial-finished', $this->voiceCall('CA1')), ['DialCallStatus' => 'no-answer'])->assertOk()->getContent();
        $this->assertStringContainsString('<Gather', $xml);

        // "always": it answers first, even with an agent free.
        VoiceReceptionist::query()->update(['mode' => 'always']);
        $this->assertStringContainsString('<Gather', $this->ring('CA2'));

        // "after_hours": during the day nothing changes (no agent free: the owner's phone); at night it answers.
        VoiceReceptionist::query()->update(['mode' => 'after_hours']);
        app(AgentPresence::class)->set($this->agent, AgentPresence::OFFLINE);
        $this->assertStringContainsString('<Number>+15125550199</Number>', $this->ring('CA3'));
        Carbon::setTestNow(CarbonImmutable::parse('2026-10-14 21:00', self::TZ));
        $this->line->update(['after_hours' => 'voicemail']);
        $this->assertStringContainsString('<Gather', $this->ring('CA4'));

        // Off, not picked for the business, or no AI connected: as before.
        VoiceReceptionist::query()->update(['mode' => 'off']);
        $this->assertStringNotContainsString('<Gather', $this->ring('CA5'));
        VoiceReceptionist::query()->update(['mode' => 'always']);
        app(FeatureAccess::class)->pick($this->business, 'ai_receptionist', false, null);
        $this->assertStringNotContainsString('<Gather', $this->ring('CA6'));
        app(FeatureAccess::class)->pick($this->business, 'ai_receptionist', true, null);
        $this->ai->configured = false;
        $this->assertStringNotContainsString('<Gather', $this->ring('CA7'));
    }

    public function test_it_puts_callers_through_or_hands_over_and_never_leaves_silence_unanswered(): void
    {
        // The caller asks for a person: put through to the owner's phone.
        $this->ring('CA1');
        $this->ai->tool('transfer_to_owner', ['reason' => 'Wants to talk about a quote'])->reply('Of course, I\'m putting you through now.');
        $xml = $this->say($this->voiceCall('CA1'), 'Can I talk to the owner please?');
        $this->assertStringContainsString('putting you through', $xml);
        $this->assertStringContainsString('<Number>+15125550199</Number>', $xml);

        // Without transfers, "hand over" raises an escalation, says goodbye and hangs up.
        VoiceReceptionist::query()->update(['transfer_to_owner' => false]);
        $this->ring('CA2');
        $this->ai->tool('hand_over_to_team', ['reason' => 'Gas smell', 'category' => 'emergency'])->reply('');
        $xml = $this->say($this->voiceCall('CA2'), 'I smell gas in the basement');
        $this->assertNotContains('transfer_to_owner', $this->ai->toolNames(2));
        $this->assertStringContainsString('<Hangup', $xml);
        $this->assertStringContainsString('someone will call you back', $xml);
        $this->assertSame(1, Escalation::query()->forOrganization($this->business)->count());

        // Silence twice: the team gets a call-back task and the call ends.
        $this->ring('CA3');
        $this->assertStringContainsString('didn\'t catch that', $this->say($this->voiceCall('CA3'), ''));
        $xml = $this->say($this->voiceCall('CA3'), '');
        $this->assertStringContainsString('<Hangup', $xml);
        $this->assertSame(1, Task::query()->forOrganization($this->business)->where('type', TaskType::Callback->value)->count());

        // The AI is down: an apology, a call-back task and a goodbye.
        $this->ring('CA4');
        $this->ai->fail();
        $xml = $this->say($this->voiceCall('CA4'), 'Hello?');
        $this->assertStringContainsString('having trouble', $xml);
        $this->assertSame(2, Task::query()->forOrganization($this->business)->where('type', TaskType::Callback->value)->count());
    }

    public function test_a_caller_who_never_speaks_is_a_missed_call(): void
    {
        $this->ring('CA1');
        $this->post('/api/webhooks/voice/status', ['CallSid' => 'CA1', 'CallStatus' => 'completed'])->assertNoContent();
        $this->assertSame(VoiceCall::MISSED, $this->voiceCall('CA1')->status);
        $this->assertSame(1, Task::query()->forOrganization($this->business)->where('type', TaskType::Callback->value)->count());

        // A phone call can't be replied to from the Inbox; a team note still can.
        $conversation = Conversation::withoutGlobalScopes()->findOrFail($this->voiceCall('CA1')->ai_conversation_id);
        try {
            app(SendReply::class)->handle($conversation, 'Hi!', $this->owner);
            $this->fail('a reply was sent to a phone call');
        } catch (ValidationException) {
        }
        app(SendReply::class)->handle($conversation, 'Called them back', $this->owner, note: true);
        $this->assertSame(1, $conversation->messages()->where('is_note', true)->count());

        // A turn for a call it didn't answer, or that's over, just hangs up.
        $this->assertStringContainsString('<Hangup', $this->say($this->voiceCall('CA1'), 'Hello'));
    }

    public function test_owners_set_it_up_and_practice_without_anything_being_done(): void
    {
        $this->enableTwoFactor($this->owner);
        $this->actingAs($this->owner)->get(route('app.ai-receptionist'))->assertOk()->assertSee('When it answers')->assertSee('Try it');
        $this->actingAs($this->owner)->get(route('app.dashboard'))->assertSee(route('app.ai-receptionist'), false);

        Livewire::test(AiReceptionist::class)
            ->set('form.greeting', 'Hello, Rivera Plumbing here, how can I help?')->call('save')->assertHasErrors('form.greeting')
            ->set('form.greeting', 'Hi, you\'ve reached {business}. I\'m an AI assistant. What can I do for you?')
            ->set('form.mode', 'always')->set('form.voice', 'Polly.Matthew-Neural')->call('save')->assertHasNoErrors()->assertDispatched('toast', type: 'success');
        $settings = VoiceReceptionist::for($this->business);
        $this->assertSame(['always', 'Polly.Matthew-Neural'], [$settings->mode, $settings->voice]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'ai_receptionist.updated']);

        // Practice: it "books", but nothing is really booked.
        $this->ai->tool('book_appointment', ['starts_at' => '2026-10-15 10:00', 'name' => 'Test', 'phone' => '+15125550147'])->reply('All set for Thursday at ten.');
        Livewire::test(AiReceptionist::class)
            ->assertSee('Hi, you\'ve reached Rivera Plumbing. I\'m an AI assistant.')
            ->set('practiceInput', 'Can you come tomorrow at ten?')->call('sendPractice')
            ->assertSee('All set for Thursday at ten.')->assertSee('book appointment: practice only');
        $this->assertSame(0, Appointment::query()->forOrganization($this->business)->count());
        $this->assertSame(0, Conversation::query()->forOrganization($this->business)->count());
        $this->assertStringContainsString('practice call', $this->ai->requests[0]->instructions);
    }

    public function test_only_picked_businesses_and_people_who_manage_ai_get_it(): void
    {
        // Another business, not picked: no menu item and no page.
        $other = User::factory()->create(['role' => 'client', 'is_active' => true, 'must_change_password' => false]);
        app(ProvisionUserTenancy::class)->handle($other);
        $this->actingAs($other)->get(route('app.ai-receptionist'))->assertNotFound();
        $this->actingAs($other)->get(route('app.dashboard'))->assertOk()->assertDontSee(route('app.ai-receptionist'), false);

        // Staff of the picked business (no AI permissions) can't open it.
        $staff = User::factory()->create(['role' => 'client', 'is_active' => true, 'must_change_password' => false]);
        $this->business->members()->attach($staff->id, ['role' => 'staff', 'status' => 'active']);
        $this->enableTwoFactor($staff);
        $this->actingAs($staff)->get(route('app.ai-receptionist'))->assertForbidden();
    }
}
