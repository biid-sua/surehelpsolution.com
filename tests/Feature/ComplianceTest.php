<?php

namespace Tests\Feature;

use App\Actions\Organizations\ProvisionUserTenancy;
use App\Listeners\KeepPatientDetailsInApp;
use App\Livewire\Client\Compliance;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\LegalAcceptance;
use App\Models\Organization;
use App\Models\PhoneNumber;
use App\Models\SmsOptOut;
use App\Models\User;
use App\Models\VoiceCall;
use App\Notifications\HipaaActivityNotice;
use App\Notifications\LeadReceived;
use App\Services\Ai\AgentAssist;
use App\Services\Billing\FeatureAccess;
use App\Services\Telephony\VoiceCalls;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Compliance (D117): HIPAA mode keeps patient details away from AI and out of email, text and push;
 * consent records are there to download.
 */
class ComplianceTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Organization $practice;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->travelTo(CarbonImmutable::parse('2026-10-20 15:00', 'UTC'));
        $this->owner = User::factory()->create(['role' => 'client', 'is_active' => true, 'must_change_password' => false]);
        $this->practice = app(ProvisionUserTenancy::class)->handle($this->owner);
        $this->practice->forceFill(['name' => 'Bright Smile Dental', 'status' => 'active', 'setup_completed_at' => now()])->save();
        $this->enableTwoFactor($this->owner);
        $this->actingAs($this->owner);
    }

    private function turnOn(): void
    {
        Livewire::test(Compliance::class)->call('enableHipaa')->assertHasErrors('agree')
            ->set('agree', true)->call('enableHipaa')->assertHasNoErrors()->assertDispatched('toast', type: 'success');
        $this->practice->refresh();
    }

    public function test_turning_hipaa_mode_on_signs_the_baa_and_switches_ai_off(): void
    {
        $features = app(FeatureAccess::class);
        $features->saveModes(['ai_receptionist' => FeatureAccess::EVERYONE]);
        app()->forgetScopedInstances();
        $this->assertTrue(app(FeatureAccess::class)->allows($this->practice, 'ai_receptionist'));

        $this->turnOn();
        $this->assertTrue($this->practice->hipaa_mode);
        $this->assertNotNull($this->practice->baa_accepted_at);
        $this->assertSame('baa', LegalAcceptance::query()->where('user_id', $this->owner->id)->value('document'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'compliance.hipaa_enabled']);

        app()->forgetScopedInstances();
        $this->assertFalse(app(FeatureAccess::class)->allows($this->practice, 'ai_receptionist'));
        $this->assertFalse(app(FeatureAccess::class)->allows($this->practice, 'ai_assistant'));
        $this->assertTrue(app(FeatureAccess::class)->allows($this->practice, 'estimates'), 'other features stay');
        $this->assertSame('AI help is off for this business (HIPAA mode).', app(AgentAssist::class)->blocker($this->practice));

        // No transcription is started, and a transcript that arrives isn't summarized by AI.
        config(['telephony.transcribe' => true]);
        PhoneNumber::create(['organization_id' => $this->practice->id, 'provider' => 'fake', 'provider_sid' => 'PN1', 'number' => '+15125550100', 'record_calls' => true]);
        $twiml = $this->post(route('webhooks.voice.incoming'), ['CallSid' => 'CA1', 'From' => '+15125550147', 'To' => '+15125550100'])->assertOk()->getContent();
        $this->assertStringNotContainsString('Transcription', $twiml);
        $call = VoiceCall::query()->forOrganization($this->practice)->sole();
        $call->forceFill(['transcript' => 'Caller: my tooth hurts'])->save();
        Queue::fake();
        app(VoiceCalls::class)->transcriptFinished($call);
        $this->assertNull($call->fresh()->summary_status);

        // Off again: AI comes back.
        Livewire::test(Compliance::class)->call('disableHipaa');
        app()->forgetScopedInstances();
        $this->assertTrue(app(FeatureAccess::class)->allows($this->practice->fresh(), 'ai_receptionist'));
    }

    public function test_alerts_with_patient_details_stay_in_the_app(): void
    {
        $this->turnOn();
        $lead = Lead::create(['organization_id' => $this->practice->id, 'source' => 'website', 'name' => 'Ana Lopez', 'phone' => '+15125550147',
            'message' => 'Broken tooth, in pain', 'received_at' => now()]);
        $listener = new KeepPatientDetailsInApp;

        $this->assertFalse($listener->handle(new NotificationSending($this->owner, new LeadReceived($lead), 'mail')));
        $this->assertFalse($listener->handle(new NotificationSending($this->owner, new LeadReceived($lead), 'push')));
        $this->assertNull($listener->handle(new NotificationSending($this->owner, new LeadReceived($lead), 'database')), 'the bell inside the app still gets it');
        Notification::assertSentToTimes($this->owner, HipaaActivityNotice::class, 2);
        $notice = new HipaaActivityNotice('Bright Smile Dental', ['mail']);
        $this->assertStringNotContainsString('Ana', implode(' ', $notice->toMail($this->owner)->introLines));

        // At most one plain note per 10 minutes per channel.
        $listener->handle(new NotificationSending($this->owner, new LeadReceived($lead), 'mail'));
        Notification::assertSentToTimes($this->owner, HipaaActivityNotice::class, 2);

        // The plain note itself goes out, and without HIPAA mode alerts go as usual.
        $this->assertNull($listener->handle(new NotificationSending($this->owner, new HipaaActivityNotice('x', ['mail']), 'mail')));
        $this->practice->forceFill(['hipaa_mode' => false])->save();
        $this->assertNull($listener->handle(new NotificationSending($this->owner, new LeadReceived($lead), 'mail')));
    }

    public function test_opening_a_patient_record_is_logged_and_consent_records_download(): void
    {
        $ana = Customer::create(['organization_id' => $this->practice->id, 'first_name' => 'Ana', 'phone' => '5125550147', 'email' => 'ana@example.test',
            'sms_consent' => true, 'sms_consent_at' => now(), 'consent_source' => 'web_form']);
        Customer::create(['organization_id' => $this->practice->id, 'first_name' => 'Ben', 'phone' => '5125550148', 'marketing_opt_out_at' => now()]);
        SmsOptOut::create(['organization_id' => $this->practice->id, 'phone' => '+15125550148', 'opted_out_at' => now()]);

        $this->get(route('app.customers.show', $ana))->assertOk();
        $this->assertDatabaseMissing('audit_logs', ['action' => 'customers.viewed']);
        $this->turnOn();
        $this->get(route('app.customers.show', $ana))->assertOk();
        $this->assertDatabaseHas('audit_logs', ['action' => 'customers.viewed', 'subject_label' => 'Ana']);

        $this->get(route('app.compliance'))->assertOk()->assertSee('HIPAA mode')->assertSee('Texted STOP')->assertSee('Download consent records');
        $csv = $this->get(route('app.compliance.consent-export'))->assertOk()->streamedContent();
        $this->assertStringContainsString('Ana,+15125550147,ana@example.test,yes,"2026-10-20 15:00",web_form,,no,', $csv);
        $this->assertStringContainsString('Ben,+15125550148,,no,,,"2026-10-20 15:00",no,"2026-10-20 15:00"', $csv);
        $this->assertDatabaseHas('audit_logs', ['action' => 'compliance.consent_exported']);
    }

    public function test_only_owners_turn_it_on_and_businesses_are_separate(): void
    {
        $staff = User::factory()->create(['role' => 'client', 'is_active' => true, 'must_change_password' => false]);
        $this->practice->members()->attach($staff->id, ['role' => 'staff', 'status' => 'active']);
        $this->enableTwoFactor($staff);
        $this->actingAs($staff);
        $this->get(route('app.compliance'))->assertForbidden();
        Livewire::test(Compliance::class)->assertForbidden();
        $this->assertFalse($this->practice->fresh()->hipaa_mode);

        $this->actingAs($this->owner);
        $this->turnOn();
        $otherOwner = User::factory()->create(['role' => 'client', 'is_active' => true, 'must_change_password' => false]);
        $other = app(ProvisionUserTenancy::class)->handle($otherOwner);
        $this->assertFalse($other->fresh()->hipaa_mode);
        $this->enableTwoFactor($otherOwner);
        $this->actingAs($otherOwner)->get(route('app.compliance'))->assertOk()->assertDontSee('BAA signed');
    }
}
