<?php

namespace Tests\Feature;

use App\Actions\Assignments\AssignAgent;
use App\Actions\Assignments\ChangeAssignment;
use App\Actions\Organizations\ProvisionUserTenancy;
use App\Livewire\Agent\Company\Tasks;
use App\Models\AgentAssignment;
use App\Models\Appointment;
use App\Models\CallLog;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\Task;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Brief §2.12 / §12: an agent assigned to Company A can work with Company A and can't reach
 * anything of Company B, on web pages, the API, or by changing ids, and loses access the moment
 * the assignment stops being current. Unassigned looks exactly like "doesn't exist".
 */
class CrossTenantAgentSecurityTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $agent;

    /** @var array{org: Organization, owner: User, customer: Customer, call: CallLog, appointment: Appointment, conversation: Conversation, task: Task} */
    private array $a;

    /** @var array{org: Organization, owner: User, customer: Customer, call: CallLog, appointment: Appointment, conversation: Conversation, task: Task} */
    private array $b;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->admin = User::factory()->create(['role' => 'admin', 'is_active' => true, 'must_change_password' => false]);
        $this->admin->syncRoles(['super_admin']);
        $this->agent = User::factory()->create(['role' => 'agent', 'is_active' => true, 'must_change_password' => false]);
        $this->agent->syncRoles(['agent']);
        $this->a = $this->company('ABC Plumbing', 'Maria');
        $this->b = $this->company('XYZ Cleaning', 'Victor');

        app(AssignAgent::class)->handle($this->a['org'], $this->agent, $this->admin);
    }

    /** @return array{org: Organization, owner: User, customer: Customer, call: CallLog, appointment: Appointment, conversation: Conversation, task: Task} */
    private function company(string $name, string $customerName): array
    {
        $owner = User::factory()->create(['role' => 'client', 'is_active' => true, 'must_change_password' => false, 'name' => $name.' owner']);
        $org = app(ProvisionUserTenancy::class)->handle($owner);
        $org->update(['name' => $name]);
        $customer = Customer::create(['organization_id' => $org->id, 'first_name' => $customerName, 'last_name' => 'Customer', 'phone' => '(512) 555-01'.random_int(10, 99)]);

        return [
            'org' => $org->fresh(),
            'owner' => $owner,
            'customer' => $customer,
            'call' => CallLog::withoutGlobalScopes()->create([
                'call_id' => CallLog::generateCallId(), 'organization_id' => $org->id, 'client_id' => $owner->id, 'call_date' => now()->toDateString(),
                'call_time' => '10:00', 'reason_for_call' => 'general-inquiry', 'call_outcome' => 'resolved-by-agent', 'agent_name' => 'Agent',
                'status' => 'new', 'user_id' => $this->agent->id, 'caller_name' => $customerName.' caller',
            ]),
            'appointment' => Appointment::create(['organization_id' => $org->id, 'customer_id' => $customer->id, 'title' => $name.' visit',
                'starts_at' => now()->addHours(3), 'ends_at' => now()->addHours(4), 'blocked_until' => now()->addHours(4), 'timezone' => 'UTC']),
            'conversation' => Conversation::create(['organization_id' => $org->id, 'channel' => 'web_chat', 'channel_key' => 'web', 'external_thread_id' => 't-'.$name, 'contact_name' => $customerName.' chat', 'last_inbound_at' => now()]),
            'task' => Task::create(['organization_id' => $org->id, 'title' => 'Call back '.$customerName, 'customer_id' => $customer->id]),
        ];
    }

    /** @return list<string> */
    private function webPages(array $c, array $nestedFrom): array
    {
        $o = $c['org']->ulid;

        return [
            route('agent.businesses.show', $o),
            route('agent.businesses.customers', $o),
            route('agent.businesses.customers.show', [$o, $nestedFrom['customer']->ulid]),
            route('agent.businesses.appointments', $o),
            route('agent.businesses.messages', $o),
            route('agent.businesses.messages', [$o, $nestedFrom['conversation']->ulid]),
            route('agent.businesses.tasks', $o),
        ];
    }

    /** @return list<string> */
    private function apiPaths(array $c, array $nestedFrom): array
    {
        $o = $c['org']->ulid;

        return [
            "/api/v1/agent/companies/{$o}",
            "/api/v1/agent/companies/{$o}/customers",
            "/api/v1/agent/companies/{$o}/customers/{$nestedFrom['customer']->ulid}",
            "/api/v1/agent/companies/{$o}/appointments",
            "/api/v1/agent/companies/{$o}/calls",
            "/api/v1/agent/companies/{$o}/conversations",
        ];
    }

    public function test_the_agent_works_with_their_own_company(): void
    {
        $this->actingAs($this->agent);
        foreach ($this->webPages($this->a, $this->a) as $url) {
            $this->get($url)->assertOk();
        }
        $this->get(route('agent.businesses.customers', $this->a['org']->ulid))->assertSee('Maria Customer');
        $this->get(route('agent.companies'))->assertOk()->assertSee('ABC Plumbing')->assertDontSee('XYZ Cleaning');

        Sanctum::actingAs($this->agent);
        foreach ($this->apiPaths($this->a, $this->a) as $path) {
            $this->getJson($path)->assertOk();
        }
        $this->getJson('/api/v1/agent/companies')->assertOk()->assertJsonCount(1, 'data.companies')->assertJsonPath('data.companies.0.name', 'ABC Plumbing');
    }

    public function test_another_company_is_indistinguishable_from_one_that_does_not_exist(): void
    {
        $this->actingAs($this->agent);
        foreach ($this->webPages($this->b, $this->b) as $url) {
            $this->get($url)->assertNotFound();
        }
        $this->get(route('agent.businesses.show', '01JZZZZZZZZZZZZZZZZZZZZZZZ'))->assertNotFound();

        Sanctum::actingAs($this->agent);
        foreach ($this->apiPaths($this->b, $this->b) as $path) {
            $this->getJson($path)->assertNotFound();
        }
        $this->getJson('/api/v1/agent/companies/01JZZZZZZZZZZZZZZZZZZZZZZZ')->assertNotFound();
    }

    public function test_changing_nested_ids_never_crosses_companies(): void
    {
        // Own company in the URL, the other company's records as the nested id.
        $this->actingAs($this->agent);
        $this->get(route('agent.businesses.customers.show', [$this->a['org']->ulid, $this->b['customer']->ulid]))->assertNotFound();
        $this->get(route('agent.businesses.messages', [$this->a['org']->ulid, $this->b['conversation']->ulid]))->assertNotFound();
        try {
            Livewire::test(Tasks::class, ['organization' => $this->a['org']])
                ->call('setStatus', $this->b['task']->ulid, 'completed');
            $this->fail('Another company\'s task must not be found');
        } catch (ModelNotFoundException) {
            $this->addToAssertionCount(1); // a 404 in a real request
        }
        $this->assertSame('open', $this->b['task']->fresh()->status->value);

        Sanctum::actingAs($this->agent);
        $this->getJson("/api/v1/agent/companies/{$this->a['org']->ulid}/customers/{$this->b['customer']->ulid}")->assertNotFound();
        $this->getJson("/api/v1/agent/companies/{$this->a['org']->ulid}/customers")->assertOk()->assertDontSee('Victor');
    }

    public function test_the_older_call_endpoints_follow_the_same_rule(): void
    {
        Sanctum::actingAs($this->agent);
        $clients = $this->getJson('/api/v1/agent/clients')->assertOk()->json('data.clients');
        $this->assertSame([$this->a['owner']->id], array_column($clients, 'id'));

        // Logging a call for Company B's owner: "not found", not "not assigned".
        $this->postJson('/api/v1/agent/call-logs', [
            'client_id' => (string) $this->b['owner']->id, 'call_date' => now()->toDateString(), 'call_time' => '10:00',
            'reason_for_call' => 'general-inquiry', 'call_outcome' => 'resolved-by-agent', 'agent_name' => 'Agent', 'status' => 'new',
        ])->assertStatus(422)->assertJsonPath('errors.client_id.0', 'The selected client was not found.');

        // Editing Company B's call by id: as if it didn't exist.
        $this->putJson("/api/v1/agent/call-logs/{$this->b['call']->id}", ['notes' => 'x'])->assertNotFound();
        $this->putJson('/api/v1/agent/call-logs/999999', ['notes' => 'x'])->assertNotFound();

        // The agent's own call history only covers companies they serve now.
        $history = $this->getJson('/api/v1/agent/call-logs')->assertOk()->json('data.call_logs');
        $this->assertSame([$this->a['call']->call_id], array_column($history, 'call_id'));
    }

    public function test_access_follows_the_assignment_lifecycle(): void
    {
        $assignment = AgentAssignment::sole();
        $url = route('agent.businesses.customers', $this->a['org']->ulid);
        // Each real request loads the user afresh; so does every step here.
        $as = fn () => $this->actingAs($this->agent->fresh());

        app(ChangeAssignment::class)->suspend($assignment, $this->admin, 'Investigating a complaint');
        $as()->get($url)->assertNotFound();
        app(ChangeAssignment::class)->resume($assignment->fresh(), $this->admin);
        $as()->get($url)->assertOk();

        app(ChangeAssignment::class)->end($assignment->fresh(), $this->admin, 'Account reassigned');
        $as()->get($url)->assertNotFound();
        $as()->get(route('agent.businesses.show', $this->a['org']->ulid))->assertNotFound();
        Sanctum::actingAs($this->agent->fresh());
        $this->getJson('/api/v1/agent/companies')->assertJsonCount(0, 'data.companies');
        $this->getJson('/api/v1/agent/call-logs')->assertJsonCount(0, 'data.call_logs');

        // A future assignment gives nothing until it starts, then everything, without waiting for the sweep.
        app(AssignAgent::class)->handle($this->b['org'], $this->agent, $this->admin, ['starts_at' => CarbonImmutable::now()->addDays(2)]);
        $as()->get(route('agent.businesses.customers', $this->b['org']->ulid))->assertNotFound();
        $this->travel(3)->days();
        $this->flushSession(); // three idle days end any session; this is a new sign-in
        $as()->get(route('agent.businesses.customers', $this->b['org']->ulid))->assertOk();
    }

    public function test_billing_settings_and_platform_pages_stay_out_of_reach(): void
    {
        $this->actingAs($this->agent);
        foreach (['app.billing', 'app.business.profile', 'app.settings.privacy', 'app.social.index', 'app.inbox.index'] as $route) {
            $this->get(route($route))->assertForbidden(); // the business portal is for the business's own people
        }
        foreach (['admin.home', 'admin.billing', 'admin.organizations.index'] as $route) {
            $this->get(route($route))->assertForbidden();
        }
        $this->get(route('agent.assignments'))->assertForbidden();
        $this->get(route('agent.team'))->assertForbidden();

        Sanctum::actingAs($this->agent);
        $this->getJson('/api/v1/client/customers')->assertForbidden();
        $this->getJson('/api/v1/admin/users')->assertForbidden();
    }
}
