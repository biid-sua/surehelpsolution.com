<?php

namespace Tests\Feature;

use App\Actions\Appointments\ChangeAppointmentStatus;
use App\Actions\Notifications\NotifyOrganization;
use App\Actions\Organizations\ProvisionUserTenancy;
use App\Actions\Tasks\CreateTask;
use App\Enums\AppointmentStatus;
use App\Enums\CustomerStatus;
use App\Jobs\FinishVoiceCall;
use App\Livewire\Client\Business\Automations;
use App\Models\Appointment;
use App\Models\Automation;
use App\Models\AutomationRun;
use App\Models\BusinessHour;
use App\Models\Customer;
use App\Models\Escalation;
use App\Models\Organization;
use App\Models\PhoneNumber;
use App\Models\Tag;
use App\Models\Task;
use App\Models\User;
use App\Models\VoiceCall;
use App\Notifications\CustomerEmail;
use App\Services\Telephony\TelephonyProvider;
use App\Services\Telephony\Texting;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Automations 2.0, step 1 (D80): more triggers, texts, tags and status, conditions and recipes.
 */
class AutomationsTwoTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Organization $org;

    private int $customers = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->travelTo(CarbonImmutable::parse('2026-10-12 15:00', 'UTC'));   // a Monday
        $this->owner = User::factory()->create(['role' => 'client', 'is_active' => true, 'must_change_password' => false]);
        $this->org = app(ProvisionUserTenancy::class)->handle($this->owner);
        $this->org->forceFill(['name' => 'Rivera Plumbing', 'timezone' => 'UTC', 'status' => 'active'])->save();
        $this->org = $this->org->fresh();
        $this->actingAs($this->owner);
    }

    private function automation(string $trigger, string $action, array $config, int $delay = 0, array $conditions = []): Automation
    {
        return Automation::create(['organization_id' => $this->org->id, 'name' => Str::headline($trigger), 'trigger' => $trigger,
            'delay_minutes' => $delay, 'action' => $action, 'action_config' => $config, 'conditions' => $conditions ?: null]);
    }

    private function customer(array $attributes = []): Customer
    {
        $n = $this->customers++;
        $phone = $n === 0 ? '(512) 555-0147' : '(512) 555-'.str_pad((string) (200 + $n), 4, '0', STR_PAD_LEFT);

        return Customer::create($attributes + ['organization_id' => $this->org->id, 'first_name' => 'Ana', 'email' => 'ana@example.test', 'phone' => $phone]);
    }

    private function booking(Customer $customer, string $starts, array $attributes = []): Appointment
    {
        $at = CarbonImmutable::parse($starts, 'UTC');

        return Appointment::create(['organization_id' => $this->org->id, 'customer_id' => $customer->id, 'title' => 'Leak repair', 'timezone' => 'UTC',
            'starts_at' => $at, 'ends_at' => $at->addHour(), 'blocked_until' => $at->addHour()] + $attributes);
    }

    private function emailsTo(string $email, string $startsWith): int
    {
        $n = 0;
        Notification::assertSentTo(new AnonymousNotifiable, CustomerEmail::class, function (CustomerEmail $mail, $channels, $notifiable) use ($email, $startsWith, &$n) {
            if (($notifiable->routes['mail'] ?? null) === $email && str_starts_with($mail->subject, $startsWith)) {
                $n++;
            }

            return true;
        });

        return $n;
    }

    public function test_first_time_bookings_get_a_welcome_and_imports_dont(): void
    {
        $this->automation('appointment_booked', 'send_email', ['subject' => 'Welcome to {business}', 'body' => 'Hi {first_name}, see you {date}.', 'consent_only' => false],
            conditions: ['rules' => [['field' => 'first_visit', 'op' => 'yes']]]);
        $new = $this->customer();
        $returning = $this->customer(['first_name' => 'Bo', 'email' => 'bo@example.test', 'phone' => null]);
        $old = $this->booking($returning, '2026-09-01 10:00');
        app(ChangeAppointmentStatus::class)->handle($old, AppointmentStatus::Completed, $this->owner);

        $this->booking($new, '2026-10-20 10:00');
        $this->booking($returning, '2026-10-21 10:00');
        $this->booking($this->customer(['email' => 'imp@example.test']), '2026-10-22 10:00', ['source' => 'import']);
        $this->artisan('automations:run')->assertSuccessful();

        $this->assertSame(1, $this->emailsTo('ana@example.test', 'Welcome to Rivera Plumbing'));
        $this->assertSame(0, $this->emailsTo('bo@example.test', 'Welcome'), 'not their first visit');
        $this->assertSame(0, $this->emailsTo('imp@example.test', 'Welcome'), 'imports never trigger');
    }

    public function test_coming_up_steps_count_back_from_the_start_and_follow_a_moved_booking(): void
    {
        $this->automation('appointment_upcoming', 'send_email', ['subject' => 'Your {service} on {date}', 'body' => 'Get ready.', 'consent_only' => false], 2 * 1440);
        $booking = $this->booking($this->customer(), '2026-10-20 10:00');
        $run = AutomationRun::query()->sole();
        $this->assertTrue($run->run_at->equalTo(CarbonImmutable::parse('2026-10-18 10:00', 'UTC')));

        $booking->update(['starts_at' => CarbonImmutable::parse('2026-10-25 10:00', 'UTC'), 'ends_at' => CarbonImmutable::parse('2026-10-25 11:00', 'UTC')]);
        $this->assertTrue($run->fresh()->run_at->equalTo(CarbonImmutable::parse('2026-10-23 10:00', 'UTC')), 'follows the new time');

        $this->travelTo(CarbonImmutable::parse('2026-10-23 10:01', 'UTC'));
        $this->artisan('automations:run');
        $this->assertSame(1, $this->emailsTo('ana@example.test', 'Your Leak repair on Sunday, October 25'));

        // Booked at short notice: sent straight away rather than skipped.
        $soon = $this->booking($this->customer(['email' => 'soon@example.test']), '2026-10-24 09:00');
        $this->assertTrue(AutomationRun::query()->where('subject_id', $soon->id)->sole()->run_at->lte(now()));
        // Cancelled before the day: nothing goes out.
        $later = $this->booking($this->customer(['email' => 'later@example.test']), '2026-11-10 10:00');
        $later->update(['status' => AppointmentStatus::Cancelled]);
        $this->travelTo(CarbonImmutable::parse('2026-11-08 10:01', 'UTC'));
        $this->artisan('automations:run');
        $this->assertSame('cancelled', AutomationRun::query()->where('subject_id', $later->id)->sole()->status);
    }

    public function test_no_shows_get_a_text_only_with_consent(): void
    {
        PhoneNumber::create(['organization_id' => $this->org->id, 'provider' => 'fake', 'provider_sid' => 'PN1', 'number' => '+15125550100']);
        $this->automation('appointment_no_show', 'send_text', ['text' => 'Hi {first_name}, we missed you at {business}.', 'consent_only' => true]);
        $agreed = $this->booking($this->customer(['sms_consent' => true]), '2026-10-12 13:00');
        $notAgreed = $this->booking($this->customer(['first_name' => 'Bo', 'phone' => '(512) 555-0188']), '2026-10-12 13:00');
        foreach ([$agreed, $notAgreed] as $a) {
            app(ChangeAppointmentStatus::class)->handle($a, AppointmentStatus::NoShow, $this->owner);
        }
        $this->artisan('automations:run');

        // (The booking confirmation texts are sent separately; only the automation's text counts here.)
        $texts = array_values(array_filter(app(TelephonyProvider::class)->texts, fn ($t) => str_contains($t['body'], 'missed you')));
        $this->assertCount(1, $texts);
        $this->assertSame(['+15125550147', 'Hi Ana, we missed you at Rivera Plumbing.'], [$texts[0]['to'], $texts[0]['body']]);
        $this->assertSame(['done', 'skipped'], AutomationRun::query()->orderBy('id')->pluck('status')->all());
        $this->assertStringContainsString('agreed to texts', AutomationRun::query()->where('status', 'skipped')->value('result'));
    }

    public function test_missed_calls_after_hours_become_a_call_back_task(): void
    {
        foreach (range(1, 5) as $day) {
            BusinessHour::create(['organization_id' => $this->org->id, 'day_of_week' => $day, 'opens_at' => '09:00', 'closes_at' => '17:00']);
        }
        $this->automation('call_missed', 'create_task', ['title' => 'Call back {first_name} first thing', 'due_hours' => 12],
            conditions: ['rules' => [['field' => 'business_hours', 'op' => 'closed']]]);
        $line = PhoneNumber::create(['organization_id' => $this->org->id, 'provider' => 'fake', 'provider_sid' => 'PN1', 'number' => '+15125550100']);
        $missed = fn (string $at) => VoiceCall::create(['organization_id' => $this->org->id, 'phone_number_id' => $line->id, 'provider' => 'fake', 'provider_call_sid' => 'CA'.Str::random(8),
            'from_number' => '+15125550147', 'to_number' => '+15125550100', 'status' => VoiceCall::MISSED, 'started_at' => CarbonImmutable::parse($at, 'UTC')]);

        (new FinishVoiceCall($missed('2026-10-12 21:30')->id))->handle(app(CreateTask::class), app(NotifyOrganization::class), app(Texting::class));
        (new FinishVoiceCall($missed('2026-10-12 11:00')->id))->handle(app(CreateTask::class), app(NotifyOrganization::class), app(Texting::class));
        $this->artisan('automations:run');

        $this->assertSame(1, Task::query()->where('title', 'like', 'Call back % first thing')->count(), 'only the one after hours');
    }

    public function test_tags_status_and_conditions_on_new_customers(): void
    {
        $vip = Tag::create(['organization_id' => $this->org->id, 'name' => 'VIP']);
        $this->automation('customer_created', 'add_tag', ['tag_id' => $vip->id], conditions: ['rules' => [['field' => 'has_email', 'op' => 'yes']]]);
        $this->automation('customer_created', 'set_status', ['status' => 'prospect']);

        $withEmail = $this->customer();
        $noEmail = $this->customer(['first_name' => 'Bo', 'email' => null]);
        $this->artisan('automations:run');

        $this->assertTrue($withEmail->tags()->whereKey($vip->id)->exists());
        $this->assertFalse($noEmail->tags()->whereKey($vip->id)->exists());
        $this->assertSame(CustomerStatus::Prospect, $withEmail->fresh()->status);
    }

    public function test_customers_who_havent_been_back_get_one_win_back_email(): void
    {
        $this->automation('customer_inactive', 'send_email', ['subject' => 'It\'s been a while', 'body' => 'Come back!', 'consent_only' => true], conditions: ['inactive_days' => 180]);
        $lapsed = $this->customer(['email_consent' => true]);
        $recent = $this->customer(['first_name' => 'Bo', 'email' => 'bo@example.test', 'email_consent' => true]);
        foreach ([[$lapsed, '2026-03-01 10:00'], [$recent, '2026-09-01 10:00']] as [$c, $when]) {
            app(ChangeAppointmentStatus::class)->handle($this->booking($c, $when), AppointmentStatus::Completed, $this->owner);
        }

        $this->artisan('automations:run');
        $this->assertSame(1, $this->emailsTo('ana@example.test', 'It\'s been a while'));
        $this->assertSame(0, $this->emailsTo('bo@example.test', 'It\'s'));

        // Once only.
        $this->travel(2)->days();
        $this->artisan('automations:run');
        $this->assertSame(1, AutomationRun::query()->count());
    }

    public function test_escalations_email_the_owner_and_internal_triggers_cant_message_customers(): void
    {
        $this->automation('escalation_raised', 'email_address', ['to' => 'owner@rivera.test', 'subject' => 'Escalation: {reason}', 'body' => '{customer} needs you.']);
        Escalation::create(['organization_id' => $this->org->id, 'customer_id' => $this->customer()->id, 'type' => 'emergency', 'priority' => 'urgent', 'reason' => 'Flooded basement']);
        $this->artisan('automations:run');
        $this->assertSame(1, $this->emailsTo('owner@rivera.test', 'Escalation: Flooded basement'));

        Livewire::test(Automations::class)->call('start')
            ->set('form.name', 'Loop')->set('form.trigger', 'task_overdue')->set('form.steps.0.action', 'create_task')
            ->set('form.steps.0.title', 'Again')->set('form.steps.0.due_hours', '2')->call('save')->assertHasErrors('form.steps.0.action');
        Livewire::test(Automations::class)->call('start')
            ->set('form.name', 'Leak')->set('form.trigger', 'escalation_raised')->set('form.steps.0.action', 'send_email')
            ->set('form.steps.0.subject', 'x')->set('form.steps.0.body', 'y')->call('save')->assertHasErrors('form.steps.0.action');
    }

    public function test_recipes_and_the_condition_builder(): void
    {
        $vip = Tag::create(['organization_id' => $this->org->id, 'name' => 'VIP']);
        Livewire::test(Automations::class)->call('start', 'welcome')
            ->assertSet('form.rules.0.field', 'first_visit')
            ->call('addRule')->set('form.rules.1.field', 'customer_tag')->assertSet('form.rules.1.op', 'has')
            ->call('save')->assertHasErrors('form.rules.1.value')
            ->set('form.rules.1.value', (string) $vip->id)->call('save')->assertHasNoErrors();
        $a = Automation::query()->sole();
        $this->assertSame([['field' => 'first_visit', 'op' => 'yes'], ['field' => 'customer_tag', 'op' => 'has', 'value' => (string) $vip->id]], $a->rules());

        Livewire::test(Automations::class)->call('edit', $a->ulid)->call('removeRule', 0)->call('save')->assertHasNoErrors();
        $this->assertCount(1, $a->fresh()->rules());

        Livewire::test(Automations::class)->call('start', 'win_back')->call('save')->assertHasNoErrors();
        $this->assertSame(180, Automation::query()->where('trigger', 'customer_inactive')->sole()->condition('inactive_days'));
        Livewire::test(Automations::class)->call('start', 'prepare')->call('save')->assertHasNoErrors()
            ->assertSee('2 days before');
    }

    public function test_moved_bookings_run_each_time(): void
    {
        $this->automation('appointment_rescheduled', 'notify_team', ['message' => '{first_name} moved to {date}']);
        $booking = $this->booking($this->customer(), '2026-10-20 10:00');
        foreach (['2026-10-21 10:00', '2026-10-22 10:00'] as $when) {
            $booking->update(['starts_at' => CarbonImmutable::parse($when, 'UTC'), 'ends_at' => CarbonImmutable::parse($when, 'UTC')->addHour()]);
            $this->artisan('automations:run');
        }
        $this->assertSame('done', AutomationRun::query()->sole()->status);
        $this->assertStringContainsString('Told', AutomationRun::query()->sole()->result);
    }
}
