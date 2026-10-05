<?php

namespace Tests\Feature;

use App\Livewire\Admin\Schedules\Index as AdminSchedule;
use App\Livewire\Agent\Schedule as AgentSchedule;
use App\Models\AgentDutySchedule;
use App\Models\ShiftRequest;
use App\Models\User;
use App\Notifications\ShiftRequestActivity;
use App\Services\Scheduling\ShiftRequests;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Shift swap and leave requests (AGT-11) and the coverage view (SUP-03).
 */
class ShiftRequestsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Carbon::setTestNow(CarbonImmutable::parse('2026-10-12 08:00'));   // a Monday
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function agent(string $name): User
    {
        $user = User::factory()->create(['role' => 'agent', 'is_active' => true, 'must_change_password' => false, 'name' => $name]);
        $this->enableTwoFactor($user);

        return $user;
    }

    private function admin(string $role = 'super_admin'): User
    {
        $user = User::factory()->create(['role' => 'admin', 'is_active' => true, 'name' => 'Olga Ops']);
        if ($role !== 'super_admin') {
            $user->syncRoles([$role]);
        }
        $this->enableTwoFactor($user);

        return $user;
    }

    private function shift(User $agent, string $start, string $end, string $type = 'morning'): AgentDutySchedule
    {
        return AgentDutySchedule::create(['agent_id' => $agent->id, 'title' => ucfirst($type), 'shift_type' => $type, 'is_active' => true,
            'start_datetime' => CarbonImmutable::parse($start), 'end_datetime' => CarbonImmutable::parse($end)]);
    }

    public function test_an_agent_hands_over_a_shift_and_the_scheduler_approves_it(): void
    {
        $admin = $this->admin();
        $ana = $this->agent('Ana Agent');
        $ben = $this->agent('Ben Agent');
        $tuesday = $this->shift($ana, '2026-10-13 09:00', '2026-10-13 17:00');
        $this->shift($ben, '2026-10-14 09:00', '2026-10-14 17:00');

        $this->actingAs($ana)->get(route('agent.schedule'))->assertOk()->assertSee('Hand over a shift')->assertSee('Ask for time off');
        Livewire::test(AgentSchedule::class)->call('ask', 'swap', $tuesday->id)->assertSet('request.shift_id', (string) $tuesday->id)
            ->set('request.reason', 'Doctor\'s appointment')->call('submit')->assertHasNoErrors()->assertSee('Hand-over asked')->assertSee('Waiting');
        // One request per shift.
        Livewire::test(AgentSchedule::class)->call('ask', 'swap', $tuesday->id)->call('submit')->assertHasErrors('request.shift_id');

        $request = ShiftRequest::sole();
        Notification::assertSentTo($admin, ShiftRequestActivity::class, fn (ShiftRequestActivity $n) => $n->kind === 'submitted');

        $this->actingAs($admin)->get(route('admin.schedule', ['week' => '2026-10-12']))->assertOk()->assertSee('Requests from agents')->assertSee('Doctor');
        Livewire::test(AdminSchedule::class)->call('approve', $request->ulid)->assertHasErrors("decision.{$request->ulid}.colleague")
            ->set("decision.{$request->ulid}.colleague", (string) $ben->id)->call('approve', $request->ulid)->assertHasNoErrors();

        $this->assertSame($ben->id, $tuesday->fresh()->agent_id);
        $this->assertSame(ShiftRequest::APPROVED, $request->fresh()->status);
        $this->assertSame($ben->id, $request->fresh()->swap_with_id);
        Notification::assertSentTo($ana, ShiftRequestActivity::class, fn (ShiftRequestActivity $n) => $n->kind === 'approved');
    }

    public function test_a_colleague_who_already_works_then_cannot_take_the_shift_and_declines_need_a_reason(): void
    {
        $admin = $this->admin();
        $ana = $this->agent('Ana Agent');
        $ben = $this->agent('Ben Agent');
        $shift = $this->shift($ana, '2026-10-13 09:00', '2026-10-13 17:00');
        $this->shift($ben, '2026-10-13 12:00', '2026-10-13 20:00');
        $request = app(ShiftRequests::class)->requestSwap($ana, $shift->id, $ben->id, null);

        $this->actingAs($admin);
        Livewire::test(AdminSchedule::class)->call('approve', $request->ulid)->assertHasErrors("decision.{$request->ulid}.colleague")
            ->call('decline', $request->ulid)->assertHasErrors("decision.{$request->ulid}.note")
            ->set("decision.{$request->ulid}.note", 'Nobody free on Tuesday')->call('decline', $request->ulid)->assertHasNoErrors();
        $this->assertSame($ana->id, $shift->fresh()->agent_id);

        $this->actingAs($ana)->get(route('agent.schedule'))->assertSee('Declined')->assertSee('Nobody free on Tuesday');

        // Agents can't decide; and only their own upcoming shifts can be offered.
        $other = $this->shift($ben, '2026-10-15 09:00', '2026-10-15 17:00');
        Livewire::test(AgentSchedule::class)->set('request.type', 'swap')->set('request.shift_id', (string) $other->id)->call('submit')->assertHasErrors('request.shift_id');
        $this->expectException(HttpException::class);
        app(ShiftRequests::class)->approve(app(ShiftRequests::class)->requestLeave($ana, '2026-10-20', '2026-10-20', null), $ana);
    }

    public function test_approved_leave_clears_the_agents_shifts_and_shows_the_gap_in_coverage(): void
    {
        $admin = $this->admin();
        $ana = $this->agent('Ana Agent');
        $wed = $this->shift($ana, '2026-10-14 09:00', '2026-10-14 17:00');
        $thu = $this->shift($ana, '2026-10-15 22:00', '2026-10-16 06:00', 'night');
        $keep = $this->shift($ana, '2026-10-17 09:00', '2026-10-17 17:00');

        $this->actingAs($ana);
        Livewire::test(AgentSchedule::class)->call('ask', 'leave')->set('request.leave_from', '2026-10-15')->set('request.leave_until', '2026-10-14')
            ->call('submit')->assertHasErrors('request.leave_until')
            ->set('request.leave_from', '2026-10-14')->set('request.leave_until', '2026-10-15')->call('submit')->assertHasNoErrors();
        $request = ShiftRequest::sole();
        $this->assertSame('Time off Wed Oct 14 – Thu Oct 15', $request->summary());

        $coverage = app(ShiftRequests::class)->coverage(CarbonImmutable::parse('2026-10-12'));
        $this->assertSame(1, $coverage['2026-10-14'][9]);

        $this->actingAs($admin);
        Livewire::test(AdminSchedule::class)->call('approve', $request->ulid)->assertHasNoErrors();

        $this->assertFalse($wed->fresh()->is_active);
        $this->assertFalse($thu->fresh()->is_active);
        $this->assertTrue($keep->fresh()->is_active);
        $this->assertSame(2, AgentDutySchedule::active()->where('title', 'Leave')->where('shift_type', 'off')->count());

        $coverage = app(ShiftRequests::class)->coverage(CarbonImmutable::parse('2026-10-12'));
        $this->assertSame(0, $coverage['2026-10-14'][9]);
        $this->assertSame(1, $coverage['2026-10-17'][16]);
        $this->assertSame(0, $coverage['2026-10-17'][17]);
        $this->get(route('admin.schedule', ['week' => '2026-10-12']))->assertSee('Coverage')->assertSee('Nobody on shift');

        // Support staff can see the schedule but not decide.
        $support = $this->admin('support_agent');
        $pending = app(ShiftRequests::class)->requestLeave($ana, '2026-10-20', '2026-10-20', null);
        $this->actingAs($support);
        Livewire::test(AdminSchedule::class)->assertDontSee('Approve')->call('approve', $pending->ulid)->assertForbidden();
    }
}
