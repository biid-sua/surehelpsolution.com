<?php

namespace Tests\Feature;

use App\Actions\Assignments\AssignAgent;
use App\Actions\Organizations\ProvisionUserTenancy;
use App\Enums\SocialNetwork;
use App\Models\BusinessService;
use App\Models\CallLog;
use App\Models\Customer;
use App\Models\KnowledgeItem;
use App\Models\Organization;
use App\Models\SocialAccount;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Follow-ups the mobile app asked for (D76): tasks linked to their call, tag ids, the business on an
 * agent's client list and call logs, one call log by id, a company's briefing, and searching users.
 */
class MobileApiFollowUpsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Organization $business;

    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->owner = User::factory()->create(['role' => 'client', 'is_active' => true, 'must_change_password' => false, 'name' => 'Elena Rivera']);
        $this->business = app(ProvisionUserTenancy::class)->handle($this->owner);
        $this->business->update(['name' => 'Rivera Plumbing']);
        $this->agent = User::factory()->create(['role' => 'agent', 'is_active' => true, 'must_change_password' => false]);
        $this->agent->syncRoles(['agent']);
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true, 'must_change_password' => false]);
        $admin->syncRoles(['super_admin']);
        app(AssignAgent::class)->handle($this->business->fresh(), $this->agent, $admin);
    }

    private function logCall(array $attributes = []): CallLog
    {
        return CallLog::withoutGlobalScopes()->create(array_merge([
            'call_id' => CallLog::generateCallId(), 'organization_id' => $this->business->id, 'client_id' => $this->owner->id,
            'call_date' => now()->toDateString(), 'call_time' => '10:00', 'reason_for_call' => 'general-inquiry', 'call_outcome' => 'resolved-by-agent',
            'agent_name' => 'Sam', 'status' => 'new', 'user_id' => $this->agent->id, 'caller_name' => 'Maria',
        ], $attributes));
    }

    public function test_a_task_can_follow_up_a_call(): void
    {
        $customer = Customer::create(['organization_id' => $this->business->id, 'first_name' => 'Maria', 'phone' => '5125550147']);
        $call = $this->logCall(['customer_id' => $customer->id]);

        Sanctum::actingAs($this->owner);
        $task = $this->postJson('/api/v1/client/tasks', ['title' => 'Call Maria back', 'call_id' => $call->call_id])->assertCreated()->json('data.task');
        $this->assertSame($call->call_id, $task['call_id']);
        $this->assertSame($customer->ulid, $task['customer']['id'], 'the call’s customer comes along');

        // Another business's call isn't one of yours.
        $other = User::factory()->create(['role' => 'client', 'is_active' => true, 'must_change_password' => false]);
        $otherBusiness = app(ProvisionUserTenancy::class)->handle($other);
        $foreign = $this->logCall(['organization_id' => $otherBusiness->id]);
        $this->postJson('/api/v1/client/tasks', ['title' => 'x', 'call_id' => $foreign->call_id])->assertStatus(422)->assertJsonValidationErrors('call_id');
    }

    public function test_customers_list_their_tags_with_ids(): void
    {
        $customer = Customer::create(['organization_id' => $this->business->id, 'first_name' => 'Maria', 'phone' => '5125550147']);
        $tag = Tag::create(['organization_id' => $this->business->id, 'name' => 'VIP']);
        $customer->tags()->attach($tag);

        Sanctum::actingAs($this->owner);
        $data = $this->getJson("/api/v1/client/customers/{$customer->ulid}")->assertOk()->json('data.customer');
        $this->assertSame(['VIP'], $data['tags'], 'the old shape stays');
        $this->assertSame([['id' => $tag->id, 'name' => 'VIP']], $data['tag_list']);
    }

    public function test_agents_see_the_business_on_clients_and_call_logs_and_open_one_log(): void
    {
        $mine = $this->logCall();
        $someoneElses = $this->logCall(['user_id' => User::factory()->create(['role' => 'agent', 'is_active' => true])->id]);

        Sanctum::actingAs($this->agent);
        $client = collect($this->getJson('/api/v1/agent/clients')->assertOk()->json('data.clients'))->firstWhere('id', $this->owner->id);
        $this->assertSame(['id' => $this->business->ulid, 'name' => 'Rivera Plumbing'], $client['business']);

        $listed = $this->getJson('/api/v1/agent/call-logs')->assertOk()->json('data.call_logs.0');
        $this->assertSame('Rivera Plumbing', $listed['business']['name']);
        $this->assertSame($this->owner->id, (int) $listed['client_id']);

        $this->getJson("/api/v1/agent/call-logs/{$mine->id}")->assertOk()
            ->assertJsonPath('data.call_log.call_id', $mine->call_id)
            ->assertJsonPath('data.call_log.business.id', $this->business->ulid);
        $this->getJson("/api/v1/agent/call-logs/{$someoneElses->id}")->assertNotFound();
        $this->getJson('/api/v1/agent/call-logs/999999')->assertNotFound();
    }

    public function test_the_company_briefing_for_agents(): void
    {
        BusinessService::create(['organization_id' => $this->business->id, 'name' => 'Water heater install', 'is_active' => true, 'duration_minutes' => 120]);
        KnowledgeItem::create(['organization_id' => $this->business->id, 'type' => 'faq', 'title' => 'Parking', 'content' => 'Park in the back.', 'is_active' => true]);

        Sanctum::actingAs($this->agent);
        $company = $this->getJson("/api/v1/agent/companies/{$this->business->ulid}")->assertOk()->json('data.company');
        $this->assertSame('Rivera Plumbing', $company['name']);
        $this->assertSame('Water heater install', $company['services'][0]['name']);
        $this->assertSame(120, $company['services'][0]['duration_minutes']);
        $this->assertArrayHasKey('hours', $company);
        $this->assertArrayHasKey('open', $company['status']);
        $this->assertIsArray($company['briefing']);
        $this->assertSame('Parking', $company['knowledge'][0]['title'] ?? null);
        $this->assertFalse($company['emergency']['available']);
    }

    public function test_admins_search_users(): void
    {
        User::factory()->create(['role' => 'agent', 'name' => 'Priya Sharma', 'email' => 'priya@surehelp.test']);
        User::factory()->create(['role' => 'agent', 'name' => 'Sam Lee', 'email' => 'sam@surehelp.test']);
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true, 'must_change_password' => false]);
        $admin->syncRoles(['super_admin']);

        Sanctum::actingAs($admin);
        $names = collect($this->getJson('/api/v1/admin/users?search=priya')->assertOk()->json('data.users'))->pluck('name')->all();
        $this->assertSame(['Priya Sharma'], $names);
        $this->assertCount(0, $this->getJson('/api/v1/admin/users?search=%25')->json('data.users'), 'wildcards are taken literally');
    }

    public function test_google_post_options_come_back_for_editing(): void
    {
        $google = SocialAccount::create(['organization_id' => $this->business->id, 'network' => SocialNetwork::GoogleBusiness, 'external_id' => 'g-1',
            'name' => 'Rivera on Google', 'access_token' => 't', 'is_enabled' => true]);

        Sanctum::actingAs($this->owner);
        $options = ['topic' => 'OFFER', 'button' => 'BOOK', 'button_url' => 'https://rivera.test/book', 'title' => '10% off tune-ups',
            'starts_on' => now()->addDay()->toDateString(), 'ends_on' => now()->addDays(8)->toDateString(), 'coupon' => 'SPRING10'];
        $id = $this->postJson('/api/v1/client/social/posts', ['intent' => 'draft', 'body' => 'Spring tune-ups', 'accounts' => [$google->ulid],
            'options' => [$google->ulid => $options]])->assertCreated()->json('data.post.id');

        $this->assertSame($options, $this->getJson("/api/v1/client/social/posts/{$id}")->assertOk()->json("data.post.options.{$google->ulid}"));
    }
}
