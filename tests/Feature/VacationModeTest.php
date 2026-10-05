<?php

namespace Tests\Feature;

use App\Actions\Organizations\ProvisionUserTenancy;
use App\Livewire\Client\Business\Hours;
use App\Livewire\Client\Dashboard;
use App\Models\BusinessHour;
use App\Models\BusinessProfile;
use App\Models\Organization;
use App\Models\User;
use App\Services\Business\BusinessHours;
use App\Services\Scheduling\Availability;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Vacation mode (spec CLI-06): planned time away closes the business on those days only.
 */
class VacationModeTest extends TestCase
{
    use RefreshDatabase;

    private const TZ = 'America/Chicago';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(CarbonImmutable::parse('2026-10-14 10:00', self::TZ));   // Wednesday
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
        $org = app(ProvisionUserTenancy::class)->handle($owner);
        $org->forceFill(['timezone' => self::TZ, 'setup_completed_at' => now()])->save();
        foreach ([1, 2, 3, 4, 5] as $day) {
            BusinessHour::create(['organization_id' => $org->id, 'day_of_week' => $day, 'opens_at' => '09:00', 'closes_at' => '17:00']);
        }

        return [$owner, $org->fresh()];
    }

    public function test_planned_vacation_closes_only_those_days_and_everyone_is_told(): void
    {
        [$owner, $org] = $this->business();
        $this->actingAs($owner);

        Livewire::test(Hours::class)->set('closedFrom', '2026-10-19')->set('closedUntil', '2026-10-16')->call('save')->assertHasErrors('closedUntil');
        Livewire::test(Hours::class)->set('closedFrom', '2026-10-19')->set('closedUntil', '2026-10-23')
            ->set('closureMessage', 'On vacation. Emergencies: Mike, (512) 555-0199.')->call('save')->assertHasNoErrors();

        $hours = app(BusinessHours::class);
        $hours->forget($org);
        $this->assertNotEmpty($hours->intervalsOn($org, CarbonImmutable::parse('2026-10-16', self::TZ)), 'open before the trip');
        $this->assertSame([], $hours->intervalsOn($org, CarbonImmutable::parse('2026-10-20', self::TZ)));
        $this->assertNotEmpty($hours->intervalsOn($org, CarbonImmutable::parse('2026-10-26', self::TZ)), 'open again after');
        $this->assertTrue($hours->status($org)['open'], 'still open today');
        $this->assertSame([], app(Availability::class)->slots($org, CarbonImmutable::parse('2026-10-21', self::TZ), 60));

        $this->get(route('app.dashboard'))->assertSee('Vacation planned: Oct 19 – Oct 23')->assertSee('Cancel it');

        // During the trip: the dashboard and agents show it, and the owner can end it early.
        Carbon::setTestNow(CarbonImmutable::parse('2026-10-20 10:00', self::TZ));
        $hours->forget($org);
        $this->assertSame('temporarily_closed', $hours->status($org)['reason']);
        $this->get(route('app.dashboard'))->assertSee('Vacation mode is on until Friday, Oct 23')->assertSee('end it now');
        Livewire::test(Dashboard::class)->call('endVacation');
        $this->assertNull(BusinessProfile::query()->forOrganization($org)->sole()->closed_until);
        $hours->forget($org);
        $this->assertNotEmpty($hours->intervalsOn($org, CarbonImmutable::parse('2026-10-21', self::TZ)));
    }

    public function test_agents_see_current_and_upcoming_time_away(): void
    {
        [, $org] = $this->business();
        BusinessProfile::create(['organization_id' => $org->id, 'closed_from' => '2026-10-19', 'closed_until' => '2026-10-23', 'closure_message' => 'Back on the 26th.']);
        $agent = User::factory()->create(['role' => 'agent', 'is_active' => true]);
        $org->assignAgent($agent);
        $this->enableTwoFactor($agent);

        $this->actingAs($agent)->get(route('agent.businesses.show', $org))->assertOk()->assertSee('Away Oct 19 – Oct 23')->assertSee('Back on the 26th.');
        Carbon::setTestNow(CarbonImmutable::parse('2026-10-21 10:00', self::TZ));
        $this->withSession(['auth.last_activity' => now()->timestamp])   // a week later: a fresh session, not an idle one
            ->get(route('agent.businesses.show', $org))->assertSee('On vacation until Friday, Oct 23');
    }

    public function test_closures_saved_before_vacation_mode_still_start_straight_away(): void
    {
        [$owner, $org] = $this->business();
        BusinessProfile::create(['organization_id' => $org->id, 'closed_until' => '2026-10-16']);
        $this->assertSame([], app(BusinessHours::class)->intervalsOn($org, CarbonImmutable::parse('2026-10-15', self::TZ)));
        $this->actingAs($owner);
        Livewire::test(Hours::class)->assertSet('closedFrom', '2026-10-14')->call('save')->assertHasNoErrors();
    }
}
