<?php

namespace Tests\Feature;

use App\Actions\Organizations\ProvisionUserTenancy;
use App\Enums\ServicePriceType;
use App\Livewire\Client\Business\Services;
use App\Models\BusinessService;
use App\Models\Organization;
use App\Models\User;
use App\Support\Money;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * P2-2 — services (spec §11) and money handling (spec §75).
 */
class BusinessServicesTest extends TestCase
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

    public function test_money_is_parsed_and_formatted_without_floats(): void
    {
        $this->assertSame(15000, Money::parse('150'));
        $this->assertSame(15050, Money::parse('150.5'));
        $this->assertSame(125099, Money::parse('$1,250.99'));
        $this->assertSame(1, Money::parse('0.01'));
        $this->assertSame('$150', Money::format(15000));
        $this->assertSame('$1,250.99', Money::format(125099));
        $this->assertSame('$0.07', Money::format(7));
        $this->assertSame('149.90', Money::toInput(14990));
        $this->assertSame('150', Money::toInput(15000));

        foreach (['-5', '1.234', 'abc', '', '10000000'] as $bad) {
            try {
                Money::parse($bad);
                $this->fail("'{$bad}' should be rejected");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_price_labels_follow_price_type(): void
    {
        $this->assertSame('$150', ServicePriceType::Fixed->display(15000, 'USD'));
        $this->assertSame('From $99.50', ServicePriceType::StartingFrom->display(9950, 'USD'));
        $this->assertSame('Quote required', ServicePriceType::QuoteRequired->display(null, 'USD'));
        $this->assertSame('Ask our team for pricing', ServicePriceType::Hidden->display(15000, 'USD'));
    }

    public function test_owner_creates_edits_deactivates_and_removes_a_service(): void
    {
        [$owner, $org] = $this->business();
        $this->actingAs($owner)->get(route('app.business.services'))->assertOk()->assertSee('No services yet');

        Livewire::test(Services::class)
            ->call('create')
            ->set('form.name', 'Water heater repair')
            ->set('form.category', 'Repairs')
            ->set('form.duration_minutes', '90')
            ->set('form.buffer_minutes', '30')
            ->set('form.price_type', 'starting_from')
            ->set('form.price', '150')
            ->set('form.required_fields', ['phone', 'address', 'details'])
            ->set('form.agent_instructions', 'Ask tank size.')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('editing', false)
            ->assertSee('From $150');

        $service = BusinessService::where('organization_id', $org->id)->sole();
        $this->assertSame(15000, $service->price_cents);
        $this->assertSame('1 h 30 min', $service->durationLabel());
        $this->assertSame(['phone', 'address', 'details'], $service->required_fields);
        $this->assertDatabaseHas('audit_logs', ['action' => 'service.created', 'organization_id' => $org->id]);

        Livewire::test(Services::class)
            ->call('edit', $service->id)
            ->assertSet('form.price', '150')
            ->set('form.price_type', 'fixed')
            ->set('form.price', '175.50')
            ->call('save')
            ->call('toggleActive', $service->id);

        $service->refresh();
        $this->assertSame(17550, $service->price_cents);
        $this->assertFalse($service->is_active);

        Livewire::test(Services::class)->call('delete', $service->id);
        $this->assertSoftDeleted($service);
    }

    public function test_validation(): void
    {
        [$owner, $org] = $this->business();
        BusinessService::create(['organization_id' => $org->id, 'name' => 'Drain cleaning', 'price_type' => 'quote_required']);
        $this->actingAs($owner);

        Livewire::test(Services::class)
            ->call('create')
            ->set('form.name', 'Drain cleaning')
            ->set('form.duration_minutes', '2')
            ->set('form.price_type', 'fixed')
            ->set('form.price', 'twelve')
            ->set('form.required_fields', ['phone', 'shoe_size'])
            ->call('save')
            ->assertHasErrors(['form.name', 'form.duration_minutes', 'form.required_fields.1']);

        Livewire::test(Services::class)
            ->call('create')
            ->set('form.name', 'Inspection')
            ->set('form.price_type', 'fixed')
            ->set('form.price', 'twelve')
            ->call('save')
            ->assertHasErrors('form.price');
    }

    public function test_quote_required_service_stores_no_amount(): void
    {
        [$owner, $org] = $this->business();
        $this->actingAs($owner);

        Livewire::test(Services::class)->call('create')
            ->set('form.name', 'Remodel consultation')
            ->set('form.price_type', 'quote_required')
            ->set('form.price', '999')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertNull(BusinessService::where('organization_id', $org->id)->sole()->price_cents);
    }

    public function test_manager_manages_services_staff_cannot_open(): void
    {
        [, $org] = $this->business();
        $manager = $this->member($org, 'manager');
        $staff = $this->member($org, 'staff');

        $this->actingAs($manager);
        Livewire::test(Services::class)->call('create')->set('form.name', 'Inspection')->call('save')->assertHasNoErrors();
        $this->assertSame(1, BusinessService::where('organization_id', $org->id)->count());

        $this->actingAs($staff)->get(route('app.business.services'))->assertForbidden();
    }

    public function test_services_are_isolated_per_business(): void
    {
        [$owner] = $this->business();
        [, $other] = $this->business();
        $theirs = BusinessService::create(['organization_id' => $other->id, 'name' => 'Secret service', 'price_type' => 'hidden']);

        $this->actingAs($owner);
        Livewire::test(Services::class)->assertDontSee('Secret service');

        $this->expectException(ModelNotFoundException::class);
        Livewire::test(Services::class)->call('delete', $theirs->id);
    }

    public function test_api_lists_active_services(): void
    {
        [$owner, $org] = $this->business();
        BusinessService::create(['organization_id' => $org->id, 'name' => 'Repair', 'price_type' => 'fixed', 'price_cents' => 15000, 'is_active' => true]);
        BusinessService::create(['organization_id' => $org->id, 'name' => 'Old offer', 'price_type' => 'quote_required', 'is_active' => false]);

        Sanctum::actingAs($owner);
        $this->getJson('/api/v1/client/services')
            ->assertOk()
            ->assertJsonCount(1, 'data.services')
            ->assertJsonPath('data.services.0.price_label', '$150')
            ->assertJsonPath('data.services.0.price_cents', 15000);

        $this->getJson('/api/v1/client/services?include_inactive=1')->assertJsonCount(2, 'data.services');
    }
}
