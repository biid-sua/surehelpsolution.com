<?php

namespace Tests\Feature;

use App\Actions\Organizations\ProvisionUserTenancy;
use App\Livewire\Admin\Organizations\Show as AdminOrganization;
use App\Livewire\Client\Customers\Index as CustomersIndex;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Multi-location (D89): one person, several businesses, a switcher and an overview.
 */
class MultiLocationTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Organization $north;

    private Organization $south;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-12 15:00', 'UTC'));
        $this->owner = User::factory()->create(['role' => 'client', 'is_active' => true, 'must_change_password' => false, 'email' => 'maria@rivera.test']);
        $this->north = app(ProvisionUserTenancy::class)->handle($this->owner);
        $this->north->forceFill(['name' => 'Rivera North', 'status' => 'active', 'setup_completed_at' => now()])->save();
        $other = User::factory()->create(['role' => 'client', 'is_active' => true, 'must_change_password' => false]);
        $this->south = app(ProvisionUserTenancy::class)->handle($other);
        $this->south->forceFill(['name' => 'Rivera South', 'status' => 'active', 'setup_completed_at' => now()])->save();
        Customer::create(['organization_id' => $this->north->id, 'first_name' => 'Nora', 'phone' => '5125550101']);
        Customer::create(['organization_id' => $this->south->id, 'first_name' => 'Sam', 'phone' => '5125550102']);
    }

    private function link(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true, 'must_change_password' => false]);
        $this->actingAs($admin);
        Livewire::test(AdminOrganization::class, ['organization' => $this->south])
            ->set('memberEmail', 'nobody@example.test')->call('addMember')->assertHasErrors('memberEmail')
            ->set('memberEmail', 'maria@rivera.test')->set('memberRole', 'owner')->call('addMember')->assertHasNoErrors();
        $this->assertSame('owner', $this->owner->fresh()->organizationRole($this->south));
    }

    public function test_an_owner_switches_between_locations(): void
    {
        $this->actingAs($this->owner);
        $this->get(route('app.dashboard'))->assertOk()->assertDontSee('All locations overview');
        $this->get(route('app.locations'))->assertNotFound();
        $this->post(route('app.switch', $this->south->ulid))->assertNotFound();

        $this->link();
        $this->actingAs($this->owner);
        $this->get(route('app.dashboard'))->assertOk()->assertSee('2 locations')->assertSee('All locations overview');
        Livewire::test(CustomersIndex::class)->assertSee('Nora')->assertDontSee('Sam');

        $this->post(route('app.switch', $this->south->ulid))->assertRedirect(route('app.dashboard'));
        $this->assertSame($this->south->ulid, session(User::CURRENT_ORGANIZATION));
        Livewire::test(CustomersIndex::class)->assertSee('Sam')->assertDontSee('Nora');

        $this->get(route('app.locations'))->assertOk()->assertSee('Rivera North')->assertSee('Rivera South')->assertSee('All locations');
    }

    public function test_the_app_picks_a_location_with_a_header(): void
    {
        $this->link();
        Sanctum::actingAs($this->owner);
        $this->getJson('/api/v1/client/customers')->assertOk()->assertJsonPath('data.customers.0.first_name', 'Nora');
        $this->getJson('/api/v1/client/customers', ['X-Organization' => $this->south->ulid])->assertOk()->assertJsonPath('data.customers.0.first_name', 'Sam');

        // A business they don't belong to is ignored.
        $elsewhere = app(ProvisionUserTenancy::class)->handle(User::factory()->create(['role' => 'client', 'is_active' => true]));
        $this->getJson('/api/v1/client/customers', ['X-Organization' => $elsewhere->ulid])->assertOk()->assertJsonPath('data.customers.0.first_name', 'Nora');
    }
}
