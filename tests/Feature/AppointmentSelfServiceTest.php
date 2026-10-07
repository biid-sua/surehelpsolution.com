<?php

namespace Tests\Feature;

use App\Actions\Appointments\BookAppointment;
use App\Actions\Appointments\ChangeAppointmentStatus;
use App\Actions\Assignments\AssignAgent;
use App\Actions\Organizations\ProvisionUserTenancy;
use App\Enums\AppointmentStatus;
use App\Livewire\Client\Business\CustomerEmails;
use App\Livewire\Client\Business\Rules;
use App\Livewire\Customer\ManageAppointment;
use App\Models\Appointment;
use App\Models\BusinessHour;
use App\Models\BusinessProfile;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\AppointmentActivity;
use App\Notifications\CustomerEmail;
use App\Services\Appointments\CustomerSelfService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * CAL-09: customers change or cancel from a signed link in their emails. CAL-08: owners can approve
 * bookings made by SureHelp agents before they're confirmed.
 */
class AppointmentSelfServiceTest extends TestCase
{
    use RefreshDatabase;

    private const TZ = 'America/Chicago';

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Carbon::setTestNow(CarbonImmutable::parse('2026-10-12 09:00', self::TZ)); // Monday
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array{0: User, 1: Organization, 2: Customer} */
    private function business(): array
    {
        $owner = User::factory()->create(['role' => 'client', 'is_active' => true, 'must_change_password' => false]);
        $org = app(ProvisionUserTenancy::class)->handle($owner);
        $org->forceFill(['name' => 'Rivera Plumbing', 'timezone' => self::TZ, 'setup_completed_at' => now()])->save();
        BusinessProfile::create(['organization_id' => $org->id, 'display_name' => 'Rivera Plumbing', 'phone' => '5125550100']);
        foreach (range(1, 5) as $day) {
            BusinessHour::create(['organization_id' => $org->id, 'day_of_week' => $day, 'opens_at' => '08:00', 'closes_at' => '17:00']);
        }
        $customer = Customer::create(['organization_id' => $org->id, 'first_name' => 'Ana', 'phone' => '5125550101', 'email' => 'ana@example.test']);

        return [$owner, $org->fresh(), $customer];
    }

    private function book(Organization $org, Customer $customer, string $at, ?User $actor = null): Appointment
    {
        return app(BookAppointment::class)->handle($org, ['starts_at' => CarbonImmutable::parse($at, self::TZ), 'title' => 'Leak repair', 'customer_id' => $customer->id], $actor);
    }

    private function sentLink(): ?string
    {
        $url = null;
        Notification::assertSentOnDemand(CustomerEmail::class, function (CustomerEmail $n) use (&$url) {
            $url = $n->actionUrl;

            return true;
        });

        return $url;
    }

    public function test_the_confirmation_email_links_to_a_page_where_the_customer_moves_the_appointment(): void
    {
        [$owner, $org, $customer] = $this->business();
        $appointment = $this->book($org, $customer, '2026-10-15 10:00');
        $url = $this->sentLink();
        $this->assertNotNull($url);
        $this->assertStringContainsString('signature=', $url);

        $this->get($url)->assertOk()->assertSee('Rivera Plumbing')->assertSee('Leak repair')->assertSee('Change the time');

        Livewire::test(ManageAppointment::class, ['appointment' => $appointment->ulid])
            ->call('startMove')->set('date', '2026-10-16')
            ->assertViewHas('slots', fn (array $slots) => count($slots) > 0)
            ->set('time', '18:00')->call('move')->assertHasErrors('time')
            ->set('time', '11:00')->call('move')->assertHasNoErrors();

        $this->assertSame('2026-10-16 11:00', $appointment->fresh()->localStart()->format('Y-m-d H:i'));
        Notification::assertSentTo($owner, AppointmentActivity::class);
        $this->assertDatabaseHas('audit_logs', ['action' => 'appointment.rescheduled', 'actor_id' => null]);
    }

    public function test_the_customer_cancels_until_the_cut_off_then_must_call(): void
    {
        [, $org, $customer] = $this->business();
        $soon = $this->book($org, $customer, '2026-10-12 15:00');      // 6 hours away, cut-off 24
        $later = $this->book($org, $customer, '2026-10-20 10:00');

        $this->assertNull(app(CustomerSelfService::class)->link($soon), 'no link inside the cut-off');
        Livewire::test(ManageAppointment::class, ['appointment' => $soon->ulid])->assertSee('too close to the appointment')
            ->set('mode', 'cancel')->call('cancel');
        $this->assertSame(AppointmentStatus::Confirmed, $soon->fresh()->status);

        Livewire::test(ManageAppointment::class, ['appointment' => $later->ulid])
            ->set('mode', 'cancel')->set('reason', 'Fixed it myself')->call('cancel')->assertSee('This appointment is cancelled');
        $later->refresh();
        $this->assertSame(AppointmentStatus::Cancelled, $later->status);
        $this->assertSame('Cancelled by the customer online: Fixed it myself', $later->cancellation_reason);
    }

    public function test_links_can_be_switched_off_and_cant_be_forged_or_reused_after_the_start(): void
    {
        [$owner, $org, $customer] = $this->business();
        $appointment = $this->book($org, $customer, '2026-10-15 10:00');
        $url = (string) app(CustomerSelfService::class)->link($appointment);

        $this->get(str_replace($appointment->ulid, (string) $this->book($org, $customer, '2026-10-16 10:00')->ulid, $url))
            ->assertForbidden()->assertSee('This link no longer works');
        $this->travelTo(CarbonImmutable::parse('2026-10-15 10:30', self::TZ));
        $this->get($url)->assertForbidden();
        $this->travelBack();

        $this->actingAs($owner);
        Livewire::test(CustomerEmails::class)->set('changeHours', 'off')->call('saveSelfService')->assertHasNoErrors();
        $this->assertNull(app(CustomerSelfService::class)->link($appointment->fresh()));
        Livewire::test(CustomerEmails::class)->set('changeHours', '7')->call('saveSelfService')->assertHasErrors('changeHours');
    }

    public function test_owners_can_approve_bookings_made_by_agents(): void
    {
        [$owner, $org, $customer] = $this->business();
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $admin->syncRoles(['super_admin']);
        $agent = User::factory()->create(['role' => 'agent', 'is_active' => true]);
        $agent->syncRoles(['agent']);
        app(AssignAgent::class)->handle($org, $agent, $admin);

        $this->actingAs($owner);
        Livewire::test(Rules::class)->call('toggleApproval');
        $org->refresh();
        $this->assertTrue($org->approve_agent_bookings);
        Notification::fake();

        $byAgent = $this->book($org, $customer, '2026-10-15 10:00', $agent);
        $byOwner = $this->book($org, $customer, '2026-10-16 10:00', $owner);
        $this->assertSame(AppointmentStatus::Pending, $byAgent->status);
        $this->assertSame(AppointmentStatus::Confirmed, $byOwner->status);
        Notification::assertSentTo($owner, AppointmentActivity::class, fn (AppointmentActivity $n) => str_starts_with($n->title(), 'Approve:'));

        // Nothing goes to the customer until the owner confirms.
        $emails = fn () => collect(Notification::sentNotifications())->flatten(3)->filter(fn ($e) => ($e['notification'] ?? null) instanceof CustomerEmail)->count();
        $this->assertSame(1, $emails(), 'only the owner\'s own booking was confirmed to the customer');
        app(ChangeAppointmentStatus::class)->handle($byAgent, AppointmentStatus::Confirmed, $owner);
        $this->assertSame(2, $emails());
    }
}
