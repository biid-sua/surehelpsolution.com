<?php

namespace Tests\Feature;

use App\Actions\Assignments\AssignAgent;
use App\Actions\Assignments\ChangeAssignment;
use App\Actions\Organizations\ProvisionUserTenancy;
use App\Enums\AgentAssignmentSource;
use App\Events\AgentAssignmentStarted;
use App\Livewire\Agent\Assignments;
use App\Models\AgentAssignment;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\AssignmentActivity;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Brief §2.1–2.4, §9–10 (D40–D41): the assignment lifecycle, history, who may assign where,
 * checks, notifications, audit and the sweep.
 */
class AgentAssignmentsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->admin = $this->user('admin', 'super_admin');
    }

    private function user(string $portal, string $role, array $extra = []): User
    {
        $user = User::factory()->create(array_merge(['role' => $portal, 'is_active' => true, 'must_change_password' => false], $extra));
        $user->syncRoles([$role]);

        return $user;
    }

    private function company(string $name = 'ABC Plumbing'): Organization
    {
        $owner = User::factory()->create(['role' => 'client', 'is_active' => true, 'must_change_password' => false]);
        $org = app(ProvisionUserTenancy::class)->handle($owner);
        $org->update(['name' => $name]);

        return $org->fresh();
    }

    public function test_assigning_records_who_when_and_tells_the_agent(): void
    {
        Event::fake([AgentAssignmentStarted::class]);
        $org = $this->company();
        $agent = $this->user('agent', 'agent');

        $assignment = app(AssignAgent::class)->handle($org, $agent, $this->admin, ['notes' => 'Covering mornings']);

        $this->assertSame('active', $assignment->status);
        $this->assertSame($this->admin->id, $assignment->assigned_by_user_id);
        $this->assertTrue($agent->fresh()->isAssignedTo($org));
        Notification::assertSentTo($agent, AssignmentActivity::class, fn ($n) => $n->change === AssignmentActivity::STARTED);
        Event::assertDispatched(AgentAssignmentStarted::class);
        $this->assertDatabaseHas('audit_logs', ['action' => 'agent.assigned', 'organization_id' => $org->id, 'actor_id' => $this->admin->id]);

        // No double assignment.
        $this->expectException(ValidationException::class);
        app(AssignAgent::class)->handle($org, $agent, $this->admin);
    }

    public function test_history_is_kept_and_a_later_reassignment_is_a_new_row(): void
    {
        $org = $this->company();
        $agent = $this->user('agent', 'agent');
        $first = app(AssignAgent::class)->handle($org, $agent, $this->admin);

        app(ChangeAssignment::class)->end($first, $this->admin, 'Account reassigned');
        $first->refresh();
        $this->assertSame('ended', $first->status);
        $this->assertSame('Account reassigned', $first->end_reason);
        $this->assertSame($this->admin->id, $first->ended_by_user_id);
        $this->assertFalse($agent->fresh()->isAssignedTo($org));
        Notification::assertSentTo($agent, AssignmentActivity::class, fn ($n) => $n->change === AssignmentActivity::ENDED);

        $second = app(AssignAgent::class)->handle($org, $agent, $this->admin);
        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(2, AgentAssignment::where('agent_user_id', $agent->id)->count(), 'history is never deleted');
        $this->assertDatabaseHas('audit_logs', ['action' => 'agent.unassigned', 'organization_id' => $org->id]);
    }

    public function test_revoking_needs_a_reason_and_reassigning_moves_the_company(): void
    {
        $org = $this->company();
        $maria = $this->user('agent', 'agent', ['name' => 'Maria']);
        $tom = $this->user('agent', 'agent', ['name' => 'Tom']);
        $assignment = app(AssignAgent::class)->handle($org, $maria, $this->admin);

        try {
            app(ChangeAssignment::class)->revoke($assignment, $this->admin, '  ');
            $this->fail('A revocation needs a reason');
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        }

        $new = app(AssignAgent::class)->reassign($assignment->fresh(), $tom, $this->admin, '');
        $this->assertSame('ended', $assignment->fresh()->status);
        $this->assertSame('Reassigned to Tom', $assignment->fresh()->end_reason);
        $this->assertTrue($tom->fresh()->isAssignedTo($org));
        $this->assertFalse($maria->fresh()->isAssignedTo($org));
        $this->assertSame($tom->id, $new->agent_user_id);
    }

    public function test_checks_refuse_inactive_agents_and_companies(): void
    {
        $org = $this->company();
        $off = $this->user('agent', 'agent', ['is_active' => false]);
        $client = User::factory()->create(['role' => 'client']);

        foreach ([$off, $client] as $who) {
            try {
                app(AssignAgent::class)->handle($org, $who, $this->admin);
                $this->fail('should be refused');
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }

        $org->update(['status' => 'cancelled']);
        $this->expectException(ValidationException::class);
        app(AssignAgent::class)->handle($org->fresh(), $this->user('agent', 'agent'), $this->admin);
    }

    public function test_supervisors_manage_only_their_own_companies_and_owners_never(): void
    {
        $mine = $this->company('ABC Plumbing');
        $other = $this->company('XYZ Cleaning');
        $supervisor = $this->user('agent', 'agent_supervisor');
        app(AssignAgent::class)->handle($mine, $supervisor, $this->admin);
        $agent = $this->user('agent', 'agent');

        app(AssignAgent::class)->handle($mine, $agent, $supervisor);
        $this->assertTrue($agent->fresh()->isAssignedTo($mine));

        try {
            app(AssignAgent::class)->handle($other, $agent, $supervisor);
            $this->fail('A supervisor can\'t assign agents to a company they don\'t serve');
        } catch (AuthorizationException) {
            $this->addToAssertionCount(1);
        }

        // The screen only offers their companies and refuses another one.
        $this->actingAs($supervisor)->get(route('agent.assignments'))->assertOk()->assertSee('ABC Plumbing')->assertDontSee('XYZ Cleaning');
        Livewire::test(Assignments::class)->set('creating', true)->set('form.agent', (string) $agent->id)->set('form.company', $other->ulid)
            ->call('assign')->assertHasErrors('form.company');

        // A business owner can never assign agents, even to their own company.
        $owner = $mine->owner;
        $this->assertFalse($owner->hasPermissionIn('agent_assignments.create', $mine));
        $this->expectException(AuthorizationException::class);
        app(AssignAgent::class)->handle($mine, $this->user('agent', 'agent'), $owner);
    }

    public function test_the_assignment_screen_previews_checks_then_assigns(): void
    {
        $org = $this->company();
        $agent = $this->user('agent', 'agent', ['name' => 'John Smith']);

        $this->actingAs($this->admin);
        Livewire::test(Assignments::class)
            ->call('startCreate')
            ->set('form.agent', (string) $agent->id)
            ->set('form.company', $org->ulid)
            ->assertSee('Before you confirm')
            ->assertSee('Training ready')
            ->set('form.ends_on', now()->addMonth()->toDateString())
            ->call('assign')
            ->assertHasNoErrors();

        $assignment = AgentAssignment::sole();
        $this->assertNotNull($assignment->ends_at);
        $this->actingAs($this->admin)->get(route('agent.team.show', $agent->id))->assertOk()->assertSee('ABC Plumbing');
    }

    public function test_scheduled_assignments_start_and_expired_ones_end_with_the_sweep(): void
    {
        $org = $this->company();
        $agent = $this->user('agent', 'agent');
        $later = app(AssignAgent::class)->handle($org, $agent, $this->admin, [
            'starts_at' => CarbonImmutable::now()->addDay(), 'ends_at' => CarbonImmutable::now()->addDays(5),
        ]);
        $this->assertSame('scheduled', $later->status);
        Notification::assertSentTo($agent, AssignmentActivity::class, fn ($n) => $n->change === AssignmentActivity::SCHEDULED);

        $this->travel(2)->days();
        $this->artisan('assignments:sweep')->assertSuccessful();
        $this->assertSame('active', $later->fresh()->status);
        Notification::assertSentTo($agent, AssignmentActivity::class, fn ($n) => $n->change === AssignmentActivity::STARTED);

        $this->travel(5)->days();
        $this->artisan('assignments:sweep')->assertSuccessful();
        $this->assertSame('ended', $later->fresh()->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'agent.assignment_expired']);
        $this->assertSame(1, AuditLog::where('action', 'agent.assignment_started')->count(), 'the sweep acts once');
    }

    public function test_automatic_assignment_is_off_and_old_automatic_ones_are_flagged_for_review(): void
    {
        $this->assertFalse(config('tenancy.auto_assign_agents'));
        $this->user('agent', 'agent');
        $org = $this->company();
        $this->assertSame(0, AgentAssignment::where('organization_id', $org->id)->count(), 'new companies get no agents by themselves');

        $agent = $this->user('agent', 'agent');
        $org->assignAgent($agent, AgentAssignmentSource::Automatic);
        $auto = AgentAssignment::sole();
        $this->assertTrue($auto->needsReview());

        $this->actingAs($this->admin);
        Livewire::test(Assignments::class)->assertSee('to review')->call('confirmReview', $auto->id);
        $this->assertFalse($auto->fresh()->needsReview());
        $this->assertSame($this->admin->id, $auto->fresh()->assigned_by_user_id);
    }
}
