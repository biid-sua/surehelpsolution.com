<?php

namespace Tests\Feature;

use App\Actions\Organizations\ProvisionUserTenancy;
use App\Models\AgentDutySchedule;
use App\Models\CallLog;
use App\Models\Customer;
use App\Models\QaReview;
use App\Models\ShiftRequest;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Mobile API (D74): agents' shift requests (API-13), quality reviews (API-14) and availability
 * (API-15); search (API-17); the generated OpenAPI description (API-19).
 */
class MobileApiAgentsTest extends TestCase
{
    use RefreshDatabase;

    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->agent = User::factory()->create(['role' => 'agent', 'is_active' => true, 'name' => 'Sam Agent']);
    }

    public function test_agents_ask_for_swaps_and_time_off_and_withdraw_them(): void
    {
        $shift = AgentDutySchedule::create(['agent_id' => $this->agent->id, 'title' => 'Morning', 'shift_type' => 'morning', 'is_active' => true,
            'start_datetime' => CarbonImmutable::now()->addDays(2)->setTime(8, 0), 'end_datetime' => CarbonImmutable::now()->addDays(2)->setTime(16, 0)]);
        $colleague = User::factory()->create(['role' => 'agent', 'is_active' => true, 'name' => 'Lee']);

        Sanctum::actingAs($this->agent);
        $this->assertContains('Lee', array_column($this->getJson('/api/v1/agent/shift-requests')->assertOk()->json('data.colleagues'), 'name'));

        $swap = $this->postJson('/api/v1/agent/shift-requests', ['type' => 'swap', 'shift_id' => $shift->id, 'swap_with_id' => $colleague->id, 'reason' => 'Dentist'])
            ->assertCreated()->json('data.request');
        $this->assertSame(['swap', 'pending', 'Lee'], [$swap['type'], $swap['status'], $swap['swap_with']['name']]);

        $this->postJson('/api/v1/agent/shift-requests', ['type' => 'leave', 'leave_from' => now()->subDay()->toDateString(), 'leave_until' => now()->toDateString()])->assertStatus(422);
        $this->postJson('/api/v1/agent/shift-requests', ['type' => 'leave', 'leave_from' => now()->addWeek()->toDateString(), 'leave_until' => now()->addWeek()->addDays(2)->toDateString()])->assertCreated();
        $this->assertCount(2, $this->getJson('/api/v1/agent/shift-requests')->json('data.requests'));

        $this->postJson("/api/v1/agent/shift-requests/{$swap['id']}/withdraw")->assertOk()->assertJsonPath('data.request.status', ShiftRequest::CANCELLED);

        // Someone else's request can't be withdrawn.
        Sanctum::actingAs($colleague);
        $this->postJson("/api/v1/agent/shift-requests/{$swap['id']}/withdraw")->assertNotFound();
    }

    public function test_agents_read_and_acknowledge_their_quality_reviews(): void
    {
        $owner = User::factory()->create(['role' => 'client', 'is_active' => true]);
        $business = app(ProvisionUserTenancy::class)->handle($owner);
        $call = CallLog::create(['user_id' => $this->agent->id, 'call_id' => CallLog::generateCallId(), 'organization_id' => $business->id, 'call_date' => now()->toDateString(),
            'call_time' => '09:00', 'caller_name' => 'Maria', 'reason_for_call' => 'Leak', 'call_outcome' => 'booked', 'agent_name' => 'Sam', 'status' => 'new']);
        $reviewer = User::factory()->create(['role' => 'admin', 'is_active' => true, 'name' => 'Pat Supervisor']);
        $review = QaReview::create(['call_log_id' => $call->id, 'organization_id' => $business->id, 'agent_user_id' => $this->agent->id, 'reviewer_id' => $reviewer->id,
            'source' => 'sample', 'status' => QaReview::COMPLETED, 'score' => 88, 'passed' => true, 'reviewed_at' => now(), 'strengths' => 'Warm greeting', 'improvements' => 'Confirm the address',
            'scores' => ['greeting' => ['label' => 'Greeting', 'weight' => 10, 'critical' => false, 'mark' => 'met']]]);
        $second = $call->replicate(['call_id'])->fill(['call_id' => CallLog::generateCallId()]);
        $second->save();
        QaReview::create(['call_log_id' => $second->id, 'organization_id' => $business->id, 'agent_user_id' => $this->agent->id, 'source' => 'sample']);   // not scored yet

        Sanctum::actingAs($this->agent);
        $data = $this->getJson('/api/v1/agent/quality/reviews')->assertOk()->json('data');
        $this->assertSame(1, $data['summary']['count']);
        $this->assertCount(1, $data['reviews']);
        $one = $this->getJson("/api/v1/agent/quality/reviews/{$review->ulid}")->assertOk()->json('data.review');
        $this->assertSame([88, 'Pat Supervisor', 'Confirm the address', 'Met'], [$one['score'], $one['reviewer'], $one['improvements'], $one['scores'][0]['mark_label']]);

        $this->postJson("/api/v1/agent/quality/reviews/{$review->ulid}/acknowledge")->assertOk();
        $this->assertNotNull($review->fresh()->acknowledged_at);

        Sanctum::actingAs(User::factory()->create(['role' => 'agent', 'is_active' => true]));
        $this->getJson("/api/v1/agent/quality/reviews/{$review->ulid}")->assertNotFound();
    }

    public function test_the_app_steps_away_and_back_only_while_the_softphone_is_online(): void
    {
        Sanctum::actingAs($this->agent);
        $this->getJson('/api/v1/agent/presence')->assertOk()->assertJsonPath('data.status', 'offline')->assertJsonPath('data.softphone_online', false);
        $this->putJson('/api/v1/agent/presence', ['status' => 'available'])->assertStatus(409);
        $this->assertNull($this->agent->fresh()->phone_seen_at, 'the app never counts as the softphone checking in');

        $this->agent->forceFill(['phone_status' => 'available', 'phone_status_at' => now(), 'phone_seen_at' => now()])->save();
        $this->putJson('/api/v1/agent/presence', ['status' => 'away'])->assertOk()->assertJsonPath('data.status', 'away');
        $this->putJson('/api/v1/agent/presence', ['status' => 'available'])->assertOk()->assertJsonPath('data.status', 'available');
        $this->putJson('/api/v1/agent/presence', ['status' => 'busy'])->assertStatus(422);

        $this->agent->forceFill(['phone_status' => 'busy'])->save();
        $this->putJson('/api/v1/agent/presence', ['status' => 'away'])->assertStatus(409);

        Sanctum::actingAs(User::factory()->create(['role' => 'client', 'is_active' => true]));
        $this->getJson('/api/v1/agent/presence')->assertForbidden();
    }

    public function test_search_finds_what_the_person_may_see(): void
    {
        $owner = User::factory()->create(['role' => 'client', 'is_active' => true, 'must_change_password' => false]);
        $business = app(ProvisionUserTenancy::class)->handle($owner);
        Customer::create(['organization_id' => $business->id, 'first_name' => 'Maria', 'last_name' => 'Lopez', 'phone' => '5125550147']);
        CallLog::create(['user_id' => $this->agent->id, 'call_id' => CallLog::generateCallId(), 'organization_id' => $business->id, 'call_date' => now()->toDateString(),
            'call_time' => '09:00', 'caller_name' => 'Maria Lopez', 'reason_for_call' => 'Leak', 'call_outcome' => 'booked', 'agent_name' => 'Sam', 'status' => 'new']);

        Sanctum::actingAs($owner);
        $results = $this->getJson('/api/v1/client/search?q=maria')->assertOk()->json('data.results');
        $this->assertSame('Maria Lopez', $results['customers'][0]['name']);
        $this->assertSame('Maria Lopez', $results['calls'][0]['caller']);
        $this->getJson('/api/v1/client/search?q=m')->assertStatus(422);
    }

    public function test_the_openapi_description_is_built_from_the_routes(): void
    {
        $spec = $this->getJson('/api/v1/openapi.json')->assertOk()->json();

        $this->assertSame('3.1.0', $spec['openapi']);
        $this->assertArrayHasKey('/login', $spec['paths']);
        $this->assertSame([], $spec['paths']['/app/version']['get']['security'], 'no sign-in');
        $pay = $spec['paths']['/client/billing/invoices/{ulid}/pay']['post'];
        $this->assertSame([['bearer' => []]], $pay['security']);
        $this->assertStringContainsString('Permission: billing.manage', $pay['description']);
        $this->assertSame('ulid', $pay['parameters'][0]['name']);
        $this->assertSame('#/components/parameters/IdempotencyKey', $spec['paths']['/client/customers']['post']['parameters'][0]['$ref']);
        foreach ($spec['paths'] as $path => $operations) {
            $this->assertStringStartsNotWith('//', $path);
        }
    }
}
