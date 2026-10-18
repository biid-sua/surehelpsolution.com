<?php

namespace Tests\Feature;

use App\Actions\Organizations\ProvisionUserTenancy;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\User;
use App\Services\Inbox\TextedPictures;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Mobile API (D72): inbox actions (API-7), adding and editing customers (API-8) and the files that
 * open links in the app (API-16).
 */
class MobileApiInboxCustomersTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Organization $business;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create(['role' => 'client', 'is_active' => true, 'must_change_password' => false, 'name' => 'Elena Rivera']);
        $this->business = app(ProvisionUserTenancy::class)->handle($this->owner);
    }

    private function member(string $role): User
    {
        $user = User::factory()->create(['role' => 'client', 'is_active' => true, 'must_change_password' => false]);
        $this->business->members()->attach($user->id, ['role' => $role, 'status' => 'active']);

        return $user;
    }

    private function conversation(array $attributes = []): Conversation
    {
        return Conversation::create(array_merge(['organization_id' => $this->business->id, 'channel' => 'web_chat', 'channel_key' => 'web',
            'external_thread_id' => 'v'.random_int(1, 99999), 'contact_name' => 'Maria', 'last_inbound_at' => now(), 'needs_human' => true, 'unread_count' => 2], $attributes));
    }

    public function test_assign_close_reopen_ai_and_read(): void
    {
        $conversation = $this->conversation();
        $staff = $this->member('staff');
        $outsider = User::factory()->create(['role' => 'client', 'is_active' => true]);

        Sanctum::actingAs($this->owner);
        $names = array_column($this->getJson('/api/v1/client/inbox/assignees')->assertOk()->json('data.assignees'), 'name');
        $this->assertContains('Elena Rivera', $names);

        $base = "/api/v1/client/inbox/conversations/{$conversation->ulid}";
        $this->postJson("{$base}/assign", ['user_id' => $staff->id])->assertOk()->assertJsonPath('data.assigned_to.id', $staff->id);
        $this->postJson("{$base}/assign", ['user_id' => $outsider->id])->assertStatus(422);
        $this->getJson($base)->assertJsonPath('data.conversation.assigned_to.id', $staff->id);
        $this->postJson("{$base}/assign", ['user_id' => null])->assertOk()->assertJsonPath('data.assigned_to', null);

        $this->postJson("{$base}/ai", ['on' => false])->assertOk()->assertJsonPath('data.ai_paused', true);
        $this->postJson("{$base}/ai", ['on' => true])->assertOk()->assertJsonPath('data.needs_human', false);

        $this->postJson("{$base}/read")->assertOk();
        $this->assertSame(0, $conversation->fresh()->unread_count);

        $this->postJson("{$base}/close")->assertOk()->assertJsonPath('data.status', 'closed');
        $this->postJson("{$base}/reopen")->assertOk()->assertJsonPath('data.status', 'open');
        $this->assertDatabaseHas('audit_logs', ['action' => 'inbox.closed', 'organization_id' => $this->business->id]);

        // Another business's conversation doesn't exist.
        $stranger = User::factory()->create(['role' => 'client', 'is_active' => true, 'must_change_password' => false]);
        app(ProvisionUserTenancy::class)->handle($stranger);
        Sanctum::actingAs($stranger->fresh());
        $this->postJson("{$base}/close")->assertNotFound();
    }

    public function test_ai_suggestions_are_sent_or_discarded_and_answers_rated(): void
    {
        $conversation = $this->conversation();
        $draft = $conversation->messages()->create(['organization_id' => $this->business->id, 'direction' => 'out', 'author_type' => 'ai', 'body' => 'We can come Tuesday.', 'status' => 'draft']);
        $other = $conversation->messages()->create(['organization_id' => $this->business->id, 'direction' => 'out', 'author_type' => 'ai', 'body' => 'Maybe Wednesday?', 'status' => 'draft']);
        $sentByAi = $conversation->messages()->create(['organization_id' => $this->business->id, 'direction' => 'out', 'author_type' => 'ai', 'body' => 'We open at 8.', 'status' => 'sent']);

        Sanctum::actingAs($this->owner);
        $base = "/api/v1/client/inbox/conversations/{$conversation->ulid}";
        $this->postJson("{$base}/drafts/{$draft->ulid}/send")->assertOk();
        $this->assertSame('used', $draft->fresh()->status);
        $this->assertDatabaseHas('messages', ['conversation_id' => $conversation->id, 'author_type' => 'user', 'body' => 'We can come Tuesday.']);
        $this->postJson("{$base}/drafts/{$draft->ulid}/send")->assertNotFound();   // only once

        $this->postJson("{$base}/drafts/{$other->ulid}/discard")->assertOk();
        $this->assertSame('discarded', $other->fresh()->status);

        $this->postJson("{$base}/messages/{$sentByAi->ulid}/rate", ['rating' => 'unhelpful', 'correction' => 'We open at 7 on weekdays.'])->assertOk();
        $this->assertDatabaseHas('ai_feedback', ['message_id' => $sentByAi->id, 'rating' => 'unhelpful']);
    }

    public function test_texted_pictures_come_as_signed_links(): void
    {
        Storage::fake('local');
        $file = strtolower((string) Str::ulid()).'.jpg';
        Storage::disk('local')->put(TextedPictures::path($this->business->ulid, $file), 'jpeg-bytes');
        $conversation = $this->conversation(['channel' => 'sms', 'channel_key' => '+15125550100', 'external_thread_id' => '+15125550147']);
        $conversation->messages()->create(['organization_id' => $this->business->id, 'direction' => 'in', 'author_type' => 'customer', 'body' => 'Look', 'status' => 'received',
            'attachments' => [['type' => 'image', 'url' => route('inbox.media', [$this->business->ulid, $file]), 'name' => 'Picture.jpg']]]);

        Sanctum::actingAs($this->owner);
        $url = $this->getJson("/api/v1/client/inbox/conversations/{$conversation->ulid}")->assertOk()->json('data.messages.0.attachments.0.url');
        $this->assertStringContainsString('/api/v1/inbox-media/', $url);
        $this->assertStringContainsString('signature=', $url);

        $this->app['auth']->forgetGuards();
        $this->get($url)->assertOk();
        $this->get(str_replace('signature=', 'signature=x', $url))->assertForbidden();
    }

    public function test_customers_are_added_without_duplicates_and_edited(): void
    {
        Sanctum::actingAs($this->owner);
        $this->postJson('/api/v1/client/customers', ['company' => ''])->assertStatus(422)->assertJsonValidationErrors(['first_name', 'phone']);
        $this->postJson('/api/v1/client/customers', ['first_name' => 'Ana', 'phone' => '12'])->assertStatus(422)->assertJsonValidationErrors('phone');

        $id = $this->postJson('/api/v1/client/customers', ['first_name' => 'Ana', 'last_name' => 'Lopez', 'phone' => '(512) 555-0147'])
            ->assertCreated()->json('data.customer.id');
        $this->postJson('/api/v1/client/customers', ['first_name' => 'Anna', 'phone' => '512-555-0147'])
            ->assertStatus(409)->assertJsonPath('existing.id', $id)->assertJsonPath('existing.name', 'Ana Lopez');
        $this->getJson('/api/v1/client/customers/lookup?phone=5125550147')->assertJsonPath('data.match.id', $id)->assertJsonPath('data.match.matched_by', 'phone');

        // A partial edit keeps everything else.
        $this->patchJson("/api/v1/client/customers/{$id}", ['email' => 'ana@lopez.test', 'city' => 'Austin'])->assertOk();
        $customer = Customer::query()->where('ulid', $id)->firstOrFail();
        $this->assertSame(['Ana', 'ana@lopez.test', 'Austin', '+15125550147'], [$customer->first_name, $customer->email, $customer->city, $customer->phone_e164]);
        $other = Customer::create(['organization_id' => $this->business->id, 'first_name' => 'Bo', 'phone' => '5125550199']);
        $this->patchJson("/api/v1/client/customers/{$other->ulid}", ['phone' => '512 555 0147'])->assertStatus(422);

        $this->postJson("/api/v1/client/customers/{$id}/notes", ['note' => 'Prefers mornings'])->assertCreated();
        $this->assertSame('Prefers mornings', $this->getJson("/api/v1/client/customers/{$id}")->json('data.timeline.0.body'));
        $tagId = $this->postJson("/api/v1/client/customers/{$id}/tags", ['name' => 'VIP'])->assertOk()->json('data.tags.0.id');
        $this->deleteJson("/api/v1/client/customers/{$id}/tags/{$tagId}")->assertOk()->assertJsonPath('data.tags', []);

        // Staff can add and edit; nobody outside the business can.
        Sanctum::actingAs($this->member('staff'));
        $this->patchJson("/api/v1/client/customers/{$id}", ['notes' => 'Gate code at the door'])->assertOk();
        $stranger = User::factory()->create(['role' => 'client', 'is_active' => true, 'must_change_password' => false]);
        app(ProvisionUserTenancy::class)->handle($stranger);
        Sanctum::actingAs($stranger->fresh());
        $this->patchJson("/api/v1/client/customers/{$id}", ['notes' => 'x'])->assertNotFound();
    }

    public function test_app_link_files_for_android_and_ios(): void
    {
        $this->get('/.well-known/assetlinks.json')->assertOk()->assertExactJson([]);

        config(['mobile.app_links.android_sha256' => ['AB:CD'], 'mobile.app_links.ios_app_ids' => ['TEAM123.surehelpsolution.com.shsMobileApp']]);
        $this->get('/.well-known/assetlinks.json')->assertOk()
            ->assertJsonPath('0.target.package_name', 'surehelpsolution.com.shs_mobile_app')
            ->assertJsonPath('0.target.sha256_cert_fingerprints', ['AB:CD']);
        $this->get('/.well-known/apple-app-site-association')->assertOk()->assertHeader('Content-Type', 'application/json')
            ->assertJsonPath('applinks.details.0.appIDs', ['TEAM123.surehelpsolution.com.shsMobileApp'])
            ->assertJsonPath('applinks.details.0.components.0', ['/' => '/app/*']);
    }
}
