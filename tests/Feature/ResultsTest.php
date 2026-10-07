<?php

namespace Tests\Feature;

use App\Actions\Organizations\ProvisionUserTenancy;
use App\Livewire\Client\Results;
use App\Models\BusinessHour;
use App\Models\CallLog;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\MonthlyResults;
use App\Services\Metrics\ResultsReport;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Results page and monthly report (spec RPT-01/02).
 */
class ResultsTest extends TestCase
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
    private function business(): array
    {
        $owner = User::factory()->create(['role' => 'client', 'is_active' => true, 'must_change_password' => false]);
        $org = app(ProvisionUserTenancy::class)->handle($owner);
        $org->forceFill(['name' => 'Rivera Plumbing', 'timezone' => self::TZ, 'setup_completed_at' => now(), 'average_job_value_cents' => 25000, 'created_at' => '2026-08-01'])->save();
        foreach ([1, 2, 3, 4, 5] as $day) {
            BusinessHour::create(['organization_id' => $org->id, 'day_of_week' => $day, 'opens_at' => '09:00', 'closes_at' => '17:00']);
        }

        return [$owner, $org->fresh()];
    }

    private function logCall(Organization $org, string $at, string $outcome, string $reason = 'service-request'): void
    {
        $this->agent ??= User::factory()->create(['role' => 'agent', 'is_active' => true]);
        $call = CallLog::create([
            'user_id' => $this->agent->id,
            'call_id' => CallLog::generateCallId(), 'organization_id' => $org->id, 'call_date' => substr($at, 0, 10), 'call_time' => '09:00',
            'reason_for_call' => $reason, 'call_outcome' => $outcome, 'agent_name' => 'Agent', 'status' => 'new',
        ]);
        $call->forceFill(['created_at' => CarbonImmutable::parse($at, self::TZ)->utc()])->save();
    }

    private function october(Organization $org): void
    {
        $this->logCall($org, '2026-10-05 10:00', 'scheduled-appointment');                     // Monday, open
        $this->logCall($org, '2026-10-06 11:00', 'scheduled-appointment');
        $this->logCall($org, '2026-10-07 20:30', 'scheduled-appointment', 'emergency-service'); // after hours
        $this->logCall($org, '2026-10-10 12:00', 'provided-information', 'general-inquiry');  // Saturday: closed
        $this->logCall($org, '2026-10-12 10:00', 'call-dropped');                              // missed: not "answered"
        $this->logCall($org, '2026-10-13 10:00', 'wrong-number', 'wrong-number');              // spam
        $this->logCall($org, '2026-09-15 10:00', 'scheduled-appointment');                     // previous month
        Customer::create(['organization_id' => $org->id, 'first_name' => 'Ana', 'phone' => '+15125550101'])->forceFill(['created_at' => CarbonImmutable::parse('2026-10-05 10:00', self::TZ)->utc()])->save();
    }

    public function test_results_count_answered_calls_bookings_after_hours_and_estimated_revenue(): void
    {
        [, $org] = $this->business();
        $this->october($org);

        $report = app(ResultsReport::class);
        $period = $report->month($org, '2026-10');
        $r = $report->compute($org, $period['start'], $period['end']);

        $this->assertSame('October 2026', $period['label']);
        $this->assertSame(6, $r['calls']);
        $this->assertSame(4, $r['answered'], 'missed and spam calls are not "answered"');
        $this->assertSame(3, $r['booked']);
        $this->assertSame(75000, $r['revenue_cents']);
        $this->assertSame(2, $r['after_hours'], 'the 8:30 PM booking and the Saturday call');
        $this->assertSame(1, $r['leads']);
        $this->assertSame(200, $r['change']['booked'], '3 bookings vs 1 in September');
        $this->assertSame('Service request', array_key_first($r['reasons']));
        $this->assertSame(1, $r['heatmap'][3][20], 'Wednesday 8 PM, business time');
        $this->assertSame(1, $r['outcomes']['spam']['count']);
    }

    public function test_results_page_pdf_and_job_value(): void
    {
        [$owner, $org] = $this->business();
        $this->october($org);
        $this->actingAs($owner);

        $this->get(route('app.results', ['month' => '2026-10']))->assertOk()
            ->assertSee('Results')->assertSee('$750')->assertSee('3 jobs booked × $250 average job value')->assertSee('After-hours calls caught');
        Livewire::test(Results::class, ['month' => '2026-10'])->set('jobValue', 'abc')->call('saveJobValue')->assertHasErrors('jobValue')
            ->set('jobValue', '400')->call('saveJobValue')->assertHasNoErrors()->assertSee('$1,200');

        $pdf = $this->get(route('app.results.pdf', ['month' => '2026-10']))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
        $this->assertStringContainsString('rivera-plumbing-results-2026-10.pdf', $pdf->headers->get('Content-Disposition'));

        // Without a job value there's no revenue figure, only a prompt.
        $org->forceFill(['average_job_value_cents' => null])->save();
        $this->get(route('app.results', ['month' => '2026-10']))->assertSee('Tell us your average job value');
    }

    public function test_staff_see_results_but_only_owners_set_the_job_value(): void
    {
        [, $org] = $this->business();
        $staff = User::factory()->create(['role' => 'client', 'is_active' => true]);
        $org->members()->attach($staff->id, ['role' => 'staff', 'status' => 'active']);
        $this->actingAs($staff)->get(route('app.results'))->assertOk()->assertDontSee('Average job value ($)');
        Livewire::test(Results::class)->set('jobValue', '1')->call('saveJobValue')->assertForbidden();
    }

    public function test_monthly_report_email_goes_out_once_with_the_pdf(): void
    {
        Notification::fake();
        [$owner, $org] = $this->business();
        $this->october($org);
        [, $notSetUp] = $this->business();
        $notSetUp->forceFill(['setup_completed_at' => null])->save();

        $this->artisan('reports:monthly')->assertSuccessful()->expectsOutputToContain('sent to 1 business');
        $this->artisan('reports:monthly')->expectsOutputToContain('sent to 0 businesses');
        $this->assertSame('2026-10', $org->fresh()->last_report_month);

        Notification::assertSentToTimes($owner, MonthlyResults::class, 1);
        Notification::assertSentTo($owner, MonthlyResults::class, function (MonthlyResults $n) use ($owner) {
            $mail = $n->toMail($owner);

            return $n->headline() === 'In October 2026 we answered 4 calls and booked $750 in jobs for you.'
                && str_starts_with($mail->rawAttachments[0]['data'], '%PDF')
                && $mail->rawAttachments[0]['name'] === 'rivera-plumbing-results-2026-10.pdf';
        });
    }

    public function test_results_download_as_csv(): void
    {
        [$owner, $org] = $this->business();
        $this->october($org);

        $response = $this->actingAs($owner)->get(route('app.results.csv', ['month' => '2026-10']))->assertOk();
        $csv = $response->streamedContent();
        $this->assertStringContainsString('rivera-plumbing-results-2026-10.csv', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('"Results for Rivera Plumbing","October 2026"', $csv);
        $this->assertStringContainsString('Summary,"Calls that booked a job",3,200', $csv);
        $this->assertStringContainsString('Summary,"Estimated revenue",$750', $csv);
        $this->assertStringContainsString('"Top reasons for calling","Service request",', $csv);
    }
}
