<?php

namespace Tests\Feature;

use App\Actions\Billing\ManageSubscription;
use App\Actions\Organizations\ProvisionUserTenancy;
use App\Livewire\Admin\Billing\Features;
use App\Livewire\Client\Results;
use App\Models\Appointment;
use App\Models\BusinessHour;
use App\Models\BusinessService;
use App\Models\CallLog;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\User;
use App\Notifications\MonthlyResults;
use App\Services\Billing\FeatureAccess;
use App\Services\Metrics\ResultsReport;
use App\Services\Metrics\ValueReport;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The dollars report (D107): what SureHelp was worth to a business, what it paid us and the
 * return; and features kept for the businesses staff pick, hidden from the rest.
 */
class ValueReportTest extends TestCase
{
    use RefreshDatabase;

    private const TZ = 'America/Chicago';

    private ?User $agent = null;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(CarbonImmutable::parse('2026-11-01 09:00', self::TZ));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array{0: User, 1: Organization} */
    private function business(string $name = 'Rivera Plumbing'): array
    {
        $owner = User::factory()->create(['role' => 'client', 'is_active' => true, 'must_change_password' => false]);
        $org = app(ProvisionUserTenancy::class)->handle($owner);
        $org->forceFill(['name' => $name, 'timezone' => self::TZ, 'setup_completed_at' => now(), 'average_job_value_cents' => 25000, 'created_at' => '2026-08-01'])->save();
        foreach ([1, 2, 3, 4, 5] as $day) {
            BusinessHour::create(['organization_id' => $org->id, 'day_of_week' => $day, 'opens_at' => '09:00', 'closes_at' => '17:00']);
        }

        return [$owner, $org->fresh()];
    }

    private function staff(): User
    {
        $user = User::factory()->create(['role' => 'admin', 'is_active' => true, 'must_change_password' => false]);
        $this->enableTwoFactor($user);

        return $user;
    }

    private function booked(Organization $org, string $at, ?BusinessService $service = null, string $outcome = 'scheduled-appointment'): CallLog
    {
        $this->agent ??= User::factory()->create(['role' => 'agent', 'is_active' => true]);
        $call = CallLog::create([
            'user_id' => $this->agent->id, 'call_id' => CallLog::generateCallId(), 'organization_id' => $org->id, 'call_date' => substr($at, 0, 10), 'call_time' => '09:00',
            'reason_for_call' => 'service-request', 'call_outcome' => $outcome, 'agent_name' => 'Agent', 'status' => 'new',
        ]);
        $call->forceFill(['created_at' => CarbonImmutable::parse($at, self::TZ)->utc()])->save();
        if ($service) {
            $start = CarbonImmutable::parse($at, self::TZ)->addDay();
            Appointment::create(['organization_id' => $org->id, 'title' => 'Job', 'timezone' => self::TZ, 'status' => 'confirmed', 'call_log_id' => $call->id,
                'service_id' => $service->id, 'starts_at' => $start, 'ends_at' => $start->addHour(), 'blocked_until' => $start->addHour()]);
        }

        return $call;
    }

    /** October: a $900 water heater job, two jobs at the $250 average (one after hours), and one in September. */
    private function october(Organization $org): void
    {
        $heater = BusinessService::create(['organization_id' => $org->id, 'name' => 'Water heater install', 'duration_minutes' => 120, 'price_type' => 'fixed', 'price_cents' => 90000]);
        $quote = BusinessService::create(['organization_id' => $org->id, 'name' => 'Remodel', 'duration_minutes' => 60, 'price_type' => 'quote_required', 'price_cents' => 500000]);
        $this->booked($org, '2026-10-05 10:00', $heater);
        $this->booked($org, '2026-10-06 11:00', $quote);           // a quote isn't a price: the average applies
        $this->booked($org, '2026-10-07 20:30');                   // after hours
        $this->booked($org, '2026-10-08 10:00', outcome: 'provided-information');   // not a booking
        $this->booked($org, '2026-09-15 10:00');
    }

    private function october2026(Organization $org): array
    {
        $period = app(ResultsReport::class)->month($org, '2026-10');

        return app(ValueReport::class)->compute($org, $period['start'], $period['end']);
    }

    public function test_jobs_are_valued_at_their_service_price_or_the_average_and_compared_with_what_was_paid(): void
    {
        [, $org] = $this->business();
        $this->october($org);
        Invoice::create(['number' => 'INV-10', 'organization_id' => $org->id, 'status' => 'paid', 'currency' => 'USD', 'subtotal_cents' => 34900, 'total_cents' => 34900,
            'amount_paid_cents' => 34900, 'period_start' => CarbonImmutable::parse('2026-10-01', self::TZ), 'period_end' => CarbonImmutable::parse('2026-10-31', self::TZ),
            'issued_at' => now(), 'due_at' => now()]);
        Invoice::create(['number' => 'INV-11', 'organization_id' => $org->id, 'status' => 'void', 'currency' => 'USD', 'subtotal_cents' => 99900, 'total_cents' => 99900,
            'amount_paid_cents' => 0, 'period_start' => CarbonImmutable::parse('2026-10-01', self::TZ), 'issued_at' => now(), 'due_at' => now()]);

        $v = $this->october2026($org);
        $this->assertSame(3, $v['jobs']);
        $this->assertSame(1, $v['priced_jobs']);
        $this->assertSame(2, $v['estimated_jobs']);
        $this->assertSame(90000 + 25000 + 25000, $v['value_cents']);
        $this->assertSame(1, $v['after_hours_jobs']);
        $this->assertSame(25000, $v['after_hours_value_cents']);
        $this->assertSame(34900, $v['cost_cents'], 'void invoices are left out');
        $this->assertSame('invoices', $v['cost_source']);
        $this->assertSame(4.0, $v['multiple']);
        $this->assertSame(140000 + 25000, $v['since_value_cents'], 'September counts towards "since you joined"');
        $this->assertSame('2026-09', $v['since']->format('Y-m'));
    }

    public function test_a_yearly_plan_counts_per_month_and_without_any_price_there_is_no_value(): void
    {
        [, $org] = $this->business();
        $this->october($org);
        $admin = $this->staff();
        $plan = Plan::create(['slug' => 'pro', 'name' => 'Pro', 'price_cents' => 1200000, 'interval' => 'year']);
        app(ManageSubscription::class)->subscribe($org, $plan, $admin, CarbonImmutable::parse('2026-10-01'), false);

        $v = $this->october2026($org);
        $this->assertSame(100000, $v['cost_cents'], 'a yearly plan counts per month');
        $this->assertSame(1.4, $v['multiple']);

        // With no invoice for the month, the plan's monthly price stands in.
        Invoice::query()->where('organization_id', $org->id)->delete();
        $v = $this->october2026($org);
        $this->assertSame([100000, 'plan'], [$v['cost_cents'], $v['cost_source']]);

        [, $bare] = $this->business('No Prices Co');
        $bare->forceFill(['average_job_value_cents' => null])->save();
        $this->booked($bare, '2026-10-05 10:00');
        $v = $this->october2026($bare->fresh());
        $this->assertSame(1, $v['jobs']);
        $this->assertNull($v['value_cents'], 'nothing to value it with: no made-up $0');
        $this->assertNull($v['multiple']);
    }

    public function test_the_business_sees_it_on_results_the_dashboard_and_the_monthly_email(): void
    {
        [$owner, $org] = $this->business();
        $this->october($org);
        $this->booked($org, '2026-11-01 08:00');   // this month so far
        $this->actingAs($owner);

        Livewire::test(Results::class)->set('month', '2026-10')
            ->assertSee('What SureHelp was worth to you in October 2026')->assertSee('$1,400')->assertSee('Booked while you were closed');
        $this->get(route('app.dashboard'))->assertOk()->assertSee('This month so far, SureHelp booked')->assertSee('$250');

        // The app gets it too, as an added field.
        $this->getJson('/api/v1/client/results?month=2026-10')->assertOk()->assertJsonPath('data.value.value_cents', 140000)->assertJsonPath('data.value.jobs', 3);

        Notification::fake();
        $this->artisan('reports:monthly', ['--month' => '2026-10'])->assertSuccessful();
        Notification::assertSentTo($owner, MonthlyResults::class, fn (MonthlyResults $n) => str_contains($n->headline(), 'booked $1,400 in jobs for you'));
    }

    public function test_a_feature_kept_for_picked_businesses_is_hidden_from_the_rest(): void
    {
        [$picked, $chosen] = $this->business('Chosen Co');
        [$other, $otherOrg] = $this->business('Other Co');
        $this->october($chosen);
        $this->october($otherOrg);
        $admin = $this->staff();

        // Staff switch the dollars report to "only businesses I pick" and pick one.
        $this->actingAs($admin);
        Livewire::test(Features::class)->set('modes.value_report', 'selected')->call('save')
            ->call('pickFor', 'value_report')->assertSee('Chosen Co')->assertSee('Other Co')
            ->call('togglePick', $chosen->id)->assertDispatched('toast', type: 'success')
            ->assertSee('1 picked: Chosen Co');
        $this->assertDatabaseHas('audit_logs', ['action' => 'features.business_picked']);
        app()->forgetScopedInstances();

        $features = app(FeatureAccess::class);
        $this->assertTrue($features->allows($chosen, 'value_report'));
        $this->assertFalse($features->allows($otherOrg, 'value_report'));
        $this->assertTrue($features->hidden($otherOrg, 'value_report'), 'hidden, not offered as an upgrade');

        $this->actingAs($picked);
        Livewire::test(Results::class)->set('month', '2026-10')->assertSee('What SureHelp was worth to you');
        app()->forgetScopedInstances();
        $this->actingAs($other);
        Livewire::test(Results::class)->set('month', '2026-10')->assertDontSee('What SureHelp was worth to you')->assertSee('Estimated revenue from jobs we booked');

        // Unpicking takes it away again.
        app()->forgetScopedInstances();
        $this->actingAs($admin);
        Livewire::test(Features::class)->call('pickFor', 'value_report')->call('togglePick', $chosen->id);
        app()->forgetScopedInstances();
        $this->assertFalse(app(FeatureAccess::class)->allows($chosen->fresh(), 'value_report'));

        // A page behind a feature for picked businesses answers 404 to the rest, and leaves the menu.
        app()->forgetScopedInstances();
        $this->actingAs($admin);
        Livewire::test(Features::class)->set('modes.website_tools', 'selected')->call('save');
        app()->forgetScopedInstances();
        $this->actingAs($other)->get(route('app.website'))->assertNotFound();
        $this->actingAs($other)->get(route('app.dashboard'))->assertOk()->assertDontSee(route('app.website').'"', false);
    }

    public function test_only_billing_managers_pick_businesses_and_staff_see_the_value_on_the_business_page(): void
    {
        [, $org] = $this->business();
        $this->october($org);
        $viewer = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $viewer->syncRoles(['support_agent']);
        $this->actingAs($viewer);
        Livewire::test(Features::class)->call('pickFor', 'value_report')->call('togglePick', $org->id)->assertForbidden();

        $admin = $this->staff();
        $this->actingAs($admin)->get(route('admin.organizations.show', $org))->assertOk()
            ->assertSee('Value to this business')->assertSee('$1,400');
    }
}
