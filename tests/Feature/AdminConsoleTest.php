<?php

namespace Tests\Feature;

use App\Actions\Organizations\ProvisionUserTenancy;
use App\Enums\CallOwnershipSource;
use App\Livewire\Admin\Calls\Review;
use App\Livewire\Admin\Organizations\Index as OrganizationIndex;
use App\Livewire\Admin\Organizations\Show as OrganizationShow;
use App\Models\CallLog;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * P1-4 — admin console: organizations, agent assignments (D3), call review queue (D2).
 */
class AdminConsoleTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        config(['tenancy.auto_assign_agents' => false]);
        $this->admin = User::factory()->create(['role' => 'admin', 'is_active' => true, 'must_change_password' => false]);
    }

    private function user(string $role, array $attributes = []): User
    {
        return User::factory()->create(array_merge(['role' => $role, 'is_active' => true, 'must_change_password' => false], $attributes));
    }

    private function makeCall(array $attributes): CallLog
    {
        return CallLog::withoutGlobalScopes()->create(array_merge([
            'call_id' => CallLog::generateCallId(),
            'call_date' => now()->toDateString(),
            'call_time' => '10:00',
            'reason_for_call' => 'general-inquiry',
            'call_outcome' => 'resolved-by-agent',
            'agent_name' => 'Agent',
            'status' => 'new',
            'user_id' => $this->user('agent')->id,
        ], $attributes));
    }

    public function test_login_sends_admins_to_the_new_console(): void
    {
        $this->user('admin', ['email' => 'boss@surehelp.test', 'password' => 'Secret123!']);

        $this->postJson(route('auth.login'), ['email' => 'boss@surehelp.test', 'password' => 'Secret123!'])
            ->assertJsonPath('redirect', route('admin.home'));
    }

    public function test_console_pages_render_for_admins_only(): void
    {
        $organization = app(ProvisionUserTenancy::class)->handle($this->user('client', ['name' => 'Rapid Plumbing']));

        foreach ([route('admin.home'), route('admin.organizations.index'), route('admin.organizations.show', $organization), route('admin.calls.review')] as $url) {
            $this->actingAs($this->admin)->get($url)->assertOk();
            $this->actingAs($this->user('agent'))->get($url)->assertForbidden();
            $this->actingAs($this->user('client'))->get($url)->assertForbidden();
        }
    }

    public function test_organization_urls_use_the_public_ulid(): void
    {
        $organization = Organization::factory()->create();

        $this->assertStringContainsString($organization->ulid, route('admin.organizations.show', $organization));
        $this->actingAs($this->admin)->get('/admin/organizations/'.$organization->id)->assertNotFound();
    }

    public function test_organization_list_search(): void
    {
        Organization::factory()->create(['name' => 'Rapid Plumbing']);
        Organization::factory()->create(['name' => 'Sunny Dental']);

        $this->actingAs($this->admin);
        Livewire::test(OrganizationIndex::class)
            ->assertSee('Rapid Plumbing')
            ->assertSee('Sunny Dental')
            ->set('search', 'dental')
            ->assertSee('Sunny Dental')
            ->assertDontSee('Rapid Plumbing');
    }

    public function test_admin_assigns_and_removes_agents(): void
    {
        $organization = Organization::factory()->create();
        $agent = $this->user('agent', ['name' => 'Rita Agent']);

        $this->actingAs($this->admin);
        Livewire::test(OrganizationShow::class, ['organization' => $organization])
            ->set('agentToAdd', (string) $agent->id)
            ->call('assignAgent')
            ->assertDispatched('toast')
            ->assertSee('Rita Agent');

        $this->assertTrue($organization->hasAgent($agent));
        $this->assertSame('manual', $organization->agents()->first()->pivot->source);
        $this->assertTrue($agent->fresh()->can('calls.create', $organization), 'assignment grants agent permissions');

        Livewire::test(OrganizationShow::class, ['organization' => $organization])
            ->call('unassignAgent', $agent->id);

        $this->assertFalse($organization->fresh()->hasAgent($agent));
        $this->assertFalse($agent->fresh()->can('calls.create', $organization), 'removal revokes them immediately');
    }

    public function test_cannot_assign_inactive_or_non_agent_users(): void
    {
        $organization = Organization::factory()->create();
        $inactive = $this->user('agent', ['is_active' => false]);
        $client = $this->user('client');

        $this->actingAs($this->admin);
        foreach ([$inactive, $client] as $user) {
            Livewire::test(OrganizationShow::class, ['organization' => $organization])
                ->set('agentToAdd', (string) $user->id)
                ->call('assignAgent')
                ->assertHasErrors('agentToAdd');
        }

        $this->assertSame(0, $organization->agents()->count());
    }

    public function test_admin_updates_name_and_timezone_with_validation(): void
    {
        $organization = Organization::factory()->create(['timezone' => null]);

        $this->actingAs($this->admin);
        Livewire::test(OrganizationShow::class, ['organization' => $organization])
            ->set('timezone', 'Mars/Olympus')
            ->call('save')
            ->assertHasErrors('timezone')
            ->set('name', 'Rapid Plumbing LLC')
            ->set('timezone', 'America/Chicago')
            ->call('save')
            ->assertHasNoErrors();

        $organization->refresh();
        $this->assertSame('Rapid Plumbing LLC', $organization->name);
        $this->assertSame('America/Chicago', $organization->timezone);
    }

    public function test_support_agent_can_view_but_not_change_organizations(): void
    {
        $organization = Organization::factory()->create(['name' => 'Rapid Plumbing']);
        $support = $this->user('admin');
        $support->syncRoles(['support_agent']);
        $agent = $this->user('agent');

        $this->actingAs($support);
        Livewire::test(OrganizationShow::class, ['organization' => $organization])
            ->assertSee('Rapid Plumbing')
            ->set('agentToAdd', (string) $agent->id)
            ->call('assignAgent')
            ->assertForbidden();

        $this->assertSame(0, $organization->agents()->count());
    }

    public function test_review_queue_confirms_email_matches_and_assigns_unassigned_calls(): void
    {
        $organization = Organization::factory()->create(['name' => 'Rapid Plumbing']);
        $emailMatch = $this->makeCall(['organization_id' => $organization->id, 'ownership_source' => CallOwnershipSource::EmailMatch, 'caller_name' => 'Matched Caller']);
        $orphan = $this->makeCall(['organization_id' => null, 'ownership_source' => CallOwnershipSource::Unassigned, 'caller_name' => 'Orphan Caller']);
        $fine = $this->makeCall(['organization_id' => $organization->id, 'ownership_source' => CallOwnershipSource::Direct, 'caller_name' => 'Normal Caller']);

        $this->actingAs($this->admin);
        $component = Livewire::test(Review::class)
            ->assertSee('Matched Caller')
            ->assertSee('Orphan Caller')
            ->assertDontSee('Normal Caller');

        $component->call('confirm', $emailMatch->id)->assertDispatched('toast');
        $this->assertSame(CallOwnershipSource::Reviewed, $emailMatch->fresh()->ownership_source);

        $component->call('assign', $orphan->id)->assertHasErrors("assignTo.{$orphan->id}");
        $component->set("assignTo.{$orphan->id}", (string) $organization->id)->call('assign', $orphan->id);
        $this->assertSame($organization->id, $orphan->fresh()->organization_id);
        $this->assertSame(CallOwnershipSource::Reviewed, $orphan->fresh()->ownership_source);

        // Calls outside the queue can't be touched through it.
        $this->expectException(ModelNotFoundException::class);
        $component->call('confirm', $fine->id);
    }

    public function test_navigation_marks_classic_screens(): void
    {
        $this->actingAs($this->admin)->get(route('admin.home'))
            ->assertOk()
            ->assertSee('Organizations')
            ->assertSee('Call review')
            ->assertSee('Classic');
    }
}
