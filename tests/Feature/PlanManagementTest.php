<?php

namespace Tests\Feature;

use App\Actions\Billing\ManageSubscription;
use App\Actions\Organizations\ProvisionUserTenancy;
use App\Livewire\Admin\Billing\Index as AdminBilling;
use App\Livewire\Client\Billing\Index as ClientBilling;
use App\Models\Plan;
use App\Models\User;
use App\Services\Sales\SalesPlans;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Plans managed in Admin › Billing › Plans fill the website's pricing section, sign-up, Billing and
 * the sales chat (D98); plans nobody has been on can be deleted.
 */
class PlanManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->admin = User::factory()->create(['role' => 'admin', 'is_active' => true, 'must_change_password' => false]);
    }

    private function createPlan(array $fields): Plan
    {
        $form = Livewire::actingAs($this->admin)->test(AdminBilling::class)->set('tab', 'plans');
        foreach ($fields as $key => $value) {
            $form->set('plan.'.$key, $value);
        }
        $form->call('savePlan')->assertHasNoErrors();

        return Plan::query()->latest('id')->firstOrFail();
    }

    public function test_a_new_plan_with_its_features_appears_on_the_website(): void
    {
        $plan = $this->createPlan([
            'name' => 'Professional', 'price' => '400', 'annual_price' => '360', 'trial_days' => '14', 'tagline' => 'Best for home services',
            'description' => "150 minutes a month included\nCustom call scripts\nMonthly reports\nPriority notifications\nAnalytics\nEnhanced client portal",
            'features' => ['calendar_sync', 'reviews'], 'is_featured' => true, 'extra_call' => '1.50', 'sort_order' => '2',
        ]);

        $this->assertSame(40000, $plan->price_cents);
        $this->assertSame(36000, $plan->annual_monthly_cents);
        $this->assertSame(['calendar_sync', 'reviews'], $plan->features);
        $this->assertTrue($plan->is_featured);

        $this->get(route('home'))->assertOk()
            ->assertSee('Professional')->assertSee('Best for home services')
            ->assertSeeInOrder(['400', '/month', '360'])
            ->assertSee('Save 10%')->assertSee('MOST POPULAR')
            ->assertSee('Enhanced client portal')->assertSee('Extra calls: $1.50/call')
            ->assertSee(route('signup', ['plan' => $plan->slug]), false);
    }

    public function test_editing_a_price_changes_the_website_and_the_sales_chat(): void
    {
        $plan = $this->createPlan(['name' => 'Essential', 'price' => '300', 'description' => '100 minutes a month included']);

        Livewire::actingAs($this->admin)->test(AdminBilling::class)->set('tab', 'plans')
            ->call('editPlan', $plan->id)->assertSet('plan.name', 'Essential')
            ->set('plan.price', '325')->call('savePlan')->assertHasNoErrors();

        $this->get(route('home'))->assertSee('325')->assertDontSee('Save 10%');
        $sales = app(SalesPlans::class)->all();
        $this->assertSame('Essential', $sales[0]['name']);
        $this->assertSame(325, $sales[0]['monthly']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'plan.updated']);
    }

    public function test_a_plan_priced_on_request_is_shown_but_cannot_be_chosen_online(): void
    {
        $this->createPlan(['name' => 'Starter', 'price' => '99', 'trial_days' => '14']);
        $enterprise = $this->createPlan(['name' => 'Enterprise', 'price' => '0', 'contact_only' => true, 'tagline' => 'Best for franchises']);

        $this->get(route('home'))->assertSee('Enterprise')->assertSee('Custom')->assertSee('Talk to us');
        auth()->logout();
        $this->get(route('signup'))->assertOk()->assertSee('Starter')->assertDontSee('Enterprise');

        $owner = User::factory()->create(['role' => 'client', 'is_active' => true, 'must_change_password' => false]);
        app(ProvisionUserTenancy::class)->handle($owner);
        Livewire::actingAs($owner)->test(ClientBilling::class)->assertSee('Starter')->assertDontSee('Enterprise');
        $this->expectException(ModelNotFoundException::class);
        Livewire::actingAs($owner)->test(ClientBilling::class)->call('requestPlan', $enterprise->id);
    }

    public function test_hidden_or_retired_plans_stay_off_the_website(): void
    {
        $this->createPlan(['name' => 'Secret Deal', 'price' => '199', 'is_public' => false]);
        $this->createPlan(['name' => 'Old Plan', 'price' => '149', 'is_active' => false]);

        $this->get(route('home'))->assertOk()->assertDontSee('Secret Deal')->assertDontSee('Old Plan')->assertSee('Talk to us');
    }

    public function test_only_plans_nobody_has_been_on_can_be_deleted(): void
    {
        $unused = $this->createPlan(['name' => 'Trial Balloon', 'price' => '50']);
        $used = $this->createPlan(['name' => 'Receptionist', 'price' => '299']);
        $owner = User::factory()->create(['role' => 'client', 'is_active' => true]);
        app(ManageSubscription::class)->subscribe(app(ProvisionUserTenancy::class)->handle($owner), $used, $this->admin);

        Livewire::actingAs($this->admin)->test(AdminBilling::class)->set('tab', 'plans')
            ->assertSeeHtml('wire:click="deletePlan('.$unused->id.')"')->assertDontSeeHtml('wire:click="deletePlan('.$used->id.')"')
            ->call('deletePlan', $unused->id)->assertDispatched('toast', type: 'success')
            ->call('deletePlan', $used->id)->assertDispatched('toast', type: 'warning');

        $this->assertModelMissing($unused);
        $this->assertModelExists($used);
        $this->assertDatabaseHas('audit_logs', ['action' => 'plan.deleted']);
    }

    public function test_people_without_billing_access_cannot_change_plans(): void
    {
        $plan = $this->createPlan(['name' => 'Essential', 'price' => '300']);
        $agent = User::factory()->create(['role' => 'agent', 'is_active' => true, 'must_change_password' => false]);
        $client = User::factory()->create(['role' => 'client', 'is_active' => true, 'must_change_password' => false]);

        foreach ([$agent, $client] as $user) {
            $this->actingAs($user)->get(route('admin.billing', ['tab' => 'plans']))->assertForbidden();
            Livewire::actingAs($user)->test(AdminBilling::class)->assertForbidden();
        }
        $this->assertModelExists($plan);
    }
}
