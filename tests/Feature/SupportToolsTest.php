<?php

namespace Tests\Feature;

use App\Actions\Organizations\ProvisionUserTenancy;
use App\Livewire\Admin\Search;
use App\Models\AuditLog;
use App\Models\CallLog;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\User;
use App\Services\Account\Impersonation;
use App\Support\Audit\Audit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Support tools (spec ADM-05, ADM-09): "view as client" and global search.
 */
class SupportToolsTest extends TestCase
{
    use RefreshDatabase;

    private function staff(string $role = 'super_admin'): User
    {
        $user = User::factory()->create(['role' => 'admin', 'is_active' => true, 'name' => 'Sam Support']);
        if ($role !== 'super_admin') {
            $user->syncRoles([$role]);
        }
        $this->enableTwoFactor($user);

        return $user;
    }

    /** @return array{0: User, 1: Organization} */
    private function business(): array
    {
        $owner = User::factory()->create(['role' => 'client', 'is_active' => true, 'must_change_password' => false, 'name' => 'Maria Rivera', 'email' => 'maria@rivera.test']);
        $org = app(ProvisionUserTenancy::class)->handle($owner);
        $org->forceFill(['name' => 'Rivera Plumbing', 'setup_completed_at' => now()])->save();

        return [$owner, $org->fresh()];
    }

    public function test_support_views_as_a_client_with_a_banner_and_every_action_is_attributed(): void
    {
        [$owner, $org] = $this->business();
        $staff = $this->staff('support_agent');

        $this->actingAs($staff)->get(route('admin.organizations.show', $org))->assertOk()->assertSee('View as Maria');
        $this->from(route('admin.organizations.show', $org))->post(route('admin.impersonate', $owner))->assertRedirect(route('app.dashboard'));
        $this->assertAuthenticatedAs($owner);
        $this->assertSame($staff->id, session(Impersonation::KEY));

        $this->get(route('app.dashboard'))->assertOk()->assertSee('viewing as Maria Rivera (Rivera Plumbing)')->assertSee('Stop viewing');
        $this->get(route('account.security'))->assertRedirect(route('app.dashboard'));   // private even now

        // Anything done meanwhile names the staff member.
        $this->post(route('auth.logout'))->assertRedirect(route('admin.organizations.show', $org));   // "sign out" = stop viewing
        $this->assertAuthenticatedAs($staff);
        $started = AuditLog::where('action', 'impersonation.started')->sole();
        $this->assertSame($staff->id, $started->actor_id);
        $this->assertSame($org->id, $started->organization_id);
        $this->assertSame($staff->id, AuditLog::where('action', 'impersonation.ended')->sole()->actor_id);

        $this->post(route('admin.impersonate', $owner));
        app(Audit::class)->record('test.action', $org);
        $this->assertSame($staff->id, AuditLog::where('action', 'test.action')->sole()->impersonator_id);
        $this->post(route('impersonation.stop'));
        $this->actingAs($this->staff())->get(route('admin.audit'))->assertOk()->assertSee('by Sam Support, viewing as them');
    }

    public function test_only_permitted_staff_and_only_active_business_users(): void
    {
        [$owner] = $this->business();
        $agent = User::factory()->create(['role' => 'agent', 'is_active' => true]);
        $otherAdmin = $this->staff();

        $this->actingAs($this->staff('agent_supervisor'))->post(route('admin.impersonate', $owner))->assertForbidden();
        $this->actingAs($otherAdmin)->post(route('admin.impersonate', $agent))->assertForbidden();
        $owner->forceFill(['is_active' => false])->save();
        $this->actingAs($otherAdmin)->post(route('admin.impersonate', $owner))->assertForbidden();
        $this->assertAuthenticatedAs($otherAdmin);

        // Clients can't use it on anyone.
        $this->actingAs(User::factory()->create(['role' => 'client', 'is_active' => true]))->post(route('admin.impersonate', $owner))->assertForbidden();
        $this->post(route('impersonation.stop'))->assertNotFound();
    }

    public function test_staff_signed_out_elsewhere_ends_viewing_as(): void
    {
        [$owner] = $this->business();
        $staff = $this->staff();
        $this->actingAs($staff)->post(route('admin.impersonate', $owner));
        $staff->forceFill(['session_epoch' => $staff->session_epoch + 1])->save();

        $this->get(route('app.dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_global_search_finds_businesses_people_callers_calls_and_appointments(): void
    {
        [$owner, $org] = $this->business();
        $agent = User::factory()->create(['role' => 'agent', 'is_active' => true]);
        Customer::create(['organization_id' => $org->id, 'first_name' => 'Ana', 'last_name' => 'Lopez', 'phone' => '(512) 555-0101']);
        CallLog::create(['user_id' => $agent->id, 'organization_id' => $org->id, 'call_id' => 'CL-20261005-0042', 'call_date' => '2026-10-05', 'call_time' => '09:00',
            'reason_for_call' => 'service-request', 'call_outcome' => 'scheduled-appointment', 'agent_name' => 'A', 'status' => 'new', 'caller_name' => 'Ana Lopez', 'caller_phone' => '5125550101']);

        $this->actingAs($this->staff())->get(route('admin.home'))->assertSee('Search businesses, people, callers, call IDs');
        $this->get(route('admin.search', ['q' => 'rivera']))->assertOk()->assertSee('Rivera Plumbing')->assertSee('maria@rivera.test');
        Livewire::test(Search::class)->set('q', '555-0101')->assertSee('Ana Lopez')->assertSee('CL-20261005-0042');
        Livewire::test(Search::class)->set('q', 'cl-20261005-0042')->assertSee('CL-20261005-0042')->assertDontSee('maria@rivera.test');
        Livewire::test(Search::class)->set('q', 'zzzz')->assertSee('Nothing found');
        Livewire::test(Search::class)->set('q', 'a')->assertSee('Type at least two characters');
    }

    public function test_search_only_shows_groups_staff_may_see(): void
    {
        $this->business();
        $supervisor = $this->staff('agent_supervisor');   // agent-scoped role: no users.view
        $this->actingAs($supervisor)->get(route('admin.search', ['q' => 'maria']))->assertDontSee('maria@rivera.test');
    }
}
