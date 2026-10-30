<?php

namespace Tests\Feature;

use App\Actions\Organizations\ProvisionUserTenancy;
use App\Exceptions\AppAuthorizationLost;
use App\Exceptions\AppRequestFailed;
use App\Livewire\Client\Business\ConnectedApps;
use App\Models\AppConnection;
use App\Models\AppLink;
use App\Models\Automation;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\PaymentAccount;
use App\Models\PaymentRequest;
use App\Models\User;
use App\Services\Apps\QuickBooks\FakeQuickBooksClient;
use App\Services\Apps\QuickBooks\IntuitClient;
use App\Services\Apps\QuickBooks\QuickBooks;
use App\Services\Apps\QuickBooks\QuickBooksClient;
use App\Services\Billing\FeatureAccess;
use App\Services\Payments\CustomerPayments;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * QuickBooks Online (D110): a business connects its company; payments taken through SureHelp go in
 * as payments from the customer, QuickBooks customers come into SureHelp, and nothing goes twice.
 */
class QuickBooksTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Organization $business;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->travelTo(CarbonImmutable::parse('2026-10-14 15:00', 'UTC'));
        config(['apps.quickbooks.client_id' => 'qb-client', 'apps.quickbooks.client_secret' => 'qb-secret']);
        $this->owner = User::factory()->create(['role' => 'client', 'is_active' => true, 'must_change_password' => false]);
        $this->business = app(ProvisionUserTenancy::class)->handle($this->owner);
        $this->business->forceFill(['name' => 'Rivera Plumbing', 'timezone' => 'America/Chicago', 'status' => 'active', 'setup_completed_at' => now()])->save();
        $this->enableTwoFactor($this->owner);
    }

    private function fake(): FakeQuickBooksClient
    {
        /** @var FakeQuickBooksClient */
        return app(QuickBooksClient::class);
    }

    private function connect(): AppConnection
    {
        $this->actingAs($this->owner);
        $redirect = $this->get(route('app.apps.quickbooks.connect'))->assertRedirect();
        parse_str((string) parse_url((string) $redirect->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->get(route('app.apps.quickbooks.callback', ['code' => 'abc', 'state' => $query['state'], 'realmId' => '9130350']))
            ->assertRedirect(route('app.business.apps'))->assertSessionHas('status');

        return AppConnection::query()->forOrganization($this->business)->sole();
    }

    private function paidPayment(Customer $customer, int $cents = 15000): PaymentRequest
    {
        PaymentAccount::query()->firstOrCreate(['organization_id' => $this->business->id], ['stripe_account_id' => 'acct_rivera', 'charges_enabled' => true, 'currency' => 'usd']);
        $request = PaymentRequest::create(['organization_id' => $this->business->id, 'customer_id' => $customer->id, 'kind' => 'deposit',
            'description' => 'Deposit for water heater install', 'amount_cents' => $cents, 'currency' => 'usd', 'status' => PaymentRequest::OPEN]);
        app(CustomerPayments::class)->completed(['payment_status' => 'paid', 'amount_total' => $cents, 'payment_intent' => 'pi_3QabcdefGHIJKLMNOPqrstu',
            'metadata' => ['payment_request' => $request->ulid]], 'acct_rivera');

        return $request->fresh();
    }

    public function test_connecting_brings_in_quickbooks_customers_and_matches_the_ones_we_have(): void
    {
        $ana = Customer::create(['organization_id' => $this->business->id, 'first_name' => 'Ana', 'phone' => '(512) 555-0147']);
        $this->fake()->addCustomer(['DisplayName' => 'Ana Lopez', 'GivenName' => 'Ana', 'FamilyName' => 'Lopez', 'PrimaryPhone' => ['FreeFormNumber' => '512-555-0147'],
            'PrimaryEmailAddr' => ['Address' => 'ana@lopez.test'], 'BillAddr' => ['Line1' => '12 Oak St', 'City' => 'Austin', 'CountrySubDivisionCode' => 'TX', 'PostalCode' => '78704']]);
        $this->fake()->addCustomer(['DisplayName' => 'Ben Ray', 'GivenName' => 'Ben', 'FamilyName' => 'Ray', 'Mobile' => ['FreeFormNumber' => '(512) 555-0148']]);
        $this->fake()->addCustomer(['DisplayName' => 'Bayside HOA', 'CompanyName' => 'Bayside HOA', 'PrimaryEmailAddr' => ['Address' => 'board@bayside.test']]);
        $this->fake()->addCustomer(['DisplayName' => 'No Contact Co']);
        Automation::create(['organization_id' => $this->business->id, 'name' => 'Welcome', 'trigger' => 'customer_created', 'action' => 'notify_team', 'is_active' => true, 'delay_minutes' => 0]);

        $connection = $this->connect();
        $this->assertSame(['Rivera Plumbing LLC', '9130350', AppConnection::ACTIVE], [$connection->account_name, $connection->account_id, $connection->status]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'apps.quickbooks_connected']);

        // Ana was matched by her phone and only her blanks were filled; Ben and Bayside were added.
        $ana->refresh();
        $this->assertSame(['Ana', 'Lopez', 'ana@lopez.test', '12 Oak St', 'TX'], [$ana->first_name, $ana->last_name, $ana->email, $ana->address_line1, $ana->state]);
        $ben = Customer::query()->forOrganization($this->business)->where('phone_e164', '+15125550148')->sole();
        $this->assertSame(['Ben', 'Ray', 'quickbooks', 'customer'], [$ben->first_name, $ben->last_name, $ben->source, $ben->status->value]);
        $this->assertSame('Bayside HOA', Customer::query()->forOrganization($this->business)->where('email', 'board@bayside.test')->sole()->company);
        $this->assertSame(3, Customer::query()->forOrganization($this->business)->count(), 'no one without a phone or email');
        $this->assertSame(3, AppLink::query()->forOrganization($this->business)->where('local_type', 'customer')->count());
        $this->assertDatabaseCount('automation_runs', 0);   // they're not new leads
        $this->assertSame('2 new customers, 1 matched to customers you had', $connection->entries()->sole()->label);

        // Next hour: only what changed, and what the team typed is never overwritten.
        $ben->update(['first_name' => 'Benjamin']);
        $this->travel(1)->hour();
        $id = $ben->id;
        $quickBooksBen = collect($this->fake()->customers)->firstWhere('DisplayName', 'Ben Ray');
        $this->fake()->customers[$quickBooksBen['Id']]['GivenName'] = 'Benny';
        $this->fake()->customers[$quickBooksBen['Id']]['PrimaryEmailAddr'] = ['Address' => 'ben@ray.test'];
        $this->fake()->customers[$quickBooksBen['Id']]['MetaData']['LastUpdatedTime'] = now()->toIso8601String();
        $this->artisan('apps:quickbooks-pull')->assertSuccessful();
        $ben = Customer::query()->find($id);
        $this->assertSame(['Benjamin', 'ben@ray.test'], [$ben->first_name, $ben->email]);
        $this->assertSame(3, Customer::query()->forOrganization($this->business)->count());
    }

    public function test_a_paid_deposit_goes_into_quickbooks_once_with_its_customer(): void
    {
        $connection = $this->connect();
        $ana = Customer::create(['organization_id' => $this->business->id, 'first_name' => 'Ana', 'last_name' => 'Lopez', 'phone' => '5125550147', 'email' => 'ana@lopez.test']);
        $this->assertSame([], $this->fake()->customers, 'new customers stay out of QuickBooks by default');

        $request = $this->paidPayment($ana);
        $this->assertCount(1, $this->fake()->customers);
        $customer = array_values($this->fake()->customers)[0];
        $this->assertSame(['Ana Lopez', 'Ana', 'Lopez', '(512) 555-0147', 'ana@lopez.test'],
            [$customer['DisplayName'], $customer['GivenName'], $customer['FamilyName'], $customer['PrimaryPhone']['FreeFormNumber'], $customer['PrimaryEmailAddr']['Address']]);
        $payment = $this->fake()->payments[0];
        $this->assertSame(['value' => $customer['Id']], $payment['CustomerRef']);
        $this->assertSame([150.0, '2026-10-14', 'JKLMNOPqrstu'], [$payment['TotalAmt'], $payment['TxnDate'], substr($payment['PaymentRefNum'], -12)]);
        $this->assertStringContainsString('Deposit through SureHelp: Deposit for water heater install', $payment['PrivateNote']);

        // Sent again (a retried event): nothing doubles.
        app(QuickBooks::class)->sendPayment($connection->fresh(), $request);
        $this->assertCount(1, $this->fake()->payments);

        // A second payment from Ana reuses her QuickBooks customer.
        $this->paidPayment($ana, 9900);
        $this->assertCount(2, $this->fake()->payments);
        $this->assertCount(1, $this->fake()->customers);
        $this->assertSame(['payment_sent', 'payment_sent', 'customer_sent'], $connection->entries()->latest('id')->pluck('action')->all());
    }

    public function test_existing_quickbooks_customers_are_matched_and_taken_names_kept_apart(): void
    {
        $this->fake()->addCustomer(['DisplayName' => 'Cal Diaz', 'PrimaryEmailAddr' => ['Address' => 'cal@diaz.test']]);
        $this->fake()->addCustomer(['DisplayName' => 'Dee Moss', 'PrimaryEmailAddr' => ['Address' => 'someone-else@moss.test']]);
        $connection = $this->connect();
        $connection->forceFill(['settings' => ['receive_customers' => false, 'send_new_customers' => true, 'send_payments' => true]])->save();

        // Same email in QuickBooks: matched, not added.
        $cal = Customer::create(['organization_id' => $this->business->id, 'first_name' => 'Cal', 'last_name' => 'Diaz', 'email' => 'cal@diaz.test']);
        $this->assertCount(2, $this->fake()->customers);
        $this->assertSame(collect($this->fake()->customers)->firstWhere('DisplayName', 'Cal Diaz')['Id'],
            AppLink::query()->forOrganization($this->business)->where('local_id', $cal->id)->value('external_id'));

        // "Dee Moss" is someone else there: the new one gets the last digits of the phone.
        Customer::create(['organization_id' => $this->business->id, 'first_name' => 'Dee', 'last_name' => 'Moss', 'phone' => '5125550170', 'email' => 'dee@moss.test']);
        $this->assertNotNull(collect($this->fake()->customers)->firstWhere('DisplayName', 'Dee Moss (0170)'));
    }

    public function test_lost_access_demo_businesses_and_the_feature_switch(): void
    {
        $connection = $this->connect();
        $ana = Customer::create(['organization_id' => $this->business->id, 'first_name' => 'Ana', 'phone' => '5125550147']);

        // Expired access is refreshed quietly.
        $connection->forceFill(['token_expires_at' => now()->subMinute()])->save();
        $this->paidPayment($ana);
        $this->assertSame(1, $this->fake()->refreshes);
        $this->assertCount(1, $this->fake()->payments);

        // Disconnected in QuickBooks: the owner is asked to connect again, and nothing is lost.
        $connection->refresh()->forceFill(['token_expires_at' => now()->subMinute()])->save();
        $this->fake()->lost = true;
        $this->paidPayment($ana, 5000);
        $this->assertSame(AppConnection::NEEDS_REAUTH, $connection->fresh()->status);
        $this->get(route('app.business.apps'))->assertOk()->assertSee('Connect again');
        $this->fake()->lost = false;

        // A demo business never reaches QuickBooks.
        $connection->refresh()->forceFill(['status' => AppConnection::ACTIVE, 'token_expires_at' => now()->addHour()])->save();
        $this->business->forceFill(['is_demo' => true])->save();
        $this->paidPayment($ana, 6000);
        $this->assertCount(1, $this->fake()->payments);
        $this->business->forceFill(['is_demo' => false])->save();

        // Switched off for this business: no page, no sync.
        app(FeatureAccess::class)->saveModes(['quickbooks' => FeatureAccess::SELECTED]);
        app()->forgetScopedInstances();
        $this->get(route('app.business.apps'))->assertOk()->assertDontSee('Connect QuickBooks')->assertSee('finishing the Jobber setup');
        $this->get(route('app.apps.quickbooks.connect'))->assertNotFound();
        $this->paidPayment($ana, 7000);
        $this->assertCount(1, $this->fake()->payments);
    }

    public function test_the_page_settings_and_disconnecting(): void
    {
        $this->actingAs($this->owner);
        $this->get(route('app.business.apps'))->assertOk()->assertSee('Connect QuickBooks')->assertSee('Connected apps');

        // A stale or someone else's answer from QuickBooks is refused.
        $this->get(route('app.apps.quickbooks.callback', ['code' => 'abc', 'state' => 'forged', 'realmId' => '1']))->assertSessionHas('error');
        $this->assertDatabaseCount('app_connections', 0);

        $connection = $this->connect();
        $this->get(route('app.business.apps'))->assertOk()->assertSee('Rivera Plumbing LLC')->assertSee('Fetch changes now');
        Livewire::test(ConnectedApps::class)->set('settings.quickbooks.send_new_customers', true)->call('save', 'quickbooks')->assertDispatched('toast', type: 'success')
            ->call('syncNow', 'quickbooks')->assertDispatched('toast', type: 'success');
        $this->assertTrue($connection->fresh()->wants('send_new_customers'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'apps.quickbooks_settings_updated']);

        // Another business sees none of it.
        $otherOwner = User::factory()->create(['role' => 'client', 'is_active' => true, 'must_change_password' => false]);
        app(ProvisionUserTenancy::class)->handle($otherOwner);
        $this->enableTwoFactor($otherOwner);
        $this->actingAs($otherOwner)->get(route('app.business.apps'))->assertOk()->assertDontSee('Rivera Plumbing LLC')->assertSee('Connect QuickBooks');
        Livewire::test(ConnectedApps::class)->call('save', 'quickbooks')->assertNotFound();
        $this->assertTrue($connection->fresh()->wants('send_new_customers'));

        // Staff can't manage connected apps.
        $staff = User::factory()->create(['role' => 'client', 'is_active' => true, 'must_change_password' => false]);
        $this->business->members()->attach($staff->id, ['role' => 'staff', 'status' => 'active']);
        $this->enableTwoFactor($staff);
        $this->actingAs($staff)->get(route('app.business.apps'))->assertForbidden();
        $this->get(route('app.apps.quickbooks.connect'))->assertForbidden();

        $this->actingAs($this->owner);
        Livewire::test(ConnectedApps::class)->call('disconnect', 'quickbooks')->assertRedirect(route('app.business.apps'));
        $this->assertDatabaseCount('app_connections', 0);
        $this->assertSame(['qb-refresh-abc'], $this->fake()->revoked);
        $this->assertDatabaseHas('audit_logs', ['action' => 'apps.quickbooks_disconnected']);
    }

    public function test_the_intuit_client_speaks_the_quickbooks_api(): void
    {
        config(['apps.quickbooks.environment' => 'sandbox']);
        Http::fake([
            'oauth.platform.intuit.com/*' => Http::response(['access_token' => 'at', 'refresh_token' => 'rt', 'expires_in' => 3600]),
            '*/query*' => Http::response(['QueryResponse' => ['Customer' => [['Id' => '58', 'DisplayName' => "O'Neil Air"]]]]),
            '*/payment*' => Http::response(['Fault' => ['Error' => [['Message' => 'Invalid Reference Id', 'Detail' => 'Customer not found', 'code' => '2500']]]], 400),
            '*' => Http::response([], 401),
        ]);
        $client = new IntuitClient;

        $this->assertStringStartsWith('https://appcenter.intuit.com/connect/oauth2?client_id=qb-client', $client->authorizationUrl('s', 'https://x.test/cb'));
        $this->assertSame('rt', $client->exchange('code', 'https://x.test/cb')['refresh_token']);
        $this->assertSame('58', $client->findCustomer('123', 'at', null, "O'Neil Air")['Id']);
        Http::assertSent(fn (Request $r) => str_starts_with($r->url(), 'https://sandbox-quickbooks.api.intuit.com/v3/company/123/query')
            && $r['query'] === "SELECT * FROM Customer WHERE DisplayName = 'O\\'Neil Air' MAXRESULTS 1" && $r->hasHeader('Authorization', 'Bearer at'));

        try {
            $client->createPayment('123', 'at', ['TotalAmt' => 1]);
            $this->fail('QuickBooks refused the payment');
        } catch (AppRequestFailed $e) {
            $this->assertSame(['2500', 'Invalid Reference Id. Customer not found'], [$e->appCode, $e->getMessage()]);
        }
        $this->expectException(AppAuthorizationLost::class);
        $client->companyName('123', 'at');
    }
}
