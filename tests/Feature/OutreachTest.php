<?php

namespace Tests\Feature;

use App\Actions\Organizations\ProvisionUserTenancy;
use App\Enums\AppointmentStatus;
use App\Jobs\SendCampaign;
use App\Livewire\Client\Outreach;
use App\Models\Appointment;
use App\Models\BusinessProfile;
use App\Models\BusinessService;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\PhoneNumber;
use App\Models\ServiceReminder;
use App\Models\SmsOptOut;
use App\Models\User;
use App\Notifications\CustomerEmail;
use App\Services\Marketing\Outreach as OutreachService;
use App\Services\Telephony\TelephonyProvider;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Service reminders and win-back campaigns (D116), within the marketing rules: consent for texts,
 * unsubscribe for email, no messages at night, and nothing from demo businesses.
 */
class OutreachTest extends TestCase
{
    use RefreshDatabase;

    private const TZ = 'America/Chicago';

    private User $owner;

    private Organization $business;

    private BusinessService $tuneUp;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->travelTo(CarbonImmutable::parse('2026-10-20 11:00', self::TZ));   // a Tuesday morning
        config(['telephony.sms' => true]);
        $this->owner = User::factory()->create(['role' => 'client', 'is_active' => true, 'must_change_password' => false]);
        $this->business = app(ProvisionUserTenancy::class)->handle($this->owner);
        $this->business->forceFill(['name' => 'Rivera HVAC', 'timezone' => self::TZ, 'status' => 'active', 'setup_completed_at' => now()])->save();
        BusinessProfile::create(['organization_id' => $this->business->id, 'display_name' => 'Rivera HVAC', 'phone' => '5125550100', 'email' => 'office@rivera.test']);
        PhoneNumber::create(['organization_id' => $this->business->id, 'provider' => 'fake', 'provider_sid' => 'PN1', 'number' => '+15125550100']);
        $this->tuneUp = BusinessService::create(['organization_id' => $this->business->id, 'name' => 'AC tune-up', 'duration_minutes' => 60, 'price_type' => 'fixed', 'price_cents' => 12900]);
        $this->enableTwoFactor($this->owner);
        $this->actingAs($this->owner);
    }

    private function customer(string $first, string $phone, array $extra = []): Customer
    {
        return Customer::create(array_merge(['organization_id' => $this->business->id, 'first_name' => $first, 'phone' => $phone, 'sms_consent' => true, 'status' => 'customer'], $extra));
    }

    private function job(Customer $customer, string $when, string $status = 'completed', ?BusinessService $service = null): Appointment
    {
        $at = CarbonImmutable::parse($when, self::TZ);

        return Appointment::create(['organization_id' => $this->business->id, 'customer_id' => $customer->id, 'service_id' => ($service ?? $this->tuneUp)->id,
            'title' => 'Tune-up', 'timezone' => self::TZ, 'starts_at' => $at, 'ends_at' => $at->addHour(), 'blocked_until' => $at->addHour(),
            'status' => $status, 'source' => 'import']);
    }

    private function texts(): Collection
    {
        return collect(app(TelephonyProvider::class)->texts);
    }

    public function test_service_reminders_go_out_once_when_due_and_only_by_the_rules(): void
    {
        Livewire::test(Outreach::class)->set('reminders.'.$this->tuneUp->id.'.months', '12')->call('saveReminders')->assertHasNoErrors();
        $this->assertSame(12, $this->tuneUp->fresh()->reminder_months);
        $this->assertDatabaseHas('audit_logs', ['action' => 'marketing.reminders_updated']);

        $ana = $this->customer('Ana', '5125550147', ['email' => 'ana@lopez.test']);
        $this->job($ana, '2025-10-18 10:00');
        $ben = $this->customer('Ben', '5125550148');
        $this->job($ben, '2025-10-17 10:00');
        $this->job($ben, '2026-09-01 10:00', 'confirmed');   // booked again since: no reminder
        $cal = $this->customer('Cal', '5125550149', ['sms_consent' => false, 'email' => 'cal@diaz.test', 'marketing_opt_out_at' => now()]);
        $this->job($cal, '2025-10-19 10:00');
        $dee = $this->customer('Dee', '5125550150');
        $this->job($dee, '2025-10-16 10:00');
        SmsOptOut::create(['organization_id' => $this->business->id, 'phone' => '+15125550150', 'opted_out_at' => now()]);
        $this->job($this->customer('Eve', '5125550151'), '2026-04-01 10:00');   // not due yet

        app(TelephonyProvider::class)->texts = [];   // booking confirmations from the setup above

        // Night: nothing.
        $this->travelTo(CarbonImmutable::parse('2026-10-20 21:30', self::TZ));
        $this->artisan('marketing:reminders')->assertSuccessful();
        $this->assertCount(0, $this->texts());

        $this->travelTo(CarbonImmutable::parse('2026-10-20 10:00', self::TZ));
        $this->artisan('marketing:reminders')->assertSuccessful();
        $this->assertSame(['+15125550147'], $this->texts()->pluck('to')->all());
        $this->assertStringContainsString('Hi Ana, it\'s been a while since your AC tune-up with Rivera HVAC.', $this->texts()[0]['body']);
        $this->assertStringContainsString('(512) 555-0100', $this->texts()[0]['body']);
        $this->assertStringContainsString('Reply STOP to opt out.', $this->texts()[0]['body']);
        Notification::assertSentOnDemand(CustomerEmail::class, fn (CustomerEmail $m, $c, $to) => $to->routes['mail'] === 'ana@lopez.test' && str_contains($m->body, '/unsubscribe/'));
        Notification::assertSentOnDemandTimes(CustomerEmail::class, 1);   // Cal unsubscribed

        // Next hour: no repeats.
        $this->travel(1)->hour();
        $this->artisan('marketing:reminders')->assertSuccessful();
        $this->assertCount(1, $this->texts());
        $this->assertSame(4, ServiceReminder::query()->forOrganization($this->business)->count());
    }

    public function test_a_win_back_campaign_reaches_the_right_customers_and_counts_who_came_back(): void
    {
        $gone = $this->customer('Ana', '5125550147', ['email' => 'ana@lopez.test']);
        $this->job($gone, '2025-06-01 10:00');
        $recent = $this->customer('Ben', '5125550148');
        $this->job($recent, '2026-09-01 10:00');
        $emailOnly = $this->customer('Cal', '5125550149', ['sms_consent' => false, 'email' => 'cal@diaz.test']);
        $this->job($emailOnly, '2025-03-01 10:00');
        $out = $this->customer('Dee', '5125550150', ['marketing_opt_out_at' => now()]);
        $this->job($out, '2025-03-01 10:00');
        $never = $this->customer('Eve', '5125550151');   // never had work done

        $page = Livewire::test(Outreach::class)->set('campaign.inactive_months', '12')->assertViewHas('groupSize', 2)->assertViewHas('textable', 1)
            ->set('campaign.name', 'Fall furnace check')->call('launch')->assertHasNoErrors()->assertDispatched('toast', type: 'success');
        $campaign = Campaign::query()->forOrganization($this->business)->sole();
        $this->assertSame(['sent', 2, 2], [$campaign->status, $campaign->recipients_count, $campaign->sent_count]);
        $this->assertSame(['+15125550147'], $this->texts()->pluck('to')->all());
        $this->assertStringContainsString('Hi Ana, it\'s Rivera HVAC.', $this->texts()[0]['body']);
        Notification::assertSentOnDemandTimes(CustomerEmail::class, 2);
        $this->assertDatabaseHas('audit_logs', ['action' => 'marketing.campaign_started']);

        // Ana books a week later: counted as brought back.
        $this->travel(7)->days();
        Appointment::create(['organization_id' => $this->business->id, 'customer_id' => $gone->id, 'title' => 'Furnace check', 'timezone' => self::TZ,
            'starts_at' => now()->addDays(3), 'ends_at' => now()->addDays(3)->addHour(), 'blocked_until' => now()->addDays(3)->addHour(), 'status' => AppointmentStatus::Confirmed, 'source' => 'agent']);
        $this->assertSame(1, app(OutreachService::class)->bookedAfter($campaign->fresh()));
        $this->get(route('app.outreach'))->assertOk()->assertSee('Fall furnace check')->assertSee('1 booked');
    }

    public function test_campaigns_wait_for_the_morning_and_unsubscribing_works(): void
    {
        $ana = $this->customer('Ana', '5125550147', ['email' => 'ana@lopez.test']);
        $this->job($ana, '2025-06-01 10:00');
        $this->travelTo(CarbonImmutable::parse('2026-10-20 22:00', self::TZ));
        Livewire::test(Outreach::class)->set('campaign.name', 'Late one')->set('campaign.inactive_months', '')->call('launch')->assertHasNoErrors();
        $campaign = Campaign::query()->forOrganization($this->business)->sole();
        $this->assertSame([Campaign::SENDING, 0], [$campaign->status, $campaign->sent_count]);
        $this->assertCount(0, $this->texts());

        $this->travelTo(CarbonImmutable::parse('2026-10-21 09:30', self::TZ));
        (new SendCampaign($campaign->id))->handle(app(OutreachService::class));
        $this->assertSame([Campaign::SENT, 1], [$campaign->fresh()->status, $campaign->fresh()->sent_count]);

        // The email's link unsubscribes her from reminders and campaigns, but nothing else.
        $this->app['auth']->forgetGuards();
        $this->get(URL::signedRoute('marketing.unsubscribe', ['customer' => $ana->ulid]))->assertOk()->assertSee('You\'re unsubscribed');
        $this->assertNotNull($ana->fresh()->marketing_opt_out_at);
        $this->get(route('marketing.unsubscribe', ['customer' => $ana->ulid]))->assertForbidden();   // unsigned
    }

    public function test_staff_other_businesses_demo_and_validation(): void
    {
        Livewire::test(Outreach::class)->set('campaign.name', '')->set('campaign.channels', [])->call('launch')->assertHasErrors(['campaign.name', 'campaign.channels']);
        Livewire::test(Outreach::class)->set('campaign.name', 'Nobody')->call('launch')->assertHasErrors('campaign.name');   // no one to reach

        $other = app(ProvisionUserTenancy::class)->handle(User::factory()->create(['role' => 'client', 'is_active' => true]));
        $theirService = BusinessService::create(['organization_id' => $other->id, 'name' => 'Theirs', 'duration_minutes' => 30, 'price_type' => 'quote_required']);
        Livewire::test(Outreach::class)->set('campaign.name', 'X')->set('campaign.service_id', (string) $theirService->id)->call('launch')->assertHasErrors('campaign.service_id');
        $this->get(route('app.outreach'))->assertDontSee('Theirs');

        $this->job($this->customer('Ana', '5125550147'), '2025-06-01 10:00');
        $this->business->forceFill(['is_demo' => true])->save();
        Livewire::test(Outreach::class)->set('campaign.name', 'Demo')->call('launch')->assertHasErrors('campaign.name');
        $this->business->forceFill(['is_demo' => false])->save();

        $staff = User::factory()->create(['role' => 'client', 'is_active' => true, 'must_change_password' => false]);
        $this->business->members()->attach($staff->id, ['role' => 'staff', 'status' => 'active']);
        $this->enableTwoFactor($staff);
        $this->actingAs($staff);
        $this->get(route('app.outreach'))->assertOk()->assertDontSee('New campaign');
        Livewire::test(Outreach::class)->call('saveReminders')->assertForbidden();
        Livewire::test(Outreach::class)->set('campaign.name', 'X')->call('launch')->assertForbidden();
    }
}
