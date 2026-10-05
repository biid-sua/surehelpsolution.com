<?php

namespace Tests\Feature;

use App\Models\AgentDutySchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The mobile API checks permissions, like the web screens (docs/decisions.md D23, D32): the portal
 * comes from the route, what someone may do from their role's permissions.
 */
class ApiPermissionsTest extends TestCase
{
    use RefreshDatabase;

    private function staff(string $role): User
    {
        $user = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        if ($role !== 'super_admin') {
            $user->syncRoles([$role]);
        }

        return $user;
    }

    private function agent(?string $role = null): User
    {
        $user = User::factory()->create(['role' => 'agent', 'is_active' => true]);
        if ($role) {
            $user->syncRoles([$role]);
        }

        return $user;
    }

    private function shift(User $agent): AgentDutySchedule
    {
        return AgentDutySchedule::create(['agent_id' => $agent->id, 'title' => 'Morning', 'shift_type' => 'morning', 'is_active' => true,
            'start_datetime' => now()->addDay()->setTime(9, 0), 'end_datetime' => now()->addDay()->setTime(17, 0)]);
    }

    public function test_admin_endpoints_follow_the_staff_members_permissions(): void
    {
        $agent = $this->agent();
        $newUser = ['name' => 'New Agent', 'email' => 'new@surehelp.test', 'password' => 'Secret123!', 'password_confirmation' => 'Secret123!', 'role' => 'agent'];

        // Support: can look at people, calls and the schedule; can't change them.
        $this->actingAs($this->staff('support_agent'), 'sanctum');
        $this->getJson('/api/v1/admin/users')->assertOk();
        $this->getJson('/api/v1/admin/call-logs')->assertOk();
        $this->getJson('/api/v1/admin/duty-schedules')->assertOk();
        $this->postJson('/api/v1/admin/users', $newUser)->assertForbidden()->assertJson(['success' => false]);
        $this->deleteJson('/api/v1/admin/users/'.$agent->id)->assertForbidden();
        $this->postJson('/api/v1/admin/duty-schedules', [])->assertForbidden();

        // Operations managers change people but can't delete them.
        $this->actingAs($this->staff('operations_manager'), 'sanctum');
        $this->postJson('/api/v1/admin/duty-schedules/check-conflicts', [])->assertStatus(422);
        $this->deleteJson('/api/v1/admin/users/'.$agent->id)->assertForbidden();

        // Super admins can do everything.
        $this->actingAs($this->staff('super_admin'), 'sanctum');
        $this->getJson('/api/v1/admin/agent-performance')->assertOk();
        $this->deleteJson('/api/v1/admin/users/'.$agent->id)->assertOk();
    }

    public function test_agents_see_only_their_own_shifts_and_numbers(): void
    {
        $ana = $this->agent();
        $ben = $this->agent();
        $this->shift($ana);
        $this->shift($ben);

        $this->actingAs($ana, 'sanctum');
        $ids = collect($this->getJson('/api/v1/duty-schedules?agent_id='.$ben->id)->assertOk()->json('data.schedules'))->pluck('agent_id')->unique()->values()->all();
        $this->assertSame([$ana->id], $ids);

        // Supervisors too: they plan nothing here, so no one else's shifts.
        $this->actingAs($this->agent('agent_supervisor'), 'sanctum');
        $this->assertSame([], $this->getJson('/api/v1/duty-schedules?agent_id='.$ben->id)->assertOk()->json('data.schedules'));
        $this->getJson('/api/v1/admin/duty-schedules')->assertForbidden();

        // Schedulers see anyone's.
        $this->actingAs($this->staff('operations_manager'), 'sanctum');
        $this->assertSame([$ben->id], collect($this->getJson('/api/v1/duty-schedules?agent_id='.$ben->id)->json('data.schedules'))->pluck('agent_id')->unique()->values()->all());
    }
}
