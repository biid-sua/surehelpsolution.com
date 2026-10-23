<?php

namespace Tests\Feature;

use App\Livewire\Admin\Enquiries\Index as Enquiries;
use App\Mail\SalesChatReplyMail;
use App\Mail\SalesDemoBookedMail;
use App\Mail\SalesLeadAlertMail;
use App\Models\ContactSubmission;
use App\Models\SalesChat;
use App\Models\User;
use App\Services\Ai\Contracts\AiProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Tests\Support\ScriptedAiProvider;
use Tests\TestCase;

/**
 * SureHelp's own sales chat on the website (D78): leads, demo bookings and a light touch on the AI.
 */
class SalesChatTest extends TestCase
{
    use RefreshDatabase;

    private ScriptedAiProvider $ai;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-09 14:00:00');   // Monday 9:00 AM in New York
        config(['sales.demos.timezone' => 'America/New_York', 'sales.notify' => 'sales@surehelp.test', 'sales.ai.model' => 'claude-haiku-5-5']);
        Mail::fake();
        $this->ai = new ScriptedAiProvider;
        $this->app->instance(AiProvider::class, $this->ai);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @param array<string, mixed> $body */
    private function chat(string $method, string $uri, array $body = [], string $origin = 'http://localhost'): TestResponse
    {
        return $this->call($method, '/api/sales-chat/'.$uri, $method === 'GET' ? $body : [], [], [],
            ['HTTP_ORIGIN' => $origin, 'CONTENT_TYPE' => 'text/plain;charset=UTF-8'], $method === 'GET' ? null : json_encode($body));
    }

    private function start(array $body = []): string
    {
        return $this->chat('POST', 'start', $body + ['page' => '/', 'utm' => ['source' => 'google', 'campaign' => 'fall']])->assertOk()->json('token');
    }

    public function test_a_visitor_starts_and_resumes_a_chat_from_our_own_site_only(): void
    {
        $start = $this->chat('POST', 'start', ['page' => '/pricing', 'utm' => ['source' => 'google', 'evil' => 'x']])->assertOk()
            ->assertJsonPath('assistant', 'Ava')->assertJsonPath('ai', true)
            ->assertJsonPath('quick_replies.0.id', 'demo')->assertJsonCount(1, 'messages');
        $this->assertStringContainsString('plans', $start->json('messages.0.text'), 'the greeting fits the page');
        $token = $start->json('token');
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{48}$/', $token);
        $chat = SalesChat::sole();
        $this->assertSame(hash('sha256', $token), $chat->token_hash, 'only the hash is stored');
        $this->assertSame(['source' => 'google'], $chat->utm);

        $this->chat('POST', 'start', ['token' => $token])->assertOk()->assertJsonCount(1, 'messages')->assertJsonPath('token', $token);
        $this->assertSame(1, SalesChat::count());

        $this->chat('POST', 'start', [], 'https://evil.example')->assertForbidden();
        $this->chat('POST', 'messages', ['token' => 'nope', 'text' => 'hi'])->assertNotFound();

        config(['sales.ai.chats_per_ip_per_day' => 1]);
        $this->chat('POST', 'start', [])->assertStatus(429);
    }

    public function test_buttons_answer_without_the_ai(): void
    {
        $token = $this->start();

        $pricing = $this->chat('POST', 'messages', ['token' => $token, 'quick' => 'pricing'])->assertOk();
        $this->assertSame(['you', 'assistant'], array_column($pricing->json('messages'), 'from'));
        $this->assertStringContainsString('$300/month', $pricing->json('messages.1.text'));

        $demo = $this->chat('POST', 'messages', ['token' => $token, 'quick' => 'demo'])->assertOk()->assertJsonPath('action.type', 'slots');
        $first = $demo->json('action.days.0');
        $this->assertSame('Today', $first['label']);
        $this->assertSame('11:00 AM', $first['slots'][0]['label'], 'two hours notice from 9:00');

        $this->chat('POST', 'messages', ['token' => $token, 'quick' => 'bogus'])->assertStatus(422);
        $this->assertSame([], $this->ai->requests, 'no AI call for buttons');
    }

    public function test_pick_a_time_then_leave_details_and_the_demo_is_booked(): void
    {
        $token = $this->start();
        $slot = $this->chat('POST', 'messages', ['token' => $token, 'quick' => 'demo'])->json('action.days.0.slots.0.starts_at');

        $this->chat('POST', 'book', ['token' => $token, 'starts_at' => $slot])->assertOk()
            ->assertJsonPath('action.type', 'contact')->assertJsonPath('action.required', ['name', 'email']);
        $this->assertSame(0, ContactSubmission::count(), 'no lead before they leave details');

        $this->chat('POST', 'lead', ['token' => $token, 'name' => 'Dana Ortiz', 'email' => 'nope'])->assertStatus(422)->assertJsonStructure(['errors' => ['email']]);
        $done = $this->chat('POST', 'lead', ['token' => $token, 'name' => 'Dana Ortiz', 'email' => 'dana@ortizdental.test', 'phone' => '(512) 555-0101', 'company' => 'Ortiz Dental', 'sms_consent' => false])
            ->assertOk()->assertJsonPath('booking.timezone', 'America/New_York')->assertJsonPath('lead.complete', true);
        $this->assertStringContainsString('Monday, November 9 at 11:00 AM', $done->json('messages.0.text'));

        $enquiry = ContactSubmission::sole();
        $this->assertSame(['chat', 'demo', 'booked', 'hot', false], [$enquiry->source, $enquiry->inquiry_type, $enquiry->demo_status, $enquiry->score, $enquiry->sms_consent]);
        $this->assertTrue($enquiry->demo_at->equalTo(Carbon::parse('2026-11-09 16:00:00')));
        Mail::assertQueued(SalesDemoBookedMail::class, function (SalesDemoBookedMail $mail) {
            $ics = $mail->ics();

            return $mail->hasTo('dana@ortizdental.test') && str_contains($ics, 'DTSTART:20261109T160000Z') && str_contains($ics, 'DTEND:20261109T163000Z')
                && str_contains($ics, 'mailto:dana@ortizdental.test');
        });
        Mail::assertQueued(SalesLeadAlertMail::class, fn ($mail) => $mail->hasTo('sales@surehelp.test') && str_contains($mail->headline, 'Demo booked'));

        // The same time is gone for the next visitor.
        $other = $this->start();
        $this->chat('POST', 'lead', ['token' => $other, 'name' => 'Sam Lee', 'email' => 'sam@lee.test']);
        $this->chat('POST', 'book', ['token' => $other, 'starts_at' => $slot])->assertStatus(409)->assertJsonPath('action.type', 'slots');
        $this->assertNotSame($slot, $this->chat('POST', 'messages', ['token' => $other, 'quick' => 'demo'])->json('action.days.0.slots.0.starts_at'));
    }

    public function test_the_ai_answers_questions_lightly_and_learns_about_the_lead(): void
    {
        $token = $this->start();
        $this->ai->tool('save_lead_details', ['industry' => 'dental clinic', 'monthly_calls' => 'about 400', 'timeline' => 'this week', 'evil' => 'x'])
            ->tool('offer_demo_times', ['part_of_day' => 'afternoon'])
            ->reply('Happy to help a dental clinic! Pick a time below for a quick demo.');

        $answer = $this->chat('POST', 'messages', ['token' => $token, 'text' => 'We are a dental clinic with about 400 calls a month, want to start this week'])
            ->assertOk()->assertJsonPath('action.type', 'slots');
        $this->assertSame('Happy to help a dental clinic! Pick a time below for a quick demo.', $answer->json('messages.1.text'));
        $this->assertTrue(collect($answer->json('action.days.0.slots'))->every(fn ($s) => (int) Carbon::parse($s['starts_at'])->format('G') >= 12), 'afternoon only');

        $chat = SalesChat::sole();
        $this->assertSame(['industry' => 'dental clinic', 'monthly_calls' => 'about 400', 'timeline' => 'this week'], $chat->lead);
        $this->assertSame(1, $chat->ai_replies);
        $this->assertSame(3000, $chat->input_tokens);
        $this->assertSame(3110, (int) Cache::get('sales-chat-tokens:2026-11-09'));

        $first = $this->ai->requests[0];
        $this->assertSame(['low', 'claude-haiku-5-5', 2000], [$first->effort, $first->model, $first->maxTokens]);
        $this->assertSame(['save_lead_details', 'offer_demo_times', 'book_demo', 'show_contact_form', 'hand_over_to_team'], $this->ai->toolNames(0));
        $this->assertStringContainsString('$400/month', $first->instructions);
        $this->assertStringNotContainsString('2026', $first->instructions, 'nothing volatile in the cached part');
        $this->assertSame($first->instructions, $this->ai->requests[2]->instructions);
        $this->assertStringContainsString('industry: dental clinic', $this->ai->requests[2]->context);
        $this->assertSame('user', $first->messages[0]['role'], 'history starts with the visitor');

        // No lead yet (no name or email), so nothing in Website enquiries.
        $this->assertSame(0, ContactSubmission::count());
    }

    public function test_the_ai_books_a_named_time_and_asks_for_details_when_missing(): void
    {
        $token = $this->start();
        $slot = $this->chat('POST', 'messages', ['token' => $token, 'quick' => 'demo'])->json('action.days.0.slots.1.starts_at');
        $this->ai->tool('book_demo', ['starts_at' => $slot])->reply('Just send your name and email and you\'re booked.');

        $this->chat('POST', 'messages', ['token' => $token, 'text' => 'The second time works'])->assertOk()->assertJsonPath('action.type', 'contact');
        $this->assertSame($slot, SalesChat::sole()->pending_slot);

        $this->chat('POST', 'lead', ['token' => $token, 'name' => 'Dana', 'email' => 'dana@x.test'])->assertOk()->assertJsonPath('booking.starts_at', Carbon::parse($slot)->utc()->toIso8601String());
    }

    public function test_without_a_key_or_past_the_budget_the_chat_still_helps(): void
    {
        $token = $this->start();
        $this->ai->configured = false;
        $this->chat('POST', 'start', ['token' => $token])->assertJsonPath('ai', false);
        $this->assertStringContainsString('$300/month', $this->chat('POST', 'messages', ['token' => $token, 'text' => 'How much does it cost?'])->json('messages.1.text'));
        $this->chat('POST', 'messages', ['token' => $token, 'text' => 'Can I book a demo?'])->assertJsonPath('action.type', 'slots');
        $this->chat('POST', 'messages', ['token' => $token, 'text' => 'Do you integrate with Salesforce?'])->assertJsonPath('action.type', 'contact');

        $this->ai->configured = true;
        Cache::put('sales-chat-tokens:2026-11-09', 5_000_000);
        $this->chat('POST', 'messages', ['token' => $token, 'text' => 'What about pricing?'])->assertOk();
        $this->assertSame([], $this->ai->requests, 'over budget: no AI call');
    }

    public function test_a_person_takes_over_and_the_visitor_sees_the_reply(): void
    {
        $token = $this->start();
        $this->chat('POST', 'lead', ['token' => $token, 'name' => 'Dana Ortiz', 'email' => 'dana@ortizdental.test']);
        $this->chat('POST', 'messages', ['token' => $token, 'quick' => 'person'])->assertOk();
        $chat = SalesChat::sole();
        $this->assertTrue($chat->needs_human);
        $this->assertSame('sales', $chat->enquiry->inquiry_type);

        // While the team has it, the AI stays quiet.
        $this->chat('POST', 'messages', ['token' => $token, 'text' => 'Hello?'])->assertOk()->assertJsonCount(1, 'messages');
        $this->assertSame([], $this->ai->requests);

        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true, 'must_change_password' => false, 'name' => 'Riley Sales']);
        $this->actingAs($admin);
        $this->travel(5)->minutes();
        Livewire::test(Enquiries::class)->call('select', $chat->contact_submission_id)
            ->assertSee('Hello?')->assertSee('Waiting for the team')
            ->set('reply', 'Hi Dana, Riley here. Happy to help!')->call('sendReply')->assertHasNoErrors();
        Mail::assertQueued(SalesChatReplyMail::class, fn ($mail) => $mail->hasTo('dana@ortizdental.test'));

        $poll = $this->chat('GET', 'messages', ['token' => $token])->assertOk()->assertJsonPath('staff_joined', true);
        $this->assertSame('team', collect($poll->json('messages'))->last()['from']);
        $this->chat('POST', 'start', ['token' => $token])->assertJsonPath('quick_replies', []);

        Livewire::test(Enquiries::class)->call('select', $chat->contact_submission_id)->call('handBack');
        $this->assertNull($chat->fresh()->staff_user_id);
        $this->assertFalse($chat->fresh()->needs_human);
    }

    public function test_bots_and_old_chats(): void
    {
        $token = $this->start();
        $this->chat('POST', 'lead', ['token' => $token, 'name' => 'Bot', 'email' => 'bot@spam.test', 'website' => 'http://spam'])->assertOk();
        $this->assertSame(0, ContactSubmission::count());
        $this->chat('POST', 'messages', ['token' => $token, 'text' => str_repeat('a', 601)])->assertStatus(422);

        $lead = $this->start();
        $this->chat('POST', 'lead', ['token' => $lead, 'name' => 'Dana', 'email' => 'dana@x.test']);
        $this->travel(91)->days();
        $this->artisan('privacy:run')->assertSuccessful();
        $this->assertSame(1, SalesChat::count(), 'only the chat that became a lead is kept');
        $this->assertNotNull(SalesChat::sole()->contact_submission_id);
    }
}
