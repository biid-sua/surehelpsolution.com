<?php

namespace Tests\Feature;

use App\Actions\Appointments\BookAppointment;
use App\Actions\Organizations\ProvisionUserTenancy;
use App\Enums\BusinessRuleType;
use App\Enums\EscalationType;
use App\Livewire\Client\Business\Knowledge;
use App\Livewire\Client\Business\Rules;
use App\Models\BusinessHour;
use App\Models\BusinessRule;
use App\Models\BusinessService;
use App\Models\Customer;
use App\Models\Escalation;
use App\Models\KnowledgeItem;
use App\Models\Organization;
use App\Models\User;
use App\Services\Rules\BusinessRules;
use App\Services\Scheduling\Availability;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * P2-6 — knowledge base (spec §22) and business rules (spec §23).
 */
class KnowledgeAndRulesTest extends TestCase
{
    use RefreshDatabase;

    private const TZ = 'America/Chicago';

    protected function setUp(): void
    {
        parent::setUp();
        config(['tenancy.auto_assign_agents' => true]); // written before D40: every agent serves every business
        Notification::fake();
        Carbon::setTestNow(CarbonImmutable::parse('2026-10-05 08:00', self::TZ)); // Monday
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array{0: User, 1: Organization} */
    private function business(): array
    {
        $owner = User::factory()->create(['role' => 'client', 'is_active' => true, 'must_change_password' => false]);
        $organization = app(ProvisionUserTenancy::class)->handle($owner);
        $organization->update(['timezone' => self::TZ]);
        foreach ([1, 2, 3, 4, 5] as $day) {
            BusinessHour::create(['organization_id' => $organization->id, 'day_of_week' => $day, 'opens_at' => '08:00', 'closes_at' => '20:00']);
        }

        return [$owner, $organization->fresh()];
    }

    private function member(Organization $organization, string $role): User
    {
        $user = User::factory()->create(['role' => 'client', 'is_active' => true, 'must_change_password' => false]);
        $organization->members()->attach($user->id, ['role' => $role, 'status' => 'active']);

        return $user;
    }

    /** @param  array<string, mixed>  $config */
    private function rule(Organization $organization, BusinessRuleType $type, array $config): BusinessRule
    {
        app(BusinessRules::class)->forget();

        return BusinessRule::create(['organization_id' => $organization->id, 'type' => $type, 'config' => $config]);
    }

    /** @param  array<string, mixed>  $data */
    private function agentBooks(Organization $organization, string $local, array $data = []): void
    {
        app(BookAppointment::class)->handle($organization, ['starts_at' => CarbonImmutable::parse($local, self::TZ)] + $data, null, 'agent', strict: true);
    }

    private function assertRefused(callable $fn, string $field, string $contains): void
    {
        try {
            $fn();
            $this->fail("expected a {$field} rule error");
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($field, $e->errors());
            $this->assertStringContainsString($contains, $e->errors()[$field][0]);
        }
    }

    public function test_owner_builds_the_knowledge_base(): void
    {
        [$owner, $org] = $this->business();
        $this->actingAs($owner)->get(route('app.business.knowledge'))->assertOk()->assertSee('Teach our agents about your business');

        Livewire::test(Knowledge::class)
            ->call('create')
            ->set('form.type', 'faq')
            ->set('form.title', 'Do you charge for estimates?')
            ->set('form.content', 'No, estimates are free within Austin city limits.')
            ->set('form.visibility', 'public')
            ->set('form.is_pinned', true)
            ->call('save')
            ->assertHasNoErrors()
            ->call('create')
            ->set('form.type', 'policy')
            ->set('form.title', 'Owner pricing notes')
            ->set('form.content', 'Margin targets, never shared.')
            ->set('form.visibility', 'team_only')
            ->call('save')
            ->assertHasNoErrors();

        $faq = KnowledgeItem::withoutGlobalScopes()->where('title', 'Do you charge for estimates?')->sole();
        $this->assertSame($owner->id, $faq->updated_by_user_id);
        $this->assertDatabaseHas('audit_logs', ['action' => 'knowledge.created', 'organization_id' => $org->id]);

        // Agents never see team-only items; pinned items come first.
        $forAgents = KnowledgeItem::query()->forOrganization($org)->forAgents()->ordered()->pluck('title')->all();
        $this->assertSame(['Do you charge for estimates?'], $forAgents);

        Livewire::test(Knowledge::class)->set('search', 'estimates')->assertSee('free within Austin')->assertDontSee('Margin targets')
            ->call('toggle', $faq->ulid);
        $this->assertFalse($faq->fresh()->is_active);
        $this->assertSame([], KnowledgeItem::query()->forOrganization($org)->forAgents()->pluck('title')->all());
    }

    public function test_knowledge_permissions_and_isolation(): void
    {
        [, $org] = $this->business();
        [, $other] = $this->business();
        $manager = $this->member($org, 'manager');
        $staff = $this->member($org, 'staff');
        $theirs = KnowledgeItem::create(['organization_id' => $other->id, 'type' => 'faq', 'title' => 'Their secret', 'content' => 'x']);

        $this->actingAs($manager)->get(route('app.business.knowledge'))->assertOk()->assertDontSee('Their secret');
        Livewire::test(Knowledge::class)->call('create')->assertOk();
        $this->actingAs($staff)->get(route('app.business.knowledge'))->assertForbidden();

        $this->actingAs($manager);
        $this->expectException(ModelNotFoundException::class);
        Livewire::test(Knowledge::class)->call('edit', $theirs->ulid);
    }

    public function test_rules_are_added_described_and_toggled(): void
    {
        [$owner, $org] = $this->business();
        $service = BusinessService::create(['organization_id' => $org->id, 'name' => 'Emergency call-out', 'duration_minutes' => 60, 'price_type' => 'quote_required']);

        $this->actingAs($owner);
        Livewire::test(Rules::class)
            ->set('type', 'booking_cutoff')->set('config.time', '17:00')->set('config.service_id', (string) $service->id)->call('add')->assertHasNoErrors()
            ->set('type', 'require_detail')->set('config.field', 'address')->call('add')->assertHasNoErrors()
            ->set('type', 'service_area')->set('config.postal_codes', '78701, 78702 7870x')->call('add')->assertHasErrors('config.postal_codes')
            ->set('config.postal_codes', '78701, 78702')->call('add')->assertHasNoErrors()
            ->set('type', 'auto_escalate')->set('config.reasons', ['complaint'])->set('config.escalation_type', 'complaint')->call('add')->assertHasNoErrors()
            ->set('type', 'booking_window')->set('config.min_notice_hours', '2')->set('config.max_days_ahead', '30')->call('add')->assertHasNoErrors()
            ->set('type', 'instruction')->set('config.text', 'Never give final prices for custom jobs.')->call('add')->assertHasNoErrors();

        $briefing = app(BusinessRules::class)->briefing($org);
        $this->assertSame('Never give final prices for custom jobs.', $briefing[0], 'instructions first');
        $this->assertContains("Don't book Emergency call-out starting at or after 5:00 PM.", $briefing);
        $this->assertContains('Always get the visit address before booking.', $briefing);
        $this->assertContains('Only book visits in ZIP codes 78701, 78702.', $briefing);
        $this->assertContains('Escalate complaint calls as "Complaint".', $briefing);
        $this->assertContains('Book at least 2 hours ahead. Book no more than 30 days ahead.', $briefing);
        $this->assertDatabaseHas('audit_logs', ['action' => 'business_rule.created', 'organization_id' => $org->id]);

        $this->get(route('app.business.rules'))->assertOk()->assertSee('Checked automatically');

        $rule = BusinessRule::withoutGlobalScopes()->where('type', 'instruction')->sole();
        Livewire::test(Rules::class)->call('toggle', $rule->id);
        app(BusinessRules::class)->forget();
        $this->assertNotContains('Never give final prices for custom jobs.', app(BusinessRules::class)->briefing($org));
    }

    public function test_staff_cannot_change_rules(): void
    {
        [, $org] = $this->business();
        $manager = $this->member($org, 'manager');
        $this->rule($org, BusinessRuleType::Instruction, ['text' => 'Be kind']);

        // Managers can; staff can't even open the Business section.
        $this->actingAs($manager);
        Livewire::test(Rules::class)->set('config.text', 'Smile')->call('add')->assertHasNoErrors();
        $this->actingAs($this->member($org, 'staff'))->get(route('app.business.rules'))->assertForbidden();
    }

    public function test_booking_rules_bind_agents_and_shape_free_times(): void
    {
        [, $org] = $this->business();
        $emergency = BusinessService::create(['organization_id' => $org->id, 'name' => 'Emergency call-out', 'duration_minutes' => 60, 'price_type' => 'quote_required']);
        $this->rule($org, BusinessRuleType::BookingCutoff, ['time' => '17:00', 'service_id' => $emergency->id]);

        $slots = array_map(fn ($s) => $s->format('H:i'), app(Availability::class)->slots($org, '2026-10-06', 60, serviceId: $emergency->id));
        $this->assertSame('16:30', end($slots), 'no emergency starts at or after 5 PM');
        $this->assertContains('19:00', array_map(fn ($s) => $s->format('H:i'), app(Availability::class)->slots($org, '2026-10-06', 60)), 'other services unaffected');

        $this->assertRefused(fn () => $this->agentBooks($org, '2026-10-06 17:30', ['service_id' => $emergency->id]), 'starts_at', '5:00 PM');
        $this->agentBooks($org, '2026-10-06 16:00', ['service_id' => $emergency->id]);

        // The business itself may still book an evening favour from its portal.
        app(BookAppointment::class)->handle($org, ['starts_at' => CarbonImmutable::parse('2026-10-06 18:00', self::TZ), 'service_id' => $emergency->id]);

        // Booking window.
        $this->rule($org, BusinessRuleType::BookingWindow, ['min_notice_minutes' => 240, 'max_days_ahead' => 14]);
        $this->assertSame('12:00', app(Availability::class)->slots($org, '2026-10-05', 60)[0]->format('H:i'), '4 hours from 8 AM');
        $this->assertSame([], app(Availability::class)->slots($org, '2026-10-26', 60));
        $this->assertRefused(fn () => $this->agentBooks($org, '2026-10-05 10:00'), 'starts_at', 'notice');
        $this->assertRefused(fn () => $this->agentBooks($org, '2026-10-27 10:00'), 'starts_at', '14 days');
    }

    public function test_address_and_service_area_rules(): void
    {
        [, $org] = $this->business();
        $this->rule($org, BusinessRuleType::RequireDetail, ['field' => 'address']);
        $this->rule($org, BusinessRuleType::ServiceArea, ['postal_codes' => ['78701', '78702']]);
        $inArea = Customer::create(['organization_id' => $org->id, 'first_name' => 'Ana', 'address_line1' => '1 Main St', 'city' => 'Austin', 'state' => 'TX', 'postal_code' => '78701']);

        $this->assertRefused(fn () => $this->agentBooks($org, '2026-10-06 10:00'), 'address', 'visit address');
        $this->assertRefused(fn () => $this->agentBooks($org, '2026-10-06 10:00', ['address' => '9 Elm St, Round Rock, TX 78664']), 'address', 'outside');
        $this->assertRefused(fn () => $this->agentBooks($org, '2026-10-06 10:00', ['address' => '9 Elm St, Austin']), 'address', 'ZIP');

        $this->agentBooks($org, '2026-10-06 10:00', ['address' => '500 Congress Ave, Austin, TX 78701-1234']);
        $this->agentBooks($org, '2026-10-06 12:00', ['customer_id' => $inArea->id]); // address on file
        $this->assertSame('78702', BusinessRules::postalCode('12345 Ranch Rd 78702'), 'the last 5-digit group is the ZIP');
    }

    public function test_calls_are_escalated_by_reason_rules(): void
    {
        [$owner, $org] = $this->business();
        $agent = User::factory()->create(['role' => 'agent', 'is_active' => true]);
        app(ProvisionUserTenancy::class)->handle($agent);
        $this->rule($org, BusinessRuleType::AutoEscalate, ['reasons' => ['complaint', 'billing-question'], 'escalation_type' => 'complaint', 'priority' => 'urgent']);

        $post = fn (string $reason) => $this->actingAs($agent)->postJson('/api/v1/agent/call-logs', [
            'client_id' => (string) $owner->id, 'call_date' => now()->toDateString(), 'call_time' => '10:00',
            'caller_name' => 'Pat', 'reason_for_call' => $reason, 'call_outcome' => 'resolved-by-agent', 'agent_name' => 'A', 'status' => 'new',
        ])->assertOk();

        $post('complaint');
        $post('general-inquiry');

        $escalation = Escalation::withoutGlobalScopes()->sole();
        $this->assertSame(EscalationType::Complaint, $escalation->type);
        $this->assertSame('urgent', $escalation->priority->value);
    }

    public function test_knowledge_and_rules_api(): void
    {
        [$owner, $org] = $this->business();
        [, $other] = $this->business();
        KnowledgeItem::create(['organization_id' => $org->id, 'type' => 'faq', 'title' => 'Hours on holidays?', 'content' => 'Closed', 'is_pinned' => true]);
        KnowledgeItem::create(['organization_id' => $org->id, 'type' => 'policy', 'title' => 'Old policy', 'content' => 'x', 'is_active' => false]);
        KnowledgeItem::create(['organization_id' => $other->id, 'type' => 'faq', 'title' => 'Not mine', 'content' => 'x']);
        $this->rule($org, BusinessRuleType::RequireDetail, ['field' => 'phone']);

        Sanctum::actingAs($owner);
        $this->getJson('/api/v1/client/knowledge')->assertOk()->assertJsonCount(1, 'data.items')->assertJsonPath('data.items.0.title', 'Hours on holidays?');
        $this->getJson('/api/v1/client/rules')->assertOk()
            ->assertJsonPath('data.rules.0.description', 'Always get a phone number before booking.')
            ->assertJsonPath('data.rules.0.enforced', true);
    }
}
