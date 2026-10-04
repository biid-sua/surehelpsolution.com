<?php

namespace Tests\Feature;

use App\Actions\Organizations\ProvisionUserTenancy;
use App\Enums\EscalationPriority;
use App\Enums\EscalationStatus;
use App\Enums\EscalationType;
use App\Enums\NotificationEvent;
use App\Enums\TimelineEventType;
use App\Livewire\Client\Dashboard;
use App\Livewire\Client\Escalations\Index;
use App\Models\CallLog;
use App\Models\Escalation;
use App\Models\NotificationPreference;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\EscalationActivity;
use App\Services\Escalations\EscalationReminderSweep;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * P2-4c — escalations (spec §25, NTF-03).
 */
class EscalationsTest extends TestCase
{
    use RefreshDatabase;

    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->agent = User::factory()->create(['role' => 'agent', 'is_active' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array{0: User, 1: Organization} */
    private function business(): array
    {
        $owner = User::factory()->create(['role' => 'client', 'is_active' => true, 'must_change_password' => false]);

        return [$owner, app(ProvisionUserTenancy::class)->handle($owner)];
    }

    private function member(Organization $organization, string $role): User
    {
        $user = User::factory()->create(['role' => 'client', 'is_active' => true, 'must_change_password' => false]);
        $organization->members()->attach($user->id, ['role' => $role, 'status' => 'active']);

        return $user;
    }

    /** @param  array<string, mixed>  $extra */
    private function logCall(User $client, string $outcome = 'escalated-to-client', array $extra = []): TestResponse
    {
        return $this->actingAs($this->agent)->postJson(route('admin.call-logs.store'), array_merge([
            'client_id' => (string) $client->id,
            'call_date' => now()->toDateString(), 'call_time' => '10:00',
            'caller_name' => 'Maria Lopez', 'caller_phone' => '(512) 555-0147',
            'reason_for_call' => 'emergency-service', 'call_outcome' => $outcome,
            'agent_name' => 'Agent', 'status' => 'new', 'notes' => 'Water pouring through the ceiling',
        ], $extra));
    }

    public function test_an_escalated_call_raises_an_escalation_and_alerts_the_team(): void
    {
        [$owner, $org] = $this->business();
        $staff = $this->member($org, 'staff');
        [$otherOwner] = $this->business();

        $this->logCall($owner, extra: ['escalation_type' => 'emergency'])->assertOk();

        $escalation = Escalation::withoutGlobalScopes()->sole();
        $call = CallLog::withoutGlobalScopes()->sole();
        $this->assertSame(EscalationType::Emergency, $escalation->type);
        $this->assertSame(EscalationPriority::Urgent, $escalation->priority, 'emergencies default to urgent');
        $this->assertSame(EscalationStatus::Open, $escalation->status);
        $this->assertSame($call->id, $escalation->call_log_id);
        $this->assertSame($call->customer_id, $escalation->customer_id);
        $this->assertStringContainsString('Maria Lopez', $escalation->reason);
        $this->assertSame('Water pouring through the ceiling', $escalation->details);
        $this->assertSame('call', $escalation->source);
        $this->assertDatabaseHas('customer_timeline_events', ['customer_id' => $call->customer_id, 'type' => TimelineEventType::Escalation->value]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'escalation.raised', 'organization_id' => $org->id]);

        Notification::assertSentTo([$owner, $staff], EscalationActivity::class, fn (EscalationActivity $n) => $n->kind === EscalationActivity::RAISED);
        Notification::assertNotSentTo($otherOwner, EscalationActivity::class);

        // Without a type, an escalation is an urgent customer issue.
        $this->logCall($owner)->assertOk();
        $this->assertSame(EscalationType::UrgentIssue, Escalation::withoutGlobalScopes()->latest('id')->first()->type);
        $this->logCall($owner, extra: ['escalation_type' => 'made-up'])->assertStatus(422);
    }

    public function test_urgent_escalations_cannot_be_switched_off(): void
    {
        [$owner, $org] = $this->business();
        NotificationPreference::create(['user_id' => $owner->id, 'event' => NotificationEvent::EscalationCreated->value, 'channels' => []]);
        $owner->load('notificationPreferences');

        $urgent = Escalation::create(['organization_id' => $org->id, 'type' => 'emergency', 'priority' => 'urgent', 'reason' => 'Flood']);
        $normal = Escalation::create(['organization_id' => $org->id, 'type' => 'pricing_approval', 'priority' => 'normal', 'reason' => 'Discount?']);

        $this->assertEqualsCanonicalizing(['database', 'mail'], (new EscalationActivity($urgent))->via($owner));
        $this->assertSame([], (new EscalationActivity($normal))->via($owner));

        $payload = (new EscalationActivity($urgent))->toArray($owner);
        $this->assertSame('escalation.created', $payload['event']);
        $this->assertStringStartsWith('URGENT:', $payload['title']);
        $this->assertStringContainsString($urgent->ulid, $payload['url']);
    }

    public function test_one_escalation_per_call_and_agents_can_escalate_on_edit(): void
    {
        [$owner] = $this->business();
        $this->logCall($owner, 'resolved-by-agent')->assertOk();
        $call = CallLog::withoutGlobalScopes()->sole();
        $this->assertSame(0, Escalation::withoutGlobalScopes()->count());

        Sanctum::actingAs($this->agent);
        $this->putJson("/api/v1/agent/call-logs/{$call->id}", ['call_outcome' => 'escalated-to-client'])->assertOk();
        $this->putJson("/api/v1/agent/call-logs/{$call->id}", ['call_outcome' => 'resolved-by-agent'])->assertOk();
        $this->putJson("/api/v1/agent/call-logs/{$call->id}", ['call_outcome' => 'escalated-to-client'])->assertOk();

        $this->assertSame(1, Escalation::withoutGlobalScopes()->where('call_log_id', $call->id)->count());
    }

    public function test_team_acknowledges_assigns_and_resolves(): void
    {
        [$owner, $org] = $this->business();
        $staff = $this->member($org, 'staff');
        $this->logCall($owner)->assertOk();
        $escalation = Escalation::withoutGlobalScopes()->sole();

        $this->actingAs($owner)->get(route('app.escalations.index'))->assertOk()->assertSee($escalation->reason)->assertSee("I'm on it", false);
        $this->actingAs($owner)->get(route('app.dashboard'))->assertOk()->assertSee('1 escalation needs your attention');

        Livewire::test(Index::class)
            ->call('acknowledge', $escalation->ulid)
            ->call('assign', $escalation->ulid, (string) $staff->id);

        $escalation->refresh();
        $this->assertSame(EscalationStatus::Acknowledged, $escalation->status);
        $this->assertSame($owner->id, $escalation->acknowledged_by_user_id);
        $this->assertSame($staff->id, $escalation->assigned_to_user_id);
        Notification::assertSentTo($staff, EscalationActivity::class, fn (EscalationActivity $n) => $n->kind === EscalationActivity::ASSIGNED);

        // Staff resolve, with a note.
        $this->actingAs($staff);
        Livewire::test(Index::class)
            ->call('startResolve', $escalation->ulid)
            ->call('resolve')
            ->assertHasErrors('resolutionNotes')
            ->set('resolutionNotes', 'Sent a plumber, ceiling patched.')
            ->call('resolve')
            ->assertHasNoErrors()
            ->assertSet('resolving', null);

        $escalation->refresh();
        $this->assertSame(EscalationStatus::Resolved, $escalation->status);
        $this->assertSame($staff->id, $escalation->resolved_by_user_id);
        $this->assertSame('Sent a plumber, ceiling patched.', $escalation->resolution_notes);
        $this->assertDatabaseHas('audit_logs', ['action' => 'escalation.resolved', 'organization_id' => $org->id]);
        $this->assertDatabaseHas('customer_timeline_events', ['customer_id' => $escalation->customer_id, 'title' => 'Escalation resolved']);
        $this->assertSame(0, Livewire::test(Dashboard::class)->viewData('activeEscalations'));
        Livewire::test(Index::class)->set('view', 'resolved')->assertSee('Sent a plumber, ceiling patched.');
    }

    public function test_another_businesss_escalations_are_invisible(): void
    {
        [$owner] = $this->business();
        [$other] = $this->business();
        $this->logCall($other)->assertOk();
        $theirs = Escalation::withoutGlobalScopes()->sole();

        $this->actingAs($owner);
        Livewire::test(Index::class)->assertDontSee($theirs->reason);
        foreach (['acknowledge', 'startResolve'] as $method) {
            try {
                Livewire::test(Index::class)->call($method, $theirs->ulid);
                $this->fail("{$method} reached another business's escalation");
            } catch (ModelNotFoundException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame(EscalationStatus::Open, $theirs->fresh()->status);
    }

    public function test_unacknowledged_urgent_escalations_are_resent_once(): void
    {
        [$owner, $org] = $this->business();
        $urgent = Escalation::create(['organization_id' => $org->id, 'type' => 'emergency', 'priority' => 'urgent', 'reason' => 'Flood']);
        $acknowledged = Escalation::create(['organization_id' => $org->id, 'type' => 'emergency', 'priority' => 'urgent', 'reason' => 'Fire', 'status' => 'acknowledged']);
        Escalation::create(['organization_id' => $org->id, 'type' => 'complaint', 'priority' => 'high', 'reason' => 'Rude tech']);

        $sweep = app(EscalationReminderSweep::class);
        $this->assertSame(0, $sweep->run(), 'too early');

        Carbon::setTestNow(now()->addMinutes(EscalationReminderSweep::AFTER_MINUTES + 1));
        $this->assertSame(1, $sweep->run());
        $this->assertSame(0, $sweep->run(), 'only once');
        Notification::assertSentTo($owner, EscalationActivity::class, fn (EscalationActivity $n) => $n->kind === EscalationActivity::REMINDER && $n->escalation->is($urgent));
        $this->assertNull($acknowledged->fresh()->reminded_at);
    }

    public function test_escalations_api(): void
    {
        [$owner, $org] = $this->business();
        [$other] = $this->business();
        [$outsider] = $this->business();
        $this->logCall($owner)->assertOk();
        $this->logCall($other)->assertOk();
        $mine = Escalation::withoutGlobalScopes()->where('organization_id', $org->id)->sole();
        $theirs = Escalation::withoutGlobalScopes()->where('organization_id', '!=', $org->id)->sole();

        Sanctum::actingAs($owner);
        $this->getJson('/api/v1/client/escalations')->assertOk()
            ->assertJsonCount(1, 'data.escalations')
            ->assertJsonPath('data.escalations.0.id', $mine->ulid)
            ->assertJsonPath('data.escalations.0.priority', 'urgent');

        $this->postJson("/api/v1/client/escalations/{$mine->ulid}/acknowledge")->assertOk()->assertJsonPath('data.escalation.status', 'acknowledged');
        $this->postJson("/api/v1/client/escalations/{$mine->ulid}/assign", ['assigned_to' => $outsider->id])->assertStatus(422);
        $this->postJson("/api/v1/client/escalations/{$mine->ulid}/resolve", [])->assertStatus(422);
        $this->postJson("/api/v1/client/escalations/{$mine->ulid}/resolve", ['resolution_notes' => 'Handled by phone'])
            ->assertOk()->assertJsonPath('data.escalation.status', 'resolved');
        $this->getJson('/api/v1/client/escalations?status=resolved')->assertOk()->assertJsonCount(1, 'data.escalations');

        $this->getJson("/api/v1/client/escalations/{$theirs->ulid}")->assertNotFound();
        $this->postJson("/api/v1/client/escalations/{$theirs->ulid}/resolve", ['resolution_notes' => 'x'])->assertNotFound();
    }

    public function test_operations_see_every_open_escalation(): void
    {
        [$owner] = $this->business();
        [$other] = $this->business();
        $this->logCall($owner)->assertOk();
        $this->logCall($other)->assertOk();
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true, 'must_change_password' => false]);

        $page = $this->actingAs($admin)->get(route('admin.escalations'))->assertOk()->assertSee('2 urgent escalations not acknowledged');
        foreach (Escalation::withoutGlobalScopes()->with('organization')->get() as $escalation) {
            $page->assertSee($escalation->organization->name);
        }

        $this->actingAs($this->agent)->get(route('admin.escalations'))->assertForbidden();
    }

    public function test_escalation_permissions(): void
    {
        [, $org] = $this->business();
        $staff = $this->member($org, 'staff');

        $this->assertTrue($staff->hasPermissionIn('escalations.view', $org));
        $this->assertTrue($staff->hasPermissionIn('escalations.resolve', $org));
        $this->assertFalse($staff->hasPermissionIn('escalations.create', $org));
        $this->assertTrue($this->agent->hasPermissionIn('escalations.create', $org), 'assigned agents raise escalations');
        $this->assertFalse($this->agent->hasPermissionIn('escalations.resolve', $org));
    }
}
