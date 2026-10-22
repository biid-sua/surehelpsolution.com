<?php

namespace Tests\Feature;

use App\Actions\Organizations\ProvisionUserTenancy;
use App\Enums\BusinessRuleType;
use App\Enums\KnowledgeType;
use App\Livewire\Client\Setup\Wizard;
use App\Models\BusinessHour;
use App\Models\BusinessProfile;
use App\Models\BusinessRule;
use App\Models\BusinessService;
use App\Models\KnowledgeItem;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\SetupCompleted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The self-serve setup wizard (spec ONB-01..08).
 */
class SetupWizardTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: Organization} */
    private function owner(): array
    {
        $owner = User::factory()->create(['role' => 'client', 'is_active' => true, 'must_change_password' => false, 'name' => 'Maria Rivera']);
        $organization = app(ProvisionUserTenancy::class)->handle($owner);

        return [$owner, $organization->fresh()];
    }

    public function test_a_new_owner_is_guided_through_setup_with_industry_suggestions(): void
    {
        Notification::fake();
        [$owner, $org] = $this->owner();
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $this->actingAs($owner);

        $this->get(route('app.dashboard'))->assertOk()->assertSee('Finish setting up SureHelp')->assertSee('0 of 6 steps');
        $this->get(route('app.setup'))->assertOk()->assertSee('Your business')->assertSee('0 of 6 steps');

        // 1. Business
        $wizard = Livewire::test(Wizard::class)->assertSet('step', 'business')
            ->set('business.name', 'Rivera Plumbing')->set('business.industry', 'plumbing')->set('business.timezone', 'America/Chicago')
            ->set('business.website', 'not a url')->call('saveBusiness')->assertHasErrors('business.website')
            ->set('business.website', 'https://riveraplumbing.test')->set('business.job_value', '275')->call('saveBusiness')->assertHasNoErrors()
            ->assertSet('step', 'services');
        $org->refresh();
        $this->assertSame('Rivera Plumbing', $org->name);
        $this->assertSame('America/Chicago', $org->timezone);
        $this->assertSame(27500, $org->average_job_value_cents);
        $this->assertSame('Plumbing', BusinessProfile::query()->forOrganization($org)->sole()->industry);

        // 2. Services from the plumbing template: untick one, change a price.
        $services = $wizard->get('services');
        $this->assertSame('Drain cleaning', $services[0]['name']);
        $wizard->set('services.1.selected', false)->set('services.0.price', '159')->call('saveServices')->assertHasNoErrors()->assertSet('step', 'hours');
        $this->assertSame(count($services) - 1, BusinessService::query()->forOrganization($org)->count());
        $this->assertSame(15900, BusinessService::query()->forOrganization($org)->where('name', 'Drain cleaning')->sole()->price_cents);

        // 3. Hours: emergencies prefilled from the industry; ZIP codes become a service-area rule.
        $this->assertTrue($wizard->get('emergencyAvailable'));
        $this->assertStringContainsString('Burst pipe', $wizard->get('emergencyInstructions'));
        $wizard->call('applyHoursPreset', 'six')->set('zips', '78701, 7870x')->call('saveHours')->assertHasErrors('zips')
            ->set('zips', '78701, 78702')->call('saveHours')->assertHasNoErrors()->assertSet('step', 'calls');
        $this->assertSame(6, BusinessHour::query()->forOrganization($org)->count());
        $this->assertSame(['78701', '78702'], BusinessRule::query()->forOrganization($org)->where('type', 'service_area')->sole()->config['postal_codes']);

        // 4. Calls: greeting suggested, FAQ answers required, do's become instructions.
        $this->assertStringContainsString('Thank you for calling Rivera Plumbing', $wizard->get('greeting'));
        $this->assertSame(['address', 'phone'], $wizard->get('details'));
        $wizard->call('saveCalls')->assertHasErrors('faqs.0.a');
        $wizard->set('faqs.0.a', 'Estimates are free.')->call('removeFaq', 2)->call('removeFaq', 1)
            ->set('dos', "Never quote installations.\nMention the senior discount.")->set('escalation', 'Text Maria for complaints.')
            ->call('saveCalls')->assertHasNoErrors()->assertSet('step', 'calendar');
        $this->assertSame(1, KnowledgeItem::query()->forOrganization($org)->where('type', KnowledgeType::Faq->value)->count());
        $this->assertSame(2, BusinessRule::query()->forOrganization($org)->where('type', BusinessRuleType::Instruction->value)->count());
        $this->assertSame(2, BusinessRule::query()->forOrganization($org)->where('type', BusinessRuleType::RequireDetail->value)->count());

        // Saving the step again replaces what it made instead of duplicating it.
        Livewire::test(Wizard::class, ['step' => 'calls'])->set('dos', 'Only one rule now.')->call('saveCalls');
        $this->assertSame(1, BusinessRule::query()->forOrganization($org)->where('type', BusinessRuleType::Instruction->value)->count());
        $this->assertSame(1, KnowledgeItem::query()->forOrganization($org)->where('type', KnowledgeType::Faq->value)->count());

        // 5–6. Calendar and team can be skipped.
        $wizard = Livewire::test(Wizard::class, ['step' => 'calendar'])->call('continueCalendar')->assertSet('step', 'team')
            ->call('continueTeam')->assertSet('step', 'review')->assertSee('What happens next');
        $this->assertSame('skipped', $org->fresh()->setup_progress['calendar']);

        // 7. Finish: SureHelp staff hear about it, the owner lands on the dashboard.
        $wizard->call('finish')->assertRedirect(route('app.dashboard'));
        $this->assertTrue($org->fresh()->isSetUp());
        Notification::assertSentTo($admin, SetupCompleted::class);
        $this->get(route('app.dashboard'))->assertDontSee('Finish setting up SureHelp');
        $this->assertSame(route('app.dashboard'), $owner->fresh()->homeUrl());
    }

    public function test_required_steps_must_be_done_and_progress_is_kept(): void
    {
        [$owner, $org] = $this->owner();
        $this->actingAs($owner);
        $this->assertSame(route('app.setup'), $owner->homeUrl());

        Livewire::test(Wizard::class, ['step' => 'business'])->call('skip')->assertStatus(422);
        Livewire::test(Wizard::class, ['step' => 'calendar'])->call('skip');
        Livewire::test(Wizard::class, ['step' => 'review'])->call('finish')->assertHasErrors('finish')->assertSee('Your business, Hours &amp; area', false);
        $this->assertFalse($org->fresh()->isSetUp());

        // Coming back starts at the first unfinished step.
        Livewire::test(Wizard::class)->assertSet('step', 'business');
    }

    public function test_only_owners_run_setup_and_admins_see_progress(): void
    {
        [$owner, $org] = $this->owner();
        $staff = User::factory()->create(['role' => 'client', 'is_active' => true]);
        $org->members()->attach($staff->id, ['role' => 'staff', 'status' => 'active']);
        $this->actingAs($staff)->get(route('app.setup'))->assertForbidden();
        $this->actingAs($staff)->get(route('app.dashboard'))->assertOk()->assertDontSee('Finish setting up');
        $this->assertSame(route('app.dashboard'), $staff->homeUrl());

        $org->forceFill(['setup_progress' => ['business' => 'done', 'hours' => 'done']])->save();
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $this->enableTwoFactor($admin);
        $this->actingAs($admin)->get(route('admin.organizations.index', ['status' => 'setup']))->assertOk()->assertSee('2/6 steps');
        $this->get(route('admin.organizations.show', $org))->assertOk()->assertSee('2 of 6 steps done');
    }

    public function test_existing_businesses_are_not_sent_into_setup(): void
    {
        [, $org] = $this->owner();
        BusinessHour::create(['organization_id' => $org->id, 'day_of_week' => 1, 'opens_at' => '09:00', 'closes_at' => '17:00']);
        $migration = require database_path('migrations/2026_10_13_000001_add_setup_progress_and_job_value.php');
        $migration->down();
        $migration->up();
        $this->assertTrue($org->fresh()->isSetUp());
    }

    public function test_business_names_with_symbols_are_shown_once_escaped(): void
    {
        [$owner, $org] = $this->owner();
        $org->forceFill(['name' => 'Brooks Heating & Air <Co>', 'setup_completed_at' => now()])->save();

        $this->actingAs($owner)->get(route('app.dashboard'))->assertOk()
            ->assertSee('Brooks Heating &amp; Air &lt;Co&gt;', false)
            ->assertDontSee('&amp;amp;', false)
            ->assertDontSee('<Co>', false);
    }
}
