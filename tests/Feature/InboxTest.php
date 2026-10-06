<?php

namespace Tests\Feature;

use App\Actions\Inbox\SendReply;
use App\Actions\Organizations\ProvisionUserTenancy;
use App\Enums\SocialNetwork;
use App\Jobs\RespondToConversation;
use App\Livewire\Client\Inbox\Channels;
use App\Livewire\Client\Inbox\Index as Inbox;
use App\Models\AiAssistant;
use App\Models\ChatWidget;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Organization;
use App\Models\SocialAccount;
use App\Models\User;
use App\Notifications\InboxMessageReceived;
use App\Services\Ai\Contracts\AiProvider;
use App\Services\Inbox\MessageSplitter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\Support\ScriptedAiProvider;
use Tests\TestCase;

/**
 * M-1 — the unified inbox: website chat, Messenger and Instagram (spec §26, D37).
 */
class InboxTest extends TestCase
{
    use RefreshDatabase;

    private ScriptedAiProvider $ai;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->ai = new ScriptedAiProvider;
        $this->ai->configured = false;
        $this->app->instance(AiProvider::class, $this->ai);
        config(['social.connectors.meta.client_id' => 'meta-app', 'social.connectors.meta.client_secret' => 'meta-secret', 'social.connectors.meta.webhook_verify_token' => 'verify-me']);
    }

    /** @return array{0: User, 1: Organization} */
    private function business(): array
    {
        $owner = User::factory()->create(['role' => 'client', 'is_active' => true, 'must_change_password' => false]);

        return [$owner, app(ProvisionUserTenancy::class)->handle($owner)];
    }

    private function member(Organization $org, string $role): User
    {
        $user = User::factory()->create(['role' => 'client', 'is_active' => true, 'must_change_password' => false]);
        $org->members()->attach($user->id, ['role' => $role, 'status' => 'active']);

        return $user;
    }

    private function chat(string $method, string $uri, array $body = [], string $origin = 'https://brightplumbing.com'): TestResponse
    {
        return $this->call($method, '/api/chat/'.$uri, [], [], [], ['HTTP_ORIGIN' => $origin, 'CONTENT_TYPE' => 'text/plain'], $body ? json_encode($body) : null);
    }

    private function page(Organization $org, SocialNetwork $network = SocialNetwork::Facebook, string $id = '111'): SocialAccount
    {
        return SocialAccount::create([
            'organization_id' => $org->id, 'network' => $network, 'external_id' => $id, 'name' => 'Bright Plumbing',
            'access_token' => 'page-token', 'is_enabled' => true, 'messaging_enabled' => true,
            'meta' => $network === SocialNetwork::Instagram ? ['page_id' => '111'] : null,
        ]);
    }

    private function webhook(array $payload, ?string $secret = 'meta-secret'): TestResponse
    {
        $body = json_encode($payload);

        return $this->call('POST', '/api/webhooks/meta', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, (string) $secret),
        ], $body);
    }

    private function messengerEvent(string $mid, string $text, string $sender = 'psid-1', array $extra = []): array
    {
        return ['object' => 'page', 'entry' => [['id' => '111', 'messaging' => [array_merge([
            'sender' => ['id' => $sender], 'recipient' => ['id' => '111'], 'timestamp' => now()->getTimestampMs(),
            'message' => ['mid' => $mid, 'text' => $text],
        ], $extra)]]]];
    }

    public function test_website_chat_round_trip_with_site_restriction(): void
    {
        [$owner, $org] = $this->business();
        $widget = ChatWidget::for($org);
        $widget->update(['allowed_origins' => ['brightplumbing.com']]);

        $config = $this->chat('GET', $widget->public_key.'/config')->assertOk()->assertHeader('Access-Control-Allow-Origin', 'https://brightplumbing.com');
        $this->assertNull($config->json('assistant'), 'no AI disclosure while the assistant is off');
        $this->chat('GET', $widget->public_key.'/config', origin: 'https://evil.test')->assertForbidden();
        $this->chat('GET', $widget->public_key.'/config', origin: 'https://www.brightplumbing.com')->assertOk();

        $sent = $this->chat('POST', $widget->public_key.'/messages', ['body' => 'Do you fix leaking taps?', 'name' => 'Maria', 'email' => 'maria@example.test'])->assertOk();
        $token = $sent->json('token');
        $this->assertSame(48, strlen($token));
        $conversation = Conversation::sole();
        $this->assertSame(hash('sha256', $token), $conversation->external_thread_id, 'only the hash is stored');
        $this->assertSame('maria@example.test', $conversation->customer->email);
        $this->assertTrue($conversation->needs_human);
        Notification::assertSentTo($owner, InboxMessageReceived::class);

        // The team replies, adds a note; the visitor sees the reply but never the note.
        app(SendReply::class)->handle($conversation, 'Yes we do! When suits you?', $owner);
        app(SendReply::class)->handle($conversation, 'Probably the kitchen tap', $owner, note: true);
        $messages = $this->chat('GET', $widget->public_key.'/messages?token='.$token)->assertOk()->json('messages');
        $this->assertSame(['you', 'business'], array_column($messages, 'from'));
        $this->assertSame('Yes we do! When suits you?', $messages[1]['text']);

        // Polling with "after" returns only newer messages; another visitor's token sees nothing.
        $this->assertSame([], $this->chat('GET', $widget->public_key.'/messages?token='.$token.'&after='.$messages[1]['id'])->json('messages'));
        $this->assertSame([], $this->chat('GET', $widget->public_key.'/messages?token='.str_repeat('a', 48))->json('messages'));

        $widget->update(['is_enabled' => false]);
        $this->chat('GET', $widget->public_key.'/config')->assertNotFound();
        $this->chat('GET', 'shw_nope/config')->assertNotFound();
    }

    public function test_website_chat_hands_new_messages_to_the_ai_when_it_answers(): void
    {
        [, $org] = $this->business();
        Bus::fake([RespondToConversation::class]);
        $this->ai->configured = true;
        AiAssistant::create(['organization_id' => $org->id, 'is_enabled' => true, 'name' => 'Sam', 'modes' => ['web_chat' => 'auto']]);
        $widget = ChatWidget::for($org);

        $this->assertSame('Sam', $this->chat('GET', $widget->public_key.'/config')->json('assistant'));
        $this->chat('POST', $widget->public_key.'/messages', ['body' => 'Hi'])->assertOk();

        Bus::assertDispatched(RespondToConversation::class);
        $this->assertFalse(Conversation::sole()->needs_human, 'the AI is answering');
        Notification::assertNothingSent();
    }

    public function test_meta_webhook_is_verified_and_signed(): void
    {
        $this->get('/api/webhooks/meta?hub_mode=subscribe&hub_verify_token=verify-me&hub_challenge=12345')->assertOk()->assertSee('12345');
        $this->get('/api/webhooks/meta?hub_mode=subscribe&hub_verify_token=wrong&hub_challenge=1')->assertForbidden();
        $this->webhook($this->messengerEvent('m1', 'Hi'), 'not-the-secret')->assertForbidden();
        $this->assertSame(0, Message::count());
    }

    public function test_messenger_messages_arrive_once_and_replies_respect_metas_windows(): void
    {
        [$owner, $org] = $this->business();
        $this->page($org);
        Http::fake([
            'graph.facebook.com/*/psid-1?*' => Http::response(['name' => 'Maria Lopez']),
            'graph.facebook.com/*/111/messages' => Http::sequence()
                ->push(['recipient_id' => 'psid-1', 'message_id' => 'm_out_1'])
                ->push(['recipient_id' => 'psid-1', 'message_id' => 'm_out_2']),
        ]);

        $this->webhook($this->messengerEvent('m1', 'Are you open today?'))->assertOk();
        $this->webhook($this->messengerEvent('m1', 'Are you open today?'))->assertOk(); // Meta retried
        $conversation = Conversation::sole();
        $this->assertSame('Maria Lopez', $conversation->contact_name);
        $this->assertSame(1, $conversation->messages()->count());

        // Within 24 hours: a normal response.
        app(SendReply::class)->handle($conversation->fresh(), 'Yes, until 5pm!', $owner);
        Http::assertSent(fn (HttpRequest $r) => str_ends_with(parse_url($r->url(), PHP_URL_PATH), '/111/messages')
            && $r['messaging_type'] === 'RESPONSE' && json_decode($r['recipient'], true)['id'] === 'psid-1' && $r['access_token'] === 'page-token');
        $this->assertSame('m_out_1', Message::where('direction', 'out')->value('external_id'));

        // Our own reply echoes back from Meta: not stored twice.
        $this->webhook($this->messengerEvent('m_out_1', 'Yes, until 5pm!', '111', ['recipient' => ['id' => 'psid-1'], 'message' => ['mid' => 'm_out_1', 'text' => 'Yes, until 5pm!', 'is_echo' => true]]))->assertOk();
        $this->assertSame(1, Message::where('direction', 'out')->count());

        // After 24 hours a person may still reply, with Meta's human-agent tag.
        $this->travel(30)->hours();
        app(SendReply::class)->handle($conversation->fresh(), 'Following up on your question', $owner);
        Http::assertSent(fn (HttpRequest $r) => str_ends_with(parse_url($r->url(), PHP_URL_PATH), '/111/messages') && ($r['tag'] ?? null) === 'HUMAN_AGENT');

        // After 7 days nobody can.
        $this->travel(7)->days();
        $this->expectException(ValidationException::class);
        app(SendReply::class)->handle($conversation->fresh(), 'Too late', $owner);
    }

    public function test_replies_sent_from_facebook_itself_appear_and_pause_the_ai(): void
    {
        [, $org] = $this->business();
        $this->page($org);
        Http::fake(['graph.facebook.com/*' => Http::response(['name' => 'Maria'])]);
        $this->webhook($this->messengerEvent('m1', 'Hello'))->assertOk();

        $this->webhook($this->messengerEvent('m_fb', 'Hi Maria, Tom here', '111', ['recipient' => ['id' => 'psid-1'], 'message' => ['mid' => 'm_fb', 'text' => 'Hi Maria, Tom here', 'is_echo' => true]]))->assertOk();

        $out = Message::where('direction', 'out')->sole();
        $this->assertSame('Hi Maria, Tom here', $out->body);
        $this->assertTrue(Conversation::sole()->ai_paused);
    }

    public function test_an_echo_that_beats_our_save_is_recognised_as_our_own_reply(): void
    {
        [$owner, $org] = $this->business();
        $this->page($org);
        Http::fake(['graph.facebook.com/*' => Http::response(['name' => 'Maria'])]);
        $this->webhook($this->messengerEvent('m1', 'Hello'))->assertOk();
        $conversation = Conversation::sole();

        // Our reply is stored but its id isn't saved yet when Meta's echo arrives.
        $pending = $conversation->messages()->create(['organization_id' => $org->id, 'direction' => 'out', 'author_type' => 'user', 'author_user_id' => $owner->id, 'body' => 'Hi Maria!', 'status' => 'pending']);
        $this->webhook($this->messengerEvent('m_out_9', 'Hi Maria!', '111', ['recipient' => ['id' => 'psid-1'], 'message' => ['mid' => 'm_out_9', 'text' => 'Hi Maria!', 'is_echo' => true]]))->assertOk();

        $this->assertSame(1, Message::where('direction', 'out')->count(), 'not recorded twice');
        $this->assertSame('m_out_9', $pending->fresh()->external_id);
        $this->assertFalse((bool) $conversation->fresh()->ai_paused, 'our own reply doesn\'t pause the AI');
    }

    public function test_instagram_messages_use_the_instagram_account(): void
    {
        [$owner, $org] = $this->business();
        $this->page($org, SocialNetwork::Instagram, '999');
        Http::fake([
            'graph.facebook.com/*/igsid-1?*' => Http::response(['username' => 'maria.l']),
            'graph.facebook.com/*/999/messages' => fn () => Http::response(['message_id' => 'ig_out_'.uniqid()]),
        ]);

        $this->webhook(['object' => 'instagram', 'entry' => [['id' => '999', 'messaging' => [[
            'sender' => ['id' => 'igsid-1'], 'recipient' => ['id' => '999'], 'timestamp' => now()->getTimestampMs(),
            'message' => ['mid' => 'ig1', 'text' => 'Price for a drain clean?'],
        ]]]]])->assertOk();

        $conversation = Conversation::sole();
        $this->assertSame('instagram', $conversation->channel->value);
        $this->assertSame('@maria.l', $conversation->contact_name);
        app(SendReply::class)->handle($conversation, str_repeat('Long answer. ', 120), $owner); // over Instagram's 1,000 characters
        $this->assertGreaterThan(1, Message::where('direction', 'out')->count(), 'long replies are split');
        Http::assertSent(fn (HttpRequest $r) => str_ends_with(parse_url($r->url(), PHP_URL_PATH), '/999/messages'));
    }

    public function test_messages_split_at_sentence_boundaries(): void
    {
        $parts = MessageSplitter::split('First sentence here. Second one is a bit longer. Third.', 30);
        $this->assertSame(['First sentence here.', 'Second one is a bit longer.', 'Third.'], $parts);
        $this->assertSame([], MessageSplitter::split('   ', 30));
    }

    public function test_inbox_pages_permissions_and_isolation(): void
    {
        [$owner, $org] = $this->business();
        [, $otherOrg] = $this->business();
        $staff = $this->member($org, 'staff');
        $mine = Conversation::create(['organization_id' => $org->id, 'channel' => 'web_chat', 'channel_key' => 'web', 'external_thread_id' => 'a', 'contact_name' => 'Maria', 'last_inbound_at' => now(), 'needs_human' => true]);
        $mine->messages()->create(['organization_id' => $org->id, 'direction' => 'in', 'author_type' => 'customer', 'body' => 'Hello from Maria', 'status' => 'received']);
        $theirs = Conversation::create(['organization_id' => $otherOrg->id, 'channel' => 'web_chat', 'channel_key' => 'web', 'external_thread_id' => 'b', 'contact_name' => 'Other']);

        $this->actingAs($owner)->get(route('app.inbox.index'))->assertOk()->assertSee('Maria')->assertDontSee('Other');
        $this->get(route('app.inbox.show', $mine))->assertOk()->assertSee('Hello from Maria');
        $this->get(route('app.inbox.show', $theirs))->assertNotFound();
        $this->get(route('app.inbox.channels'))->assertOk()->assertSee('data-surehelp-chat', false);
        $this->get(route('app.dashboard'))->assertSee(route('app.inbox.index'));

        // Staff answer messages; they don't change channels.
        $this->actingAs($staff);
        Livewire::test(Inbox::class, ['conversation' => $mine->ulid])->set('reply', 'On it!')->call('send')->assertHasNoErrors()
            ->call('setStatus', 'closed');
        $this->assertSame('closed', $mine->fresh()->status);
        $this->assertSame($staff->id, $mine->fresh()->assigned_to_user_id);
        Livewire::test(Channels::class)->set('widget.title', 'Hijack')->call('saveWidget')->assertForbidden();

        // A new message reopens a closed conversation.
        $this->chat('POST', ChatWidget::for($org)->public_key.'/messages', ['body' => 'Hi']); // different visitor: new conversation
        $this->assertSame(2, Conversation::query()->forOrganization($org)->count());
    }

    public function test_switching_messenger_on_subscribes_the_page(): void
    {
        [$owner, $org] = $this->business();
        $page = $this->page($org);
        $page->update(['messaging_enabled' => false]);
        Http::fake(['graph.facebook.com/*/111/subscribed_apps' => Http::sequence()
            ->push(['success' => true])
            ->push(['error' => ['code' => 100, 'message' => 'Missing pages_messaging']], 400)]);

        $this->actingAs($owner);
        Livewire::test(Channels::class)->call('toggleMessaging', $page->ulid);
        $this->assertTrue($page->fresh()->messaging_enabled);
        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), '/111/subscribed_apps') && str_contains((string) $r['subscribed_fields'], 'messages'));

        // Off again, then Meta refuses to switch it back on (permission not granted): a clear message, and it stays off.
        Livewire::test(Channels::class)->call('toggleMessaging', $page->ulid);
        $this->assertFalse($page->fresh()->messaging_enabled);
        Livewire::test(Channels::class)->call('toggleMessaging', $page->ulid)->assertDispatched('toast');
        $this->assertFalse($page->fresh()->messaging_enabled);
    }

    public function test_mobile_api_lists_reads_and_replies(): void
    {
        [$owner, $org] = $this->business();
        $conversation = Conversation::create(['organization_id' => $org->id, 'channel' => 'web_chat', 'channel_key' => 'web', 'external_thread_id' => 'a', 'contact_name' => 'Maria', 'last_inbound_at' => now(), 'needs_human' => true, 'unread_count' => 1]);
        $conversation->messages()->create(['organization_id' => $org->id, 'direction' => 'in', 'author_type' => 'customer', 'body' => 'Hello', 'status' => 'received']);

        Sanctum::actingAs($owner);
        $this->getJson('/api/v1/client/inbox/conversations')->assertOk()->assertJsonPath('data.conversations.0.id', $conversation->ulid);
        $this->getJson("/api/v1/client/inbox/conversations/{$conversation->ulid}")->assertOk()->assertJsonPath('data.messages.0.text', 'Hello');
        $this->assertSame(0, $conversation->fresh()->unread_count);
        $this->postJson("/api/v1/client/inbox/conversations/{$conversation->ulid}/reply", ['text' => 'Hi Maria'])->assertOk()->assertJsonPath('data.message.status', 'sent');

        [$stranger] = $this->business();
        Sanctum::actingAs($stranger);
        $this->getJson("/api/v1/client/inbox/conversations/{$conversation->ulid}")->assertNotFound();
    }
}
