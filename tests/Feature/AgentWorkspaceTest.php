<?php

namespace Tests\Feature;

use App\Actions\Organizations\ProvisionUserTenancy;
use App\Enums\BusinessRuleType;
use App\Enums\EscalationType;
use App\Enums\TaskType;
use App\Livewire\Agent\Home;
use App\Livewire\Agent\Workspace;
use App\Models\Appointment;
use App\Models\BusinessHour;
use App\Models\BusinessRule;
use App\Models\BusinessService;
use App\Models\CallLog;
use App\Models\Customer;
use App\Models\Escalation;
use App\Models\KnowledgeItem;
use App\Models\Organization;
use App\Models\Task;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * P2-7 — agent workspace (spec §15, §20, §21).
 */
class AgentWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private const TZ = 'America/Chicago';

    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Carbon::setTestNow(CarbonImmutable::parse('2026-10-06 10:00', self::TZ)); // Tuesday morning
        $this->agent = User::factory()->create(['role' => 'agent', 'is_active' => true, 'must_change_password' => false, 'name' => 'Jamie Agent']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array{0: User, 1: Organization} */
    private function business(string $name = 'Rivera Plumbing', bool $assign = true): array
    {
        $owner = User::factory()->create(['role' => 'client', 'is_active' => true, 'must_change_password' => false]);
        $organization = app(ProvisionUserTenancy::class)->handle($owner);
        $organization->update(['timezone' => self::TZ, 'name' => $name]);
        foreach ([1, 2, 3, 4, 5] as $day) {
            BusinessHour::create(['organization_id' => $organization->id, 'day_of_week' => $day, 'opens_at' => '08:00', 'closes_at' => '17:00']);
        }
        if ($assign) {
            $organization->assignAgent($this->agent);
        }

        return [$owner, $organization->fresh()];
    }

    private function workspace(Organization $organization)
    {
        $this->actingAs($this->agent);

        return Livewire::test(Workspace::class, ['organization' => $organization]);
    }

    public function test_agents_land_on_their_businesses(): void
    {
        [, $mine] = $this->business('Rivera Plumbing');
        [, $notMine] = $this->business('Secret Dental', assign: false);

        // Agents must use two-step sign-in (D8): without it they set it up first.
        $this->postJson(route('auth.login'), ['email' => $this->agent->email, 'password' => 'password'])
            ->assertJsonPath('redirect', route('agent.home'));
        $this->get(route('agent.home'))->assertRedirect(route('account.security'));
        $this->flushSession();

        $this->actingAs($this->agent)->get(route('agent.home'))->assertOk()
            ->assertSee('Rivera Plumbing')->assertSee('Open until 5 PM')->assertDontSee('Secret Dental');

        $this->get(route('agent.businesses.show', $mine))->assertOk()->assertSee('Who is calling?');
        $this->get(route('agent.businesses.show', $notMine))->assertNotFound(); // D40: as if it didn't exist

        $client = User::factory()->create(['role' => 'client', 'is_active' => true, 'must_change_password' => false]);
        $this->actingAs($client)->get(route('agent.home'))->assertForbidden();

        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true, 'must_change_password' => false]);
        Livewire::actingAs($admin)->test(Home::class)->assertSee('Rivera Plumbing')->assertSee('Secret Dental');
    }

    public function test_briefing_shows_rules_agent_knowledge_and_services(): void
    {
        [, $org] = $this->business();
        BusinessService::create(['organization_id' => $org->id, 'name' => 'Drain cleaning', 'duration_minutes' => 60, 'price_type' => 'fixed', 'price_cents' => 12900, 'agent_instructions' => 'Ask if it is the kitchen or bathroom.']);
        BusinessRule::create(['organization_id' => $org->id, 'type' => BusinessRuleType::Instruction, 'config' => ['text' => 'Never give final prices for custom jobs.']]);
        KnowledgeItem::create(['organization_id' => $org->id, 'type' => 'faq', 'title' => 'Free estimates?', 'content' => 'Yes, inside city limits.', 'visibility' => 'public']);
        KnowledgeItem::create(['organization_id' => $org->id, 'type' => 'policy', 'title' => 'Owner margin notes', 'content' => 'Private', 'visibility' => 'team_only']);

        $this->workspace($org)
            ->assertSee('Never give final prices for custom jobs.')
            ->assertSee('Free estimates?')->assertSee('OK to share')
            ->assertDontSee('Owner margin notes')
            ->assertSee('Drain cleaning')->assertSee('$129')->assertSee('Ask if it is the kitchen or bathroom.')
            ->set('knowledgeSearch', 'estimates')->assertSee('Yes, inside city limits.');
    }

    public function test_a_known_caller_is_confirmed_before_merging(): void
    {
        [, $org] = $this->business();
        $maria = Customer::create(['organization_id' => $org->id, 'first_name' => 'Maria', 'last_name' => 'Lopez', 'phone' => '(512) 555-0147']);

        $component = $this->workspace($org)
            ->set('entry.phone', '512.555.0147')
            ->assertSee('This number belongs to')->assertSee('Maria Lopez')
            ->set('entry.reason', 'service-request')
            ->set('entry.outcome', 'resolved-by-agent')
            ->call('save')
            ->assertHasErrors('customer');
        $this->assertSame(0, CallLog::withoutGlobalScopes()->count(), 'nothing saved until the agent answers');

        $component->call('confirmCustomer', $maria->id)
            ->assertSet('entry.name', 'Maria Lopez')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('entry.phone', '');

        $call = CallLog::withoutGlobalScopes()->sole();
        $this->assertSame($maria->id, $call->customer_id);
        $this->assertSame($org->id, $call->organization_id);
        $this->assertSame((string) $org->owner_user_id, (string) $call->client_id);
        $this->assertSame('Jamie', $call->agent_name);
        $this->assertSame(1, Customer::withoutGlobalScopes()->count(), 'no duplicate');

        // Same number, different person: a new record, the number stays with Maria.
        $this->workspace($org)
            ->set('entry.phone', '(512) 555-0147')->set('entry.name', 'Luis Lopez')
            ->call('differentPerson')
            ->set('entry.reason', 'general-inquiry')->set('entry.outcome', 'provided-information')
            ->call('save')->assertHasNoErrors();
        $luis = Customer::withoutGlobalScopes()->where('first_name', 'Luis')->sole();
        $this->assertNull($luis->phone);
        $this->assertSame($luis->id, CallLog::withoutGlobalScopes()->latest('id')->first()->customer_id);
    }

    public function test_booking_on_a_call_follows_hours_and_rules_and_saves_together(): void
    {
        [, $org] = $this->business();
        $service = BusinessService::create(['organization_id' => $org->id, 'name' => 'Water heater repair', 'duration_minutes' => 90, 'price_type' => 'quote_required']);
        BusinessRule::create(['organization_id' => $org->id, 'type' => BusinessRuleType::RequireDetail, 'config' => ['field' => 'address']]);
        Appointment::create(['organization_id' => $org->id, 'title' => 'Existing', 'starts_at' => CarbonImmutable::parse('2026-10-07 09:00', self::TZ),
            'ends_at' => CarbonImmutable::parse('2026-10-07 10:30', self::TZ), 'blocked_until' => CarbonImmutable::parse('2026-10-07 10:30', self::TZ), 'timezone' => self::TZ]);

        $component = $this->workspace($org)
            ->set('entry.phone', '(512) 555-0199')->set('entry.name', 'Grace Kim')
            ->set('entry.reason', 'appointment-scheduling')->set('entry.outcome', 'scheduled-appointment')
            ->set('entry.book', true)
            ->set('entry.service_id', (string) $service->id)
            ->set('entry.date', '2026-10-07')
            ->assertDontSeeHtml("pickTime('2026-10-07 09:00')")->assertSeeHtml("pickTime('2026-10-07 10:30')")
            ->call('pickTime', '2026-10-07 10:30')
            ->call('save')
            ->assertHasErrors('entry.address');
        $this->assertSame(0, CallLog::withoutGlobalScopes()->count(), 'call and booking are saved together or not at all');

        // A time someone else took meanwhile: refused with suggestions, still nothing saved.
        $component->set('entry.address', '500 Congress Ave, Austin, TX 78701')->set('entry.time', '09:00')->call('save')->assertHasErrors('entry.time')->assertNotSet('suggestions', []);
        $this->assertSame(0, CallLog::withoutGlobalScopes()->count());

        $component->call('pickTime', '2026-10-07 10:30')->call('save')->assertHasNoErrors();

        $call = CallLog::withoutGlobalScopes()->sole();
        $appointment = Appointment::withoutGlobalScopes()->where('call_log_id', $call->id)->sole();
        $this->assertSame('agent', $appointment->source);
        $this->assertSame($call->customer_id, $appointment->customer_id);
        $this->assertSame('10:30', $appointment->localStart()->format('H:i'));
        $this->assertSame($this->agent->id, $appointment->booked_by_user_id);
        $this->assertTrue($call->service_request);
    }

    public function test_follow_ups_and_escalations_from_the_call(): void
    {
        [, $org] = $this->business();

        $this->workspace($org)
            ->set('entry.phone', '(512) 555-0111')->set('entry.name', 'Pat Doe')
            ->set('entry.reason', 'complaint')->set('entry.outcome', 'escalated-to-client')
            ->assertSee('What kind of escalation?')
            ->set('entry.escalation_type', 'complaint')
            ->set('entry.notes', 'Technician left a mess')
            ->set('entry.follow_up', true)->set('entry.follow_up_title', 'Call Pat about the clean-up')->set('entry.follow_up_date', '2026-10-08')
            ->call('save')->assertHasNoErrors();

        $call = CallLog::withoutGlobalScopes()->sole();
        $escalation = Escalation::withoutGlobalScopes()->sole();
        $this->assertSame(EscalationType::Complaint, $escalation->type);
        $this->assertSame($call->id, $escalation->call_log_id);

        $task = Task::withoutGlobalScopes()->sole();
        $this->assertSame(TaskType::FollowUp, $task->type);
        $this->assertSame('Call Pat about the clean-up', $task->title);
        $this->assertSame('2026-10-08 17:00', $task->due_at->setTimezone(self::TZ)->format('Y-m-d H:i'));
        $this->assertSame($call->customer_id, $task->customer_id);
    }

    public function test_the_workspace_rechecks_access_on_every_action(): void
    {
        [, $org] = $this->business();
        $component = $this->workspace($org)->set('entry.reason', 'other')->set('entry.outcome', 'other');

        // Unassigned mid-call: the next action is refused.
        $org->agents()->detach($this->agent->id);
        $this->actingAs($this->agent->fresh()); // each real request loads the user afresh
        $component->call('save')->assertNotFound();
        $this->assertSame(0, CallLog::withoutGlobalScopes()->count());
    }
}
