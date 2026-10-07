<?php

namespace Tests\Feature;

use App\Actions\Assignments\AssignAgent;
use App\Actions\Organizations\ProvisionUserTenancy;
use App\Enums\AppointmentStatus;
use App\Livewire\Agent\Calls;
use App\Livewire\Agent\Company\Appointments;
use App\Livewire\Agent\Company\CustomerShow;
use App\Models\Appointment;
use App\Models\BusinessHour;
use App\Models\CallLog;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * AGT-14: agents move and cancel appointments, edit customers and add notes to their own recent
 * calls on the web, only inside companies they serve and under the business's booking rules.
 */
class AgentRecordEditingTest extends TestCase
{
    use RefreshDatabase;

    private User $agent;

    private Organization $mine;

    private Organization $other;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->travelTo(CarbonImmutable::parse('2026-10-05 08:00', 'UTC')); // a Monday

        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true, 'must_change_password' => false]);
        $admin->syncRoles(['super_admin']);
        $this->agent = User::factory()->create(['role' => 'agent', 'is_active' => true, 'must_change_password' => false]);
        $this->agent->syncRoles(['agent']);
        $this->mine = $this->company('ABC Plumbing');
        $this->other = $this->company('XYZ Cleaning');
        app(AssignAgent::class)->handle($this->mine, $this->agent, $admin);
    }

    private function company(string $name): Organization
    {
        $owner = User::factory()->create(['role' => 'client', 'is_active' => true, 'must_change_password' => false]);
        $org = app(ProvisionUserTenancy::class)->handle($owner);
        $org->forceFill(['name' => $name, 'timezone' => 'UTC'])->save();
        foreach (range(0, 6) as $day) {
            BusinessHour::create(['organization_id' => $org->id, 'day_of_week' => $day, 'opens_at' => '09:00', 'closes_at' => '17:00']);
        }

        return $org->fresh();
    }

    private function appointment(Organization $org, string $start = '2026-10-06 10:00'): Appointment
    {
        $starts = CarbonImmutable::parse($start, 'UTC');

        return Appointment::create(['organization_id' => $org->id, 'title' => 'Visit', 'status' => AppointmentStatus::Confirmed,
            'starts_at' => $starts, 'ends_at' => $starts->addHour(), 'blocked_until' => $starts->addHour(), 'timezone' => 'UTC']);
    }

    public function test_an_agent_moves_an_appointment_to_a_free_time_within_the_rules(): void
    {
        $appointment = $this->appointment($this->mine);
        $this->appointment($this->mine, '2026-10-07 14:00'); // taken

        $page = Livewire::actingAs($this->agent)->test(Appointments::class, ['organization' => $this->mine])
            ->call('startMove', $appointment->id)
            ->set('moveDate', '2026-10-07')
            ->assertViewHas('slots', function (array $slots) {
                $times = array_map(fn ($s) => $s->format('H:i'), $slots);

                return in_array('13:00', $times, true) && ! in_array('14:00', $times, true) && ! in_array('13:30', $times, true);
            });

        // Outside opening hours: refused for agents.
        $page->set('moveTime', '18:00')->call('move')->assertHasErrors('moveTime');
        $this->assertSame('2026-10-06 10:00', $appointment->fresh()->starts_at->format('Y-m-d H:i'));

        $page->set('moveTime', '13:00')->call('move')->assertHasNoErrors();
        $this->assertSame('2026-10-07 13:00', $appointment->fresh()->starts_at->format('Y-m-d H:i'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'appointment.rescheduled', 'actor_id' => $this->agent->id]);
    }

    public function test_an_agent_cancels_with_a_reason(): void
    {
        $appointment = $this->appointment($this->mine);

        Livewire::actingAs($this->agent)->test(Appointments::class, ['organization' => $this->mine])
            ->call('startCancel', $appointment->id)
            ->call('cancel')->assertHasErrors('cancelReason')
            ->set('cancelReason', 'Customer called to cancel')->call('cancel')->assertHasNoErrors();

        $appointment->refresh();
        $this->assertSame(AppointmentStatus::Cancelled, $appointment->status);
        $this->assertSame('Customer called to cancel', $appointment->cancellation_reason);
    }

    public function test_another_companys_appointment_cant_be_touched(): void
    {
        $theirs = $this->appointment($this->other);
        $page = Livewire::actingAs($this->agent)->test(Appointments::class, ['organization' => $this->mine]);

        foreach (['startMove', 'startCancel'] as $method) {
            try {
                $page->call($method, $theirs->id);
                $this->fail("{$method} reached another company's appointment.");
            } catch (ModelNotFoundException) {
                // expected: looked up inside the agent's company only
            }
        }
        $this->assertSame(AppointmentStatus::Confirmed, $theirs->fresh()->status);
    }

    public function test_an_agent_edits_a_customer_and_adds_a_note(): void
    {
        $customer = Customer::create(['organization_id' => $this->mine->id, 'first_name' => 'Ana', 'phone' => '(512) 555-0101']);

        Livewire::actingAs($this->agent)->test(CustomerShow::class, ['organization' => $this->mine, 'customer' => $customer->ulid])
            ->call('edit')
            ->set('form.last_name', 'Lopez')->set('form.phone', 'not a phone')
            ->call('save')->assertHasErrors('form.phone')
            ->set('form.phone', '(512) 555-0199')->call('save')->assertHasNoErrors()
            ->set('note', 'Prefers mornings')->call('addNote')->assertHasNoErrors();

        $customer->refresh();
        $this->assertSame('Lopez', $customer->last_name);
        $this->assertSame('+15125550199', $customer->phone_e164);
        $this->assertDatabaseHas('customer_timeline_events', ['customer_id' => $customer->id, 'body' => 'Prefers mornings']);

        $theirs = Customer::create(['organization_id' => $this->other->id, 'first_name' => 'Zed']);
        $this->actingAs($this->agent)->get(route('agent.businesses.customers.show', [$this->mine->ulid, $theirs->ulid]))->assertNotFound();
    }

    public function test_an_agent_adds_notes_to_their_own_recent_calls_only(): void
    {
        $call = fn (array $extra = []) => CallLog::withoutGlobalScopes()->create($extra + [
            'call_id' => CallLog::generateCallId(), 'organization_id' => $this->mine->id, 'call_date' => now()->toDateString(), 'call_time' => '10:00',
            'reason_for_call' => 'general-inquiry', 'call_outcome' => 'resolved-by-agent', 'agent_name' => 'Agent', 'status' => 'new',
            'user_id' => $this->agent->id, 'caller_name' => 'Caller', 'notes' => 'Original',
        ]);
        $recent = $call();
        $old = $call();
        $old->forceFill(['created_at' => now()->subDays(2)])->save();
        $someoneElses = $call(['user_id' => User::factory()->create()->id]);

        $page = Livewire::actingAs($this->agent)->test(Calls::class)
            ->call('startNote', $recent->id)->set('note', 'Called back, all sorted')->call('saveNote')->assertHasNoErrors();
        $this->assertStringStartsWith('Original', $recent->fresh()->notes);
        $this->assertStringContainsString('Called back, all sorted', $recent->fresh()->notes);

        $page->set('noting', $old->id)->set('note', 'Late')->call('saveNote')->assertHasErrors('note');
        $this->assertSame('Original', $old->fresh()->notes);

        $this->expectException(ModelNotFoundException::class);
        $page->call('startNote', $someoneElses->id);
    }
}
