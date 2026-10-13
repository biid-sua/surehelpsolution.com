<?php

namespace Tests\Feature;

use App\Actions\Customers\EraseCustomer;
use App\Actions\Organizations\ProvisionUserTenancy;
use App\Enums\BusinessRuleType;
use App\Livewire\Agent\Workspace;
use App\Models\Appointment;
use App\Models\BusinessHour;
use App\Models\BusinessLocation;
use App\Models\BusinessRule;
use App\Models\CallLog;
use App\Models\Customer;
use App\Models\CustomerLocation;
use App\Models\Organization;
use App\Models\User;
use App\Support\Geo\Address;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * D56 — where an agent's booking happens: an exact address in parts, a saved place, or remote.
 */
class AgentVisitLocationTest extends TestCase
{
    use RefreshDatabase;

    private const TZ = 'America/Chicago';

    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Carbon::setTestNow(CarbonImmutable::parse('2026-10-06 10:00', self::TZ)); // Tuesday morning
        $this->agent = User::factory()->create(['role' => 'agent', 'is_active' => true, 'must_change_password' => false, 'name' => 'Jamie Agent']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function business(): Organization
    {
        $owner = User::factory()->create(['role' => 'client', 'is_active' => true, 'must_change_password' => false]);
        $organization = app(ProvisionUserTenancy::class)->handle($owner);
        $organization->update(['timezone' => self::TZ, 'name' => 'Rivera Plumbing']);
        foreach ([1, 2, 3, 4, 5] as $day) {
            BusinessHour::create(['organization_id' => $organization->id, 'day_of_week' => $day, 'opens_at' => '08:00', 'closes_at' => '17:00']);
        }
        $organization->assignAgent($this->agent);

        return $organization->fresh();
    }

    /** A call from Grace with a booking at 10:30 tomorrow, everything filled in except where. */
    private function bookingCall(Organization $organization, string $phone = '(512) 555-0199'): Testable
    {
        $this->actingAs($this->agent);

        return Livewire::test(Workspace::class, ['organization' => $organization])
            ->set('entry.phone', $phone)->set('entry.name', 'Grace Kim')
            ->set('entry.reason', 'appointment-scheduling')->set('entry.outcome', 'scheduled-appointment')
            ->set('entry.book', true)->set('entry.date', '2026-10-07')->call('pickTime', '2026-10-07 10:30');
    }

    private function address(Testable $component, array $parts): Testable
    {
        foreach ($parts as $field => $value) {
            $component->set('entry.location.'.$field, $value);
        }

        return $component;
    }

    public function test_an_exact_address_is_stored_in_parts_and_can_be_saved_for_next_time(): void
    {
        $org = $this->business();

        $component = $this->bookingCall($org)->assertSee('Specify exact address')->assertSee('Use saved location')->assertSee('Remote (no address)');
        $this->address($component, ['name' => 'Lake house', 'type' => 'residential', 'line1' => '12  Main St', 'line2' => 'Unit 4',
            'city' => 'Austin', 'state' => 'TX', 'postal_code' => '7870', 'save' => true])
            ->call('save')->assertHasErrors(['entry.location.postal_code']);
        $this->assertSame(0, CallLog::withoutGlobalScopes()->count());

        $component->set('entry.location.postal_code', '78701')->call('save')->assertHasNoErrors();

        $appointment = Appointment::withoutGlobalScopes()->sole();
        $this->assertSame(Appointment::LOCATION_ADDRESS, $appointment->location_mode);
        $this->assertSame(['12 Main St', 'Unit 4', 'Austin', 'TX', '78701', 'US'],
            [$appointment->address_line1, $appointment->address_line2, $appointment->city, $appointment->state, $appointment->postal_code, $appointment->country]);
        $this->assertSame('12 Main St, Unit 4, Austin, TX 78701', $appointment->address, 'the one-line form every other reader uses');
        $this->assertSame('Lake house · 12 Main St, Unit 4, Austin, TX 78701', $appointment->whereLabel());

        $customer = Customer::withoutGlobalScopes()->sole();
        $this->assertSame(['12 Main St', 'Austin', 'TX', '78701'], [$customer->address_line1, $customer->city, $customer->state, $customer->postal_code],
            'a new customer gets the address in its fields, not as one line');
        $saved = CustomerLocation::withoutGlobalScopes()->sole();
        $this->assertSame([$customer->id, 'Lake house', 'residential', '78701'], [$saved->customer_id, $saved->name, $saved->type, $saved->postal_code]);
        $this->assertSame($saved->id, $appointment->customer_location_id);
    }

    public function test_a_returning_caller_is_offered_their_saved_places_and_the_business_locations(): void
    {
        $org = $this->business();
        $office = BusinessLocation::create(['organization_id' => $org->id, 'name' => 'Main office', 'address_line1' => '1 Shop Rd', 'city' => 'Austin', 'state' => 'TX', 'postal_code' => '78702', 'is_primary' => true]);
        $customer = Customer::create(['organization_id' => $org->id, 'first_name' => 'Grace', 'last_name' => 'Kim', 'phone' => '(512) 555-0199']);
        $old = CustomerLocation::create(['organization_id' => $org->id, 'customer_id' => $customer->id, 'name' => 'Rental', 'address_line1' => '9 Elm St', 'city' => 'Austin', 'state' => 'TX', 'postal_code' => '78703', 'last_used_at' => now()->subMonth()]);
        $home = CustomerLocation::create(['organization_id' => $org->id, 'customer_id' => $customer->id, 'name' => 'Home', 'address_line1' => '5 Oak Ave', 'city' => 'Austin', 'state' => 'TX', 'postal_code' => '78704', 'last_used_at' => now()->subDay()]);

        // Before the caller is confirmed only the business's own locations are on offer.
        $component = $this->bookingCall($org)->set('entry.location.mode', 'saved')
            ->assertSee('At Main office')->assertDontSee('9 Elm St');

        $component->call('confirmCustomer', $customer->id)
            ->assertSet('entry.location.mode', 'saved')->assertSet('entry.location.saved', 'c:'.$home->id) // most recently used first
            ->assertSee('Customer&#039;s places', false)->assertSee('9 Elm St, Austin, TX 78703')->assertSee('At Main office');

        $component->set('entry.location.saved', 'c:'.$old->id)->call('pickTime', '2026-10-07 10:30')->call('save')->assertHasNoErrors();
        $appointment = Appointment::withoutGlobalScopes()->sole();
        $this->assertSame([$old->id, 'Rental', '9 Elm St, Austin, TX 78703'], [$appointment->customer_location_id, $appointment->place_name, $appointment->address]);
        $this->assertTrue($old->fresh()->last_used_at->isToday(), 'used places move to the top');

        // At the business: its location (and its calendar) is used.
        $this->bookingCall($org)->call('confirmCustomer', $customer->id)->set('entry.location.saved', 'b:'.$office->id)
            ->call('pickTime', '2026-10-07 13:00')->call('save')->assertHasNoErrors();
        $atOffice = Appointment::withoutGlobalScopes()->latest('id')->first();
        $this->assertSame([Appointment::LOCATION_BUSINESS, $office->id], [$atOffice->location_mode, $atOffice->location_id]);
        $this->assertSame('Main office · 1 Shop Rd, Austin, TX 78702', $atOffice->whereLabel());

        // Someone else's saved place can't be used, even by forging the form.
        $stranger = Customer::create(['organization_id' => $org->id, 'first_name' => 'Other', 'phone' => '(512) 555-0111']);
        $theirs = CustomerLocation::create(['organization_id' => $org->id, 'customer_id' => $stranger->id, 'address_line1' => '1 Secret Ln', 'city' => 'Austin']);
        $this->bookingCall($org)->call('confirmCustomer', $customer->id)->set('entry.location.saved', 'c:'.$theirs->id)
            ->call('save')->assertHasErrors('entry.location.saved');
    }

    public function test_remote_bookings_need_no_address_even_when_the_business_requires_one_for_visits(): void
    {
        $org = $this->business();
        BusinessRule::create(['organization_id' => $org->id, 'type' => BusinessRuleType::RequireDetail, 'config' => ['field' => 'address']]);

        $this->bookingCall($org)->set('entry.location.mode', 'remote')->assertSee('No address is recorded')->call('save')->assertHasNoErrors();

        $appointment = Appointment::withoutGlobalScopes()->sole();
        $this->assertSame([Appointment::LOCATION_REMOTE, null], [$appointment->location_mode, $appointment->address]);
        $this->assertSame('Remote (no address)', $appointment->whereLabel());
        $this->assertSame('Remote (no address)', CallLog::withoutGlobalScopes()->sole()->service_location);
    }

    public function test_an_agent_can_book_any_exact_minute_within_hours_and_rules(): void
    {
        $org = $this->business();

        $this->bookingCall($org)->assertSee('Or set an exact time')->set('entry.location.mode', 'remote')
            ->set('entry.time', '08:06:00')->assertSet('entry.time', '08:06') // seconds from some browsers are dropped
            ->call('save')->assertHasNoErrors();
        $this->assertSame('08:06', Appointment::withoutGlobalScopes()->sole()->localStart()->format('H:i'));

        // Still checked like any other time: before opening, and on top of the 8:06 booking.
        $this->bookingCall($org, '(512) 555-0100')->set('entry.location.mode', 'remote')->set('entry.time', '07:55')
            ->call('save')->assertHasErrors('entry.time');
        $this->bookingCall($org, '(512) 555-0101')->set('entry.location.mode', 'remote')->set('entry.time', '08:41')
            ->call('save')->assertHasErrors('entry.time');
        $this->assertSame(1, Appointment::withoutGlobalScopes()->count());
    }

    public function test_the_service_area_reads_the_zip_field(): void
    {
        $org = $this->business();
        BusinessRule::create(['organization_id' => $org->id, 'type' => BusinessRuleType::ServiceArea, 'config' => ['postal_codes' => ['78701']]]);
        $component = $this->address($this->bookingCall($org), ['line1' => '400 Lamar Blvd', 'city' => 'Austin', 'state' => 'TX', 'postal_code' => '78745']);

        $component->call('save')->assertHasErrors('entry.location');
        $this->assertStringContainsString('ZIP 78745 is outside', $component->errors()->first('entry.location'));

        $component->set('entry.location.postal_code', '78701-1234')->call('save')->assertHasNoErrors();
    }

    public function test_country_sets_the_region_list_and_postal_format(): void
    {
        $org = $this->business();
        $component = $this->bookingCall($org)->assertSee('ZIP code')->assertSee('Texas')
            ->set('entry.location.state', 'TX')->set('entry.location.country', 'CA')
            ->assertSet('entry.location.state', '')->assertSee('Province')->assertSee('Postal code')->assertSee('Quebec');
        $this->address($component, ['line1' => '1 Rue Sainte-Catherine', 'city' => 'Montréal', 'state' => 'QC', 'postal_code' => 'h3b1a7'])
            ->call('save')->assertHasNoErrors();
        $this->assertSame('1 Rue Sainte-Catherine, Montréal, QC H3B 1A7, Canada', Appointment::withoutGlobalScopes()->sole()->address);

        $this->assertSame(['US', 'CA'], array_slice(array_keys(Address::countries()), 0, 2), 'the most common countries come first');
        $this->assertCount(249, Address::countries());

        // Elsewhere the region is typed and the postal code is optional.
        $this->assertSame([], Address::regions('GB'));
        $this->assertSame('10 Downing St, London, United Kingdom', Address::singleLine(['line1' => '10 Downing St', 'city' => 'London', 'country' => 'GB']));
    }

    public function test_saving_a_place_needs_someone_to_save_it_for_and_does_not_duplicate(): void
    {
        $org = $this->business();
        $parts = ['line1' => '12 Main St', 'city' => 'Austin', 'state' => 'TX', 'postal_code' => '78701', 'save' => true];

        $this->address($this->bookingCall($org, phone: '')->set('entry.name', 'No Number'), $parts)
            ->call('save')->assertHasErrors('entry.location.save');

        $this->address($this->bookingCall($org), $parts)->call('save')->assertHasNoErrors();
        $customer = Customer::withoutGlobalScopes()->sole();
        $this->address($this->bookingCall($org)->call('confirmCustomer', $customer->id)->set('entry.location.mode', 'address'),
            ['line1' => '12 MAIN ST'] + $parts)->call('pickTime', '2026-10-07 13:00')->call('save')->assertHasNoErrors();
        $this->assertSame(1, CustomerLocation::withoutGlobalScopes()->count());

        // Erasing the customer removes their places along with everything else personal.
        app(EraseCustomer::class)->handle($customer, $this->agent);
        $this->assertSame(0, CustomerLocation::withoutGlobalScopes()->count());
        $this->assertSame(0, Appointment::withoutGlobalScopes()->whereNotNull('address_line1')->count());
    }
}
