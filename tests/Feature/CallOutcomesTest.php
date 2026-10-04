<?php

namespace Tests\Feature;

use App\Actions\Organizations\ProvisionUserTenancy;
use App\Enums\NotificationEvent;
use App\Enums\OutcomeCategory;
use App\Livewire\Client\Business\Outcomes;
use App\Models\CallLog;
use App\Models\CallOutcome;
use App\Models\Organization;
use App\Models\User;
use App\Services\Calls\CallOutcomes;
use App\Services\Metrics\ClientMetrics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * P2-4a — configurable call outcomes (spec §14).
 */
class CallOutcomesTest extends TestCase
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

    private function member(Organization $organization, string $role): User
    {
        $user = User::factory()->create(['role' => 'client', 'is_active' => true, 'must_change_password' => false]);
        $organization->members()->attach($user->id, ['role' => $role, 'status' => 'active']);

        return $user;
    }

    private function postCall(User $client, string $outcome): TestResponse
    {
        return $this->actingAs($this->agent)->postJson('/api/v1/agent/call-logs', [
            'client_id' => (string) $client->id,
            'call_date' => now()->toDateString(), 'call_time' => '10:00',
            'reason_for_call' => 'service-request', 'call_outcome' => $outcome,
            'agent_name' => 'Agent', 'status' => 'new',
        ]);
    }

    private function registry(): CallOutcomes
    {
        $registry = app(CallOutcomes::class);
        $registry->forget();

        return $registry;
    }

    private function custom(Organization $organization, string $key, string $label, string $category, bool $active = true): void
    {
        CallOutcome::create(['organization_id' => $organization->id, 'key' => $key, 'label' => $label, 'category' => $category, 'is_active' => $active]);
    }

    /**
     * A deploy applying several releases runs the P2-3 customer backfill before call_outcomes exists.
     * The registry must answer "no outcomes" then, and must not remember that once the table appears.
     */
    public function test_registry_tolerates_a_missing_table_without_caching_it(): void
    {
        $registry = $this->registry();
        Schema::rename('call_outcomes', 'call_outcomes_hidden');

        try {
            $this->assertCount(0, $registry->effective(null));
            $this->assertSame([], $registry->keys(1, OutcomeCategory::Callback));
        } finally {
            Schema::rename('call_outcomes_hidden', 'call_outcomes');
        }

        $this->assertCount(11, $registry->effective(null));
        $this->assertContains('callback-requested', $registry->keys(1, OutcomeCategory::Callback));
    }

    public function test_platform_defaults_are_seeded_with_categories(): void
    {
        $defaults = $this->registry()->effective(null);

        $this->assertCount(11, $defaults);
        $this->assertSame(OutcomeCategory::Booked, $defaults['scheduled-appointment']['category']);
        $this->assertSame(OutcomeCategory::Callback, $defaults['callback-requested']['category']);
        $this->assertSame(['call-dropped', 'no-response'], $this->registry()->keys(null, OutcomeCategory::Missed));
    }

    public function test_business_overrides_and_custom_outcomes_merge_with_defaults(): void
    {
        [, $org] = $this->business();
        [, $other] = $this->business();

        $this->custom($org, 'scheduled-appointment', 'Job booked', 'spam');
        $this->custom($org, 'wrong-number', 'Wrong number', 'spam', false);
        $this->custom($org, 'custom-sent-price-list', 'Sent price list', 'information');

        $registry = $this->registry();
        $mine = $registry->effective($org);

        $this->assertSame('Job booked', $mine['scheduled-appointment']['label']);
        $this->assertSame(OutcomeCategory::Booked, $mine['scheduled-appointment']['category'], 'a default keeps its meaning');
        $this->assertFalse($registry->isAllowed($org, 'wrong-number'));
        $this->assertTrue($registry->isAllowed($org, 'custom-sent-price-list'));
        $this->assertSame('custom-sent-price-list', $mine->keys()->last(), 'custom outcomes come after defaults');

        // Another business is untouched.
        $this->assertSame('Appointment booked', $registry->label($other, 'scheduled-appointment'));
        $this->assertTrue($registry->isAllowed($other, 'wrong-number'));
        $this->assertFalse($registry->isAllowed($other, 'custom-sent-price-list'));
    }

    public function test_calls_only_accept_the_businesss_active_outcomes(): void
    {
        [$owner, $org] = $this->business();
        $this->custom($org, 'custom-sent-price-list', 'Sent price list', 'information');
        $this->custom($org, 'wrong-number', 'Wrong number', 'spam', false);

        $this->postCall($owner, 'custom-sent-price-list')->assertOk();
        $this->postCall($owner, 'wrong-number')->assertStatus(422)->assertJsonValidationErrors('call_outcome');
        $this->postCall($owner, 'made-up-outcome')->assertStatus(422)->assertJsonValidationErrors('call_outcome');

        $this->assertSame(1, CallLog::withoutGlobalScopes()->count());
    }

    public function test_custom_outcome_category_drives_notifications_and_metrics(): void
    {
        [$owner, $org] = $this->business();
        $this->custom($org, 'custom-no-show', 'Caller hung up', 'missed');
        $this->custom($org, 'custom-quote-booked', 'Quote visit booked', 'booked');

        $this->postCall($owner, 'custom-no-show')->assertOk();
        $this->postCall($owner, 'custom-quote-booked')->assertOk();

        $missed = CallLog::withoutGlobalScopes()->where('call_outcome', 'custom-no-show')->first();
        $this->assertSame(OutcomeCategory::Missed, $missed->outcomeCategory());
        $this->assertSame(NotificationEvent::CallMissed, OutcomeCategory::Missed->notificationEvent());
        $this->assertSame('Caller hung up', $missed->statusLabel());
        $this->assertSame('danger', $missed->statusTone());

        $this->registry();
        $kpis = app(ClientMetrics::class)->kpis($org, 'month');
        $this->assertSame(1, $kpis['missed']['value']);
        $this->assertSame(1, $kpis['scheduled']['value']);
    }

    public function test_owner_manages_outcomes_from_the_business_settings(): void
    {
        [$owner, $org] = $this->business();
        $this->actingAs($owner)->get(route('app.business.outcomes'))->assertOk()->assertSee('Appointment booked');

        Livewire::test(Outcomes::class)
            ->call('startRename', 'scheduled-appointment')
            ->set('renameLabel', 'Job booked')
            ->call('saveRename')
            ->assertHasNoErrors()
            ->call('toggle', 'wrong-number')
            ->set('newLabel', 'Sent price list')
            ->set('newCategory', 'information')
            ->call('add')
            ->assertHasNoErrors()
            ->set('newLabel', 'Sent price list')
            ->call('add')
            ->assertHasErrors('newLabel');

        $registry = $this->registry();
        $this->assertSame('Job booked', $registry->label($org, 'scheduled-appointment'));
        $this->assertFalse($registry->isAllowed($org, 'wrong-number'));
        $this->assertTrue($registry->isAllowed($org, 'custom-sent-price-list'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'call_outcome.created', 'organization_id' => $org->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'call_outcome.updated', 'organization_id' => $org->id]);

        // Platform rows are never edited by a business.
        $this->assertSame('Appointment booked', CallOutcome::whereNull('organization_id')->where('key', 'scheduled-appointment')->value('label'));

        Livewire::test(Outcomes::class)->call('delete', 'custom-sent-price-list');
        $this->assertFalse($this->registry()->effective($org)->has('custom-sent-price-list'));
    }

    public function test_used_custom_outcomes_cannot_be_deleted(): void
    {
        [$owner, $org] = $this->business();
        $this->custom($org, 'custom-sent-price-list', 'Sent price list', 'information');
        $this->postCall($owner, 'custom-sent-price-list')->assertOk();

        $this->actingAs($owner);
        Livewire::test(Outcomes::class)->call('delete', 'custom-sent-price-list')->assertDispatched('toast');
        $this->assertDatabaseHas('call_outcomes', ['organization_id' => $org->id, 'key' => 'custom-sent-price-list']);
    }

    public function test_managers_manage_outcomes_and_staff_cannot_open(): void
    {
        [, $org] = $this->business();
        $manager = $this->member($org, 'manager');
        $staff = $this->member($org, 'staff');

        $this->actingAs($manager)->get(route('app.business.outcomes'))->assertOk()->assertSee('Add your own outcome');
        Livewire::test(Outcomes::class)->call('toggle', 'wrong-number')->assertOk();
        $this->assertFalse($this->registry()->isAllowed($org, 'wrong-number'));

        $this->actingAs($staff)->get(route('app.business.outcomes'))->assertForbidden();
    }

    public function test_agent_client_lists_carry_each_businesss_outcomes(): void
    {
        [$owner, $org] = $this->business();
        $this->custom($org, 'custom-sent-price-list', 'Sent price list', 'information');
        $this->custom($org, 'wrong-number', 'Wrong number', 'spam', false);

        $clients = $this->actingAs($this->agent)->getJson('/api/v1/agent/clients')->assertOk()->json('data.clients');
        $keys = array_column(collect($clients)->firstWhere('id', $owner->id)['call_outcomes'], 'key');

        $this->assertContains('custom-sent-price-list', $keys);
        $this->assertNotContains('wrong-number', $keys);
    }

    public function test_admin_success_rate_counts_booked_and_information_outcomes(): void
    {
        $keys = $this->registry()->keys(null, OutcomeCategory::Booked, OutcomeCategory::Information);

        $this->assertContains('scheduled-appointment', $keys);
        $this->assertContains('provided-information', $keys);
        $this->assertNotContains('information-provided', $keys);
        $this->assertNotContains('call-dropped', $keys);
    }
}
