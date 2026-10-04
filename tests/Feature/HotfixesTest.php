<?php

namespace Tests\Feature;

use App\Models\AgentDutySchedule;
use App\Models\CallLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * P1-0 hotfixes from docs/implementation-plan.md.
 */
class HotfixesTest extends TestCase
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

    private function callPayload(array $overrides = []): array
    {
        return array_merge([
            'call_date' => now()->toDateString(),
            'call_time' => '09:00',
            'reason_for_call' => 'general-inquiry',
            'call_outcome' => 'resolved-by-agent',
            'agent_name' => 'Agent',
            'status' => 'new',
        ], $overrides);
    }

    // HF-2 ------------------------------------------------------------------

    public function test_client_cannot_use_agent_workspace_routes(): void
    {
        $client = $this->user('client');

        $this->actingAs($client)->postJson(route('admin.call-logs.store'), $this->callPayload())->assertForbidden();
        $this->actingAs($client)->getJson(route('admin.call-logs.index'))->assertForbidden();
        $this->actingAs($client)->getJson(route('admin.clients.list'))->assertForbidden();
        $this->actingAs($client)->getJson(route('admin.kpi-data', 'today'))->assertForbidden();
        $this->actingAs($client)->get(route('admin.call-logs.export'))->assertForbidden();

        $this->assertSame(0, CallLog::count());
    }

    public function test_agent_can_still_use_agent_workspace_routes(): void
    {
        $agent = $this->user('agent');

        $this->actingAs($agent)->postJson(route('admin.call-logs.store'), $this->callPayload())->assertOk();
        $this->actingAs($agent)->getJson(route('admin.clients.list'))->assertOk();
        $this->actingAs($agent)->getJson(route('admin.kpi-data', 'today'))->assertOk();
    }

    public function test_only_admin_can_probe_schedule_conflicts(): void
    {
        $agent = $this->user('agent');

        $this->actingAs($agent)
            ->postJson(route('admin.duty-schedules.check-conflicts'), [])
            ->assertForbidden();
    }

    public function test_debug_route_is_removed(): void
    {
        $this->actingAs($this->user('admin'))->get('/admin/users/debug')->assertNotFound();
    }

    // HF-5 ------------------------------------------------------------------

    public function test_web_login_is_throttled(): void
    {
        $this->user('client', ['email' => 'owner@example.com']);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson(route('auth.login'), ['email' => 'owner@example.com', 'password' => 'wrong'])
                ->assertStatus(422);
        }

        $this->postJson(route('auth.login'), ['email' => 'owner@example.com', 'password' => 'wrong'])
            ->assertStatus(429);
    }

    public function test_api_login_is_throttled(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/login', ['email' => 'nobody@example.com', 'password' => 'wrong-password']);
        }

        $this->postJson('/api/v1/login', ['email' => 'nobody@example.com', 'password' => 'wrong-password'])
            ->assertStatus(429);
    }

    // HF-6 ------------------------------------------------------------------

    public function test_deactivated_user_token_is_rejected_and_revoked(): void
    {
        $client = $this->user('client');
        $token = $client->createToken('auth-token')->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/user')->assertOk();

        $client->update(['is_active' => false]);
        $this->app['auth']->forgetGuards();

        $this->withToken($token)->getJson('/api/v1/user')
            ->assertForbidden()
            ->assertJson(['account_deactivated' => true]);

        $this->assertSame(0, $client->tokens()->count());
    }

    public function test_deactivating_a_user_revokes_their_tokens(): void
    {
        $admin = $this->user('admin');
        $agent = $this->user('agent');
        $agent->createToken('phone');

        $this->actingAs($admin)
            ->patchJson(route('admin.users.toggle-status', $agent))
            ->assertOk();

        $this->assertFalse($agent->fresh()->is_active);
        $this->assertSame(0, $agent->tokens()->count());
    }

    // HF-7 ------------------------------------------------------------------

    public function test_duty_schedule_api_round_trip(): void
    {
        $admin = $this->user('admin');
        $agent = $this->user('agent');
        Sanctum::actingAs($admin);

        $created = $this->postJson('/api/v1/admin/duty-schedules', [
            'agent_id' => $agent->id,
            'schedule_date' => '2026-10-10',
            'start_time' => '09:00',
            'end_time' => '17:00',
            'shift_type' => 'morning',
            'notes' => 'Front desk',
        ])->assertOk()
            ->assertJsonPath('data.schedule.schedule_date', '2026-10-10')
            ->assertJsonPath('data.schedule.start_time', '09:00')
            ->assertJsonPath('data.schedule.end_time', '17:00')
            ->assertJsonPath('data.schedule.notes', 'Front desk');

        $id = $created->json('data.schedule.id');
        $this->assertSame('Morning Shift', AgentDutySchedule::find($id)->title);

        // Overlapping shift is rejected.
        $this->postJson('/api/v1/admin/duty-schedules', [
            'agent_id' => $agent->id,
            'schedule_date' => '2026-10-10',
            'start_time' => '16:00:00',
            'end_time' => '18:00:00',
            'shift_type' => 'evening',
        ])->assertStatus(409);

        $this->getJson('/api/v1/admin/duty-schedules?date_from=2026-10-10&date_to=2026-10-10')
            ->assertOk()
            ->assertJsonCount(1, 'data.schedules');

        $this->getJson('/api/v1/admin/duty-schedules/calendar?start=2026-10-01&end=2026-10-31')
            ->assertOk()
            ->assertJsonCount(1, 'data.events');

        $this->postJson('/api/v1/admin/duty-schedules/check-conflicts', [
            'agent_id' => $agent->id,
            'schedule_date' => '2026-10-10',
            'start_time' => '08:00',
            'end_time' => '10:00',
        ])->assertOk()->assertJsonPath('data.has_conflicts', true);

        $this->putJson("/api/v1/admin/duty-schedules/{$id}", ['end_time' => '15:00'])
            ->assertOk()
            ->assertJsonPath('data.schedule.end_time', '15:00');

        $this->deleteJson("/api/v1/admin/duty-schedules/{$id}")->assertOk();
        $this->assertNull(AgentDutySchedule::find($id));
    }

    public function test_agent_sees_only_own_schedules_via_api(): void
    {
        $agent = $this->user('agent');
        $other = $this->user('agent');

        foreach ([$agent, $other] as $owner) {
            AgentDutySchedule::create([
                'agent_id' => $owner->id,
                'title' => 'Shift',
                'start_datetime' => '2026-10-10 09:00:00',
                'end_datetime' => '2026-10-10 17:00:00',
                'shift_type' => 'morning',
                'is_active' => true,
            ]);
        }

        Sanctum::actingAs($agent);

        $this->getJson('/api/v1/duty-schedules')
            ->assertOk()
            ->assertJsonCount(1, 'data.schedules')
            ->assertJsonPath('data.schedules.0.agent_id', $agent->id);
    }

    // HF-8 ------------------------------------------------------------------

    public function test_completed_status_can_be_saved(): void
    {
        $agent = $this->user('agent');

        $this->actingAs($agent)
            ->postJson(route('admin.call-logs.store'), $this->callPayload(['status' => 'completed']))
            ->assertOk();

        $this->assertSame('Completed', CallLog::first()->statusLabel());
    }

    // HF-9 ------------------------------------------------------------------

    public function test_api_errors_do_not_expose_exception_messages(): void
    {
        $source = '';
        foreach (glob(app_path('Http/Controllers/Api/*.php')) as $file) {
            $source .= file_get_contents($file);
        }

        $this->assertStringNotContainsString("'error' => \$e->getMessage()", $source);
    }

    // HF-10 -----------------------------------------------------------------

    public function test_admin_dashboard_shows_real_recent_calls_not_sample_data(): void
    {
        $admin = $this->user('admin');
        $agent = $this->user('agent', ['name' => 'Rita Agent']);
        $client = $this->user('client', ['name' => 'Plumbing Co']);

        CallLog::create($this->callPayload([
            'call_id' => CallLog::generateCallId(),
            'client_id' => (string) $client->id,
            'call_outcome' => 'call-dropped',
            'user_id' => $agent->id,
        ]));

        $this->actingAs($admin)->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Rita Agent')
            ->assertSee('Plumbing Co')
            ->assertSee('Dropped')
            ->assertDontSee('John Doe')
            ->assertDontSee('ABC Corp');
    }

    public function test_agent_dashboard_has_no_fake_notifications(): void
    {
        $this->actingAs($this->user('agent'))->get(route('admin.agent-dashboard'))
            ->assertOk()
            ->assertDontSee('New message received')
            ->assertDontSee('System update available');
    }

    public function test_agent_can_export_own_calls_as_csv(): void
    {
        $agent = $this->user('agent');
        $other = $this->user('agent');

        CallLog::create($this->callPayload(['call_id' => 'CL-MINE-0001', 'caller_name' => '=HYPERLINK("x")', 'user_id' => $agent->id]));
        CallLog::create($this->callPayload(['call_id' => 'CL-OTHER-0001', 'user_id' => $other->id]));

        $csv = $this->actingAs($agent)->get(route('admin.call-logs.export'))
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8')
            ->streamedContent();

        $this->assertStringContainsString('CL-MINE-0001', $csv);
        $this->assertStringNotContainsString('CL-OTHER-0001', $csv);
        $this->assertStringContainsString("'=HYPERLINK", $csv);
    }

    // HF-12 -----------------------------------------------------------------

    public function test_call_ownership_audit_reports_groups(): void
    {
        $agent = $this->user('agent');
        $client = $this->user('client', ['email' => 'owner@plumbing.test']);
        $other = $this->user('client');

        CallLog::create($this->callPayload(['call_id' => 'CL-A', 'client_id' => (string) $client->id, 'user_id' => $agent->id]));
        CallLog::create($this->callPayload(['call_id' => 'CL-B', 'client_id' => null, 'user_id' => $agent->id]));
        CallLog::create($this->callPayload(['call_id' => 'CL-C', 'client_id' => '999999', 'user_id' => $agent->id]));
        CallLog::create($this->callPayload([
            'call_id' => 'CL-D',
            'client_id' => (string) $other->id,
            'caller_email' => 'owner@plumbing.test',
            'user_id' => $agent->id,
        ]));

        $this->artisan('audit:call-ownership', ['--details' => true])
            ->expectsOutputToContain('Call logs: 4')
            ->expectsOutputToContain('no_client: CL-B')
            ->expectsOutputToContain('invalid_client: CL-C')
            ->expectsOutputToContain('email_only: CL-D')
            ->assertSuccessful();

        $this->assertSame(4, CallLog::count());
    }
}
