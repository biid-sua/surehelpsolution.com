<?php

namespace Tests\Feature;

use App\Actions\Organizations\ProvisionUserTenancy;
use App\Enums\AppointmentStatus;
use App\Enums\ServicePriceType;
use App\Exceptions\AppAuthorizationLost;
use App\Exceptions\AppRequestFailed;
use App\Livewire\Client\Business\ConnectedApps;
use App\Models\AppConnection;
use App\Models\Appointment;
use App\Models\BusinessService;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\User;
use App\Services\Apps\HousecallPro\FakeHousecallProClient;
use App\Services\Apps\HousecallPro\HousecallPro;
use App\Services\Apps\HousecallPro\HousecallProClient;
use App\Services\Apps\HousecallPro\RestHousecallProClient;
use App\Services\Billing\FeatureAccess;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Housecall Pro (D112): a business pastes its API key; bookings SureHelp makes go onto its schedule as
 * jobs at the customer's address, Housecall Pro customers come into SureHelp, and nothing goes twice.
 */
class HousecallProTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Organization $business;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->travelTo(CarbonImmutable::parse('2026-10-14 15:00', 'UTC'));
        $this->owner = User::factory()->create(['role' => 'client', 'is_active' => true, 'must_change_password' => false]);
        $this->business = app(ProvisionUserTenancy::class)->handle($this->owner);
        $this->business->forceFill(['name' => 'Rivera Plumbing', 'timezone' => 'America/Chicago', 'status' => 'active', 'setup_completed_at' => now()])->save();
        $this->enableTwoFactor($this->owner);
    }

    private function fake(): FakeHousecallProClient
    {
        /** @var FakeHousecallProClient */
        return app(HousecallProClient::class);
    }

    private function connect(): AppConnection
    {
        $this->actingAs($this->owner);
        Livewire::test(ConnectedApps::class)->set('housecallKey', 'hcp_test_key')->call('connectHousecallPro')->assertHasNoErrors()->assertDispatched('toast', type: 'success');

        return AppConnection::query()->forOrganization($this->business)->where('provider', 'housecall_pro')->sole();
    }

    private function book(Customer $customer, array $attributes = []): Appointment
    {
        $at = CarbonImmutable::parse('2026-10-16 19:00', 'UTC');

        return Appointment::create(array_merge(['organization_id' => $this->business->id, 'customer_id' => $customer->id, 'title' => 'Leak repair', 'timezone' => 'America/Chicago',
            'starts_at' => $at, 'ends_at' => $at->addHours(2), 'blocked_until' => $at->addHours(2), 'status' => AppointmentStatus::Confirmed, 'source' => 'agent'], $attributes));
    }

    public function test_pasting_the_key_connects_and_brings_in_customers(): void
    {
        $this->actingAs($this->owner);
        Livewire::test(ConnectedApps::class)->set('housecallKey', 'not-the-right-key')->call('connectHousecallPro')->assertHasErrors('housecallKey');
        $this->assertDatabaseCount('app_connections', 0);

        $ana = Customer::create(['organization_id' => $this->business->id, 'first_name' => 'Ana', 'phone' => '5125550147']);
        $this->fake()->addCustomer(['first_name' => 'Ana', 'last_name' => 'Lopez', 'phone' => '+15125550147', 'email' => 'ana@lopez.test',
            'addresses' => [['id' => 'adr_x', 'street' => '12 Oak St', 'city' => 'Austin', 'state' => 'TX', 'zip' => '78704']]]);
        $this->fake()->addCustomer(['first_name' => 'Ben', 'last_name' => 'Ray', 'email' => 'ben@ray.test']);
        $this->fake()->addCustomer(['company' => 'No Contact Co']);

        $connection = $this->connect();
        $this->assertSame(['Rivera Plumbing', 'co_1', 'hcp_test_key'], [$connection->account_name, $connection->account_id, $connection->access_token]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'apps.housecall_pro_connected']);
        $this->assertStringNotContainsString('hcp_test_key', (string) DB::table('app_connections')->value('access_token'), 'kept encrypted');

        $ana->refresh();
        $this->assertSame(['Lopez', 'ana@lopez.test', '12 Oak St'], [$ana->last_name, $ana->email, $ana->address_line1]);
        $this->assertSame('housecall_pro', Customer::query()->forOrganization($this->business)->where('email', 'ben@ray.test')->sole()->source);
        $this->assertSame(2, Customer::query()->forOrganization($this->business)->count());

        // Each hourly run reads them again: nothing doubles.
        $this->artisan('apps:housecall-pro-pull')->assertSuccessful();
        $this->assertSame(2, Customer::query()->forOrganization($this->business)->count());
    }

    public function test_a_booking_goes_onto_the_schedule_once_as_a_job(): void
    {
        $connection = $this->connect();
        $ana = Customer::create(['organization_id' => $this->business->id, 'first_name' => 'Ana', 'last_name' => 'Lopez', 'phone' => '5125550147', 'email' => 'ana@lopez.test',
            'address_line1' => '12 Oak St', 'city' => 'Austin', 'state' => 'TX', 'postal_code' => '78704']);
        $this->assertSame([], $this->fake()->customers, 'new customers stay out by default');

        $service = BusinessService::create(['organization_id' => $this->business->id, 'name' => 'Water heater install', 'duration_minutes' => 120,
            'price_type' => ServicePriceType::Fixed, 'price_cents' => 140000]);
        $appointment = $this->book($ana, ['service_id' => $service->id]);

        $customer = array_values($this->fake()->customers)[0];
        $this->assertSame(['Ana', 'Lopez', 'ana@lopez.test', '+15125550147'], [$customer['first_name'], $customer['last_name'], $customer['email'], $customer['phone']]);
        $job = $this->fake()->jobs[0];
        $this->assertSame([$customer['id'], $customer['addresses'][0]['id']], [$job['customer_id'], $job['address_id']]);
        $this->assertSame(['scheduled_start' => '2026-10-16T19:00:00Z', 'scheduled_end' => '2026-10-16T21:00:00Z', 'arrival_window' => 0], $job['schedule']);
        $this->assertSame([['name' => 'Water heater install', 'quantity' => 1, 'unit_price' => 140000]], $job['line_items']);

        // Again: nothing doubles. A booking at another address adds that address to the customer.
        app(HousecallPro::class)->sendBooking($connection->fresh(), $appointment);
        $this->book($ana, ['address_line1' => '400 Elm Ave', 'city' => 'Austin']);
        $this->assertCount(2, $this->fake()->jobs);
        $this->assertCount(1, $this->fake()->customers);
        $this->assertCount(2, $this->fake()->customer('hcp_test_key', $customer['id'])['addresses']);
        $this->assertSame(['job_sent', 'job_sent', 'customer_sent'], $connection->entries()->latest('id')->pluck('action')->all());
    }

    public function test_bookings_without_an_address_and_refusals_are_recorded(): void
    {
        $connection = $this->connect();
        $this->fake()->addCustomer(['first_name' => 'Cal', 'last_name' => 'Diaz', 'phone' => '+15125550160']);
        $cal = Customer::create(['organization_id' => $this->business->id, 'first_name' => 'Cal', 'phone' => '512-555-0160']);

        $this->book($cal);
        $this->assertSame([], $this->fake()->jobs);
        $this->assertStringContainsString('need an address', (string) $connection->entries()->latest('id')->value('error'));

        // Matched by phone, not added again; Housecall Pro refusing the job is written down.
        $client = new class extends FakeHousecallProClient
        {
            public function createJob(string $key, array $job): string
            {
                throw new AppRequestFailed('schedule.scheduled_start is invalid', '422');
            }
        };
        $client->customers = $this->fake()->customers;
        $this->app->instance(HousecallProClient::class, $client);
        $this->book($cal, ['address_line1' => '9 Pine Rd']);
        $this->assertCount(1, $client->customers);
        $this->assertSame('schedule.scheduled_start is invalid', $connection->fresh()->last_error);
    }

    public function test_a_revoked_key_demo_businesses_and_the_feature_switch(): void
    {
        $connection = $this->connect();
        $ana = Customer::create(['organization_id' => $this->business->id, 'first_name' => 'Ana', 'phone' => '5125550147', 'address_line1' => '12 Oak St']);

        // The key was deleted in Housecall Pro: the owner pastes a new one.
        $this->fake()->key = 'hcp_new_key_123';
        $this->book($ana);
        $this->assertSame(AppConnection::NEEDS_REAUTH, $connection->fresh()->status);
        $this->get(route('app.business.apps'))->assertOk()->assertSee('Paste a new Housecall Pro API key');
        Livewire::test(ConnectedApps::class)->set('housecallKey', 'hcp_new_key_123')->call('connectHousecallPro')->assertHasNoErrors();
        $this->assertSame(AppConnection::ACTIVE, $connection->fresh()->status);

        $this->business->forceFill(['is_demo' => true])->save();
        $this->book($ana);
        $this->assertSame([], $this->fake()->jobs);
        $this->business->forceFill(['is_demo' => false])->save();

        app(FeatureAccess::class)->saveModes(['housecall_pro' => FeatureAccess::SELECTED]);
        app()->forgetScopedInstances();
        $this->get(route('app.business.apps'))->assertOk()->assertDontSee('Housecall Pro API key');
        Livewire::test(ConnectedApps::class)->set('housecallKey', 'hcp_new_key_123')->call('connectHousecallPro')->assertNotFound();
        $this->book($ana);
        $this->assertSame([], $this->fake()->jobs);
    }

    public function test_other_businesses_staff_and_disconnecting(): void
    {
        $connection = $this->connect();

        $otherOwner = User::factory()->create(['role' => 'client', 'is_active' => true, 'must_change_password' => false]);
        app(ProvisionUserTenancy::class)->handle($otherOwner);
        $this->enableTwoFactor($otherOwner);
        $this->actingAs($otherOwner)->get(route('app.business.apps'))->assertOk()->assertSee('Your Housecall Pro API key')->assertDontSee('Send bookings to Housecall Pro');
        Livewire::test(ConnectedApps::class)->call('disconnect', 'housecall_pro')->assertNotFound();

        $staff = User::factory()->create(['role' => 'client', 'is_active' => true, 'must_change_password' => false]);
        $this->business->members()->attach($staff->id, ['role' => 'staff', 'status' => 'active']);
        $this->enableTwoFactor($staff);
        $this->actingAs($staff);
        Livewire::test(ConnectedApps::class)->assertForbidden();

        $this->actingAs($this->owner);
        Livewire::test(ConnectedApps::class)->call('disconnect', 'housecall_pro')->assertRedirect(route('app.business.apps'));
        $this->assertModelMissing($connection);
        $this->assertDatabaseHas('audit_logs', ['action' => 'apps.housecall_pro_disconnected']);
    }

    public function test_the_rest_client_speaks_the_housecall_pro_api(): void
    {
        Http::fake([
            'api.housecallpro.com/company' => Http::response(['id' => 'co_9', 'name' => 'Rivera Plumbing']),
            'api.housecallpro.com/customers?*' => Http::response(['customers' => [['id' => 'cus_1', 'first_name' => 'Ana', 'home_number' => '5125550147',
                'addresses' => [['id' => 'adr_1', 'street' => '12 Oak St', 'zip' => '78704']]]], 'total_pages' => 3]),
            'api.housecallpro.com/jobs' => Http::response(['error' => ['message' => 'address_id is invalid']], 422),
            'api.housecallpro.com/customers/cus_gone' => Http::response([], 401),
        ]);
        $client = new RestHousecallProClient;

        $this->assertSame(['id' => 'co_9', 'name' => 'Rivera Plumbing'], $client->company('key_1'));
        $page = $client->customers('key_1', 1, 100);
        $this->assertTrue($page['more']);
        $this->assertSame(['cus_1', '5125550147', 'adr_1'], [$page['customers'][0]['id'], $page['customers'][0]['phone'], $page['customers'][0]['addresses'][0]['id']]);
        Http::assertSent(fn (Request $r) => $r->hasHeader('Authorization', 'Token key_1') && str_contains($r->url(), 'page_size=100'));

        try {
            $client->createJob('key_1', ['customer_id' => 'cus_1']);
            $this->fail('Housecall Pro refused the job');
        } catch (AppRequestFailed $e) {
            $this->assertSame(['address_id is invalid', '422'], [$e->getMessage(), $e->appCode]);
        }
        $this->expectException(AppAuthorizationLost::class);
        $client->customer('key_1', 'cus_gone');
    }
}
