<?php

namespace Tests\Feature;

use App\Actions\Billing\ManageSubscription;
use App\Actions\Organizations\ProvisionUserTenancy;
use App\Livewire\Admin\Enquiries\Index as Enquiries;
use App\Mail\SalesDemoBookedMail;
use App\Mail\SalesDemoFollowUpMail;
use App\Models\ContactSubmission;
use App\Models\Plan;
use App\Models\User;
use App\Services\Sales\DemoFollowUp;
use App\Services\Sales\DemoSlots;
use App\Services\Telephony\TelephonyProvider;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Demo calls after the booking (D96): the Teams link, reminders, moving or cancelling from the
 * visitor's page, the outcome in Website enquiries, and the follow-up emails.
 */
class DemoFollowUpTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-09 14:00:00');   // Monday 9:00 AM in New York
        config(['sales.demos.timezone' => 'America/New_York', 'sales.notify' => 'sales@surehelp.test',
            'sales.demos.meeting_url' => 'https://teams.microsoft.com/l/meetup-join/19%3ameeting_demo',
            'telephony.notify_from' => '+15125550100']);
        Mail::fake();
        $this->admin = User::factory()->create(['role' => 'admin', 'is_active' => true, 'must_change_password' => false, 'name' => 'Riley Admin']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Booked on Monday for Wednesday 11:00 AM New York (16:00 UTC). */
    private function booked(bool $sms = true): ContactSubmission
    {
        $enquiry = ContactSubmission::create(['name' => 'Dana Ortiz', 'email' => 'dana@ortizdental.test', 'phone' => '(512) 555-0101',
            'company' => 'Ortiz Dental', 'inquiry_type' => 'demo', 'message' => 'Demo please', 'sms_consent' => $sms, 'source' => 'chat']);
        $this->assertTrue(app(DemoSlots::class)->book($enquiry, CarbonImmutable::parse('2026-11-11 11:00', 'America/New_York')));
        app(DemoFollowUp::class)->booked($enquiry);

        return $enquiry->fresh();
    }

    public function test_the_invitation_carries_the_teams_link_and_a_private_page_to_change_it(): void
    {
        $enquiry = $this->booked();
        $this->assertSame(40, strlen($enquiry->demo_token));

        Mail::assertQueued(SalesDemoBookedMail::class, function (SalesDemoBookedMail $mail) use ($enquiry) {
            $ics = $mail->ics();
            $body = $mail->render();

            return $mail->kind === 'booked' && str_contains($body, 'Join on Microsoft Teams: https://teams.microsoft.com/l/meetup-join/19%3ameeting_demo')
                && str_contains($body, route('demo.show', $enquiry->demo_token))
                && str_contains($ics, 'UID:demo-'.$enquiry->id.'@') && str_contains($ics, 'URL:https://teams.microsoft.com/')
                && str_contains($ics, 'LOCATION:Microsoft Teams') && str_contains($ics, 'METHOD:REQUEST');
        });

        $this->get(route('demo.show', $enquiry->demo_token))->assertOk()
            ->assertSee('Wednesday, November 11 at 11:00 AM')->assertSee('Join on Microsoft Teams')->assertSee('Cancel this demo');
        $this->get(route('demo.show', str_repeat('x', 40)))->assertNotFound();
        $this->get('/demo/short')->assertNotFound();
    }

    public function test_reminders_go_the_day_before_and_an_hour_before_with_a_text_when_they_agreed(): void
    {
        $enquiry = $this->booked();
        $this->artisan('sales:demo-follow-ups')->assertSuccessful();
        Mail::assertNotQueued(SalesDemoFollowUpMail::class);   // two days ahead

        $this->travelTo(CarbonImmutable::parse('2026-11-10 17:00', 'UTC'));   // 23 hours before
        $this->artisan('sales:demo-follow-ups');
        $this->artisan('sales:demo-follow-ups');
        Mail::assertQueuedCount(2);   // the invitation, then one day-before reminder
        Mail::assertQueued(SalesDemoFollowUpMail::class, fn ($m) => $m->kind === 'reminder_day' && $m->hasTo('dana@ortizdental.test'));

        $this->travelTo(CarbonImmutable::parse('2026-11-11 15:10', 'UTC'));   // 50 minutes before
        $this->artisan('sales:demo-follow-ups')->expectsOutputToContain('1 reminders (1 by text)');
        Mail::assertQueued(SalesDemoFollowUpMail::class, fn ($m) => $m->kind === 'reminder_hour');
        $texts = app(TelephonyProvider::class)->texts;
        $this->assertCount(1, $texts);
        $this->assertSame('+15125550101', $texts[0]['to']);
        $this->assertStringContainsString('starts at 11:00 AM EST', $texts[0]['body']);
        $this->assertStringContainsString('teams.microsoft.com', $texts[0]['body']);
        $this->assertNotNull($enquiry->fresh()->demo_reminded_hour_at);
    }

    public function test_no_text_without_consent(): void
    {
        $this->booked(sms: false);
        $this->travelTo(CarbonImmutable::parse('2026-11-11 15:10', 'UTC'));
        $this->artisan('sales:demo-follow-ups');
        Mail::assertQueued(SalesDemoFollowUpMail::class, fn ($m) => $m->kind === 'reminder_hour');
        $this->assertSame([], app(TelephonyProvider::class)->texts);
    }

    public function test_the_visitor_moves_or_cancels_from_their_page(): void
    {
        $enquiry = $this->booked();
        $this->travelTo(CarbonImmutable::parse('2026-11-10 17:00', 'UTC'));
        $this->artisan('sales:demo-follow-ups');   // day-before reminder sent
        $new = CarbonImmutable::parse('2026-11-12 14:00', 'America/New_York');

        $this->post(route('demo.reschedule', $enquiry->demo_token), ['starts_at' => 'nonsense'])->assertRedirect()->assertSessionHas('demo_error');
        $this->post(route('demo.reschedule', $enquiry->demo_token), ['starts_at' => $new->toIso8601String()])->assertRedirect(route('demo.show', $enquiry->demo_token));
        $enquiry->refresh();
        $this->assertTrue($enquiry->demo_at->equalTo($new));
        $this->assertSame('booked', $enquiry->demo_status);
        $this->assertNull($enquiry->demo_reminded_day_at, 'reminders start again for the new time');
        Mail::assertQueued(SalesDemoBookedMail::class, fn ($m) => $m->kind === 'moved' && str_contains($m->ics(), 'DTSTART:20261112T190000Z'));
        Mail::assertQueued(SalesDemoFollowUpMail::class, fn ($m) => $m->kind === 'team' && $m->hasTo('sales@surehelp.test') && str_contains($m->headline, 'Demo moved'));

        $this->post(route('demo.cancel', $enquiry->demo_token))->assertRedirect();
        $this->assertSame('cancelled', $enquiry->fresh()->demo_status);
        Mail::assertQueued(SalesDemoBookedMail::class, fn ($m) => $m->kind === 'cancelled' && str_contains($m->ics(), 'METHOD:CANCEL') && str_contains($m->ics(), 'STATUS:CANCELLED'));
        $this->post(route('demo.cancel', $enquiry->demo_token))->assertNotFound();
        $this->get(route('demo.show', $enquiry->demo_token))->assertOk()->assertSee('Pick a new time');
        // The freed time is open for others again.
        $this->assertStringContainsString('2:00 PM', json_encode(app(DemoSlots::class)->days('2026-11-12', 1, 40)));
    }

    public function test_the_team_marks_the_outcome_and_the_right_follow_up_goes_once(): void
    {
        $enquiry = $this->booked();
        $this->travelTo(CarbonImmutable::parse('2026-11-11 17:00', 'UTC'));   // after the call
        $this->actingAs($this->admin);

        $page = Livewire::test(Enquiries::class)->set('show', 'outcome')->assertSee('Dana Ortiz')->assertSee('Demo needs outcome')
            ->call('select', $enquiry->id)
            ->call('assignDemo', (string) $this->admin->id)
            ->call('markDemo', 'attended')->assertDispatched('toast');
        $this->assertSame([$this->admin->id, 'attended'], [$enquiry->fresh()->demo_owner_user_id, $enquiry->fresh()->demo_status]);
        Mail::assertQueued(SalesDemoFollowUpMail::class, fn ($m) => $m->kind === 'recap' && str_contains($m->render(), url('/start')) && str_contains($m->render(), 'with Riley Admin'));

        $page->call('markDemo', 'no_show')->call('markDemo', 'attended');
        Mail::assertQueued(SalesDemoFollowUpMail::class, 1);   // only the recap, never twice
        $page->call('setOutcome', 'not_interested');
        $this->assertSame('not_interested', $enquiry->fresh()->demo_outcome);
        $page->call('setOutcome', 'bogus')->assertStatus(422);

        // Business users can't reach it.
        $owner = User::factory()->create(['role' => 'client', 'is_active' => true, 'must_change_password' => false]);
        $this->actingAs($owner)->get(route('admin.enquiries'))->assertForbidden();
    }

    public function test_a_no_show_gets_a_new_time_link_and_can_rebook(): void
    {
        $enquiry = $this->booked();
        $this->travelTo(CarbonImmutable::parse('2026-11-11 17:00', 'UTC'));
        app(DemoFollowUp::class)->mark($enquiry, 'no_show', $this->admin);
        Mail::assertQueued(SalesDemoFollowUpMail::class, fn ($m) => $m->kind === 'missed' && str_contains($m->render(), route('demo.show', $enquiry->demo_token)));

        $slot = app(DemoSlots::class)->days(null, 1, 1)[0]['slots'][0]['starts_at'];
        $this->post(route('demo.reschedule', $enquiry->demo_token), ['starts_at' => $slot])->assertRedirect();
        $this->assertSame('booked', $enquiry->fresh()->demo_status);
    }

    public function test_sign_ups_are_spotted_and_quiet_demos_get_a_nudge(): void
    {
        $enquiry = $this->booked();
        $other = ContactSubmission::create(['name' => 'Sam Lee', 'email' => 'sam@lee.test', 'inquiry_type' => 'demo', 'message' => 'Hi', 'source' => 'chat']);
        app(DemoSlots::class)->book($other, CarbonImmutable::parse('2026-11-10 12:00', 'America/New_York'));
        $this->travelTo(CarbonImmutable::parse('2026-11-11 18:00', 'UTC'));
        app(DemoFollowUp::class)->mark($enquiry, 'attended', $this->admin);
        app(DemoFollowUp::class)->mark($other->fresh(), 'attended', $this->admin);

        // Dana signs up with the same email: a trial, then a paying customer.
        $dana = User::factory()->create(['role' => 'client', 'email' => 'dana@ortizdental.test', 'is_active' => true]);
        $organization = app(ProvisionUserTenancy::class)->handle($dana);
        $this->artisan('sales:demo-follow-ups')->expectsOutputToContain('1 sign-ups spotted');
        $this->assertSame(['trial', $organization->id], [$enquiry->fresh()->demo_outcome, $enquiry->fresh()->signed_up_organization_id]);

        $plan = Plan::create(['slug' => 'pro', 'name' => 'Pro', 'price_cents' => 40000, 'trial_days' => 0]);
        app(ManageSubscription::class)->subscribe($organization, $plan, $this->admin, withTrial: false);
        $this->artisan('sales:demo-follow-ups');
        $this->assertSame('customer', $enquiry->fresh()->demo_outcome);

        // Sam hasn't started a trial three days on: one nudge.
        $this->travel(4)->days();
        $this->artisan('sales:demo-follow-ups')->expectsOutputToContain('1 nudges');
        $this->artisan('sales:demo-follow-ups')->expectsOutputToContain('0 nudges');
        Mail::assertQueued(SalesDemoFollowUpMail::class, fn ($m) => $m->kind === 'nudge' && $m->hasTo('sam@lee.test'));
        Mail::assertNotQueued(SalesDemoFollowUpMail::class, fn ($m) => $m->kind === 'nudge' && $m->hasTo('dana@ortizdental.test'));
    }
}
