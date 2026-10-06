<?php

namespace Tests\Feature;

use App\Actions\Organizations\ProvisionUserTenancy;
use App\Models\AgentDutySchedule;
use App\Models\CallLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Freezes the /api/v1 response shapes the mobile app relies on (docs/decisions.md D7).
 * Changes must be additive: if one of these fails, a mobile client would break.
 */
class ApiContractTest extends TestCase
{
    use RefreshDatabase;

    private User $agent;

    private User $client;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        config(['tenancy.auto_assign_agents' => true]); // written before D40: every agent serves every business

        $this->agent = User::factory()->create(['role' => 'agent', 'is_active' => true, 'password' => 'Secret123!', 'email' => 'agent@surehelp.test']);
        $this->admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $this->client = User::factory()->create(['role' => 'client', 'is_active' => true]);
        $organization = app(ProvisionUserTenancy::class)->handle($this->client);

        CallLog::withoutGlobalScopes()->create([
            'call_id' => CallLog::generateCallId(),
            'client_id' => (string) $this->client->id,
            'organization_id' => $organization->id,
            'call_date' => now()->toDateString(),
            'call_time' => '10:00',
            'caller_name' => 'Maria Lopez',
            'reason_for_call' => 'service-request',
            'call_outcome' => 'scheduled-appointment',
            'agent_name' => 'Agent',
            'status' => 'new',
            'service_request' => true,
            'service_date' => now()->toDateString(),
            'user_id' => $this->agent->id,
        ]);

        AgentDutySchedule::create([
            'agent_id' => $this->agent->id, 'title' => 'Shift', 'shift_type' => 'morning', 'is_active' => true,
            'start_datetime' => now()->startOfDay()->addHours(9), 'end_datetime' => now()->startOfDay()->addHours(17),
        ]);
    }

    public function test_auth_endpoints(): void
    {
        $secret = $this->enableTwoFactor(User::where('email', 'agent@surehelp.test')->sole());
        $login = $this->postJson('/api/v1/login', ['email' => 'agent@surehelp.test', 'password' => 'Secret123!', 'two_factor_code' => $this->twoFactorCode($secret)])
            ->assertOk()
            ->assertJsonStructure(['success', 'message', 'data' => [
                'user' => ['id', 'name', 'email', 'role', 'phone', 'unique_id', 'is_active', 'must_change_password', 'created_at', 'updated_at'],
                'token', 'token_type', 'must_change_password',
            ]]);

        $token = $login->json('data.token');

        $this->withToken($token)->getJson('/api/v1/user')
            ->assertOk()
            ->assertJsonStructure(['success', 'data' => ['user' => ['id', 'name', 'email', 'role', 'phone', 'unique_id', 'is_active']]]);

        $refreshed = $this->withToken($token)->postJson('/api/v1/refresh-token')
            ->assertOk()
            ->assertJsonStructure(['success', 'message', 'data' => ['token', 'token_type']]);

        $this->app['auth']->forgetGuards();
        $this->withToken($refreshed->json('data.token'))->postJson('/api/v1/logout')
            ->assertOk()
            ->assertJsonStructure(['success', 'message']);
    }

    public function test_client_endpoints(): void
    {
        Sanctum::actingAs($this->client);

        $this->getJson('/api/v1/client/dashboard/summary?period=daily')->assertOk()
            ->assertJsonStructure(['success', 'data' => ['summary' => ['total_calls', 'service_requests', 'total_scheduled', 'in_progress'], 'period', 'period_range' => ['start', 'end']]]);

        $this->getJson('/api/v1/client/call-history')->assertOk()
            ->assertJsonStructure(['success', 'data' => [
                'call_logs' => [['id', 'call_id', 'callId', 'date', 'time', 'caller_name', 'callerName', 'caller_number', 'callerNumber',
                    'call_outcome', 'callOutcome', 'agent_name', 'agentName', 'scheduled_service', 'scheduledService', 'service_location',
                    'serviceLocation', 'service_window', 'serviceWindow', 'service_date', 'serviceDate', 'reason_for_call', 'status', 'notes', 'note']],
                'callData', 'pagination' => ['limit', 'offset', 'total_count', 'has_more'], 'period', 'period_info',
            ]]);

        $this->getJson('/api/v1/client/service-requests')->assertOk()
            ->assertJsonStructure(['success', 'data' => ['service_requests' => [['id', 'call_id', 'request_date', 'service_date', 'status', 'agent_name', 'reason_for_call']], 'filters' => ['status']]]);

        $this->getJson('/api/v1/client/calendar')->assertOk()
            ->assertJsonStructure(['success', 'data' => ['events', 'date_range' => ['start', 'end']]]);

        $this->getJson('/api/v1/client/profile')->assertOk()
            ->assertJsonStructure(['success', 'data' => ['user' => ['id', 'name', 'email', 'phone', 'unique_id', 'is_active']]]);

        $this->putJson('/api/v1/client/profile', ['name' => 'Renamed Owner'])->assertOk()
            ->assertJsonStructure(['success', 'message', 'data' => ['user' => ['id', 'name', 'email', 'phone']]]);
    }

    public function test_agent_endpoints(): void
    {
        Sanctum::actingAs($this->agent);

        $this->getJson('/api/v1/agent/dashboard/kpi')->assertOk()
            ->assertJsonStructure(['success', 'data' => ['kpi_data' => ['total_calls' => ['value', 'change'], 'service_requests', 'conversion_rate', 'total_schedules', 'request_callback'], 'period', 'agent_id', 'agent_name']]);

        $this->getJson('/api/v1/agent/dashboard/performance')->assertOk()
            ->assertJsonStructure(['success', 'data' => ['performance_data' => ['daily_calls', 'call_outcomes', 'call_reasons', 'hourly_calls', 'service_trend', 'metrics'], 'agent_id', 'agent_name']]);

        $this->getJson('/api/v1/agent/call-logs')->assertOk()
            ->assertJsonStructure(['success', 'data' => ['call_logs' => [['id', 'call_id', 'caller_name', 'call_outcome', 'status', 'created_at']], 'pagination' => ['limit', 'offset', 'has_more'], 'filters']]);

        $this->getJson('/api/v1/agent/clients')->assertOk()
            ->assertJsonStructure(['success', 'data' => ['clients' => [['id', 'unique_id', 'name', 'email', 'phone']]]]);

        $created = $this->postJson('/api/v1/agent/call-logs', [
            'client_id' => (string) $this->client->id, 'call_date' => now()->toDateString(), 'call_time' => '11:00',
            'reason_for_call' => 'general-inquiry', 'call_outcome' => 'resolved-by-agent', 'agent_name' => 'Agent', 'status' => 'new',
        ])->assertOk()->assertJsonStructure(['success', 'message', 'data' => ['call_log' => ['id', 'call_id', 'status', 'created_at']]]);

        $this->putJson('/api/v1/agent/call-logs/'.$created->json('data.call_log.id'), ['notes' => 'Updated'])->assertOk()
            ->assertJsonStructure(['success', 'message', 'data' => ['call_log' => ['id', 'call_id', 'status', 'updated_at']]]);

        $this->getJson('/api/v1/duty-schedules')->assertOk()
            ->assertJsonStructure(['success', 'data' => ['schedules' => [['id', 'agent_id', 'schedule_date', 'start_time', 'end_time', 'shift_type', 'is_active', 'notes']], 'filters']]);

        $this->getJson('/api/v1/duty-schedules/calendar')->assertOk()
            ->assertJsonStructure(['success', 'data' => ['events', 'date_range' => ['start', 'end']]]);
    }

    public function test_admin_endpoints(): void
    {
        Sanctum::actingAs($this->admin);

        $this->getJson('/api/v1/admin/dashboard/stats')->assertOk()->assertJsonStructure(['success', 'data' => ['stats']]);
        $this->getJson('/api/v1/admin/analytics')->assertOk()->assertJsonStructure(['success', 'data' => ['analytics', 'period']]);
        $this->getJson('/api/v1/admin/agent-performance')->assertOk()->assertJsonStructure(['success', 'data' => ['agents']]);
        $this->getJson('/api/v1/admin/users')->assertOk()
            ->assertJsonStructure(['success', 'data' => ['users' => [['id', 'name', 'email', 'role']], 'pagination' => ['limit', 'offset', 'total', 'has_more'], 'filters']]);
        $this->getJson('/api/v1/admin/call-logs')->assertOk()
            ->assertJsonStructure(['success', 'data' => ['call_logs', 'pagination' => ['limit', 'offset', 'total', 'has_more'], 'filters']]);
        $this->getJson('/api/v1/admin/duty-schedules')->assertOk()->assertJsonStructure(['success', 'data' => ['schedules', 'filters']]);
    }

    public function test_device_token_registration(): void
    {
        Sanctum::actingAs($this->client);

        $this->postJson('/api/v1/notifications/device-token', ['token' => 'fcm-token-123', 'platform' => 'android'])
            ->assertOk()
            ->assertJsonStructure(['success', 'message', 'data']);
    }
}
