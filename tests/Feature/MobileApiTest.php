<?php

namespace Tests\Feature;

use App\Actions\Billing\ManageSubscription;
use App\Actions\Organizations\ProvisionUserTenancy;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\User;
use App\Notifications\AccountClosureScheduled;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * API for the modernised mobile app (D70, docs/mobile-app-plan.md): what a person may use (API-1),
 * the notification centre (API-2) and closing the account in the app (API-4).
 */
class MobileApiTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Organization $business;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create(['role' => 'client', 'is_active' => true, 'must_change_password' => false, 'password' => 'secret-pass-1']);
        $this->business = app(ProvisionUserTenancy::class)->handle($this->owner);
        $this->business->update(['name' => 'Rivera Plumbing']);
    }

    private function notify(User $user, array $data, ?string $readAt = null, string $type = 'App\\Notifications\\TaskActivity'): string
    {
        $id = (string) Str::uuid();
        $user->notifications()->create(['id' => $id, 'type' => $type, 'data' => $data, 'read_at' => $readAt]);

        return $id;
    }

    public function test_capabilities_show_permissions_features_limits_and_the_plan(): void
    {
        $plan = Plan::create(['slug' => 'pro', 'name' => 'Pro', 'price_cents' => 49900, 'trial_days' => 14,
            'features' => ['social_publishing'], 'limits' => ['team_members' => 5, 'calls' => 600]]);
        app(ManageSubscription::class)->subscribe($this->business->fresh(), $plan, $this->owner, CarbonImmutable::today());

        Sanctum::actingAs($this->owner);
        $data = $this->getJson('/api/v1/me/capabilities')->assertOk()->json('data');

        $this->assertSame('client', $data['role']);
        $this->assertContains('appointments.create', $data['permissions']);
        $this->assertNotContains('users.impersonate', $data['permissions']);
        $this->assertSame(['owner', true, 'Rivera Plumbing'], [$data['business']['role'], $data['business']['is_owner'], $data['business']['name']]);
        $this->assertSame(5, $data['business']['limits']['team_members']);
        $this->assertNull($data['business']['limits']['calendars']);
        $this->assertArrayHasKey('social_publishing', $data['business']['features']);
        $this->assertSame(['Pro', 'trialing'], [$data['business']['plan']['name'], $data['business']['plan']['status']]);
        $this->assertTrue($data['account']['can_close_account']);

        // A manager sees their own, narrower permissions; an agent has no business.
        $manager = User::factory()->create(['role' => 'client', 'is_active' => true, 'must_change_password' => false]);
        $this->business->members()->attach($manager->id, ['role' => 'manager', 'status' => 'active']);
        Sanctum::actingAs($manager);
        $data = $this->getJson('/api/v1/me/capabilities')->assertOk()->json('data');
        $this->assertSame(['manager', false, false], [$data['business']['role'], $data['business']['is_owner'], $data['account']['can_close_account']]);

        Sanctum::actingAs(User::factory()->create(['role' => 'agent', 'is_active' => true]));
        $data = $this->getJson('/api/v1/me/capabilities')->assertOk()->json('data');
        $this->assertNull($data['business']);
        $this->assertTrue($data['account']['two_factor_required']);
    }

    public function test_the_notification_centre_lists_reads_and_routes_notifications(): void
    {
        $old = $this->notify($this->owner, ['title' => 'Old', 'url' => '/app/tasks', 'event' => 'task.assigned'], now()->subDay()->toDateTimeString());
        $this->travel(1)->minutes();
        $call = $this->notify($this->owner, ['title' => 'Missed call', 'body' => 'Maria called', 'url' => '/app/calls/01JCALL?tab=notes', 'event' => 'call.missed']);
        $this->travel(1)->minutes();
        $odd = $this->notify($this->owner, ['title' => 'Elsewhere', 'url' => 'https://evil.test/phish']);
        $theirs = $this->notify(User::factory()->create(['role' => 'client']), ['title' => 'Not yours']);

        Sanctum::actingAs($this->owner);
        $response = $this->getJson('/api/v1/notifications')->assertOk();
        $this->assertSame([$odd, $call, $old], array_column($response->json('data.notifications'), 'id'));
        $this->assertSame(2, $response->json('meta.unread'));
        $item = $response->json('data.notifications.1');
        $this->assertSame(['task_activity', 'call.missed', 'Missed call', 'Maria called', url('/app/calls/01JCALL?tab=notes')],
            [$item['type'], $item['event'], $item['title'], $item['body'], $item['url']]);
        $this->assertSame(['route' => 'app.calls.show', 'params' => ['callId' => '01JCALL', 'tab' => 'notes']], $item['target']);
        $this->assertNull($response->json('data.notifications.0.url'), 'only our own pages are linked');
        $this->assertNull($response->json('data.notifications.0.target'));

        $this->assertSame([$odd, $call], array_column($this->getJson('/api/v1/notifications?unread=1')->json('data.notifications'), 'id'));
        $this->assertSame(1, $this->getJson('/api/v1/notifications?per_page=1')->json('meta.pagination.per_page'));

        $this->postJson("/api/v1/notifications/{$call}/read")->assertOk()->assertJsonPath('data.unread', 1)->assertJsonPath('data.notification.id', $call);
        $this->getJson('/api/v1/notifications/unread-count')->assertJsonPath('data.unread', 1);
        $this->postJson("/api/v1/notifications/{$theirs}/read")->assertNotFound();
        $this->deleteJson("/api/v1/notifications/{$theirs}")->assertNotFound();

        $this->postJson('/api/v1/notifications/read-all')->assertOk()->assertJsonPath('data.unread', 0);
        $this->deleteJson("/api/v1/notifications/{$old}")->assertOk();
        $this->assertSame(2, $this->owner->notifications()->count());
    }

    public function test_the_owner_closes_and_keeps_the_account_from_the_app(): void
    {
        Notification::fake();
        Sanctum::actingAs($this->owner);
        $this->getJson('/api/v1/account/closure')->assertOk()
            ->assertJsonPath('data.can_request', true)->assertJsonPath('data.scheduled', false)->assertJsonPath('data.grace_days', 30);

        $this->postJson('/api/v1/account/closure', ['business_name' => 'Wrong name', 'password' => 'nope'])
            ->assertStatus(422)->assertJsonValidationErrors(['business_name', 'password']);

        $this->postJson('/api/v1/account/closure', ['business_name' => 'rivera plumbing', 'password' => 'secret-pass-1'])
            ->assertOk()->assertJsonPath('data.scheduled', true);
        $this->assertNotNull($this->business->fresh()->closes_at);
        Notification::assertSentTo($this->owner, AccountClosureScheduled::class);
        $this->assertDatabaseHas('audit_logs', ['action' => 'organization.closure_requested', 'organization_id' => $this->business->id]);
        $this->postJson('/api/v1/account/closure', ['business_name' => 'Rivera Plumbing', 'password' => 'secret-pass-1'])->assertStatus(409);

        $this->deleteJson('/api/v1/account/closure')->assertOk()->assertJsonPath('data.scheduled', false);
        $this->assertNull($this->business->fresh()->closes_at);
    }

    public function test_only_the_owner_can_close_and_others_are_told_whom_to_ask(): void
    {
        $manager = User::factory()->create(['role' => 'client', 'is_active' => true, 'must_change_password' => false, 'password' => 'secret-pass-1']);
        $this->business->members()->attach($manager->id, ['role' => 'manager', 'status' => 'active']);
        Sanctum::actingAs($manager);
        $this->getJson('/api/v1/account/closure')->assertOk()->assertJsonPath('data.can_request', false)
            ->assertJsonPath('data.contact_email', config('company.email'));
        $this->postJson('/api/v1/account/closure', ['business_name' => 'Rivera Plumbing', 'password' => 'secret-pass-1'])->assertForbidden();

        Sanctum::actingAs(User::factory()->create(['role' => 'agent', 'is_active' => true]));
        $this->getJson('/api/v1/account/closure')->assertOk()->assertJsonPath('data.can_request', false);
        $this->assertStringContainsString('managed by SureHelp', $this->getJson('/api/v1/account/closure')->json('data.message'));
        $this->assertNull($this->business->fresh()->closes_at);
    }
}
