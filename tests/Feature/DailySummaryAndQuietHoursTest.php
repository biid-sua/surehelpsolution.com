<?php

namespace Tests\Feature;

use App\Actions\Appointments\BookAppointment;
use App\Actions\Organizations\ProvisionUserTenancy;
use App\Enums\EscalationPriority;
use App\Enums\NotificationEvent;
use App\Livewire\Client\Settings\Notifications;
use App\Models\CallLog;
use App\Models\Escalation;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\CallActivity;
use App\Notifications\DailySummaryEmail;
use App\Notifications\EscalationActivity;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Daily summary email (spec NTF-04) and quiet hours (NTF-07).
 */
class DailySummaryAndQuietHoursTest extends TestCase
{
    use RefreshDatabase;

    private const TZ = 'America/Chicago';

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function at(string $local): void
    {
        Carbon::setTestNow(CarbonImmutable::parse($local, self::TZ));
    }

    /** @return array{0: User, 1: Organization, 2: User} owner, business, staff */
    private function business(): array
    {
        $owner = User::factory()->create(['role' => 'client', 'is_active' => true, 'must_change_password' => false, 'name' => 'Maria Rivera']);
        $org = app(ProvisionUserTenancy::class)->handle($owner);
        $org->forceFill(['name' => 'Rivera Plumbing', 'timezone' => self::TZ, 'setup_completed_at' => now()])->save();
        $staff = User::factory()->create(['role' => 'client', 'is_active' => true]);
        $org->members()->attach($staff->id, ['role' => 'staff', 'status' => 'active']);

        return [$owner, $org->fresh(), $staff];
    }

    private function callAt(Organization $org, string $local, string $outcome): CallLog
    {
        $agent = User::factory()->create(['role' => 'agent', 'is_active' => true]);
        $call = CallLog::create(['user_id' => $agent->id, 'organization_id' => $org->id, 'call_id' => CallLog::generateCallId(), 'call_date' => substr($local, 0, 10),
            'call_time' => '09:00', 'reason_for_call' => 'service-request', 'call_outcome' => $outcome, 'agent_name' => 'A', 'status' => 'new']);
        $call->forceFill(['created_at' => CarbonImmutable::parse($local, self::TZ)->utc()])->save();

        return $call;
    }

    public function test_owners_get_a_morning_summary_once_at_their_time(): void
    {
        Notification::fake();
        Bus::fake();
        $this->at('2026-10-14 06:00');
        [$owner, $org, $staff] = $this->business();
        $this->assertSame('07:30', $owner->dailySummaryTime());
        $this->assertNull($staff->dailySummaryTime(), 'others opt in');

        $this->callAt($org, '2026-10-13 10:00', 'scheduled-appointment');
        $this->callAt($org, '2026-10-13 15:00', 'call-dropped');
        app(BookAppointment::class)->handle($org, ['starts_at' => CarbonImmutable::parse('2026-10-14 10:00', self::TZ), 'title' => 'Leak repair']);

        $this->artisan('notifications:daily-summary');
        Notification::assertNotSentTo($owner, DailySummaryEmail::class);

        $this->at('2026-10-14 07:35');
        $this->artisan('notifications:daily-summary')->expectsOutputToContain('sent to 1 person');
        $this->artisan('notifications:daily-summary')->expectsOutputToContain('sent to 0 people');
        Notification::assertSentToTimes($owner, DailySummaryEmail::class, 1);
        Notification::assertNotSentTo($staff, DailySummaryEmail::class);

        Notification::assertSentTo($owner, DailySummaryEmail::class, function (DailySummaryEmail $n) use ($owner) {
            $text = implode("\n", $n->toMail($owner)->introLines);

            return str_contains($text, 'Tuesday, Oct 13') && str_contains($text, '2 calls answered, 1 job booked')
                && str_contains($text, '1 missed call') && str_contains($text, '10:00 AM Leak repair');
        });
    }

    public function test_quiet_days_send_nothing_and_people_choose_their_time(): void
    {
        Notification::fake();
        $this->at('2026-10-14 07:40');
        [$owner, , $staff] = $this->business();
        $this->artisan('notifications:daily-summary')->expectsOutputToContain('sent to 0 people');

        $this->actingAs($staff);
        Livewire::test(Notifications::class)->assertSet('summaryAt', 'off')->set('summaryAt', '06:30')->call('save')->assertHasNoErrors();
        $this->assertSame('06:30', $staff->fresh()->dailySummaryTime());
        Livewire::test(Notifications::class)->set('summaryAt', '03:00')->call('save')->assertHasErrors('summaryAt');

        $this->actingAs($owner);
        Livewire::test(Notifications::class)->set('summaryAt', 'off')->call('save');
        $this->assertNull($owner->fresh()->dailySummaryTime());
    }

    public function test_quiet_hours_hold_emails_until_morning_but_not_urgent_escalations(): void
    {
        $this->at('2026-10-14 23:10');
        [$owner, $org] = $this->business();
        $this->actingAs($owner);
        Livewire::test(Notifications::class)->set('quietOn', true)->set('quietStart', '21:00')->set('quietEnd', '21:00')->call('save')->assertHasErrors('quietEnd');
        Livewire::test(Notifications::class)->set('quietOn', true)->set('quietStart', '21:00')->set('quietEnd', '07:00')->call('save')->assertHasNoErrors();
        $owner->refresh();

        $until = $owner->quietUntil();
        $this->assertSame('2026-10-15 07:00', $until->setTimezone(self::TZ)->format('Y-m-d H:i'));

        $call = $this->callAt($org, '2026-10-14 23:05', 'callback-requested');
        $delay = (new CallActivity($call, NotificationEvent::FollowUpCreated))->withDelay($owner);
        $this->assertSame('2026-10-15 07:00', CarbonImmutable::instance($delay['mail'])->setTimezone(self::TZ)->format('Y-m-d H:i'));
        $this->assertArrayNotHasKey('database', $delay, 'in-app arrives straight away');

        $urgent = Escalation::create(['organization_id' => $org->id, 'type' => 'emergency', 'priority' => EscalationPriority::Urgent, 'reason' => 'Flooding']);
        $this->assertSame([], (new EscalationActivity($urgent))->withDelay($owner));
        $normal = Escalation::create(['organization_id' => $org->id, 'type' => 'complaint', 'priority' => EscalationPriority::Normal, 'reason' => 'Late']);
        $this->assertArrayHasKey('mail', (new EscalationActivity($normal))->withDelay($owner));

        // Daytime: nothing waits.
        $this->at('2026-10-15 12:00');
        $this->assertNull($owner->quietUntil());
        $this->assertSame([], (new CallActivity($call, NotificationEvent::FollowUpCreated))->withDelay($owner));
    }
}
