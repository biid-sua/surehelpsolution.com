<?php

namespace Tests\Feature;

use App\Actions\Organizations\ProvisionUserTenancy;
use App\Livewire\Admin\Agencies;
use App\Livewire\Client\Agency as AgencyDashboard;
use App\Models\Agency;
use App\Models\AgencyStatement;
use App\Models\CallLog;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\User;
use App\Services\Business\Branding;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Agency resellers (D118): staff set an agency up; its people look after its businesses from one
 * dashboard, the businesses wear the agency's brand, and the agency sees its share.
 */
class AgencyTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $agencyOwner;

    private Organization $plumber;

    private Organization $dentist;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->travelTo(CarbonImmutable::parse('2026-10-20 15:00', 'UTC'));
        $this->admin = User::factory()->create(['role' => 'admin', 'is_active' => true, 'must_change_password' => false]);
        $this->admin->assignRole('super_admin');
        $this->enableTwoFactor($this->admin);
        $this->agencyOwner = User::factory()->create(['role' => 'client', 'is_active' => true, 'must_change_password' => false, 'email' => 'maya@brightagency.test']);
        app(ProvisionUserTenancy::class)->handle($this->agencyOwner);   // the agency's own SureHelp account
        $this->enableTwoFactor($this->agencyOwner);
        $this->plumber = $this->business('Rivera Plumbing');
        $this->dentist = $this->business('Bright Smile Dental');
    }

    private function business(string $name): Organization
    {
        $owner = User::factory()->create(['role' => 'client', 'is_active' => true, 'must_change_password' => false]);
        $organization = app(ProvisionUserTenancy::class)->handle($owner);
        $organization->forceFill(['name' => $name, 'status' => 'active', 'setup_completed_at' => now()])->save();

        return $organization;
    }

    private function setUpAgency(): Agency
    {
        $this->actingAs($this->admin);
        $page = Livewire::test(Agencies::class)->call('edit')
            ->set('form.name', 'Bright Agency')->set('form.brand_color', '#0d9488')->set('form.commission_percent', '20')->call('save')->assertHasNoErrors()
            ->set('memberEmail', 'maya@brightagency.test')->set('memberRole', 'owner')->call('addMember')->assertHasNoErrors()
            ->set('business', 'Rivera Plumbing')->call('addBusiness')->assertHasNoErrors()
            ->set('business', $this->dentist->ulid)->call('addBusiness')->assertHasNoErrors();
        $this->assertDatabaseHas('audit_logs', ['action' => 'agency.created']);

        return Agency::query()->sole();
    }

    public function test_staff_set_up_an_agency_whose_people_look_after_its_businesses(): void
    {
        $agency = $this->setUpAgency();
        $this->assertSame([$agency->id, $agency->id], [$this->plumber->fresh()->agency_id, $this->dentist->fresh()->agency_id]);
        // Maya is a manager of both, through the agency.
        foreach ([$this->plumber, $this->dentist] as $organization) {
            $this->assertSame('manager', $organization->members()->whereKey($this->agencyOwner->id)->first()->pivot->role);
        }

        // The businesses wear the agency's brand.
        $brand = app(Branding::class)->for($this->plumber->fresh());
        $this->assertSame([true, '#0d9488'], [$brand['white_label'], $brand['color']]);

        // The agency dashboard: calls and what they paid, and the agency's share.
        CallLog::create(['user_id' => User::factory()->create(['role' => 'agent'])->id, 'call_id' => CallLog::generateCallId(), 'organization_id' => $this->plumber->id,
            'call_date' => '2026-10-20', 'call_time' => '09:00', 'reason_for_call' => 'service-request', 'call_outcome' => 'general-inquiry', 'agent_name' => 'A', 'status' => 'new']);
        Invoice::create(['number' => 'SH-1', 'organization_id' => $this->plumber->id, 'status' => 'paid', 'currency' => 'USD', 'subtotal_cents' => 29900, 'total_cents' => 29900,
            'amount_paid_cents' => 29900, 'issued_at' => now(), 'due_at' => now(), 'paid_at' => now()]);
        $this->actingAs($this->agencyOwner);
        $this->get(route('app.agency'))->assertOk()->assertSee('Bright Agency')->assertSee('Rivera Plumbing')->assertSee('Bright Smile Dental')
            ->assertSee('$299')->assertSee('$59.80')->assertSee('Your share (20%)');
        $this->get(route('app.dashboard'))->assertSee('Your agency');

        // Opening a business switches to it.
        $this->post(route('app.switch', $this->dentist->ulid))->assertRedirect(route('app.dashboard'));

        // Removing a business takes the agency's people out of it, but not its own owner.
        $this->actingAs($this->admin);
        Livewire::test(Agencies::class)->call('edit', $agency->ulid)->call('removeBusiness', $this->dentist->ulid);
        $this->assertNull($this->dentist->fresh()->agency_id);
        $this->assertFalse($this->dentist->members()->whereKey($this->agencyOwner->id)->exists());
        $this->assertSame(1, $this->dentist->members()->count());
        $this->assertFalse(app(Branding::class)->for($this->dentist->fresh())['white_label']);
    }

    public function test_a_monthly_statement_of_the_share_is_made_and_marked_paid(): void
    {
        $agency = $this->setUpAgency();
        Invoice::create(['number' => 'SH-1', 'organization_id' => $this->plumber->id, 'status' => 'paid', 'currency' => 'USD', 'subtotal_cents' => 29900, 'total_cents' => 29900,
            'amount_paid_cents' => 29900, 'issued_at' => now(), 'due_at' => now(), 'paid_at' => now()]);
        Invoice::create(['number' => 'SH-2', 'organization_id' => $this->dentist->id, 'status' => 'paid', 'currency' => 'USD', 'subtotal_cents' => 49900, 'total_cents' => 49900,
            'amount_paid_cents' => 49900, 'issued_at' => now(), 'due_at' => now(), 'paid_at' => now()]);

        $this->travelTo(CarbonImmutable::parse('2026-11-01 12:00', 'UTC'));
        $this->artisan('agencies:statements')->assertSuccessful();
        $this->artisan('agencies:statements')->assertSuccessful();   // once per month
        $statement = AgencyStatement::query()->sole();
        $this->assertSame(['2026-10-01', 79800, 15960, 'due'], [$statement->month->toDateString(), $statement->clients_paid_cents, $statement->share_cents, $statement->status]);

        $this->actingAs($this->agencyOwner)->get(route('app.agency'))->assertOk()->assertSee('October 2026')->assertSee('$159.60')->assertSee('Due');

        $this->actingAs($this->admin);
        Livewire::test(Agencies::class)->call('edit', $agency->ulid)->set('references.'.$statement->id, 'ACH 4471')->call('markPaid', $statement->id)->assertDispatched('toast', type: 'success');
        $this->assertSame(['paid', 'ACH 4471'], [$statement->fresh()->status, $statement->fresh()->reference]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'agency.statement_paid']);
        Livewire::test(Agencies::class)->call('edit', $agency->ulid)->call('markPaid', $statement->id)->assertNotFound();   // already paid
        $this->actingAs($this->agencyOwner)->get(route('app.agency'))->assertSee('Paid Nov 1');
    }

    public function test_agency_owners_add_new_client_businesses(): void
    {
        $agency = $this->setUpAgency();
        $this->actingAs($this->agencyOwner);
        Livewire::test(AgencyDashboard::class)->set('name', '')->call('addBusiness')->assertHasErrors('name')
            ->set('name', 'Oak Street HVAC')->set('timezone', 'America/Chicago')->call('addBusiness')->assertHasNoErrors()->assertDispatched('toast', type: 'success');
        $new = Organization::query()->where('name', 'Oak Street HVAC')->sole();
        $this->assertSame([$agency->id, 'America/Chicago', 'onboarding'], [$new->agency_id, $new->timezone, $new->status->value]);
        $this->assertSame('owner', $new->members()->whereKey($this->agencyOwner->id)->first()->pivot->role);
        $this->assertDatabaseHas('audit_logs', ['action' => 'agency.business_created']);
    }

    public function test_people_outside_the_agency_and_agency_members_who_are_not_owners(): void
    {
        $agency = $this->setUpAgency();
        // A business owner who isn't in an agency sees no agency page or menu item.
        $owner = $this->plumber->members()->wherePivot('role', 'owner')->first();
        $this->enableTwoFactor($owner);
        $this->actingAs($owner)->get(route('app.agency'))->assertNotFound();
        $this->get(route('app.dashboard'))->assertDontSee('Your agency');

        // A team member sees the dashboard but can't add businesses.
        $member = User::factory()->create(['role' => 'client', 'is_active' => true, 'must_change_password' => false, 'email' => 'sam@brightagency.test']);
        $this->actingAs($this->admin);
        Livewire::test(Agencies::class)->call('edit', $agency->ulid)->set('memberEmail', 'sam@brightagency.test')->set('memberRole', 'member')->call('addMember')->assertHasNoErrors()
            ->set('memberEmail', 'nobody@nowhere.test')->call('addMember')->assertHasErrors('memberEmail')
            ->set('business', 'No Such Business')->call('addBusiness')->assertHasErrors('business');
        $this->enableTwoFactor($member);
        $this->actingAs($member)->get(route('app.agency'))->assertOk()->assertDontSee('Add a client business');
        Livewire::test(AgencyDashboard::class)->set('name', 'Sneaky Co')->call('addBusiness')->assertForbidden();

        // Removing a person takes them out of the agency's businesses.
        $this->actingAs($this->admin);
        Livewire::test(Agencies::class)->call('edit', $agency->ulid)->call('removeMember', $member->id);
        $this->assertFalse($this->plumber->members()->whereKey($member->id)->exists());

        // Business owners can't reach the admin page.
        $this->actingAs($this->agencyOwner)->get(route('admin.agencies'))->assertForbidden();
    }
}
