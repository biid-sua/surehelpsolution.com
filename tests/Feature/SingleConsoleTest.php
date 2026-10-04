<?php

namespace Tests\Feature;

use App\Livewire\Admin\Enquiries\Index as Enquiries;
use App\Livewire\Admin\Schedules\Index as Schedules;
use App\Livewire\Admin\Users\Index as Users;
use App\Models\AgentDutySchedule;
use App\Models\CallLog;
use App\Models\ContactSubmission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * One UI: the screens that replaced the previous admin console and agent dashboard.
 */
class SingleConsoleTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-14 10:00:00');   // a Wednesday
        $this->admin = $this->user('admin');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function user(string $role, array $attributes = []): User
    {
        return User::factory()->create(array_merge(['role' => $role, 'is_active' => true, 'must_change_password' => false], $attributes));
    }

    // Users -------------------------------------------------------------------

    public function test_admin_adds_an_agent_with_a_generated_temporary_password(): void
    {
        $this->actingAs($this->admin)->get(route('admin.users'))->assertOk()->assertSee('Add user');

        $component = Livewire::test(Users::class)->call('startAdding')
            ->set('draft.role', 'agent')->assertSet('draft.platform_role', 'agent')
            ->set('draft.platform_role', 'agent_supervisor')
            ->set('draft.name', 'Rita Agent')->set('draft.email', 'Rita@Example.test')
            ->call('create')->assertHasNoErrors();

        $rita = User::where('email', 'rita@example.test')->sole();
        $password = $component->get('issued')['password'];
        $this->assertSame(12, strlen($password));
        $this->assertTrue(Hash::check($password, $rita->password));
        $this->assertTrue($rita->must_change_password);
        $this->assertTrue($rita->hasRole('agent_supervisor'));
        $component->assertSee($password);

        Livewire::test(Users::class)->call('startAdding')->set('draft.name', 'Dup')->set('draft.email', 'rita@example.test')
            ->call('create')->assertHasErrors('draft.email');
    }

    public function test_reset_password_and_switch_off(): void
    {
        $agent = $this->user('agent');
        $agent->createToken('phone');
        $this->actingAs($this->admin);

        $component = Livewire::test(Users::class)->call('resetPassword', $agent->id);
        $this->assertTrue(Hash::check($component->get('issued')['password'], $agent->fresh()->password));
        $this->assertTrue($agent->fresh()->must_change_password);
        $this->assertSame(0, $agent->tokens()->count());

        Livewire::test(Users::class)->call('toggleActive', $agent->id);
        $this->assertFalse($agent->fresh()->is_active);
        Livewire::test(Users::class)->call('toggleActive', $this->admin->id)->assertForbidden();
        $this->assertTrue($this->admin->fresh()->is_active);
    }

    public function test_only_a_super_admin_manages_staff(): void
    {
        $operations = $this->user('admin');
        $operations->syncRoles(['operations_manager']);
        $client = $this->user('client');

        $this->actingAs($operations);
        Livewire::test(Users::class)->call('startAdding')->set('draft.role', 'admin')->set('draft.name', 'X')
            ->set('draft.email', 'x@example.test')->set('draft.platform_role', 'super_admin')->call('create')->assertForbidden();
        Livewire::test(Users::class)->call('resetPassword', $this->admin->id)->assertForbidden();
        Livewire::test(Users::class)->call('toggleActive', $client->id)->assertOk();

        $this->actingAs($this->admin);
        Livewire::test(Users::class)->call('changeRole', $operations->id, 'support_agent');
        $this->assertTrue($operations->fresh()->hasRole('support_agent'));
        Livewire::test(Users::class)->call('changeRole', $operations->id, 'agent')->assertStatus(422);
    }

    public function test_users_list_filters(): void
    {
        $this->user('client', ['name' => 'Owner Olive']);
        $this->user('agent', ['name' => 'Agent Ann', 'is_active' => false]);
        $this->actingAs($this->admin);

        Livewire::test(Users::class)->set('type', 'client')->assertSee('Owner Olive')->assertDontSee('Agent Ann');
        Livewire::test(Users::class)->set('status', 'off')->assertSee('Agent Ann')->assertDontSee('Owner Olive');
        Livewire::test(Users::class)->set('search', 'olive')->assertSee('Owner Olive')->assertDontSee('Agent Ann');
    }

    // Duty schedule -------------------------------------------------------------

    public function test_shifts_are_planned_in_a_week_grid_without_overlaps(): void
    {
        $agent = $this->user('agent', ['name' => 'Night Nick']);
        $this->actingAs($this->admin)->get(route('admin.schedule'))->assertOk()->assertSee('Night Nick');

        // Overnight: ends the next morning.
        Livewire::test(Schedules::class)->call('add', $agent->id, '2026-10-14')
            ->set('shift.shift_type', 'night')->set('shift.start', '22:00')->set('shift.end', '06:00')
            ->call('save')->assertHasNoErrors();
        $shift = AgentDutySchedule::sole();
        $this->assertSame('2026-10-15 06:00:00', $shift->end_datetime->toDateTimeString());
        $this->assertSame('Night', $shift->title);

        Livewire::test(Schedules::class)->call('add', $agent->id, '2026-10-15')
            ->set('shift.start', '05:00')->set('shift.end', '09:00')->call('save')->assertHasErrors('shift.start');
        $this->assertSame(1, AgentDutySchedule::count());

        Livewire::test(Schedules::class)->call('edit', $shift->id)->set('shift.end', '23:30')->call('save')->assertHasNoErrors();
        $this->assertSame('2026-10-14 23:30:00', $shift->fresh()->end_datetime->toDateTimeString());

        // Next week starts empty; copying repeats this week's shifts once.
        Livewire::test(Schedules::class)->call('nextWeek')->assertSet('week', '2026-10-19')->call('copyPreviousWeek');
        Livewire::test(Schedules::class)->set('week', '2026-10-19')->call('copyPreviousWeek');
        $this->assertSame(2, AgentDutySchedule::count());
        $this->assertSame('2026-10-21 22:00:00', AgentDutySchedule::latest('id')->first()->start_datetime->toDateTimeString());

        Livewire::test(Schedules::class)->call('edit', $shift->id)->call('delete');
        $this->assertSame(1, AgentDutySchedule::count());
    }

    public function test_agents_see_only_their_own_schedule(): void
    {
        $agent = $this->user('agent');
        $other = $this->user('agent');
        AgentDutySchedule::create(['agent_id' => $agent->id, 'title' => 'Morning desk', 'start_datetime' => '2026-10-14 08:00', 'end_datetime' => '2026-10-14 16:00', 'shift_type' => 'morning', 'is_active' => true]);
        AgentDutySchedule::create(['agent_id' => $other->id, 'title' => 'Someone else', 'start_datetime' => '2026-10-15 08:00', 'end_datetime' => '2026-10-15 16:00', 'shift_type' => 'morning', 'is_active' => true]);

        $this->actingAs($agent)->get(route('agent.schedule'))->assertOk()
            ->assertSee('On shift: Morning desk')->assertSee('8 h')->assertDontSee('Someone else');
        $this->get(route('admin.schedule'))->assertForbidden();
        $this->get(route('admin.users'))->assertForbidden();
    }

    // Website enquiries ---------------------------------------------------------

    public function test_enquiries_are_read_and_marked_handled(): void
    {
        $enquiry = ContactSubmission::create(['name' => 'Dana Lead', 'email' => 'dana@example.test', 'inquiry_type' => 'demo', 'message' => 'Please show me a demo next week.']);
        ContactSubmission::create(['name' => 'Old Lead', 'email' => 'old@example.test', 'inquiry_type' => 'sales', 'message' => 'Already answered this one.'])
            ->forceFill(['handled_at' => now()])->save();

        $this->actingAs($this->admin)->get(route('admin.enquiries'))->assertOk()->assertSee('Dana Lead')->assertDontSee('Old Lead')->assertSee('Open (1)');

        Livewire::test(Enquiries::class)->call('select', $enquiry->id)->assertSee('Please show me a demo')->assertSee('Demo request')
            ->call('toggleHandled');
        $this->assertSame($this->admin->id, $enquiry->fresh()->handled_by);
        Livewire::test(Enquiries::class)->assertSee('All caught up');

        $support = $this->user('admin');
        $support->syncRoles(['support_agent']);
        $this->actingAs($support)->get(route('admin.enquiries'))->assertForbidden();
    }

    // Agent: my calls --------------------------------------------------------------

    public function test_agents_see_and_export_their_own_calls(): void
    {
        $agent = $this->user('agent');
        $other = $this->user('agent');
        $base = ['call_date' => '2026-10-14', 'call_time' => '09:00', 'reason_for_call' => 'general-inquiry', 'call_outcome' => 'resolved-by-agent', 'agent_name' => 'A', 'status' => 'new'];
        CallLog::create($base + ['call_id' => 'CL-MINE-1', 'caller_name' => 'Mine Caller', 'user_id' => $agent->id]);
        CallLog::create($base + ['call_id' => 'CL-THEIRS-1', 'caller_name' => 'Their Caller', 'user_id' => $other->id]);

        $this->actingAs($agent)->get(route('agent.calls'))->assertOk()->assertSee('Mine Caller')->assertDontSee('Their Caller')
            ->assertSee('My schedule');
        $csv = $this->get(route('agent.calls.export'))->assertOk()->streamedContent();
        $this->assertStringContainsString('CL-MINE-1', $csv);
        $this->assertStringNotContainsString('CL-THEIRS-1', $csv);
    }

    // First sign-in -------------------------------------------------------------------

    public function test_first_sign_in_password_page_uses_the_new_design(): void
    {
        $owner = $this->user('client', ['must_change_password' => true, 'password' => 'TempPass123']);

        $this->actingAs($owner)->get(route('app.dashboard'))->assertRedirect(route('password.change'));
        $this->get(route('password.change'))->assertOk()->assertSee('Choose your password')->assertDontSee('bootstrap');
        $this->post(route('password.change.update'), ['current_password' => 'wrong', 'password' => 'NewSecret123', 'password_confirmation' => 'NewSecret123'])
            ->assertSessionHasErrors('current_password');
        $this->post(route('password.change.update'), ['current_password' => 'TempPass123', 'password' => 'NewSecret123', 'password_confirmation' => 'NewSecret123'])
            ->assertRedirect(route('app.dashboard'));
        $this->assertFalse($owner->fresh()->must_change_password);
    }
}
