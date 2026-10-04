<?php

namespace Tests\Feature;

use App\Actions\Customers\MatchOrCreateCustomer;
use App\Actions\Organizations\ProvisionUserTenancy;
use App\Enums\TimelineEventType;
use App\Livewire\Client\Customers\Index;
use App\Livewire\Client\Customers\Show;
use App\Models\CallLog;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\User;
use App\Services\Customers\CustomerBackfill;
use App\Support\Phone;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * P2-3 — customers, deduplication, timeline (spec §12–13, §15, §93).
 */
class CustomersTest extends TestCase
{
    use RefreshDatabase;

    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->agent = User::factory()->create(['role' => 'agent', 'is_active' => true]);
    }

    /** @return array{0: User, 1: Organization} */
    private function business(): array
    {
        $owner = User::factory()->create(['role' => 'client', 'is_active' => true, 'must_change_password' => false]);

        return [$owner, app(ProvisionUserTenancy::class)->handle($owner)];
    }

    private function logCall(User $client, array $overrides = []): void
    {
        $this->actingAs($this->agent)->postJson(route('admin.call-logs.store'), array_merge([
            'client_id' => (string) $client->id,
            'call_date' => now()->toDateString(), 'call_time' => '10:00',
            'caller_name' => 'Maria Lopez', 'caller_phone' => '(512) 555-0147',
            'reason_for_call' => 'service-request', 'call_outcome' => 'resolved-by-agent',
            'agent_name' => 'Agent', 'status' => 'new',
        ], $overrides))->assertOk();
    }

    public function test_phone_numbers_normalise_to_e164(): void
    {
        $this->assertSame('+15125550147', Phone::normalize('(512) 555-0147'));
        $this->assertSame('+15125550147', Phone::normalize('512.555.0147'));
        $this->assertSame('+15125550147', Phone::normalize('+1 512 555 0147'));
        $this->assertSame('+442079460958', Phone::normalize('+44 20 7946 0958'));
        $this->assertNull(Phone::normalize('555-0147'));
        $this->assertNull(Phone::normalize('call me'));
        $this->assertSame('(512) 555-0147', Phone::display('+15125550147'));
    }

    public function test_logged_call_creates_customer_and_timeline(): void
    {
        [$client, $org] = $this->business();

        $this->logCall($client, ['caller_email' => 'MARIA@Example.test', 'service_location' => '12 Main St']);

        $customer = Customer::withoutGlobalScopes()->sole();
        $this->assertSame($org->id, $customer->organization_id);
        $this->assertSame(['Maria', 'Lopez'], [$customer->first_name, $customer->last_name]);
        $this->assertSame('+15125550147', $customer->phone_e164);
        $this->assertSame('maria@example.test', $customer->email);
        $this->assertSame('12 Main St', $customer->address_line1);
        $this->assertSame('call', $customer->source);
        $this->assertSame($customer->id, CallLog::withoutGlobalScopes()->sole()->customer_id);

        $types = $customer->timeline()->get()->pluck('type')->all();
        $this->assertEqualsCanonicalizing([TimelineEventType::CustomerCreated, TimelineEventType::CallIncoming], $types);
        $this->assertNotNull($customer->fresh()->last_activity_at);
    }

    public function test_repeat_caller_matches_by_phone_and_is_only_enriched(): void
    {
        [$client] = $this->business();

        $this->logCall($client, ['caller_name' => 'Maria Lopez', 'caller_phone' => '512-555-0147']);
        $this->logCall($client, ['caller_name' => 'M', 'caller_phone' => '+1 (512) 555 0147', 'caller_email' => 'maria@example.test']);

        $customer = Customer::withoutGlobalScopes()->sole();
        $this->assertSame('Maria', $customer->first_name, 'an existing name is never overwritten');
        $this->assertSame('maria@example.test', $customer->email, 'empty fields are filled');
        $this->assertSame(2, CallLog::withoutGlobalScopes()->where('customer_id', $customer->id)->count());
        $this->assertSame(3, $customer->timeline()->count()); // created + 2 calls
    }

    public function test_same_phone_in_another_business_is_a_different_customer(): void
    {
        [$alice] = $this->business();
        [$bob] = $this->business();

        $this->logCall($alice);
        $this->logCall($bob);

        $this->assertSame(2, Customer::withoutGlobalScopes()->where('phone_e164', '+15125550147')->count());
    }

    public function test_email_match_without_phone_and_no_identity_means_no_customer(): void
    {
        [$client] = $this->business();

        $this->logCall($client, ['caller_phone' => null, 'caller_email' => 'tom@example.test', 'caller_name' => 'Tom']);
        $this->logCall($client, ['caller_phone' => null, 'caller_email' => 'TOM@example.test', 'caller_name' => 'Tom Reed']);
        $this->logCall($client, ['caller_phone' => null, 'caller_email' => null, 'caller_name' => 'Anonymous']);

        $this->assertSame(1, Customer::withoutGlobalScopes()->count());
        $this->assertNull(CallLog::withoutGlobalScopes()->latest('id')->first()->customer_id);
    }

    public function test_database_enforces_one_customer_per_phone_per_business(): void
    {
        [, $org] = $this->business();
        Customer::create(['organization_id' => $org->id, 'first_name' => 'A', 'phone' => '(512) 555-0147']);

        $this->expectException(UniqueConstraintViolationException::class);
        Customer::create(['organization_id' => $org->id, 'first_name' => 'B', 'phone' => '512 555 0147']);
    }

    public function test_soft_deleted_customer_is_restored_instead_of_duplicated(): void
    {
        [, $org] = $this->business();
        $customer = Customer::create(['organization_id' => $org->id, 'first_name' => 'Maria', 'phone' => '(512) 555-0147']);
        $customer->delete();

        $match = app(MatchOrCreateCustomer::class)->handle($org, ['phone' => '5125550147', 'name' => 'Maria']);

        $this->assertFalse($match['created']);
        $this->assertSame($customer->id, $match['customer']->id);
        $this->assertFalse($customer->fresh()->trashed());
    }

    public function test_backfill_builds_customers_from_existing_calls(): void
    {
        [, $org] = $this->business();
        $make = fn (array $a) => CallLog::withoutGlobalScopes()->create(array_merge([
            'call_id' => CallLog::generateCallId(), 'organization_id' => $org->id, 'call_date' => '2026-09-01', 'call_time' => '10:00',
            'reason_for_call' => 'service-request', 'call_outcome' => 'resolved-by-agent', 'agent_name' => 'A', 'status' => 'new', 'user_id' => $this->agent->id,
        ], $a));
        $first = $make(['caller_name' => 'Maria Lopez', 'caller_phone' => '512-555-0147']);
        $first->forceFill(['created_at' => '2026-09-01 15:00:00'])->saveQuietly();
        $make(['caller_name' => 'Maria', 'caller_phone' => '(512) 555-0147']);
        $make(['caller_name' => 'Tom', 'caller_email' => 'tom@example.test']);
        $make(['caller_name' => 'Nobody']);

        $dry = app(CustomerBackfill::class)->run(dryRun: true);
        $this->assertSame(2, $dry['customers_created']);
        $this->assertSame(0, Customer::withoutGlobalScopes()->count(), 'dry run saves nothing');

        $report = app(CustomerBackfill::class)->run();
        $this->assertSame(['calls_linked' => 3, 'customers_created' => 2, 'calls_without_identity' => 1], $report);

        $maria = Customer::withoutGlobalScopes()->where('phone_e164', '+15125550147')->sole();
        $this->assertSame('backfill', $maria->source);
        $this->assertSame('2026-09-01 15:00:00', $maria->timeline()->where('type', 'call_incoming')->reorder('occurred_at')->first()->occurred_at->format('Y-m-d H:i:s'));

        $again = app(CustomerBackfill::class)->run();
        $this->assertSame(0, $again['calls_linked'], 'idempotent');
    }

    public function test_customer_list_search_filters_and_isolation(): void
    {
        [$owner, $org] = $this->business();
        [, $other] = $this->business();
        Customer::create(['organization_id' => $org->id, 'first_name' => 'Maria', 'last_name' => 'Lopez', 'phone' => '(512) 555-0147']);
        Customer::create(['organization_id' => $org->id, 'first_name' => 'Tom', 'last_name' => 'Reed', 'email' => 'tom@example.test', 'status' => 'customer']);
        Customer::create(['organization_id' => $other->id, 'first_name' => 'Secret', 'last_name' => 'Person', 'phone' => '(512) 555-0199']);

        $this->actingAs($owner);
        Livewire::test(Index::class)
            ->assertSee('Maria Lopez')->assertSee('Tom Reed')->assertDontSee('Secret Person')
            ->set('search', '555-0147')->assertSee('Maria Lopez')->assertDontSee('Tom Reed')
            ->set('search', 'maria lop')->assertSee('Maria Lopez')
            ->set('search', '')->set('status', 'customer')->assertSee('Tom Reed')->assertDontSee('Maria Lopez');
    }

    public function test_manual_add_prevents_duplicates(): void
    {
        [$owner, $org] = $this->business();
        Customer::create(['organization_id' => $org->id, 'first_name' => 'Maria', 'last_name' => 'Lopez', 'phone' => '(512) 555-0147']);

        $this->actingAs($owner);
        Livewire::test(Index::class)->call('add')
            ->set('form.first_name', 'Someone')->set('form.phone', '512.555.0147')
            ->call('create')
            ->assertHasErrors(['form.phone' => 'Maria Lopez already has this number.']);

        Livewire::test(Index::class)->call('add')
            ->set('form.first_name', 'Ana')->set('form.phone', '(512) 555-0199')
            ->call('create')
            ->assertHasNoErrors()
            ->assertRedirect();

        $this->assertDatabaseHas('customers', ['phone_e164' => '+15125550199', 'source' => 'manual']);
    }

    public function test_detail_page_edit_consent_note_and_tags(): void
    {
        [$owner, $org] = $this->business();
        $customer = Customer::create(['organization_id' => $org->id, 'first_name' => 'Maria', 'phone' => '(512) 555-0147']);

        $this->actingAs($owner)->get(route('app.customers.show', $customer))->assertOk()->assertSee('Maria');

        Livewire::test(Show::class, ['customer' => $customer->ulid])
            ->call('edit')
            ->set('form.last_name', 'Lopez')
            ->set('form.status', 'customer')
            ->set('form.sms_consent', true)
            ->call('save')->assertHasNoErrors()
            ->set('note', 'Prefers mornings.')->call('addNote')
            ->set('newTag', 'VIP')->call('addTag')
            ->assertSee('Prefers mornings.')
            ->assertSee('VIP');

        $customer->refresh();
        $this->assertSame('Lopez', $customer->last_name);
        $this->assertTrue($customer->sms_consent);
        $this->assertNotNull($customer->sms_consent_at);
        $this->assertStringContainsString($owner->name, $customer->consent_source);
        $this->assertDatabaseHas('audit_logs', ['action' => 'customer.updated', 'subject_id' => $customer->id]);
    }

    public function test_other_businesss_customer_is_not_found(): void
    {
        [$owner] = $this->business();
        [, $other] = $this->business();
        $theirs = Customer::create(['organization_id' => $other->id, 'first_name' => 'Secret', 'phone' => '(512) 555-0199']);

        $this->actingAs($owner)->get(route('app.customers.show', $theirs))->assertNotFound();
    }

    public function test_export_and_api_are_scoped(): void
    {
        [$owner, $org] = $this->business();
        [, $other] = $this->business();
        $mine = Customer::create(['organization_id' => $org->id, 'first_name' => 'Maria', 'phone' => '(512) 555-0147']);
        $theirs = Customer::create(['organization_id' => $other->id, 'first_name' => 'Secret', 'phone' => '(512) 555-0199']);

        $csv = $this->actingAs($owner)->get(route('app.customers.export'))->assertOk()->streamedContent();
        $this->assertStringContainsString('Maria', $csv);
        $this->assertStringNotContainsString('Secret', $csv);

        Sanctum::actingAs($owner);
        $this->getJson('/api/v1/client/customers')->assertOk()->assertJsonCount(1, 'data.customers')
            ->assertJsonPath('data.customers.0.id', $mine->ulid)->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/client/customers/'.$mine->ulid)->assertOk()->assertJsonStructure(['data' => ['customer', 'timeline']]);
        $this->getJson('/api/v1/client/customers/'.$theirs->ulid)->assertNotFound();
    }
}
