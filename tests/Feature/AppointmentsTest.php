<?php

namespace Tests\Feature;

use App\Actions\Appointments\BookAppointment;
use App\Actions\Appointments\ChangeAppointmentStatus;
use App\Actions\Organizations\ProvisionUserTenancy;
use App\Enums\AppointmentStatus;
use App\Enums\CustomerStatus;
use App\Enums\NotificationEvent;
use App\Enums\TimelineEventType;
use App\Exceptions\SlotUnavailable;
use App\Livewire\Client\Appointments\Index;
use App\Livewire\Client\Dashboard;
use App\Models\Appointment;
use App\Models\BusinessHour;
use App\Models\BusinessLocation;
use App\Models\BusinessService;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\AppointmentActivity;
use App\Services\Scheduling\Availability;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * P2-5 — appointments (spec §16), availability (§19) and double-booking prevention (§88).
 */
class AppointmentsTest extends TestCase
{
    use RefreshDatabase;

    private const TZ = 'America/Chicago';

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        // Monday 5 Oct 2026, 8:00 AM in Chicago.
        Carbon::setTestNow(CarbonImmutable::parse('2026-10-05 08:00', self::TZ));
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
        $organization = app(ProvisionUserTenancy::class)->handle($owner);
        $organization->update(['timezone' => self::TZ]);
        foreach ([1, 2, 3, 4, 5] as $day) {
            BusinessHour::create(['organization_id' => $organization->id, 'day_of_week' => $day, 'opens_at' => '09:00', 'closes_at' => '17:00']);
        }

        return [$owner, $organization->fresh()];
    }

    private function member(Organization $organization, string $role): User
    {
        $user = User::factory()->create(['role' => 'client', 'is_active' => true, 'must_change_password' => false]);
        $organization->members()->attach($user->id, ['role' => $role, 'status' => 'active']);

        return $user;
    }

    private function service(Organization $organization, int $duration = 60, int $buffer = 0, array $extra = []): BusinessService
    {
        return BusinessService::create(array_merge([
            'organization_id' => $organization->id, 'name' => 'Water heater repair', 'duration_minutes' => $duration,
            'buffer_minutes' => $buffer, 'price_type' => 'quote_required', 'is_active' => true, 'is_bookable' => true,
        ], $extra));
    }

    private function at(string $local): CarbonImmutable
    {
        return CarbonImmutable::parse($local, self::TZ);
    }

    /** @param  array<string, mixed>  $data */
    private function book(Organization $organization, string $local, array $data = []): Appointment
    {
        return app(BookAppointment::class)->handle($organization, ['starts_at' => $this->at($local)] + $data);
    }

    /** @param  list<CarbonImmutable>  $slots */
    private function times(array $slots): array
    {
        return array_map(fn (CarbonImmutable $s) => $s->format('H:i'), $slots);
    }

    public function test_availability_follows_hours_bookings_buffers_and_notice(): void
    {
        [, $org] = $this->business();
        $service = $this->service($org, 60, 15);
        $this->book($org, '2026-10-06 10:00', ['service_id' => $service->id]);

        $slots = $this->times(app(Availability::class)->slots($org, '2026-10-06', 60, 15));
        // 9:00 would run its cleanup into 10:00; the 10:00 job blocks until 11:15.
        $this->assertSame(['11:30', '12:00', '12:30', '13:00', '13:30', '14:00', '14:30', '15:00', '15:30', '16:00'], $slots);

        // Closed on Sunday.
        $this->assertSame([], app(Availability::class)->slots($org, '2026-10-11', 60));

        // Minimum notice: at 10:10 nothing before 11:10 is offered.
        Carbon::setTestNow($this->at('2026-10-07 10:10'));
        $this->assertSame('11:30', $this->times(app(Availability::class)->slots($org, '2026-10-07', 60))[0]);
    }

    public function test_times_are_stored_in_utc_whatever_timezone_they_carry(): void
    {
        [, $org] = $this->business();
        $appointment = Appointment::create(['organization_id' => $org->id, 'title' => 'X', 'timezone' => self::TZ,
            'starts_at' => $this->at('2026-10-06 09:00'), 'ends_at' => $this->at('2026-10-06 10:00'), 'blocked_until' => $this->at('2026-10-06 10:00')]);

        $this->assertSame('2026-10-06 14:00:00', DB::table('appointments')->where('id', $appointment->id)->value('starts_at'), '9 AM CDT is 14:00 UTC');
        $this->assertSame('09:00', $appointment->fresh()->localStart()->format('H:i'));
    }

    public function test_availability_is_right_on_daylight_saving_days(): void
    {
        [, $org] = $this->business();
        // Chicago falls back on Sunday 1 Nov 2026; open that day 9–17 like any other.
        BusinessHour::create(['organization_id' => $org->id, 'day_of_week' => 0, 'opens_at' => '09:00', 'closes_at' => '17:00']);

        $slots = app(Availability::class)->slots($org, '2026-11-01', 60);
        $this->assertSame('09:00', $slots[0]->format('H:i'));
        $this->assertSame('16:00', end($slots)->format('H:i'));
        $this->assertCount(15, $slots);
        $this->assertSame('2026-11-01T15:00:00+00:00', $slots[0]->utc()->toIso8601String(), '9 AM CST is 15:00 UTC after the change');
    }

    public function test_double_booking_is_refused_with_suggestions(): void
    {
        [, $org] = $this->business();
        $service = $this->service($org, 60, 30);
        $this->book($org, '2026-10-06 14:00', ['service_id' => $service->id]);

        foreach (['2026-10-06 14:00', '2026-10-06 14:30', '2026-10-06 13:30', '2026-10-06 15:15'] as $clash) {
            try {
                $this->book($org, $clash, ['service_id' => $service->id]);
                $this->fail("{$clash} should clash");
            } catch (SlotUnavailable $e) {
                $this->assertStringContainsString('Water heater repair', $e->describe());
                $this->assertNotEmpty($e->suggestions);
            }
        }

        // Back to back after the buffer is fine; so is a cancelled slot.
        $this->book($org, '2026-10-06 15:30', ['service_id' => $service->id]);
        $this->assertSame(2, Appointment::withoutGlobalScopes()->count());
    }

    public function test_locations_have_their_own_calendars(): void
    {
        [, $org] = $this->business();
        $north = BusinessLocation::create(['organization_id' => $org->id, 'name' => 'North']);
        $south = BusinessLocation::create(['organization_id' => $org->id, 'name' => 'South']);

        $this->book($org, '2026-10-06 10:00', ['location_id' => $north->id]);
        $this->book($org, '2026-10-06 10:00', ['location_id' => $south->id]);

        $this->expectException(SlotUnavailable::class);
        $this->book($org, '2026-10-06 10:00'); // no location = the whole business
    }

    public function test_owner_books_from_the_portal_and_the_team_hears_about_it(): void
    {
        [$owner, $org] = $this->business();
        $staff = $this->member($org, 'staff');
        $service = $this->service($org);
        $customer = Customer::create(['organization_id' => $org->id, 'first_name' => 'Maria', 'last_name' => 'Lopez', 'phone' => '(512) 555-0147']);

        $this->actingAs($owner)->get(route('app.appointments.index'))->assertOk()->assertSee('No upcoming appointments');

        Livewire::test(Index::class)
            ->call('book', $customer->ulid)
            ->set('form.service_id', (string) $service->id)
            ->set('form.date', '2026-10-06')
            ->assertSee('11:30 AM')
            ->call('pickTime', '2026-10-06 11:30')
            ->set('form.notes', 'Gate code 1234')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('booking', false);

        $appointment = Appointment::withoutGlobalScopes()->sole();
        $this->assertSame('2026-10-06 16:30', $appointment->starts_at->utc()->format('Y-m-d H:i'), '11:30 CDT');
        $this->assertSame('2026-10-06 17:30', $appointment->ends_at->utc()->format('Y-m-d H:i'));
        $this->assertSame('Water heater repair · Maria Lopez', $appointment->title);
        $this->assertSame(AppointmentStatus::Confirmed, $appointment->status);
        $this->assertSame('portal', $appointment->source);
        $this->assertDatabaseHas('audit_logs', ['action' => 'appointment.created', 'organization_id' => $org->id]);
        $this->assertDatabaseHas('customer_timeline_events', ['customer_id' => $customer->id, 'type' => TimelineEventType::AppointmentCreated->value]);
        Notification::assertSentTo($staff, AppointmentActivity::class, fn (AppointmentActivity $n) => $n->event === NotificationEvent::AppointmentCreated);
        Notification::assertNotSentTo($owner, AppointmentActivity::class);

        // The same time again: refused, with free times offered.
        Livewire::test(Index::class)
            ->call('book', $customer->ulid)
            ->set('form.date', '2026-10-06')
            ->set('form.time', '11:30')
            ->call('save')
            ->assertHasErrors('form.time')
            ->assertSet('booking', true)
            ->assertNotSet('suggestions', []);

        // Shown on the calendar feed (business wall-clock), the dashboard and the customer.
        $events = $this->getJson(route('app.calendar.events', ['start' => '2026-10-01', 'end' => '2026-10-31']))->assertOk()->json();
        $this->assertSame('2026-10-06T11:30:00', collect($events)->firstWhere('id', 'appt-'.$appointment->ulid)['start']);
        $this->get(route('app.customers.show', $customer))->assertOk()->assertSee('Upcoming appointments')->assertSee('Tue 6 Oct');

        Carbon::setTestNow($this->at('2026-10-06 08:00'));
        $this->assertSame('11:30 AM', Livewire::test(Dashboard::class)->viewData('schedule')->first()['time']);
    }

    public function test_lifecycle_moves_cancels_completes_and_promotes_the_customer(): void
    {
        [$owner, $org] = $this->business();
        $customer = Customer::create(['organization_id' => $org->id, 'first_name' => 'Tom', 'phone' => '(512) 555-0188', 'status' => 'lead']);
        $appointment = $this->book($org, '2026-10-06 10:00', ['customer_id' => $customer->id, 'status' => 'pending']);
        $blocker = $this->book($org, '2026-10-06 13:00');

        $this->actingAs($owner);
        Livewire::test(Index::class)
            ->set('selected', $appointment->ulid)
            ->assertSee('Confirm')
            ->call('setStatus', $appointment->ulid, 'confirmed')
            ->call('startReschedule', $appointment->ulid)
            ->set('form.time', '13:00')
            ->call('save')
            ->assertHasErrors('form.time')
            ->set('form.time', '15:00')
            ->call('save')
            ->assertHasNoErrors();

        $appointment->refresh();
        $this->assertSame(AppointmentStatus::Confirmed, $appointment->status);
        $this->assertSame('15:00', $appointment->localStart()->format('H:i'));
        $this->assertDatabaseHas('customer_timeline_events', ['customer_id' => $customer->id, 'type' => TimelineEventType::AppointmentUpdated->value]);

        // Cancelling frees the slot.
        Livewire::test(Index::class)->set('cancelReason', 'Customer away')->call('setStatus', $blocker->ulid, 'cancelled');
        $this->assertSame(AppointmentStatus::Cancelled, $blocker->fresh()->status);
        $this->assertSame('Customer away', $blocker->fresh()->cancellation_reason);
        $this->book($org, '2026-10-06 13:00');

        // Completing turns a lead into a customer; undoing re-checks the time.
        Carbon::setTestNow($this->at('2026-10-06 16:30'));
        app(ChangeAppointmentStatus::class)->handle($appointment, AppointmentStatus::Completed, $owner);
        $this->assertSame(CustomerStatus::Customer, $customer->fresh()->status);

        $this->expectException(ValidationException::class);
        app(ChangeAppointmentStatus::class)->handle($appointment->fresh(), AppointmentStatus::Pending, $owner);
    }

    public function test_rules_for_times_and_references(): void
    {
        [, $org] = $this->business();
        [, $other] = $this->business();
        $theirService = $this->service($other);
        $quoteOnly = $this->service($org, extra: ['is_bookable' => false, 'name' => 'Quote']);

        $fails = function (callable $fn, string $field) {
            try {
                $fn();
                $this->fail("expected a {$field} error");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey($field, $e->errors());
            }
        };

        $fails(fn () => $this->book($org, '2026-10-05 07:00'), 'starts_at');
        $fails(fn () => $this->book($org, '2026-10-06 10:00', ['service_id' => $theirService->id]), 'service_id');
        $fails(fn () => $this->book($org, '2026-10-06 10:00', ['service_id' => $quoteOnly->id]), 'service_id');
        // Agents stay inside opening hours; the business itself may book any time.
        $fails(fn () => app(BookAppointment::class)->handle($org, ['starts_at' => $this->at('2026-10-06 18:00')], null, 'agent', strict: true), 'starts_at');
        $this->book($org, '2026-10-06 18:00');
        $this->assertSame(1, Appointment::withoutGlobalScopes()->count());
    }

    public function test_staff_can_book_and_move_but_not_cancel_and_tenants_are_isolated(): void
    {
        [, $org] = $this->business();
        [, $other] = $this->business();
        $staff = $this->member($org, 'staff');
        $mine = $this->book($org, '2026-10-06 10:00');
        $theirs = $this->book($other, '2026-10-06 10:00', ['title' => 'Secret job']);

        $this->actingAs($staff);
        Livewire::test(Index::class)->assertSee('Appointment')->assertDontSee('Secret job')
            ->call('setStatus', $mine->ulid, 'cancelled')->assertForbidden();
        $this->assertSame(AppointmentStatus::Confirmed, $mine->fresh()->status);

        try {
            Livewire::test(Index::class)->call('startReschedule', $theirs->ulid);
            $this->fail('reached another business');
        } catch (ModelNotFoundException) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_appointments_api(): void
    {
        [$owner, $org] = $this->business();
        [$other] = $this->business();
        $service = $this->service($org, 90);
        $customer = Customer::create(['organization_id' => $org->id, 'first_name' => 'Ana', 'phone' => '(512) 555-0102']);

        Sanctum::actingAs($owner);
        $slots = $this->getJson("/api/v1/client/availability?date=2026-10-06&service_id={$service->id}")->assertOk()->json('data');
        $this->assertSame('America/Chicago', $slots['timezone']);
        $this->assertSame(90, $slots['duration_minutes']);
        $this->assertSame('09:00', $slots['slots'][0]['local']);

        $id = $this->postJson('/api/v1/client/appointments', ['starts_at' => '2026-10-06T09:00', 'service_id' => $service->id, 'customer_id' => $customer->ulid])
            ->assertCreated()
            ->assertJsonPath('data.appointment.local_start', '2026-10-06T09:00')
            ->assertJsonPath('data.appointment.customer.id', $customer->ulid)
            ->json('data.appointment.id');

        $this->postJson('/api/v1/client/appointments', ['starts_at' => '2026-10-06T14:30:00Z', 'service_id' => $service->id])
            ->assertStatus(409)->assertJsonPath('success', false)->assertJsonStructure(['suggestions' => [['local', 'starts_at']]]);

        $this->patchJson("/api/v1/client/appointments/{$id}", ['starts_at' => '2026-10-06T13:00'])->assertOk()->assertJsonPath('data.appointment.local_start', '2026-10-06T13:00');
        $this->patchJson("/api/v1/client/appointments/{$id}", ['status' => 'cancelled', 'cancellation_reason' => 'Sick'])->assertOk()->assertJsonPath('data.appointment.status', 'cancelled');
        $this->getJson('/api/v1/client/appointments?from=2026-10-01')->assertOk()->assertJsonCount(1, 'data.appointments');

        $theirs = $this->book(Organization::where('id', '!=', $org->id)->first(), '2026-10-06 10:00');
        $this->getJson("/api/v1/client/appointments/{$theirs->ulid}")->assertNotFound();
        $this->patchJson("/api/v1/client/appointments/{$theirs->ulid}", ['status' => 'cancelled'])->assertNotFound();
        $this->assertNotNull($other);
    }
}
