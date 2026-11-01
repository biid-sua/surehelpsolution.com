<?php

namespace Tests\Feature;

use App\Actions\Organizations\ProvisionUserTenancy;
use App\Livewire\Admin\Billing\Features as AdminFeatures;
use App\Livewire\Admin\Organizations\Features as OrganizationFeatures;
use App\Livewire\Client\Dashboard;
use App\Models\Automation;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\User;
use App\Services\Automation\AutomationEngine;
use App\Services\Billing\FeatureAccess;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Features switched on or off for everyone (D119): off is hidden everywhere, whatever the plan or a
 * business's own override; switching it back on picks up who had it and announces it as "New".
 */
class FeatureSwitchTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $owner;

    private Organization $business;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->travelTo(CarbonImmutable::parse('2026-11-02 15:00', 'UTC'));
        $this->admin = User::factory()->create(['role' => 'admin', 'is_active' => true, 'must_change_password' => false]);
        $this->admin->assignRole('super_admin');
        $this->enableTwoFactor($this->admin);
        $this->owner = User::factory()->create(['role' => 'client', 'is_active' => true, 'must_change_password' => false]);
        $this->business = app(ProvisionUserTenancy::class)->handle($this->owner);
        $this->business->forceFill(['name' => 'Rivera Plumbing', 'status' => 'active', 'setup_completed_at' => now()])->save();
        $this->enableTwoFactor($this->owner);
    }

    private function features(): FeatureAccess
    {
        app()->forgetScopedInstances();

        return app(FeatureAccess::class);
    }

    public function test_a_feature_switched_off_is_hidden_everywhere_and_comes_back_as_new(): void
    {
        $this->actingAs($this->owner)->get(route('app.reviews'))->assertOk();
        $this->get(route('app.dashboard'))->assertSee('Reviews')->assertDontSee('Just switched on for')->assertDontSee('title="New in SureHelp"', false);

        // Off: even a business switched on by hand doesn't have it.
        $this->actingAs($this->admin);
        Livewire::test(OrganizationFeatures::class, ['organizationId' => $this->business->id])->set('choices.reviews', 'on')->call('save');
        Livewire::test(AdminFeatures::class)->assertSee('Reviews')->set('active.reviews', false)->call('save')->assertDispatched('toast', type: 'success');
        $this->assertSame('off', $this->features()->mode('reviews'));
        $this->assertFalse($this->features()->allows($this->business->fresh(), 'reviews'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'features.modes_changed']);
        Livewire::test(OrganizationFeatures::class, ['organizationId' => $this->business->id])->assertSee('Off for everyone');

        $this->actingAs($this->owner);
        $this->get(route('app.reviews'))->assertNotFound();
        $this->get(route('app.dashboard'))->assertOk()->assertDontSee(route('app.reviews'));
        Sanctum::actingAs($this->owner);
        $this->getJson('/api/v1/me/capabilities')->assertOk()->assertJsonPath('data.business.features.reviews', false)
            ->assertJsonPath('data.business.new_features', []);

        // Back on: announced as new to the businesses that have it, for 30 days.
        $this->actingAs($this->admin);
        Livewire::test(AdminFeatures::class)->set('active.reviews', true)->assertSee('Show it as New')->call('save')
            ->assertDispatched('toast', message: 'Saved. Businesses that have it will see Reviews as new.');
        $this->assertTrue($this->features()->isNew('reviews'));
        Sanctum::actingAs($this->owner);
        $this->getJson('/api/v1/me/capabilities')->assertJsonPath('data.business.features.reviews', true)
            ->assertJsonPath('data.business.new_features', ['reviews']);

        $this->actingAs($this->owner);
        $this->get(route('app.dashboard'))->assertSee('Just switched on for Rivera Plumbing')->assertSee('Google reviews in one place')->assertSee(route('app.reviews'));
        Livewire::test(Dashboard::class)->call('dismissNewFeatures');
        $this->assertNotNull($this->owner->fresh()->features_seen_at);
        $this->get(route('app.dashboard'))->assertDontSee('Just switched on for')->assertSee('title="New in SureHelp"', false);   // the menu badge stays

        $this->travel(31)->days();
        $this->assertFalse($this->features()->isNew('reviews'));
    }

    public function test_switching_back_on_keeps_who_got_it_and_can_skip_the_announcement(): void
    {
        $this->actingAs($this->admin);
        Livewire::test(AdminFeatures::class)->set('audiences.estimates', 'plan')->call('save');
        Livewire::test(AdminFeatures::class)->set('active.estimates', false)->call('save');
        $this->assertSame(['off', 'plan'], [$this->features()->mode('estimates'), $this->features()->audiences()['estimates']]);

        Livewire::test(AdminFeatures::class)->assertSet('audiences.estimates', 'plan')
            ->set('active.estimates', true)->set('announce.estimates', false)->call('save')->assertDispatched('toast', message: 'Feature access saved.');
        $this->assertSame('plan', $this->features()->mode('estimates'));
        $this->assertFalse($this->features()->isNew('estimates'));

        // All off at once, and the list can be narrowed.
        Livewire::test(AdminFeatures::class)->call('setAll', false)->call('save')
            ->set('show', 'on')->assertSee('No features match.')
            ->set('show', 'off')->set('find', 'quickbooks')->assertSee('QuickBooks Online')->assertDontSee('Housecall Pro');
        $this->assertNotContains(true, array_map(fn ($k) => $this->features()->allows($this->business->fresh(), $k), array_keys($this->features()->catalog())));
        Livewire::test(AdminFeatures::class)->set('audiences.reviews', 'nobody')->call('save')->assertHasErrors('audiences.reviews');
    }

    public function test_switched_off_automations_stop_running_and_their_page_is_gone(): void
    {
        $ana = Customer::create(['organization_id' => $this->business->id, 'first_name' => 'Ana', 'phone' => '5125550147']);
        Automation::create(['organization_id' => $this->business->id, 'name' => 'Welcome', 'trigger' => 'customer_created', 'is_active' => true,
            'delay_minutes' => 0, 'action' => 'create_task', 'action_config' => ['title' => 'Say hello']]);
        $this->assertSame(1, app(AutomationEngine::class)->fire('customer_created', $ana));
        $this->actingAs($this->owner)->get(route('app.business.automations'))->assertOk();
        $this->get(route('app.business.profile'))->assertSee(route('app.business.automations'));

        $this->features()->configure(['automations' => false], []);
        $this->features();
        $this->assertSame(0, app(AutomationEngine::class)->fire('customer_created', $ana));
        $this->assertSame(['done' => 0, 'skipped' => 1, 'failed' => 0], app(AutomationEngine::class)->runDue());
        $this->get(route('app.business.automations'))->assertNotFound();
        $this->get(route('app.business.profile'))->assertOk()->assertDontSee(route('app.business.automations'));
    }

    public function test_pages_that_explain_a_feature_are_gone_while_it_is_off(): void
    {
        $this->actingAs($this->owner);
        foreach (['app.seo', 'app.payments', 'app.business.branding'] as $route) {
            $this->get(route($route))->assertOk();
        }
        $this->get(route('app.dashboard'))->assertSee(route('app.seo'))->assertSee(route('app.payments'));

        $this->features()->configure(['seo_service' => false, 'customer_payments' => false, 'white_label' => false], []);
        foreach (['app.seo', 'app.payments', 'app.business.branding'] as $route) {
            $this->get(route($route))->assertNotFound();
        }
        $this->get(route('app.dashboard'))->assertDontSee(route('app.seo'))->assertDontSee(route('app.payments'));
        $this->get(route('app.business.profile'))->assertDontSee(route('app.business.branding'));
    }

    public function test_only_staff_with_billing_access_change_it(): void
    {
        $this->actingAs($this->owner)->get(route('admin.billing'))->assertForbidden();
        Livewire::actingAs($this->owner)->test(AdminFeatures::class)->assertForbidden();

        // Support staff can look but not change anything.
        $support = User::factory()->create(['role' => 'admin', 'is_active' => true, 'must_change_password' => false]);
        $support->syncRoles(['support_agent']);
        $this->enableTwoFactor($support);
        Livewire::actingAs($support)->test(AdminFeatures::class)->assertOk()
            ->set('active.reviews', false)->call('save')->assertForbidden();
        Livewire::actingAs($support)->test(AdminFeatures::class)->call('setAll', false)->assertForbidden();
        $this->assertSame('everyone', $this->features()->mode('reviews'));
    }
}
