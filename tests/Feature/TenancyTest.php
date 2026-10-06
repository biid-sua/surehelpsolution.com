<?php

namespace Tests\Feature;

use App\Actions\Organizations\ProvisionUserTenancy;
use App\Enums\AgentAssignmentSource;
use App\Enums\CallOwnershipSource;
use App\Livewire\Admin\Users\Index as AdminUsers;
use App\Models\CallLog;
use App\Models\Organization;
use App\Models\User;
use App\Services\Tenancy\TenancyBackfill;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * P1-2 — organizations and tenant isolation (spec §4, §64; docs/decisions.md D1–D4).
 */
class TenancyTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role, array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'role' => $role,
            'is_active' => true,
            'must_change_password' => false,
        ], $attributes));
    }

    /** A client with their own organization, as created after this release. */
    private function client(array $attributes = []): array
    {
        $client = $this->user('client', $attributes);

        return [$client, app(ProvisionUserTenancy::class)->handle($client)];
    }

    private function legacyCall(array $attributes): CallLog
    {
        return CallLog::withoutGlobalScopes()->create(array_merge([
            'call_id' => CallLog::generateCallId(),
            'call_date' => now()->toDateString(),
            'call_time' => '10:00',
            'reason_for_call' => 'general-inquiry',
            'call_outcome' => 'resolved-by-agent',
            'agent_name' => 'Agent',
            'status' => 'new',
        ], $attributes));
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'call_date' => now()->toDateString(),
            'call_time' => '10:00',
            'reason_for_call' => 'general-inquiry',
            'call_outcome' => 'resolved-by-agent',
            'agent_name' => 'Agent',
            'status' => 'new',
        ], $overrides);
    }

    // Isolation -------------------------------------------------------------

    public function test_client_dashboard_shows_only_own_organization_calls(): void
    {
        $agent = $this->user('agent');
        [$alice, $aliceOrg] = $this->client(['email' => 'alice@plumbing.test']);
        [, $bobOrg] = $this->client();

        $this->legacyCall(['call_id' => 'CL-ALICE-1', 'organization_id' => $aliceOrg->id, 'user_id' => $agent->id]);
        // Bob's call where the caller happens to use Alice's email: previously leaked (audit R3).
        $this->legacyCall(['call_id' => 'CL-BOB-1', 'organization_id' => $bobOrg->id, 'caller_email' => 'alice@plumbing.test', 'user_id' => $agent->id]);

        $this->actingAs($alice)->get(route('app.calls.index'))
            ->assertOk()
            ->assertSee('CL-ALICE-1')
            ->assertDontSee('CL-BOB-1');
    }

    public function test_client_api_returns_only_own_organization_calls(): void
    {
        $agent = $this->user('agent');
        [$alice, $aliceOrg] = $this->client(['email' => 'alice@plumbing.test']);
        [, $bobOrg] = $this->client();

        $this->legacyCall(['call_id' => 'CL-ALICE-1', 'organization_id' => $aliceOrg->id, 'user_id' => $agent->id, 'service_request' => true]);
        $this->legacyCall(['call_id' => 'CL-BOB-1', 'organization_id' => $bobOrg->id, 'caller_email' => 'alice@plumbing.test', 'user_id' => $agent->id, 'service_request' => true]);

        Sanctum::actingAs($alice);

        $history = $this->getJson('/api/v1/client/call-history')->assertOk()->json('data.call_logs');
        $this->assertSame(['CL-ALICE-1'], array_column($history, 'call_id'));

        $requests = $this->getJson('/api/v1/client/service-requests')->assertOk()->json('data');
        $this->assertStringNotContainsString('CL-BOB-1', json_encode($requests));

        $this->getJson('/api/v1/client/dashboard/summary?period=daily')
            ->assertOk()
            ->assertJsonPath('data.summary.total_calls', 1);
    }

    public function test_client_without_organization_is_refused(): void
    {
        $orphan = $this->user('client');

        $this->actingAs($orphan)->get(route('app.dashboard'))->assertForbidden();

        Sanctum::actingAs($orphan);
        $this->getJson('/api/v1/client/call-history')->assertForbidden();
    }

    public function test_global_scope_limits_queries_to_current_organization(): void
    {
        $agent = $this->user('agent');
        [, $aliceOrg] = $this->client();
        [, $bobOrg] = $this->client();
        $this->legacyCall(['organization_id' => $aliceOrg->id, 'user_id' => $agent->id]);
        $this->legacyCall(['organization_id' => $bobOrg->id, 'user_id' => $agent->id]);

        $current = app(CurrentOrganization::class);

        $this->assertSame(2, CallLog::count());
        $this->assertSame(1, $current->runAs($aliceOrg, fn () => CallLog::count()));

        // New records are stamped with the context, and call IDs stay globally unique.
        $created = $current->runAs($aliceOrg, fn () => CallLog::create($this->payload([
            'call_id' => CallLog::generateCallId(),
            'user_id' => $agent->id,
        ])));
        $this->assertSame($aliceOrg->id, $created->organization_id);
        $this->assertSame(3, CallLog::withoutGlobalScopes()->distinct()->count('call_id'));
    }

    // Agent assignments -----------------------------------------------------

    public function test_unassigned_agent_cannot_log_calls_for_a_client(): void
    {
        config(['tenancy.auto_assign_agents' => false]);
        $agent = $this->user('agent');
        [$client] = $this->client();

        $this->actingAs($agent)
            ->postJson('/api/v1/agent/call-logs', $this->payload(['client_id' => (string) $client->id]))
            ->assertStatus(422)
            ->assertJsonPath('errors.client_id.0', 'The selected client was not found.');

        Sanctum::actingAs($agent);
        $this->postJson('/api/v1/agent/call-logs', $this->payload(['client_id' => (string) $client->id]))
            ->assertStatus(422);

        $this->assertSame(0, CallLog::withoutGlobalScopes()->count());
    }

    public function test_assigned_agent_logs_call_into_client_organization(): void
    {
        config(['tenancy.auto_assign_agents' => false]);
        $agent = $this->user('agent');
        [$client, $organization] = $this->client();
        $organization->assignAgent($agent);

        $this->actingAs($agent)
            ->postJson('/api/v1/agent/call-logs', $this->payload(['client_id' => (string) $client->id]))
            ->assertOk();

        $log = CallLog::withoutGlobalScopes()->sole();
        $this->assertSame($organization->id, $log->organization_id);
        $this->assertSame(CallOwnershipSource::Direct, $log->ownership_source);
    }

    public function test_agent_client_picker_lists_only_assigned_clients(): void
    {
        config(['tenancy.auto_assign_agents' => false]);
        $agent = $this->user('agent');
        [$mine, $myOrg] = $this->client(['name' => 'Assigned Plumbing']);
        $this->client(['name' => 'Someone Else Dental']);
        $myOrg->assignAgent($agent);

        Sanctum::actingAs($agent);
        $apiNames = collect($this->getJson('/api/v1/agent/clients')->assertOk()->json('data.clients'))->pluck('name');
        $this->assertEquals(['Assigned Plumbing'], $apiNames->all());
    }

    // Provisioning ----------------------------------------------------------

    public function test_admin_creating_a_client_provisions_an_organization(): void
    {
        config(['tenancy.auto_assign_agents' => true]);
        $admin = $this->user('admin');
        $agent = $this->user('agent');

        $this->actingAs($admin);
        Livewire::test(AdminUsers::class)->call('startAdding')
            ->set('draft.role', 'client')->set('draft.name', 'Rosa Rapid')->set('draft.business_name', 'Rapid Plumbing')
            ->set('draft.email', 'owner@rapid.test')->set('draft.password', 'TempPass123')
            ->call('create')->assertHasNoErrors();

        $client = User::where('email', 'owner@rapid.test')->sole();
        $organization = $client->primaryOrganization();

        $this->assertNotNull($organization);
        $this->assertSame('Rapid Plumbing', $organization->name);
        $this->assertSame($client->id, $organization->owner_user_id);
        $this->assertTrue($organization->hasAgent($agent), 'auto-assign keeps the shared agent pool working');
    }

    public function test_new_agent_is_assigned_to_active_organizations_when_auto_assign_is_on(): void
    {
        config(['tenancy.auto_assign_agents' => true]);
        [, $organization] = $this->client();

        Sanctum::actingAs($this->user('admin'));
        $this->postJson('/api/v1/admin/users', [
            'name' => 'New Agent',
            'email' => 'agent@surehelp.test',
            'role' => 'agent',
            'password' => 'TempPass123',
        ])->assertOk();

        $this->assertTrue($organization->hasAgent(User::where('email', 'agent@surehelp.test')->sole()));
    }

    public function test_api_user_endpoint_includes_organization(): void
    {
        [$client, $organization] = $this->client();
        Sanctum::actingAs($client);

        $this->getJson('/api/v1/user')
            ->assertOk()
            ->assertJsonPath('data.user.id', $client->id)
            ->assertJsonPath('data.organization.id', $organization->ulid);
    }

    // Backfill (D1–D3) ------------------------------------------------------

    public function test_backfill_moves_legacy_data_into_organizations(): void
    {
        $agent = $this->user('agent');
        $inactiveAgent = $this->user('agent', ['is_active' => false]);
        $alice = $this->user('client', ['email' => 'alice@plumbing.test', 'name' => 'Alice Plumbing']);
        $bob = $this->user('client', ['email' => 'bob@dental.test']);

        $byId = $this->legacyCall(['client_id' => (string) $alice->id, 'user_id' => $agent->id]);
        $byEmail = $this->legacyCall(['client_id' => null, 'caller_email' => 'BOB@dental.test', 'user_id' => $agent->id]);
        $orphan = $this->legacyCall(['client_id' => '999999', 'user_id' => $agent->id]);
        // Valid client_id wins over a conflicting email: never re-attributed by email.
        $conflict = $this->legacyCall(['client_id' => (string) $alice->id, 'caller_email' => 'bob@dental.test', 'user_id' => $agent->id]);

        $report = app(TenancyBackfill::class)->run();

        $this->assertSame(2, $report['organizations_created']);
        $this->assertSame(2, $report['calls_by_client_id']);
        $this->assertSame(1, $report['calls_by_email_match']);
        $this->assertSame(1, $report['calls_unassigned']);

        $aliceOrg = $alice->primaryOrganization();
        $bobOrg = $bob->primaryOrganization();
        $this->assertSame('Alice Plumbing', $aliceOrg->name);
        $this->assertSame($alice->id, $aliceOrg->owner_user_id);

        $this->assertSame($aliceOrg->id, $byId->fresh()->organization_id);
        $this->assertSame(CallOwnershipSource::ClientId, $byId->fresh()->ownership_source);
        $this->assertSame($bobOrg->id, $byEmail->fresh()->organization_id);
        $this->assertSame(CallOwnershipSource::EmailMatch, $byEmail->fresh()->ownership_source);
        $this->assertNull($orphan->fresh()->organization_id);
        $this->assertSame(CallOwnershipSource::Unassigned, $orphan->fresh()->ownership_source);
        $this->assertSame($aliceOrg->id, $conflict->fresh()->organization_id);

        // D3: active agents keep serving every client; inactive agents get nothing.
        $this->assertTrue($aliceOrg->hasAgent($agent));
        $this->assertTrue($bobOrg->hasAgent($agent));
        $this->assertFalse($aliceOrg->hasAgent($inactiveAgent));
        $this->assertSame(AgentAssignmentSource::Migration->value, $aliceOrg->agents()->first()->pivot->source);

        // Idempotent.
        $again = app(TenancyBackfill::class)->run();
        $this->assertSame(0, $again['organizations_created']);
        $this->assertSame(0, $again['calls_by_client_id']);
        $this->assertSame(0, $again['agent_assignments_created']);
        $this->assertSame(2, Organization::count());
    }

    public function test_backfill_dry_run_saves_nothing(): void
    {
        $agent = $this->user('agent');
        $client = $this->user('client');
        $call = $this->legacyCall(['client_id' => (string) $client->id, 'user_id' => $agent->id]);

        $this->artisan('tenancy:backfill', ['--dry-run' => true])
            ->expectsOutputToContain('Dry run')
            ->assertSuccessful();

        $this->assertSame(0, Organization::count());
        $this->assertNull($call->fresh()->organization_id);
    }

    public function test_call_from_unknown_email_stays_unassigned(): void
    {
        $agent = $this->user('agent');
        $this->user('client', ['email' => 'owner@example.test']);
        $call = $this->legacyCall(['client_id' => null, 'caller_email' => 'stranger@example.test', 'user_id' => $agent->id]);

        app(TenancyBackfill::class)->run();

        $this->assertNull($call->fresh()->organization_id);
        $this->assertSame(CallOwnershipSource::Unassigned, $call->fresh()->ownership_source);
    }
}
