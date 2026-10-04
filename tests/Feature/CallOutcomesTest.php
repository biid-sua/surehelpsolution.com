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

    private function postCall(User $client, string $outcome)
    {
        return $this->actingAs($this->agent)->postJson(route('admin.call-logs.store'), [
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

        CallOutcome::create(['organization_id' => $org->id, 'key' => 'scheduled-appointment', 'label' => 'Job booked', 'category' => 'spam', 'is_active' => true]);
        CallOutcome::create(['organization_id' => $org->id, 'key' => 'wrong-number', 'label' => 'Wrong number', 'category' => 'spam', 'is_active' => false]);
        CallOutcome::create(['organization_id' => $org->id, 'key' => 'custom-sent-price-list', 'label' => 'Sent price list', 'category' => 'information']);

        $registry = $this->registry();
        $mine = $registry->effective($org);

        $this->assertSame('Job booked', $mine['scheduled-appointment']['label']);
        $this->assertSame(OutcomeCategory::Booked, $mine['scheduled-appointment']['category'], 'a default keeps its meaning');
        $this->assertFalse($registry->isAllowed($org, 'wrong-number'));
        $this->assertTrue($registry->isAllowed($org, 'custom-sent-price-list'));
        $this->assertSame('custom-sent-price-list', $mine->keys()->last(), 'custom outcomes come after defaults');

        // Another business is untouched.
        $this->assertNotSame('Job booked', $registry->label($other, 'scheduled-appointment'));
        $this->assertTrue($registry->isAllowed($other, 'wrong-number'));
        $this->assertFalse($registry->isAllowed($other, 'custom-sent-price-list'));
    }

    public function test_calls_only_accept_the_businesss_active_outcomes(): void
    {
        [$owner, $org] = $this->business();
        CallOutcome::create(['organization_id' => $org->id, 'key' => 'custom-sent-price-list', 'label' => 'Sent price list', 'category' => 'information']);
        CallOutcome::create(['organization_id' => $org->id, 'key' => 'wrong-number', 'label' => 'Wrong number', 'category' => 'spam', 'is_active' => false]);

        $this->postCall($owner, 'custom-sent-price-list')->assertOk();
        $this->postCall($owner, 'wrong-number')->assertStatus(422)->assertJsonValidationErrors('call_outcome');
        $this->postCall($owner, 'made-up-outcome')->assertStatus(422)->assertJsonValidationErrors('call_outcome');

        $this->assertSame(1, CallLog::withoutGlobalScopes()->count());
    }

    public function test_custom_outcome_category_drives_notifications_and_metrics(): void
    {
        [$owner, $org] = $this->business();
        CallOutcome::create(['organization_id' => $org->id, 'key' => 'custom-no-show', 'label' => 'Caller hung up', 'category' => 'missed']);
        CallOutcome::create(['organization_id' => $org->id, 'key' => 'custom-quote-booked', 'label' => 'Quote visit booked', 'category' => 'booked']);

        $this->postCall($owner, 'custom-no-show')->assertOk();
        $this->postCall($owner, 'custom-quote-booked')->assertOk();

        $missed = CallLog::withoutGlobalScopes()->where('call_outcome', 'custom-no-show')->first();
        $this->assertSame(OutcomeCategory::Missed, $missed->outcomeCategory());
        $this->assertSame(NotificationEvent::CallMissed, OutcomeCategory::Missed->notificationEvent());
        $this->assertSame('Caller hung up', $missed->statusLabel());

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

        // Platform rows are never edited by a business.
        $this->assertSame('Appointment booked', CallOutcome::whereNull('organization_id')->where('key', 'scheduled-appointment')->value('label'));

        Livewire::test(Outcomes::class)->call('delete', 'custom-sent-price-list');
        $this->assertFalse($this->registry()->effective($org)->has('custom-sent-price-list'));
    }

    public function test_used_custom_outcomes_cannot_be_deleted(): void
    {
        [$owner, $org] = $this->business();
        CallOutcome::create(['organization_id' => $org->id, 'key' => 'custom-sent-price-list', 'label' => 'Sent price list', 'category' => 'information']);
        $this->postCall($owner, 'custom-sent-price-list')->assertOk();

        $this->actingAs($owner);
        Livewire::test(Outcomes::class)->call('delete', 'custom-sent-price-list')->assertDispatched('toast');
        $this->assertDatabaseHas('call_outcomes', ['organization_id' => $org->id, 'key' => 'custom-sent-price-list']);
    }

    public function test_manager_manages_outcomes_and_staff_cannot_open(): void
    {
        [, $org] = $this->business();
        $member = function (string $role) use ($org): User {
            $user = User::factory()->create(['role' => 'client', 'is_active' => true, 'must_change_password' => false]);
            $org->members()->attach($user->id, ['role' => $role, 'status' => 'active']);

            return $user;
        };
        $manager = $member('manager');
        $staff = $member('staff');

        $this->actingAs($manager)->get(route('app.business.outcomes'))->assertOk()->assertSee('Add your own outcome');
        Livewire::test(Outcomes::class)->call('toggle', 'wrong-number')->assertOk();
        $this->assertFalse($this->registry()->isAllowed($org, 'wrong-number'));

        $this->actingAs($staff)->get(route('app.business.outcomes'))->assertForbidden();
    }

    public function test_agent_client_list_carries_each_businesss_outcomes(): void
    {
        [$owner, $org] = $this->business();
        CallOutcome::create(['organization_id' => $org->id, 'key' => 'custom-sent-price-list', 'label' => 'Sent price list', 'category' => 'information']);

        $clients = $this->actingAs($this->agent)->getJson(route('admin.clients.list'))->assertOk()->json('clients');
        $row = collect($clients)->firstWhere('id', $owner->id);

        $this->assertContains('custom-sent-price-list', array_column($row['call_outcomes'], 'key'));
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
