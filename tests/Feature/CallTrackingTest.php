<?php

namespace Tests\Feature;

use App\Actions\Organizations\ProvisionUserTenancy;
use App\Enums\ServicePriceType;
use App\Livewire\Client\Business\PhoneLine;
use App\Livewire\Client\CallTracking;
use App\Models\Appointment;
use App\Models\BusinessService;
use App\Models\CallLog;
use App\Models\Organization;
use App\Models\PhoneNumber;
use App\Models\User;
use App\Models\VoiceCall;
use App\Services\Billing\FeatureAccess;
use App\Services\Metrics\CallTrackingReport;
use App\Services\Telephony\Texting;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Call tracking (D114): a number per marketing channel, answered like the main line, with calls,
 * booked jobs and cost per job for each one.
 */
class CallTrackingTest extends TestCase
{
    use RefreshDatabase;

    private const TZ = 'America/Chicago';

    private User $owner;

    private Organization $business;

    private PhoneNumber $main;

    private ?User $agent = null;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->travelTo(CarbonImmutable::parse('2026-10-20 15:00', 'UTC'));
        $this->owner = User::factory()->create(['role' => 'client', 'is_active' => true, 'must_change_password' => false]);
        $this->business = app(ProvisionUserTenancy::class)->handle($this->owner);
        $this->business->forceFill(['name' => 'Rivera Plumbing', 'timezone' => self::TZ, 'status' => 'active', 'setup_completed_at' => now(), 'average_job_value_cents' => 30000])->save();
        $this->main = PhoneNumber::create(['organization_id' => $this->business->id, 'provider' => 'fake', 'provider_sid' => 'PN1', 'number' => '+15125550100',
            'transfer_number' => '+15125550199', 'after_hours' => 'voicemail', 'text_back' => true]);
        $this->enableTwoFactor($this->owner);
        $this->actingAs($this->owner);
    }

    private function voiceCall(PhoneNumber $line, string $status, ?string $outcome = null, ?BusinessService $service = null): VoiceCall
    {
        $log = null;
        if ($outcome) {
            $this->agent ??= User::factory()->create(['role' => 'agent', 'is_active' => true]);
            $log = CallLog::create(['user_id' => $this->agent->id, 'call_id' => CallLog::generateCallId(), 'organization_id' => $this->business->id,
                'call_date' => '2026-10-20', 'call_time' => '09:00', 'reason_for_call' => 'service-request', 'call_outcome' => $outcome, 'agent_name' => 'Agent', 'status' => 'new']);
            if ($service) {
                $at = CarbonImmutable::parse('2026-10-22 15:00', 'UTC');
                Appointment::create(['organization_id' => $this->business->id, 'call_log_id' => $log->id, 'service_id' => $service->id, 'title' => 'Job', 'timezone' => self::TZ,
                    'starts_at' => $at, 'ends_at' => $at->addHour(), 'blocked_until' => $at->addHour(), 'status' => 'confirmed', 'source' => 'agent']);
            }
        }

        return VoiceCall::create(['organization_id' => $this->business->id, 'phone_number_id' => $line->id, 'provider' => 'fake', 'provider_call_sid' => 'CA'.uniqid(),
            'direction' => 'inbound', 'from_number' => '+15125550147', 'to_number' => $line->number, 'status' => $status, 'call_log_id' => $log?->id, 'started_at' => now()]);
    }

    public function test_an_owner_adds_a_number_for_a_channel_and_it_is_answered_like_the_main_line(): void
    {
        $this->get(route('app.call-tracking'))->assertOk()->assertSee('Main number')->assertSee('Add a tracking number');

        $page = Livewire::test(CallTracking::class)->set('source', 'Google Ads')->set('areaCode', '512')->call('search')->assertHasNoErrors();
        $number = $page->get('found')[0]['number'];
        $page->call('buy', $number)->assertHasNoErrors()->assertDispatched('toast', type: 'success');

        $line = PhoneNumber::query()->forOrganization($this->business)->where('number', $number)->sole();
        $this->assertSame(['Google Ads', '+15125550199', 'voicemail', true], [$line->tracking_source, $line->transfer_number, $line->after_hours, $line->text_back]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'call_tracking.number_added']);

        // A call to it rings the business like any other, and is counted for Google Ads.
        $this->post(route('webhooks.voice.incoming'), ['CallSid' => 'CA123', 'From' => '+15125550147', 'To' => $number])->assertOk();
        $this->assertSame($line->id, VoiceCall::query()->forOrganization($this->business)->where('provider_call_sid', 'CA123')->value('phone_number_id'));

        // Changing the main line's settings changes the tracking numbers too; texts still go from the main line.
        Livewire::test(PhoneLine::class)->set('transferNumber', '(512) 555-0188')->call('save')->assertHasNoErrors();
        $this->assertSame('+15125550188', $line->fresh()->transfer_number);
        $this->assertSame($this->main->id, app(Texting::class)->lineFor($this->business)->id);

        // Removing it releases the number; its calls stay in the month's report.
        Livewire::test(CallTracking::class)->call('remove', $line->ulid)->assertDispatched('toast', type: 'success');
        $this->assertSame(PhoneNumber::STATUS_RELEASED, $line->fresh()->status);
        $this->get(route('app.call-tracking'))->assertSee('Google Ads')->assertSee('(removed)');
    }

    public function test_the_report_counts_calls_jobs_value_and_cost_per_job_by_channel(): void
    {
        $google = PhoneNumber::create(['organization_id' => $this->business->id, 'provider' => 'fake', 'provider_sid' => 'PN2', 'number' => '+15125550101',
            'tracking_source' => 'Google Ads', 'monthly_spend_cents' => 60000]);
        $signs = PhoneNumber::create(['organization_id' => $this->business->id, 'provider' => 'fake', 'provider_sid' => 'PN3', 'number' => '+15125550102', 'tracking_source' => 'Truck signs']);
        $service = BusinessService::create(['organization_id' => $this->business->id, 'name' => 'Water heater install', 'duration_minutes' => 120,
            'price_type' => ServicePriceType::Fixed, 'price_cents' => 140000]);

        $this->voiceCall($google, VoiceCall::COMPLETED, 'scheduled-appointment', $service);   // $1,400
        $this->voiceCall($google, VoiceCall::COMPLETED, 'scheduled-appointment');             // average $300
        $this->voiceCall($google, VoiceCall::COMPLETED, 'general-inquiry');
        $this->voiceCall($google, VoiceCall::MISSED);
        $this->voiceCall($signs, VoiceCall::COMPLETED, 'scheduled-appointment');
        $this->voiceCall($this->main, VoiceCall::VOICEMAIL);
        // Last month doesn't count.
        $old = $this->voiceCall($google, VoiceCall::COMPLETED, 'scheduled-appointment');
        $old->forceFill(['created_at' => now()->subMonth()])->save();

        $now = CarbonImmutable::now(self::TZ)->startOfMonth();
        $rows = collect(app(CallTrackingReport::class)->compute($this->business, $now, $now->endOfMonth()))->keyBy('source');
        $this->assertSame(['Main number', 'Google Ads', 'Truck signs'], $rows->keys()->all());
        $this->assertSame([4, 3, 1, 2, 170000, 60000, 15000, 30000],
            array_values($rows['Google Ads'] ? array_intersect_key($rows['Google Ads'], array_flip(['calls', 'answered', 'missed', 'booked', 'value_cents', 'spend_cents', 'cost_per_call_cents', 'cost_per_job_cents'])) : []));
        $this->assertSame([1, null], [$rows['Truck signs']['booked'], $rows['Truck signs']['cost_per_job_cents']]);
        $this->assertSame([1, 0, 1], [$rows['Main number']['calls'], $rows['Main number']['answered'], $rows['Main number']['missed']]);

        // Owners enter what a channel costs; the page shows the cost per job.
        Livewire::test(CallTracking::class)->set('spend.'.$signs->ulid, '250')->call('saveSpend', $signs->ulid)->assertHasNoErrors()
            ->set('spend.'.$signs->ulid, 'lots')->call('saveSpend', $signs->ulid)->assertHasErrors('spend.'.$signs->ulid);
        $this->assertSame(25000, $signs->fresh()->monthly_spend_cents);
        $this->get(route('app.call-tracking'))->assertSee('$1,700')->assertSee('$300')->assertSee('$250');
    }

    public function test_limits_demo_businesses_staff_other_businesses_and_the_feature(): void
    {
        $other = app(ProvisionUserTenancy::class)->handle(User::factory()->create(['role' => 'client', 'is_active' => true]));
        $theirs = PhoneNumber::create(['organization_id' => $other->id, 'provider' => 'fake', 'provider_sid' => 'PN9', 'number' => '+15125550109', 'tracking_source' => 'Their Billboard']);
        Livewire::test(CallTracking::class)->call('remove', $theirs->ulid)->assertNotFound();
        Livewire::test(CallTracking::class)->call('saveSpend', $theirs->ulid)->assertNotFound();
        $this->get(route('app.call-tracking'))->assertDontSee('Their Billboard');

        // A number nobody offered can't be bought; demo businesses can't buy real numbers.
        Livewire::test(CallTracking::class)->set('source', 'Yelp')->call('buy', '+15125550000')->assertStatus(422);
        $this->business->forceFill(['is_demo' => true])->save();
        Livewire::test(CallTracking::class)->set('source', 'Yelp')->set('areaCode', '512')->call('search')
            ->call('buy', '+15125550107')->assertHasErrors('source');
        $this->business->forceFill(['is_demo' => false])->save();

        // Staff see the report but can't add or remove numbers.
        $staff = User::factory()->create(['role' => 'client', 'is_active' => true, 'must_change_password' => false]);
        $this->business->members()->attach($staff->id, ['role' => 'staff', 'status' => 'active']);
        $this->enableTwoFactor($staff);
        $this->actingAs($staff);
        $response = $this->get(route('app.call-tracking'));
        if ($response->status() === 200) {
            $response->assertDontSee('Add a tracking number');
        } else {
            $response->assertForbidden();
        }
        Livewire::test(CallTracking::class)->set('source', 'Yelp')->set('areaCode', '512')->call('search')->assertForbidden();
    }

    public function test_it_is_off_for_plans_without_it(): void
    {
        app(FeatureAccess::class)->saveModes(['call_tracking' => FeatureAccess::PLAN]);
        app()->forgetScopedInstances();
        $this->get(route('app.call-tracking'))->assertRedirect(route('app.billing'));
    }
}
