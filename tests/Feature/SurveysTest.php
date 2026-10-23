<?php

namespace Tests\Feature;

use App\Actions\Appointments\ChangeAppointmentStatus;
use App\Actions\Organizations\ProvisionUserTenancy;
use App\Enums\AppointmentStatus;
use App\Livewire\Client\Business\Automations;
use App\Models\Appointment;
use App\Models\Automation;
use App\Models\BusinessProfile;
use App\Models\CallLog;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\PhoneNumber;
use App\Models\Survey;
use App\Models\Task;
use App\Models\User;
use App\Notifications\CustomerEmail;
use App\Notifications\CustomerUnhappy;
use App\Services\Telephony\TelephonyProvider;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Satisfaction surveys (D84): a 1–5 rating by text or email after a job or a call; happy customers are
 * offered the review link, unhappy ones reach the team first.
 */
class SurveysTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->travelTo(CarbonImmutable::parse('2026-10-12 15:00', 'UTC'));
        $this->owner = User::factory()->create(['role' => 'client', 'is_active' => true, 'must_change_password' => false]);
        $this->org = app(ProvisionUserTenancy::class)->handle($this->owner);
        $this->org->forceFill(['name' => 'Rivera Plumbing', 'timezone' => 'UTC', 'status' => 'active'])->save();
        $this->org = $this->org->fresh();
        BusinessProfile::create(['organization_id' => $this->org->id, 'display_name' => 'Rivera Plumbing', 'phone' => '5125550100']);
        PhoneNumber::create(['organization_id' => $this->org->id, 'provider' => 'fake', 'provider_sid' => 'PN1', 'number' => '+15125550100']);
        $this->actingAs($this->owner);
    }

    private function surveyAfterJobs(): Automation
    {
        Livewire::test(Automations::class)->call('start', 'survey_job')->set('form.steps.0.review_url', 'https://g.page/r/rivera/review')
            ->call('save')->assertHasNoErrors();

        return Automation::query()->sole();
    }

    private function completedJob(Customer $customer): Appointment
    {
        $at = now()->subHours(3);
        $appointment = Appointment::create(['organization_id' => $this->org->id, 'customer_id' => $customer->id, 'title' => 'Leak repair', 'timezone' => 'UTC',
            'starts_at' => $at, 'ends_at' => $at->addHour(), 'blocked_until' => $at->addHour(), 'status' => 'confirmed']);
        app(ChangeAppointmentStatus::class)->handle($appointment, AppointmentStatus::Completed, $this->owner);

        return $appointment;
    }

    /** @return list<array{to: string, body: string}> */
    private function texts(): array
    {
        return array_values(app(TelephonyProvider::class)->texts);
    }

    public function test_a_happy_customer_rates_by_text_and_is_offered_the_review_link(): void
    {
        $this->surveyAfterJobs();
        $ana = Customer::create(['organization_id' => $this->org->id, 'first_name' => 'Ana', 'phone' => '(512) 555-0147', 'sms_consent' => true]);
        $this->completedJob($ana);
        $this->travel(2)->hours();
        $this->artisan('automations:run')->assertSuccessful();

        $survey = Survey::query()->sole();
        $this->assertSame('sms', $survey->channel);
        $sent = collect($this->texts())->last();
        $this->assertStringContainsString('How did we do?', $sent['body']);
        $this->assertStringContainsString($survey->link(), $sent['body']);

        // She replies "5".
        $this->post('/api/webhooks/sms/incoming', ['MessageSid' => 'SM9', 'From' => '+15125550147', 'To' => '+15125550100', 'Body' => '5'])->assertOk();
        $survey->refresh();
        $this->assertSame(5, $survey->score);
        $this->assertSame('text', $survey->answered_by);
        $this->assertStringContainsString(route('survey.review', $survey->token), collect($this->texts())->last()['body']);
        $this->assertSame(0, Task::query()->count());

        // The review link is counted, then goes to Google.
        $this->get(route('survey.review', $survey->token))->assertRedirect('https://g.page/r/rivera/review');
        $this->assertNotNull($survey->fresh()->review_clicked_at);

        // Asked at most once every two weeks.
        $this->completedJob($ana);
        $this->travel(2)->hours();
        $this->artisan('automations:run')->assertSuccessful();
        $this->assertSame(1, Survey::query()->count());
    }

    public function test_an_unhappy_customer_reaches_the_team_with_their_feedback(): void
    {
        $this->surveyAfterJobs();
        $bo = Customer::create(['organization_id' => $this->org->id, 'first_name' => 'Bo', 'last_name' => 'Diaz', 'phone' => '(512) 555-0148', 'sms_consent' => true]);
        $this->completedJob($bo);
        $this->travel(2)->hours();
        $this->artisan('automations:run')->assertSuccessful();
        $survey = Survey::query()->sole();

        // The page never records a vote on its own (link scanners open links).
        $this->get(route('survey.show', $survey->token))->assertOk()->assertSee('How did we do, Bo?');
        $this->get(route('survey.show', ['token' => $survey->token, 'score' => 2]))->assertOk()->assertSee('data-preset', false);
        $this->assertNull($survey->fresh()->score);

        $this->post(route('survey.answer', $survey->token), ['score' => 2])->assertRedirect(route('survey.show', $survey->token));
        $this->get(route('survey.show', $survey->token))->assertSee('We\'re sorry it wasn\'t better', false);
        $task = Task::query()->sole();
        $this->assertSame('Call Bo Diaz: rated us 2 out of 5', $task->title);
        Notification::assertSentTo($this->owner, CustomerUnhappy::class);

        $this->post(route('survey.feedback', $survey->token), ['feedback' => 'The tech was rude.'])->assertRedirect();
        $this->assertStringContainsString('What they said: The tech was rude.', $task->fresh()->description);
        $this->get(route('survey.show', $survey->token))->assertSee('Thank you for telling us');

        // Closed after a week.
        $this->travel(8)->days();
        $this->post(route('survey.answer', $survey->token), ['score' => 5]);
        $this->assertSame(2, $survey->fresh()->score);
        $this->get(route('survey.show', 'notatoken'))->assertNotFound();
    }

    public function test_calls_are_rated_for_the_agent_and_results_show_satisfaction(): void
    {
        $agent = User::factory()->create(['role' => 'agent', 'is_active' => true]);
        Livewire::test(Automations::class)->call('start', 'survey_call')->set('form.steps.0.channel', 'email')
            ->set('form.steps.0.subject', 'How was your call?')->set('form.steps.0.body', 'Hi {first_name}, how did we do?')
            ->call('save')->assertHasErrors('form.steps.0.body')
            ->set('form.steps.0.body', 'Hi {first_name}, how did we do? {survey_link}')->call('save')->assertHasNoErrors();
        $customer = Customer::create(['organization_id' => $this->org->id, 'first_name' => 'Cy', 'email' => 'cy@example.test', 'email_consent' => true]);
        CallLog::create(['call_id' => 'C-1', 'organization_id' => $this->org->id, 'customer_id' => $customer->id, 'caller_name' => 'Cy', 'caller_phone' => '5125550190',
            'reason_for_call' => 'Leak', 'call_outcome' => 'appointment_booked', 'agent_name' => $agent->name, 'user_id' => $agent->id, 'status' => 'completed', 'call_date' => now(), 'call_time' => now()->format('H:i')]);
        $this->travel(31)->minutes();
        $this->artisan('automations:run')->assertSuccessful();

        $survey = Survey::query()->sole();
        $this->assertSame($agent->id, $survey->agent_user_id);
        Notification::assertSentTo(new AnonymousNotifiable, CustomerEmail::class, fn (CustomerEmail $m) => $m->actionUrl === $survey->link() && $m->actionText === 'Rate your experience');

        $this->post(route('survey.answer', $survey->token), ['score' => 4]);
        $this->get(route('app.results'))->assertOk()->assertSee('Customer satisfaction')->assertSee('4.0 / 5')->assertSee('100%');
    }

    public function test_the_test_run_shows_the_survey_without_sending(): void
    {
        $customer = Customer::create(['organization_id' => $this->org->id, 'first_name' => 'Di', 'phone' => '(512) 555-0149', 'sms_consent' => true]);
        $this->completedJob($customer);
        $test = Livewire::test(Automations::class)->call('start', 'survey_job')->call('testRun')->get('test');

        $this->assertStringStartsWith('Would text (512) 555-0149: "Hi Di, thanks for choosing Rivera Plumbing! How did we do?', $test['lines'][1]['text']);
        $this->assertSame(0, Survey::query()->count());
    }
}
