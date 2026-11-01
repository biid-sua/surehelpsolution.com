<?php

namespace Tests\Feature;

use App\Actions\Organizations\ProvisionUserTenancy;
use App\Enums\TaskType;
use App\Jobs\RespondToLead;
use App\Livewire\Client\Leads;
use App\Models\BusinessHour;
use App\Models\BusinessProfile;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\LeadSettings;
use App\Models\Organization;
use App\Models\PhoneNumber;
use App\Models\SmsOptOut;
use App\Models\User;
use App\Notifications\CustomerEmail;
use App\Notifications\LeadReceived;
use App\Services\Billing\FeatureAccess;
use App\Services\Leads\LeadIntake;
use App\Services\Leads\LeadResponder;
use App\Services\Telephony\FakeProvider;
use App\Services\Telephony\TelephonyProvider;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Instant replies to new leads (D109): from the lead link or the website form, a text and an email
 * within seconds, the owner's phone rings to connect them, and the team gets a task and an alert.
 */
class LeadResponseTest extends TestCase
{
    use RefreshDatabase;

    private const TZ = 'America/Chicago';

    private User $owner;

    private Organization $business;

    private LeadSettings $settings;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Carbon::setTestNow(CarbonImmutable::parse('2026-10-14 10:00', self::TZ)); // Wednesday morning
        config(['telephony.sms' => true]);
        $this->owner = User::factory()->create(['role' => 'client', 'is_active' => true, 'must_change_password' => false]);
        $this->business = app(ProvisionUserTenancy::class)->handle($this->owner);
        $this->business->forceFill(['name' => 'Rivera Plumbing', 'timezone' => self::TZ, 'setup_completed_at' => now()])->save();
        BusinessProfile::create(['organization_id' => $this->business->id, 'display_name' => 'Rivera Plumbing', 'phone' => '5125550100', 'email' => 'office@rivera.test']);
        foreach ([1, 2, 3, 4, 5] as $day) {
            BusinessHour::create(['organization_id' => $this->business->id, 'day_of_week' => $day, 'opens_at' => '08:00', 'closes_at' => '17:00']);
        }
        PhoneNumber::create(['organization_id' => $this->business->id, 'provider' => 'fake', 'provider_sid' => 'PN1', 'number' => '+15125550100', 'transfer_number' => '+15125550199']);
        $this->settings = LeadSettings::create(['organization_id' => $this->business->id, 'is_enabled' => true, 'connect_call' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function fake(): FakeProvider
    {
        /** @var FakeProvider */
        return app(TelephonyProvider::class);
    }

    private function postLead(array $body, string $query = '?source=facebook'): TestResponse
    {
        return $this->postJson('/api/leads/'.$this->settings->token().$query, $body);
    }

    public function test_a_lead_from_the_lead_link_is_answered_in_seconds_and_the_owner_is_connected(): void
    {
        $this->postLead(['full_name' => 'Ana Lopez', 'phone_number' => '(512) 555-0147', 'email' => 'ana@lopez.test', 'comments' => 'Water heater is leaking', 'zip' => '78704'])
            ->assertCreated()->assertJsonPath('ok', true);

        $lead = Lead::query()->forOrganization($this->business)->sole();
        $this->assertSame(['facebook', '+15125550147', 'Ana Lopez'], [$lead->source, $lead->phone, $lead->name]);
        $this->assertSame(['zip' => '78704'], $lead->details);
        $this->assertSame('lead', Customer::query()->forOrganization($this->business)->sole()->source);
        $this->assertSame(TaskType::Callback, $lead->task->type);
        $this->assertStringContainsString('New lead from Ana Lopez (Facebook lead ad)', $lead->task->title);
        Notification::assertSentTo($this->owner, LeadReceived::class);

        // The text, the email and the call, straight away.
        $this->assertCount(1, $this->fake()->texts);
        $this->assertSame('+15125550147', $this->fake()->texts[0]['to']);
        $this->assertStringContainsString('Hi Ana, thanks for contacting Rivera Plumbing!', $this->fake()->texts[0]['body']);
        $this->assertStringContainsString('STOP', $this->fake()->texts[0]['body']);
        Notification::assertSentOnDemand(CustomerEmail::class, fn (CustomerEmail $n, array $channels, object $to) => $to->routes['mail'] === 'ana@lopez.test' && $n->replyTo === 'office@rivera.test');
        $this->assertCount(1, $this->fake()->calls);
        $this->assertSame(['+15125550100', '+15125550199'], [$this->fake()->calls[0]['from'], $this->fake()->calls[0]['to']]);
        $lead->refresh();
        $this->assertNotNull($lead->texted_at);
        $this->assertNotNull($lead->emailed_at);
        $this->assertSame(0, $lead->responseSeconds());
        $this->assertSame('calling_owner', $lead->call_status);

        // The owner picks up, hears who it is, presses 1, and the lead's phone rings.
        $whisper = $this->post(route('webhooks.voice.lead-whisper', $lead))->assertOk()->getContent();
        $this->assertStringContainsString('New lead for Rivera Plumbing: Ana Lopez, from Facebook lead ad.', $whisper);
        $this->assertStringContainsString('Press 1', $whisper);
        $connect = $this->post(route('webhooks.voice.lead-connect', $lead), ['Digits' => '1'])->assertOk()->getContent();
        $this->assertStringContainsString('<Number>+15125550147</Number>', $connect);
        $this->assertStringContainsString('callerId="+15125550100"', $connect);
        $this->post(route('webhooks.voice.lead-dialed', $lead), ['DialCallStatus' => 'completed'])->assertOk();
        $this->assertSame('connected', $lead->fresh()->call_status);

        // The lead source retries: the same lead, nothing sent twice.
        $this->postLead(['full_name' => 'Ana Lopez', 'phone_number' => '(512) 555-0147'])->assertCreated()->assertJsonPath('lead', $lead->ulid);
        $this->assertCount(1, $this->fake()->texts);
    }

    public function test_the_text_waits_for_the_morning_and_the_call_for_opening_hours(): void
    {
        Carbon::setTestNow(CarbonImmutable::parse('2026-10-14 22:30', self::TZ));
        Queue::fake([RespondToLead::class]);
        $this->postLead(['name' => 'Ben Ray', 'phone' => '5125550148', 'email' => 'ben@ray.test'])->assertCreated();
        $lead = Lead::query()->forOrganization($this->business)->sole();
        // The reply that runs right after the request (held by the fake queue here).
        Queue::assertPushed(RespondToLead::class, fn (RespondToLead $job) => ! $job->morning);
        (new RespondToLead($lead->id))->handle(app(LeadResponder::class));
        $lead->refresh();

        $this->assertCount(0, $this->fake()->texts, 'no texts at night');
        $this->assertCount(0, $this->fake()->calls, 'no call outside opening hours');
        $this->assertNotNull($lead->emailed_at, 'the email goes at once');
        $this->assertStringContainsString('the text waits until 8 AM', (string) $lead->note);
        Queue::assertPushed(RespondToLead::class, fn (RespondToLead $job) => $job->morning && $job->leadId === $lead->id);
    }

    public function test_it_respects_opt_outs_settings_demo_businesses_and_the_link(): void
    {
        // Someone who texted STOP never gets a text.
        SmsOptOut::create(['organization_id' => $this->business->id, 'phone' => '+15125550166', 'opted_out_at' => now()]);
        $this->postLead(['name' => 'Cal', 'phone' => '+15125550166'])->assertCreated();
        $this->assertCount(0, $this->fake()->texts);

        // Instant replies off: still a lead, a task and an alert, but nothing sent.
        $this->settings->update(['is_enabled' => false]);
        $this->postLead(['name' => 'Dee', 'phone' => '+15125550167'])->assertCreated();
        $this->assertCount(0, $this->fake()->texts);
        $this->assertSame(2, Lead::query()->forOrganization($this->business)->whereNotNull('task_id')->count());

        // A demo business never texts, emails or calls anyone.
        $this->settings->update(['is_enabled' => true]);
        $this->business->forceFill(['is_demo' => true])->save();
        $this->postLead(['name' => 'Eve', 'phone' => '+15125550168'])->assertCreated();
        $this->assertCount(0, $this->fake()->texts);
        $this->business->forceFill(['is_demo' => false])->save();

        // A wrong link, no contact details, or the feature switched off.
        $this->postJson('/api/leads/'.str_repeat('x', 40), ['name' => 'X', 'phone' => '5125550100'])->assertNotFound();
        $this->postLead(['name' => 'No contact'])->assertUnprocessable();
        app(FeatureAccess::class)->saveModes(['lead_response' => FeatureAccess::SELECTED]);
        app()->forgetScopedInstances();
        $this->postLead(['name' => 'Fay', 'phone' => '+15125550169'])->assertNotFound();
    }

    public function test_website_form_leads_get_the_same_answer(): void
    {
        $lead = app(LeadIntake::class)->receive($this->business, 'website', ['name' => 'Gus', 'phone' => '512-555-0170', 'message' => 'Need a quote', 'page' => 'https://rivera.test/contact']);
        $this->assertSame('Website enquiry from Gus', $lead->task->title);
        $this->assertStringContainsString('Sent from: https://rivera.test/contact', (string) $lead->task->description);
        $this->assertSame('website', Customer::query()->forOrganization($this->business)->sole()->source);
    }

    public function test_owners_see_their_leads_set_it_up_and_replace_the_link(): void
    {
        $this->postLead(['name' => 'Ana Lopez', 'phone' => '+15125550147']);
        $otherOwner = User::factory()->create(['role' => 'client', 'is_active' => true, 'must_change_password' => false]);
        $other = app(ProvisionUserTenancy::class)->handle($otherOwner);
        app(LeadIntake::class)->receive($other, 'yelp', ['name' => 'Not Yours', 'phone' => '+15125550190']);

        $this->enableTwoFactor($this->owner);
        $this->actingAs($this->owner)->get(route('app.leads'))->assertOk()
            ->assertSee('Ana Lopez')->assertDontSee('Not Yours')->assertSee('Texted in 0 s')->assertSee('Your lead link');

        $old = $this->settings->token();
        Livewire::test(Leads::class)->set('form.text_message', 'Hi {first_name}! {business} got your request.')->set('form.connect_call', false)
            ->call('save')->assertHasNoErrors()->assertDispatched('toast', type: 'success')
            ->call('newLink')->assertDispatched('toast', type: 'success');
        $this->assertDatabaseHas('audit_logs', ['action' => 'leads.settings_updated']);
        $this->assertNotSame($old, $this->settings->fresh()->webhook_token);
        $this->postJson('/api/leads/'.$old, ['name' => 'Late', 'phone' => '+15125550171'])->assertNotFound();

        // Staff see leads but not the link, and can't change the settings.
        $staff = User::factory()->create(['role' => 'client', 'is_active' => true, 'must_change_password' => false]);
        $this->business->members()->attach($staff->id, ['role' => 'staff', 'status' => 'active']);
        $this->enableTwoFactor($staff);
        $this->actingAs($staff)->get(route('app.leads'))->assertOk()->assertSee('Ana Lopez')->assertDontSee('Your lead link');
        Livewire::test(Leads::class)->call('save')->assertForbidden();
        Livewire::test(Leads::class)->call('newLink')->assertForbidden();
    }
}
