<?php

namespace Tests\Feature;

use App\Actions\Organizations\ProvisionUserTenancy;
use App\Livewire\Client\Appointments\Index;
use App\Models\Appointment;
use App\Models\BusinessHour;
use App\Models\BusinessLocation;
use App\Models\BusinessService;
use App\Models\Customer;
use App\Models\CustomerLocation;
use App\Models\Organization;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * D56 — the business's own booking form has the same Location section as the agent workspace,
 * except that the address may be left out.
 */
class ClientBookingLocationTest extends TestCase
{
    use RefreshDatabase;

    private const TZ = 'America/Chicago';

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Carbon::setTestNow(CarbonImmutable::parse('2026-10-05 08:00', self::TZ)); // Monday morning
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function business(): Organization
    {
        $this->owner = User::factory()->create(['role' => 'client', 'is_active' => true, 'must_change_password' => false]);
        $organization = app(ProvisionUserTenancy::class)->handle($this->owner);
        $organization->update(['timezone' => self::TZ]);
        foreach ([1, 2, 3, 4, 5] as $day) {
            BusinessHour::create(['organization_id' => $organization->id, 'day_of_week' => $day, 'opens_at' => '09:00', 'closes_at' => '17:00']);
        }

        return $organization->fresh();
    }

    private function form(string $customerUlid = ''): Testable
    {
        $this->actingAs($this->owner)->get(route('app.appointments.index'))->assertOk();

        return Livewire::test(Index::class)->call('book', $customerUlid)->set('form.date', '2026-10-06')->call('pickTime', '2026-10-06 11:00');
    }

    public function test_the_address_is_optional_but_complete_when_given_and_can_be_saved(): void
    {
        $org = $this->business();
        $customer = Customer::create(['organization_id' => $org->id, 'first_name' => 'Maria', 'last_name' => 'Lopez', 'phone' => '(512) 555-0147']);

        // Nothing typed: booked without an address, as before.
        $this->form($customer->ulid)->assertSee('Specify exact address')->assertSee('Leave blank if there')
            ->call('save')->assertHasNoErrors()->assertSet('booking', false);
        $plain = Appointment::withoutGlobalScopes()->sole();
        $this->assertNull($plain->address);
        $this->assertNull($plain->location_mode);

        // Part of an address: the rest is needed.
        $form = $this->form($customer->ulid)->call('pickTime', '2026-10-06 13:00')->set('form.location.line1', '12 Main St')
            ->call('save')->assertHasErrors(['form.location.city', 'form.location.state', 'form.location.postal_code']);
        $form->set('form.location.city', 'Austin')->set('form.location.state', 'TX')->set('form.location.postal_code', '78701')
            ->set('form.location.name', 'Home')->set('form.location.save', true)->call('save')->assertHasNoErrors();

        $appointment = Appointment::withoutGlobalScopes()->latest('id')->first();
        $this->assertSame([Appointment::LOCATION_ADDRESS, '12 Main St, Austin, TX 78701', 'Home'], [$appointment->location_mode, $appointment->address, $appointment->place_name]);
        $saved = CustomerLocation::withoutGlobalScopes()->sole();
        $this->assertSame([$customer->id, $saved->id], [$saved->customer_id, $appointment->customer_location_id]);

        // Next time the customer's place is offered first.
        $this->form($customer->ulid)->assertSet('form.location.mode', 'saved')->assertSet('form.location.saved', 'c:'.$saved->id)
            ->assertSee('12 Main St, Austin, TX 78701');
    }

    public function test_business_locations_replace_the_old_location_picker_and_follow_the_service(): void
    {
        $org = $this->business();
        $north = BusinessLocation::create(['organization_id' => $org->id, 'name' => 'North', 'address_line1' => '1 North Rd', 'city' => 'Austin', 'state' => 'TX', 'postal_code' => '78750', 'is_primary' => true]);
        $south = BusinessLocation::create(['organization_id' => $org->id, 'name' => 'South', 'address_line1' => '9 South Rd', 'city' => 'Austin', 'state' => 'TX', 'postal_code' => '78745']);
        $service = BusinessService::create(['organization_id' => $org->id, 'name' => 'Tune-up', 'duration_minutes' => 60, 'price_type' => 'quote_required', 'location_id' => $south->id]);
        // North is busy at 11; South is free.
        Appointment::create(['organization_id' => $org->id, 'location_id' => $north->id, 'title' => 'Busy', 'starts_at' => CarbonImmutable::parse('2026-10-06 11:00', self::TZ),
            'ends_at' => CarbonImmutable::parse('2026-10-06 12:00', self::TZ), 'blocked_until' => CarbonImmutable::parse('2026-10-06 12:00', self::TZ), 'timezone' => self::TZ]);

        $form = $this->form()->set('form.service_id', (string) $service->id)
            ->assertSet('form.location.mode', 'saved')->assertSet('form.location.saved', 'b:'.$south->id)
            ->assertSee('At North')->assertSee('At South')->assertSeeHtml("pickTime('2026-10-06 11:00')");

        // Moving to North changes the calendar: 11:00 is no longer offered.
        $form->set('form.location.saved', 'b:'.$north->id)->assertSet('form.time', '')->assertDontSeeHtml("pickTime('2026-10-06 11:00')");

        $form->set('form.location.saved', 'b:'.$south->id)->call('pickTime', '2026-10-06 11:00')->call('save')->assertHasNoErrors();
        $appointment = Appointment::withoutGlobalScopes()->latest('id')->first();
        $this->assertSame([Appointment::LOCATION_BUSINESS, $south->id, 'South · 9 South Rd, Austin, TX 78745'],
            [$appointment->location_mode, $appointment->location_id, $appointment->whereLabel()]);
    }

    public function test_the_business_can_book_an_exact_minute(): void
    {
        $this->business();

        $this->form()->assertSee('Or set an exact time')->set('form.time', '09:07:00')->assertSet('form.time', '09:07')
            ->call('save')->assertHasNoErrors();
        $this->assertSame('09:07', Appointment::withoutGlobalScopes()->sole()->localStart()->format('H:i'));
    }

    public function test_remote_and_new_customers(): void
    {
        $org = $this->business();

        $this->form()->set('form.location.mode', 'remote')->call('save')->assertHasNoErrors();
        $this->assertSame('Remote (no address)', Appointment::withoutGlobalScopes()->sole()->whereLabel());

        // A new customer typed into the form gets the address on their record, and the place saved.
        $parts = ['line1' => '5 Oak Ave', 'city' => 'Austin', 'state' => 'TX', 'postal_code' => '78704', 'save' => true];
        $form = $this->form()->call('pickTime', '2026-10-06 14:00')->set('form.new_name', 'Ana Ruiz');
        foreach ($parts as $field => $value) {
            $form->set('form.location.'.$field, $value);
        }
        $form->call('save')->assertHasErrors('form.location.save');

        $form->set('form.new_phone', '(512) 555-0188')->call('save')->assertHasNoErrors();
        $ana = Customer::withoutGlobalScopes()->where('first_name', 'Ana')->sole();
        $this->assertSame(['5 Oak Ave', 'Austin', 'TX', '78704'], [$ana->address_line1, $ana->city, $ana->state, $ana->postal_code]);
        $this->assertSame($ana->id, CustomerLocation::withoutGlobalScopes()->sole()->customer_id);
    }
}
