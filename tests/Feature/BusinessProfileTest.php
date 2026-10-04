<?php

namespace Tests\Feature;

use App\Actions\Organizations\ProvisionUserTenancy;
use App\Livewire\Client\Business\Hours;
use App\Livewire\Client\Business\Profile;
use App\Models\BusinessHoliday;
use App\Models\BusinessHour;
use App\Models\BusinessLocation;
use App\Models\BusinessProfile;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * P2-1 — business profile, location, hours and holidays (spec §9–10).
 */
class BusinessProfileTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_owner_saves_profile_location_and_timezone(): void
    {
        [$owner, $org] = $this->business();

        $this->actingAs($owner)->get(route('app.business.profile'))->assertOk()->assertSee('Business');

        Livewire::test(Profile::class)
            ->set('form.name', 'Rapid Plumbing')
            ->set('form.timezone', 'America/Chicago')
            ->set('form.description', 'Family-run plumbers since 1998.')
            ->set('form.website', 'https://rapidplumbing.test')
            ->set('form.address_line1', '12 Main St')
            ->set('form.city', 'Austin')
            ->set('form.state', 'TX')
            ->set('form.postal_code', '78701')
            ->call('save')
            ->assertHasNoErrors()
            ->assertDispatched('toast');

        $org->refresh();
        $this->assertSame('Rapid Plumbing', $org->name);
        $this->assertSame('America/Chicago', $org->timezone);
        $this->assertSame('Family-run plumbers since 1998.', BusinessProfile::where('organization_id', $org->id)->value('description'));
        $location = BusinessLocation::where('organization_id', $org->id)->sole();
        $this->assertTrue($location->is_primary);
        $this->assertSame('12 Main St, Austin, TX 78701', $location->singleLine());
        $this->assertDatabaseHas('audit_logs', ['action' => 'business_profile.updated', 'organization_id' => $org->id]);
    }

    public function test_profile_validation(): void
    {
        [$owner] = $this->business();
        $this->actingAs($owner);

        Livewire::test(Profile::class)
            ->set('form.name', '')
            ->set('form.timezone', 'Mars/Base')
            ->set('form.website', 'not a url')
            ->set('form.email', 'nope')
            ->call('save')
            ->assertHasErrors(['form.name', 'form.timezone', 'form.website', 'form.email']);
    }

    public function test_manager_can_view_but_not_change_and_staff_cannot_open(): void
    {
        [, $org] = $this->business();
        $manager = $this->member($org, 'manager');
        $staff = $this->member($org, 'staff');

        $this->actingAs($manager)->get(route('app.business.profile'))->assertOk()->assertSee('Only the business owner can change these details.');
        Livewire::test(Profile::class)->set('form.name', 'Hijacked')->call('save')->assertForbidden();
        $this->assertNotSame('Hijacked', $org->fresh()->name);

        $this->actingAs($staff)->get(route('app.business.profile'))->assertForbidden();
        $this->actingAs($staff)->get(route('app.business.hours'))->assertForbidden();
    }

    public function test_hours_with_split_shift_are_saved(): void
    {
        [$owner, $org] = $this->business();
        $org->update(['timezone' => 'America/New_York']);
        $this->actingAs($owner);

        Livewire::test(Hours::class)
            ->call('addInterval', 1)
            ->set('days.1.0.opens', '08:00')->set('days.1.0.closes', '12:00')
            ->call('addInterval', 1)
            ->set('days.1.1.opens', '13:00')->set('days.1.1.closes', '17:00')
            ->call('copyMondayToWeekdays')
            ->set('emergencyAvailable', true)
            ->set('emergencyInstructions', 'Burst pipes: transfer to my cell.')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(10, BusinessHour::where('organization_id', $org->id)->count()); // 5 weekdays × 2 shifts
        $this->assertSame(0, BusinessHour::where('organization_id', $org->id)->where('day_of_week', 0)->count());
        $this->assertTrue(BusinessProfile::where('organization_id', $org->id)->value('emergency_available'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'business_hours.updated']);
    }

    public function test_overlapping_and_empty_shifts_are_rejected(): void
    {
        [$owner, $org] = $this->business();
        $this->actingAs($owner);

        Livewire::test(Hours::class)
            ->set('days.2', [['opens' => '09:00', 'closes' => '13:00'], ['opens' => '12:00', 'closes' => '17:00'], ['opens' => '18:00', 'closes' => '18:00']])
            ->call('save')
            ->assertHasErrors(['days.2.1', 'days.2.2']);

        $this->assertSame(0, BusinessHour::where('organization_id', $org->id)->count(), 'nothing saved on error');
    }

    public function test_holidays_and_special_hours(): void
    {
        [$owner, $org] = $this->business();
        $this->actingAs($owner);

        $component = Livewire::test(Hours::class)
            ->set('newHoliday', ['date' => now()->addMonth()->toDateString(), 'name' => 'Thanksgiving', 'is_closed' => '1', 'opens' => '', 'closes' => ''])
            ->call('addHoliday')
            ->assertHasNoErrors()
            ->set('newHoliday', ['date' => now()->addMonth()->addDay()->toDateString(), 'name' => 'Black Friday', 'is_closed' => '0', 'opens' => '', 'closes' => ''])
            ->call('addHoliday')
            ->assertHasErrors(['newHoliday.opens', 'newHoliday.closes'])
            ->set('newHoliday.opens', '10:00')->set('newHoliday.closes', '14:00')
            ->call('addHoliday')
            ->assertHasNoErrors()
            ->assertSee('Thanksgiving')
            ->assertSee('Black Friday');

        $special = BusinessHoliday::where('name', 'Black Friday')->sole();
        $this->assertFalse($special->is_closed);

        $component->call('removeHoliday', $special->id);
        $this->assertNull(BusinessHoliday::find($special->id));
    }

    public function test_cannot_remove_another_businesss_holiday(): void
    {
        [$owner] = $this->business();
        [, $other] = $this->business();
        $theirs = BusinessHoliday::create(['organization_id' => $other->id, 'date' => now()->addWeek()->toDateString(), 'name' => 'Their day', 'is_closed' => true]);

        $this->actingAs($owner);
        $this->expectException(ModelNotFoundException::class);
        Livewire::test(Hours::class)->call('removeHoliday', $theirs->id);
    }

    public function test_api_returns_business_details_and_status(): void
    {
        [$owner, $org] = $this->business();
        $org->update(['timezone' => 'America/New_York']);
        BusinessProfile::create(['organization_id' => $org->id, 'description' => 'Plumbers', 'emergency_available' => true]);
        BusinessHour::create(['organization_id' => $org->id, 'day_of_week' => 1, 'opens_at' => '09:00', 'closes_at' => '17:00']);
        [, $other] = $this->business();
        BusinessProfile::create(['organization_id' => $other->id, 'description' => 'Other business']);

        Sanctum::actingAs($owner);
        $this->getJson('/api/v1/client/business')
            ->assertOk()
            ->assertJsonPath('data.business.id', $org->ulid)
            ->assertJsonPath('data.business.description', 'Plumbers')
            ->assertJsonPath('data.business.emergency_available', true)
            ->assertJsonPath('data.hours.Monday.0', '9 AM – 5 PM')
            ->assertJsonStructure(['success', 'data' => ['business', 'location', 'hours', 'upcoming_holidays', 'status' => ['open', 'label', 'until', 'next_open', 'reason']]]);
    }
}
