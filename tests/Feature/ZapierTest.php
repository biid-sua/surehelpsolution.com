<?php

namespace Tests\Feature;

use App\Actions\Organizations\ProvisionUserTenancy;
use App\Models\ApiKey;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\Organization;
use App\Models\User;
use App\Models\WebhookEndpoint;
use App\Services\Websites\DnsResolver;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\Support\FakeDnsResolver;
use Tests\TestCase;

/**
 * The SureHelp Zapier app (D113): checking the key, Zaps turning their trigger on and off, examples for
 * the Zap editor, and the "Add Lead" action.
 */
class ZapierTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->travelTo(CarbonImmutable::parse('2026-10-12 15:00', 'UTC'));
        $this->app->instance(DnsResolver::class, new FakeDnsResolver(['hooks.zapier.com' => ['54.86.10.1'], 'inside.example.com' => ['10.0.0.5']]));
        $this->owner = User::factory()->create(['role' => 'client', 'is_active' => true, 'must_change_password' => false]);
        $this->org = app(ProvisionUserTenancy::class)->handle($this->owner);
        $this->org->forceFill(['name' => 'Rivera Plumbing', 'status' => 'active', 'setup_completed_at' => now()])->save();
    }

    /** @return array<string, string> */
    private function key(Organization $org, User $user, bool $write = true): array
    {
        [, $secret] = ApiKey::issue($org, $user, 'Zapier', $write);

        return ['Authorization' => 'Bearer '.$secret];
    }

    public function test_a_zap_turns_its_trigger_on_receives_events_and_turns_it_off(): void
    {
        $key = $this->key($this->org, $this->owner);
        $this->getJson('/api/v1/client/zapier/me', $key)->assertOk()->assertJsonPath('data.business', 'Rivera Plumbing');
        $this->getJson('/api/v1/client/zapier/me', ['Authorization' => 'Bearer shk_wrong'])->assertUnauthorized();

        $this->postJson('/api/v1/client/zapier/hooks', ['target_url' => 'https://inside.example.com/hook', 'event' => 'customer.created'], $key)->assertUnprocessable();
        $this->postJson('/api/v1/client/zapier/hooks', ['target_url' => 'https://hooks.zapier.com/1/abc', 'event' => 'not.an.event'], $key)->assertUnprocessable();
        $id = $this->postJson('/api/v1/client/zapier/hooks', ['target_url' => 'https://hooks.zapier.com/1/abc', 'event' => 'customer.created'], $key)
            ->assertCreated()->json('data.id');
        $endpoint = WebhookEndpoint::query()->forOrganization($this->org)->sole();
        $this->assertSame(['zapier', ['customer.created']], [$endpoint->source, $endpoint->events]);

        // The business sees it under API & webhooks, marked as a Zap's.
        $this->actingAs($this->owner);
        $this->enableTwoFactor($this->owner);
        $this->get(route('app.business.integrations'))->assertOk()->assertSee('Added by a Zap');

        // A new customer reaches the Zap.
        $received = [];
        Http::fake(['https://hooks.zapier.com/*' => function (Request $request) use (&$received) {
            $received[] = $request->data();

            return Http::response(['status' => 'success']);
        }]);
        Customer::create(['organization_id' => $this->org->id, 'first_name' => 'Ana', 'phone' => '5125550147']);
        $this->assertSame(['customer.created', 'Ana'], [$received[0]['event'], $received[0]['data']['first_name']]);

        // Turning the Zap off removes the address; someone else's or a hand-made one can't be removed this way.
        $mine = WebhookEndpoint::create(['organization_id' => $this->org->id, 'url' => 'https://hooks.zapier.com/mine', 'events' => ['call.logged']]);
        $this->app['auth']->forgetGuards();
        $this->deleteJson('/api/v1/client/zapier/hooks/'.$mine->ulid, [], $key)->assertNotFound();
        $this->deleteJson('/api/v1/client/zapier/hooks/'.$id, [], $key)->assertOk();
        $this->assertModelMissing($endpoint);
        $this->assertDatabaseHas('audit_logs', ['action' => 'webhook.deleted']);
    }

    public function test_examples_for_the_zap_editor(): void
    {
        $key = $this->key($this->org, $this->owner);
        // Nothing yet: a made-up example in the same shape.
        $this->getJson('/api/v1/client/zapier/sample?event=payment.paid', $key)->assertOk()
            ->assertJsonPath('data.0.type', 'payment')->assertJsonPath('data.0.event', 'payment.paid')->assertJsonPath('data.0.amount_cents', 5000);

        // Then the newest real one, exactly as a webhook would carry it.
        $ana = Customer::create(['organization_id' => $this->org->id, 'first_name' => 'Ana', 'phone' => '5125550147']);
        $sample = $this->getJson('/api/v1/client/zapier/sample?event=customer.created', $key)->assertOk()->json('data.0');
        $this->assertSame(['evt_sample_customer_created', $ana->ulid, 'Ana'], [$sample['id'], $sample['record_id'], $sample['first_name']]);
    }

    public function test_the_add_lead_action_gets_the_instant_reply(): void
    {
        $key = $this->key($this->org, $this->owner);
        $this->postJson('/api/v1/client/zapier/leads', ['name' => 'Nobody'], $key)->assertUnprocessable();
        $this->postJson('/api/v1/client/zapier/leads', ['name' => 'Ana Lopez', 'phone' => '(512) 555-0147', 'message' => 'Leaking water heater', 'source' => 'facebook'], $key)
            ->assertCreated()->assertJsonPath('data.name', 'Ana Lopez')->assertJsonPath('data.source', 'facebook')->assertJsonPath('data.phone', '+15125550147');
        $lead = Lead::query()->forOrganization($this->org)->sole();
        $this->assertNotNull($lead->task_id);
        $this->assertSame('lead', $lead->customer->source);

        // A read-only key can't add leads.
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/client/zapier/leads', ['name' => 'Ben', 'phone' => '5125550148'], $this->key($this->org, $this->owner, false))->assertForbidden();
    }

    public function test_other_businesses_and_staff(): void
    {
        $key = $this->key($this->org, $this->owner);
        $id = $this->postJson('/api/v1/client/zapier/hooks', ['target_url' => 'https://hooks.zapier.com/1/abc', 'event' => 'call.logged'], $key)->json('data.id');

        $otherOwner = User::factory()->create(['role' => 'client', 'is_active' => true, 'must_change_password' => false]);
        $other = app(ProvisionUserTenancy::class)->handle($otherOwner);
        $this->app['auth']->forgetGuards();
        $this->deleteJson('/api/v1/client/zapier/hooks/'.$id, [], $this->key($other, $otherOwner))->assertNotFound();
        $this->assertDatabaseCount('webhook_endpoints', 1);

        // Staff can't manage integrations, so their keys can't add triggers.
        $staff = User::factory()->create(['role' => 'client', 'is_active' => true, 'must_change_password' => false]);
        $this->org->members()->attach($staff->id, ['role' => 'staff', 'status' => 'active']);
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/client/zapier/hooks', ['target_url' => 'https://hooks.zapier.com/1/def', 'event' => 'call.logged'], $this->key($this->org, $staff))->assertForbidden();
    }
}
