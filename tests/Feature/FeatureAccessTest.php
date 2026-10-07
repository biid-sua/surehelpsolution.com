<?php

namespace Tests\Feature;

use App\Actions\Appointments\BookAppointment;
use App\Actions\Billing\ManageSubscription;
use App\Actions\Organizations\ProvisionUserTenancy;
use App\Livewire\Admin\Billing\Features as AdminFeatures;
use App\Livewire\Admin\Organizations\Features as OrganizationFeatures;
use App\Models\Addon;
use App\Models\CalendarConnection;
use App\Models\ChatWidget;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\OrganizationAddon;
use App\Models\Plan;
use App\Models\User;
use App\Notifications\CustomerEmail;
use App\Services\Billing\FeatureAccess;
use App\Services\Calendar\CalendarSync;
use App\Services\Messages\CustomerMessages;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Paid features follow the plan, add-ons and per-business overrides (spec §30–31, FND-18, D46).
 */
class FeatureAccessTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
    }

    /** @return array{0: User, 1: Organization} */
    private function business(): array
    {
        $owner = User::factory()->create(['role' => 'client', 'is_active' => true, 'must_change_password' => false]);
        $org = app(ProvisionUserTenancy::class)->handle($owner);
        $org->forceFill(['name' => 'Rivera Plumbing', 'setup_completed_at' => now()])->save();

        return [$owner, $org->fresh()];
    }

    private function restrict(string ...$features): void
    {
        $this->actingAs($this->admin);
        $test = Livewire::test(AdminFeatures::class);
        foreach ($features as $feature) {
            $test->set("modes.$feature", 'plan');
        }
        $test->call('save');
        app()->forgetScopedInstances();
    }

    public function test_everything_is_open_until_staff_restrict_a_feature_to_plans(): void
    {
        [$owner, $org] = $this->business();
        $this->actingAs($owner)->get(route('app.business.calendars'))->assertOk();
        $this->get(route('app.website'))->assertOk()->assertDontSee('Upgrade');

        $this->restrict('calendar_sync', 'website_tools');
        $this->assertSame('plan', app(FeatureAccess::class)->mode('calendar_sync'));

        $this->actingAs($owner)->get(route('app.business.calendars'))->assertRedirect(route('app.billing'))
            ->assertSessionHas('feature_locked', fn ($m) => str_contains($m, 'Google / Outlook calendar sync isn\'t included in your plan'));
        $this->get(route('app.billing'))->assertSee('Not in your plan');
        $this->get(route('app.dashboard'))->assertSee('Upgrade');
        $this->get(route('app.integrations.calendar.connect', 'google'))->assertRedirect(route('app.billing'));

        // Staff without billing access land on the dashboard instead.
        $staff = User::factory()->create(['role' => 'client', 'is_active' => true]);
        $org->members()->attach($staff->id, ['role' => 'manager', 'status' => 'active']);
        $manager = $staff;
        $this->actingAs($manager)->get(route('app.website'))->assertRedirect();

        // A plan that includes the key unlocks it; so does an add-on with that key.
        $plan = Plan::create(['slug' => 'pro', 'name' => 'Pro', 'price_cents' => 49900, 'interval' => 'month', 'features' => ['calendar_sync']]);
        app(ManageSubscription::class)->subscribe($org, $plan, $this->admin, CarbonImmutable::today(), false);
        app()->forgetScopedInstances();
        $this->actingAs($owner)->get(route('app.business.calendars'))->assertOk();
        $this->get(route('app.website'))->assertRedirect(route('app.billing'));

        $addon = Addon::create(['slug' => 'website_tools', 'name' => 'Website tools', 'price_cents' => 2900]);
        OrganizationAddon::create(['organization_id' => $org->id, 'addon_id' => $addon->id, 'status' => 'active', 'price_cents' => 2900, 'started_on' => now()->toDateString()]);
        app()->forgetScopedInstances();
        $this->get(route('app.website'))->assertOk();
    }

    public function test_staff_override_a_feature_for_one_business(): void
    {
        [$owner, $org] = $this->business();
        $this->restrict('social_publishing');

        $this->actingAs($this->admin);
        Livewire::test(OrganizationFeatures::class, ['organizationId' => $org->id])->assertSee('Not included')
            ->set('choices.social_publishing', 'on')->set('choices.customer_emails', 'off')->call('save');
        app()->forgetScopedInstances();
        $features = app(FeatureAccess::class);
        $this->assertTrue($features->allows($org, 'social_publishing'));
        $this->assertFalse($features->allows($org, 'customer_emails'), 'even though it is open to everyone');
        $this->actingAs($owner)->get(route('app.social.index'))->assertOk();

        // Back to the plan.
        $this->actingAs($this->admin);
        Livewire::test(OrganizationFeatures::class, ['organizationId' => $org->id])->set('choices.social_publishing', 'plan')->call('save');
        app()->forgetScopedInstances();
        $this->assertFalse(app(FeatureAccess::class)->allows($org, 'social_publishing'));

        // Support staff can look but not change.
        $support = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $support->syncRoles(['support_agent']);
        $this->actingAs($support);
        Livewire::test(OrganizationFeatures::class, ['organizationId' => $org->id])->assertDontSee('Save')->call('save')->assertForbidden();
        Livewire::test(AdminFeatures::class)->call('save')->assertForbidden();
    }

    public function test_background_work_stops_without_the_feature(): void
    {
        [, $org] = $this->business();
        $customer = Customer::create(['organization_id' => $org->id, 'first_name' => 'Ana', 'email' => 'ana@example.test']);
        $this->restrict('customer_emails', 'calendar_sync', 'website_tools');

        // No appointment emails to customers.
        $appointment = app(BookAppointment::class)->handle($org, ['starts_at' => now()->addDays(2)->setTime(10, 0), 'title' => 'Visit', 'customer_id' => $customer->id]);
        $this->assertFalse(app(CustomerMessages::class)->send($appointment, 'appointment_confirmed'));
        Notification::assertNothingSentTo(new AnonymousNotifiable, CustomerEmail::class);

        // Connected calendars stop syncing.
        $connection = CalendarConnection::create(['organization_id' => $org->id, 'provider' => 'google', 'account_email' => 'a@b.test', 'access_token' => 'x',
            'refresh_token' => 'y', 'token_expires_at' => now()->addHour(), 'calendars' => [], 'busy_calendar_ids' => ['c']]);
        $this->assertSame(0, app(CalendarSync::class)->pullBusy($connection));

        // The website snippet keeps chat but drops booking, calls and the form.
        $widget = ChatWidget::for($org);
        $widget->update(['features' => ['chat' => true, 'booking' => true, 'call' => true, 'lead' => true]]);
        $this->call('GET', '/api/chat/'.$widget->public_key.'/config', [], [], [], ['HTTP_ORIGIN' => 'https://rivera.test'])->assertOk()
            ->assertJsonPath('features', ['chat' => true, 'booking' => false, 'call' => false, 'lead' => false]);
        $this->call('POST', '/api/chat/'.$widget->public_key.'/lead', [], [], [], ['HTTP_ORIGIN' => 'https://rivera.test', 'CONTENT_TYPE' => 'text/plain'],
            json_encode(['name' => 'Ana', 'phone' => '5125550147', 'message' => 'Hi']))->assertNotFound();
    }
}
