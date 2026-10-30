<?php

namespace Tests\Feature;

use App\Actions\Organizations\ProvisionUserTenancy;
use App\Enums\AppointmentStatus;
use App\Exceptions\AppAuthorizationLost;
use App\Exceptions\AppRequestFailed;
use App\Jobs\SyncToJobber;
use App\Livewire\Client\Business\ConnectedApps;
use App\Models\AppConnection;
use App\Models\AppLink;
use App\Models\Appointment;
use App\Models\BusinessService;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\User;
use App\Services\Apps\Jobber\FakeJobberClient;
use App\Services\Apps\Jobber\GraphqlJobberClient;
use App\Services\Apps\Jobber\Jobber;
use App\Services\Apps\Jobber\JobberClient;
use App\Services\Billing\FeatureAccess;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Jobber (D111): a business connects its account; bookings SureHelp makes go in as requests from the
 * client, Jobber clients come into SureHelp, and nothing goes twice.
 */
class JobberTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Organization $business;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->travelTo(CarbonImmutable::parse('2026-10-14 15:00', 'UTC'));
        config(['apps.jobber.client_id' => 'jb-client', 'apps.jobber.client_secret' => 'jb-secret']);
        $this->owner = User::factory()->create(['role' => 'client', 'is_active' => true, 'must_change_password' => false]);
        $this->business = app(ProvisionUserTenancy::class)->handle($this->owner);
        $this->business->forceFill(['name' => 'Rivera Plumbing', 'timezone' => 'America/Chicago', 'status' => 'active', 'setup_completed_at' => now()])->save();
        $this->enableTwoFactor($this->owner);
    }

    private function fake(): FakeJobberClient
    {
        /** @var FakeJobberClient */
        return app(JobberClient::class);
    }

    private function connect(): AppConnection
    {
        $this->actingAs($this->owner);
        $redirect = $this->get(route('app.apps.jobber.connect'))->assertRedirect();
        parse_str((string) parse_url((string) $redirect->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->get(route('app.apps.jobber.callback', ['code' => 'abc', 'state' => $query['state']]))
            ->assertRedirect(route('app.business.apps'))->assertSessionHas('status');

        return AppConnection::query()->forOrganization($this->business)->where('provider', 'jobber')->sole();
    }

    private function book(Customer $customer, array $attributes = []): Appointment
    {
        $at = CarbonImmutable::parse('2026-10-16 19:00', 'UTC');   // 2 PM in Chicago

        return Appointment::create(array_merge(['organization_id' => $this->business->id, 'customer_id' => $customer->id, 'title' => 'Leak repair', 'timezone' => 'America/Chicago',
            'starts_at' => $at, 'ends_at' => $at->addHour(), 'blocked_until' => $at->addHour(), 'status' => AppointmentStatus::Confirmed, 'source' => 'agent'], $attributes));
    }

    public function test_connecting_brings_in_jobber_clients(): void
    {
        $ana = Customer::create(['organization_id' => $this->business->id, 'first_name' => 'Ana', 'email' => 'ana@lopez.test']);
        $this->fake()->addClient(['firstName' => 'Ana', 'lastName' => 'Lopez', 'email' => 'ANA@lopez.test', 'phone' => '512-555-0147',
            'address' => ['street1' => '12 Oak St', 'city' => 'Austin', 'province' => 'TX', 'postalCode' => '78704']]);
        $this->fake()->addClient(['firstName' => 'Ben', 'lastName' => 'Ray', 'phone' => '(512) 555-0148']);
        $this->fake()->addClient(['companyName' => 'Old Client Co', 'phone' => '5125550149', 'archived' => true]);
        $this->fake()->addClient(['firstName' => 'Nobody']);

        $connection = $this->connect();
        $this->assertSame(['Rivera Plumbing', 'acc_1', AppConnection::ACTIVE], [$connection->account_name, $connection->account_id, $connection->status]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'apps.jobber_connected']);

        $ana->refresh();
        $this->assertSame(['Ana', 'Lopez', '+15125550147', '12 Oak St'], [$ana->first_name, $ana->last_name, $ana->phone_e164, $ana->address_line1]);
        $ben = Customer::query()->forOrganization($this->business)->where('phone_e164', '+15125550148')->sole();
        $this->assertSame(['jobber', 'customer'], [$ben->source, $ben->status->value]);
        $this->assertSame(2, Customer::query()->forOrganization($this->business)->count(), 'archived clients and clients with no phone or email stay out');
        $this->assertSame('1 new customer, 1 matched to customers you had', $connection->entries()->sole()->label);

        // Next hour: only what changed.
        $this->travel(1)->hour();
        $this->fake()->addClient(['firstName' => 'Cal', 'lastName' => 'Diaz', 'email' => 'cal@diaz.test']);
        $this->artisan('apps:jobber-pull')->assertSuccessful();
        $this->assertSame(3, Customer::query()->forOrganization($this->business)->count());
        $this->assertSame('1 new customer', $connection->entries()->latest('id')->first()->label);
    }

    public function test_a_booking_goes_into_jobber_once_as_a_request(): void
    {
        $connection = $this->connect();
        $ana = Customer::create(['organization_id' => $this->business->id, 'first_name' => 'Ana', 'last_name' => 'Lopez', 'phone' => '5125550147', 'email' => 'ana@lopez.test',
            'address_line1' => '12 Oak St', 'city' => 'Austin', 'state' => 'TX', 'postal_code' => '78704']);
        $this->assertSame([], $this->fake()->clients, 'new customers stay out of Jobber by default');

        $service = BusinessService::create(['organization_id' => $this->business->id, 'name' => 'Water heater install', 'duration_minutes' => 60, 'price_type' => 'quote_required']);
        $appointment = $this->book($ana, ['service_id' => $service->id]);

        $client = array_values($this->fake()->clients)[0];
        $this->assertSame(['Ana', 'Lopez', 'ana@lopez.test', '(512) 555-0147', 'Austin'],
            [$client['firstName'], $client['lastName'], $client['email'], $client['phone'], $client['address']['city']]);
        $request = $this->fake()->requests[0];
        $this->assertSame($client['id'], $request['clientId']);
        $this->assertSame('Water heater install · Fri, Oct 16, 2:00 PM (booked by SureHelp)', $request['title']);

        // Sent again: nothing doubles. A second booking reuses the Jobber client.
        app(Jobber::class)->sendBooking($connection->fresh(), $appointment);
        $this->book($ana);
        $this->assertCount(2, $this->fake()->requests);
        $this->assertCount(1, $this->fake()->clients);
        $this->assertSame(['booking_sent', 'booking_sent', 'customer_sent'], $connection->entries()->latest('id')->pluck('action')->all());

        // Bookings copied in from a calendar weren't made by SureHelp: not sent.
        $this->book($ana, ['source' => 'calendar']);
        $this->assertCount(2, $this->fake()->requests);
    }

    public function test_existing_jobber_clients_are_matched_and_failures_are_recorded(): void
    {
        $connection = $this->connect();
        $connection->forceFill(['settings' => ['receive_customers' => false, 'send_new_customers' => true, 'send_bookings' => true]])->save();
        $this->fake()->addClient(['firstName' => 'Cal', 'lastName' => 'Diaz', 'phone' => '(512) 555-0160']);

        // Same phone in Jobber: matched, not added.
        $cal = Customer::create(['organization_id' => $this->business->id, 'first_name' => 'Cal', 'phone' => '512-555-0160']);
        $this->assertCount(1, $this->fake()->clients);
        $this->assertSame(array_key_first($this->fake()->clients), AppLink::query()->forOrganization($this->business)->where('local_id', $cal->id)->value('external_id'));

        // No name: the phone number stands in, as Jobber needs a name.
        Customer::create(['organization_id' => $this->business->id, 'phone' => '512-555-0161']);
        $this->assertNotNull(collect($this->fake()->clients)->firstWhere('lastName', '(512) 555-0161'));

        // Jobber refuses a client: written in the history, not retried forever.
        $client = new class extends FakeJobberClient
        {
            public function createClient(string $token, array $input): string
            {
                throw new AppRequestFailed('Email is not valid.');
            }
        };
        $this->app->instance(JobberClient::class, $client);
        $dee = Customer::create(['organization_id' => $this->business->id, 'first_name' => 'Dee', 'email' => 'dee@moss.test']);
        $this->assertSame('failed', $connection->entries()->latest('id')->value('action'));
        $this->assertSame('Email is not valid.', $connection->fresh()->last_error);
        $this->assertNull(AppLink::query()->forOrganization($this->business)->where('local_id', $dee->id)->value('external_id'));
    }

    public function test_lost_access_demo_businesses_and_the_feature_switch(): void
    {
        $connection = $this->connect();
        $ana = Customer::create(['organization_id' => $this->business->id, 'first_name' => 'Ana', 'phone' => '5125550147']);

        $connection->forceFill(['token_expires_at' => now()->subMinute()])->save();
        $this->book($ana);
        $this->assertSame(1, $this->fake()->refreshes);
        $this->assertCount(1, $this->fake()->requests);

        $connection->refresh()->forceFill(['token_expires_at' => now()->subMinute()])->save();
        $this->fake()->lost = true;
        $this->book($ana);
        $this->assertSame(AppConnection::NEEDS_REAUTH, $connection->fresh()->status);
        $this->get(route('app.business.apps'))->assertOk()->assertSee('Connect again');
        $this->fake()->lost = false;

        $connection->refresh()->forceFill(['status' => AppConnection::ACTIVE, 'token_expires_at' => now()->addHour()])->save();
        $this->business->forceFill(['is_demo' => true])->save();
        $this->book($ana);
        $this->assertCount(1, $this->fake()->requests);
        $this->business->forceFill(['is_demo' => false])->save();

        // Both apps switched off for this business: no page, no sync.
        app(FeatureAccess::class)->saveModes(['jobber' => FeatureAccess::SELECTED, 'quickbooks' => FeatureAccess::SELECTED]);
        app()->forgetScopedInstances();
        $this->get(route('app.business.apps'))->assertNotFound();
        $this->get(route('app.apps.jobber.connect'))->assertNotFound();
        $this->get(route('app.business.integrations'))->assertOk()->assertDontSee('Connected apps');
        $this->book($ana);
        $this->assertCount(1, $this->fake()->requests);
    }

    public function test_jobber_asking_us_to_slow_down_is_retried(): void
    {
        $connection = $this->connect();
        $ana = Customer::create(['organization_id' => $this->business->id, 'first_name' => 'Ana', 'phone' => '5125550147']);
        $appointment = $this->book($ana);
        $this->fake()->requests = [];
        AppLink::query()->forOrganization($this->business)->where('local_type', 'appointment')->delete();

        $client = new class extends FakeJobberClient
        {
            public function createRequest(string $token, array $input): string
            {
                throw new AppRequestFailed('Throttled', 'THROTTLED');
            }
        };
        $client->clients = $this->fake()->clients;
        $this->app->instance(JobberClient::class, $client);
        $this->expectException(AppRequestFailed::class);
        (new SyncToJobber($connection->id, 'appointment', $appointment->id))->handle(app(Jobber::class));
    }

    public function test_the_page_for_both_apps_and_disconnecting(): void
    {
        config(['apps.quickbooks.client_id' => 'qb', 'apps.quickbooks.client_secret' => 'qb']);
        $this->actingAs($this->owner);
        $this->get(route('app.business.apps'))->assertOk()->assertSee('Connect QuickBooks')->assertSee('Connect Jobber');
        $this->get(route('app.apps.jobber.callback', ['code' => 'abc', 'state' => 'forged']))->assertSessionHas('error');
        $this->assertDatabaseCount('app_connections', 0);

        $connection = $this->connect();
        $this->get(route('app.business.apps'))->assertOk()->assertSee('Send bookings to Jobber')->assertSee('Connect QuickBooks');
        Queue::fake();
        Livewire::test(ConnectedApps::class)->set('settings.jobber.send_bookings', false)->call('save', 'jobber')->assertDispatched('toast', type: 'success')
            ->call('syncNow', 'jobber')->assertDispatched('toast', type: 'success')
            ->call('save', 'quickbooks')->assertNotFound();   // not connected
        $this->assertFalse($connection->fresh()->wants('send_bookings'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'apps.jobber_settings_updated']);

        // Another business sees none of it; staff can't manage connected apps.
        $otherOwner = User::factory()->create(['role' => 'client', 'is_active' => true, 'must_change_password' => false]);
        app(ProvisionUserTenancy::class)->handle($otherOwner);
        $this->enableTwoFactor($otherOwner);
        $this->actingAs($otherOwner)->get(route('app.business.apps'))->assertOk()->assertDontSee('Send bookings to Jobber');
        Livewire::test(ConnectedApps::class)->call('disconnect', 'jobber')->assertNotFound();
        $staff = User::factory()->create(['role' => 'client', 'is_active' => true, 'must_change_password' => false]);
        $this->business->members()->attach($staff->id, ['role' => 'staff', 'status' => 'active']);
        $this->enableTwoFactor($staff);
        $this->actingAs($staff)->get(route('app.business.apps'))->assertForbidden();
        $this->get(route('app.apps.jobber.connect'))->assertForbidden();

        $this->actingAs($this->owner);
        Livewire::test(ConnectedApps::class)->call('disconnect', 'jobber')->assertRedirect(route('app.business.apps'));
        $this->assertDatabaseCount('app_connections', 0);
        $this->assertSame(1, $this->fake()->disconnects);
        $this->assertDatabaseHas('audit_logs', ['action' => 'apps.jobber_disconnected']);
    }

    public function test_the_graphql_client_speaks_the_jobber_api(): void
    {
        Http::fake([
            'api.getjobber.com/api/oauth/token' => Http::response(['access_token' => 'h.'.rtrim(strtr(base64_encode(json_encode(['exp' => time() + 3000])), '+/', '-_'), '=').'.s', 'refresh_token' => 'rt']),
            'api.getjobber.com/api/graphql' => Http::sequence()
                ->push(['data' => ['clients' => ['nodes' => [['id' => 'Z2lk', 'firstName' => 'Ana', 'lastName' => 'Lopez', 'isArchived' => false, 'updatedAt' => '2026-10-14T10:00:00Z',
                    'emails' => [['address' => 'old@x.test', 'primary' => false], ['address' => 'ana@lopez.test', 'primary' => true]], 'phones' => [], 'billingAddress' => null]]]]])
                ->push(['data' => ['requestCreate' => ['request' => null, 'userErrors' => [['message' => 'Client is archived', 'path' => ['clientId']]]]]])
                ->push(['errors' => [['message' => 'Throttled', 'extensions' => ['code' => 'THROTTLED']]]])
                ->push([], 401),
        ]);
        $client = new GraphqlJobberClient;

        $this->assertStringStartsWith('https://api.getjobber.com/api/oauth/authorize?response_type=code&client_id=jb-client', $client->authorizationUrl('s', 'https://x.test/cb'));
        $tokens = $client->exchange('code', 'https://x.test/cb');
        $this->assertSame('rt', $tokens['refresh_token']);
        $this->assertEqualsWithDelta(3000, $tokens['expires_in'], 2);

        $found = $client->searchClients('at', 'ana@lopez.test');
        $this->assertSame(['Z2lk', 'ana@lopez.test', null], [$found[0]['id'], $found[0]['email'], $found[0]['phone']]);
        Http::assertSent(fn (Request $r) => $r->url() === 'https://api.getjobber.com/api/graphql' && $r->hasHeader('X-JOBBER-GRAPHQL-VERSION', '2025-01-20')
            && $r->hasHeader('Authorization', 'Bearer at') && json_decode($r->body(), true)['variables']['term'] === 'ana@lopez.test');

        foreach ([['Client is archived', ''], ['Throttled', 'THROTTLED']] as [$message, $code]) {
            try {
                $client->createRequest('at', ['clientId' => 'Z2lk', 'title' => 'x']);
                $this->fail('Jobber refused it');
            } catch (AppRequestFailed $e) {
                $this->assertSame([$message, $code], [$e->getMessage(), $e->appCode]);
            }
        }
        $this->expectException(AppAuthorizationLost::class);
        $client->account('at');
    }
}
