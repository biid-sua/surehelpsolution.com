<?php

namespace Tests\Feature;

use App\Actions\Organizations\ProvisionUserTenancy;
use App\Models\CallLog;
use App\Models\User;
use App\Services\CallStatsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class DashboardFixesTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function makeCall(User $agent, array $overrides = []): CallLog
    {
        return CallLog::create(array_merge([
            'client_id' => null,
            'call_date' => now()->toDateString(),
            'call_time' => now()->format('H:i'),
            'reason_for_call' => 'general-inquiry',
            'call_outcome' => 'resolved-by-agent',
            'agent_name' => $agent->name,
            'status' => 'new',
            'service_request' => false,
            'user_id' => $agent->id,
        ], $overrides, [
            'call_id' => $overrides['call_id'] ?? CallLog::generateCallId(),
        ]));
    }

    /** FIX-01: KPI cards and the chart count the same calls. */
    public function test_kpis_and_chart_agree_for_today(): void
    {
        Carbon::setTestNow('2026-10-03 14:30:00');
        $agent = User::factory()->create(['role' => 'agent']);

        $this->makeCall($agent, ['service_request' => true]);
        $this->makeCall($agent, ['call_outcome' => 'scheduled-appointment']);
        $this->makeCall($agent, ['call_outcome' => 'callback-requested']);

        $stats = app(CallStatsService::class);
        $kpis = $stats->kpis($agent->id, 'today');
        $performance = $stats->performance($agent->id);

        $this->assertSame(3, $kpis['total_calls']['value']);
        $this->assertSame(1, $kpis['service_requests']['value']);
        $this->assertSame(1, $kpis['total_schedules']['value']);
        $this->assertSame(1, $kpis['request_callback']['value']);
        $this->assertSame(3, array_sum(array_column($performance['hourly_calls'], 'calls')));
        $this->assertSame(3, $performance['hourly_calls'][14]['calls']);
        $this->assertSame(3, array_sum(array_column($performance['weekly_calls'], 'calls')));
    }

    public function test_agent_dashboard_renders_real_kpis(): void
    {
        $agent = User::factory()->create(['role' => 'agent', 'is_active' => true]);
        $this->makeCall($agent);
        $this->makeCall($agent);

        $this->actingAs($agent)
            ->get(route('admin.agent-dashboard'))
            ->assertOk()
            ->assertSee('id="totalCallsValue" data-target="2"', false)
            ->assertDontSee('User Management')
            ->assertDontSee('Content Management')
            ->assertDontSee('SEO Tools')
            ->assertSee('&copy; '.now()->year, false);
    }

    /** FIX-02: dropped calls are no longer labelled "Scheduled". */
    public function test_status_label_reflects_the_call_record(): void
    {
        $agent = User::factory()->create(['role' => 'agent']);

        $this->assertSame('Dropped', $this->makeCall($agent, ['call_outcome' => 'call-dropped'])->statusLabel());
        $this->assertSame('Scheduled', $this->makeCall($agent, ['call_outcome' => 'scheduled-appointment'])->statusLabel());
        $this->assertSame('In Progress', $this->makeCall($agent, ['status' => 'service-requested'])->statusLabel());
        $this->assertSame('New', $this->makeCall($agent)->statusLabel());
        $this->assertSame('Spam', $this->makeCall($agent, ['status' => 'spam'])->statusLabel());
    }

    /** FIX-02 + FIX-03 on the rendered client dashboard. */
    public function test_client_call_history_shows_real_status_and_no_null_text(): void
    {
        $agent = User::factory()->create(['role' => 'agent']);
        $client = User::factory()->create(['role' => 'client', 'is_active' => true, 'must_change_password' => false]);
        $organization = app(ProvisionUserTenancy::class)->handle($client);

        $this->makeCall($agent, [
            'client_id' => (string) $client->id,
            'organization_id' => $organization->id,
            'call_outcome' => 'call-dropped',
            'service_location' => null,
        ]);

        $this->actingAs($client)
            ->get(route('admin.client-dashboard'))
            ->assertOk()
            ->assertSee('>Dropped</span>', false)
            ->assertDontSee('>Scheduled</span>', false)
            ->assertDontSee('<td>null</td>', false)
            ->assertDontSee('<td></td>', false);
    }

    /** FIX-03 */
    public function test_display_replaces_empty_values(): void
    {
        $this->assertSame('—', CallLog::display(null));
        $this->assertSame('—', CallLog::display(''));
        $this->assertSame('—', CallLog::display('null'));
        $this->assertSame('12 Main St', CallLog::display('12 Main St'));
    }

    /** FIX-04: IDs are sequential per day, server-side, and never repeat. */
    public function test_call_ids_are_sequential_and_unique(): void
    {
        Carbon::setTestNow('2026-10-03 09:00:00');

        $first = CallLog::generateCallId();
        $second = CallLog::generateCallId();

        $this->assertSame('CL-20261003-0001', $first);
        $this->assertSame('CL-20261003-0002', $second);

        Carbon::setTestNow('2026-10-04 09:00:00');
        $this->assertSame('CL-20261004-0001', CallLog::generateCallId());
    }

    public function test_call_id_counter_continues_after_existing_ids(): void
    {
        Carbon::setTestNow('2026-10-03 09:00:00');
        $agent = User::factory()->create(['role' => 'agent']);
        $this->makeCall($agent, ['call_id' => 'CL-20261003-0041']);

        $this->assertSame('CL-20261003-0042', CallLog::generateCallId());
    }

    public function test_storing_a_call_log_ignores_client_supplied_id(): void
    {
        Carbon::setTestNow('2026-10-03 09:00:00');
        $agent = User::factory()->create(['role' => 'agent', 'is_active' => true, 'must_change_password' => false]);

        $this->actingAs($agent)
            ->postJson(route('admin.call-logs.store'), [
                'call_id' => 'CL-FAKE-9999',
                'call_date' => '2026-10-03',
                'call_time' => '09:00',
                'reason_for_call' => 'general-inquiry',
                'call_outcome' => 'resolved-by-agent',
                'agent_name' => $agent->name,
                'status' => 'new',
            ])
            ->assertOk()
            ->assertJson(['success' => true, 'call_id' => 'CL-20261003-0001']);
    }
}
