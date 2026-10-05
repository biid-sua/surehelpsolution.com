<?php

namespace Tests\Feature;

use App\Actions\Appointments\BookAppointment;
use App\Actions\Appointments\ChangeAppointmentStatus;
use App\Actions\Appointments\RescheduleAppointment;
use App\Actions\Organizations\ProvisionUserTenancy;
use App\Enums\AppointmentStatus;
use App\Enums\TimelineEventType;
use App\Livewire\Client\Business\CustomerEmails;
use App\Models\Appointment;
use App\Models\BusinessProfile;
use App\Models\Customer;
use App\Models\CustomerTimelineEvent;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\CustomerEmail;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Appointment emails to a business's customers (spec §26–27).
 */
class CustomerEmailsTest extends TestCase
{
    use RefreshDatabase;

    private const TZ = 'America/Chicago';

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Carbon::setTestNow(CarbonImmutable::parse('2026-10-14 09:00', self::TZ));
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
        BusinessProfile::create(['organization_id' => $org->id, 'display_name' => 'Rivera Plumbing', 'phone' => '5125550100', 'email' => 'office@rivera.test']);
        $customer = Customer::create(['organization_id' => $org->id, 'first_name' => 'Ana', 'last_name' => 'Lopez', 'phone' => '5125550101', 'email' => 'ana@example.test']);

        return [$owner, $org->fresh(), $customer];
    }

    /** @return list<CustomerEmail> emails sent to customers */
    private function customerEmails(): array
    {
        $found = [];
        foreach (Notification::sentNotifications() as $notifiable => $byId) {
            foreach ($byId as $notifications) {
                foreach ($notifications[CustomerEmail::class] ?? [] as $entry) {
                    $found[] = $entry['notification'];
                }
            }
        }

        return $found;
    }

    private function book(Organization $org, Customer $customer, string $at, string $status = 'confirmed'): Appointment
    {
        return app(BookAppointment::class)->handle($org, ['starts_at' => CarbonImmutable::parse($at, self::TZ), 'title' => 'Leak repair', 'customer_id' => $customer->id, 'address' => '12 Oak St', 'status' => $status]);
    }

    public function test_confirmation_change_and_cancellation_emails_in_the_business_name(): void
    {
        [$owner, $org, $customer] = $this->business();
        $appointment = $this->book($org, $customer, '2026-10-16 10:00');

        Notification::assertSentOnDemand(CustomerEmail::class, function (CustomerEmail $n, array $channels, AnonymousNotifiable $to) {
            $mail = $n->toMail($to);

            return $to->routes['mail'] === 'ana@example.test'
                && $n->subject === 'Your appointment with Rivera Plumbing is confirmed'
                && str_contains($n->body, 'Hi Ana,') && str_contains($n->body, 'Friday, October 16 at 10:00 AM')
                && str_contains($n->body, 'Address: 12 Oak St') && str_contains($n->body, '(512) 555-0100')
                && $mail->from[1] === 'Rivera Plumbing' && $mail->replyTo[0][0] === 'office@rivera.test';
        });
        $this->assertSame(1, CustomerTimelineEvent::where('customer_id', $customer->id)->where('type', TimelineEventType::EmailSent->value)->count());

        app(RescheduleAppointment::class)->handle($appointment->fresh(), CarbonImmutable::parse('2026-10-17 14:00', self::TZ), null, $owner);
        app(ChangeAppointmentStatus::class)->handle($appointment->fresh(), AppointmentStatus::Cancelled, $owner, 'Customer called');

        $subjects = array_map(fn (CustomerEmail $n) => $n->subject, $this->customerEmails());
        $this->assertSame([
            'Your appointment with Rivera Plumbing is confirmed',
            'Your appointment with Rivera Plumbing has moved',
            'Your appointment with Rivera Plumbing was cancelled',
        ], $subjects);
    }

    public function test_no_email_without_an_address_or_when_switched_off_and_pending_waits_for_confirmation(): void
    {
        [$owner, $org, $customer] = $this->business();
        $noEmail = Customer::create(['organization_id' => $org->id, 'first_name' => 'Bo', 'phone' => '5125550102']);
        $this->book($org, $noEmail, '2026-10-16 10:00');

        $pending = $this->book($org, $customer, '2026-10-16 13:00', 'pending');
        $this->assertSame([], $this->customerEmails());
        app(ChangeAppointmentStatus::class)->handle($pending->fresh(), AppointmentStatus::Confirmed, $owner);
        $this->assertCount(1, $this->customerEmails());

        $this->actingAs($owner);
        Livewire::test(CustomerEmails::class)->call('toggle', 'appointment_confirmed');
        $this->book($org, $customer, '2026-10-20 10:00');
        $this->assertCount(1, $this->customerEmails(), 'switched off');
    }

    public function test_reminders_go_once_inside_the_lead_time_but_not_for_last_minute_bookings(): void
    {
        [, $org, $customer] = $this->business();
        $soon = $this->book($org, $customer, '2026-10-14 15:00');          // booked 6 hours ahead: no reminder
        $tomorrow = $this->book($org, $customer, '2026-10-15 10:00');
        $tomorrow->forceFill(['created_at' => now()->subDays(3)])->saveQuietly();
        $nextWeek = $this->book($org, $customer, '2026-10-21 10:00');
        $nextWeek->forceFill(['created_at' => now()->subDays(3)])->saveQuietly();

        Carbon::setTestNow(CarbonImmutable::parse('2026-10-14 11:00', self::TZ));   // 23 hours before tomorrow 10:00
        $this->artisan('appointments:send-reminders')->expectsOutputToContain('Sent 1 reminder.');
        $this->artisan('appointments:send-reminders')->expectsOutputToContain('Sent 0 reminders.');
        $reminders = array_values(array_filter($this->customerEmails(), fn (CustomerEmail $n) => str_starts_with($n->subject, 'Reminder')));
        $this->assertCount(1, $reminders);
        $this->assertSame('Reminder: Leak repair on Thursday, October 15 at 10:00 AM', $reminders[0]->subject);
        $this->assertNotNull($soon->fresh()->reminder_sent_at, 'covered by the confirmation');
        $this->assertNull($nextWeek->fresh()->reminder_sent_at);
    }

    public function test_owners_edit_the_wording_with_a_live_preview(): void
    {
        [$owner, $org, $customer] = $this->business();
        $this->actingAs($owner)->get(route('app.business.emails'))->assertOk()->assertSee('Booking confirmation')->assertSee('Reminder');

        Livewire::test(CustomerEmails::class)->call('edit', 'appointment_confirmed')
            ->set('draft.subject', 'See you {date}, {first_name}!')->assertSee('See you')
            ->set('draft.body', 'Hello {nickname}')->call('save')->assertHasErrors('draft.body')
            ->set('draft.body', "Hi {first_name}, you're booked for {time}.")->call('save')->assertHasNoErrors()
            ->call('edit', 'appointment_reminder')->set('draft.lead_hours', 4)->call('save')->assertHasNoErrors();

        $this->book($org, $customer, '2026-10-16 10:00');
        $email = $this->customerEmails()[0];
        $this->assertSame('See you Friday, October 16, Ana!', $email->subject);
        $this->assertSame("Hi Ana, you're booked for 10:00 AM.", $email->body);

        Livewire::test(CustomerEmails::class)->call('edit', 'appointment_confirmed')->call('sendTest');
        Notification::assertSentOnDemand(CustomerEmail::class, fn (CustomerEmail $n, array $c, AnonymousNotifiable $to) => $to->routes['mail'] === $owner->email && str_starts_with($n->subject, '[Test]'));

        // Managers look after customer emails too (settings.manage).
        $staff = User::factory()->create(['role' => 'client', 'is_active' => true]);
        $org->members()->attach($staff->id, ['role' => 'manager', 'status' => 'active']);
        $this->actingAs($staff);
        Livewire::test(CustomerEmails::class)->call('edit', 'appointment_confirmed')->assertOk();
    }
}
