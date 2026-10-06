<?php

namespace Tests\Feature;

use App\Actions\Inbox\ReceiveMessage;
use App\Actions\Organizations\ProvisionUserTenancy;
use App\Enums\InboxChannel;
use App\Enums\KnowledgeVisibility;
use App\Jobs\RespondToConversation;
use App\Livewire\Client\Inbox\Assistant;
use App\Livewire\Client\Inbox\Index as Inbox;
use App\Models\AiAssistant;
use App\Models\AiGuideline;
use App\Models\AiRun;
use App\Models\Appointment;
use App\Models\BusinessHour;
use App\Models\BusinessService;
use App\Models\ChatWidget;
use App\Models\Conversation;
use App\Models\Escalation;
use App\Models\KnowledgeItem;
use App\Models\Message;
use App\Models\Organization;
use App\Models\Task;
use App\Models\User;
use App\Notifications\InboxMessageReceived;
use App\Services\Ai\Assistant\ConversationAssistant;
use App\Services\Ai\Contracts\AiProvider;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Support\ScriptedAiProvider;
use Tests\TestCase;

/**
 * M-2 / M-3 — the AI messaging assistant and feedback (spec §26A, D38–D39).
 */
class AiAssistantTest extends TestCase
{
    use RefreshDatabase;

    private const TZ = 'America/Chicago';

    private ScriptedAiProvider $ai;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Bus::fake([RespondToConversation::class]); // these tests run the assistant directly
        Carbon::setTestNow(CarbonImmutable::parse('2026-10-05 08:00', self::TZ)); // a Monday morning
        $this->ai = new ScriptedAiProvider;
        $this->app->instance(AiProvider::class, $this->ai);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array{0: User, 1: Organization, 2: BusinessService} */
    private function business(string $mode = 'auto', array $assistant = []): array
    {
        $owner = User::factory()->create(['role' => 'client', 'is_active' => true, 'must_change_password' => false]);
        $org = app(ProvisionUserTenancy::class)->handle($owner);
        $org->update(['timezone' => self::TZ, 'name' => 'Bright Plumbing']);
        foreach ([1, 2, 3, 4, 5] as $day) {
            BusinessHour::create(['organization_id' => $org->id, 'day_of_week' => $day, 'opens_at' => '09:00', 'closes_at' => '17:00']);
        }
        $service = BusinessService::create([
            'organization_id' => $org->id, 'name' => 'Boiler service', 'duration_minutes' => 60, 'price_type' => 'fixed',
            'price_cents' => 12000, 'currency' => 'USD', 'is_active' => true, 'is_bookable' => true, 'agent_instructions' => 'Ask the boiler brand.',
        ]);
        AiAssistant::create(array_merge([
            'organization_id' => $org->id, 'is_enabled' => true, 'name' => 'Sam',
            'modes' => ['web_chat' => $mode, 'facebook' => $mode, 'instagram' => $mode],
        ], $assistant));

        return [$owner, $org->fresh(), $service];
    }

    private function inbound(Organization $org, string $text, string $thread = 'visitor-1', InboxChannel $channel = InboxChannel::WebChat): Message
    {
        return app(ReceiveMessage::class)->handle($org, $channel, ['channel_key' => 'web', 'thread' => $thread, 'body' => $text]);
    }

    private function respond(Message $message): ?AiRun
    {
        return app(ConversationAssistant::class)->respond($message->conversation, $message->id);
    }

    public function test_it_books_an_appointment_like_an_agent_and_replies(): void
    {
        [, $org, $service] = $this->business();
        KnowledgeItem::create(['organization_id' => $org->id, 'type' => 'faq', 'title' => 'Parking', 'content' => 'Free parking at the back.', 'visibility' => KnowledgeVisibility::Public]);
        KnowledgeItem::create(['organization_id' => $org->id, 'type' => 'policy', 'title' => 'Owner margin', 'content' => 'SECRET-MARGIN-40%', 'visibility' => KnowledgeVisibility::TeamOnly]);

        $this->ai->tool('get_available_times', ['date' => '2026-10-06', 'service_id' => $service->id])
            ->tool('book_appointment', ['starts_at' => '2026-10-06 10:00', 'service_id' => $service->id, 'name' => 'Maria Lopez', 'phone' => '(512) 555-0147'])
            ->reply('You\'re booked for a boiler service on Tuesday 6 October at 10:00 AM. See you then!');

        $run = $this->respond($this->inbound($org, 'Can you service my boiler on Tuesday morning? I\'m Maria, 512 555 0147'));

        // Booked through the normal booking rules, by the AI, for a customer it saved.
        $appointment = Appointment::sole();
        $this->assertSame('ai', $appointment->source);
        $this->assertSame('confirmed', $appointment->status->value);
        $this->assertSame('2026-10-06 10:00', $appointment->starts_at->setTimezone(self::TZ)->format('Y-m-d H:i'));
        $conversation = Conversation::sole();
        $this->assertSame('Maria Lopez', $conversation->customer->fullName());
        $this->assertSame($conversation->customer_id, $appointment->customer_id);

        // The reply went out, labelled as the AI, and the run is logged with both tool calls.
        $reply = Message::where('author_type', 'ai')->sole();
        $this->assertSame('sent', $reply->status);
        $this->assertSame('replied', $run->status);
        $this->assertSame(['get_available_times', 'book_appointment'], array_column($run->tool_calls, 'name'));
        $this->assertSame(3, $run->steps);
        $this->assertFalse($conversation->needs_human);

        // What the model was given: tools to act, the business's facts, never team-only knowledge, the time now.
        $first = $this->ai->requests[0];
        $this->assertContains('book_appointment', $this->ai->toolNames());
        $this->assertStringContainsString('Boiler service', $first->instructions);
        $this->assertStringContainsString('Ask the boiler brand.', $first->instructions);
        $this->assertStringContainsString('Free parking at the back.', $first->instructions);
        $this->assertStringNotContainsString('SECRET-MARGIN', $first->instructions);
        $this->assertStringContainsString('Monday 5 October 2026', $first->context);
        $this->assertStringNotContainsString('2026', $first->instructions, 'the cached instructions never contain the date');
        $this->assertStringContainsString('10:00 AM', $this->ai->toolResults(1)[0]['content'], 'available times were returned to the model');
    }

    public function test_bookings_follow_the_same_rules_as_agents(): void
    {
        [, $org, $service] = $this->business();
        $this->ai->tool('book_appointment', ['starts_at' => '2026-10-04 10:00', 'service_id' => $service->id, 'name' => 'Sun Day', 'phone' => '5125550100']) // a Sunday: closed
            ->reply('Sorry, we\'re closed on Sundays. Shall I look at Monday?');

        $this->respond($this->inbound($org, 'Sunday at 10?'));

        $this->assertSame(0, Appointment::count());
        $result = $this->ai->toolResults(1)[0];
        $this->assertTrue($result['is_error']);
    }

    public function test_bookings_can_wait_for_the_team_to_confirm_or_be_switched_off(): void
    {
        [, $org, $service] = $this->business(assistant: ['bookings_need_confirmation' => true]);
        $this->ai->tool('book_appointment', ['starts_at' => '2026-10-06 11:00', 'service_id' => $service->id, 'name' => 'Pat', 'email' => 'pat@example.test'])->reply('Requested!');
        $this->respond($this->inbound($org, 'Book me in'));
        $this->assertSame('pending', Appointment::sole()->status->value);

        AiAssistant::query()->update(['can_book' => false]);
        $this->ai->reply('Our team will book that for you.');
        $this->respond($this->inbound($org, 'Another one please', 'visitor-2'));
        $this->assertNotContains('book_appointment', $this->ai->toolNames(2));
    }

    public function test_it_hands_over_and_steps_back(): void
    {
        [$owner, $org] = $this->business();
        $this->ai->tool('hand_over_to_team', ['reason' => 'Customer wants a refund for last week\'s visit', 'category' => 'refund_request'])->reply('');

        $message = $this->inbound($org, 'I want my money back!');
        $run = $this->respond($message);

        $this->assertSame('handed_over', $run->status);
        $escalation = Escalation::sole();
        $this->assertSame('refund_request', $escalation->type->value);
        $this->assertSame('ai', $escalation->source);
        $conversation = $message->conversation->fresh();
        $this->assertTrue($conversation->needs_human);
        $this->assertTrue($conversation->ai_paused);
        $this->assertStringContainsString('passed this to our team', Message::where('author_type', 'ai')->sole()->body, 'the default hand-over message is used');

        // The next message is for the team: no AI call, and the team is told.
        $next = $this->inbound($org, 'Hello??');
        $this->assertSame('skipped', $this->respond($next)->status);
        $this->assertCount(2, $this->ai->requests, 'no further AI call after the hand-over');
        Notification::assertSentTo($owner, InboxMessageReceived::class);
    }

    public function test_a_team_reply_pauses_the_ai_until_handed_back(): void
    {
        [$owner, $org] = $this->business();
        $message = $this->inbound($org, 'Hi there');
        $this->ai->reply('Hello! How can I help?');
        $this->respond($message);

        $this->actingAs($owner);
        Livewire::test(Inbox::class, ['conversation' => $message->conversation->ulid])->set('reply', 'Hi, this is Tom from the office.')->call('send')->assertHasNoErrors();
        $this->assertTrue($message->conversation->fresh()->ai_paused);

        $run = $this->respond($this->inbound($org, 'Thanks Tom, one more question'));
        $this->assertSame('skipped', $run->status);
        $this->assertCount(1, $this->ai->requests);

        Livewire::test(Inbox::class, ['conversation' => $message->conversation->ulid])->call('setAi', true);
        $this->ai->reply('Of course, what is it?');
        $this->assertSame('replied', $this->respond($this->inbound($org, 'Are you open Saturday?'))->status);
    }

    public function test_suggest_mode_drafts_for_a_person_with_read_only_tools(): void
    {
        [$owner, $org] = $this->business('suggest');
        $this->ai->reply('We\'re open 9 to 5 on weekdays.');

        $message = $this->inbound($org, 'When are you open?');
        $this->assertTrue($message->conversation->fresh()->needs_human, 'a person has to send it');
        $run = $this->respond($message);

        $this->assertSame('drafted', $run->status);
        $draft = Message::where('status', 'draft')->sole();
        $this->assertSame(['get_available_times', 'search_knowledge'], $this->ai->toolNames());

        // Not visible to the visitor until a person sends it.
        $widget = ChatWidget::for($org);
        $this->assertCount(1, $this->visitorMessages($widget, $message->conversation));

        $this->actingAs($owner);
        Livewire::test(Inbox::class, ['conversation' => $message->conversation->ulid])->call('sendDraft', $draft->ulid)->assertHasNoErrors();
        $this->assertSame('used', $draft->fresh()->status);
        $sent = Message::where('status', 'sent')->where('direction', 'out')->sole();
        $this->assertSame('We\'re open 9 to 5 on weekdays.', $sent->body);
        $this->assertFalse($message->conversation->fresh()->needs_human);
    }

    public function test_it_never_auto_replies_outside_metas_24_hours(): void
    {
        [, $org] = $this->business();
        $message = $this->inbound($org, 'Hello', 'psid-1', InboxChannel::Facebook);
        Carbon::setTestNow(now()->addHours(25));

        $run = $this->respond($message);
        $this->assertSame('skipped', $run->status);
        $this->assertSame([], $this->ai->requests);
    }

    public function test_switched_off_or_without_a_key_nothing_is_sent_to_the_ai(): void
    {
        [$owner, $org] = $this->business();
        $this->ai->configured = false;
        $this->assertNull($this->respond($this->inbound($org, 'Hello')));
        Notification::assertSentTo($owner, InboxMessageReceived::class);

        $this->ai->configured = true;
        $this->actingAs($owner);
        Livewire::test(Assistant::class)->call('stopAll');
        $this->assertNull($this->respond($this->inbound($org, 'Hello again', 'visitor-9')));
        $this->assertSame([], $this->ai->requests);
    }

    public function test_refusals_errors_and_runaway_loops_go_to_the_team(): void
    {
        [, $org] = $this->business();
        $this->ai->reply('', 'refusal');
        $first = $this->inbound($org, 'Something odd');
        $this->respond($first);
        $this->assertTrue($first->conversation->fresh()->needs_human);

        $this->ai->fail();
        $second = $this->inbound($org, 'Hello', 'visitor-2');
        $this->assertSame('failed', $this->respond($second)->status);
        $this->assertTrue($second->conversation->fresh()->needs_human);

        foreach (range(1, 6) as $i) {
            $this->ai->tool('search_knowledge', ['query' => 'loop '.$i]);
        }
        $third = $this->inbound($org, 'Tell me everything', 'visitor-3');
        $this->respond($third);
        $this->assertTrue($third->conversation->fresh()->needs_human);
        $this->assertSame(6, AiRun::where('conversation_id', $third->conversation_id)->value('steps'));
    }

    public function test_feedback_becomes_a_guideline_once_approved(): void
    {
        [$owner, $org] = $this->business();
        $this->ai->reply('Yes, we install boilers the same day!');
        $message = $this->inbound($org, 'Same-day install?');
        $this->respond($message);
        $aiReply = Message::where('author_type', 'ai')->sole();

        $this->actingAs($owner);
        Livewire::test(Inbox::class, ['conversation' => $message->conversation->ulid])
            ->call('rate', $aiReply->ulid, 'unhelpful')
            ->set('correction.'.$aiReply->ulid, 'We never promise same-day installs; offer the next weekday.')
            ->call('saveCorrection', $aiReply->ulid)
            ->assertHasNoErrors();

        $guideline = AiGuideline::sole();
        $this->assertSame('draft', $guideline->status);

        // Not used until approved.
        $this->ai->reply('Let me check.');
        $this->respond($this->inbound($org, 'Hi', 'visitor-2'));
        $this->assertStringNotContainsString('never promise same-day', $this->ai->requests[1]->instructions);

        Livewire::test(Assistant::class)->call('approveGuideline', $guideline->ulid);
        $this->ai->reply('We can do the next weekday.');
        $this->respond($this->inbound($org, 'Hi again', 'visitor-3'));
        $this->assertStringContainsString('never promise same-day', $this->ai->requests[2]->instructions);
    }

    public function test_follow_up_and_details_tools_create_records_for_the_team(): void
    {
        [, $org] = $this->business();
        $this->ai->tool('save_customer_details', ['name' => 'Ana Ruiz', 'email' => 'ana@example.test'])
            ->tool('create_follow_up', ['summary' => 'Wants a quote for a new boiler', 'call_back' => true])
            ->reply('Thanks Ana, our team will call you back about the quote.');

        $message = $this->inbound($org, 'Can I get a quote for a new boiler? I\'m Ana, ana@example.test');
        $this->respond($message);

        $task = Task::sole();
        $this->assertSame('callback', $task->type->value);
        $this->assertSame('ai', $task->source);
        $this->assertSame($message->conversation->fresh()->customer_id, $task->customer_id);
        $this->assertStringContainsString('Wants a quote', (string) $task->description);
    }

    public function test_settings_need_ai_manage_and_staff_cannot_open_them(): void
    {
        [$owner, $org] = $this->business('off');
        $staff = User::factory()->create(['role' => 'client', 'is_active' => true, 'must_change_password' => false]);
        $org->members()->attach($staff->id, ['role' => 'staff', 'status' => 'active']);

        $this->actingAs($owner)->get(route('app.inbox.assistant'))->assertOk()->assertSee('Use the AI assistant');
        Livewire::test(Assistant::class)->set('form.modes.web_chat', 'auto')->set('form.name', 'Riley')->call('save')->assertHasNoErrors();
        $this->assertSame('auto', AiAssistant::for($org)->modeFor(InboxChannel::WebChat));
        $this->assertSame('Riley', AiAssistant::for($org)->name);

        $this->actingAs($staff)->get(route('app.inbox.assistant'))->assertForbidden();
        $this->actingAs($staff)->get(route('app.inbox.index'))->assertOk();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function visitorMessages(ChatWidget $widget, Conversation $conversation): array
    {
        return Message::where('conversation_id', $conversation->id)->where('is_note', false)->whereIn('status', ['received', 'sent'])->get()->all();
    }
}
