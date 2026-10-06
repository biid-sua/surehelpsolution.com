<?php

namespace Tests\Feature;

use App\Actions\Organizations\ProvisionUserTenancy;
use App\Actions\Tasks\CreateCallbackTask;
use App\Enums\OutcomeCategory;
use App\Livewire\Client\Calls\Index as CallsIndex;
use App\Livewire\Client\Dashboard;
use App\Models\CallLog;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * P1-4 — business portal (/app): real data, tenant isolation, filters, export.
 */
class ClientPortalTest extends TestCase
{
    use RefreshDatabase;

    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agent = User::factory()->create(['role' => 'agent', 'is_active' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array{0: User, 1: Organization} */
    private function client(array $attributes = []): array
    {
        $client = User::factory()->create(array_merge(['role' => 'client', 'is_active' => true, 'must_change_password' => false], $attributes));

        return [$client, app(ProvisionUserTenancy::class)->handle($client)];
    }

    private function callFor(Organization $organization, array $attributes = []): CallLog
    {
        $createdAt = $attributes['created_at'] ?? null;
        unset($attributes['created_at']);

        $call = CallLog::withoutGlobalScopes()->create(array_merge([
            'call_id' => CallLog::generateCallId(),
            'organization_id' => $organization->id,
            'call_date' => now()->toDateString(),
            'call_time' => '10:00',
            'caller_name' => 'Caller',
            'reason_for_call' => 'general-inquiry',
            'call_outcome' => 'resolved-by-agent',
            'agent_name' => 'Agent',
            'status' => 'new',
            'user_id' => $this->agent->id,
        ], $attributes));

        // A logged call-back creates its task (LogCall does this in production).
        if ($call->outcomeCategory() === OutcomeCategory::Callback) {
            app(CreateCallbackTask::class)->handle($call, $organization);
        }

        if ($createdAt) {
            // created_at isn't mass assignable; set it explicitly for time-based tests.
            $call->forceFill(['created_at' => $createdAt])->saveQuietly();
        }

        return $call;
    }

    public function test_login_sends_clients_to_the_business_portal(): void
    {
        [$client, $organization] = $this->client(['email' => 'owner@plumbing.test', 'password' => 'Secret123!']);

        // A new business starts in the setup wizard; once set up, owners land on the dashboard.
        $this->postJson(route('auth.login'), ['email' => 'owner@plumbing.test', 'password' => 'Secret123!'])
            ->assertOk()
            ->assertJsonPath('redirect', route('app.setup'));
        $this->post(route('auth.logout'));
        $organization->forceFill(['setup_completed_at' => now()])->save();
        $this->postJson(route('auth.login'), ['email' => 'owner@plumbing.test', 'password' => 'Secret123!'])
            ->assertJsonPath('redirect', route('app.dashboard'));
    }

    public function test_dashboard_shows_real_counts_for_own_business_only(): void
    {
        Carbon::setTestNow('2026-10-07 15:00:00');
        [$alice, $aliceOrg] = $this->client(['name' => 'Alice Owner']);
        [, $bobOrg] = $this->client();
        $aliceOrg->update(['timezone' => 'UTC']);

        $this->callFor($aliceOrg, ['service_request' => true]);
        $this->callFor($aliceOrg, ['call_outcome' => 'call-dropped']);
        $this->callFor($aliceOrg, ['call_outcome' => 'callback-requested']);
        $this->callFor($bobOrg);
        $this->callFor($bobOrg);

        $this->actingAs($alice);
        $component = Livewire::test(Dashboard::class)->assertOk();

        $kpis = $component->viewData('kpis');
        $this->assertSame(3, $kpis['calls']['value']);
        $this->assertSame(1, $kpis['service_requests']['value']);
        $this->assertSame(1, $kpis['missed']['value']);
        $this->assertSame(1, $kpis['follow_ups']['value']);
        $this->assertSame(3, array_sum($component->viewData('series')['data']));

        $component->assertSee('1 caller waiting for a follow-up');
    }

    public function test_dashboard_period_switch_and_invalid_period(): void
    {
        [$client, $org] = $this->client();
        $this->callFor($org, ['created_at' => now()->subDays(2)]);

        $this->actingAs($client);
        Livewire::test(Dashboard::class)
            ->set('period', 'month')
            ->assertSet('period', 'month')
            ->set('period', 'decade')
            ->assertSet('period', 'today');
    }

    public function test_empty_dashboard_explains_what_happens_next(): void
    {
        [$client] = $this->client();

        $this->actingAs($client)->get(route('app.dashboard'))
            ->assertOk()
            ->assertSee('No calls yet')
            ->assertSee('Once your phone forwarding is live');
    }

    public function test_calls_list_search_filters_and_isolation(): void
    {
        [$alice, $aliceOrg] = $this->client();
        [, $bobOrg] = $this->client();

        $this->callFor($aliceOrg, ['caller_name' => 'Maria Lopez', 'call_outcome' => 'callback-requested']);
        $this->callFor($aliceOrg, ['caller_name' => 'Tom Reed', 'call_outcome' => 'call-dropped']);
        $this->callFor($bobOrg, ['caller_name' => 'Bob Secret Customer']);

        $this->actingAs($alice);

        Livewire::test(CallsIndex::class)
            ->assertSee('Maria Lopez')
            ->assertSee('Tom Reed')
            ->assertDontSee('Bob Secret Customer')
            ->set('search', 'maria')
            ->assertSee('Maria Lopez')
            ->assertDontSee('Tom Reed')
            ->set('search', '')
            ->set('view', 'missed')
            ->assertSee('Tom Reed')
            ->assertDontSee('Maria Lopez')
            ->set('view', 'follow_up')
            ->assertSee('Maria Lopez')
            ->call('clearFilters')
            ->assertSet('view', 'all');
    }

    public function test_calls_list_paginates_on_the_server(): void
    {
        [$client, $org] = $this->client();
        foreach (range(1, 25) as $i) {
            $this->callFor($org, ['caller_name' => "Caller {$i}"]);
        }

        $this->actingAs($client);
        $component = Livewire::test(CallsIndex::class);
        $this->assertCount(20, $component->viewData('calls')->items());
        $this->assertSame(25, $component->viewData('calls')->total());
    }

    public function test_date_range_uses_the_business_timezone(): void
    {
        [$client, $org] = $this->client();
        $org->update(['timezone' => 'America/Los_Angeles']);

        // 2026-10-07 23:30 in Los Angeles is 2026-10-08 06:30 UTC.
        $this->callFor($org, ['caller_name' => 'Late Caller', 'created_at' => '2026-10-08 06:30:00']);

        $this->actingAs($client);
        Livewire::test(CallsIndex::class)
            ->set('from', '2026-10-07')->set('to', '2026-10-07')
            ->assertSee('Late Caller')
            ->set('from', '2026-10-08')->set('to', '2026-10-08')
            ->assertDontSee('Late Caller');
    }

    public function test_call_detail_is_scoped_to_own_business(): void
    {
        [$alice, $aliceOrg] = $this->client();
        [, $bobOrg] = $this->client();
        $mine = $this->callFor($aliceOrg, ['caller_name' => 'Maria Lopez', 'notes' => 'Leaking water heater']);
        $theirs = $this->callFor($bobOrg);

        $this->actingAs($alice)->get(route('app.calls.show', $mine->call_id))
            ->assertOk()
            ->assertSee('Maria Lopez')
            ->assertSee('Leaking water heater');

        // Another business's call is indistinguishable from a missing one.
        $this->actingAs($alice)->get(route('app.calls.show', $theirs->call_id))->assertNotFound();
        $this->actingAs($alice)->get(route('app.calls.show', 'CL-DOES-NOT-EXIST'))->assertNotFound();
    }

    public function test_export_respects_filters_and_isolation(): void
    {
        [$alice, $aliceOrg] = $this->client();
        [, $bobOrg] = $this->client();
        $this->callFor($aliceOrg, ['caller_name' => 'Maria Lopez', 'call_outcome' => 'call-dropped']);
        $this->callFor($aliceOrg, ['caller_name' => 'Tom Reed']);
        $this->callFor($aliceOrg, ['caller_name' => '=cmd|calc', 'call_outcome' => 'call-dropped']);
        $this->callFor($bobOrg, ['caller_name' => 'Bob Secret Customer']);

        $csv = $this->actingAs($alice)->get(route('app.calls.export', ['view' => 'missed']))
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('Maria Lopez', $csv);
        $this->assertStringNotContainsString('Tom Reed', $csv);
        $this->assertStringNotContainsString('Bob Secret Customer', $csv);
        $this->assertStringContainsString("'=cmd|calc", $csv, 'formulas are neutralised');
    }

    public function test_calendar_feed_returns_only_own_service_visits(): void
    {
        [$alice, $aliceOrg] = $this->client();
        [, $bobOrg] = $this->client();
        $this->callFor($aliceOrg, ['caller_name' => 'Maria Lopez', 'service_date' => '2026-10-10', 'service_request' => true]);
        $this->callFor($aliceOrg, ['caller_name' => 'Outside Range', 'service_date' => '2026-12-10']);
        $this->callFor($bobOrg, ['caller_name' => 'Bob Secret Customer', 'service_date' => '2026-10-10']);

        $events = $this->actingAs($alice)
            ->getJson(route('app.calendar.events', ['start' => '2026-10-01T00:00:00-04:00', 'end' => '2026-11-01T00:00:00-04:00']))
            ->assertOk()
            ->json();

        $this->assertCount(1, $events);
        $this->assertStringContainsString('Maria Lopez', $events[0]['title']);
        $this->assertSame('2026-10-10', $events[0]['start']);

        $this->actingAs($alice)->getJson(route('app.calendar.events'))->assertStatus(422);
    }

    public function test_agents_and_admins_cannot_open_the_business_portal(): void
    {
        $this->actingAs($this->agent)->get(route('app.dashboard'))->assertForbidden();

        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $this->actingAs($admin)->get(route('app.calls.index'))->assertForbidden();
    }

    public function test_navigation_shows_core_items_all_live(): void
    {
        [$client] = $this->client();

        $this->actingAs($client)->get(route('app.dashboard'))
            ->assertOk()
            ->assertSee('Calls')
            ->assertSee('Calendar')
            ->assertSee('Customers')
            ->assertSee('Inbox')
            ->assertSee('Social')
            ->assertDontSee('Soon')
            ->assertSee('Skip to content');
    }
}
