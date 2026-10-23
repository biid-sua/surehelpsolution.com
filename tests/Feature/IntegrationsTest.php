<?php

namespace Tests\Feature;

use App\Actions\Organizations\ProvisionUserTenancy;
use App\Livewire\Client\Business\Integrations;
use App\Models\ApiKey;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Services\Websites\DnsResolver;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Support\FakeDnsResolver;
use Tests\TestCase;

/**
 * API keys and outgoing webhooks (D87).
 */
class IntegrationsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-12 15:00', 'UTC'));
        $this->app->instance(DnsResolver::class, new FakeDnsResolver(['hooks.example.com' => ['93.184.216.34'], 'inside.example.com' => ['10.0.0.5']]));
        $this->owner = User::factory()->create(['role' => 'client', 'is_active' => true, 'must_change_password' => false]);
        $this->org = app(ProvisionUserTenancy::class)->handle($this->owner);
        $this->org->forceFill(['name' => 'Rivera Plumbing', 'status' => 'active', 'setup_completed_at' => now()])->save();
        $this->org = $this->org->fresh();
    }

    private function key(bool $write): string
    {
        $this->actingAs($this->owner);
        $secret = Livewire::test(Integrations::class)->set('keyName', 'n8n')->set('keyWrite', $write)->call('createKey')->assertHasNoErrors()->get('newKey');
        $this->assertStringStartsWith('shk_', $secret);
        $this->app['auth']->forgetGuards();

        return $secret;
    }

    public function test_api_keys_use_the_business_api_with_the_right_scope(): void
    {
        Customer::create(['organization_id' => $this->org->id, 'first_name' => 'Ana', 'email' => 'ana@example.test']);
        $read = $this->key(false);
        $this->assertNotSame($read, ApiKey::query()->value('hash'), 'only a hash is stored');

        $this->getJson('/api/v1/client/customers', ['Authorization' => 'Bearer '.$read])->assertOk()->assertJsonPath('data.customers.0.first_name', 'Ana');
        $this->postJson('/api/v1/client/customers', ['first_name' => 'Bo', 'phone' => '5125550111'], ['Authorization' => 'Bearer '.$read])
            ->assertForbidden()->assertJsonPath('message', 'This API key is read-only.');
        $this->getJson('/api/v1/user', ['Authorization' => 'Bearer '.$read])->assertForbidden();
        $this->getJson('/api/v1/client/customers', ['Authorization' => 'Bearer shk_wrong'])->assertUnauthorized();
        $this->assertNotNull(ApiKey::query()->first()->last_used_at);

        $write = $this->key(true);
        $this->postJson('/api/v1/client/customers', ['first_name' => 'Bo', 'phone' => '5125550111'], ['Authorization' => 'Bearer '.$write])->assertCreated();

        $this->actingAs($this->owner);
        Livewire::test(Integrations::class)->call('revokeKey', ApiKey::query()->where('can_write', true)->value('ulid'));
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/client/customers', ['Authorization' => 'Bearer '.$write])->assertUnauthorized();
    }

    public function test_events_are_posted_signed_to_webhooks(): void
    {
        $received = [];
        Http::fake(['https://hooks.example.com/*' => function (Request $request) use (&$received) {
            $received[] = $request;

            return Http::response(['ok' => true]);
        }]);
        $this->actingAs($this->owner);
        Livewire::test(Integrations::class)->set('url', 'https://inside.example.com/hook')->call('addEndpoint')->assertHasErrors('url')
            ->set('url', 'http://hooks.example.com/hook')->call('addEndpoint')->assertHasErrors('url')
            ->set('url', 'https://hooks.example.com/surehelp')->set('events', ['customer.created'])->call('addEndpoint')->assertHasNoErrors();
        $endpoint = WebhookEndpoint::query()->sole();

        Customer::create(['organization_id' => $this->org->id, 'first_name' => 'Ana', 'last_name' => 'Lopez', 'phone' => '(512) 555-0147']);

        $this->assertCount(1, $received);
        $request = $received[0];
        $body = json_decode($request->body(), true);
        $this->assertSame('customer.created', $body['event']);
        $this->assertSame('Ana Lopez', $body['data']['name']);
        $this->assertSame('+15125550147', $body['data']['phone']);
        $this->assertSame($this->org->ulid, $body['organization']['id']);
        preg_match('/^t=(\d+),v1=([0-9a-f]{64})$/', $request->header('X-SureHelp-Signature')[0], $m);
        $this->assertSame(hash_hmac('sha256', $m[1].'.'.$request->body(), $endpoint->secret), $m[2]);
        $this->assertSame('delivered', WebhookDelivery::query()->sole()->status);

        // Events it didn't ask for aren't sent; a test ping is.
        Livewire::test(Integrations::class)->call('test', $endpoint->ulid);
        $this->assertSame('ping', json_decode($received[1]->body(), true)['event']);
    }

    public function test_failed_deliveries_are_retried_then_the_webhook_is_switched_off(): void
    {
        Http::fake(['https://hooks.example.com/*' => Http::response('nope', 500)]);
        $endpoint = WebhookEndpoint::create(['organization_id' => $this->org->id, 'url' => 'https://hooks.example.com/x', 'events' => ['customer.created']]);
        $endpoint->forceFill(['failures' => WebhookEndpoint::MAX_FAILURES - 1])->save();

        Customer::create(['organization_id' => $this->org->id, 'first_name' => 'Ana', 'phone' => '(512) 555-0147']);

        $delivery = WebhookDelivery::query()->sole();
        $this->assertSame([500, 'Answered HTTP 500.'], [$delivery->response_code, $delivery->error]);
        $endpoint->refresh();
        $this->assertFalse($endpoint->is_active);
        $this->assertStringStartsWith('Switched off after 20 failed deliveries', $endpoint->disabled_reason);
    }
}
