<?php

namespace Tests\Feature;

use App\Actions\Organizations\ProvisionUserTenancy;
use App\Models\CallLog;
use App\Models\Organization;
use App\Models\User;
use App\Support\Authorization\RoleCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * P1-3 — permission-based authorization (spec §5, §64; docs/permissions.md).
 */
class PermissionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['tenancy.auto_assign_agents' => false]);
    }

    private function user(string $role, array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'role' => $role,
            'is_active' => true,
            'must_change_password' => false,
        ], $attributes));
    }

    private function member(Organization $organization, string $orgRole): User
    {
        $user = $this->user('client');
        $organization->members()->attach($user->id, ['role' => $orgRole, 'status' => 'active']);

        return $user;
    }

    public function test_catalogue_matches_spec_and_is_synced(): void
    {
        $permissions = app(RoleCatalog::class)->permissions();

        $this->assertSame($permissions, array_values(array_unique($permissions)), 'no duplicates');
        foreach (['dashboard.view', 'calls.recording.view', 'billing.manage', 'settings.manage', 'social.manage'] as $p) {
            $this->assertContains($p, $permissions);
        }

        $this->assertDatabaseCount('permissions', count($permissions));
        $this->assertDatabaseHas('roles', ['name' => 'super_admin']);
        $this->assertDatabaseHas('roles', ['name' => 'agent']);
    }

    public function test_organization_roles(): void
    {
        $org = Organization::factory()->create();
        $owner = $this->member($org, 'owner');
        $manager = $this->member($org, 'manager');
        $staff = $this->member($org, 'staff');

        $this->assertTrue($owner->can('billing.manage', $org));
        $this->assertTrue($manager->can('billing.view', $org));
        $this->assertFalse($manager->can('billing.manage', $org));
        // Spec §64: staff cannot access billing.
        $this->assertFalse($staff->can('billing.view', $org));
        $this->assertTrue($staff->can('calls.view', $org));
    }

    public function test_membership_grants_nothing_in_another_organization(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $owner = $this->member($orgA, 'owner');

        $this->assertTrue($owner->can('calls.view', $orgA));
        $this->assertFalse($owner->can('calls.view', $orgB));
    }

    public function test_agent_permissions_apply_only_in_assigned_organizations(): void
    {
        $assigned = Organization::factory()->create();
        $other = Organization::factory()->create();
        $agent = $this->user('agent');
        $assigned->assignAgent($agent);

        $this->assertTrue($agent->hasRole('agent'));
        $this->assertTrue($agent->can('calls.create', $assigned));
        $this->assertFalse($agent->can('calls.create', $other));
        // Spec §3.3: agents never see client billing.
        $this->assertFalse($agent->can('billing.view', $assigned));
    }

    public function test_platform_roles_apply_everywhere(): void
    {
        $org = Organization::factory()->create();
        $admin = $this->user('admin');

        $this->assertTrue($admin->hasRole('super_admin'));
        $this->assertTrue($admin->can('billing.manage', $org));

        $support = $this->user('admin');
        $support->syncRoles(['support_agent']);
        $this->assertTrue($support->fresh()->can('calls.view', $org));
        $this->assertFalse($support->fresh()->can('calls.delete', $org));
    }

    public function test_changing_portal_type_replaces_global_role(): void
    {
        $admin = $this->user('admin');
        Sanctum::actingAs($this->user('admin'));

        $this->putJson("/api/v1/admin/users/{$admin->id}", ['role' => 'agent'])->assertOk();

        $admin->refresh();
        $this->assertFalse($admin->hasRole('super_admin'), 'demotion must not leave Super Admin behind');
        $this->assertTrue($admin->hasRole('agent'));
    }

    public function test_sync_gives_legacy_users_their_default_role(): void
    {
        $agent = $this->user('agent');
        $agent->syncRoles([]);

        app(RoleCatalog::class)->sync();

        $this->assertTrue($agent->fresh()->hasRole('agent'));
    }

    public function test_client_staff_can_open_the_client_dashboard(): void
    {
        $owner = $this->user('client');
        $org = app(ProvisionUserTenancy::class)->handle($owner);
        $staff = $this->member($org, 'staff');

        $this->actingAs($staff)->get(route('app.dashboard'))->assertOk();
    }

    public function test_agent_cannot_edit_calls_after_being_unassigned(): void
    {
        $org = Organization::factory()->create();
        $agent = $this->user('agent');
        $org->assignAgent($agent);

        $call = CallLog::create([
            'call_id' => CallLog::generateCallId(),
            'organization_id' => $org->id,
            'call_date' => now()->toDateString(),
            'call_time' => '10:00',
            'reason_for_call' => 'general-inquiry',
            'call_outcome' => 'resolved-by-agent',
            'agent_name' => 'Agent',
            'status' => 'new',
            'user_id' => $agent->id,
        ]);

        Sanctum::actingAs($agent);
        $this->putJson("/api/v1/agent/call-logs/{$call->id}", ['notes' => 'ok'])->assertOk();

        $org->agents()->detach($agent->id);
        $this->putJson("/api/v1/agent/call-logs/{$call->id}", ['notes' => 'again'])->assertForbidden();

        $other = $this->user('agent');
        $org->assignAgent($other);
        Sanctum::actingAs($other);
        $this->putJson("/api/v1/agent/call-logs/{$call->id}", ['notes' => 'not mine'])->assertForbidden();
    }

    public function test_api_update_of_missing_call_returns_404(): void
    {
        Sanctum::actingAs($this->user('agent'));

        $this->putJson('/api/v1/agent/call-logs/999999', ['notes' => 'x'])->assertNotFound();
    }
}
